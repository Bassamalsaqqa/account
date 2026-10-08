<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Application\Reporting\Queries\ProfitReportQuery;
use App\Application\Reporting\Queries\PurchasePriceHistoryReportQuery;
use App\Application\Reporting\Queries\PurchasesByProductReportQuery;
use App\Application\Reporting\Queries\SalesSummaryReportQuery;
use App\Models\LedgerAccount;
use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;

final class TradeFamilyContractTest extends TradeTestCase
{
    public function test_every_trade_report_executes_on_canonical_history_and_is_zero_write(): void
    {
        $purchase = $this->createAndPostPurchase();
        $this->createAndPostReturn($purchase);
        $invoice = $this->createAndPostSalesInvoice(['lines' => [['product_id' => $this->product->id, 'quantity' => '2', 'unit_price' => '50', 'discount_type' => 'fixed', 'discount_value' => '10']]]);
        $draft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, ['sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-05', 'lines' => [['sales_invoice_line_id' => $invoice->lines()->sole()->id, 'quantity' => '1']]]);
        app(PostSalesReturnAction::class)->execute($draft, $this->owner);
        $tables = ['purchases', 'purchase_lines', 'purchase_returns', 'purchase_return_lines', 'sales_invoices', 'sales_invoice_lines', 'sales_returns', 'sales_return_lines', 'posting_batches', 'posting_lines', 'stock_movements', 'inventory_balances', 'inventory_cost_states', 'document_sequences'];
        $snapshot = fn () => array_map(fn ($table) => hash('sha256', json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR)), $tables);
        $before = $snapshot();
        $queries = [];
        foreach (glob(app_path('Application/Reporting/Queries/*.php')) as $path) {
            $name = basename($path, '.php');
            if (! preg_match('/^(Sales|Customer|Purchase|Vendor)/', $name)) {
                continue;
            }
            $filters = [];
            if ($name === 'CustomerStatementReportQuery') {
                $filters = ['customer_id' => $this->defaultCustomer->id];
            }
            if ($name === 'VendorStatementReportQuery') {
                $filters = ['vendor_id' => $this->vendor->id];
            }
            $result = app('App\\Application\\Reporting\\Queries\\'.$name)->execute($this->company, $filters, $this->owner);
            $this->assertSame(['current_page', 'per_page', 'total', 'last_page'], array_keys($result->pagination), $name);
            $this->assertLessThanOrEqual(50, count($result->rows), $name);
            $this->assertIsString($result->generatedAt, $name);
            $queries[] = $name;
        }
        $this->assertCount(28, $queries);
        $this->assertSame($before, $snapshot());
    }

    public function test_tax_inclusive_net_sales_matches_gl_and_preserves_commercial_amount(): void
    {
        $this->createAndPostPurchase();
        $tax = TaxRate::create(['company_id' => $this->company->id, 'code' => 'INC16', 'name_ar' => 'VAT', 'rate' => '16', 'calculation' => 'inclusive', 'active' => true, 'sales_tax_account_id' => LedgerAccount::where('system_key', 'tax_output')->sole()->id]);
        $invoice = $this->createAndPostSalesInvoice(['lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '126', 'discount_type' => 'fixed', 'discount_value' => '10', 'tax_rate_id' => $tax->id]]]);
        $report = app(SalesSummaryReportQuery::class)->execute($this->company, [], $this->owner);
        $profit = app(ProfitReportQuery::class)->execute($this->company, [], $this->owner);
        $this->assertSame('116.000000', $report->totals['commercial_net_sales_base']);
        $this->assertSame('100.000000', $report->totals['net_sales_base']);
        $this->assertSame('100.000000', $report->totals['currencies']['ILS']['net_sales']);
        $this->assertSame($profit->totals['net_sales_base'], $report->totals['net_sales_base']);
        $this->assertSame('116.000000', $report->rows[0]['gross_sales_base']);
        $this->assertSame('100.000000', $report->rows[0]['net_sales_base']);
    }

    public function test_purchase_acquisition_excludes_recoverable_tax_but_raw_vendor_price_does_not(): void
    {
        $tax = TaxRate::create(['company_id' => $this->company->id, 'code' => 'IN16', 'name_ar' => 'Input VAT', 'rate' => '16', 'calculation' => 'inclusive', 'active' => true, 'purchase_tax_account_id' => LedgerAccount::where('system_key', 'tax_input')->sole()->id]);
        $purchase = $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '116', 'tax_rate_id' => $tax->id]]]);
        $price = app(PurchasePriceHistoryReportQuery::class)->execute($this->company, [], $this->owner);
        $byProduct = app(PurchasesByProductReportQuery::class)->execute($this->company, [], $this->owner);
        $this->assertSame('116.000000', $price->rows[0]['commercial_unit_price']);
        $this->assertSame('100.000000', $price->rows[0]['net_commercial_price_per_base_unit']);
        $this->assertSame('100.000000', $price->rows[0]['total_acquisition_cost_base']);
        $this->assertSame('116.000000',$byProduct->totals['commercial_total_base']);
        $this->assertSame('100.000000',$byProduct->totals['total_acquisition_cost_base']);
    }
}
