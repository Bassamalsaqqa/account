<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantReportPolicySettings;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeepSeekDefaults;
use App\Livewire\AI\AiSettings;
use App\Models\AiCompanyPolicy;
use App\Models\AiProviderConnection;
use App\Models\AssistantRequest;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssistantReportPolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private AiCompanyPolicy $policy;

    private AiProviderConnection $connection;

    private const PASSWORD = 'synthetic-owner-report-password';

    private const ORIGINAL_REPORTS = ['sales.summary', 'customers.balances', 'purchases.summary',
        'vendors.balances', 'inventory.low-stock', 'inventory.expiry', 'money.balances'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        config(['ai_assistant.enabled' => true, 'ai_assistant.questions.enabled' => true]);
        $this->owner = User::factory()->create(['password' => Hash::make(self::PASSWORD), 'locale' => 'en']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة اختبار', 'name_en' => 'Synthetic company', 'base_currency_code' => 'ILS',
        ]);
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        app()->setLocale('en');
        $governance = app(AiGovernance::class);
        $this->connection = $governance->provision($this->company, $this->owner, [
            'provider' => 'deepseek', 'model' => 'deepseek-flash', 'region' => 'global',
            'capability' => 'text_structured', 'credential_source' => 'company_byok',
        ], 'synthetic-policy-key-not-real', self::PASSWORD);
        $this->connection->update(['verification_status' => 'verified']);
        $this->policy = $governance->updatePolicy($this->company, $this->owner, [
            'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
            'workflow_mode' => 'guided', 'speech_method' => 'disabled',
            'settings' => [
                'allowed_connections' => [$this->connection->public_id], 'text_connection' => $this->connection->public_id,
                'speech_connection' => null, 'fallback' => ['enabled' => false, 'text_connection' => null, 'speech_connection' => null],
                'privacy' => DeepSeekDefaults::privacy(), 'pricing' => [$this->connection->public_id => DeepSeekDefaults::pricing()],
                'limits' => ['request_micros' => 200, 'daily_company_micros' => 1500, 'monthly_company_micros' => 14000,
                    'daily_user_micros' => 700, 'monthly_user_micros' => 9000, 'daily_company_requests' => 7, 'daily_user_requests' => 3],
                'retention_days' => 17, 'global_button_enabled' => true, 'allowed_intents' => ['sales'],
                'questions' => ['enabled' => true, 'allowed_reports' => self::ORIGINAL_REPORTS],
            ],
        ]);
    }

    public function test_existing_seven_report_policy_expands_explicitly_without_altering_keys_limits_usage_or_permissions(): void
    {
        $this->assertSame(self::ORIGINAL_REPORTS, $this->service()->read($this->company, $this->owner)['allowed_reports']);
        $this->assertNotContains('sales.last-invoice', app(AssistantReportCatalog::class)->permittedKeys($this->company, $this->owner));
        $before = $this->policy->getAttributes();
        $settingsBefore = $this->policy->settings;
        $credentialBefore = $this->connection->getAttributes();
        $rolesBefore = DB::table('model_has_roles')->get()->toArray();
        $permissionsBefore = DB::table('model_has_permissions')->get()->toArray();
        AssistantRequest::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id,
            'request_key' => (string) Str::uuid(), 'purpose' => 'question', 'input_hash' => str_repeat('a', 64),
            'provider' => 'direct', 'status' => 'failed', 'reserved_cost_micros' => 13200, 'cost_status' => 'unknown']);
        $usageBefore = DB::table('assistant_requests')->get()->toArray();
        $reports = [...self::ORIGINAL_REPORTS, 'sales.last-invoice', 'sales.by-customer'];
        $updated = $this->service()->update($this->company, $this->owner, $reports, $this->policy->revision, self::PASSWORD);
        $this->assertSame($reports, $updated->settings['questions']['allowed_reports']);
        $permitted = app(AssistantReportCatalog::class)->permittedKeys($this->company, $this->owner);
        $this->assertContains('sales.last-invoice', $permitted);
        $this->assertContains('sales.by-customer', $permitted);
        $this->assertSame($this->policy->revision + 1, $updated->revision);
        $settingsBefore['questions']['allowed_reports'] = $reports;
        $this->assertSame($settingsBefore, $updated->settings);
        foreach (['enabled', 'personal_credentials_enabled', 'follow_up_enabled', 'workflow_mode', 'speech_method'] as $field) {
            $this->assertSame($before[$field], $updated->getAttributes()[$field]);
        }
        $this->assertEquals($credentialBefore, $this->connection->fresh()->getAttributes());
        $this->assertEquals($rolesBefore, DB::table('model_has_roles')->get()->toArray());
        $this->assertEquals($permissionsBefore, DB::table('model_has_permissions')->get()->toArray());
        $this->assertEquals($usageBefore, DB::table('assistant_requests')->get()->toArray());
        $audit = AuditEvent::where('company_id', $this->company->id)->where('event_key', 'ai.report_policy.updated')->firstOrFail();
        $this->assertSame($this->owner->id, $audit->actor_user_id);
        Http::assertNothingSent();
    }

    public function test_report_selection_changes_preserve_question_consent_without_a_new_acknowledgement(): void
    {
        $governance = app(AiGovernance::class);
        $governance->recordConsent($this->company, $this->owner, $this->connection->public_id,
            $governance->consentFingerprint($this->policy, $this->connection, 'question'), 'question');
        $preferencesBefore = DB::table('ai_user_preferences')->get()->toArray();
        $updated = $this->service()->update($this->company, $this->owner,
            [...self::ORIGINAL_REPORTS, 'sales.last-invoice'], $this->policy->revision, self::PASSWORD);
        $this->assertEquals($preferencesBefore, DB::table('ai_user_preferences')->get()->toArray());
        $governance->requireConsent($updated, $this->connection, $this->owner, 'question');
    }

    public function test_disabled_company_and_empty_selection_remain_disabled_without_password_for_reduction(): void
    {
        $settings = $this->policy->settings;
        $settings['questions']['enabled'] = false;
        $this->policy->update(['enabled' => false, 'settings' => $settings]);
        $updated = $this->service()->update($this->company, $this->owner, [], $this->policy->revision);
        $this->assertFalse($updated->enabled);
        $this->assertSame(['enabled' => false, 'allowed_reports' => []], $updated->settings['questions']);
        $this->assertSame([], app(AssistantReportCatalog::class)->permittedKeys($this->company, $this->owner));
    }

    public function test_scope_expansion_requires_fresh_correct_owner_password(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service()->update($this->company, $this->owner, [...self::ORIGINAL_REPORTS, 'sales.last-invoice'],
            $this->policy->revision, 'incorrect-synthetic-password');
    }

    public function test_stale_revision_cannot_overwrite_concurrent_policy_change(): void
    {
        $this->policy->update(['revision' => $this->policy->revision + 1]);
        try {
            $this->service()->update($this->company, $this->owner, [], $this->policy->revision - 1);
            $this->fail('Stale policy update must fail.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->diagnosticReason());
        }
        $this->assertSame(self::ORIGINAL_REPORTS, $this->policy->fresh()->settings['questions']['allowed_reports']);
    }

    public function test_invalid_or_protected_report_keys_are_not_available_for_expansion(): void
    {
        $this->expectException(AssistantUnavailable::class);
        $this->service()->update($this->company, $this->owner, ['payroll.salaries'], $this->policy->revision, self::PASSWORD);
    }

    public function test_revoked_membership_cannot_read_or_change_report_policy(): void
    {
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $this->expectException(AuthorizationException::class);
        $this->service()->update($this->company, $this->owner, [], $this->policy->revision);
    }

    public function test_administrator_with_settings_permission_cannot_manage_protected_owner_allowlist(): void
    {
        $administrator = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $administrator->id, 'status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $administrator->assignRole(Role::where('company_id', $this->company->id)->where('name', 'Administrator')->firstOrFail());
        $administrator->givePermissionTo('ai.settings.manage');
        $this->actingAs($administrator);
        app(CompanyContext::class)->setCompany($this->company, $administrator);
        $this->expectException(AuthorizationException::class);
        $this->service()->read($this->company, $administrator);
    }

    public function test_foreign_company_policy_cannot_be_changed_under_current_company_context(): void
    {
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة أخرى', 'name_en' => 'Other company', 'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->expectException(AuthorizationException::class);
        $this->service()->update($other, $this->owner, [], $this->policy->revision);
    }

    public function test_owner_settings_screen_exposes_finite_explicit_selection_and_saves_it(): void
    {
        Livewire::test(AiSettings::class)->assertSee('Report access')->assertSee('Latest issued sales invoice')
            ->assertSet('reportAllowlist', self::ORIGINAL_REPORTS)
            ->set('reportAllowlist', [...self::ORIGINAL_REPORTS, 'sales.last-invoice'])
            ->call('saveReportPolicy', self::PASSWORD)->assertSee('Report access saved.');
        $this->assertContains('sales.last-invoice', $this->policy->fresh()->settings['questions']['allowed_reports']);
        Http::assertNothingSent();
    }

    public function test_unchanged_selection_is_idempotent_and_does_not_create_policy_audits(): void
    {
        $beforeAuditCount = AuditEvent::count();
        $updated = $this->service()->update($this->company, $this->owner, array_reverse(self::ORIGINAL_REPORTS), $this->policy->revision);
        $this->assertSame($this->policy->revision, $updated->revision);
        $this->assertSame(self::ORIGINAL_REPORTS, $updated->settings['questions']['allowed_reports']);
        $this->assertSame($beforeAuditCount, AuditEvent::count());
    }

    public function test_report_access_does_not_normalize_unrelated_legacy_settings(): void
    {
        $settings = $this->policy->settings;
        unset($settings['global_button_enabled']);
        $this->policy->update(['settings' => $settings]);
        $beforeAuditCount = AuditEvent::count();
        try {
            $this->service()->update($this->company, $this->owner, [], $this->policy->revision);
            $this->fail('Other legacy settings cannot be silently normalized.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('policy_invalid', $e->diagnosticReason());
        }
        $this->assertSame($settings, $this->policy->fresh()->settings);
        $this->assertSame($beforeAuditCount, AuditEvent::count());
    }

    public function test_arabic_report_settings_render_with_no_payroll_report_choice(): void
    {
        app()->setLocale('ar');
        $this->owner->update(['locale' => 'ar']);
        Livewire::test(AiSettings::class)->assertSee('التقارير المتاحة للمساعد')->assertSee('آخر فاتورة مبيعات صادرة')
            ->assertDontSee('value="payroll.salaries"', false);
    }

    public function test_invalid_saved_report_selection_keeps_settings_available_without_silent_filtering(): void
    {
        $settings = $this->policy->settings;
        $settings['questions']['allowed_reports'] = ['payroll.salaries'];
        $this->policy->update(['settings' => $settings]);
        Livewire::test(AiSettings::class)->assertSee('DeepSeek AI')->assertSee(__('assistant_policy.invalid'))
            ->assertSet('reportPolicyRevision', null)->assertSet('reportAllowlist', []);
        $this->assertSame($settings, $this->policy->fresh()->settings);
        Http::assertNothingSent();
    }

    private function service(): AssistantReportPolicySettings
    {
        return app(AssistantReportPolicySettings::class);
    }
}
