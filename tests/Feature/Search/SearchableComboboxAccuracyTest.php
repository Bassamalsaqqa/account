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
use App\Services\Search\SearchableSelectorService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $baseUnit = Unit::create([
            'company_id' => $this->company->id,
            'name_ar' => 'قطعة',
            'name_en' => 'Piece',
            'symbol_ar' => 'قطعة',
            'symbol_en' => 'pc',
        ]);

        Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج عادي يحتوي كود 100',
            'name_en' => 'Normal item with 100 in name',
            'sku' => 'OTHER-100',
            'base_unit_id' => $baseUnit->id,
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
        $baseUnit = Unit::create([
            'company_id' => $this->company->id,
            'name_ar' => 'حبة',
            'name_en' => 'Piece',
            'symbol_ar' => 'حبة',
            'symbol_en' => 'pc',
        ]);

        $cartonUnit = Unit::create([
            'company_id' => $this->company->id,
            'name_ar' => 'كرتونة',
            'name_en' => 'Carton',
            'symbol_ar' => 'كرتونة',
            'symbol_en' => 'ctn',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عصير برتقال طبيعي',
            'name_en' => 'Natural Orange Juice',
            'sku' => 'JUICE-01',
            'base_unit_id' => $baseUnit->id,
            'active' => true,
            'track_stock' => true,
            'created_by' => $this->user->id,
        ]);

        $productUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $cartonUnit->id,
            'conversion_factor' => '12.0000',
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
        $otherCompany = $creator->execute($otherUser, [
            'name_ar' => 'شركة منافسة أخرى',
            'base_currency_code' => 'JOD',
        ]);

        Customer::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'عميل سري خاص بالشركة الأخرى',
            'name_en' => 'Secret Customer of Other Co',
            'active' => true,
            'status' => 'active',
            'created_by' => $otherUser->id,
        ]);

        $response = $this->service->search($this->company, $this->user, 'customer', 'سري', null, 25, ['context' => 'sales']);
        $this->assertEmpty($response['items']);
    }

    public function test_cost_price_redaction_for_unauthorized_users(): void
    {
        $baseUnit = Unit::create([
            'company_id' => $this->company->id,
            'name_ar' => 'قطعة',
            'name_en' => 'Piece',
            'symbol_ar' => 'قطعة',
            'symbol_en' => 'pc',
        ]);

        Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'سلعة تجارية مكلفة',
            'name_en' => 'High Cost Item',
            'sku' => 'ITEM-EXP',
            'default_purchase_cost_base' => '150.00',
            'default_sale_price_base' => '250.00',
            'base_unit_id' => $baseUnit->id,
            'active' => true,
            'track_stock' => true,
            'created_by' => $this->user->id,
        ]);

        // In sales context, cost MUST be redacted regardless of user roles
        $response = $this->service->search($this->company, $this->user, 'product', 'مكلفة', null, 25, ['context' => 'sales']);
        $this->assertNotEmpty($response['items']);
        $this->assertArrayNotHasKey('default_purchase_cost_base', $response['items'][0]['meta']);
        $this->assertSame('250.00', $response['items'][0]['meta']['default_sale_price_base']);
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
        $containsSelected = collect($response['items'])->contains(fn ($item) => (int) $item['id'] === $selected->id);
        $this->assertTrue($containsSelected, 'The selected record must be hydrated even when past first page limit.');
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

        Purchase::create([
            'company_id' => $this->company->id,
            'vendor_id' => $deletedVendorWithPurchase->id,
            'purchase_number' => 'PUR-001',
            'status' => Purchase::STATUS_POSTED,
            'currency_code' => 'ILS',
            'total_amount' => '500.00',
            'created_by' => $this->user->id,
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
        $this->expectException(AuthorizationException::class);
        $invoiceComponent->call('searchSelector', 'vendor', 'test', null, [
            'capability' => 'sales.invoice.create', // Forged authority attempt
        ]);
    }

    public function test_livewire_invoice_and_quotation_clear_product_preserves_draft_state(): void
    {
        $baseUnit = Unit::create([
            'company_id' => $this->company->id,
            'name_ar' => 'قطعة',
            'name_en' => 'Piece',
            'symbol_ar' => 'قطعة',
            'symbol_en' => 'pc',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج تجريبي للتفريغ',
            'name_en' => 'Clear Test Product',
            'sku' => 'CLR-01',
            'default_sale_price_base' => '50.00',
            'base_unit_id' => $baseUnit->id,
            'active' => true,
            'track_stock' => true,
            'created_by' => $this->user->id,
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
        $baseUnit = Unit::create([
            'company_id' => $this->company->id,
            'name_ar' => 'حبة',
            'name_en' => 'Piece',
            'symbol_ar' => 'حبة',
            'symbol_en' => 'pc',
        ]);

        $cartonUnit = Unit::create([
            'company_id' => $this->company->id,
            'name_ar' => 'كرتونة',
            'name_en' => 'Carton',
            'symbol_ar' => 'كرتونة',
            'symbol_en' => 'ctn',
        ]);

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
            'conversion_factor' => '10.0000',
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
}
