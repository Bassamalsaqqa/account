<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Livewire\AI\AiSettings;
use App\Livewire\Pages\SettingsIndex;
use App\Models\AiCompanyPolicy;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiSettingsRegressionTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();

        // Ensure questions policy structure is initialized
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = ['enabled' => true, 'allowed_reports' => ['sales.summary']];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => $this->aiPolicy->enabled,
            'personal_credentials_enabled' => $this->aiPolicy->personal_credentials_enabled,
            'follow_up_enabled' => $this->aiPolicy->follow_up_enabled,
            'workflow_mode' => $this->aiPolicy->workflow_mode,
            'speech_method' => $this->aiPolicy->speech_method,
            'settings' => $settings,
        ], $this->aiPolicy->revision);
    }

    /** @param list<string> $permissions */
    protected function member(array $permissions = []): User
    {
        $user = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $user->id, 'status' => 'active', 'is_owner' => false]);
        if ($permissions !== []) {
            $role = Role::create(['company_id' => $this->company->id, 'name' => 'role-'.Str::random(6), 'guard_name' => 'web']);
            $role->givePermissionTo($permissions);
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_get_settings_section_ai_resolves_and_renders_successfully(): void
    {
        $response = $this->actingAs($this->owner)->get(route('settings.index', ['section' => 'ai']));
        $response->assertOk();
        $response->assertSee(__('assistant_v2.settings_title'));
        $response->assertSee('DeepSeek AI');
        Http::assertNothingSent();
    }

    public function test_ai_settings_component_mounts_and_renders_via_both_livewire_aliases(): void
    {
        // 1. Registered alias used in SettingsIndex view
        Livewire::actingAs($this->owner)
            ->test('ai.ai-settings')
            ->assertOk()
            ->assertSee('DeepSeek AI')
            ->assertSee(__('assistant_policy.title'));

        // 2. Direct folder-based alias
        Livewire::actingAs($this->owner)
            ->test('ai.settings')
            ->assertOk()
            ->assertSee('DeepSeek AI')
            ->assertSee(__('assistant_policy.title'));

        // 3. Class-based invocation
        Livewire::actingAs($this->owner)
            ->test(AiSettings::class)
            ->assertOk()
            ->assertSee('DeepSeek AI');

        Http::assertNothingSent();
    }

    public function test_ai_settings_requires_ai_settings_manage_permission_in_settings_index(): void
    {
        $unauthorizedMember = $this->member(['settings.company.view']);

        // Directly loading section=ai without ai.settings.manage permission aborts with 403 Forbidden
        $this->actingAs($unauthorizedMember)
            ->get(route('settings.index', ['section' => 'ai']))
            ->assertForbidden();

        // Updating activeSection via Livewire also throws 403
        Livewire::actingAs($unauthorizedMember)
            ->test(SettingsIndex::class)
            ->call('setSection', 'ai')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_tenant_isolation_ai_settings_bound_to_active_company(): void
    {
        // Clear active company context before creating a secondary tenant
        app(CompanyContext::class)->clear();

        // Create second company with owner using CreateCompanyAction (sets up default roles)
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة عازلة أخرى',
            'name_en' => 'Other Isolated Co',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
        ]);

        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);

        // Other owner does NOT have policy configured on their company
        $this->assertNull(AiCompanyPolicy::where('company_id', $otherCompany->id)->first());

        $component = Livewire::actingAs($otherOwner)
            ->test(AiSettings::class)
            ->assertOk()
            ->assertSee(__('assistant_policy.setup_first'));

        $this->assertNull($component->get('reportPolicyRevision'));
        $this->assertSame([], $component->get('reportAllowlist'));

        Http::assertNothingSent();
    }

    public function test_report_policy_save_preserves_revision_and_enforces_password_and_error_feedback(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(AiSettings::class)
            ->assertOk();

        $initialRevision = $component->get('reportPolicyRevision');
        $this->assertNotNull($initialRevision);

        // 1. Expanding report access with invalid password surfaces explicit error feedback without hiding malfunction
        $component->set('reportAllowlist', ['sales.summary', 'sales.last-invoice'])
            ->call('saveReportPolicy', 'wrong-password')
            ->assertOk();

        $this->assertSame(__('assistant_policy.authorization_failed'), $component->get('reportPolicyMessage'));
        $this->assertSame($initialRevision, $component->get('reportPolicyRevision'));

        // 2. Valid password updates allowlist and increments revision
        $component->call('saveReportPolicy', 'synthetic-configuration-only')
            ->assertOk();

        $this->assertSame(__('assistant_policy.saved'), $component->get('reportPolicyMessage'));
        $this->assertGreaterThan($initialRevision, $component->get('reportPolicyRevision'));

        // Fresh model check
        $freshPolicy = $this->aiPolicy->fresh();
        $this->assertContains('sales.last-invoice', $freshPolicy->settings['questions']['allowed_reports'] ?? []);

        Http::assertNothingSent();
    }
}
