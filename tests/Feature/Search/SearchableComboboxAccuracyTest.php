<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Search\SearchableSelectorService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $resultsAr = $this->service->search('customer', 'أ', $this->company->id, null, 'sales');
        $this->assertNotEmpty($resultsAr);
        $this->assertTrue(collect($resultsAr)->contains(fn ($item) => str_contains($item->label, 'أحمد')));

        // English single-char query 'S'
        $resultsEn = $this->service->search('customer', 'S', $this->company->id, null, 'sales');
        $this->assertNotEmpty($resultsEn);
        $this->assertTrue(collect($resultsEn)->contains(fn ($item) => str_contains($item->label, 'سالم') || str_contains($item->label, 'Salem')));
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

        $results = $this->service->search('product', '100', $this->company->id, null, 'sales');

        $this->assertNotEmpty($results);
        // The first result MUST be the exact SKU match
        $this->assertSame((string) $exactProduct->id, $results[0]->id);
    }

    public function test_barcode_unit_mapping_resolution(): void
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

        $results = $this->service->search('product', '628100123456', $this->company->id, null, 'sales');

        $this->assertNotEmpty($results);
        $match = collect($results)->firstWhere('id', (string) $product->id);
        $this->assertNotNull($match);
        // Unit mapping must correctly identify the carton unit rather than base unit or null
        $this->assertSame($productUnit->id, $match->matched_product_unit_id);
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

        // Literal % search: should ONLY return the 100% record, not all records
        $percentResults = $this->service->search('customer', '%', $this->company->id, null, 'sales');
        $this->assertCount(1, $percentResults);
        $this->assertStringContainsString('100%', $percentResults[0]->label);

        // Literal _ search: should ONLY return the underscore record, not single char wildcard matching all
        $underscoreResults = $this->service->search('customer', '_', $this->company->id, null, 'sales');
        $this->assertCount(1, $underscoreResults);
        $this->assertStringContainsString('_', $underscoreResults[0]->label);
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

        $results = $this->service->search('customer', 'سري', $this->company->id, null, 'sales');
        $this->assertEmpty($results);
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
            'cost_price' => '150.00',
            'sale_price' => '250.00',
            'base_unit_id' => $baseUnit->id,
            'active' => true,
            'track_stock' => true,
            'created_by' => $this->user->id,
        ]);

        // In sales context without cost permission, cost_price MUST be null
        $results = $this->service->search('product', 'مكلفة', $this->company->id, null, 'sales');
        $this->assertNotEmpty($results);
        $this->assertNull($results[0]->cost_price);
        $this->assertSame('250.00', $results[0]->sale_price);
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

        // Create 35 customers that alphabetically precede Yaqoot
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

        // Search empty query but with selectedId
        $results = $this->service->search('customer', '', $this->company->id, $selected->id, 'sales');

        $this->assertNotEmpty($results);
        $containsSelected = collect($results)->contains(fn ($item) => (int) $item->id === $selected->id);
        $this->assertTrue($containsSelected, 'The selected record must be hydrated even when past first page limit.');
    }
}
