<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Application\Reporting\Queries\CustomerProductHistoryReportQuery;
use App\Application\Reporting\Queries\CustomerReceivablesAgingReportQuery;
use App\Application\Reporting\Queries\PurchasesByProductReportQuery;
use App\Application\Reporting\Queries\PurchasesByVendorReportQuery;
use App\Application\Reporting\Queries\SalesByCustomerReportQuery;
use App\Application\Reporting\Queries\SalesByProductReportQuery;
use App\Application\Reporting\Queries\SalesGrossProfitReportQuery;
use App\Application\Reporting\Queries\VendorPayablesAgingReportQuery;
use App\Application\Reporting\Queries\VendorProductHistoryReportQuery;
use App\Application\Reporting\Support\ProductAggregationHelper;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Purchasing\PurchaseDraftBuilder;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class Correction02HistoricalAggregatesTest extends TradeTestCase
{
    private function supplyStock(string $qty = '500'): void
    {
        $this->createAndPostPurchase([
            'purchase_date' => '2026-10-01',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $qty,
                    'unit_cost' => '10.000000',
                ],
            ],
        ]);
    }

    public function test_stable_product_aggregation_and_representative_snapshot_and_null_products(): void
    {
        $this->activateUser($this->owner);
        $this->supplyStock('100');

        // Invoice 1: 5 units of product at 100 on Oct 2 with Description 1
        $inv1 = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'وصف قديم 1',
                    'quantity' => '5.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);

        // Invoice 2: 3 units of product at 120 on Oct 5 with Description 2 (latest)
        $inv2 = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-05',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'وصف حديث 2 (الأحدث)',
                    'quantity' => '3.000000',
                    'unit_price' => '120.000000',
                ],
            ],
        ]);

        // Return: 1 unit returned on Oct 8
        $returnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'sales_invoice_id' => $inv1->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-08',
            'lines' => [
                [
                    'sales_invoice_line_id' => $inv1->lines->first()->id,
                    'quantity' => '1.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($returnDraft, $this->owner);

        // Invoice with null product_id: Line Alpha
        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-03',
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'خدمة استشارية أ',
                    'quantity' => '2.000000',
                    'unit_price' => '50.000000',
                ],
            ],
        ]);

        // Invoice with null product_id: Line Beta (distinct description)
        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-04',
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'خدمة صيانة ب',
                    'quantity' => '1.000000',
                    'unit_price' => '80.000000',
                ],
            ],
        ]);

        $query = app(SalesByProductReportQuery::class);
        $result = $query->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);

        // Expect 3 rows: catalog product, service A, service B
        $this->assertCount(3, $result->rows);

        // Find catalog product row
        $catalogRow = collect($result->rows)->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($catalogRow);
        // Representative snapshot must be from latest invoice (Invoice 2)
        $this->assertSame('وصف حديث 2 (الأحدث)', $catalogRow['item_description']);
        // Quantity base: 5 + 3 - 1 = 7.000000
        $this->assertSame('7.000000', $catalogRow['quantity_base']);

        // Null product rows must be distinct
        $descriptions = array_column($result->rows, 'item_description');
        $this->assertContains('خدمة استشارية أ', $descriptions);
        $this->assertContains('خدمة صيانة ب', $descriptions);
    }

    public function test_customer_product_history_original_only_last_purchased_date(): void
    {
        $this->activateUser($this->owner);
        $this->supplyStock('100');

        // Invoice on Oct 2
        $inv1 = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'منتج تجريبي',
                    'quantity' => '5.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);

        // Invoice on Oct 5
        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-05',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'منتج تجريبي',
                    'quantity' => '2.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);

        // Return on Oct 8
        $returnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'sales_invoice_id' => $inv1->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-08',
            'lines' => [
                [
                    'sales_invoice_line_id' => $inv1->lines->first()->id,
                    'quantity' => '1.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($returnDraft, $this->owner);

        $query = app(CustomerProductHistoryReportQuery::class);

        // Full month query: last_purchased_date must be 2026-10-05 (Invoice 2), NOT 2026-10-08 (Return)
        $result = $query->execute($this->company, [
            'customer_id' => $this->defaultCustomer->id,
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]);
        $this->assertNotEmpty($result->rows);
        $this->assertSame('2026-10-05', $result->rows[0]['last_purchased_date']);

        // Return-only period: Oct 7 to Oct 9
        $returnOnlyResult = $query->execute($this->company, [
            'customer_id' => $this->defaultCustomer->id,
            'from' => '2026-10-07',
            'to' => '2026-10-09',
        ]);
        $this->assertNotEmpty($returnOnlyResult->rows);
        // Original-only date must be null because no original purchase lines exist in this window
        $this->assertNull($returnOnlyResult->rows[0]['last_purchased_date']);
    }

    public function test_group_encoding_has_no_delimiter_collision(): void
    {
        $this->activateUser($this->owner);

        // Group 1: description = 'A::B', SKU = 'C'
        // Group 2: description = 'A', SKU = 'B::C'
        $lines = DB::query()->fromSub(function ($query) {
            $query->selectRaw("NULL as product_id, 'A::B' as item_description, 'C' as product_sku, '' as product_name_ar, '' as product_name_en, '' as unit_name_ar, '' as unit_name_en")
                ->unionAll(DB::query()->selectRaw("NULL as product_id, 'A' as item_description, 'B::C' as product_sku, '' as product_name_ar, '' as product_name_en, '' as unit_name_ar, '' as unit_name_en"));
        }, 'l')
            ->selectRaw(ProductAggregationHelper::groupKeySql('l').' as k')
            ->distinct()
            ->get();

        $this->assertCount(2, $lines, 'Length-prefixed hashing must prevent delimiter collision.');
    }

    public function test_group_encoding_preserves_exact_case_identity(): void
    {
        $this->activateUser($this->owner);

        $lines = DB::query()->fromSub(function ($query) {
            $query->selectRaw("NULL as product_id, 'Service A' as item_description, '' as product_sku, '' as product_name_ar, '' as product_name_en, '' as unit_name_ar, '' as unit_name_en")
                ->unionAll(DB::query()->selectRaw("NULL as product_id, 'service a' as item_description, '' as product_sku, '' as product_name_ar, '' as product_name_en, '' as unit_name_ar, '' as unit_name_en"));
        }, 'l')
            ->selectRaw(ProductAggregationHelper::groupKeySql('l').' as k')
            ->distinct()
            ->get();

        $this->assertCount(2, $lines, 'Exact binary SHA2 hash must keep case variants in distinct groups.');
    }

    public function test_free_text_line_with_sales_return_cancellation_preserves_group(): void
    {
        $this->activateUser($this->owner);

        $inv = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'خدمة تصميم موقع خاص',
                    'quantity' => '5.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);

        $returnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'sales_invoice_id' => $inv->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-06',
            'lines' => [
                [
                    'sales_invoice_line_id' => $inv->lines->first()->id,
                    'quantity' => '2.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($returnDraft, $this->owner);

        $query = app(SalesByProductReportQuery::class);
        $result = $query->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);

        $row = collect($result->rows)->firstWhere('item_description', 'خدمة تصميم موقع خاص');
        $this->assertNotNull($row);
        $this->assertNull($row['product_id']);
        // Net quantity: 5 - 2 = 3.000000
        $this->assertSame('3.000000', $row['quantity_base']);
        // Net revenue: 500 - 200 = 300.000000
        $this->assertSame('300.000000', $row['sales_revenue_base']);
    }

    public function test_renamed_catalog_product_snapshots_aggregate_properly_across_queries(): void
    {
        $this->activateUser($this->owner);
        $this->supplyStock('100');

        // Invoice 1: 4 units at 50, Description A
        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'وصف قديم أ',
                    'quantity' => '4.000000',
                    'unit_price' => '50.000000',
                ],
            ],
        ]);

        // Invoice 2: 6 units at 60, Description B (latest original)
        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-05',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'وصف حديث ب',
                    'quantity' => '6.000000',
                    'unit_price' => '60.000000',
                ],
            ],
        ]);

        // 1. SalesByProductReportQuery
        $salesByProd = app(SalesByProductReportQuery::class)->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $sbRow = collect($salesByProd->rows)->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($sbRow);
        $this->assertSame('10.000000', $sbRow['quantity_base']); // 4 + 6
        $this->assertSame('560.000000', $sbRow['sales_revenue_base']); // 200 + 360
        $this->assertSame('وصف حديث ب', $sbRow['item_description']);

        // 2. SalesGrossProfitReportQuery (by_product mode)
        $grossProfit = app(SalesGrossProfitReportQuery::class)->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31', 'grouping' => 'by_product']);
        $gpRow = collect($grossProfit->rows)->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($gpRow);
        $this->assertSame('10.000000', $gpRow['quantity_base']);
        $this->assertSame('560.000000', $gpRow['revenue_base']);
        $this->assertSame('وصف حديث ب', $gpRow['item_description']);

        // 3. CustomerProductHistoryReportQuery
        $custHist = app(CustomerProductHistoryReportQuery::class)->execute($this->company, ['customer_id' => $this->defaultCustomer->id, 'from' => '2026-10-01', 'to' => '2026-10-31']);
        $chRow = collect($custHist->rows)->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($chRow);
        $this->assertSame('10.000000', $chRow['total_quantity_base']);
        $this->assertSame('560.000000', $chRow['total_spent_base']);
        $this->assertSame('2026-10-05', $chRow['last_purchased_date']);
        $this->assertSame('وصف حديث ب', $chRow['item_description']);

        // 4. PurchasesByProductReportQuery & VendorProductHistoryReportQuery
        $pur1Draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-03',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'شراء وصف 1',
                    'quantity' => '10.000000',
                    'unit_cost' => '15.000000',
                ],
            ],
        ]);
        app(PostPurchaseAction::class)->execute($pur1Draft, $this->owner);

        $pur2Draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-07',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'شراء وصف 2 حديث',
                    'quantity' => '20.000000',
                    'unit_cost' => '18.000000',
                ],
            ],
        ]);
        app(PostPurchaseAction::class)->execute($pur2Draft, $this->owner);

        $purchByProd = app(PurchasesByProductReportQuery::class)->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $pbRow = collect($purchByProd->rows)->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($pbRow);
        $this->assertSame('130.000000', $pbRow['quantity_base']);
        $this->assertSame('شراء وصف 2 حديث', $pbRow['item_description']);

        $vendHist = app(VendorProductHistoryReportQuery::class)->execute($this->company, ['vendor_id' => $this->vendor->id, 'from' => '2026-10-01', 'to' => '2026-10-31']);
        $vhRow = collect($vendHist->rows)->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($vhRow);
        $this->assertSame('130.000000', $vhRow['total_quantity_base']);
        $this->assertSame('1510.000000', $vhRow['total_commercial_base']);
        $this->assertSame('2026-10-07', $vhRow['last_purchased_date']);
        $this->assertSame('شراء وصف 2 حديث', $vhRow['item_description']);
    }

    public function test_vendor_product_history_original_only_last_purchased_date(): void
    {
        $this->activateUser($this->owner);

        // Purchase 1 on Oct 2
        $pur1Draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-02',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'شراء أول',
                    'quantity' => '10.000000',
                    'unit_cost' => '20.000000',
                ],
            ],
        ]);
        $pur1 = app(PostPurchaseAction::class)->execute($pur1Draft, $this->owner);

        // Purchase 2 on Oct 5
        $pur2Draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-05',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'شراء ثان',
                    'quantity' => '5.000000',
                    'unit_cost' => '20.000000',
                ],
            ],
        ]);
        app(PostPurchaseAction::class)->execute($pur2Draft, $this->owner);

        // Purchase Return on Oct 8
        $purReturnDraft = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $pur1->id,
            'return_date' => '2026-10-08',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'purchase_line_id' => $pur1->lines->first()->id,
                    'quantity' => '2.000000',
                ],
            ],
        ]);
        app(PostPurchaseReturnAction::class)->execute($purReturnDraft, $this->owner);

        // Full return of remaining 8 units of purchase 1 on Oct 10 (100% quantity returned)
        $purReturn2Draft = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $pur1->id,
            'return_date' => '2026-10-10',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'purchase_line_id' => $pur1->lines->first()->id,
                    'quantity' => '8.000000',
                ],
            ],
        ]);
        app(PostPurchaseReturnAction::class)->execute($purReturn2Draft, $this->owner);

        $query = app(VendorProductHistoryReportQuery::class);

        // Full month query: last_purchased_date must still be 2026-10-05 (Purchase 2), NOT returns (Oct 8 or Oct 10)
        $result = $query->execute($this->company, [
            'vendor_id' => $this->vendor->id,
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]);
        $this->assertNotEmpty($result->rows);
        $this->assertSame('2026-10-05', $result->rows[0]['last_purchased_date']);

        // Return/reversal-only window: Oct 7 to Oct 12 has returns only, so last_purchased_date is null
        $returnOnlyResult = $query->execute($this->company, [
            'vendor_id' => $this->vendor->id,
            'from' => '2026-10-07',
            'to' => '2026-10-12',
        ]);
        $this->assertNotEmpty($returnOnlyResult->rows);
        $this->assertNull($returnOnlyResult->rows[0]['last_purchased_date']);
    }

    public function test_customer_product_history_dates_respect_invoices_only(): void
    {
        $this->activateUser($this->owner);
        $this->supplyStock('100');

        // Invoice 1: 10 units of product at 100 on Oct 2
        $inv1 = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);

        // Invoice 2: 5 units of product at 120 on Oct 5
        $inv2 = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-05',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5.000000',
                    'unit_price' => '120.000000',
                ],
            ],
        ]);

        // Partial return of 2 units on Oct 8
        $ret1Draft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'sales_invoice_id' => $inv1->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-08',
            'lines' => [
                [
                    'sales_invoice_line_id' => $inv1->lines->first()->id,
                    'quantity' => '2.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($ret1Draft, $this->owner);

        // Full return of remaining 8 units on Oct 10 (100% quantity returned of Invoice 1)
        $ret2Draft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'sales_invoice_id' => $inv1->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-10',
            'lines' => [
                [
                    'sales_invoice_line_id' => $inv1->lines->first()->id,
                    'quantity' => '8.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($ret2Draft, $this->owner);

        $query = app(CustomerProductHistoryReportQuery::class);

        // Full month query: last_purchased_date must still be 2026-10-05 (Invoice 2), NOT returns (Oct 8 or Oct 10)
        $result = $query->execute($this->company, [
            'customer_id' => $this->defaultCustomer->id,
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]);
        $this->assertNotEmpty($result->rows);
        $this->assertSame('2026-10-05', $result->rows[0]['last_purchased_date']);

        // Return/reversal-only window: Oct 7 to Oct 12 has returns only, so last_purchased_date is null
        $returnOnlyResult = $query->execute($this->company, [
            'customer_id' => $this->defaultCustomer->id,
            'from' => '2026-10-07',
            'to' => '2026-10-12',
        ]);
        $this->assertNotEmpty($returnOnlyResult->rows);
        $this->assertNull($returnOnlyResult->rows[0]['last_purchased_date']);
    }

    public function test_trade_open_positions_aging_multicurrency_distinct_parties_kpi(): void
    {
        $this->activateUser($this->owner);
        $this->supplyStock('100');

        $customerB = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل ثان',
            'code' => 'CUST-B',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        // Customer A: 1 open invoice in ILS and 1 open invoice in USD (2 distinct currencies)
        $this->createAndPostSalesInvoice([
            'customer' => $this->defaultCustomer,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-15',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '100']],
        ]);
        $this->createAndPostSalesInvoice([
            'customer' => $this->defaultCustomer,
            'currency_code' => 'USD',
            'exchange_rate' => '3.500000',
            'issue_date' => '2026-09-10',
            'due_date' => '2026-09-20',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '150']],
        ]);

        // Customer B: 1 open invoice in ILS
        $this->createAndPostSalesInvoice([
            'customer' => $customerB,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-09-05',
            'due_date' => '2026-09-18',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '200']],
        ]);

        $receivablesQuery = app(CustomerReceivablesAgingReportQuery::class);
        $receivablesResult = $receivablesQuery->execute($this->company, ['from' => '2026-09-01', 'to' => '2026-10-15']);

        // 3 currency debt rows (Customer A ILS, Customer A USD, Customer B ILS), but distinct customer_count KPI MUST be 2!
        $this->assertCount(3, $receivablesResult->rows);
        $this->assertSame(2, $receivablesResult->totals['customer_count']);

        // Distinct customer KPI count remains 2 across pagination
        $recPage1 = $receivablesQuery->execute($this->company, ['from' => '2026-09-01', 'to' => '2026-10-15', 'per_page' => 1, 'page' => 1]);
        $this->assertCount(1, $recPage1->rows);
        $this->assertSame(2, $recPage1->totals['customer_count']);

        $recPage2 = $receivablesQuery->execute($this->company, ['from' => '2026-09-01', 'to' => '2026-10-15', 'per_page' => 1, 'page' => 2]);
        $this->assertCount(1, $recPage2->rows);
        $this->assertSame(2, $recPage2->totals['customer_count']);

        // Vendor aging check
        $vendorB = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد ثان',
            'code' => 'VEND-B',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        // Vendor A: 1 purchase in ILS, 1 in USD
        $purA1 = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-09-01',
            'due_date' => '2026-09-15',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '100']],
        ]);
        app(PostPurchaseAction::class)->execute($purA1, $this->owner);

        $purA2 = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-09-05',
            'due_date' => '2026-09-20',
            'currency_code' => 'USD',
            'exchange_rate' => '3.500000',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '50']],
        ]);
        app(PostPurchaseAction::class)->execute($purA2, $this->owner);

        // Vendor B: 1 purchase in ILS
        $purB = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $vendorB->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-09-08',
            'due_date' => '2026-09-22',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '120']],
        ]);
        app(PostPurchaseAction::class)->execute($purB, $this->owner);

        $payablesQuery = app(VendorPayablesAgingReportQuery::class);
        $payablesResult = $payablesQuery->execute($this->company, ['from' => '2026-09-01', 'to' => '2026-10-15']);

        // 3 currency debt rows, distinct vendor_count KPI MUST be 2!
        $this->assertCount(3, $payablesResult->rows);
        $this->assertSame(2, $payablesResult->totals['vendor_count']);

        // Distinct vendor KPI count remains 2 across pagination
        $payPage1 = $payablesQuery->execute($this->company, ['from' => '2026-09-01', 'to' => '2026-10-15', 'per_page' => 1, 'page' => 1]);
        $this->assertCount(1, $payPage1->rows);
        $this->assertSame(2, $payPage1->totals['vendor_count']);

        $payPage2 = $payablesQuery->execute($this->company, ['from' => '2026-09-01', 'to' => '2026-10-15', 'per_page' => 1, 'page' => 2]);
        $this->assertCount(1, $payPage2->rows);
        $this->assertSame(2, $payPage2->totals['vendor_count']);
    }

    public function test_master_codes_join_and_frozen_historical_name_for_customer_and_vendor(): void
    {
        $this->activateUser($this->owner);
        $this->supplyStock('100');

        // Customer
        $this->defaultCustomer->code = 'ORIG-CUST-001';
        $this->defaultCustomer->name_ar = 'اسم العميل الأصلي عند الترحيل';
        $this->defaultCustomer->save();

        $this->createAndPostSalesInvoice([
            'customer' => $this->defaultCustomer,
            'issue_date' => '2026-10-02',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '100']],
        ]);

        // Vendor
        $this->vendor->code = 'ORIG-VEND-001';
        $this->vendor->name_ar = 'اسم المورد الأصلي عند الترحيل';
        $this->vendor->save();

        $purDraft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-03',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '50']],
        ]);
        app(PostPurchaseAction::class)->execute($purDraft, $this->owner);

        // Mutate masters
        $this->defaultCustomer->code = 'UPDATED-CUST-999';
        $this->defaultCustomer->name_ar = 'اسم معدل في بطاقة العميل';
        $this->defaultCustomer->save();

        $this->vendor->code = 'UPDATED-VEND-999';
        $this->vendor->name_ar = 'اسم معدل في بطاقة المورد';
        $this->vendor->save();

        // Check Sales By Customer
        $salesQuery = app(SalesByCustomerReportQuery::class);
        $salesResult = $salesQuery->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $custRow = collect($salesResult->rows)->firstWhere('customer_id', $this->defaultCustomer->id);
        $this->assertNotNull($custRow);
        $this->assertSame('UPDATED-CUST-999', $custRow['customer_code']);
        $this->assertSame('اسم العميل الأصلي عند الترحيل', $custRow['customer_name_ar']);

        // Check Purchases By Vendor
        $purchQuery = app(PurchasesByVendorReportQuery::class);
        $purchResult = $purchQuery->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $vendRow = collect($purchResult->rows)->firstWhere('vendor_id', $this->vendor->id);
        $this->assertNotNull($vendRow);
        $this->assertSame('UPDATED-VEND-999', $vendRow['vendor_code']);
        $this->assertSame('اسم المورد الأصلي عند الترحيل', $vendRow['vendor_name_ar']);

        // UI and CSV assertions in AR and EN proving current codes with frozen historical names
        // 1. Sales By Customer UI (AR & EN)
        $uiSalesAr = $this->get('/reports/sales.by-customer?from=2026-10-01&to=2026-10-31');
        $uiSalesAr->assertOk();
        $this->assertStringContainsString('UPDATED-CUST-999', $uiSalesAr->getContent());
        $this->assertStringContainsString('اسم العميل الأصلي عند الترحيل', $uiSalesAr->getContent());

        app()->setLocale('en');
        $uiSalesEn = $this->get('/reports/sales.by-customer?from=2026-10-01&to=2026-10-31');
        $uiSalesEn->assertOk();
        $this->assertStringContainsString('UPDATED-CUST-999', $uiSalesEn->getContent());
        $this->assertStringContainsString('اسم العميل الأصلي عند الترحيل', $uiSalesEn->getContent());
        app()->setLocale('ar');

        // 2. Sales By Customer CSV Export
        $csvSales = $this->get(route('reports.export', ['reportKey' => 'sales.by-customer', 'filters' => ['from' => '2026-10-01', 'to' => '2026-10-31']]))->assertOk()->streamedContent();
        $this->assertStringContainsString('UPDATED-CUST-999', $csvSales);
        $this->assertStringContainsString('اسم العميل الأصلي عند الترحيل', $csvSales);

        // 3. Purchases By Vendor UI (AR & EN)
        $uiPurchAr = $this->get('/reports/purchases.by-vendor?from=2026-10-01&to=2026-10-31');
        $uiPurchAr->assertOk();
        $this->assertStringContainsString('UPDATED-VEND-999', $uiPurchAr->getContent());
        $this->assertStringContainsString('اسم المورد الأصلي عند الترحيل', $uiPurchAr->getContent());

        app()->setLocale('en');
        $uiPurchEn = $this->get('/reports/purchases.by-vendor?from=2026-10-01&to=2026-10-31');
        $uiPurchEn->assertOk();
        $this->assertStringContainsString('UPDATED-VEND-999', $uiPurchEn->getContent());
        $this->assertStringContainsString('اسم المورد الأصلي عند الترحيل', $uiPurchEn->getContent());
        app()->setLocale('ar');

        // 4. Purchases By Vendor CSV Export
        $csvPurch = $this->get(route('reports.export', ['reportKey' => 'purchases.by-vendor', 'filters' => ['from' => '2026-10-01', 'to' => '2026-10-31']]))->assertOk()->streamedContent();
        $this->assertStringContainsString('UPDATED-VEND-999', $csvPurch);
        $this->assertStringContainsString('اسم المورد الأصلي عند الترحيل', $csvPurch);

        // Cross-company defense
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'شركة دفاع', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);
        $foreignVendor = Vendor::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'مورد أجنبي',
            'code' => 'FOREIGN-VEND-CODE',
            'status' => 'active',
            'created_by' => $otherOwner->id,
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->activateUser($this->owner);
        $purchResultCompany = $purchQuery->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertNull(collect($purchResultCompany->rows)->firstWhere('vendor_id', $foreignVendor->id));
    }

    public function test_canonical_rejection_of_blank_item_description(): void
    {
        $this->activateUser($this->owner);

        // Purchase creation rejects blank item_description via PurchaseDraftBuilder validation
        $caughtPurchase = false;
        try {
            app(PurchaseDraftBuilder::class)->prepare($this->company, [
                'vendor_id' => $this->vendor->id,
                'warehouse_id' => $this->warehouse->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.000000',
                'purchase_date' => '2026-10-05',
                'lines' => [
                    [
                        'product_id' => $this->product->id,
                        'item_description' => '',
                        'quantity' => '1.000000',
                        'unit_cost' => '10.000000',
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            $caughtPurchase = true;
            $this->assertArrayHasKey('lines.0.item_description', $e->errors());
        }
        $this->assertTrue($caughtPurchase, 'Blank item_description must be canonically rejected on purchase creation.');

        // Sales creation rejects blank item_description via InvoiceForm validation rules
        Livewire::actingAs($this->owner)
            ->test(InvoiceForm::class)
            ->set('customer_id', $this->defaultCustomer->id)
            ->set('lines.0.item_description', '')
            ->call('save', false)
            ->assertHasErrors(['lines.0.item_description']);
    }
}
