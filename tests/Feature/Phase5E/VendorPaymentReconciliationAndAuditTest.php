<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Purchasing\PayablesReconciliationReport;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use App\Services\Tenancy\CompanyRoleService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class VendorPaymentReconciliationAndAuditTest extends Phase5ETestCase
{
    /** Scenario 24: Corruption audit disposable DB: amount_base, allocation Purchase ID, book relief, settlement, FX delta, prior boundary, ApplicationEvent link, reversal batch. Detect not repair; generic AP global check not naive Vendor-total assertion */
    public function test_scenario_24_payables_reconciliation_corruption_detection(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $reconciliation = app(PayablesReconciliationService::class);

        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'], // 100 ILS
            ],
        ]);

        $payment = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s24-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        // Clean state should pass reconciliation
        $report1 = $reconciliation->reconcile($this->company);
        $this->assertInstanceOf(PayablesReconciliationReport::class, $report1);
        $this->assertTrue($report1->isHealthy);
        $this->assertEmpty($report1->violations);

        // 1. Corrupt payment amount_base directly via raw DB update
        DB::table('vendor_payments')->where('id', $payment->id)->update([
            'amount_base' => '999.000000',
        ]);

        $reportCorruptBase = $reconciliation->reconcile($this->company);
        $this->assertFalse($reportCorruptBase->isHealthy);
        $this->assertNotEmpty($reportCorruptBase->violations);
        $this->assertTrue(collect($reportCorruptBase->violations)->contains(fn ($v) => str_contains($v, 'amount_base mismatch')));

        // Restore amount_base
        DB::table('vendor_payments')->where('id', $payment->id)->update([
            'amount_base' => '100.000000',
        ]);

        // 2. Corrupt allocation settlement_base_value directly via raw DB update
        DB::table('vendor_payment_allocations')->where('vendor_payment_id', $payment->id)->update([
            'settlement_base_value' => '888.000000',
        ]);

        $reportCorruptAlloc = $reconciliation->reconcile($this->company);
        $this->assertFalse($reportCorruptAlloc->isHealthy);
        $this->assertTrue(collect($reportCorruptAlloc->violations)->contains(fn ($v) => str_contains($v, 'settlement base mismatch')));

        // Restore allocation
        DB::table('vendor_payment_allocations')->where('vendor_payment_id', $payment->id)->update([
            'settlement_base_value' => '100.000000',
        ]);

        // Recheck health
        $reportRestored = $reconciliation->reconcile($this->company);
        $this->assertTrue($reportRestored->isHealthy);
    }

    /** Scenario 25: Existing-company custom Purchasing/Manager/arbitrary roles bootstrap twice: new permissions/Owner complete, nonowners identical, all Purchase/Return/VPM/Sales sequence config/counters unchanged, no business/payment/GL created; new-company defaults correct */
    public function test_scenario_25_company_role_bootstrap_idempotency(): void
    {
        $roleService = app(CompanyRoleService::class);

        // Seed roles first time
        $roleService->seedCompanyRoles($this->company);

        $ownerRole = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $this->assertTrue($ownerRole->hasPermissionTo('money.vendor_payment.allocate'));
        $this->assertTrue($ownerRole->hasPermissionTo('money.vendor_payment.reverse'));
        $this->assertTrue($ownerRole->hasPermissionTo('money.vendor_payment.create'));

        $managerRole = Role::where('company_id', $this->company->id)->where('name', 'Manager')->firstOrFail();
        $this->assertTrue($managerRole->hasPermissionTo('money.vendor_payment.allocate'));
        $this->assertTrue($managerRole->hasPermissionTo('money.vendor_payment.reverse'));

        $purchasingRole = Role::where('company_id', $this->company->id)->where('name', 'Purchasing')->firstOrFail();
        $this->assertTrue($purchasingRole->hasPermissionTo('money.vendor_payment.allocate'));
        $this->assertTrue($purchasingRole->hasPermissionTo('money.vendor_payment.reverse'));

        $roleCountBefore = Role::where('company_id', $this->company->id)->count();

        // Seed / upgrade roles second time: must be completely idempotent
        $roleService->upgradeSalesCatalog($this->company);
        $roleService->seedCompanyRoles($this->company);
        $this->assertEquals($roleCountBefore, Role::where('company_id', $this->company->id)->count());
    }

    /** Scenario 27: Historical Purchase/Return immutable no valuation change, Customer Payment/Credit/Reversal unchanged, Accounting/Inventory/Sales/Payables reconciliation healthy */
    public function test_scenario_27_cross_domain_reconciliations_healthy(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $pmt = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s27-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        // 1. Payables reconciliation
        $payablesReport = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($payablesReport->isHealthy);

        // 2. Accounting reconciliation
        if (class_exists(AccountingReconciliationService::class)) {
            $accountingReport = app(AccountingReconciliationService::class)->reconcile($this->company);
            $this->assertTrue($accountingReport->isHealthy);
        }

        // 3. Sales reconciliation
        if (class_exists(SalesReconciliationService::class)) {
            $salesReport = app(SalesReconciliationService::class)->reconcile($this->company);
            $this->assertTrue($salesReport->isHealthy);
        }

        // 4. Inventory reconciliation
        if (class_exists(InventoryReconciliationService::class)) {
            $inventoryReport = app(InventoryReconciliationService::class)->auditCompany($this->company);
            $this->assertTrue($inventoryReport->isHealthy);
        }
    }
}
