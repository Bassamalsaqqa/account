<?php

declare(strict_types=1);

namespace Tests\Feature\DeepSeekProduct;

use App\Application\AI\AiProviderRoutes;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeepSeekDefaults;
use App\Application\AI\DeepSeekQuickConnect;
use App\Livewire\AI\AiSettings;
use App\Livewire\AI\DeepSeekSetup;
use App\Models\AiCompanyPolicy;
use App\Models\AiProviderConnection;
use App\Models\AiUserPreference;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

    private function reply(): array
    {
        return ['model' => 'deepseek-flash', 'choices' => [['message' => ['content' => '{"status":"ok"}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5]];
    }

    private function connect(string $key = 'synthetic-key-never-real', ?string $requestKey = null): array
    {
        $service = app(DeepSeekQuickConnect::class);

        return $service->testConnection($this->company, $this->owner, $key, 'synthetic-owner-password', true,
            $requestKey ?? (string) Str::uuid(), (int) (AiCompanyPolicy::where('company_id', $this->company->id)->value('revision') ?? 0),
            (int) ($service->findExistingConnection($this->company)?->revision ?? 0));
    }

    private function legacyPolicy(bool $enabled = true, array $settings = []): AiCompanyPolicy
    {
        return AiCompanyPolicy::create(['company_id' => $this->company->id, 'enabled' => $enabled,
            'personal_credentials_enabled' => false, 'follow_up_enabled' => false, 'workflow_mode' => 'guided',
            'speech_method' => 'disabled', 'revision' => 1, 'settings' => $settings]);
    }

    private function legacyConnection(string $provider = 'deepseek', bool $revoked = false): AiProviderConnection
    {
        return AiProviderConnection::create(['company_id' => $this->company->id, 'public_id' => (string) Str::uuid(),
            'provider' => $provider, 'model' => $provider === 'deepseek' ? 'deepseek-flash' : 'legacy-model', 'region' => 'global',
            'capability' => 'text_structured', 'credential_source' => 'company_byok', 'owner_user_id' => null,
            'secret_ciphertext' => Crypt::encryptString('synthetic-retained-key-never-real'), 'revision' => 1,
            'revoked_at' => $revoked ? now() : null, 'verification_status' => $revoked ? 'revoked' : 'verified']);
    }

    private function transition(AiCompanyPolicy $policy): void
    {
        $service = app(DeepSeekQuickConnect::class);
        $service->transition($this->company, $this->owner, 'synthetic-owner-password', (int) $policy->fresh()->revision,
            (int) ($service->findExistingConnection($this->company)?->revision ?? 0));
    }

    public function test_fresh_owner_key_test_use_is_truthful_encrypted_and_unsaved(): void
    {
        Http::fake(fn () => Http::response($this->reply()));
        $component = Livewire::test(DeepSeekSetup::class)->assertSee('DeepSeek AI')->assertSee('Not connected yet')
            ->call('testConnection', 'synthetic-key-never-real', 'synthetic-owner-password', true)
            ->assertSet('status', 'verified')->call('startUsing')->assertDispatched('ai:open-assistant');
        $this->assertStringNotContainsString('synthetic-key-never-real', $component->html());
        $this->assertArrayNotHasKey('newKey', $component->snapshot['data']);
        $connection = AiProviderConnection::sole();
        $this->assertNotSame('synthetic-key-never-real', $connection->secret_ciphertext);
        $this->assertSame('verified', $connection->verification_status);
        $request = AssistantRequest::sole();
        $this->assertSame('connection_test', $request->intent_kind);
        $this->assertSame(1, $request->attempt_count);
        $this->assertSame(12, $request->input_units);
        $this->assertSame(5, $request->output_units);
        $this->assertNull($request->reported_cost_micros);
        $this->assertSame('unknown', $request->cost_status);
        foreach (['sales_invoices', 'purchases', 'posting_batches', 'stock_movements'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        Http::assertSentCount(1);
    }

    public function test_existing_saved_key_is_reused_without_reentry(): void
    {
        $connection = $this->legacyConnection();
        $before = $connection->fresh()->toArray();
        $policy = $this->legacyPolicy(settings: ['text_connection' => $connection->public_id]);
        $this->transition($policy);
        $this->assertSame($before, $connection->fresh()->toArray());
        Http::fake(fn () => Http::response($this->reply()));
        $this->assertSame('verified', $this->connect('')['status']);
        $this->assertDatabaseCount('ai_provider_connections', 1);
        Http::assertSentCount(1);
    }

    public function test_disabled_and_empty_policy_test_does_not_activate_or_expand_workflows(): void
    {
        $policy = $this->legacyPolicy(false, ['allowed_intents' => [], 'questions' => ['enabled' => false, 'allowed_reports' => []]]);
        Http::fake(fn () => Http::response($this->reply()));
        $this->assertSame('verified', $this->connect()['status']);
        $policy->refresh();
        $this->assertFalse($policy->enabled);
        $this->assertSame([], $policy->settings['allowed_intents']);
        $this->assertFalse($policy->settings['questions']['enabled']);
        $this->assertFalse(app(DeepSeekQuickConnect::class)->canStartUsing($this->company, $this->owner));
        app(DeepSeekQuickConnect::class)->enableCompany($this->company, $this->owner, 'synthetic-owner-password', $policy->revision);
        $this->assertTrue($policy->fresh()->enabled);
        $this->assertSame([], $policy->fresh()->settings['allowed_intents']);
        Http::assertSentCount(1);
    }

    public function test_revoked_credential_is_never_revived_and_replacement_preserves_history(): void
    {
        $old = $this->legacyConnection(revoked: true);
        $before = $old->fresh()->toArray();
        $policy = $this->legacyPolicy(settings: ['text_connection' => $old->public_id, 'allowed_connections' => [$old->public_id]]);
        $this->transition($policy);
        Http::fake(fn () => Http::response($this->reply()));
        $this->assertSame('verified', $this->connect()['status']);
        $this->assertSame($before, $old->fresh()->toArray());
        $this->assertDatabaseCount('ai_provider_connections', 2);
        $this->assertNotSame($old->public_id, $policy->fresh()->settings['text_connection']);
        Http::assertSentCount(1);
    }

    public function test_legacy_fallback_personal_and_other_provider_transition_preserves_limits_audits_and_reservations(): void
    {
        $deepseek = $this->legacyConnection();
        $other = $this->legacyConnection('qwen');
        $otherState = $other->fresh()->toArray();
        $limits = array_replace(DeepSeekDefaults::safeLimits(), ['daily_company_micros' => 14000, 'daily_user_micros' => 14000, 'daily_user_requests' => 2]);
        $policy = $this->legacyPolicy(false, ['allowed_connections' => [$other->public_id, $deepseek->public_id],
            'text_connection' => $other->public_id, 'speech_connection' => null,
            'fallback' => ['enabled' => true, 'text_connection' => $deepseek->public_id, 'speech_connection' => null],
            'allowed_intents' => ['sales'], 'questions' => ['enabled' => false, 'allowed_reports' => []],
            'limits' => $limits, 'retention_days' => 14]);
        $policy->update(['personal_credentials_enabled' => true, 'speech_method' => 'cloud']);
        $preferences = AiUserPreference::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id,
            'preferences' => ['text_connection' => $other->public_id], 'consents' => ['old' => ['fingerprint' => 'historic-only']]]);
        foreach (range(1, 2) as $unused) {
            AssistantRequest::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id,
                'request_key' => (string) Str::uuid(), 'form_key' => (string) Str::uuid(), 'purpose' => 'intent',
                'intent_kind' => 'sales', 'input_hash' => str_repeat('a', 64), 'provider' => 'deepseek', 'status' => 'failed',
                'reserved_cost_micros' => 6600, 'estimated_cost_micros' => 6600, 'cost_status' => 'unknown']);
        }
        $usage = AssistantRequest::get()->toArray();
        $prefState = $preferences->fresh()->toArray();
        $this->transition($policy);
        $settings = $policy->fresh()->settings;
        $this->assertFalse($policy->fresh()->enabled);
        $this->assertFalse($policy->fresh()->personal_credentials_enabled);
        $this->assertSame('disabled', $policy->fresh()->speech_method);
        $this->assertSame($deepseek->public_id, $settings['text_connection']);
        $this->assertSame([$deepseek->public_id], $settings['allowed_connections']);
        $this->assertFalse($settings['fallback']['enabled']);
        $this->assertSame($limits, $settings['limits']);
        $this->assertSame(['sales'], $settings['allowed_intents']);
        $this->assertSame(14, $settings['retention_days']);
        $this->assertSame($usage, AssistantRequest::get()->toArray());
        $this->assertSame(13200, (int) AssistantRequest::sum('reserved_cost_micros'));
        $this->assertSame($prefState, $preferences->fresh()->toArray());
        $this->assertSame($otherState, $other->fresh()->toArray());
        $this->assertGreaterThan(0, DB::table('audit_events')->where('event_key', 'ai.deepseek.transitioned')->count());
        Http::assertNothingSent();
    }

    public function test_explicit_transition_reuses_unique_active_key_after_selected_key_revocation(): void
    {
        $revoked = $this->legacyConnection(revoked: true);
        $active = $this->legacyConnection();
        $ciphertext = DB::table('ai_provider_connections')->where('id', $active->id)->value('secret_ciphertext');
        $policy = $this->legacyPolicy(settings: ['text_connection' => $revoked->public_id,
            'allowed_connections' => [$revoked->public_id, $active->public_id]]);
        $this->transition($policy);
        $this->assertSame($active->public_id, $policy->fresh()->settings['text_connection']);
        $this->assertSame($ciphertext, DB::table('ai_provider_connections')->where('id', $active->id)->value('secret_ciphertext'));
        $this->assertNotNull($revoked->fresh()->revoked_at);
        $this->assertSame(1, $active->fresh()->revision);
        Http::assertNothingSent();
    }

    public function test_stale_preferences_and_former_fallback_cannot_override_company_route(): void
    {
        $connection = $this->legacyConnection();
        $other = $this->legacyConnection('openai');
        $policy = $this->legacyPolicy(settings: ['text_connection' => $connection->public_id, 'allowed_connections' => [$connection->public_id],
            'fallback' => ['enabled' => true, 'text_connection' => $other->public_id], 'allowed_intents' => ['sales']]);
        AiUserPreference::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'preferences' => ['text_connection' => $other->public_id]]);
        $routes = app(AiProviderRoutes::class)->resolve($this->company, $this->owner, 'sales', 'text_structured');
        $this->assertCount(1, $routes);
        $this->assertSame($connection->public_id, $routes[0]->public_id);
        try {
            app(AiProviderRoutes::class)->resolve($this->company, $this->owner, 'sales', 'text_structured', $other->public_id);
            $this->fail('A client cannot select a legacy route.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_transition_is_idempotent_and_stale_concurrent_owner_is_refused(): void
    {
        $connection = $this->legacyConnection();
        $policy = $this->legacyPolicy(settings: ['text_connection' => $connection->public_id]);
        $service = app(DeepSeekQuickConnect::class);
        $revision = $policy->revision;
        $this->transition($policy);
        $before = $policy->fresh()->toArray();
        $this->transition($policy);
        $this->assertSame($before, $policy->fresh()->toArray());
        try {
            $service->transition($this->company, $this->owner, 'synthetic-owner-password', $revision, 1);
            $this->fail('A concurrent stale revision cannot normalize again.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_transition_requires_current_owner_password(): void
    {
        $policy = $this->legacyPolicy();
        $before = $policy->fresh()->toArray();
        try {
            app(DeepSeekQuickConnect::class)->transition($this->company, $this->owner, 'wrong', 1, 0);
            $this->fail('Password confirmation is mandatory.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $policy->fresh()->toArray());
        }
        Http::assertNothingSent();
    }

    public function test_no_consent_or_spending_budget_never_dispatches_or_raises_caps(): void
    {
        Livewire::test(DeepSeekSetup::class)->call('testConnection', 'synthetic-key-never-real', 'synthetic-owner-password', false)->assertSet('status', 'failed');
        config(['ai_assistant.request_cost_ceiling_micros' => 0]);
        $this->assertSame('budget_configuration', $this->connect()['reason']);
        $this->assertSame(0, config('ai_assistant.request_cost_ceiling_micros'));
        $this->assertDatabaseCount('assistant_requests', 0);
        Http::assertNothingSent();
    }

    public function test_provider_failure_retains_unknown_reservation_and_safe_error(): void
    {
        Http::fake(fn () => Http::response(['error' => ['message' => 'private raw provider error']], 401));
        $this->assertSame('provider_authentication', $this->connect()['reason']);
        $request = AssistantRequest::sole();
        $this->assertSame('provider_authentication', $request->failure_code);
        $this->assertSame('failed', $request->status);
        $this->assertGreaterThan(0, $request->reserved_cost_micros);
        $this->assertNull($request->reported_cost_micros);
        $this->assertSame('configured_unverified', AiProviderConnection::sole()->verification_status);
        Http::assertSentCount(1);
    }

    public function test_replay_and_inflight_test_do_not_dispatch_twice(): void
    {
        $key = (string) Str::uuid();
        $during = null;
        Http::fake(function () use ($key, &$during) {
            $during = $this->connect(requestKey: $key);

            return Http::response($this->reply());
        });
        $this->assertSame('verified', $this->connect(requestKey: $key)['status']);
        $this->assertSame('request_processed', $during['reason']);
        $this->assertSame('request_processed', $this->connect(requestKey: $key)['reason']);
        $this->assertDatabaseCount('assistant_requests', 1);
        Http::assertSentCount(1);
    }

    public function test_policy_change_during_test_is_refused_without_refund(): void
    {
        Http::fake(function () {
            AiCompanyPolicy::query()->increment('revision');

            return Http::response($this->reply());
        });
        $this->assertSame('proposal_stale', $this->connect()['reason']);
        $this->assertSame('configured_unverified', AiProviderConnection::sole()->verification_status);
        $this->assertGreaterThan(0, AssistantRequest::sole()->reserved_cost_micros);
        Http::assertSentCount(1);
    }

    public function test_membership_revoked_during_test_cannot_verify_connection(): void
    {
        Http::fake(function () {
            CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);

            return Http::response($this->reply());
        });
        try {
            $this->connect();
            $this->fail('Revoked owner cannot verify.');
        } catch (AuthorizationException) {
            $this->assertSame('unauthorized', AssistantRequest::sole()->failure_code);
            $this->assertSame('configured_unverified', AiProviderConnection::sole()->verification_status);
        }
        Http::assertSentCount(1);
    }

    public function test_company_and_session_change_block_mounted_setup(): void
    {
        $component = Livewire::test(DeepSeekSetup::class);
        session()->regenerate();
        $component->call('allowAnotherTest')->assertForbidden();
        $this->assertDatabaseCount('ai_provider_connections', 0);
        Http::assertNothingSent();
    }

    public function test_settings_no_longer_exposes_routing_or_manual_price_fields(): void
    {
        Livewire::test(AiSettings::class)->assertSee('DeepSeek AI')->assertDontSee('Provider connections')
            ->assertDontSee('Personal key')->assertDontSee('Advanced settings');
        Http::assertNothingSent();
    }
}
