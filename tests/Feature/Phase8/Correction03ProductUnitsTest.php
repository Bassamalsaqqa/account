<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Reporting\EnsureReportingFoundationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Application\Reporting\Queries\CustomerProductHistoryReportQuery;
use App\Application\Reporting\Queries\PurchasesByProductReportQuery;
use App\Application\Reporting\Queries\SalesByProductReportQuery;
use App\Application\Reporting\Queries\SalesGrossProfitReportQuery;
use App\Application\Reporting\Queries\VendorProductHistoryReportQuery;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final class Correction03ProductUnitsTest extends TradeTestCase
{
    protected Unit $unitPiece;

    protected Unit $unitCarton;

    protected ProductUnit $cartonProductUnit;

    protected function activateCompanyUser(Company $company, User $user): void
    {
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        app(CompanyContext::class)->setCompany($company, $user);
        $this->actingAs($user);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateUser($this->owner);

        $this->unitPiece = Unit::where('company_id', $this->company->id)
            ->where('code', 'piece')
            ->firstOrFail();

        $this->unitCarton = Unit::where('company_id', $this->company->id)
            ->where('code', 'carton')
            ->firstOrFail();

        $this->cartonProductUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unitCarton->id,
            'conversion_to_base' => '12.000000',
            'is_base' => false,
            'is_default_sale' => false,
            'is_default_purchase' => false,
            'active' => true,
        ]);
    }

    public function test_canonical_base_unit_labels_and_exact_seventeen_quantity_across_all_five_queries(): void
    {
        // 1. Post Purchase of 1 carton (12 base) + 5 pieces (5 base) = 17 base units
        $purchaseDraft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-02',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->cartonProductUnit->id,
                    'quantity' => '1.000000',
                    'unit_cost' => '120.000000',
                    'item_description' => 'شراء كرتونة تجريبية',
                ],
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->unit->id,
                    'quantity' => '5.000000',
                    'unit_cost' => '10.000000',
                    'item_description' => 'شراء حبات تجريبية',
                ],
            ],
        ]);
        app(PostPurchaseAction::class)->execute($purchaseDraft, $this->owner);

        // 2. Post Sales Invoice of 1 carton (12 base) + 5 pieces (5 base) = 17 base units
        $salesDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'issue_date' => '2026-10-03',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->cartonProductUnit->id,
                    'quantity' => '1.000000',
                    'unit_price' => '240.000000',
                    'item_description' => 'بيع كرتونة تجريبية',
                ],
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->unit->id,
                    'quantity' => '5.000000',
                    'unit_price' => '25.000000',
                    'item_description' => 'بيع حبات تجريبية',
                ],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($salesDraft, $this->owner);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $expectedQty = '17.000000';
        $expectedUnitAr = $this->unitPiece->name_ar;
        $expectedUnitEn = $this->unitPiece->name_en;

        // Query 1: SalesByProductReportQuery
        $salesByProd = app(SalesByProductReportQuery::class)->execute(
            $this->company,
            ['period' => 'this_month'],
            $this->owner
        );
        $this->assertEquals($expectedQty, $salesByProd->totals['quantity_base']);
        $this->assertCount(1, $salesByProd->rows);
        $row1 = $salesByProd->rows[0];
        $this->assertSame($this->product->id, $row1['product_id']);
        $this->assertSame($expectedQty, $row1['quantity_base']);
        $this->assertSame($expectedUnitAr, $row1['unit_name_ar']);
        $this->assertSame($expectedUnitEn, $row1['unit_name_en']);

        // Query 2: PurchasesByProductReportQuery
        $purchasesByProd = app(PurchasesByProductReportQuery::class)->execute(
            $this->company,
            ['period' => 'this_month'],
            $this->owner
        );
        $this->assertEquals($expectedQty, $purchasesByProd->totals['quantity_base']);
        $this->assertCount(1, $purchasesByProd->rows);
        $row2 = $purchasesByProd->rows[0];
        $this->assertSame($this->product->id, $row2['product_id']);
        $this->assertSame($expectedQty, $row2['quantity_base']);
        $this->assertSame($expectedUnitAr, $row2['unit_name_ar']);
        $this->assertSame($expectedUnitEn, $row2['unit_name_en']);

        // Query 3: SalesGrossProfitReportQuery (by_product aggregation)
        $grossProfit = app(SalesGrossProfitReportQuery::class)->execute(
            $this->company,
            ['period' => 'this_month', 'grouping' => 'by_product'],
            $this->owner
        );
        $this->assertEquals($expectedQty, $grossProfit->totals['total_quantity_base']);
        $this->assertCount(1, $grossProfit->rows);
        $row3 = $grossProfit->rows[0];
        $this->assertSame($this->product->id, $row3['product_id']);
        $this->assertSame($expectedQty, $row3['quantity_base']);
        $this->assertSame($expectedUnitAr, $row3['unit_name_ar']);
        $this->assertSame($expectedUnitEn, $row3['unit_name_en']);

        // Query 4: CustomerProductHistoryReportQuery
        $customerHistory = app(CustomerProductHistoryReportQuery::class)->execute(
            $this->company,
            ['customer_id' => $this->defaultCustomer->id, 'period' => 'this_month'],
            $this->owner
        );
        $this->assertEquals($expectedQty, $customerHistory->totals['total_quantity_base']);
        $this->assertCount(1, $customerHistory->rows);
        $row4 = $customerHistory->rows[0];
        $this->assertSame($this->product->id, $row4['product_id']);
        $this->assertSame($expectedQty, $row4['total_quantity_base']);
        $this->assertSame($expectedUnitAr, $row4['unit_name_ar']);
        $this->assertSame($expectedUnitEn, $row4['unit_name_en']);

        // Query 5: VendorProductHistoryReportQuery
        $vendorHistory = app(VendorProductHistoryReportQuery::class)->execute(
            $this->company,
            ['vendor_id' => $this->vendor->id, 'period' => 'this_month'],
            $this->owner
        );
        $this->assertEquals($expectedQty, $vendorHistory->totals['total_quantity_base']);
        $this->assertCount(1, $vendorHistory->rows);
        $row5 = $vendorHistory->rows[0];
        $this->assertSame($this->product->id, $row5['product_id']);
        $this->assertSame($expectedQty, $row5['total_quantity_base']);
        $this->assertSame($expectedUnitAr, $row5['unit_name_ar']);
        $this->assertSame($expectedUnitEn, $row5['unit_name_en']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $totalsQueries = array_filter($queries, static fn (array $q): bool => (bool) preg_match('/^select\s+(?:COUNT\(\*\)|COALESCE\(SUM)/i', $q['query']));
        $this->assertGreaterThanOrEqual(7, count($totalsQueries));
        foreach ($totalsQueries as $q) {
            $this->assertStringNotContainsString('ROW_NUMBER()', $q['query']);
        }

    }

    public function test_net_return_economics_retains_base_unit_labels(): void
    {
        // 1. Initial supply: 50 pieces
        $purchaseDraft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-01',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->unit->id,
                    'quantity' => '50.000000',
                    'unit_cost' => '10.000000',
                ],
            ],
        ]);
        $purchase = app(PostPurchaseAction::class)->execute($purchaseDraft, $this->owner);

        // 2. Sales invoice: 1 carton (12 base) + 5 pieces = 17 base
        $inv = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->cartonProductUnit->id,
                    'quantity' => '1.000000',
                    'unit_price' => '200.000000',
                ],
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->unit->id,
                    'quantity' => '5.000000',
                    'unit_price' => '20.000000',
                ],
            ],
        ]);

        // 3. Sales return: return 2 pieces
        $salesLineToReturn = $inv->lines()->where('product_unit_id', $this->unit->id)->firstOrFail();
        $returnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->defaultCustomer->id,
            'sales_invoice_id' => $inv->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-03',
            'lines' => [
                [
                    'sales_invoice_line_id' => $salesLineToReturn->id,
                    'quantity' => '2.000000',
                    'unit_price' => '20.000000',
                ],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($returnDraft, $this->owner);

        // Net quantity should be 17 - 2 = 15.000000
        $result = app(SalesByProductReportQuery::class)->execute(
            $this->company,
            ['period' => 'this_month'],
            $this->owner
        );
        $this->assertEquals('15.000000', $result->totals['quantity_base']);
        $this->assertSame('15.000000', $result->rows[0]['quantity_base']);
        $this->assertSame($this->unitPiece->name_ar, $result->rows[0]['unit_name_ar']);
        $this->assertSame($this->unitPiece->name_en, $result->rows[0]['unit_name_en']);
    }

    public function test_archived_product_preserves_canonical_base_unit_labels(): void
    {
        // 1. Create a distinct product
        $productB = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'سلعة مؤرشفة',
            'name_en' => 'Archived Product',
            'sku' => 'ARCH-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $this->unitPiece->id,
        ], $this->owner->id);
        $baseUnitB = ProductUnit::where('product_id', $productB->id)->firstOrFail();

        // 2. Supply stock & post sales invoice
        $purDraft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-01',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'product_id' => $productB->id,
                    'product_unit_id' => $baseUnitB->id,
                    'quantity' => '10.000000',
                    'unit_cost' => '15.000000',
                ],
            ],
        ]);
        app(PostPurchaseAction::class)->execute($purDraft, $this->owner);

        $inv = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $productB->id,
                    'product_unit_id' => $baseUnitB->id,
                    'quantity' => '4.000000',
                    'unit_price' => '30.000000',
                ],
            ],
        ]);

        // 3. Soft-delete the product
        $productB->delete();
        $this->assertSoftDeleted('products', ['id' => $productB->id]);

        // 4. Query sales report
        $result = app(SalesByProductReportQuery::class)->execute(
            $this->company,
            ['period' => 'this_month', 'product_id' => $productB->id],
            $this->owner
        );
        $this->assertCount(1, $result->rows);
        $row = $result->rows[0];
        $this->assertSame($productB->id, $row['product_id']);
        $this->assertSame('4.000000', $row['quantity_base']);
        $this->assertSame($this->unitPiece->name_ar, $row['unit_name_ar']);
        $this->assertSame($this->unitPiece->name_en, $row['unit_name_en']);
    }

    public function test_free_text_null_product_lines_expose_null_unit_labels(): void
    {
        // Sales invoice with null product_id (manual service / free text)
        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'أتعاب خدمة يدوية حرة',
                    'quantity' => '1.000000',
                    'unit_price' => '150.000000',
                ],
            ],
        ]);

        $result = app(SalesByProductReportQuery::class)->execute(
            $this->company,
            ['period' => 'this_month'],
            $this->owner
        );

        $this->assertGreaterThanOrEqual(1, count($result->rows));
        $nullProductRow = collect($result->rows)->first(fn ($r) => $r['product_id'] === null);
        $this->assertNotNull($nullProductRow);
        $this->assertSame('أتعاب خدمة يدوية حرة', $nullProductRow['item_description']);
        $this->assertNull($nullProductRow['unit_name_ar']);
        $this->assertNull($nullProductRow['unit_name_en']);
    }

    public function test_tenant_isolation_prevents_cross_company_base_unit_or_data_leakage(): void
    {
        // 1. Create Company B
        app(CompanyContext::class)->clear();
        $userB = User::factory()->create(['locale' => 'en']);
        $companyB = app(CreateCompanyAction::class)->execute($userB, [
            'name_ar' => 'شركة ثانية للعزل',
            'name_en' => 'Second Isolation Co',
            'base_currency_code' => 'ILS',
        ]);
        app(EnsureReportingFoundationAction::class)->execute($companyB);
        $this->activateCompanyUser($companyB, $userB);

        $customerB = Customer::create([
            'company_id' => $companyB->id,
            'name_ar' => 'عميل شركة ب',
            'name_en' => 'Company B Customer',
            'active' => true,
            'created_by' => $userB->id,
        ]);

        $unitPieceB = Unit::where('company_id', $companyB->id)->where('code', 'piece')->firstOrFail();
        $prodB = app(ProductCatalogService::class)->createProduct($companyB, [
            'name_ar' => 'منتج شركة ب',
            'name_en' => 'Company B Product',
            'sku' => 'COMPB-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $unitPieceB->id,
        ], $userB->id);
        $baseUnitB = ProductUnit::where('product_id', $prodB->id)->firstOrFail();

        $warehouseB = $companyB->warehouses()->firstOrFail();
        $vendorB = app(VendorCatalogService::class)->save($companyB, $userB, [
            'name_ar' => 'مورد شركة ب',
            'name_en' => 'Vendor B',
            'default_currency_code' => 'ILS',
        ]);

        // Purchase and sale in Company B
        $pDraft = app(CreatePurchaseDraftAction::class)->execute($companyB, $userB, [
            'vendor_id' => $vendorB->id,
            'warehouse_id' => $warehouseB->id,
            'purchase_date' => '2026-10-01',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'product_id' => $prodB->id,
                    'product_unit_id' => $baseUnitB->id,
                    'quantity' => '20.000000',
                    'unit_cost' => '10.000000',
                ],
            ],
        ]);
        app(PostPurchaseAction::class)->execute($pDraft, $userB);

        $sDraft = app(CreateSalesInvoiceDraftAction::class)->execute($companyB, $userB, [
            'customer_id' => $customerB->id,
            'issue_date' => '2026-10-02',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'lines' => [
                [
                    'product_id' => $prodB->id,
                    'product_unit_id' => $baseUnitB->id,
                    'item_description' => 'بيع منتج شركة ب',
                    'quantity' => '9.000000',
                    'unit_price' => '20.000000',
                ],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($sDraft, $userB);

        // 2. Query Company A as Owner A: must NOT see Company B data
        $this->activateCompanyUser($this->company, $this->owner);
        $resA = app(SalesByProductReportQuery::class)->execute(
            $this->company,
            ['period' => 'this_month'],
            $this->owner
        );
        $foundInA = collect($resA->rows)->first(fn ($r) => $r['product_id'] === $prodB->id);
        $this->assertNull($foundInA, 'Company A report must not contain Company B product');

        // 3. Query Company B as User B: sees only Company B data
        $this->activateCompanyUser($companyB, $userB);
        $resB = app(SalesByProductReportQuery::class)->execute(
            $companyB,
            ['period' => 'this_month'],
            $userB
        );
        $this->assertEquals('9.000000', $resB->totals['quantity_base']);
        $this->assertCount(1, $resB->rows);
        $this->assertSame($prodB->id, $resB->rows[0]['product_id']);
        $this->assertSame($unitPieceB->name_ar, $resB->rows[0]['unit_name_ar']);
        $this->assertSame($unitPieceB->name_en, $resB->rows[0]['unit_name_en']);
    }
}
