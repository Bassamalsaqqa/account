<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Purchasing\AllocateLandedCostAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Models\Expense;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\SalaryAdvanceAllocation;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Phase7ReconciliationTest extends Phase7TestCase
{
    public function test_comprehensive_phase7_fixture_reconciles_cleanly(): void
    {
        // 1. Operating expense
        $operatingExpense = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id,
            'expense_date' => '2026-10-01',
            'classification' => Expense::CLASSIFICATION_OPERATING,
            'description' => 'فاتورة الكهرباء لشهر أكتوبر',
            'currency_code' => 'ILS',
            'amount' => '150.000000',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'recon-exp-'.uniqid(),
        ]);

        // 2. Employee advance
        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'advance_date' => '2026-10-01',
            'currency_code' => 'USD',
            'amount' => '200.000000',
            'exchange_rate' => '3.5000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'idempotency_key' => 'recon-adv-'.uniqid(),
        ]);

        // 3. Salary entry consuming part of the advance
        $salaryEntry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-10-05',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'base_salary' => '1000.000000',
            'bonus' => '100.000000',
            'deduction' => '50.000000',
            'advances' => [
                ['advance_id' => $advance->id, 'allocated_amount' => '100.000000'],
            ],
            'idempotency_key' => 'recon-sal-'.uniqid(),
        ]);

        // 4. Salary payment paying part of the net salary
        $salaryPayment = app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'payment_date' => '2026-10-06',
            'currency_code' => 'USD',
            'amount' => '500.000000',
            'exchange_rate' => '3.5000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'allocations' => [
                ['salary_entry_id' => $salaryEntry->id, 'allocated_amount' => '500.000000'],
            ],
            'idempotency_key' => 'recon-slp-'.uniqid(),
        ]);

        // 5. Landed cost expense and capitalized purchase
        $landedExpense = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->landedCategory->id,
            'expense_date' => '2026-10-02',
            'classification' => Expense::CLASSIFICATION_LANDED_COST,
            'description' => 'شحن وتخليص',
            'currency_code' => 'ILS',
            'amount' => '80.000000',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'recon-landed-'.uniqid(),
        ]);

        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد عام',
            'name_en' => 'General Vendor',
        ]);
        $pieceUnit = Unit::where('code', 'piece')->firstOrFail();
        $product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صنف أ',
            'name_en' => 'Product A',
            'sku' => 'SKU-RECON-1',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id,
            'default_purchase_cost_base' => '50.000000',
        ], $this->owner->id);
        $warehouse = Warehouse::firstOrFail();

        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'vendor_invoice_number' => 'INV-RECON-'.uniqid(),
            'purchase_date' => '2026-10-03',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'unit_id' => $pieceUnit->id,
                    'quantity' => '10.000000',
                    'unit_cost' => '50.000000',
                ],
            ],
        ]);

        app(AllocateLandedCostAction::class)->execute($purchase, $landedExpense, 'value', $this->owner);
        app(PostPurchaseAction::class)->execute($purchase, $this->owner);

        // Reconcile via service
        $report = app(Phase7ReconciliationService::class)->reconcile($this->company);

        $this->assertTrue($report->isHealthy);
        $this->assertEmpty($report->violations);
        $this->assertArrayHasKey('expenses_count', $report->stats);
        $this->assertArrayHasKey('advances_count', $report->stats);
        $this->assertArrayHasKey('salary_entries_count', $report->stats);
        $this->assertArrayHasKey('salary_payments_count', $report->stats);
        $this->assertArrayHasKey('landed_cost_allocations_count', $report->stats);

        // Reconcile via artisan command
        $this->artisan('phase7:reconcile', ['companyPublicId' => $this->company->public_id])
            ->assertExitCode(0);
    }

    public function test_orphan_batch_is_detected(): void
    {
        // Insert a rogue posting batch claiming to be an expense
        $rogueBatchId = DB::table('posting_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'source_type' => 'expense',
            'source_id' => 999999,
            'batch_number' => 'PB-ROGUE-001',
            'status' => PostingBatch::STATUS_POSTED,
            'posting_date' => '2026-10-01',
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'rogue-'.uniqid(),
            'posted_by' => $this->owner->id,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'Canonical source provenance failure expense batch '.$rogueBatchId)));
    }

    public function test_advance_over_allocation_is_detected(): void
    {
        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'advance_date' => '2026-10-01',
            'currency_code' => 'USD',
            'amount' => '100.000000',
            'exchange_rate' => '3.5000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'idempotency_key' => 'recon-adv-tamper-'.uniqid(),
        ]);

        $salaryEntry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-10-05',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'base_salary' => '1000.000000',
            'bonus' => '0.000000',
            'deduction' => '0.000000',
            'advances' => [],
            'idempotency_key' => 'recon-sal-adv-'.uniqid(),
        ]);

        // Maliciously insert an allocation exceeding the advance amount
        DB::table('salary_advance_allocations')->insert([
            'public_id' => (string) Str::ulid(),
            'created_at' => now(),
            'company_id' => $this->company->id,
            'employee_advance_id' => $advance->id,
            'salary_entry_id' => $salaryEntry->id,
            'allocated_amount' => '150.000000',
            'advance_base_consumed' => '525.000000',
            'salary_base_relief' => '525.000000',
            'realized_fx_gain_loss_base' => '0.000000',
            'status' => SalaryAdvanceAllocation::STATUS_ACTIVE,
        ]);

        $report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'residual exceeds principal')));
    }

    public function test_overlapping_active_salary_entries_are_detected(): void
    {
        app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-10-05',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-15',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'base_salary' => '500.000000',
            'bonus' => '0.000000',
            'deduction' => '0.000000',
            'advances' => [],
            'idempotency_key' => 'recon-sal-p1-'.uniqid(),
        ]);

        // Manually bypass domain lock and insert overlapping entry directly in DB
        DB::table('salary_entries')->insert([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'salary_number' => 'SAL-OVERLAP-1',
            'employee_snapshot' => json_encode(['id' => $this->employee->id, 'name' => $this->employee->full_name]),
            'status' => 'posted',
            'recognition_date' => '2026-10-10',
            'period_start' => '2026-10-10',
            'period_end' => '2026-10-25',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'base_salary' => '500.000000',
            'bonus' => '0.000000',
            'deduction' => '0.000000',
            'earned_salary' => '500.000000',
            'advance_applied' => '0.000000',
            'net_payable' => '500.000000',
            'base_earned_salary' => '1750.000000',
            'base_advance_relief' => '0.000000',
            'base_payable' => '1750.000000',
            'realized_fx_gain_loss_base' => '0.000000',
            'request_hash' => hash('sha256', 'dummy'),
            'idempotency_key' => 'recon-sal-overlap-'.uniqid(),
            'posted_at' => now(),
            'posted_by' => $this->owner->id,
            'created_by' => $this->owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'Overlapping salary periods')));
    }
}
