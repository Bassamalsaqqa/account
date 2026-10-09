<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Reporting\Security\ReportPermissionCatalog;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

class ReportingBootstrapTest extends Phase8TestCase
{
    public function test_reporting_bootstrap_is_idempotent_and_updates_owner_only(): void
    {
        // Create custom non-owner role with explicit narrow permissions
        setPermissionsTeamId($this->company->id);
        $customRole = Role::create([
            'company_id' => $this->company->id,
            'name' => 'CustomAuditor',
            'guard_name' => 'web',
        ]);
        $customRole->syncPermissions(['audit.events.view']);

        app(CompanyContext::class)->clear();

        // 1. Run reporting:bootstrap on single company
        $exitCode = Artisan::call('reporting:bootstrap', [
            'companyPublicId' => $this->company->public_id,
        ]);
        $this->assertSame(0, $exitCode);

        // Verify Owner has all new report permissions
        setPermissionsTeamId($this->company->id);
        $ownerRole = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $this->assertTrue($ownerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PURCHASES_VIEW));
        $this->assertTrue($ownerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_INVENTORY_VIEW));
        $this->assertTrue($ownerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_MONEY_VIEW));
        $this->assertTrue($ownerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_EXPENSES_VIEW));
        $this->assertTrue($ownerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PAYROLL_VIEW));

        // Verify custom non-owner role remains untouched (still only audit.events.view)
        $customRole->refresh();
        $this->assertSame(['audit.events.view'], $customRole->permissions->pluck('name')->all());

        // 2. Run reporting:bootstrap with --all idempotently
        $exitCodeAll = Artisan::call('reporting:bootstrap', ['--all' => true]);
        $this->assertSame(0, $exitCodeAll);

        $customRole->refresh();
        $this->assertSame(['audit.events.view'], $customRole->permissions->pluck('name')->all());
    }

    public function test_reporting_bootstrap_never_consumes_sequences_or_creates_business_records(): void
    {
        $seqCountBefore = DocumentSequence::where('company_id', $this->company->id)->count();

        app(CompanyContext::class)->clear();
        Artisan::call('reporting:bootstrap', ['--all' => true]);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // Sequence count is unchanged
        $seqCountAfter = DocumentSequence::where('company_id', $this->company->id)->count();
        $this->assertSame($seqCountBefore, $seqCountAfter);

        // Zero business records created
        $this->assertSame(0, Customer::where('company_id', $this->company->id)->count());
        $this->assertSame(0, SalesInvoice::where('company_id', $this->company->id)->count());
    }

    public function test_new_company_creation_has_correct_default_role_report_matrix(): void
    {
        app(CompanyContext::class)->clear();

        $newOwner = User::factory()->create();
        $newCompany = app(CreateCompanyAction::class)->execute($newOwner, [
            'name_ar' => 'شركة المصفوفة الجديدة',
            'base_currency_code' => 'ILS',
        ]);

        setPermissionsTeamId($newCompany->id);

        // Owner & Admin have all permissions
        $ownerRole = Role::where('company_id', $newCompany->id)->where('name', 'Owner')->firstOrFail();
        $adminRole = Role::where('company_id', $newCompany->id)->where('name', 'Administrator')->firstOrFail();
        $this->assertTrue($ownerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PROFIT_VIEW));
        $this->assertTrue($adminRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PROFIT_VIEW));

        // Manager has report permissions across operational domains
        $managerRole = Role::where('company_id', $newCompany->id)->where('name', 'Manager')->firstOrFail();
        $this->assertTrue($managerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_SALES_VIEW));
        $this->assertTrue($managerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PURCHASES_VIEW));
        $this->assertTrue($managerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_INVENTORY_VIEW));
        $this->assertTrue($managerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_MONEY_VIEW));
        $this->assertTrue($managerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_EXPENSES_VIEW));
        $this->assertTrue($managerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PAYROLL_VIEW));
        $this->assertTrue($managerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PROFIT_VIEW));

        // Purchasing has purchases report, but NOT profit or payroll reports
        $purchasingRole = Role::where('company_id', $newCompany->id)->where('name', 'Purchasing')->firstOrFail();
        $this->assertTrue($purchasingRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PURCHASES_VIEW));
        $this->assertFalse($purchasingRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PROFIT_VIEW));
        $this->assertFalse($purchasingRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PAYROLL_VIEW));

        // Warehouse has inventory report, but NOT inventory cost or profit
        $warehouseRole = Role::where('company_id', $newCompany->id)->where('name', 'Warehouse')->firstOrFail();
        $this->assertTrue($warehouseRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_INVENTORY_VIEW));
        $this->assertFalse($warehouseRole->hasPermissionTo(ReportPermissionCatalog::INVENTORY_COST_VIEW));
        $this->assertFalse($warehouseRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_COST_VIEW));
        $this->assertFalse($warehouseRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PROFIT_VIEW));

        // Viewer has read-only reports, but NOT cost or payroll
        $viewerRole = Role::where('company_id', $newCompany->id)->where('name', 'Viewer')->firstOrFail();
        $this->assertTrue($viewerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_SALES_VIEW));
        $this->assertTrue($viewerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PURCHASES_VIEW));
        $this->assertTrue($viewerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_INVENTORY_VIEW));
        $this->assertFalse($viewerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_COST_VIEW));
        $this->assertFalse($viewerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PROFIT_VIEW));
        $this->assertFalse($viewerRole->hasPermissionTo(ReportPermissionCatalog::REPORTS_PAYROLL_VIEW));
    }
}
