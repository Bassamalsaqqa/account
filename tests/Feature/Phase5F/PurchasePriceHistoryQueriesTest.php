<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5F;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Domain\Purchasing\Queries\ProductPurchaseHistoryQuery;
use App\Domain\Purchasing\Queries\PurchasePriceHistoryItem;
use App\Domain\Purchasing\Queries\VendorProductHistoryQuery;
use App\Domain\Purchasing\Queries\VendorProductPriceHistoryQuery;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchasePriceHistoryQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Vendor $vendor;

    protected Product $product;

    protected ProductUnit $baseUnit;

    protected ProductUnit $boxUnit;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة تاريخ الأسعار',
            'name_en' => 'Price History Company',
        ]);
        $this->activate($this->company, $this->owner);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'شركة المورد الأصلي',
            'name_en' => 'Original Vendor Ltd',
            'code' => 'VEND-001',
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();

        $piece = Unit::where('code', 'piece')->firstOrFail();
        $box = Unit::where('code', 'box')->firstOrFail();

        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'شاي فاخر',
            'name_en' => 'Luxury Tea',
            'sku' => 'TEA-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $piece->id,
        ], $this->owner->id);

        $this->baseUnit = ProductUnit::where('product_id', $this->product->id)
            ->where('unit_id', $piece->id)
            ->firstOrFail();

        $this->boxUnit = app(ProductCatalogService::class)->addOrUpdateAlternateUnit(
            $this->product,
            $box->id,
            '10.000000',
            false,
            false
        );
    }

    protected function activate(Company $company, User $actor): void
    {
        app(CompanyContext::class)->setCompany($company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }

    protected function createAndPostPurchase(array $overrides = []): Purchase
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, array_replace([
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-01',
            'due_date' => null,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->baseUnit->id,
                    'quantity' => '10',
                    'unit_cost' => '15.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0',
                ],
            ],
        ], $overrides));

        return app(PostPurchaseAction::class)->execute($draft, $this->owner);
    }

    public function test_product_purchase_history_returns_only_posted_purchases_and_excludes_drafts(): void
    {
        // 1. Posted purchase
        $posted = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-01',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->baseUnit->id,
                    'quantity' => '5',
                    'unit_cost' => '12.000000',
                ],
            ],
        ]);

        // 2. Draft purchase (unposted)
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-02',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->baseUnit->id,
                    'quantity' => '20',
                    'unit_cost' => '8.000000',
                ],
            ],
        ]);

        $query = app(ProductPurchaseHistoryQuery::class);
        $history = $query->execute($this->product, $this->company->id);

        $this->assertCount(1, $history);
        $item = $history->first();
        $this->assertInstanceOf(PurchasePriceHistoryItem::class, $item);
        $this->assertSame($posted->id, $item->purchase_id);
        $this->assertSame('12.000000', $item->unit_cost);
        $this->assertNotSame($draft->id, $item->purchase_id);
    }

    public function test_product_purchase_history_enforces_strict_tenant_isolation(): void
    {
        // Clear active context before creating a second company
        app(CompanyContext::class)->clear();

        // Create second company
        $otherUser = User::factory()->create(['locale' => 'en']);
        $otherCompany = app(CreateCompanyAction::class)->execute($otherUser, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Second Company',
        ]);
        $this->activate($otherCompany, $otherUser);

        $otherVendor = app(VendorCatalogService::class)->save($otherCompany, $otherUser, [
            'name_ar' => 'مورد آخر',
            'name_en' => 'Other Vendor',
        ]);
        $otherWarehouse = Warehouse::where('company_id', $otherCompany->id)->firstOrFail();
        $piece = Unit::where('code', 'piece')->firstOrFail();

        $otherProduct = app(ProductCatalogService::class)->createProduct($otherCompany, [
            'name_ar' => 'منتج آخر',
            'name_en' => 'Other Product',
            'sku' => 'OTHER-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $piece->id,
        ], $otherUser->id);

        $otherUnit = ProductUnit::where('product_id', $otherProduct->id)->firstOrFail();

        $otherDraft = app(CreatePurchaseDraftAction::class)->execute($otherCompany, $otherUser, [
            'vendor_id' => $otherVendor->id,
            'warehouse_id' => $otherWarehouse->id,
            'purchase_date' => '2026-10-01',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [
                [
                    'product_id' => $otherProduct->id,
                    'product_unit_id' => $otherUnit->id,
                    'quantity' => '10',
                    'unit_cost' => '25.000000',
                ],
            ],
        ]);
        app(PostPurchaseAction::class)->execute($otherDraft, $otherUser);

        // Switch back to original company
        $this->activate($this->company, $this->owner);

        $query = app(ProductPurchaseHistoryQuery::class);

        // 1. Querying company 1 with company 1's product returns 0 lines
        $this->assertCount(0, $query->execute($this->product, $this->company->id));

        // 2. Querying company 1 with otherCompany's product throws AuthorizationException
        $this->expectException(AuthorizationException::class);
        $query->execute($otherProduct, $this->company->id);
    }

    public function test_raw_snapshots_preserved_even_when_vendor_or_product_is_soft_deleted_or_renamed(): void
    {
        $posted = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->baseUnit->id,
                    'quantity' => '10',
                    'unit_cost' => '30.000000',
                ],
            ],
        ]);

        // Soft delete the vendor
        $this->vendor->delete();

        // Update current product name in master data
        $this->product->update(['name_ar' => 'اسم معدل في الماستر', 'name_en' => 'Master Modified Name']);

        $query = app(ProductPurchaseHistoryQuery::class);
        $history = $query->execute($this->product->id, $this->company->id);

        $this->assertCount(1, $history);
        $item = $history->first();

        // Historical snapshots preserved faithfully
        $this->assertSame('شركة المورد الأصلي', $item->vendor_name);
        $this->assertNull($item->vendor_code);
        $this->assertSame('شاي فاخر', $item->product_name);
        $this->assertSame('TEA-001', $item->product_sku);
        $this->assertSame('30.000000', $item->unit_cost);
    }

    public function test_purchase_returns_never_erase_or_rewrite_original_purchase_price_history(): void
    {
        $posted = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->baseUnit->id,
                    'quantity' => '10',
                    'unit_cost' => '20.000000',
                ],
            ],
        ]);

        // Create and post a purchase return for 4 pieces
        $returnDraft = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $posted->id,
            'return_date' => '2026-10-03',
            'lines' => [
                [
                    'purchase_line_id' => $posted->lines->first()->id,
                    'quantity' => '4',
                ],
            ],
        ]);
        app(PostPurchaseReturnAction::class)->execute($returnDraft, $this->owner);

        // Price history query should still reflect the original purchase quantity and unit cost
        $query = app(ProductPurchaseHistoryQuery::class);
        $history = $query->execute($this->product, $this->company->id);

        $this->assertCount(1, $history);
        $item = $history->first();
        $this->assertSame('10.000000', $item->quantity);
        $this->assertSame('20.000000', $item->unit_cost);
        $this->assertSame('20.000000', $item->net_commercial_price_per_base_unit);
    }

    public function test_derived_net_commercial_price_per_base_unit_with_percentage_discount_and_multi_unit(): void
    {
        // 2 Boxes (each box = 10 pieces => 20 pieces base)
        // Unit cost = 100 ILS per box. Total subtotal = 200 ILS.
        // Discount 10% on the line => line discount = 20 ILS.
        // Net commercial total in base = 180 ILS.
        // Quantity base = 20.
        // Net commercial price per base unit = 180 / 20 = 9.000000 ILS per piece.
        $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->boxUnit->id,
                    'quantity' => '2',
                    'unit_cost' => '100.000000',
                    'discount_type' => 'percent',
                    'discount_value' => '10.000000',
                ],
            ],
        ]);

        $query = app(ProductPurchaseHistoryQuery::class);
        $item = $query->latest($this->product, $this->company->id);

        $this->assertNotNull($item);
        $this->assertSame('100.000000', $item->unit_cost);
        $this->assertSame('percent', $item->discount_type);
        $this->assertSame('10.000000', $item->discount_value);
        $this->assertSame('20.000000', $item->line_discount);
        $this->assertSame('20.000000', $item->quantity_base);
        $this->assertSame('180.000000', $item->net_commercial_total_base);
        $this->assertSame('9.000000', $item->net_commercial_price_per_base_unit);
    }

    public function test_derived_net_commercial_price_per_base_unit_with_fixed_discount_and_foreign_currency_fx(): void
    {
        // Currency = USD, exchange rate = 3.5000000000
        // 4 Boxes (40 pieces base) at $40.00 USD per box = $160.00 USD subtotal.
        // Fixed discount = $10.00 USD.
        // Net subtotal currency = $150.00 USD.
        // Subtotal base = 160 * 3.5 = 560.000000 ILS.
        // Discount base = 10 * 3.5 = 35.000000 ILS.
        // Net commercial total base = 560 - 35 = 525.000000 ILS.
        // Quantity base = 40.
        // Net commercial price per base unit = 525 / 40 = 13.125000 ILS per piece.
        $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->boxUnit->id,
                    'quantity' => '4',
                    'unit_cost' => '40.000000',
                    'discount_type' => 'fixed',
                    'discount_value' => '10.000000',
                ],
            ],
        ]);

        $query = app(ProductPurchaseHistoryQuery::class);
        $item = $query->latest($this->product, $this->company->id);

        $this->assertNotNull($item);
        $this->assertSame('USD', $item->currency_code);
        $this->assertSame('ILS', $item->base_currency_code);
        $this->assertSame('3.5000000000', $item->exchange_rate);
        $this->assertSame('40.000000', $item->unit_cost);
        $this->assertSame('525.000000', $item->net_commercial_total_base);
        $this->assertSame('13.125000', $item->net_commercial_price_per_base_unit);
    }

    public function test_comparison_metric_excludes_tax_and_handles_tax_inclusive(): void
    {
        $tax = TaxRate::where('company_id', $this->company->id)->where('rate', '16.000000')->first();
        if ($tax === null) {
            $tax = TaxRate::create([
                'company_id' => $this->company->id,
                'code' => 'TAX16',
                'name_ar' => 'ضريبة 16%',
                'name_en' => 'VAT 16%',
                'rate' => '16.000000',
                'active' => true,
            ]);
        }

        // 10 pieces at 20 ILS with 16% tax (exclusive)
        // Subtotal = 200 ILS. Tax = 32 ILS. Total = 232 ILS.
        // Commercial comparison metric must exclude tax:
        // Net commercial total base = 200.000000 ILS.
        // Net commercial price per base unit = 20.000000 ILS.
        $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->baseUnit->id,
                    'quantity' => '10',
                    'unit_cost' => '20.000000',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);

        $query = app(ProductPurchaseHistoryQuery::class);
        $item = $query->latest($this->product, $this->company->id);

        $this->assertNotNull($item);
        $this->assertSame('200.000000', $item->line_subtotal_base);
        $this->assertSame('32.000000', $item->line_tax_base);
        $this->assertSame('232.000000', $item->line_total_base);
        $this->assertSame('200.000000', $item->net_commercial_total_base);
        $this->assertSame('20.000000', $item->net_commercial_price_per_base_unit);
    }

    public function test_vendor_product_history_and_products_supplied_aggregation(): void
    {
        // Create 2nd product
        $piece = Unit::where('code', 'piece')->firstOrFail();
        $product2 = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'بن محمص',
            'name_en' => 'Roasted Coffee',
            'sku' => 'COF-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $piece->id,
        ], $this->owner->id);
        $unit2 = ProductUnit::where('product_id', $product2->id)->firstOrFail();

        // Purchase 1: Product 1
        $this->createAndPostPurchase([
            'purchase_date' => '2026-10-01',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->baseUnit->id,
                    'quantity' => '10',
                    'unit_cost' => '15.000000',
                ],
            ],
        ]);

        // Purchase 2: Product 1 again (later date)
        $this->createAndPostPurchase([
            'purchase_date' => '2026-10-03',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->baseUnit->id,
                    'quantity' => '5',
                    'unit_cost' => '18.000000',
                ],
            ],
        ]);

        // Purchase 3: Product 2
        $this->createAndPostPurchase([
            'purchase_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $product2->id,
                    'product_unit_id' => $unit2->id,
                    'quantity' => '8',
                    'unit_cost' => '40.000000',
                ],
            ],
        ]);

        $query = app(VendorProductHistoryQuery::class);

        // 1. All lines for vendor
        $allLines = $query->execute($this->vendor, $this->company->id);
        $this->assertCount(3, $allLines);
        // Ordered by date desc: 2026-10-03 first, then 2026-10-02, then 2026-10-01
        $this->assertSame('2026-10-03', $allLines->get(0)->purchase_date);
        $this->assertSame('2026-10-02', $allLines->get(1)->purchase_date);
        $this->assertSame('2026-10-01', $allLines->get(2)->purchase_date);

        // 2. Filtered by product 1
        $prod1Lines = $query->execute($this->vendor, $this->company->id, productId: $this->product->id);
        $this->assertCount(2, $prod1Lines);

        // 3. productsSupplied summary aggregation
        $supplied = $query->productsSupplied($this->vendor, $this->company->id);
        $this->assertCount(2, $supplied);

        $prod1Summary = $supplied->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($prod1Summary);
        $this->assertSame('2026-10-03', $prod1Summary['latest_purchase_date']);
        $this->assertSame('18.000000', $prod1Summary['latest_unit_cost']);
        $this->assertSame('18.000000', $prod1Summary['latest_net_commercial_price_per_base_unit']);
        $this->assertSame(2, $prod1Summary['purchases_count']);
        $this->assertSame('15.000000', $prod1Summary['total_quantity_base']); // 10 + 5

        $prod2Summary = $supplied->firstWhere('product_id', $product2->id);
        $this->assertNotNull($prod2Summary);
        $this->assertSame('2026-10-02', $prod2Summary['latest_purchase_date']);
        $this->assertSame('40.000000', $prod2Summary['latest_unit_cost']);
        $this->assertSame(1, $prod2Summary['purchases_count']);
        $this->assertSame('8.000000', $prod2Summary['total_quantity_base']);
    }

    public function test_vendor_product_price_history_query_and_latest_helper(): void
    {
        $this->createAndPostPurchase([
            'purchase_date' => '2026-09-15',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->baseUnit->id,
                    'quantity' => '10',
                    'unit_cost' => '14.000000',
                ],
            ],
        ]);

        $this->createAndPostPurchase([
            'purchase_date' => '2026-10-01',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->baseUnit->id,
                    'quantity' => '12',
                    'unit_cost' => '16.500000',
                ],
            ],
        ]);

        $query = app(VendorProductPriceHistoryQuery::class);
        $latest = $query->latest($this->vendor, $this->product, $this->company->id);

        $this->assertNotNull($latest);
        $this->assertSame('2026-10-01', $latest->purchase_date);
        $this->assertSame('16.500000', $latest->unit_cost);
        $this->assertSame('16.500000', $latest->net_commercial_price_per_base_unit);
    }

    public function test_bounded_limits_and_deterministic_ordering(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $day = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->createAndPostPurchase([
                'purchase_date' => "2026-10-{$day}",
                'lines' => [
                    [
                        'product_id' => $this->product->id,
                        'product_unit_id' => $this->baseUnit->id,
                        'quantity' => '1',
                        'unit_cost' => (string) ($i * 10),
                    ],
                ],
            ]);
        }

        $query = app(ProductPurchaseHistoryQuery::class);

        // Test limit 3
        $results = $query->execute($this->product, $this->company->id, limit: 3);
        $this->assertCount(3, $results);
        $this->assertSame('2026-10-05', $results->get(0)->purchase_date);
        $this->assertSame('2026-10-04', $results->get(1)->purchase_date);
        $this->assertSame('2026-10-03', $results->get(2)->purchase_date);

        // Bounded limit minimum = 1
        $resultsMin = $query->execute($this->product, $this->company->id, limit: 0);
        $this->assertCount(1, $resultsMin);
    }

    public function test_zero_write_and_no_side_effects_on_financial_or_inventory_state(): void
    {
        $this->createAndPostPurchase();

        $captureState = function (): array {
            $state = [];
            foreach ([
                'purchases', 'purchase_lines', 'posting_batches', 'posting_lines',
                'stock_movements', 'inventory_balances', 'inventory_cost_states',
            ] as $table) {
                $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $state;
        };

        $beforeState = $captureState();

        // Run all Phase 5F queries
        app(ProductPurchaseHistoryQuery::class)->execute($this->product, $this->company->id);
        app(ProductPurchaseHistoryQuery::class)->latest($this->product, $this->company->id);
        app(VendorProductHistoryQuery::class)->execute($this->vendor, $this->company->id);
        app(VendorProductHistoryQuery::class)->productsSupplied($this->vendor, $this->company->id);
        app(VendorProductPriceHistoryQuery::class)->execute($this->vendor, $this->product, $this->company->id);
        app(VendorProductPriceHistoryQuery::class)->latest($this->vendor, $this->product, $this->company->id);

        $afterState = $captureState();

        $this->assertSame($beforeState, $afterState, 'Price history queries must produce zero database mutations or side effects.');
    }

    protected function createRestrictedActor(array $permissions): User
    {
        $actor = User::factory()->create();
        $this->company->users()->attach($actor->id, ['status' => 'active', 'is_owner' => false, 'joined_at' => now()]);
        setPermissionsTeamId($this->company->id);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'perm_query_'.$actor->id, 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        $actor->assignRole($role);

        return $actor;
    }

    public function test_regression_r1_inclusive_tax_and_jod_fx_comparative_prices(): void
    {
        // 1. Genuine inclusive VAT test: 116 ILS inclusive 16% -> net commercial price = 100.000000
        $inclusiveTax = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'INC16',
            'name_ar' => 'ضريبة مشمولة 16%',
            'name_en' => 'Inclusive VAT 16%',
            'rate' => '16.000000',
            'calculation' => TaxRate::CALC_INCLUSIVE,
            'active' => true,
        ]);

        $purchaseInc = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-01',
            'lines' => [[
                'product_id' => $this->product->id,
                'product_unit_id' => $this->baseUnit->id,
                'quantity' => '1',
                'unit_cost' => '116.000000',
                'tax_rate_id' => $inclusiveTax->id,
            ]],
        ]);
        $this->assertTrue($purchaseInc->lines->first()->tax_inclusive);

        $historyInc = app(ProductPurchaseHistoryQuery::class)->latest($this->product, $this->company->id);
        $this->assertNotNull($historyInc);
        $this->assertSame('100.000000', $historyInc->net_commercial_total_base);
        $this->assertSame('100.000000', $historyInc->net_commercial_price_per_base_unit);

        // 2. JOD 3-decimal currency with exchange rate and exact 6-decimal scaling
        // 3 pieces at 10.000 JOD with 1.000 JOD fixed discount, exchange rate 5.0000000000
        // (30 - 1) * 5.0 = 145.000000 ILS net commercial total base
        // 145 / 3 = 48.333333 ILS per base unit
        $purchaseJod = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-02',
            'currency_code' => 'JOD',
            'exchange_rate' => '5.0000000000',
            'lines' => [[
                'product_id' => $this->product->id,
                'product_unit_id' => $this->baseUnit->id,
                'quantity' => '3',
                'unit_cost' => '10.000000',
                'discount_type' => 'fixed',
                'discount_value' => '1.000000',
            ]],
        ]);

        $historyJod = app(ProductPurchaseHistoryQuery::class)->latest($this->product, $this->company->id);
        $this->assertNotNull($historyJod);
        $this->assertSame('JOD', $historyJod->currency_code);
        $this->assertSame('145.000000', $historyJod->net_commercial_total_base);
        $this->assertSame('48.333333', $historyJod->net_commercial_price_per_base_unit);
    }

    public function test_regression_r2_rejects_incoherent_posted_purchase(): void
    {
        $purchase = $this->createAndPostPurchase();
        DB::table('purchases')->where('id', $purchase->id)->update(['posting_batch_id' => null]);

        try {
            $history = app(ProductPurchaseHistoryQuery::class)->execute($this->product, $this->company->id);
            $this->assertCount(0, $history, 'Incoherent Purchase must not be returned in price history.');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_regression_r3_query_guard_enforces_active_actor_and_cost_view_permission(): void
    {
        $this->createAndPostPurchase();

        // 1. Guest without login rejected
        auth()->logout();
        try {
            app(ProductPurchaseHistoryQuery::class)->execute($this->product, $this->company->id);
            $this->fail('Guest must be rejected by PurchasingHistoryGuard.');
        } catch (AuthorizationException $e) {
            $this->assertSame('Authentication is required to view purchase price history.', $e->getMessage());
        }

        // 2. User without purchasing.cost.view rejected
        $userWithoutCost = $this->createRestrictedActor(['inventory.stock.view']);
        $this->activate($this->company, $userWithoutCost);

        try {
            app(ProductPurchaseHistoryQuery::class)->execute($this->product, $this->company->id);
            $this->fail('Actor without purchasing.cost.view must be rejected.');
        } catch (AuthorizationException $e) {
            $this->assertSame('Permission purchasing.cost.view is required to view purchase price history.', $e->getMessage());
        }

        // 3. Foreign company product rejected
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'شركة أخرى']);
        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);
        $otherProduct = app(ProductCatalogService::class)->createProduct($otherCompany, [
            'name_ar' => 'منتج آخر',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
        ], $otherOwner->id);

        $this->activate($this->company, $this->owner);
        $this->expectException(AuthorizationException::class);
        app(ProductPurchaseHistoryQuery::class)->execute($otherProduct, $this->company->id);
    }

    public function test_regression_r4_unsnapshotted_vendor_code_and_null_sku_preserved(): void
    {
        // 1. Historical null SKU remains null after catalog edit
        $this->product->update(['sku' => null]);
        $purchase = $this->createAndPostPurchase();
        $this->assertNull($purchase->lines->first()->product_sku);

        $this->product->update(['sku' => 'NEW-SKU-ADDED']);
        $history = app(ProductPurchaseHistoryQuery::class)->latest($this->product, $this->company->id);
        $this->assertNull($history->product_sku, 'A null historical SKU must not become a new master SKU.');

        // 2. Unsnapshotted vendor code remains null and is not rewritten by master edit
        $this->assertArrayNotHasKey('code', $purchase->vendor_snapshot);
        $before = app(ProductPurchaseHistoryQuery::class)->latest($this->product, $this->company->id)->vendor_code;
        $this->assertNull($before);

        $this->vendor->update(['code' => 'NEW-VENDOR-CODE']);
        $after = app(ProductPurchaseHistoryQuery::class)->latest($this->product, $this->company->id)->vendor_code;
        $this->assertNull($after, 'Unsnapshotted mutable code must not be rewritten as frozen history.');
    }

    public function test_regression_r6_supplied_products_counts_distinct_purchases_and_batches_latest(): void
    {
        // 1. One purchase with two lines of the same product must count as 1 purchase
        $this->createAndPostPurchase(['lines' => [
            ['product_id' => $this->product->id, 'product_unit_id' => $this->baseUnit->id, 'quantity' => '1', 'unit_cost' => '10.000000'],
            ['product_id' => $this->product->id, 'product_unit_id' => $this->boxUnit->id, 'quantity' => '1', 'unit_cost' => '100.000000'],
        ]]);

        $summary = app(VendorProductHistoryQuery::class)->productsSupplied($this->vendor, $this->company->id)->first();
        $this->assertSame(1, $summary['purchases_count'], 'purchases_count must count distinct purchase documents.');

        // 2. Batched latest query helper
        $batched = app(VendorProductPriceHistoryQuery::class)->latestForProducts(
            $this->vendor,
            [$this->product->id],
            $this->company->id
        );

        $this->assertArrayHasKey($this->product->id, $batched);
        $this->assertSame('10.000000', $batched[$this->product->id]->unit_cost);
    }

    public function test_latest_hints_hydrate_and_validate_only_selected_latest_documents(): void
    {
        $latest = $this->createAndPostPurchase(['purchase_date' => '2026-10-03']);
        // A higher ID with an older business date must not replace the most recent price.
        $old = $this->createAndPostPurchase(['purchase_date' => '2026-10-01']);
        DB::table('posting_batches')->where('id', $old->posting_batch_id)->update(['source_id' => 999999]);

        $items = app(VendorProductPriceHistoryQuery::class)->latestForProducts(
            $this->vendor, [$this->product->id, $this->product->id], $this->company->id
        );
        $this->assertCount(1, $items);
        $this->assertSame($latest->id, $items[$this->product->id]->purchase_id);
    }

    public function test_supplied_summary_counts_only_its_explicit_recent_line_window(): void
    {
        $oldest = $this->createAndPostPurchase(['purchase_date' => '2026-09-01']);
        for ($i = 0; $i < VendorProductHistoryQuery::SUMMARY_LINE_LIMIT; $i++) {
            $this->createAndPostPurchase(['purchase_date' => '2026-10-01']);
        }
        // History outside the advertised window is neither summarized nor hydrated.
        DB::table('posting_batches')->where('id', $oldest->posting_batch_id)->update(['source_id' => 999999]);
        $summary = app(VendorProductHistoryQuery::class)->productsSupplied($this->vendor, $this->company->id)->sole();
        $this->assertSame(100, $summary['purchases_count']);
        $this->assertSame('1000.000000', $summary['total_quantity_base']);
    }

    public function test_batched_hints_reject_unpersisted_product_identity(): void
    {
        $this->expectException(AuthorizationException::class);
        app(VendorProductPriceHistoryQuery::class)->latestForProducts($this->vendor, [999999999], $this->company->id);
    }

    public function test_history_guard_checks_persisted_model_identity(): void
    {
        $forgedProduct = new Product(['company_id' => $this->company->id]);
        $forgedProduct->id = 999999999;
        $this->expectException(AuthorizationException::class);
        app(ProductPurchaseHistoryQuery::class)->execute($forgedProduct, $this->company->id);
    }

    public function test_correction02_history_query_cost_is_batched_without_deep_posting_replay(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->createAndPostPurchase();
        }
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $query = app(ProductPurchaseHistoryQuery::class);
        $this->assertCount(1, $query->execute($this->product, $this->company->id, limit: 1));
        $smallCount = count($queries);
        $queries = [];
        $this->assertCount(8, $query->execute($this->product, $this->company->id, limit: 8));
        $this->assertLessThanOrEqual($smallCount + 1, count($queries), 'Rendered Purchase count must not add per-document replay queries.');
        $this->assertDoesNotMatchRegularExpression('/(stock_movements|posting_lines|purchase_line_lots|inventory_lots|inventory_cost_states)/i', implode("\n", $queries));

        foreach (['ProductPurchaseHistoryQuery', 'VendorProductHistoryQuery', 'VendorProductPriceHistoryQuery'] as $name) {
            $source = file_get_contents(app_path('Domain/Purchasing/Queries/'.$name.'.php'));
            $this->assertStringNotContainsString('PurchasePostingCommandBuilder', $source);
            $this->assertStringNotContainsString('validatePosted', $source);
        }
    }

    /** @return array<string, array{string}> */
    public static function correction02InvalidProvenance(): array
    {
        return [
            'foreign batch company' => ['foreign_company'],
            'wrong batch type' => ['wrong_type'],
            'wrong batch source' => ['wrong_source'],
            'wrong canonical batch pointer' => ['wrong_pointer'],
            'batch not posted' => ['reversed_batch'],
            'missing posting actor' => ['missing_actor'],
        ];
    }

    #[DataProvider('correction02InvalidProvenance')]
    public function test_correction02_lightweight_provenance_rejects_damaged_source(string $damage): void
    {
        $purchase = $this->createAndPostPurchase();
        if ($damage === 'foreign_company') {
            app(CompanyContext::class)->clear();
            $otherOwner = User::factory()->create();
            $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'شركة أخرى']);
            $this->activate($this->company, $this->owner);
            DB::table('posting_batches')->where('id', $purchase->posting_batch_id)->update(['company_id' => $otherCompany->id]);
        } elseif ($damage === 'wrong_pointer') {
            $otherPurchase = $this->createAndPostPurchase();
            DB::table('purchases')->where('id', $purchase->id)->update(['posting_batch_id' => $otherPurchase->posting_batch_id]);
        } elseif ($damage === 'missing_actor') {
            DB::table('purchases')->where('id', $purchase->id)->update(['posted_by' => null]);
        } else {
            $changes = match ($damage) {
                'wrong_type' => ['source_type' => 'vendor_payment'],
                'wrong_source' => ['source_id' => 999999999],
                'reversed_batch' => ['status' => 'reversed'],
            };
            DB::table('posting_batches')->where('id', $purchase->posting_batch_id)->update($changes);
        }
        $this->expectException(\InvalidArgumentException::class);
        app(ProductPurchaseHistoryQuery::class)->execute($this->product, $this->company->id);
    }

    public function test_correction02_all_history_paths_reject_wrong_original_batch(): void
    {
        $purchase = $this->createAndPostPurchase();
        DB::table('posting_batches')->where('id', $purchase->posting_batch_id)->update(['source_type' => 'vendor_payment']);
        $reads = [
            fn () => app(VendorProductHistoryQuery::class)->execute($this->vendor, $this->company->id),
            fn () => app(VendorProductHistoryQuery::class)->productsSupplied($this->vendor, $this->company->id),
            fn () => app(VendorProductPriceHistoryQuery::class)->execute($this->vendor, $this->product, $this->company->id),
            fn () => app(VendorProductPriceHistoryQuery::class)->latestForProducts($this->vendor, [$this->product->id], $this->company->id),
        ];
        foreach ($reads as $read) {
            try {
                $read();
                $this->fail('Corrupt original batch metadata must not become price history.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_correction02_nonposted_purchase_is_not_price_history(): void
    {
        $purchase = $this->createAndPostPurchase();
        DB::table('purchases')->where('id', $purchase->id)->update(['status' => Purchase::STATUS_DRAFT]);
        $this->assertCount(0, app(ProductPurchaseHistoryQuery::class)->execute($this->product, $this->company->id));
        $this->assertCount(0, app(VendorProductHistoryQuery::class)->execute($this->vendor, $this->company->id));
        $this->assertSame([], app(VendorProductPriceHistoryQuery::class)->latestForProducts($this->vendor, [$this->product->id], $this->company->id));
    }

    /** @return array<string, array{string}> */
    public static function correction02DamagedSnapshots(): array
    {
        return [
            'missing vendor name' => ['vendor_missing'],
            'malformed vendor name' => ['vendor_malformed'],
            'missing product names' => ['product'],
            'missing unit names' => ['unit'],
        ];
    }

    #[DataProvider('correction02DamagedSnapshots')]
    public function test_correction02_damaged_identity_never_uses_current_master_names(string $damage): void
    {
        $purchase = $this->createAndPostPurchase();
        $this->vendor->update(['name_ar' => 'CURRENT VENDOR MUST NOT APPEAR']);
        $this->product->update(['name_ar' => 'CURRENT PRODUCT MUST NOT APPEAR']);
        if (str_starts_with($damage, 'vendor_')) {
            $snapshot = $damage === 'vendor_missing' ? [] : ['name_ar' => ['invalid' => 'name']];
            DB::table('purchases')->where('id', $purchase->id)->update(['vendor_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        } elseif ($damage === 'product') {
            DB::table('purchase_lines')->where('purchase_id', $purchase->id)->update(['product_name_ar' => '', 'product_name_en' => '']);
        } else {
            DB::table('purchase_lines')->where('purchase_id', $purchase->id)->update(['unit_name_ar' => '', 'unit_name_en' => '']);
        }
        $this->expectException(\InvalidArgumentException::class);
        app(ProductPurchaseHistoryQuery::class)->execute($this->product, $this->company->id);
    }
}
