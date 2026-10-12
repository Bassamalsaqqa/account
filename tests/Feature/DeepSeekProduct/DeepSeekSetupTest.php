<?php

declare(strict_types=1);

namespace Tests\Feature\DeepSeekProduct;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\DeepSeekDefaults;
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

    private function emptyPolicy(): AiCompanyPolicy
    {
        return app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => false, 'personal_credentials_enabled' => false, 'follow_up_enabled' => false,
            'workflow_mode' => 'guided', 'speech_method' => 'disabled', 'settings' => [
                'allowed_connections' => [], 'text_connection' => null, 'speech_connection' => null,
                'fallback' => ['enabled' => false, 'text_connection' => null, 'speech_connection' => null],
                'privacy' => ['operator' => 'Reviewed company terms', 'retention' => 'Existing terms preserved', 'policy_url' => 'https://example.test/privacy'],
                'pricing' => [], 'limits' => array_replace(DeepSeekDefaults::safeLimits(), ['daily_company_micros' => 40000, 'daily_user_micros' => 18000]),
                'retention_days' => 14, 'global_button_enabled' => true, 'allowed_intents' => ['sales'],
                'questions' => ['enabled' => false, 'allowed_reports' => []],
            ],
        ], 0);
    }

    public function test_existing_empty_policy_can_complete_setup_preserving_custom_governance(): void
    {
        $policy = $this->emptyPolicy();
        $before = $policy->settings;
        Http::fake(fn () => Http::response($this->response()));
        $this->assertFalse(app(DeepSeekQuickConnect::class)->isAdvancedPolicy($this->company));
        Livewire::test(DeepSeekSetup::class)->assertSee('Reviewed company terms')
            ->call('testConnection', 'synthetic-replacement-key-only', 'synthetic-owner-password', true)
            ->assertSet('message', __('ai_product.result_verified'))->assertSet('status', 'verified')->call('startUsing')->assertDispatched('ai:open-assistant');
        $policy->refresh();
        foreach (['privacy', 'limits', 'retention_days', 'global_button_enabled', 'allowed_intents', 'questions'] as $field) {
            $this->assertSame($before[$field], $policy->settings[$field]);
        }
        $this->assertTrue($policy->enabled);
        $this->assertFalse($policy->follow_up_enabled);
        $this->assertSame('guided', $policy->workflow_mode);
        $this->assertSame(2, $policy->revision);
        $this->assertSame([AiProviderConnection::sole()->public_id], $policy->settings['allowed_connections']);
        Http::assertSentCount(1);
    }

    public function test_revoked_route_requires_new_key_and_does_not_revive_or_reset_history(): void
    {
        Http::fake(fn () => Http::response($this->response()));
        $this->assertSame('verified', $this->connect()['status']);
        $oldRequest = AssistantRequest::sole()->toArray();
        $old = AiProviderConnection::sole();
        app(AiGovernance::class)->revoke($this->company, $this->owner, $old->public_id, 'synthetic-owner-password');
        $revoked = $old->fresh()->toArray();
        $policy = AiCompanyPolicy::sole();
        $before = $policy->settings;
        Livewire::test(DeepSeekSetup::class)
            ->call('testConnection', 'synthetic-new-key-not-revived', 'synthetic-owner-password', true)
            ->assertSet('message', __('ai_product.result_verified'))->assertSet('status', 'verified')->call('startUsing')->assertDispatched('ai:open-assistant');
        $new = AiProviderConnection::where('public_id', '!=', $old->public_id)->sole();
        $this->assertSame($revoked, $old->fresh()->toArray());
        $this->assertSame($oldRequest, AssistantRequest::findOrFail($oldRequest['id'])->toArray());
        $this->assertSame($new->public_id, $policy->fresh()->settings['text_connection']);
        $this->assertSame($before['limits'], $policy->fresh()->settings['limits']);
        $this->assertSame($before['pricing'][$old->public_id], $policy->fresh()->settings['pricing'][$new->public_id]);
        $this->assertFalse(app(DeepSeekQuickConnect::class)->isAdvancedPolicy($this->company));
        Http::assertSentCount(2);
    }

    public function test_unselected_single_deepseek_route_is_repaired_only_with_new_explicit_key(): void
    {
        Http::fake(fn () => Http::response($this->response()));
        $this->assertSame('verified', $this->connect()['status']);
        $old = AiProviderConnection::sole();
        $oldState = $old->toArray();
        $policy = AiCompanyPolicy::sole();
        $settings = $policy->settings;
        $settings['text_connection'] = null;
        $policy->update(['settings' => $settings, 'revision' => $policy->revision + 1]);
        $this->assertFalse(app(DeepSeekQuickConnect::class)->isAdvancedPolicy($this->company));
        $component = Livewire::test(DeepSeekSetup::class);
        $component->call('testConnection', '', '', true)->assertSet('status', 'failed');
        Http::assertSentCount(1);
        $component->call('allowAnotherTest')->call('testConnection', 'synthetic-new-route-key', 'synthetic-owner-password', true)->assertSet('message', __('ai_product.result_verified'))->assertSet('status', 'verified');
        $this->assertSame($oldState, $old->fresh()->toArray());
        Http::assertSentCount(2);
    }

    public function test_stale_empty_policy_repair_cannot_provision_or_change_limits(): void
    {
        $policy = $this->emptyPolicy();
        $component = Livewire::test(DeepSeekSetup::class);
        $policy->increment('revision');
        $before = $policy->fresh()->toArray();
        $component->call('testConnection', 'synthetic-new-route-key', 'synthetic-owner-password', true)->assertSet('status', 'failed');
        $this->assertSame($before, $policy->fresh()->toArray());
        $this->assertDatabaseCount('ai_provider_connections', 0);
        Http::assertNothingSent();
    }

    public function test_literal_empty_policy_and_existing_one_route_remain_simple(): void
    {
        $policy = AiCompanyPolicy::create(['company_id' => $this->company->id, 'enabled' => false,
            'personal_credentials_enabled' => false, 'follow_up_enabled' => false, 'workflow_mode' => 'guided',
            'speech_method' => 'disabled', 'revision' => 1, 'settings' => []]);
        Http::fake(fn () => Http::response($this->response()));
        Livewire::test(DeepSeekSetup::class)->call('testConnection', 'synthetic-new-route-key', 'synthetic-owner-password', true)->assertSet('message', __('ai_product.result_verified'))->assertSet('status', 'verified');
        $before = $policy->fresh()->toArray();
        Livewire::test(DeepSeekSetup::class)->call('testConnection', '', '', true)->assertSet('message', __('ai_product.result_verified'))->assertSet('status', 'verified');
        $this->assertSame($before, $policy->fresh()->toArray());
        $this->assertDatabaseCount('ai_provider_connections', 1);
        $this->assertFalse($policy->fresh()->settings['questions']['enabled']);
        Http::assertSentCount(2);
    }

    public function test_genuinely_advanced_variants_cannot_be_replaced_by_quick_connect(): void
    {
        $policy = $this->emptyPolicy();
        $governance = app(AiGovernance::class);
        $deepseek = $governance->provision($this->company, $this->owner, ['provider' => 'deepseek', 'model' => 'deepseek-flash',
            'region' => 'global', 'capability' => 'text_structured', 'credential_source' => 'company_byok'], 'synthetic-deepseek-key', 'synthetic-owner-password');
        $other = $governance->provision($this->company, $this->owner, ['provider' => 'openai', 'model' => 'gpt-4o-mini',
            'region' => 'global', 'capability' => 'text_structured', 'credential_source' => 'company_byok'], 'synthetic-legacy-key', 'synthetic-owner-password');
        $base = $policy->settings;
        foreach ([
            ['personal_credentials_enabled' => true],
            ['settings' => array_replace($base, ['allowed_personal_routes' => ['deepseek|deepseek-flash|global|text_structured']])],
            ['settings' => array_replace($base, ['fallback' => ['enabled' => true, 'text_connection' => null, 'speech_connection' => null]])],
            ['settings' => array_replace($base, ['speech_connection' => $other->public_id])],
            ['settings' => array_replace($base, ['allowed_connections' => [$deepseek->public_id, $other->public_id]])],
            ['settings' => array_replace($base, ['allowed_connections' => [$other->public_id], 'text_connection' => $other->public_id])],
        ] as $variant) {
            $policy->update(array_replace(['personal_credentials_enabled' => false, 'settings' => $base], $variant));
            $before = $policy->fresh()->toArray();
            $this->assertTrue(app(DeepSeekQuickConnect::class)->isAdvancedPolicy($this->company));
            $result = app(DeepSeekQuickConnect::class)->testConnection($this->company, $this->owner,
                'synthetic-new-route-key', 'synthetic-owner-password', true, (string) Str::uuid(), (int) $policy->revision, 0);
            $this->assertSame('advanced_policy', $result['reason']);
            $this->assertSame($before, $policy->fresh()->toArray());
        }
        $this->assertDatabaseCount('ai_provider_connections', 2);
        Http::assertNothingSent();
    }

    public function test_fresh_owner_can_connect_test_and_open_without_serializing_credentials(): void
    {
        Http::fake(fn () => Http::response($this->response()));
        $component = Livewire::test(DeepSeekSetup::class)
            ->assertSee('DeepSeek AI')->assertSee('Not connected yet')
            ->call('testConnection', 'synthetic-key-not-a-real-provider-key', 'synthetic-owner-password', true)
            ->assertSet('message', __('ai_product.result_verified'))->assertSet('status', 'verified')->assertSee('Connection verified');
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
        Http::fake(fn () => Http::response($this->response()));
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
        Http::fake(fn () => Http::response($this->response()));
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
