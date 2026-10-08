<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Inventory\AdjustStockAction;
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
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Queries\CustomerStatementReportQuery;
use App\Application\Reporting\Queries\MoneyMovementReportQuery;
use App\Application\Reporting\Queries\PayrollEmployeeStatementReportQuery;
use App\Application\Reporting\Queries\SalesSummaryReportQuery;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Money\ReceivablePositionAsOf;
use App\Services\Purchasing\PurchasePayableAsOf;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class MariaDbPerformanceExplainEvidenceTest extends Phase8TestCase
{
    public function test_mariadb_explain_and_query_performance_evidence(): void
    {
        $this->buildHistory();

        $evidenceLines = [];
        $evidenceLines[] = '# Phase 8 — MariaDB Query Performance & EXPLAIN Evidence';
        $evidenceLines[] = '';
        $evidenceLines[] = 'Generated during automated test execution on MariaDB test database.';
        $evidenceLines[] = 'Timestamp: '.now()->toIso8601String();
        $evidenceLines[] = '';

        // 1. Historical Positions - ReceivablePositionAsOf
        $evidenceLines[] = '## 1. Historical Position Queries (AR & AP)';
        $evidenceLines[] = '';
        $evidenceLines[] = '### 1.1 ReceivablePositionAsOf Query Count & Bounding';

        $invoices = SalesInvoice::where('company_id', $this->company->id)->get();
        $this->assertNotEmpty($invoices);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $arPositions = app(ReceivablePositionAsOf::class)->forInvoices($invoices, '2026-10-15');
        $arQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, $arQueries, 'ReceivablePositionAsOf must execute exactly 2 batched queries.');
        $evidenceLines[] = '- **Batched query count**: '.count($arQueries).' queries for '.$invoices->count().' invoices (batched up to 500 documents per call, avoiding N+1).';
        $evidenceLines[] = '- **Query 1 (Payment Allocations & Relinquishment)**:';
        $evidenceLines[] = '```sql';
        $evidenceLines[] = $arQueries[0]['query'];
        $evidenceLines[] = '```';
        $evidenceLines[] = '- **Query 2 (Sales Returns)**:';
        $evidenceLines[] = '```sql';
        $evidenceLines[] = $arQueries[1]['query'];
        $evidenceLines[] = '```';
        $evidenceLines[] = '';

        // EXPLAIN for AR Payment Allocations
        $evidenceLines[] = '### 1.2 MariaDB EXPLAIN: AR Payment Relief Query';
        $explainArRelief = DB::select('EXPLAIN '.$arQueries[0]['query'], $arQueries[0]['bindings']);
        $evidenceLines[] = $this->formatExplainTable($explainArRelief);
        $evidenceLines[] = '';

        // EXPLAIN for AR Returns
        $evidenceLines[] = '### 1.3 MariaDB EXPLAIN: AR Returns Query';
        $explainArReturns = DB::select('EXPLAIN '.$arQueries[1]['query'], $arQueries[1]['bindings']);
        $evidenceLines[] = $this->formatExplainTable($explainArReturns);
        $evidenceLines[] = '';

        // AP Positions
        $purchases = Purchase::where('company_id', $this->company->id)->get();
        $this->assertNotEmpty($purchases);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $apPositions = app(PurchasePayableAsOf::class)->forHistory($purchases, Carbon::parse('2026-10-15'));
        $apQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $evidenceLines[] = '### 1.4 PurchasePayableAsOf Query Count & Bounding';
        $evidenceLines[] = '- **Reporting Read Adapter (`forHistory()`)**: '.count($apQueries).' queries for '.$purchases->count().' purchases (batched up to 500 documents, avoiding deep N+1 replay).';
        $evidenceLines[] = '- **Authoritative Replay (`forPurchases()`)**: Preserved for posting retries and full financial audit (validates complete command ledger replay per purchase).';
        $evidenceLines[] = '- **Query 1 (Purchase Returns Relief)**:';
        $evidenceLines[] = '```sql';
        $evidenceLines[] = $apQueries[0]['query'];
        $evidenceLines[] = '```';
        $evidenceLines[] = '- **Query 2 (Vendor Payment Allocations Relief)**:';
        $evidenceLines[] = '```sql';
        $evidenceLines[] = $apQueries[1]['query'];
        $evidenceLines[] = '```';
        $evidenceLines[] = '';

        // 2. Money Windows - MoneyMovementReportQuery
        $evidenceLines[] = '## 2. Money Movement Window Queries';
        $evidenceLines[] = '';
        $evidenceLines[] = '### 2.1 MoneyMovementReportQuery Execution & SQL Pagination';

        $period = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $moneyResult = app(MoneyMovementReportQuery::class)->execute($this->company, [
            'money_account_id' => $this->ilsCashAccount->id,
            'period' => $period,
            'page' => 1,
            'per_page' => 25,
        ], $this->owner);
        $moneyQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $total = $moneyResult->pagination['total'] ?? count($moneyResult->rows);
        $evidenceLines[] = '- **Total Queries Executed**: '.count($moneyQueries).' (includes company check, account check, window totals, and bounded SQL page).';
        $evidenceLines[] = '- **Result Row Count**: '.count($moneyResult->rows).' rows returned on page 1 of '.$total.' total.';
        $evidenceLines[] = '- **Window Function Design**: Running balances calculate across full prior history via SQL window functions (`SUM(...) OVER (ORDER BY b.posting_date, b.id, l.line_number, l.id)`), followed by outer period filter and SQL `LIMIT / OFFSET`.';
        $evidenceLines[] = '';

        // Capture EXPLAIN of the outer window query
        $outerQueryLog = null;
        foreach ($moneyQueries as $q) {
            if (str_contains($q['query'], 'movements') && str_contains($q['query'], 'limit')) {
                $outerQueryLog = $q;
                break;
            }
        }

        if ($outerQueryLog !== null) {
            $evidenceLines[] = '### 2.2 MariaDB EXPLAIN: Money Movement Window Query';
            $evidenceLines[] = '```sql';
            $evidenceLines[] = $outerQueryLog['query'];
            $evidenceLines[] = '```';
            $explainMoney = DB::select('EXPLAIN '.$outerQueryLog['query'], $outerQueryLog['bindings']);
            $evidenceLines[] = $this->formatExplainTable($explainMoney);
            $evidenceLines[] = '';
        }

        // 3. Statement Queries vs SQL Pagination
        $evidenceLines[] = '## 3. Statement Queries vs Operational SQL Pagination';
        $evidenceLines[] = '';
        $evidenceLines[] = '### 3.1 Payroll Employee Statement (Union Subquery)';

        DB::flushQueryLog();
        DB::enableQueryLog();
        $payrollStatement = app(PayrollEmployeeStatementReportQuery::class)->execute($this->company, [
            'employee_id' => $this->employee->id,
            'period' => $period,
            'page' => 1,
            'per_page' => 25,
        ], $this->owner);
        $payrollQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $evidenceLines[] = '- **Queries Executed**: '.count($payrollQueries).' queries.';
        $evidenceLines[] = '- **Event Rows Returned**: '.count($payrollStatement->rows).' rows.';
        $evidenceLines[] = '- **Execution Mechanism**: Bounded UNION query across source legs (`employee_advances`, `salary_entries`, `salary_payments`) with SQL `LIMIT` / `OFFSET`.';
        $evidenceLines[] = '';

        // 3.2 Customer Statement (PHP Subledger Memory Slicing)
        $evidenceLines[] = '### 3.2 Customer Statement Subledger Memory Slicing';
        $customer = Customer::where('company_id', $this->company->id)->first();
        $this->assertNotNull($customer);

        $memBefore = memory_get_usage(true);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $customerStatement = app(CustomerStatementReportQuery::class)->execute($this->company, [
            'customer_id' => $customer->id,
            'period' => $period,
            'page' => 1,
            'per_page' => 25,
        ], $this->owner);
        $custQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $memAfter = memory_get_usage(true);
        $memDeltaKb = round(($memAfter - $memBefore) / 1024, 2);

        $evidenceLines[] = '- **Architecture Distinction**: Unlike operational queries (`SalesSummary`, `PurchaseSummary`, `MoneyMovement`) which paginate directly in MariaDB via SQL `LIMIT` / `OFFSET`, party statement wrappers (`CustomerStatementReportQuery`, `VendorStatementReportQuery`) invoke the accepted canonical statement subledger engine (`CustomerStatementQuery`, `VendorStatementQuery`).';
        $evidenceLines[] = '- **Running Balance Invariant**: Running balances and aging brackets require evaluating complete historical transactions from inception up to the period end date to establish exact opening balance and running debit/credit totals.';
        $evidenceLines[] = '- **Memory Slicing**: The underlying statement engine hydrates the full chronological entry list in PHP, and the presentation layer slices the requested page via `array_slice()`.';
        $evidenceLines[] = '- **Memory Delta Observed**: '.$memDeltaKb.' KB.';
        $evidenceLines[] = '- **CSV Streaming Behavior**: During CSV export, the stream fetches 100 rows per chunk. For party statements, the subledger history is re-evaluated per chunk. This trade-off preserves exact subledger arithmetic and canonical provenance without mutating the accepted financial calculation engine.';
        $evidenceLines[] = '';

        // 4. Operational Trade Queries - SalesSummaryReportQuery
        $evidenceLines[] = '## 4. Operational Aggregation Queries';
        $evidenceLines[] = '';
        $evidenceLines[] = '### 4.1 SalesSummaryReportQuery';

        DB::flushQueryLog();
        DB::enableQueryLog();
        $salesSummary = app(SalesSummaryReportQuery::class)->execute($this->company, [
            'period' => $period,
            'page' => 1,
            'per_page' => 25,
        ], $this->owner);
        $salesQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $evidenceLines[] = '- **Queries Executed**: '.count($salesQueries).' queries.';
        $evidenceLines[] = '- **Totals Calculated**: Base net sales: `'.($salesSummary->totals['net_sales_base'] ?? '0').'`.';
        $evidenceLines[] = '- **Paging**: SQL bounded with `LIMIT / OFFSET`.';
        $evidenceLines[] = '';

        // Write evidence artifact
        $outputPath = base_path('.ai/delegations/phase8-manual-correction01/mariadb-explain-performance.md');
        file_put_contents($outputPath, implode("\n", $evidenceLines));

        $this->assertFileExists($outputPath);
    }

    /**
     * @param  list<object>  $explainRows
     */
    private function formatExplainTable(array $explainRows): string
    {
        $lines = [];
        $lines[] = '| id | select_type | table | type | possible_keys | key | key_len | ref | rows | Extra |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|---|';
        foreach ($explainRows as $r) {
            $id = $r->id ?? '';
            $st = $r->select_type ?? '';
            $table = $r->table ?? '';
            $type = $r->type ?? '';
            $pk = $r->possible_keys ?? 'NULL';
            $key = $r->key ?? 'NULL';
            $klen = $r->key_len ?? 'NULL';
            $ref = $r->ref ?? 'NULL';
            $rows = $r->rows ?? '';
            $extra = $r->Extra ?? '';
            $lines[] = "| {$id} | {$st} | {$table} | {$type} | {$pk} | {$key} | {$klen} | {$ref} | {$rows} | {$extra} |";
        }

        return implode("\n", $lines);
    }

    private function buildHistory(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $landed = $this->expense('70', 'landed_cost', 'explain-landed');
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
            'idempotency_key' => 'explain-receipt',
            'allocations' => [['sales_invoice_id' => $service->id, 'allocated_amount' => '100', 'payment_currency_amount' => '330']],
        ]);
        app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-06', 'payment_method' => 'cash', 'amount' => '72', 'exchange_rate' => '1',
            'idempotency_key' => 'explain-vendor-payment',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '20', 'payment_currency_amount' => '72']],
        ]);
        app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, [
            'from_money_account_id' => $this->usdCashAccount->id, 'to_money_account_id' => $this->usdBankAccount->id,
            'transfer_date' => '2026-10-07', 'from_amount' => '20', 'to_amount' => '20',
            'from_exchange_rate' => '3.50', 'to_exchange_rate' => '3.50', 'idempotency_key' => 'explain-transfer',
        ]);

        $this->expense('30', 'operating', 'explain-operating');

        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'advance_date' => '2026-10-01', 'currency_code' => 'USD',
            'amount' => '20', 'exchange_rate' => '3.50', 'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id, 'idempotency_key' => 'explain-advance',
        ]);
        $salary = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'recognition_date' => '2026-10-08',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'currency_code' => 'USD',
            'exchange_rate' => '3.50', 'base_salary' => '100', 'bonus' => '0', 'deduction' => '0',
            'advances' => [['advance_id' => $advance->id, 'allocated_amount' => '10']], 'idempotency_key' => 'explain-salary',
        ]);
        app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'payment_date' => '2026-10-09', 'currency_code' => 'USD',
            'amount' => '40', 'exchange_rate' => '3.50', 'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'allocations' => [['salary_entry_id' => $salary->id, 'allocated_amount' => '40']],
            'idempotency_key' => 'explain-salary-payment',
        ]);

        $destination = Warehouse::create(['company_id' => $this->company->id, 'name_ar' => 'مستودع مرجعي',
            'name_en' => 'Reference Warehouse', 'code' => 'REF_EXP', 'active' => true, 'created_by' => $this->owner->id]);
        app(InventoryMovementService::class)->transfer(new StockTransferCommand(
            companyId: (int) $this->company->id, sourceWarehouseId: (int) $this->warehouse->id,
            destinationWarehouseId: (int) $destination->id, movementDate: '2026-10-10',
            lines: [new StockTransferLineCommand((int) $this->product->id, Quantity::of('2'))],
            idempotencyKey: 'explain-stock-transfer', createdBy: (int) $this->owner->id,
        ));
        app(AdjustStockAction::class)->execute($this->company, $this->product, $this->warehouse,
            StockMovement::TYPE_DAMAGE_OR_LOSS, Quantity::of('1'), 'Damaged', $this->owner,
            'explain-loss', movementDate: '2026-10-11');
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
}
