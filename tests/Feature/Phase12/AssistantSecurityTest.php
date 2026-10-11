<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AssistantBudget;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\PcmWave;
use App\Application\AI\SalesAssistant;
use App\Application\AI\SalesProposalResolver;
use App\Application\AI\SalesProposalSchema;
use App\Application\AI\StructuredIntentProvider;
use App\Infrastructure\AI\GatewayTransport;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\PostingBatch;
use App\Models\ProductBarcode;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\AssistantFixtures;
use Tests\TestCase;

class AssistantSecurityTest extends TestCase
{
    use AssistantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assistantFixtures();
        Http::preventStrayRequests();
    }

    public function test_disabled_audio_is_404_with_zero_database_queries_and_no_provider_call(): void
    {
        config(['ai_assistant.enabled' => false]);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->postJson('/sales-assistant/audio', [])->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(0, $queries);
        Http::assertNothingSent();
    }

    public function test_no_consent_or_provider_approval_cannot_reserve_or_send(): void
    {
        $provider = $this->fakeIntent($this->proposal);
        foreach ([['ai_assistant.provider_approved', false], ['ai_assistant.enabled', false], ['ai_assistant.provider', 'none']] as [$field, $value]) {
            $original = config($field);
            config([$field => $value]);
            try {
                app(SalesAssistant::class)->interpret($this->company, $this->owner, 'Sale', (string) Str::uuid(), true);
                $this->fail('Unapproved/disabled providers must fail.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('disabled', $e->reason);
            }
            config([$field => $original]);
        }
        try {
            app(SalesAssistant::class)->interpret($this->company, $this->owner, 'Sale', (string) Str::uuid(), false);
            $this->fail('Consent is required.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('consent', $e->reason);
        }
        $this->assertSame(0, $provider->calls);
        $this->assertSame(0, AssistantRequest::count());
    }

    public function test_foreign_customer_is_never_resolved_or_disclosed(): void
    {
        $foreignOwner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, ['name_ar' => 'أخرى', 'name_en' => 'Other', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($foreignCompany, $foreignOwner);
        $foreign = Customer::create(['company_id' => $foreignCompany->id, 'name_ar' => 'عميل سري', 'name_en' => 'Secret foreign customer', 'code' => 'SECRET-C', 'created_by' => $foreignOwner->id]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);
        $this->proposal['customer'] = $foreign->code;
        $result = app(SalesProposalResolver::class)->resolve($this->company, $this->owner, $this->proposal);
        $this->assertNull($result['payload']);
        $this->assertContains('customer', $result['questions']);
        $this->assertStringNotContainsString('Secret foreign customer', json_encode($result));
        $this->expectException(AuthorizationException::class);
        app(SalesAssistant::class)->interpret($foreignCompany, $this->owner, 'Sale', (string) Str::uuid(), true);
    }

    public function test_cost_limited_reader_cannot_override_price_or_discount_or_default_to_another_unit(): void
    {
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $actor->id, 'status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'AI limited Sales', 'guard_name' => 'web']);
        $role->givePermissionTo(['sales.invoice.create', 'sales.invoice.view']);
        setPermissionsTeamId($this->company->id);
        $actor->assignRole($role);
        app(CompanyContext::class)->setCompany($this->company, $actor);
        $this->actingAs($actor);
        $resolved = app(SalesProposalResolver::class)->resolve($this->company, $actor, $this->proposal);
        $this->assertNotNull($resolved['payload']);
        $this->assertStringNotContainsString('cost', json_encode($resolved));
        $this->proposal['lines'][0]['unit_price'] = '81';
        try {
            app(SalesProposalResolver::class)->resolve($this->company, $actor, $this->proposal);
            $this->fail('Unauthorized price override must fail.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->proposal['lines'][0]['unit_price'] = '80';
        $this->proposal['lines'][0]['discount_type'] = 'fixed';
        $this->proposal['lines'][0]['discount_value'] = '1';
        $this->expectException(AuthorizationException::class);
        app(SalesProposalResolver::class)->resolve($this->company, $actor, $this->proposal);
    }

    public function test_authorization_is_rechecked_after_a_provider_call(): void
    {
        $companyId = $this->company->id;
        $actorId = $this->owner->id;
        $provider = new class($this->proposal, $companyId, $actorId) implements StructuredIntentProvider
        {
            public function __construct(private array $proposal, private int $companyId, private int $actorId) {}

            public function extract(string $text, string $locale): array
            {
                CompanyUser::where('company_id', $this->companyId)->where('user_id', $this->actorId)->update(['status' => 'inactive']);

                return ['proposal' => $this->proposal, 'confidence' => 95, 'usage' => ['cost_micros' => 600, 'input_units' => 10, 'output_units' => 20]];
            }
        };
        $this->app->instance(StructuredIntentProvider::class, $provider);
        try {
            app(SalesAssistant::class)->interpret($this->company, $this->owner, 'Sale', (string) Str::uuid(), true);
            $this->fail('Revocation during provider work must fail.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertSame('failed', AssistantRequest::firstOrFail()->status);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_schema_rejects_injection_and_non_string_economic_values(): void
    {
        $schema = app(SalesProposalSchema::class);
        foreach ([['intent', 'execute_sql'], ['company_id', $this->company->id], ['sql', 'DROP TABLE'], ['version', 2]] as [$key, $value]) {
            $bad = $this->proposal;
            $bad[$key] = $value;
            try {
                $schema->validate($bad);
                $this->fail('Forbidden schema field must fail.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('proposal_invalid', $e->reason);
            }
        }
        foreach ([5, 5.1, '1e2', '-5', '٠٫٠', '1.0000001'] as $quantity) {
            $bad = $this->proposal;
            $bad['lines'][0]['quantity'] = $quantity;
            try {
                $schema->validate($bad);
                $this->fail('Invalid exact quantity must fail.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('proposal_invalid', $e->reason);
            }
        }
        $this->assertSame(0, PostingBatch::count());
    }

    public function test_arabic_digits_and_code_switching_use_exact_server_resolution(): void
    {
        $this->proposal['customer'] = 'أحمد';
        $this->proposal['currency'] = 'شيكل';
        $this->proposal['lines'][0] = ['product' => 'SOAP-3L', 'unit' => 'كرتونة', 'quantity' => '٥', 'unit_price' => '٨٠٫١٢٥'];
        $resolved = app(SalesProposalResolver::class)->resolve($this->company, $this->owner, $this->proposal);
        $this->assertSame([], $resolved['questions']);
        $this->assertSame('5.000000', $resolved['payload']['lines'][0]['quantity']);
        $this->assertSame('80.125000', $resolved['payload']['lines'][0]['unit_price']);
        $this->assertSame('ILS', $resolved['payload']['currency_code']);
        $this->assertSame('60.000000', $resolved['summary'][0]['base_quantity']);
    }

    public function test_barcode_binds_its_authoritative_transaction_unit_and_conflicts_ask(): void
    {
        ProductBarcode::create(['company_id' => $this->company->id, 'product_id' => $this->aiProduct->id, 'unit_id' => $this->aiCarton->unit_id, 'barcode' => '628123456789', 'type' => 'code128']);
        $this->proposal['lines'][0]['product'] = '628123456789';
        $this->proposal['lines'][0]['unit'] = null;
        $resolved = app(SalesProposalResolver::class)->resolve($this->company, $this->owner, $this->proposal);
        $this->assertSame((int) $this->aiCarton->id, $resolved['payload']['lines'][0]['product_unit_id']);
        $this->proposal['lines'][0]['unit'] = 'piece';
        $resolved = app(SalesProposalResolver::class)->resolve($this->company, $this->owner, $this->proposal);
        $this->assertNull($resolved['payload']);
        $this->assertContains('unit', $resolved['questions']);
    }

    public function test_pcm_samples_bound_actual_duration_and_refuse_malformed_compressed_or_long_audio(): void
    {
        $wave = app(PcmWave::class);
        $this->assertSame(1, $wave->seconds($this->wav()));
        $this->assertSame(60, $wave->seconds($this->wav(60)));
        foreach ([$this->wav(61), 'OggS'.str_repeat('x', 100), substr($this->wav(), 0, -1), substr_replace($this->wav(), pack('v', 2), 22, 2)] as $bad) {
            try {
                $wave->seconds($bad);
                $this->fail('Invalid audio must fail before provider dispatch.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('audio_invalid', $e->reason);
            }
        }
    }

    public function test_transcription_returns_editable_text_without_cache_or_recording_retention(): void
    {
        $provider = $this->fakeSpeech('بعت خمس كراتين');
        $key = (string) Str::uuid();
        $result = app(SalesAssistant::class)->transcribe($this->company, $this->owner, $this->wav(), $key, true);
        $this->assertSame('بعت خمس كراتين', $result['text']);
        $request = AssistantRequest::firstOrFail();
        $this->assertSame('transcribed', $request->status);
        $this->assertSame(1, $request->audio_seconds);
        $this->assertStringNotContainsString('بعت', json_encode($request->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertNull(Cache::get(app(SalesAssistant::class)->cacheKey($request)));
        try {
            app(SalesAssistant::class)->transcribe($this->company, $this->owner, $this->wav(), $key, true);
            $this->fail('Audio key cannot resend.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('request_processed', $e->reason);
        }
        $this->assertSame(1, $provider->calls);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_gateway_sends_only_entered_text_and_refuses_private_destinations_redirects_and_oversized_responses(): void
    {
        config(['ai_assistant.gateway.url' => 'https://approved.example', 'ai_assistant.gateway.approved_host' => 'approved.example',
            'ai_assistant.gateway.public_ipv4' => '8.8.8.8', 'ai_assistant.gateway.key' => 'synthetic-local-test-secret-only']);
        Http::fake(['approved.example/*' => Http::response(['proposal' => $this->proposal, 'confidence' => 95, 'usage' => ['cost_micros' => 600, 'input_units' => 1, 'output_units' => 2]])]);
        $transport = app(GatewayTransport::class);
        $transport->send('intent', ['version' => 1, 'intent' => 'draft_sales_invoice', 'text' => 'Sale', 'locale' => 'ar']);
        Http::assertSent(fn ($request) => array_keys($request->data()) === ['version', 'intent', 'text', 'locale', 'limits'] && $request['text'] === 'Sale' && $request['limits']['max_cost_micros'] === 1000);
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '::1'] as $ip) {
            config(['ai_assistant.gateway.public_ipv4' => $ip]);
            try {
                $transport->send('intent', ['text' => 'Sale']);
                $this->fail('Nonpublic pin must fail.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('provider_configuration', $e->reason);
            }
        }
        config(['ai_assistant.gateway.public_ipv4' => '8.8.8.8']);
        foreach ([Http::response('', 302, ['Location' => 'http://127.0.0.1']), Http::response(str_repeat('x', 131073))] as $response) {
            Http::fake(['approved.example/*' => $response]);
            try {
                $transport->send('intent', ['text' => 'Sale']);
                $this->fail('Unsafe response must fail.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('provider_failure', $e->reason);
            }
        }
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_superseded_upload_route_is_unavailable_even_with_legacy_provider_flags_enabled(): void
    {
        $provider = $this->fakeSpeech();
        $this->postJson('/sales-assistant/audio', [])->assertNotFound();
        $this->assertSame(0, $provider->calls);
        $this->assertSame(0, AssistantRequest::count());
        $this->assertSame(0, SalesInvoice::count());
        Http::assertNothingSent();
    }

    public function test_speech_gateway_builds_a_valid_minimized_multipart_request(): void
    {
        config(['ai_assistant.gateway.url' => 'https://approved.example', 'ai_assistant.gateway.approved_host' => 'approved.example',
            'ai_assistant.gateway.public_ipv4' => '8.8.8.8', 'ai_assistant.gateway.key' => 'synthetic-local-test-secret-only']);
        Http::fake(['approved.example/*' => Http::response(['text' => 'Sale', 'confidence' => 95, 'usage' => ['cost_micros' => 600, 'input_units' => 1, 'output_units' => 2]])]);
        app(GatewayTransport::class)->send('transcription', ['version' => 1, 'locale' => 'ar'], $this->wav());
        Http::assertSent(function ($request): bool {
            $data = array_column($request->data(), 'contents', 'name');

            return array_keys($data) === ['version', 'locale', 'limits', 'audio']
                && is_string($data['limits'])
                && json_decode($data['limits'], true)['max_cost_micros'] === 1000
                && $request->hasFile('audio');
        });
    }

    public function test_company_usage_requires_fresh_company_administration_permission(): void
    {
        app(AssistantBudget::class)->reserve($this->company, $this->owner, (string) Str::uuid(), 'intent', str_repeat('a', 64));
        $this->assertSame(1, app(AssistantBudget::class)->summary($this->company, $this->owner, true)['daily_requests']);
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $actor->id, 'status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'AI usage reader', 'guard_name' => 'web']);
        $role->givePermissionTo(['sales.invoice.create', 'sales.invoice.view']);
        setPermissionsTeamId($this->company->id);
        $actor->assignRole($role);
        app(CompanyContext::class)->setCompany($this->company, $actor);
        $this->actingAs($actor);
        $this->assertSame(0, app(AssistantBudget::class)->summary($this->company, $actor)['daily_requests']);
        $this->expectException(AuthorizationException::class);
        app(AssistantBudget::class)->summary($this->company, $actor, true);
    }
}
