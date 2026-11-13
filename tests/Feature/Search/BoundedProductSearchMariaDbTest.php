<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Search\BoundedProductSearchService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BoundedProductSearchMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Warehouse $warehouse;

    protected Unit $pieceUnit;

    protected BoundedProductSearchService $searchService;

    public function test_quotation_shared_picker_is_bounded_and_revalidates_selected_identity(): void
    {
        $product = $this->createProduct('صنف تجريبي', 'Test item', 'QUOTE-PICK');
        $form = Livewire::test(QuotationForm::class)
            ->set('productSearch', $product->sku);
        $this->assertLessThanOrEqual(30, count($form->get('searchResults')));
        $this->assertSame($product->sku, $form->get('searchResultsQuery'));
        $this->assertNull($form->get('searchResults.0.cost'));
        $form->call('addProduct', $product->id);
        $this->assertSame($product->id, $form->get('lines.0.product_id'));
        $this->assertNotEmpty($form->get('lines.0.row_key'));
        $this->assertSame('', $form->get('productSearch'));
        $form->call('addProduct', 2147483647)->assertNotFound();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة بحث المنتجات',
            'name_en' => 'Product Search Company',
        ]);
        $this->activate($this->owner);
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $this->pieceUnit = Unit::where('code', 'piece')->firstOrFail();
        $this->searchService = app(BoundedProductSearchService::class);
    }

    protected function activate(User $actor): void
    {
        app(CompanyContext::class)->setCompany($this->company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }

    public function test_exact_barcode_and_sku_have_deterministic_priority_over_prefix_and_name(): void
    {
        // Product 1: exact barcode match
        $prodBarcode = $this->createProduct('شامبو عادي', 'Shampoo Regular', 'SKU-SHAMP-1');
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $prodBarcode->id,
            'barcode' => '777888999',
            'is_primary' => true,
        ]);

        // Product 2: exact SKU match
        $prodSku = $this->createProduct('شامبو بلسم', 'Shampoo Conditioner', '777888999');

        // Product 3: prefix name match
        $prodName = $this->createProduct('777888999 منتج بالاسم', '777888999 Name Product', 'SKU-NAME-777');

        $results = $this->searchService->search(
            (int) $this->company->id,
            $this->owner,
            '777888999',
            'sales'
        );

        $this->assertNotEmpty($results);
        // Tier 1 exact barcode score is 100
        $this->assertSame((int) $prodBarcode->id, $results[0]['id']);
        $this->assertSame('777888999', $results[0]['matched_barcode']);

        // Tier 1 exact SKU score is 95
        $this->assertSame((int) $prodSku->id, $results[1]['id']);
    }

    public function test_inactive_products_and_inactive_barcodes_are_never_returned(): void
    {
        $inactiveProd = $this->createProduct('منتج غير نشط', 'Inactive Product', 'INACT-001');
        $inactiveProd->update(['active' => false]);
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $inactiveProd->id,
            'barcode' => 'BAR-INACT-001',
            'is_primary' => true,
        ]);

        $activeProd = $this->createProduct('منتج نشط', 'Active Product', 'ACT-001');
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $activeProd->id,
            'barcode' => 'BAR-ACT-001',
            'is_primary' => true,
        ]);

        $results = $this->searchService->search(
            (int) $this->company->id,
            $this->owner,
            'INACT-001',
            'sales'
        );
        $this->assertEmpty($results, 'Inactive products must never appear in search results.');

        $barcodeResults = $this->searchService->search(
            (int) $this->company->id,
            $this->owner,
            'BAR-INACT-001',
            'sales'
        );
        $this->assertEmpty($barcodeResults, 'Inactive product barcodes must never appear in search results.');
    }

    public function test_purchasing_context_strictly_excludes_service_and_non_stock_products(): void
    {
        $serviceProd = $this->createProduct('خدمة صيانة', 'Maintenance Service', 'SRV-001', Product::TYPE_SERVICE, false);
        $stockProd = $this->createProduct('قطعة غيار', 'Spare Part', 'SRV-PART-001', Product::TYPE_STOCK, true);

        // Sales search should find both
        $salesResults = $this->searchService->search(
            (int) $this->company->id,
            $this->owner,
            'SRV',
            'sales'
        );
        $salesIds = array_column($salesResults, 'id');
        $this->assertContains((int) $serviceProd->id, $salesIds);
        $this->assertContains((int) $stockProd->id, $salesIds);

        // Purchasing search must strictly exclude service product
        $purchasingResults = $this->searchService->search(
            (int) $this->company->id,
            $this->owner,
            'SRV',
            'purchasing'
        );
        $purchasingIds = array_column($purchasingResults, 'id');
        $this->assertNotContains((int) $serviceProd->id, $purchasingIds, 'Service products must not be returned in purchasing context.');
        $this->assertContains((int) $stockProd->id, $purchasingIds);
    }

    public function test_purchasing_cost_is_strictly_redacted_when_permission_missing(): void
    {
        $product = $this->createProduct('سكر ابيض', 'White Sugar', 'SUGAR-001');
        $pu = ProductUnit::where('product_id', $product->id)->firstOrFail();
        $pu->update(['default_purchase_price_base' => '15.500000']);

        // User without purchasing.cost.view
        $cashier = User::factory()->create(['locale' => 'ar']);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $cashier->id,
            'role' => 'cashier',
            'status' => 'active',
        ]);
        setPermissionsTeamId($this->company->id);
        $role = Role::firstOrCreate(['name' => 'limited_cashier', 'guard_name' => 'web']);
        $role->givePermissionTo('purchasing.purchase.create');
        $cashier->assignRole($role);

        $results = $this->searchService->search(
            (int) $this->company->id,
            $cashier,
            'SUGAR',
            'purchasing'
        );

        $this->assertNotEmpty($results);
        $this->assertNull($results[0]['cost'], 'Cost must be strictly null when user lacks purchasing.cost.view.');

        // User with purchasing.cost.view
        $ownerResults = $this->searchService->search(
            (int) $this->company->id,
            $this->owner,
            'SUGAR',
            'purchasing'
        );
        $this->assertNotEmpty($ownerResults);
        $this->assertSame('15.50', $ownerResults[0]['cost']);
        $role->givePermissionTo('purchasing.cost.view');
        $visible = $this->searchService->search((int) $this->company->id, $cashier, 'SUGAR', 'purchasing');
        $this->assertSame('15.50', $visible[0]['cost']);
        $role->revokePermissionTo('purchasing.cost.view');
        $redacted = $this->searchService->search((int) $this->company->id, $cashier, 'SUGAR', 'purchasing');
        $this->assertNull($redacted[0]['cost']);
        $this->assertStringNotContainsString('15.50', json_encode($redacted, JSON_THROW_ON_ERROR));

    }

    public function test_stock_status_truthfully_distinguishes_out_of_stock_low_stock_and_healthy(): void
    {
        $prodOut = $this->createProduct('منتج نافد', 'Out of stock prod', 'STOCK-OUT');
        $prodLow = $this->createProduct('منتج منخفض', 'Low stock prod', 'STOCK-LOW');
        $prodLow->update(['minimum_stock_base' => '10.000000']);
        $prodOk = $this->createProduct('منتج متوفر', 'Healthy prod', 'STOCK-OK');
        $prodOk->update(['minimum_stock_base' => '5.000000']);

        // Set inventory balances in base units
        InventoryBalance::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $prodLow->id,
            'quantity_base' => '8.000000',
        ]);

        InventoryBalance::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $prodOk->id,
            'quantity_base' => '50.000000',
        ]);

        $results = $this->searchService->search((int) $this->company->id, $this->owner, 'STOCK-', 'sales', (int) $this->warehouse->id);
        $bySku = collect($results)->keyBy('sku');

        $this->assertSame('out_of_stock', $bySku['STOCK-OUT']['stock_status']);
        $this->assertSame('low_stock', $bySku['STOCK-LOW']['stock_status']);
        $this->assertSame('ok', $bySku['STOCK-OK']['stock_status']);
    }

    public function test_search_products_query_rejects_unauthorized_company_or_invalid_context(): void
    {
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Second Company',
        ]);

        // Restore context to $this->company
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // Active company context mismatch rejects with 403
        $this->expectException(HttpException::class);
        $this->searchService->searchProductsQuery((int) $otherCompany->id, $this->owner, 'test', 'sales');
    }

    public function test_add_product_in_invoice_and_purchase_form_preserves_independent_lines_never_collapsing(): void
    {
        $product = $this->createProduct('قهوة بن برازيلي', 'Brazilian Coffee', 'COFFEE-01');
        $pu = ProductUnit::where('product_id', $product->id)->firstOrFail();
        $pu->update(['default_sale_price_base' => '20.000000', 'default_purchase_price_base' => '12.000000']);

        foreach ([[InvoiceForm::class, 'unit_price'], [PurchaseForm::class, 'unit_cost']] as [$component, $amount]) {
            $form = Livewire::test($component)
                ->set('productSearch', 'COFFEE-01')->call('addSelectedProduct')
                ->set('productSearch', 'COFFEE-01')->call('addSelectedProduct');
            $this->assertCount(2, $form->get('lines'));
            $form->set('lines.0.quantity', '3')->set('lines.0.'.$amount, '0.00')
                ->assertSet('lines.1.quantity', '1');
            $this->assertNotSame('0.00', $form->get('lines.1.'.$amount));
            $form->call('removeLine', 0)->assertSet('lines.0.product_id', (int) $product->id)
                ->assertSet('lines.0.quantity', '1');
            $this->assertCount(1, $form->get('lines'));
        }
    }

    public function test_10k_product_fixture_benchmark_and_explain_query_plan(): void
    {
        $companyId = (int) $this->company->id;
        $userId = (int) $this->owner->id;
        $baseUnitId = (int) $this->pieceUnit->id;
        $now = now()->toDateTimeString();

        // Prepare 10,000 products fixture via chunked bulk insert
        $batchSize = 2000;
        $total = 10000;
        $productsData = [];
        $barcodesData = [];

        for ($i = 1; $i <= $total; $i++) {
            $publicId = '01JFIX'.str_pad((string) $i, 20, '0', STR_PAD_LEFT);
            $sku = 'SKU-BENCH-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $nameAr = 'منتج تجريبي رقم '.$i;
            $nameEn = 'Benchmark Product '.$i;

            $productsData[] = [
                'public_id' => $publicId,
                'company_id' => $companyId,
                'sku' => $sku,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'product_type' => 'stock',
                'base_unit_id' => $baseUnitId,
                'track_stock' => 1,
                'track_expiry' => 0,
                'default_sale_price_base' => '25.000000',
                'default_purchase_cost_base' => '15.000000',
                'active' => 1,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($i % $batchSize === 0) {
                DB::table('products')->insert($productsData);
                $productsData = [];
            }
        }

        // Verify 10,000 inserted
        $count = DB::table('products')->where('company_id', $companyId)->count();
        $this->assertGreaterThanOrEqual(10000, $count);

        DB::statement('ANALYZE TABLE products');

        // Capture the statements actually executed by candidate resolution, hydration,
        // authorization and price/stock suggestions. Do not prescribe optimizer choices.
        $samples = [];
        $evidence = [];
        foreach (['SKU-BENCH-005', 'منتج تجريبي رقم 5', 'Benchmark Product 50'] as $term) {
            $durations = [];
            for ($iteration = 0; $iteration < 11; $iteration++) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $start = hrtime(true);
                $results = $this->searchService->search($companyId, $this->owner, $term, 'sales');
                $durations[] = (hrtime(true) - $start) / 1000000;
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $this->assertLessThanOrEqual(30, count($results));
            }
            $this->assertLessThan(30, count($queries), 'Full authorized search must not issue per-product queries.');
            sort($durations);
            $plans = [];
            foreach ($queries as $query) {
                if (! str_starts_with(strtolower(ltrim($query['query'])), 'select')) {
                    continue;
                }
                foreach (DB::select('EXPLAIN '.$query['query'], $query['bindings']) as $row) {
                    $row = (array) $row;
                    $plans[] = array_intersect_key($row, array_flip(['table', 'type', 'key', 'rows', 'Extra']));
                }
            }
            $evidence[] = ['term' => $term, 'query_count' => count($queries),
                'latency_ms' => ['min' => $durations[0], 'median' => $durations[5], 'p95' => $durations[10]],
                'statements' => array_values(array_unique(array_column($queries, 'query'))),
                'plans' => $plans];
        }
        fwrite(STDERR, "\nACTUAL_SEARCH_EVIDENCE ".json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");

    }

    private function createProduct(string $nameAr, string $nameEn, string $sku, string $type = Product::TYPE_STOCK, bool $trackStock = true): Product
    {
        return app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'sku' => $sku,
            'product_type' => $type,
            'track_stock' => $trackStock,
            'track_expiry' => false,
            'base_unit_id' => $this->pieceUnit->id,
            'default_sale_price_base' => '10.000000',
            'default_purchase_cost_base' => '6.000000',
        ], (int) $this->owner->id);
    }
}
