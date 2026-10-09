<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Security\ReportPermissionCatalog;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;

class ReportingGuardAndFiltersTest extends Phase8TestCase
{
    public function test_unauthenticated_guard_access_fails_closed(): void
    {
        auth()->logout();

        $guard = app(ReportingGuard::class);

        $this->expectException(AuthorizationException::class);
        $guard->authorizeProfit($this->company);
    }

    public function test_missing_company_context_fails_closed(): void
    {
        app(CompanyContext::class)->clear();

        $guard = app(ReportingGuard::class);

        $this->expectException(NoActiveCompanyException::class);
        $guard->authorizeProfit($this->company, $this->owner);
    }

    public function test_mismatched_company_context_fails_closed(): void
    {
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة ثانية',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $guard = app(ReportingGuard::class);

        // Active context is $this->company, but caller asks for $otherCompany
        $this->expectException(AuthorizationException::class);
        $guard->authorizeProfit($otherCompany, $this->owner);
    }

    public function test_non_member_user_fails_closed(): void
    {
        $outsider = User::factory()->create();
        $this->actingAs($outsider);

        $guard = app(ReportingGuard::class);

        $this->expectException(AuthorizationException::class);
        $guard->authorizeProfit($this->company, $outsider);
    }

    public function test_stale_permission_revocation_immediately_fails_closed(): void
    {
        $guard = app(ReportingGuard::class);

        // Owner initially has profit permissions
        $user = $guard->authorizeProfit($this->company, $this->owner);
        $this->assertSame($this->owner->id, $user->id);

        // Revoke reports.cost.view from Owner role
        $ownerRole = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $ownerRole->revokePermissionTo(ReportPermissionCatalog::REPORTS_COST_VIEW);

        // Next call must fail closed
        $this->expectException(AuthorizationException::class);
        $guard->authorizeProfit($this->company, $this->owner);
    }

    public function test_sensitive_capability_helpers(): void
    {
        $guard = app(ReportingGuard::class);

        $this->assertTrue($guard->canViewCost($this->owner));
        $this->assertTrue($guard->canViewInventoryCost($this->owner));
        $this->assertTrue($guard->canViewPurchasingCost($this->owner));
        $this->assertTrue($guard->canViewPayroll($this->owner));

        // Create a restricted user with only general sales report permission
        $viewer = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $viewer->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $viewerRole = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail();
        $viewer->assignRole($viewerRole);

        $this->assertFalse($guard->canViewCost($viewer));
        $this->assertFalse($guard->canViewInventoryCost($viewer));
        $this->assertFalse($guard->canViewPurchasingCost($viewer));
        $this->assertFalse($guard->canViewPayroll($viewer));
    }

    public function test_report_filters_rejects_unknown_keys(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        ReportFilters::fromArray($this->company, [
            'unsupported_filter' => 123,
        ]);
    }

    public function test_report_filters_rejects_foreign_customer(): void
    {
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة ثانية للتصفية',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);
        $foreignCustomer = Customer::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'عميل شركة أخرى',
            'active' => true,
            'created_by' => $otherOwner->id,
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $this->expectException(InvalidReportFilterException::class);
        ReportFilters::fromArray($this->company, [
            'customer_id' => $foreignCustomer->id,
        ]);
    }

    public function test_report_filters_rejects_foreign_vendor(): void
    {
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة ثانية للموردين',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);
        $foreignVendor = Vendor::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'مورد شركة أخرى',
            'default_currency_code' => 'ILS',
            'active' => true,
            'created_by' => $otherOwner->id,
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $this->expectException(InvalidReportFilterException::class);
        ReportFilters::fromArray($this->company, [
            'vendor_id' => $foreignVendor->id,
        ]);
    }

    public function test_report_filters_rejects_foreign_money_account(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        ReportFilters::fromArray($this->company, [
            'money_account_id' => 999999,
        ]);
    }

    public function test_report_filters_rejects_invalid_pagination(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        ReportFilters::fromArray($this->company, [
            'page' => 0,
        ]);
    }

    public function test_report_filters_valid_same_company_parameters(): void
    {
        $filters = ReportFilters::fromArray($this->company, [
            'vendor_id' => $this->vendor->id,
            'currency_code' => 'ILS',
            'preset' => ReportPeriod::PRESET_THIS_MONTH,
            'page' => 2,
            'per_page' => 25,
        ]);

        $this->assertSame($this->vendor->id, $filters->vendorId);
        $this->assertSame('ILS', $filters->currencyCode);
        $this->assertSame(2, $filters->page);
        $this->assertSame(25, $filters->perPage);
        $this->assertInstanceOf(ReportPeriod::class, $filters->period);
    }
}
