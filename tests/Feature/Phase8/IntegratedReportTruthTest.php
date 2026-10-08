<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Expenses\ReverseExpenseAction;
use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Purchasing\AllocateLandedCostAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Presentation\DashboardReports;
use App\Application\Reporting\Queries\CustomerBalancesReportQuery;
use App\Application\Reporting\Queries\InventoryValuationReportQuery;
use App\Application\Reporting\Queries\PayrollAdvanceReportQuery;
use App\Application\Reporting\Queries\PayrollSummaryReportQuery;
use App\Application\Reporting\Queries\ProfitReportQuery;
use App\Application\Reporting\Queries\PurchaseSummaryReportQuery;
use App\Application\Reporting\Queries\SalesSummaryReportQuery;
use App\Application\Reporting\Queries\VendorBalancesReportQuery;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** One canonical multi-domain history shared by report truth/zero-write checks. */
class IntegratedReportTruthTest extends Phase8TestCase
{
    public function test_integrated_history_profit_inventory_and_reads_preserve_every_economic_row(): void
    {
        $this->buildHistory();
        $before = $this->economicFingerprint();
        $period = ReportPeriod::custom('2026-10-01', '2026-11-30', $this->company);

        // 1. Profit Query
        $profit = app(ProfitReportQuery::class)->execute($this->company, ['period' => $period], $this->owner);
        $this->assertSame('630.000000', $profit->netSales);
        $this->assertSame('161.000000', $profit->cogs);
        $this->assertSame('30.000000', $profit->operatingExpenses);
        $this->assertSame('350.000000', $profit->salaryExpense);
        $this->assertSame('38.500000', $profit->inventoryLoss);
        $this->assertSame('22.000000', $profit->fxLoss);
        $this->assertSame('28.500000', $profit->netProfit);

        // 2. Inventory Reconciliation & Valuation Report
        $audit = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($audit->isHealthy, json_encode($audit->discrepancies));
        $this->assertSame('506.500000', $audit->totalValuationBase);
        $stock = DB::table('stock_movements')->where('company_id', $this->company->id)
            ->selectRaw('SUM(value_delta_base) AS value_delta_base')->first();
        $this->assertSame('506.500000', (string) BigDecimal::of($stock->value_delta_base)->toScale(6));

        $valReport = app(InventoryValuationReportQuery::class)->execute($this->company, [], $this->owner);
        $this->assertSame('506.500000', (string) BigDecimal::of((string) ($valReport->totals['total_valuation'] ?? '0'))->toScale(6));

        // 3. Trade Reports (Sales & Purchases)
        $salesReport = app(SalesSummaryReportQuery::class)->execute($this->company, ['period' => $period], $this->owner);
        $this->assertSame('630.000000', $salesReport->totals['net_sales_base']);

        $purchaseReport = app(PurchaseSummaryReportQuery::class)->execute($this->company, ['period' => $period], $this->owner);
        $this->assertNotEmpty($purchaseReport->rows);

        // 4. Payroll Reports: Remaining Salary Payable (50 USD / 175 ILS) & Unused Advance (10 USD / 35 ILS)
        $salaryReport = app(PayrollSummaryReportQuery::class)->execute($this->company, [], $this->owner);
        $salaryRow = $salaryReport->rows[0];
        $this->assertSame('50.000000', $salaryRow['remaining_unpaid']);
        $this->assertSame('175.000000', $salaryRow['remaining_unpaid_base']);

        $advanceReport = app(PayrollAdvanceReportQuery::class)->execute($this->company, [], $this->owner);
        $advanceRow = $advanceReport->rows[0];
        $this->assertSame('10.000000', $advanceRow['remaining_amount']);
        $this->assertSame('35.000000', $advanceRow['remaining_base_amount']);

        // 5. Positions (Customer & Vendor Balances)
        $custBalances = app(CustomerBalancesReportQuery::class)->execute($this->company, [], $this->owner);
        $this->assertNotEmpty($custBalances->rows);

        $vendBalances = app(VendorBalancesReportQuery::class)->execute($this->company, [], $this->owner);
        $this->assertNotEmpty($vendBalances->rows);

        // 6. Dashboard
        $dashboard = app(DashboardReports::class)->read($this->company, 'this_month');
        $this->assertNotEmpty($dashboard['activity']);
        $this->assertNotEmpty($dashboard['positions']);
        $this->assertNotEmpty($dashboard['alerts']);

        // 7. CSV Export Execution
        $response = $this->get(route('reports.export', ['reportKey' => 'profit', 'filters' => ['period' => $period->toArray()]]));
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('28.500000', $csv);

        // 8. Domain Reconciliations
        $acctAudit = app(AccountingReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($acctAudit->isHealthy, json_encode($acctAudit->violations));

        $p7Audit = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($p7Audit->isHealthy, json_encode($p7Audit->violations));

        $salesAudit = app(SalesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($salesAudit->isHealthy, json_encode($salesAudit->violations));

        $payablesAudit = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($payablesAudit->isHealthy, json_encode($payablesAudit->violations));

        $moneyAudit = app(MoneyReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($moneyAudit->isHealthy, json_encode($moneyAudit->violations));

        // 9. Full Row Hashes Detect Mutations as Well as Inserts/Deletes (Zero Economic Writes)
        $this->assertSame($before, $this->economicFingerprint());
    }

    private function buildHistory(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $landed = $this->expense('70', 'landed_cost', 'integrated-landed');
        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-02', 'currency_code' => 'USD', 'exchange_rate' => '3.50',
            'document_locale' => 'ar',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '20', 'unit_cost' => '10']],
        ]);
        app(AllocateLandedCostAction::class)->execute($purchase, $landed, 'value', $this->owner);
        $purchase = app(PostPurchaseAction::class)->execute($purchase, $this->owner);
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل مرجعي',
            'name_en' => 'Reference Customer', 'active' => true, 'created_by' => $this->owner->id]);
        $sale = $this->sale($customer, 'ILS', '1', '70', $this->product->id, '5');
        $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'sales_invoice_id' => $sale->id, 'currency_code' => 'ILS',
            'exchange_rate' => '1', 'issue_date' => '2026-10-04',
            'lines' => [['sales_invoice_line_id' => $sale->lines->sole()->id, 'quantity' => '1', 'unit_price' => '70']],
        ]);
        app(PostSalesReturnAction::class)->execute($return, $this->owner);
        $this->createAndPostReturn($purchase, ['return_date' => '2026-10-05', 'lines' => [['quantity' => '2']]]);

        $service = $this->sale($customer, 'USD', '3.50', '100');
        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-06', 'payment_method' => 'cash', 'amount' => '330', 'exchange_rate' => '1',
            'idempotency_key' => 'integrated-receipt',
            'allocations' => [['sales_invoice_id' => $service->id, 'allocated_amount' => '100', 'payment_currency_amount' => '330']],
        ]);
        app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-06', 'payment_method' => 'cash', 'amount' => '72', 'exchange_rate' => '1',
            'idempotency_key' => 'integrated-vendor-payment',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '20', 'payment_currency_amount' => '72']],
        ]);
        app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, [
            'from_money_account_id' => $this->usdCashAccount->id, 'to_money_account_id' => $this->usdBankAccount->id,
            'transfer_date' => '2026-10-07', 'from_amount' => '20', 'to_amount' => '20',
            'from_exchange_rate' => '3.50', 'to_exchange_rate' => '3.50', 'idempotency_key' => 'integrated-transfer',
        ]);
        $incoming = $this->check();
        $this->event($incoming, 'deposit');
        $this->event($incoming->fresh(), 'clear');
        $outgoing = $this->check('outgoing');
        $this->event($outgoing, 'clear');
        $this->expense('30', 'operating', 'integrated-operating');
        $reversed = $this->expense('10', 'operating', 'integrated-reversed');
        app(ReverseExpenseAction::class)->execute($reversed, $this->owner, 'Corrected', '2026-10-10');

        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'advance_date' => '2026-10-01', 'currency_code' => 'USD',
            'amount' => '20', 'exchange_rate' => '3.50', 'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id, 'idempotency_key' => 'integrated-advance',
        ]);
        $salary = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'recognition_date' => '2026-10-08',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'currency_code' => 'USD',
            'exchange_rate' => '3.50', 'base_salary' => '100', 'bonus' => '0', 'deduction' => '0',
            'advances' => [['advance_id' => $advance->id, 'allocated_amount' => '10']], 'idempotency_key' => 'integrated-salary',
        ]);
        app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'payment_date' => '2026-10-09', 'currency_code' => 'USD',
            'amount' => '40', 'exchange_rate' => '3.50', 'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'allocations' => [['salary_entry_id' => $salary->id, 'allocated_amount' => '40']],
            'idempotency_key' => 'integrated-salary-payment',
        ]);

        $destination = Warehouse::create(['company_id' => $this->company->id, 'name_ar' => 'مستودع مرجعي',
            'name_en' => 'Reference Warehouse', 'code' => 'REF', 'active' => true, 'created_by' => $this->owner->id]);
        app(InventoryMovementService::class)->transfer(new StockTransferCommand(
            companyId: (int) $this->company->id, sourceWarehouseId: (int) $this->warehouse->id,
            destinationWarehouseId: (int) $destination->id, movementDate: '2026-10-10',
            lines: [new StockTransferLineCommand((int) $this->product->id, Quantity::of('2'))],
            idempotencyKey: 'integrated-stock-transfer', createdBy: (int) $this->owner->id,
        ));
        app(AdjustStockAction::class)->execute($this->company, $this->product, $this->warehouse,
            StockMovement::TYPE_DAMAGE_OR_LOSS, Quantity::of('1'), 'Damaged', $this->owner,
            'integrated-loss', movementDate: '2026-10-11');
        $expiryProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صنف ذو صلاحية', 'name_en' => 'Expiry Product', 'sku' => 'REF-EXP',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => true,
            'base_unit_id' => $this->product->base_unit_id,
        ], $this->owner->id);
        app(PostOpeningStockAction::class)->execute($this->company, $expiryProduct, $this->warehouse,
            Quantity::of('3'), '2', $this->owner, 'integrated-expiry-opening',
            lotNumber: 'REF-LOT', expiryDate: '2026-10-31', movementDate: '2026-10-01');

        $laterVoid = $this->sale($customer, 'JOD', '5', '0.001');
        Carbon::setTestNow('2026-11-02 12:00:00');
        app(VoidSalesInvoiceAction::class)->execute($laterVoid, $this->owner, 'Later-period void');
    }

    private function sale(Customer $customer, string $currency, string $rate, string $price,
        ?int $productId = null, string $quantity = '1'): SalesInvoice
    {
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'currency_code' => $currency, 'exchange_rate' => $rate,
            'issue_date' => '2026-10-03',
            'lines' => [['product_id' => $productId, 'item_description' => 'Reference Service',
                'quantity' => $quantity, 'unit_price' => $price]],
        ]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    private function expense(string $amount, string $classification, string $key): Expense
    {
        return app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $classification === 'landed_cost' ? $this->landedCategory->id : $this->operatingCategory->id,
            'expense_date' => '2026-10-01', 'classification' => $classification, 'description' => $key,
            'currency_code' => 'ILS', 'amount' => $amount, 'exchange_rate' => '1',
            'payment_method' => 'cash', 'money_account_id' => $this->ilsCashAccount->id, 'idempotency_key' => $key,
        ]);
    }

    /** @return array<string, string> */
    private function economicFingerprint(): array
    {
        $hashes = [];
        foreach (['purchases', 'purchase_lines', 'purchase_returns', 'purchase_return_lines', 'sales_invoices',
            'sales_invoice_lines', 'sales_returns', 'sales_return_lines', 'posting_batches', 'posting_lines',
            'stock_movements', 'inventory_balances', 'inventory_cost_states', 'inventory_lots', 'inventory_lot_balances',
            'customer_payments', 'customer_payment_allocations', 'vendor_payments', 'vendor_payment_allocations',
            'money_transfers', 'checks', 'check_events', 'expenses', 'employee_advances', 'salary_entries',
            'salary_advance_allocations', 'salary_payments', 'salary_payment_allocations', 'landed_cost_allocations',
            'document_sequences'] as $table) {
            $hashes[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
        }

        return $hashes;
    }
}
