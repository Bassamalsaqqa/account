<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Application\Reporting\Queries\CustomerBalancesReportQuery;
use App\Application\Reporting\Queries\CustomerBuyingHistoryReportQuery;
use App\Application\Reporting\Queries\CustomerTopReportQuery;
use App\Application\Reporting\Queries\PurchasePriceHistoryReportQuery;
use App\Application\Reporting\Queries\PurchaseReturnsReportQuery;
use App\Application\Reporting\Queries\PurchasesByVendorReportQuery;
use App\Application\Reporting\Queries\PurchaseSummaryReportQuery;
use App\Application\Reporting\Queries\SalesByCustomerReportQuery;
use App\Application\Reporting\Queries\SalesByProductReportQuery;
use App\Application\Reporting\Queries\SalesGrossProfitReportQuery;
use App\Application\Reporting\Queries\SalesPriceHistoryReportQuery;
use App\Application\Reporting\Queries\SalesSummaryReportQuery;
use App\Application\Reporting\Queries\VendorBalancesReportQuery;
use App\Application\Reporting\Queries\VendorProductPriceHistoryReportQuery;
use App\Application\Reporting\Queries\VendorPurchaseHistoryReportQuery;
use App\Models\Customer;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class TradeIntegrityAndHistoryTest extends TradeTestCase
{
    public static function corruptOriginals(): array
    {
        return [['source_type', 'manual_journal'], ['source_id', 999999], ['company_id', 999999]];
    }

    #[DataProvider('corruptOriginals')]
    public function test_canonical_original_batch_corruption_fails_closed(string $field, int|string $value): void
    {
        $this->createAndPostPurchase();
        $invoice = $this->createAndPostSalesInvoice();
        // Disposable corruption probes do not represent application writes.
        if ($field === 'company_id') {
            // A foreign existing Company avoids relying on FK failure.
            $foreign = $this->company->replicate(['public_id']);
            $foreign->name_ar = 'Foreign';
            $foreign->save();
            $value = (int) $foreign->id;
        }
        DB::table('posting_batches')->where('id', $invoice->posting_batch_id)->update([$field => $value]);
        $this->expectException(ReportingException::class);
        app(SalesSummaryReportQuery::class)->execute($this->company, [], $this->owner);
    }

    public static function corruptVoids(): array
    {
        return [['source_type', 'manual_journal'], ['reversal_of_id', null], ['reversal_of_id', 999999]];
    }

    #[DataProvider('corruptVoids')]
    public function test_each_invalid_void_relationship_is_rejected_independently(string $field, int|string|null $value): void
    {
        $this->createAndPostPurchase();
        $invoice = $this->createAndPostSalesInvoice();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $invoice = app(VoidSalesInvoiceAction::class)->execute($invoice, $this->owner, 'Void');
        if ($value === 999999) {
            $value = $this->createAndPostPurchase()->posting_batch_id;
        }
        DB::table('posting_batches')->where('id', $invoice->void_posting_batch_id)->update([$field => $value]);
        $this->expectException(ReportingException::class);
        app(SalesSummaryReportQuery::class)->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31'], $this->owner);
    }

    public function test_void_with_missing_canonical_pointer_fails_closed(): void
    {
        $this->createAndPostPurchase();
        $invoice = $this->createAndPostSalesInvoice();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $invoice = app(VoidSalesInvoiceAction::class)->execute($invoice, $this->owner, 'Void');
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['void_posting_batch_id' => null]);
        $this->expectException(ReportingException::class);
        app(SalesSummaryReportQuery::class)->execute($this->company, [], $this->owner);
    }

    public function test_draft_with_tampered_batch_never_becomes_history(): void
    {
        $purchase = $this->createAndPostPurchase();
        $invoice = $this->createAndPostSalesInvoice();
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['status' => 'draft', 'posting_batch_id' => $purchase->posting_batch_id]);
        $report = app(SalesPriceHistoryReportQuery::class)->execute($this->company, [], $this->owner);
        $this->assertSame([], $report->rows);
    }

    public function test_frozen_sales_party_names_survive_rename_in_transactions_and_aggregates(): void
    {
        $this->createAndPostPurchase();
        $invoice = $this->createAndPostSalesInvoice();
        $frozen = $invoice->customer_snapshot['name_en'];
        $this->defaultCustomer->update(['name_ar' => 'Renamed Arabic Customer', 'name_en' => 'Renamed Customer']);
        foreach ([SalesPriceHistoryReportQuery::class, SalesGrossProfitReportQuery::class, SalesByCustomerReportQuery::class, CustomerBuyingHistoryReportQuery::class, CustomerTopReportQuery::class] as $query) {
            $report = app($query)->execute($this->company, [], $this->owner);
            $this->assertSame($frozen, $report->rows[0]['customer_name_en']);
            $this->assertStringNotContainsString('Renamed Customer', json_encode($report->rows, JSON_THROW_ON_ERROR));
        }
    }

    public function test_frozen_purchase_party_names_survive_rename(): void
    {
        $purchase = $this->createAndPostPurchase();
        $frozen = $purchase->vendor_snapshot['name_ar'];
        $this->vendor->update(['name_ar' => 'Renamed Vendor Arabic', 'name_en' => 'Renamed Vendor']);
        foreach ([PurchasePriceHistoryReportQuery::class, VendorProductPriceHistoryReportQuery::class, VendorPurchaseHistoryReportQuery::class, PurchasesByVendorReportQuery::class] as $query) {
            $report = app($query)->execute($this->company, [], $this->owner);
            $this->assertSame($frozen, $report->rows[0]['vendor_name_ar']);
            $this->assertStringNotContainsString('Renamed Vendor', json_encode($report->rows, JSON_THROW_ON_ERROR));
        }
    }

    public function test_legitimate_zero_gl_purchase_return_remains_operational_history(): void
    {
        $free = $this->product->replicate(['public_id', 'sku']);
        $free->sku = 'FREE-GOODS';
        $free->save();
        foreach ($this->product->productUnits as $u) {
            $nu = $u->replicate();
            $nu->product_id = $free->id;
            $nu->save();
        }
        $purchase = $this->createAndPostPurchase(['lines' => [['product_id' => $free->id, 'quantity' => '10', 'unit_cost' => '0'], ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10']]]);
        $return = $this->createAndPostReturn($purchase);
        $this->assertNull($return->posting_batch_id);
        $report = app(PurchaseReturnsReportQuery::class)->execute($this->company, [], $this->owner);
        $this->assertSame(1, $report->totals['return_count']);
        $this->assertSame((int) $return->id, $report->rows[0]['return_id']);
        $summary = app(PurchaseSummaryReportQuery::class)->execute($this->company, [], $this->owner);
        $this->assertSame(2, $summary->pagination['total']);
    }

    public static function malformedFilters(): array
    {
        return [[['customer_id' => true]], [['page' => false]], [['page' => '999999999999999999999999']], [['company_id' => '1abc']], [['currency_code' => ['USD']]], [['from' => ['2026-10-01'], 'to' => '2026-10-31']], [['employee_id' => 1]]];
    }

    #[DataProvider('malformedFilters')]
    public function test_malformed_or_unsupported_filters_are_rejected(array $filters): void
    {
        $this->expectException(InvalidReportFilterException::class);
        app(SalesSummaryReportQuery::class)->execute($this->company, $filters, $this->owner);
    }

    public function test_customer_balance_currency_filter_precedes_pagination_and_totals_cover_all_pages(): void
    {
        $a = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'A', 'name_en' => 'A', 'active' => true, 'created_by' => $this->owner->id]);
        $b = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'B', 'name_en' => 'B', 'active' => true, 'created_by' => $this->owner->id]);
        foreach ([[$a, '100'], [$b, '200']] as [$customer,$amount]) {
            $this->createAndPostSalesInvoice(['customer' => $customer, 'currency_code' => 'USD', 'exchange_rate' => '3.5', 'lines' => [['product_id' => null, 'item_description' => 'Service', 'quantity' => '1', 'unit_price' => $amount]]]);
        }
        $q = app(CustomerBalancesReportQuery::class);
        $p1 = $q->execute($this->company, ['currency_code' => 'USD', 'per_page' => 1], $this->owner);
        $p2 = $q->execute($this->company, ['currency_code' => 'USD', 'per_page' => 1, 'page' => 2], $this->owner);
        $this->assertSame(2, $p1->pagination['total']);
        $this->assertSame('300.000000', $p1->totals['outstanding_by_currency']['USD']);
        $this->assertSame($p1->totals, $p2->totals);
        $this->assertNotSame($p1->rows[0]['customer_id'], $p2->rows[0]['customer_id']);
        $this->assertSame('current', $p1->meta['position_basis']);
    }

    public function test_vendor_balance_currency_filter_precedes_pagination_and_totals_cover_all_pages(): void
    {
        $other = Vendor::create(['company_id' => $this->company->id, 'name_ar' => 'A', 'name_en' => 'A', 'active' => true, 'created_by' => $this->owner->id]);
        $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.5']);
        $this->createAndPostPurchase(['vendor_id' => $other->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5', 'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '20']]]);
        $q = app(VendorBalancesReportQuery::class);
        $p1 = $q->execute($this->company, ['currency_code' => 'USD', 'per_page' => 1], $this->owner);
        $p2 = $q->execute($this->company, ['currency_code' => 'USD', 'per_page' => 1, 'page' => 2], $this->owner);
        $this->assertSame(2, $p1->pagination['total']);
        $this->assertSame('300.000000', $p1->totals['balance_by_currency']['USD']);
        $this->assertSame($p1->totals, $p2->totals);
        $this->assertNotSame($p1->rows[0]['vendor_id'], $p2->rows[0]['vendor_id']);
    }

    public function test_current_balance_rejects_historical_cutoff_instead_of_misrepresenting_it(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        app(CustomerBalancesReportQuery::class)->execute($this->company, ['from' => '2026-01-01', 'to' => '2026-01-31'], $this->owner);
    }

    public function test_ordinary_sales_read_never_selects_cogs_source_values(): void
    {
        $this->createAndPostPurchase();
        $this->createAndPostSalesInvoice();
        $actor = $this->customActor(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($actor);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $report = app(SalesByProductReportQuery::class)->execute($this->company, [], $actor);
        $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        $this->assertDoesNotMatchRegularExpression('/(?:sil|srl|si|sr)\\.cogs_total_base/', $sql);
        $this->assertArrayNotHasKey('cogs_base', $report->rows[0]);
        $this->assertArrayNotHasKey('gross_profit_base', $report->totals);
    }
}
