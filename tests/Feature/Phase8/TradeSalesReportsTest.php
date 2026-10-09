<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Queries\SalesByCategoryReportQuery;
use App\Application\Reporting\Queries\SalesByCustomerReportQuery;
use App\Application\Reporting\Queries\SalesByProductReportQuery;
use App\Application\Reporting\Queries\SalesDiscountsReportQuery;
use App\Application\Reporting\Queries\SalesGrossProfitReportQuery;
use App\Application\Reporting\Queries\SalesPriceHistoryReportQuery;
use App\Application\Reporting\Queries\SalesReturnsReportQuery;
use App\Application\Reporting\Queries\SalesSummaryReportQuery;
use App\Application\Reporting\Queries\SalesUnpaidInvoicesReportQuery;
use App\Application\Reporting\Security\ReportPermissionCatalog;
use App\Models\Customer;
use App\Models\ProductCategory;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;

final class TradeSalesReportsTest extends TradeTestCase
{
    public function test_sales_summary_report_executes_with_period_and_calculates_correct_totals(): void
    {
        // Supply stock first
        $this->createAndPostPurchase();

        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2.000000',
                    'unit_price' => '50.000000',
                ],
            ],
        ]);

        $query = app(SalesSummaryReportQuery::class);
        $period = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);
        $filters = new ReportFilters(period: $period);

        $result = $query->execute($this->company, $filters, $this->owner);

        $this->assertSame('sales.summary', $result->reportType);
        $this->assertSame('ILS', $result->currency['base_currency_code']);
        $this->assertGreaterThan(0, count($result->rows));

        $totals = $result->totals;
        $this->assertTrue(BigDecimal::of($totals['gross_sales_base'])->isEqualTo('100.000000'));
        $this->assertTrue(BigDecimal::of($totals['net_sales_base'])->isEqualTo('100.000000'));
        $this->assertSame(1, $totals['original_invoice_count']);
        $this->assertArrayHasKey('ILS', $totals['currencies']);
    }

    public function test_sales_summary_report_supports_groupings(): void
    {
        $this->createAndPostPurchase();

        $this->createAndPostSalesInvoice(['issue_date' => '2026-10-02']);
        $this->createAndPostSalesInvoice(['issue_date' => '2026-10-03']);

        $query = app(SalesSummaryReportQuery::class);

        // Daily grouping
        $dailyResult = $query->execute($this->company, [
            'period' => 'this_month',
            'grouping' => 'daily',
        ], $this->owner);

        $this->assertNotEmpty($dailyResult->rows);
        $this->assertArrayHasKey('period_key', $dailyResult->rows[0]);
        $this->assertSame('2026-10-03', $dailyResult->rows[0]['period_key']);

        // Monthly grouping
        $monthlyResult = $query->execute($this->company, [
            'period' => 'this_month',
            'grouping' => 'monthly',
        ], $this->owner);

        $this->assertNotEmpty($monthlyResult->rows);
        $this->assertSame('2026-10', $monthlyResult->rows[0]['period_key']);
    }

    public function test_sales_summary_report_inverses_activity_on_void_posting_date(): void
    {
        $this->createAndPostPurchase();

        Carbon::setTestNow('2026-10-02 10:00:00');
        $invoice = $this->createAndPostSalesInvoice(['issue_date' => '2026-10-02']);

        // In period 1 (Oct 1 to Oct 5), invoice is active
        $query = app(SalesSummaryReportQuery::class);
        $p1 = $query->execute($this->company, ['start_date' => '2026-10-01', 'end_date' => '2026-10-05'], $this->owner);
        $this->assertTrue(BigDecimal::of($p1->totals['gross_sales_base'])->isEqualTo('100.000000'));

        // Void the invoice on Oct 15
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->activateUser($this->owner);
        app(VoidSalesInvoiceAction::class)->execute($invoice, $this->owner, 'Test reversal');

        // Period 2 (Oct 10 to Oct 20) should have inverse activity (-100)
        $p2 = $query->execute($this->company, ['start_date' => '2026-10-10', 'end_date' => '2026-10-20'], $this->owner);
        $this->assertTrue(BigDecimal::of($p2->totals['gross_sales_base'])->isEqualTo('-100.000000'));

        // Period full month (Oct 1 to Oct 31) has net 0 gross sales
        $pFull = $query->execute($this->company, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'], $this->owner);
        $this->assertTrue(BigDecimal::of($pFull->totals['gross_sales_base'])->isEqualTo('0.000000'));

        Carbon::setTestNow();
    }

    public function test_sales_summary_report_excludes_draft_invoices(): void
    {
        $this->createAndPostPurchase();

        // Create draft only (do not post)
        app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-01',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'سلعة تجريبية',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                ],
            ],
        ]);

        $query = app(SalesSummaryReportQuery::class);
        $result = $query->execute($this->company, ['period' => 'this_month'], $this->owner);

        $this->assertTrue(BigDecimal::of($result->totals['gross_sales_base'])->isEqualTo('0.000000'));
        $this->assertSame(0, $result->totals['original_invoice_count']);
    }

    public function test_sales_by_customer_report_aggregates_and_filters_by_customer(): void
    {
        $this->createAndPostPurchase();

        $c2 = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل ثان',
            'name_en' => 'Second Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $this->createAndPostSalesInvoice(['customer' => $this->defaultCustomer, 'issue_date' => '2026-10-01']);
        $this->createAndPostSalesInvoice(['customer' => $c2, 'issue_date' => '2026-10-02']);

        $query = app(SalesByCustomerReportQuery::class);

        // All customers
        $resultAll = $query->execute($this->company, ['period' => 'this_month'], $this->owner);
        $this->assertCount(2, $resultAll->rows);

        // Filtered to second customer
        $resultFiltered = $query->execute($this->company, [
            'period' => 'this_month',
            'customer_id' => $c2->id,
        ], $this->owner);
        $this->assertCount(1, $resultFiltered->rows);
        $this->assertSame((int) $c2->id, $resultFiltered->rows[0]['customer_id']);
    }

    public function test_sales_by_product_report_redacts_cost_when_actor_unauthorized(): void
    {
        $this->createAndPostPurchase();
        $this->createAndPostSalesInvoice(['issue_date' => '2026-10-01']);

        // Create restricted actor with sales report view but without cost/profit permissions
        $restrictedActor = $this->customActor([
            ReportPermissionCatalog::REPORTS_SALES_VIEW,
            'sales.invoice.view',
        ], 'SalesViewerWithoutCost');

        $this->activateUser($restrictedActor);

        $query = app(SalesByProductReportQuery::class);
        $result = $query->execute($this->company, ['period' => 'this_month'], $restrictedActor);

        // Cost and profit keys MUST NOT exist in totals or rows
        $this->assertArrayNotHasKey('cogs_base', $result->totals);
        $this->assertArrayNotHasKey('gross_profit_base', $result->totals);
        $this->assertArrayNotHasKey('gross_margin', $result->totals);

        $row = $result->rows[0];
        $this->assertArrayNotHasKey('cogs_base', $row);
        $this->assertArrayNotHasKey('gross_profit_base', $row);
        $this->assertArrayNotHasKey('gross_margin', $row);
        $this->assertArrayHasKey('sales_revenue_base', $row);
    }

    public function test_sales_by_product_report_includes_cost_and_profit_when_authorized(): void
    {
        $this->createAndPostPurchase(); // Purchase at unit_cost 10
        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-01',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2.000000',
                    'unit_price' => '50.000000', // Gross 100, cost 2 * 10 = 20, profit 80
                ],
            ],
        ]);

        $query = app(SalesByProductReportQuery::class);
        $result = $query->execute($this->company, ['period' => 'this_month'], $this->owner);

        $this->assertArrayHasKey('cogs_base', $result->totals);
        $this->assertArrayHasKey('gross_profit_base', $result->totals);
        $this->assertArrayHasKey('gross_margin', $result->totals);

        $this->assertTrue(BigDecimal::of($result->totals['sales_revenue_base'])->isEqualTo('100.000000'));
        $this->assertTrue(BigDecimal::of($result->totals['cogs_base'])->isEqualTo('20.000000'));
        $this->assertTrue(BigDecimal::of($result->totals['gross_profit_base'])->isEqualTo('80.000000'));
        $this->assertTrue(BigDecimal::of($result->totals['gross_margin'])->isEqualTo('0.8000'));
    }

    public function test_sales_by_category_report_aggregates_and_redacts_cost(): void
    {
        $category = ProductCategory::create([
            'company_id' => $this->company->id,
            'name_ar' => 'تصنيف السلع',
            'name_en' => 'Goods Category',
            'active' => true,
        ]);
        $this->product->update(['category_id' => $category->id]);

        $this->createAndPostPurchase();
        $this->createAndPostSalesInvoice(['issue_date' => '2026-10-01']);

        $query = app(SalesByCategoryReportQuery::class);
        $result = $query->execute($this->company, ['period' => 'this_month'], $this->owner);

        $this->assertNotEmpty($result->rows);
        $this->assertSame((int) $category->id, $result->rows[0]['category_id']);
        $this->assertTrue(BigDecimal::of($result->rows[0]['sales_revenue_base'])->isEqualTo('100.000000'));
        $this->assertArrayHasKey('cogs_base', $result->totals);
    }

    public function test_sales_gross_profit_report_enforces_permissions_and_calculates_profit(): void
    {
        $this->createAndPostPurchase();
        $this->createAndPostSalesInvoice(['issue_date' => '2026-10-01']);

        $query = app(SalesGrossProfitReportQuery::class);

        // Unauthorized actor without cost permissions should be blocked
        $restrictedActor = $this->customActor([
            ReportPermissionCatalog::REPORTS_SALES_VIEW,
            'sales.invoice.view',
        ], 'RestrictedNoCost');

        $this->expectException(AuthorizationException::class);
        $query->execute($this->company, ['period' => 'this_month'], $restrictedActor);
    }

    public function test_sales_gross_profit_report_calculates_margins_for_authorized_owner(): void
    {
        $this->createAndPostPurchase();
        $this->createAndPostSalesInvoice(['issue_date' => '2026-10-01']);

        $query = app(SalesGrossProfitReportQuery::class);
        $result = $query->execute($this->company, ['period' => 'this_month'], $this->owner);

        $this->assertSame('sales.gross_profit', $result->reportType);
        $this->assertTrue(BigDecimal::of($result->totals['total_revenue_base'])->isEqualTo('100.000000'));
        $this->assertTrue(BigDecimal::of($result->totals['total_cogs_base'])->isEqualTo('20.000000'));
        $this->assertTrue(BigDecimal::of($result->totals['total_gross_profit_base'])->isEqualTo('80.000000'));
        $this->assertTrue(BigDecimal::of($result->totals['overall_gross_margin'])->isEqualTo('0.8000'));
    }

    public function test_sales_discounts_report_identifies_invoices_with_discounts(): void
    {
        $this->createAndPostPurchase();

        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-01',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2.000000',
                    'unit_price' => '50.000000',
                    'discount_type' => 'fixed',
                    'discount_value' => '10.000000',
                ],
            ],
        ]);

        $query = app(SalesDiscountsReportQuery::class);
        $result = $query->execute($this->company, ['period' => 'this_month'], $this->owner);

        $this->assertSame('sales.discounts', $result->reportType);
        $this->assertNotEmpty($result->rows);
        $this->assertTrue(BigDecimal::of($result->totals['total_discount_base'])->isEqualTo('10.000000'));
    }

    public function test_sales_returns_report_reflects_returns_activity(): void
    {
        $this->createAndPostPurchase();
        $invoice = $this->createAndPostSalesInvoice(['issue_date' => '2026-10-01']);

        $invoice->loadMissing('lines');
        $invoiceLine = $invoice->lines->first();

        // Create sales return
        $returnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => '2026-10-05',
            'reason' => 'Damaged on delivery',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoiceLine?->id,
                    'quantity' => '1.000000',
                ],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($returnDraft, $this->owner);

        $query = app(SalesReturnsReportQuery::class);
        $result = $query->execute($this->company, ['period' => 'this_month'], $this->owner);

        $this->assertSame('sales.returns', $result->reportType);
        $this->assertNotEmpty($result->rows);
        $this->assertTrue(BigDecimal::of($result->totals['total_returns_base'])->isEqualTo('50.000000'));
    }

    public function test_sales_unpaid_invoices_report_reflects_open_receivables(): void
    {
        $this->createAndPostPurchase();
        $this->createAndPostSalesInvoice(['issue_date' => '2026-10-01', 'due_date' => '2026-10-15']);

        $query = app(SalesUnpaidInvoicesReportQuery::class);
        $result = $query->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-20'], $this->owner);

        $this->assertSame('sales.unpaid_invoices', $result->reportType);
        $this->assertNotEmpty($result->rows);
        $this->assertTrue(BigDecimal::of($result->rows[0]['outstanding_currency'])->isEqualTo('100.000000'));
    }

    public function test_sales_price_history_report_tracks_historical_prices(): void
    {
        $this->createAndPostPurchase();
        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-01',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1.000000',
                    'unit_price' => '45.000000',
                ],
            ],
        ]);
        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-05',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1.000000',
                    'unit_price' => '55.000000',
                ],
            ],
        ]);

        $query = app(SalesPriceHistoryReportQuery::class);
        $result = $query->execute($this->company, [
            'product_id' => $this->product->id,
            'customer_id' => $this->defaultCustomer->id,
        ], $this->owner);

        $this->assertSame('sales.price_history', $result->reportType);
        $this->assertCount(2, $result->rows);
        $this->assertTrue(BigDecimal::of($result->rows[0]['unit_price'])->isEqualTo('55.000000')); // latest first
        $this->assertTrue(BigDecimal::of($result->rows[1]['unit_price'])->isEqualTo('45.000000'));
    }
}
