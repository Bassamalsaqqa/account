<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Models\Expense;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Corrections\PurchaseCorrection;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase7\Phase7TestCase;

final class ReleaseReconciliationTest extends Phase7TestCase
{
    public function test_compound_purchase_correction_and_retry_preserve_all_six_reconciliations(): void
    {
        // Keep real unrelated sales, expense and payroll history in the same ledger.
        $invoice = $this->invoice('ILS', '1', '25');
        $expense = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id,
            'expense_date' => '2026-10-01', 'classification' => Expense::CLASSIFICATION_OPERATING,
            'description' => 'Release reconciliation expense', 'currency_code' => 'ILS',
            'amount' => '15', 'exchange_rate' => '1', 'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id, 'idempotency_key' => 'release-expense',
        ]);
        $salary = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'recognition_date' => '2026-10-05',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'base_salary' => '100',
            'bonus' => '0', 'deduction' => '0', 'advances' => [], 'idempotency_key' => 'release-salary',
        ]);
        $unrelated = [$invoice->fresh()->getAttributes(), $expense->fresh()->getAttributes(), $salary->fresh()->getAttributes()];
        $purchase = $this->createAndPostPurchase();
        $child = $this->createAndPostReturn($purchase);
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->cashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'currency_code' => 'ILS',
            'exchange_rate' => '1', 'amount' => '1', 'idempotency_key' => 'release-dedicated-payment',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '1']],
        ]);
        $this->assertSixHealthyAndReadOnly();

        $service = app(PurchaseCorrection::class);
        $preview = $service->preview($purchase, $this->owner, 'edit', 'Release qualification', '2026-11-07');
        $this->assertEmpty($preview['blockers']);
        $this->assertCount(1, $preview['returns']);
        $this->assertCount(1, $preview['payments']);
        $replacement = $service->execute($preview['token'], $this->owner);
        $this->assertTrue($replacement->isDraft());
        $this->assertTrue($purchase->fresh()->isVoid());
        $this->assertTrue($child->fresh()->isVoid());
        $this->assertTrue((bool) $payment->fresh()->is_reversed);
        $this->assertSame($unrelated, [$invoice->fresh()->getAttributes(), $expense->fresh()->getAttributes(), $salary->fresh()->getAttributes()]);
        $this->assertSixHealthyAndReadOnly();

        $beforeRetry = $this->databaseFingerprint();
        $this->assertSame($replacement->id, $service->execute($preview['token'], $this->owner)->id);
        $this->assertSame($beforeRetry, $this->databaseFingerprint(), 'Receipt replay changed persisted history');
        $this->assertSixHealthyAndReadOnly();
    }

    private function assertSixHealthyAndReadOnly(): void
    {
        $before = $this->databaseFingerprint();
        foreach ([AccountingReconciliationService::class, SalesReconciliationService::class,
            PayablesReconciliationService::class, MoneyReconciliationService::class,
            Phase7ReconciliationService::class] as $service) {
            $report = app($service)->reconcile($this->company);
            $this->assertTrue($report->isHealthy, $service.': '.json_encode($report->violations));
            $this->assertEmpty($report->violations, $service);
        }
        $inventory = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($inventory->isHealthy, json_encode($inventory->discrepancies));
        $this->assertEmpty($inventory->discrepancies);
        $this->assertSame($before, $this->databaseFingerprint(), 'Reconciliation changed persisted rows');
    }

    /** @return array<string, string> */
    private function databaseFingerprint(): array
    {
        $fingerprints = [];
        foreach (DB::select('SHOW TABLES') as $table) {
            $name = array_values((array) $table)[0];
            $rows = DB::table($name)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $fingerprints[$name] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }
        ksort($fingerprints);

        return $fingerprints;
    }
}
