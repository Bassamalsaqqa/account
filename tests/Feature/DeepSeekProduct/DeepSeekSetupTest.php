<?php

declare(strict_types=1);

namespace Tests\Feature\DeepSeekProduct;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\DeepSeekQuickConnect;
use App\Livewire\AI\AssistantLauncher;
use App\Livewire\AI\DeepSeekSetup;
use App\Models\AiCompanyPolicy;
use App\Models\AiProviderConnection;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Phase12\AssistantFixtures;
use Tests\TestCase;

class DeepSeekSetupTest extends TestCase
{
    use AssistantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assistantFixtures();
        $this->owner->update(['password' => Hash::make('synthetic-owner-password')]);
        config(['ai_assistant.provider' => 'direct', 'ai_assistant.request_cost_ceiling_micros' => 10000,
            'ai_assistant.questions.enabled' => true]);
        Http::preventStrayRequests();
    }

    private function response(): array
    {
        return ['model' => 'deepseek-flash', 'choices' => [['message' => ['content' => '{"status":"ok"}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5]];
    }

    private function connect(?string $id = null): array
    {
        return app(DeepSeekQuickConnect::class)->testConnection($this->company, $this->owner,
            'synthetic-key-not-a-real-provider-key', 'synthetic-owner-password', true, $id ?? (string) Str::uuid(), 0, 0);
    }

    public function test_fresh_owner_can_connect_test_and_open_without_serializing_credentials(): void
    {
        Http::fake(['*' => Http::response($this->response())]);
        $component = Livewire::test(DeepSeekSetup::class)
            ->assertSee('DeepSeek AI')->assertSee('Not connected yet')
            ->call('testConnection', 'synthetic-key-not-a-real-provider-key', 'synthetic-owner-password', true)
            ->assertSet('status', 'verified')->assertSee('Connection verified');
        $this->assertStringNotContainsString('synthetic-key-not-a-real-provider-key', $component->html());
        $this->assertArrayNotHasKey('newKey', $component->snapshot['data']);
        $component->call('startUsing')->assertDispatched('ai:open-assistant');
        $connection = AiProviderConnection::sole();
        $this->assertSame('verified', $connection->verification_status);
        $this->assertNotSame('synthetic-key-not-a-real-provider-key', $connection->secret_ciphertext);
        $policy = AiCompanyPolicy::sole();
        $this->assertFalse($policy->settings['fallback']['enabled']);
        $this->assertSame('disabled', $policy->speech_method);
        $this->assertSame($connection->public_id, $policy->settings['text_connection']);
        $request = AssistantRequest::sole();
        $this->assertSame('completed', $request->status);
        $this->assertSame(1, (int) $request->attempt_count);
        $this->assertSame(12, $request->input_units);
        $this->assertSame(5, $request->output_units);
        $this->assertNull($request->reported_cost_micros);
        $this->assertSame('unknown', $request->cost_status);
        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertDatabaseCount('purchases', 0);
        Http::assertSentCount(1);
        Livewire::test(AssistantLauncher::class)->call('showAssistant')->assertSet('open', true)->assertSee('Your message');
    }

    public function test_replayed_and_inflight_submissions_dispatch_only_once(): void
    {
        $key = (string) Str::uuid();
        $during = null;
        Http::fake(function () use ($key, &$during) {
            $during = $this->connect($key);

            return Http::response($this->response());
        });
        $this->assertSame('verified', $this->connect($key)['status']);
        $this->assertSame('request_processed', $during['reason']);
        $this->assertSame('request_processed', $this->connect($key)['reason']);
        $this->assertDatabaseCount('assistant_requests', 1);
        Http::assertSentCount(1);
    }

    public function test_no_consent_or_budget_never_dispatches_or_raises_caps(): void
    {
        $component = Livewire::test(DeepSeekSetup::class);
        $component->call('testConnection', 'synthetic-key-not-real', 'synthetic-owner-password', false)->assertSet('status', 'failed');
        config(['ai_assistant.request_cost_ceiling_micros' => 0]);
        $this->assertSame('budget_configuration', $this->connect()['reason']);
        $this->assertSame(0, config('ai_assistant.request_cost_ceiling_micros'));
        $this->assertDatabaseCount('assistant_requests', 0);
        Http::assertNothingSent();
    }

    public function test_provider_failure_keeps_unknown_reservation_and_does_not_verify(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'sensitive-provider-body']], 402)]);
        $result = $this->connect();
        $this->assertSame('provider_credit', $result['reason']);
        $this->assertSame('configured_unverified', AiProviderConnection::sole()->verification_status);
        $request = AssistantRequest::sole();
        $this->assertSame('failed', $request->status);
        $this->assertSame('provider_credit', $request->failure_code);
        $this->assertGreaterThan(0, $request->reserved_cost_micros);
        $this->assertNull($request->reported_cost_micros);
        $this->assertFalse(app(DeepSeekQuickConnect::class)->canStartUsing($this->company, $this->owner));
        Http::assertSentCount(1);
    }

    public function test_configuration_change_during_provider_call_refuses_verification(): void
    {
        Http::fake(function () {
            AiCompanyPolicy::where('company_id', $this->company->id)->increment('revision');

            return Http::response($this->response());
        });
        $this->assertSame('consent', $this->connect()['reason']);
        $this->assertSame('configured_unverified', AiProviderConnection::sole()->verification_status);
        Http::assertSentCount(1);
    }

    public function test_non_owner_and_revoked_membership_cannot_access_setup_or_start(): void
    {
        $component = Livewire::test(DeepSeekSetup::class);
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $component->call('startUsing')->assertForbidden();
        Livewire::test(DeepSeekSetup::class)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_existing_limits_and_advanced_config_are_preserved(): void
    {
        Http::fake(['*' => Http::response($this->response())]);
        $this->assertSame('verified', $this->connect()['status']);
        $policy = AiCompanyPolicy::sole();
        $settings = $policy->settings;
        $settings['limits']['daily_company_requests'] = 2;
        $settings['fallback']['enabled'] = true;
        $policy->update(['settings' => $settings]);
        $before = $policy->fresh()->toArray();
        $result = app(DeepSeekQuickConnect::class)->testConnection($this->company, $this->owner, '', '', true,
            (string) Str::uuid(), (int) $policy->revision, 1);
        $this->assertSame('advanced_policy', $result['reason']);
        $this->assertSame($before, $policy->fresh()->toArray());
        Http::assertSentCount(1);
    }

    public function test_company_or_session_change_invalidates_mounted_setup(): void
    {
        $component = Livewire::test(DeepSeekSetup::class);
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner,
            ['name_ar' => 'QA', 'name_en' => 'Other QA company', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($other, $this->owner);
        $component->call('allowAnotherTest')->assertForbidden();
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $component = Livewire::test(DeepSeekSetup::class);
        session()->regenerate();
        $component->call('allowAnotherTest')->assertForbidden();
        $this->assertDatabaseCount('ai_provider_connections', 0);
        Http::assertNothingSent();
    }

    public function test_revoked_selected_key_cannot_start_or_dispatch_again(): void
    {
        Http::fake(['*' => Http::response($this->response())]);
        $this->assertSame('verified', $this->connect()['status']);
        $policy = AiCompanyPolicy::sole();
        $connection = AiProviderConnection::sole();
        $connection->update(['revoked_at' => now()]);
        $this->assertSame('not_configured', app(DeepSeekQuickConnect::class)->getConnectionStatus($this->company));
        $this->assertFalse(app(DeepSeekQuickConnect::class)->canStartUsing($this->company, $this->owner));
        $result = app(DeepSeekQuickConnect::class)->testConnection($this->company, $this->owner, '', '', true,
            (string) Str::uuid(), (int) $policy->revision, (int) $connection->revision);
        $this->assertSame('credential_invalid', $result['reason']);
        Http::assertSentCount(1);
    }

    public function test_membership_revoked_during_call_fails_without_marking_verified_or_refunding(): void
    {
        Http::fake(function () {
            CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);

            return Http::response($this->response());
        });
        try {
            $this->connect();
            $this->fail('A revoked owner must not receive a verified result.');
        } catch (AuthorizationException) {
            $request = AssistantRequest::sole();
            $this->assertSame('failed', $request->status);
            $this->assertSame('unauthorized', $request->failure_code);
            $this->assertGreaterThan(0, $request->reserved_cost_micros);
            $this->assertNull($request->reported_cost_micros);
            $this->assertSame('configured_unverified', AiProviderConnection::sole()->verification_status);
        }
        Http::assertSentCount(1);
    }
}
