<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Platform\PlatformAccess;
use App\Models\Company;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformAdminPresentationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $owner;

    private Company $company;

    private PlatformOperatorGrant $grant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Synthetic Test Owner']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة الاختبار التجريبية',
            'name_en' => 'Synthetic Test Company',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->clear();

        $this->operator = User::factory()->create(['name' => 'Synthetic Test Operator']);
        $this->grant = new PlatformOperatorGrant;
        $this->grant->forceFill([
            'user_id' => $this->operator->id,
            'active' => true,
            'permissions' => PlatformAccess::PERMISSIONS,
            'revision' => 1,
        ])->save();

        // Durable login as operator with password proof
        $this->post('/login', [
            'email' => $this->operator->email,
            'password' => 'password',
        ])->assertRedirect();
    }

    public function test_platform_layout_renders_sidebar_header_and_mobile_drawer_in_arabic_and_english(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $response = $this->withSession(['locale' => $locale])
                ->get(route('platform.index'))
                ->assertOk();

            // Shell & Sidebar Navigation
            $response->assertSee(__('platform.title'));
            $response->assertSee(__('platform.nav_core'));
            $response->assertSee(__('platform.nav_governance'));
            $response->assertSee(__('platform.nav_future'));
            $response->assertSee(__('platform.coming_soon'));
            $response->assertSee(__('platform.operator_badge'));
            $response->assertSee(__('platform.tenant_portal'));
            $response->assertSee('id="platform-sidebar"', false);

            // Accessible Mobile Drawer Dialog
            $response->assertSee('id="platform-mobile-drawer"', false);
            $response->assertSee('id="platform-drawer-open"', false);
            $response->assertSee('id="platform-drawer-close"', false);
            $response->assertSee(__('platform.open_menu'));
            $response->assertSee(__('platform.close_menu'));

            // Context Header Breadcrumb
            $response->assertSee('aria-label="'.__('platform.breadcrumb_label').'"', false);
            $response->assertSee('aria-controls="platform-mobile-drawer"', false);
            $response->assertSee('aria-expanded="false"', false);
            $response->assertSee(__('platform.breadcrumb_platform'));
        }
    }

    public function test_platform_directory_renders_summary_counts_and_company_rows(): void
    {
        // Add an inactive company to verify counts
        $inactiveCompany = Company::create([
            'public_id' => 'cmp_inactive_test_9999',
            'name_ar' => 'شركة غير نشطة',
            'name_en' => 'Inactive Company',
            'status' => 'inactive',
            'base_currency_code' => 'USD',
            'default_locale' => 'en',
            'timezone' => 'Asia/Hebron',
        ]);

        $response = $this->get(route('platform.index'))->assertOk();

        // Summary Metric Cards
        $response->assertSee(__('platform.summary_companies'));
        $response->assertSee(__('platform.summary_active'));
        $response->assertSee(__('platform.summary_inactive'));
        $response->assertSee(__('platform.summary_health_unmeasured'));

        // Company Rows
        $response->assertSee($this->company->public_id);
        $response->assertSee($inactiveCompany->public_id);
        $response->assertSee(route('platform.companies.show', $this->company->public_id));
        $response->assertSee(route('platform.companies.show', $inactiveCompany->public_id));
        $response->assertSee(__('platform.view'));
    }

    public function test_platform_company_show_renders_company_owner_and_impersonation_form(): void
    {
        $response = $this->get(route('platform.companies.show', $this->company->public_id))->assertOk();

        // Identity & Breadcrumbs
        $response->assertSee($this->company->name_ar);
        $response->assertSee($this->company->public_id);
        $response->assertSee(__('platform.back'));

        // Active Owner resolution in Impersonation Card
        $response->assertSee(__('platform.impersonate_target'));
        $response->assertSee(__('platform.impersonate_owner_available'));
        $response->assertSee('Synthetic Test Owner');

        // Impersonation Form
        $response->assertSee(__('support.title'));
        $response->assertSee(route('platform.support.start', $this->company->public_id));
        $response->assertSee('name="reason"', false);
        $response->assertSee('name="current_password"', false);
        $response->assertSee(__('support.start'));

        // No read-only claims in presentation
        $response->assertDontSee('This is a read-only support session');
        $response->assertDontSee('Support view is read-only');
    }

    public function test_platform_company_show_handles_missing_owner_honestly(): void
    {
        // Company without any active owner
        $companyWithoutOwner = Company::create([
            'public_id' => 'cmp_no_owner_test_1111',
            'name_ar' => 'منشأة بدون مالك',
            'name_en' => 'Company Without Owner',
            'status' => 'active',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
        ]);

        $response = $this->get(route('platform.companies.show', $companyWithoutOwner->public_id))->assertOk();

        // Unambiguous and honest unavailable notice
        $response->assertSee(__('platform.impersonate_owner_missing'));
        $response->assertSee(__('support.owner_unavailable'));

        // Start button must NOT be rendered when owner is unavailable
        $response->assertDontSee(route('platform.support.start', $companyWithoutOwner->public_id));
    }

    public function test_platform_company_create_renders_two_step_review_and_preserves_request_key(): void
    {
        $response = $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get(route('platform.companies.create'))
            ->assertOk();

        $response->assertSee(__('platform.create'));
        $response->assertSee(route('platform.companies.store'));
        $response->assertSee('name="request_key"', false);
        $response->assertSee('name="name_ar"', false);
        $response->assertSee('name="name_en"', false);
        $response->assertSee('name="base_currency_code"', false);
        $response->assertSee('name="owner_name"', false);
        $response->assertSee('name="owner_email"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('name="password_confirmation"', false);

        // Alpine.js two-step review elements
        $response->assertSee('x-show="review"', false);
        $response->assertSee(__('platform.review'));
        $response->assertSee(__('platform.confirm'));
    }

    public function test_platform_audit_renders_activity_table_and_preserves_event_keys(): void
    {
        // Record test audit event using forceFill with explicit created_at
        $event = new PlatformAuditEvent;
        $event->forceFill([
            'event_key' => 'company.provisioned',
            'actor_user_id' => $this->operator->id,
            'company_id' => $this->company->id,
            'metadata' => json_encode(['source' => 'synthetic_test']),
            'created_at' => now()->toDateTimeString(),
        ])->save();

        $response = $this->get(route('platform.audit'))->assertOk();

        $response->assertSee(__('platform.audit'));
        $response->assertSee('company.provisioned');
        $response->assertSee('#'.$this->operator->id);
        $response->assertSee('#'.$this->company->id);
    }

    public function test_platform_support_banner_renders_operational_impersonation_in_both_locales(): void
    {
        // Start impersonation
        $this->post(route('platform.support.start', $this->company->public_id), [
            'reason' => 'Synthetic troubleshooting authorization',
            'current_password' => 'password',
        ])->assertRedirect(route('dashboard'));

        foreach (['ar', 'en'] as $locale) {
            $response = $this->withSession(['locale' => $locale])
                ->get(route('products.index'))
                ->assertOk();

            // Impersonation banner region
            $response->assertSee('role="region"', false);
            $response->assertSee(__('support.title'));
            $response->assertSee('Synthetic Test Operator');
            $response->assertSee('Synthetic Test Owner');
            $response->assertSee(route('platform.support.end'));

            // No false read-only claims in banner
            $response->assertDontSee('Read-only until');
            $response->assertDontSee('للقراءة فقط حتى');
        }
    }
}
