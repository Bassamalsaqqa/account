<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedAssistant;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantUnavailable;
use App\Models\AiCompanyPolicy;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class AssistantReportAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    private User $reader;

    private Role $role;

    private AiCompanyPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
        config(['ai_assistant.enabled' => true, 'ai_assistant.questions.enabled' => true, 'ai_assistant.provider_approved' => false]);
        Http::preventStrayRequests();
        $this->policy = AiCompanyPolicy::create(['company_id' => $this->company->id, 'enabled' => true,
            'settings' => ['questions' => ['enabled' => true, 'allowed_reports' => AssistantReportCatalog::KEYS]]]);
        $this->reader = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $this->reader->id, 'status' => 'active', 'is_owner' => false]);
        $this->role = Role::create(['company_id' => $this->company->id, 'name' => 'Assistant report reader', 'guard_name' => 'web']);
        $this->role->syncPermissions(['ai.assistant.use', 'reports.sales.view', 'sales.invoice.view']);
        $this->reader->assignRole($this->role);
        $this->actingAs($this->reader);
        app(CompanyContext::class)->setCompany($this->company, $this->reader);
    }

    public function test_report_only_reader_needs_no_transaction_create_or_provider_activation(): void
    {
        $this->assertFalse($this->reader->hasPermissionTo('sales.invoice.create'));
        $this->assertFalse($this->reader->hasPermissionTo('purchasing.purchase.create'));
        app(AssistantReportCatalog::class)->authorize($this->company, $this->reader, 'sales.summary');
        Http::assertNothingSent();
    }

    public function test_ai_permission_does_not_grant_report_source_access(): void
    {
        $this->role->syncPermissions(['ai.assistant.use']);
        $this->expectException(AuthorizationException::class);
        app(AssistantReportCatalog::class)->authorize($this->company, $this->reader, 'sales.summary');
    }

    public function test_report_permission_does_not_grant_assistant_access(): void
    {
        $this->role->syncPermissions(['reports.sales.view', 'sales.invoice.view']);
        $this->expectException(AuthorizationException::class);
        app(AssistantReportCatalog::class)->authorize($this->company, $this->reader, 'sales.summary');
    }

    public function test_mid_session_role_downgrade_is_rechecked_on_the_same_actor(): void
    {
        app(AssistantReportCatalog::class)->authorize($this->company, $this->reader, 'sales.summary');
        $this->role->syncPermissions(['ai.assistant.use']);
        $this->expectException(AuthorizationException::class);
        app(AssistantReportCatalog::class)->authorize($this->company, $this->reader, 'sales.summary');
    }

    public function test_revoked_membership_blocks_even_previously_supported_report_metadata(): void
    {
        app(AssistantReportCatalog::class)->authorize($this->company, $this->reader, 'sales.summary');
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->reader->id)->update(['status' => 'inactive']);
        $this->expectException(AuthorizationException::class);
        app(AssistantReportCatalog::class)->authorize($this->company, $this->reader, 'unsupported-secret-report');
    }

    public function test_foreign_company_never_uses_current_company_permissions_or_policy(): void
    {
        app(CompanyContext::class)->clear();
        $foreign = app(CreateCompanyAction::class)->execute(User::factory()->create(), ['name_ar' => 'شركة أخرى', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($this->company, $this->reader);
        $this->expectException(AuthorizationException::class);
        app(AssistantReportCatalog::class)->authorize($foreign, $this->reader, 'sales.summary');
    }

    public function test_a_supplied_actor_cannot_replace_the_authenticated_reader(): void
    {
        $this->expectException(AuthorizationException::class);
        app(AssistantReportCatalog::class)->authorize($this->company, $this->owner, 'sales.summary');
    }

    public function test_company_policy_is_default_deny_and_malformed_allowlist_is_refused(): void
    {
        $this->policy->update(['settings' => ['questions' => ['enabled' => true, 'allowed_reports' => 'sales.summary']]]);
        $this->expectException(AssistantUnavailable::class);
        app(AssistantReportCatalog::class)->authorize($this->company, $this->reader, 'sales.summary');
    }

    public function test_persisted_policy_revocation_is_freshly_enforced(): void
    {
        app(AssistantReportCatalog::class)->authorize($this->company, $this->reader, 'sales.summary');
        $this->policy->update(['enabled' => false]);
        try {
            app(AssistantReportCatalog::class)->authorize($this->company, $this->reader, 'sales.summary');
            $this->fail('Revoked company policy must block questions.');
        } catch (AssistantUnavailable $error) {
            $this->assertSame('question_disabled', $error->reason);
        }
    }

    public function test_question_flag_defaults_off_even_when_transaction_ai_is_enabled(): void
    {
        config(['ai_assistant.questions.enabled' => false]);
        try {
            app(AssistantReportCatalog::class)->authorize($this->company, $this->reader, 'sales.summary');
            $this->fail('Disabled questions must remain disabled.');
        } catch (AssistantUnavailable $error) {
            $this->assertSame('disabled', $error->reason);
        }
    }
}
