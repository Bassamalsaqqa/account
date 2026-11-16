<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Inventory\OpeningStockForm;
use App\Livewire\Pages\Inventory\StockMovementIndex;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Search\SearchableSelectorService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class SearchableComboboxAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected SearchableSelectorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);
        $this->company = $creator->execute($this->user, [
            'name_ar' => 'شركة فحص دقة البحث',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->actingAs($this->user);
        $this->service = app(SearchableSelectorService::class);
    }

    protected function getOrCreateUnit(
        string $code,
        string $nameAr = 'قطعة',
        ?string $nameEn = 'Piece',
        ?string $symbolAr = 'قطعة',
        ?string $symbolEn = 'pc'
    ): Unit {
        return Unit::firstOrCreate(
            [
                'company_id' => $this->company->id,
                'code' => $code,
            ],
            [
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'symbol_ar' => $symbolAr,
                'symbol_en' => $symbolEn,
                'allows_fraction' => false,
                'decimal_places' => 0,
                'active' => true,
            ]
        );
    }

    public function test_search_with_one_character_arabic_and_english(): void
    {
        Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'أحمد خليل',
            'name_en' => 'Ahmed Khalil',
            'active' => true,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'سالم محمد',
            'name_en' => 'Salem Mohamed',
            'active' => true,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        // Arabic single-char query 'أ'
        $responseAr = $this->service->search($this->company, $this->user, 'customer', 'أ', null, 25, ['context' => 'sales']);
        $this->assertNotEmpty($responseAr['items']);
        $this->assertTrue(collect($responseAr['items'])->contains(fn ($item) => str_contains($item['label'], 'أحمد')));

        // English single-char query 'S'
        $responseEn = $this->service->search($this->company, $this->user, 'customer', 'S', null, 25, ['context' => 'sales']);
        $this->assertNotEmpty($responseEn['items']);
        $this->assertTrue(collect($responseEn['items'])->contains(fn ($item) => str_contains($item['label'], 'سالم') || str_contains($item['label'], 'Salem')));
    }

    public function test_exact_code_and_sku_prioritization(): void
    {
        $baseUnit = $this->getOrCreateUnit('piece');

        Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج عادي يحتوي كود 100',
            'name_en' => 'Normal item with 100 in name',
            'sku' => 'OTHER-100',
            'base_unit_id' => $baseUnit->id,
            'product_type' => Product::TYPE_STOCK,
            'active' => true,
            'track_stock' => true,
            'created_by' => $this->user->id,
        ]);

        $exactProduct = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج ذو رمز مطابق',
            'name_en' => 'Exact SKU item',
            'sku' => '100',
            'base_unit_id' => $baseUnit->id,
            'product_type' => Product::TYPE_STOCK,
            'active' => true,
            'track_stock' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->service->search($this->company, $this->user, 'product', '100', null, 25, ['context' => 'sales']);

        $this->assertNotEmpty($response['items']);
        $this->assertSame((string) $exactProduct->id, $response['items'][0]['id']);
    }

    public function test_barcode_unit_mapping_resolution_and_null_unit_default(): void
    {
        $baseUnit = $this->getOrCreateUnit('piece', 'حبة', 'Piece', 'حبة', 'pc');
        $cartonUnit = $this->getOrCreateUnit('carton', 'كرتونة', 'Carton', 'كرتونة', 'ctn');

        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عصير برتقال طبيعي',
            'name_en' => 'Natural Orange Juice',
            'sku' => 'JUICE-01',
            'base_unit_id' => $baseUnit->id,
            'product_type' => Product::TYPE_STOCK,
            'active' => true,
            'track_stock' => true,
            'created_by' => $this->user->id,
        ]);

        $productUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $cartonUnit->id,
            'conversion_to_base' => '12.000000',
            'is_base' => false,
            'active' => true,
        ]);

        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'barcode' => '628100123456',
            'unit_id' => $cartonUnit->id,
        ]);

        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'barcode' => '628100999999',
            'unit_id' => null,
        ]);

        // Specific package unit barcode
        $cartonResponse = $this->service->search($this->company, $this->user, 'product', '628100123456', null, 25, ['context' => 'sales']);
        $matchCarton = collect($cartonResponse['items'])->firstWhere('id', (string) $product->id);
        $this->assertNotNull($matchCarton);
        $this->assertSame($productUnit->id, $matchCarton['meta']['matched_product_unit_id']);
        $this->assertSame($cartonUnit->id, $matchCarton['meta']['matched_unit_id']);

        // General barcode with null unit
        $nullUnitResponse = $this->service->search($this->company, $this->user, 'product', '628100999999', null, 25, ['context' => 'sales']);
        $matchNullUnit = collect($nullUnitResponse['items'])->firstWhere('id', (string) $product->id);
        $this->assertNotNull($matchNullUnit);
        $this->assertNull($matchNullUnit['meta']['matched_product_unit_id']);
        $this->assertNull($matchNullUnit['meta']['matched_unit_id']);
    }

    public function test_wildcard_escaping_for_percent_underscore_backslash(): void
    {
        Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'شركة 100% نجاح',
            'name_en' => '100% Success Corp',
            'active' => true,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل_شرطة_سفلية',
            'name_en' => 'Client_With_Underscore',
            'active' => true,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل عادي آخر',
            'name_en' => 'Another Normal Client',
            'active' => true,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        // Literal % search
        $percentResponse = $this->service->search($this->company, $this->user, 'customer', '%', null, 25, ['context' => 'sales']);
        $this->assertCount(1, $percentResponse['items']);
        $this->assertStringContainsString('100%', $percentResponse['items'][0]['label']);

        // Literal _ search
        $underscoreResponse = $this->service->search($this->company, $this->user, 'customer', '_', null, 25, ['context' => 'sales']);
        $this->assertCount(1, $underscoreResponse['items']);
        $this->assertStringContainsString('_', $underscoreResponse['items'][0]['label']);
    }

    public function test_tenant_isolation_boundary(): void
    {
        $creator = app(CreateCompanyAction::class);
        $otherUser = User::factory()->create();
        $context = app(CompanyContext::class);
        $context->clear();
        try {
            $otherCompany = $creator->execute($otherUser, [
                'name_ar' => 'شركة منافسة أخرى',
                'base_currency_code' => 'JOD',
            ]);
            $context->setCompany($otherCompany, $otherUser);

            Customer::create([
                'company_id' => $otherCompany->id,
                'name_ar' => 'عميل سري خاص بالشركة الأخرى',
                'name_en' => 'Secret Customer of Other Co',
                'active' => true,
                'status' => 'active',
                'created_by' => $otherUser->id,
            ]);
        } finally {
            $context->clear();
            $context->setCompany($this->company, $this->user);
        }

        $response = $this->service->search($this->company, $this->user, 'customer', 'سري', null, 25, ['context' => 'sales']);
        $this->assertEmpty($response['items']);
    }

    public function test_cost_price_redaction_for_unauthorized_users(): void
    {
        $baseUnit = $this->getOrCreateUnit('piece');

        Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'سلعة تجارية مكلفة',
            'name_en' => 'High Cost Item',
            'sku' => 'ITEM-EXP',
            'default_purchase_cost_base' => '150.00',
            'default_sale_price_base' => '250.00',
            'base_unit_id' => $baseUnit->id,
            'product_type' => Product::TYPE_STOCK,
            'active' => true,
            'track_stock' => true,
            'created_by' => $this->user->id,
        ]);

        // In sales context, cost MUST be redacted regardless of user roles
        $response = $this->service->search($this->company, $this->user, 'product', 'مكلفة', null, 25, ['context' => 'sales']);
        $this->assertNotEmpty($response['items']);
        $this->assertArrayNotHasKey('default_purchase_cost_base', $response['items'][0]['meta']);
        $this->assertSame('250.000000', $response['items'][0]['meta']['default_sale_price_base']);
    }

    public function test_selected_record_hydration_retains_selection_outside_first_page(): void
    {
        $selected = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'ياقوت زاهر عميل قديم',
            'name_en' => 'Yaqoot Zaher Old Client',
            'active' => true,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        for ($i = 1; $i <= 35; $i++) {
            Customer::create([
                'company_id' => $this->company->id,
                'name_ar' => sprintf('ألف باء تاء عميل %02d', $i),
                'name_en' => sprintf('Abc Client %02d', $i),
                'active' => true,
                'status' => 'active',
                'created_by' => $this->user->id,
            ]);
        }

        $response = $this->service->search($this->company, $this->user, 'customer', '', $selected->id, 25, ['context' => 'sales']);

        $this->assertNotEmpty($response['items']);
        $this->assertLessThanOrEqual(25, count($response['items']), 'Items stream must not exceed limit 25.');
        $this->assertNotNull($response['selected_item'], 'The selected record must be hydrated in selected_item without corrupting the items stream.');
        $this->assertSame((string) $selected->id, (string) $response['selected_item']['id']);
    }

    public function test_payment_vendor_search_finds_soft_deleted_vendor_with_posted_purchases(): void
    {
        $deletedVendorWithPurchase = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد محذوف لديه فاتورة',
            'name_en' => 'Deleted Vendor with Bill',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $deletedVendorWithPurchase->delete();

        $warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();

        DB::table('purchases')->insert([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'purchase_number' => 'PUR-001',
            'vendor_id' => $deletedVendorWithPurchase->id,
            'warehouse_id' => $warehouse->id,
            'status' => Purchase::STATUS_POSTED,
            'purchase_date' => '2026-10-03',
            'currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'subtotal_currency' => '500.000000',
            'subtotal_base' => '500.000000',
            'discount_total_currency' => '0.000000',
            'discount_total_base' => '0.000000',
            'tax_total_currency' => '0.000000',
            'tax_total_base' => '0.000000',
            'grand_total_currency' => '500.000000',
            'grand_total_base' => '500.000000',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deletedVendorNoPurchase = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد محذوف بدون فواتير',
            'name_en' => 'Deleted Vendor without Bill',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $deletedVendorNoPurchase->delete();

        // In payment context, vendor with posted purchases MUST be found
        $paymentResponse = $this->service->search(
            $this->company,
            $this->user,
            'vendor',
            'محذوف',
            null,
            25,
            ['context' => 'payment']
        );

        $ids = collect($paymentResponse['items'])->pluck('id')->all();
        $this->assertContains((string) $deletedVendorWithPurchase->id, $ids);
        $this->assertNotContains((string) $deletedVendorNoPurchase->id, $ids);
    }

    public function test_public_livewire_call_forged_options_and_unauthorized_type_rejected(): void
    {
        $invoiceComponent = Livewire::test(InvoiceForm::class);

        // Attempting to query 'vendor' on InvoiceForm must be rejected with 403
        $invoiceComponent->call('searchSelector', 'vendor', 'test', null, [
            'capability' => 'sales.invoice.create', // Forged authority attempt
        ])->assertForbidden();
    }

    public function test_livewire_invoice_and_quotation_clear_product_preserves_draft_state(): void
    {
        $baseUnit = $this->getOrCreateUnit('piece');

        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج تجريبي للتفريغ',
            'name_en' => 'Clear Test Product',
            'sku' => 'CLR-01',
            'default_sale_price_base' => '50.000000',
            'base_unit_id' => $baseUnit->id,
            'product_type' => Product::TYPE_STOCK,
            'active' => true,
            'track_stock' => true,
            'created_by' => $this->user->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $baseUnit->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'default_sale_price_base' => '50.000000',
            'active' => true,
        ]);

        // InvoiceForm test
        $invoice = Livewire::test(InvoiceForm::class)
            ->call('selectProduct', 0, $product->id);

        $lines = $invoice->get('lines');
        $this->assertSame($product->id, $lines[0]['product_id']);

        // Explicit null clear
        $invoice->call('selectProduct', 0, null);
        $clearedLines = $invoice->get('lines');
        $this->assertNull($clearedLines[0]['product_id']);
        $this->assertSame('0', $clearedLines[0]['unit_price']);

        // QuotationForm test
        $quote = Livewire::test(QuotationForm::class)
            ->call('selectProduct', 0, $product->id);

        $quoteLines = $quote->get('lines');
        $this->assertSame($product->id, $quoteLines[0]['product_id']);

        $quote->call('selectProduct', 0, null);
        $clearedQuoteLines = $quote->get('lines');
        $this->assertNull($clearedQuoteLines[0]['product_id']);
    }

    public function test_inventory_select_product_with_barcode_unit_and_null_unit(): void
    {
        $baseUnit = $this->getOrCreateUnit('piece', 'حبة', 'Piece', 'حبة', 'pc');
        $cartonUnit = $this->getOrCreateUnit('carton', 'كرتونة', 'Carton', 'كرتونة', 'ctn');

        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'سلعة جردية',
            'name_en' => 'Inventory Product',
            'sku' => 'INV-01',
            'base_unit_id' => $baseUnit->id,
            'active' => true,
            'track_stock' => true,
            'product_type' => Product::TYPE_STOCK,
            'created_by' => $this->user->id,
        ]);

        $productUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $cartonUnit->id,
            'conversion_to_base' => '10.000000',
            'is_base' => false,
            'active' => true,
        ]);

        $component = Livewire::test(OpeningStockForm::class);

        // Select product with specific package unit (e.g. from carton barcode)
        $component->call('selectProduct', $product->id, $productUnit->id);
        $this->assertSame($product->id, $component->get('product_id'));
        $this->assertSame($cartonUnit->id, $component->get('unit_id'));

        // Select product with null unit (defaults to base unit)
        $component->call('selectProduct', $product->id, null);
        $this->assertSame($baseUnit->id, $component->get('unit_id'));

        // Clear selection
        $component->call('selectProduct', null, null);
        $this->assertNull($component->get('product_id'));
        $this->assertNull($component->get('unit_id'));
    }

    public function test_stock_movement_index_clear_filter_accepts_null(): void
    {
        $component = Livewire::test(StockMovementIndex::class)
            ->set('productFilter', '5');

        $this->assertSame('5', $component->get('productFilter'));

        // Null clearing must not throw TypeError
        $component->set('productFilter', null);
        $this->assertNull($component->get('productFilter'));
    }

    public function test_vendor_payment_search_authorizes_without_permission_does_not_exist_error(): void
    {
        Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد سداد',
            'name_en' => 'Payment Vendor',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        // User as company creator / owner has permissions; must not throw Spatie\Permission\Exceptions\PermissionDoesNotExist
        $response = $this->service->search(
            $this->company,
            $this->user,
            'vendor',
            'سداد',
            null,
            25,
            ['context' => 'payment']
        );

        $this->assertNotEmpty($response['items']);
        $this->assertSame('مورد سداد (ILS)', $response['items'][0]['label']);
    }

    public function test_quotation_least_privilege_search(): void
    {
        $baseUnit = $this->getOrCreateUnit('piece');

        Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل عرض سعر',
            'name_en' => 'Quotation Customer',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'صنف عرض سعر',
            'name_en' => 'Quotation Product',
            'sku' => 'Q-001',
            'base_unit_id' => $baseUnit->id,
            'active' => true,
            'track_stock' => true,
            'product_type' => Product::TYPE_STOCK,
            'created_by' => $this->user->id,
        ]);

        // Quotation context search must succeed for quotation workflow
        $custResponse = $this->service->search(
            $this->company,
            $this->user,
            'customer',
            'عرض سعر',
            null,
            25,
            ['context' => 'quotation']
        );
        $this->assertNotEmpty($custResponse['items']);

        $prodResponse = $this->service->search(
            $this->company,
            $this->user,
            'product',
            'عرض سعر',
            null,
            25,
            ['context' => 'quotation']
        );
        $this->assertNotEmpty($prodResponse['items']);
    }

    public function test_bounded_pagination_and_cross_query_cursor_rejection(): void
    {
        $baseUnit = $this->getOrCreateUnit('piece');

        // Seed 60 products matching term across 3 pages (25 + 25 + 10)
        $records = [];
        for ($i = 1; $i <= 60; $i++) {
            $records[] = [
                'public_id' => (string) Str::ulid(),
                'company_id' => $this->company->id,
                'name_ar' => sprintf('صنف ترقيم صفحات %02d', $i),
                'name_en' => sprintf('Pagination Item %02d', $i),
                'sku' => sprintf('PAGE-%03d', $i),
                'base_unit_id' => $baseUnit->id,
                'active' => true,
                'track_stock' => true,
                'product_type' => Product::TYPE_STOCK,
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Product::insert($records);

        // Page 1: limit 25
        $page1 = $this->service->search(
            $this->company,
            $this->user,
            'product',
            'ترقيم',
            null,
            25,
            ['context' => 'sales']
        );

        $this->assertCount(25, $page1['items']);
        $this->assertTrue($page1['has_more']);
        $this->assertNotNull($page1['next_cursor']);

        // Page 2: with next_cursor
        $page2 = $this->service->search(
            $this->company,
            $this->user,
            'product',
            'ترقيم',
            null,
            25,
            ['context' => 'sales', 'cursor' => $page1['next_cursor']]
        );

        $this->assertCount(25, $page2['items']);
        $this->assertTrue($page2['has_more']);
        $this->assertNotNull($page2['next_cursor']);

        // Page 3: with page 2 next_cursor
        $page3 = $this->service->search(
            $this->company,
            $this->user,
            'product',
            'ترقيم',
            null,
            25,
            ['context' => 'sales', 'cursor' => $page2['next_cursor']]
        );

        $this->assertCount(10, $page3['items']);
        $this->assertFalse($page3['has_more']);
        $this->assertNull($page3['next_cursor']);

        // Ensure full stream deduplication and no omission across 3 pages
        $page1Ids = array_column($page1['items'], 'id');
        $page2Ids = array_column($page2['items'], 'id');
        $page3Ids = array_column($page3['items'], 'id');

        $this->assertEmpty(array_intersect($page1Ids, $page2Ids), 'Page 1 and Page 2 must not overlap.');
        $this->assertEmpty(array_intersect($page2Ids, $page3Ids), 'Page 2 and Page 3 must not overlap.');
        $this->assertEmpty(array_intersect($page1Ids, $page3Ids), 'Page 1 and Page 3 must not overlap.');

        $allIds = array_merge($page1Ids, $page2Ids, $page3Ids);
        $this->assertCount(60, array_unique($allIds), '3-page paging must return all 60 records without omission.');

        // Cursor Security Validation 1: Cross-query cursor reuse rejected
        try {
            $this->service->search(
                $this->company,
                $this->user,
                'product',
                'استعلام_مختلف',
                null,
                25,
                ['context' => 'sales', 'cursor' => $page1['next_cursor']]
            );
            $this->fail('Expected InvalidArgumentException for cross-query cursor reuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Cross-query pagination cursor reuse is rejected.', $e->getMessage());
        }

        // Cursor Security Validation 2: Cross-type cursor reuse rejected
        try {
            $this->service->search(
                $this->company,
                $this->user,
                'customer',
                'ترقيم',
                null,
                25,
                ['context' => 'sales', 'cursor' => $page1['next_cursor']]
            );
            $this->fail('Expected InvalidArgumentException for cross-type cursor reuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Cross-type pagination cursor reuse is rejected.', $e->getMessage());
        }

        // Cursor Security Validation 3: Tampered offset payload rejected
        $cursorToken = $page1['next_cursor'];
        $decoded = base64_decode(strtr($cursorToken, '-_', '+/'), true);
        $lastDot = strrpos($decoded, '.');
        $json = substr($decoded, 0, $lastDot);
        $sig = substr($decoded, $lastDot + 1);
        $tamperedPayload = json_decode($json, true);
        $tamperedPayload['o'] = 999;
        $tamperedJson = json_encode($tamperedPayload);
        $tamperedCursor = rtrim(strtr(base64_encode($tamperedJson . '.' . $sig), '+/', '-_'), '=');

        try {
            $this->service->search(
                $this->company,
                $this->user,
                'product',
                'ترقيم',
                null,
                25,
                ['context' => 'sales', 'cursor' => $tamperedCursor]
            );
            $this->fail('Expected InvalidArgumentException for tampered offset in cursor.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Tampered pagination cursor signature.', $e->getMessage());
        }

        // Cursor Security Validation 4: Malformed cursor format rejected
        try {
            $this->service->search(
                $this->company,
                $this->user,
                'product',
                'ترقيم',
                null,
                25,
                ['context' => 'sales', 'cursor' => 'not-a-valid-token']
            );
            $this->fail('Expected InvalidArgumentException for malformed cursor.');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(in_array($e->getMessage(), [
                'Missing cursor integrity signature.',
                'Malformed pagination cursor encoding.',
            ], true), 'Exception message must indicate missing signature or malformed encoding.');
        }
    }
}
