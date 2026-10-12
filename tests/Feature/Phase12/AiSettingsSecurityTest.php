<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AiGovernance;
use App\Application\AI\AiProviderRoutes;
use App\Application\AI\AiRequestBudget;
use App\Application\AI\AssistantUnavailable;
use App\Livewire\AI\AiSettings;
use App\Models\AiProviderConnection;
use App\Models\AuditEvent;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiSettingsSecurityTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
    }

    public function test_settings_never_hydrate_provider_keys_and_render_normal_password_confirmed_forms(): void
    {
        Livewire::test(AiSettings::class)
            ->assertSee('AI assistant settings')
            ->assertSee('Configured — not yet verified')
            ->assertDontSee('synthetic-primary-key-never-real')
            ->assertDontSee('synthetic-fallback-key-never-real')
            ->assertDontSee('secret_ciphertext')
            ->assertSee('name="provider_secret"', false)
            ->assertSee('name="password"', false);
        $this->assertStringNotContainsString('synthetic-primary-key', AuditEvent::all()->toJson());
        Http::assertNothingSent();
    }

    public function test_password_confirmed_configuration_is_write_only_and_makes_no_provider_call(): void
    {
        $secret = 'synthetic-http-provision-only';
        $this->post(route('settings.ai.credentials'), [
            'operation' => 'create', 'credential_source' => 'company_byok', 'route' => 'groq|whisper-large-v3-turbo|global|audio_transcription',
            'provider_secret' => $secret, 'password' => 'synthetic-configuration-only',
        ])->assertRedirect(route('settings.ai'));
        $connection = AiProviderConnection::where('provider', 'groq')->firstOrFail();
        $this->assertSame('configured_unverified', $connection->verification_status);
        $this->assertStringNotContainsString($secret, $connection->toJson());
        $this->assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($secret, AuditEvent::all()->toJson());
        Http::assertNothingSent();
    }

    public function test_invalid_credential_input_does_not_flash_key_or_password(): void
    {
        $secret = 'synthetic-never-flash-only';
        $this->from(route('settings.ai'))->post(route('settings.ai.credentials'), [
            'operation' => 'create', 'credential_source' => 'company_byok', 'route' => 'invalid-route',
            'provider_secret' => $secret, 'password' => 'synthetic-configuration-only',
        ])->assertRedirect(route('settings.ai'));
        $this->assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertSame(2, AiProviderConnection::count());
        $this->post(route('settings.ai.credentials'), [
            'operation' => 'create', 'credential_source' => 'invalid', 'provider_secret' => $secret,
            'password' => 'synthetic-configuration-only',
        ])->assertSessionHasErrors('credential_source');
        $session = json_encode(session()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $session);
        $this->assertStringNotContainsString('synthetic-configuration-only', $session);
        Http::assertNothingSent();
    }

    public function test_explicit_key_custodian_can_replace_and_revoke_without_transaction_or_assistant_use_permissions(): void
    {
        $custodian = $this->member(['ai.credentials.manage']);
        $this->assertFalse($custodian->hasPermissionTo('ai.assistant.use'));
        $this->assertFalse($custodian->hasPermissionTo('sales.invoice.create'));
        Livewire::actingAs($custodian)->test(AiSettings::class)->assertSee('Provider connections');
        $governance = app(AiGovernance::class);
        $selection = ['provider' => 'deepseek', 'model' => 'deepseek-flash', 'region' => 'global',
            'capability' => 'text_structured', 'credential_source' => 'company_byok'];
        $governance->provision($this->company, $custodian, $selection, 'synthetic-replacement-only', 'synthetic-custodian-only', $this->textConnection->public_id);
        $this->assertSame(2, $this->textConnection->fresh()->revision);
        $governance->revoke($this->company, $custodian, $this->textConnection->public_id, 'synthetic-custodian-only');
        $this->assertSame('', $this->textConnection->fresh()->secret_ciphertext);
        Http::assertNothingSent();
    }

    public function test_admin_without_explicit_ai_delegation_cannot_open_settings(): void
    {
        $admin = $this->member([]);
        Livewire::actingAs($admin)->test(AiSettings::class)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_stale_settings_revision_cannot_overwrite_a_new_company_policy(): void
    {
        $first = Livewire::test(AiSettings::class);
        $second = Livewire::test(AiSettings::class);
        $first->set('mode', 'guided')->call('save')->assertHasNoErrors();
        $second->set('mode', 'seamless')->call('save')->assertHasErrors('policy');
        $this->assertSame('guided', $this->aiPolicy->fresh()->workflow_mode);
        Http::assertNothingSent();
    }

    public function test_edited_request_count_limits_remain_usable_and_stop_at_the_saved_limit(): void
    {
        Livewire::test(AiSettings::class)
            ->set('limits.daily_company_requests', '2')
            ->set('limits.daily_user_requests', '2')
            ->call('save')->assertHasNoErrors();

        $policy = $this->aiPolicy->fresh();
        $this->assertSame(2, $policy->settings['limits']['daily_company_requests']);
        $this->assertSame(2, $policy->settings['limits']['daily_user_requests']);
        $governance = app(AiGovernance::class);
        foreach ([$this->textConnection, $this->fallbackConnection] as $connection) {
            $governance->recordConsent($this->company, $this->owner, $connection->public_id,
                $governance->consentFingerprint($policy, $connection));
        }
        $routes = app(AiProviderRoutes::class)->select($this->company, $this->owner, 'sales', 'text_structured');
        $budget = app(AiRequestBudget::class);
        foreach (range(1, 2) as $number) {
            $reserved = $budget->reserve($this->company, $this->owner, (string) Str::uuid(),
                (string) Str::uuid(), 'intent', 'sales', hash('sha256', 'edited-count-'.$number), $routes);
            $this->assertTrue($reserved['created']);
        }
        try {
            $budget->reserve($this->company, $this->owner, (string) Str::uuid(),
                (string) Str::uuid(), 'intent', 'sales', hash('sha256', 'third-request'), $routes);
            $this->fail('The edited request count must still prevent a third reservation.');
        } catch (AssistantUnavailable $exception) {
            $this->assertSame('budget', $exception->reason);
        }
        $this->assertDatabaseCount('assistant_requests', 2);
        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertDatabaseCount('purchases', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_edited_request_counts_do_not_change_policy_or_dispatch(): void
    {
        $revision = $this->aiPolicy->fresh()->revision;
        foreach (['0', '2.5', '1000000001', 'not-a-number'] as $value) {
            Livewire::test(AiSettings::class)
                ->set('limits.daily_company_requests', $value)
                ->call('save')->assertHasErrors('limits.daily_company_requests');
            $this->assertSame($revision, $this->aiPolicy->fresh()->revision);
        }
        $this->assertDatabaseCount('assistant_requests', 0);
        Http::assertNothingSent();
    }

    private function member(array $permissions): User
    {
        $member = User::factory()->create(['password' => Hash::make('synthetic-custodian-only')]);
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $member->id, 'status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'AI Test Custodian', 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        $member->assignRole($role);
        $this->actingAs($member);
        app(CompanyContext::class)->setCompany($this->company, $member);

        return $member;
    }
}
