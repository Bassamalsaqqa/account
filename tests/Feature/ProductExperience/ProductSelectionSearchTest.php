<?php

declare(strict_types=1);

namespace Tests\Feature\ProductExperience;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class ProductSelectionSearchTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    private Product $soap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
        $this->soap = $this->product('SOAP-LOOKUP', 'صابون فلسطيني', 'Palestinian soap');
        ProductBarcode::create(['company_id' => $this->company->id, 'product_id' => $this->soap->id,
            'barcode' => 'LOOKUP-123', 'type' => 'code128', 'is_primary' => true]);
    }

    public function test_sales_and_purchase_search_names_sku_and_exact_barcode_without_writes(): void
    {
        $before = $this->economicCounts();
        foreach ([InvoiceForm::class, PurchaseForm::class] as $component) {
            $form = Livewire::test($component);
            foreach (['صابون', 'Palestinian', 'SOAP-LOOK', 'LOOKUP-123'] as $term) {
                $form->set('productSearch', $term)->assertViewHas('products', fn ($products) => $products->modelKeys() === [$this->soap->id]);
            }
            $form->set('productSearch', 'does-not-exist')->assertViewHas('products', fn ($products) => $products->isEmpty());
        }
        $this->assertSame($before, $this->economicCounts());
    }

    public function test_customer_currency_fallback_renders_in_arabic_and_english_without_writes(): void
    {
        $this->customer->update(['default_currency_code' => null]);
        $before = $this->economicCounts();
        foreach (['ar', 'en'] as $locale) {
            $this->owner->update(['locale' => $locale]);
            session(['locale' => $locale]);
            app()->setLocale($locale);
            Livewire::test(InvoiceForm::class)
                ->assertViewHas('baseCurrencyCode', $this->company->base_currency_code)
                ->assertSee($this->customer->displayName().' ('.$this->company->base_currency_code.')');
        }
        $this->assertSame($before, $this->economicCounts());
    }

    public function test_selected_product_units_and_user_amounts_survive_search_changes(): void
    {
        $before = $this->economicCounts();
        foreach ([[InvoiceForm::class, 'unit_price'], [PurchaseForm::class, 'unit_cost']] as [$component, $amountField]) {
            $form = Livewire::test($component)->call('selectProduct', 0, $this->soap->id)
                ->set('lines.0.quantity', '3')->set('lines.0.'.$amountField, '7.50');
            $unitId = $form->get('lines.0.product_unit_id');
            $form->set('productSearch', 'different-product')->assertSet('lines.0.product_id', $this->soap->id)
                ->assertSet('lines.0.product_unit_id', $unitId)->assertSet('lines.0.quantity', '3')
                ->assertSet('lines.0.'.$amountField, '7.50');
            if ($component === InvoiceForm::class) {
                $form->assertViewHas('products', fn ($products) => $products->firstWhere('id', $this->soap->id)?->productUnits->isNotEmpty() === true);
            } else {
                $form->assertViewHas('unitOptions', fn ($units) => $units[0]->contains('id', $unitId));
            }
        }
        $this->assertSame($before, $this->economicCounts());
    }

    public function test_catalog_options_are_bounded_but_selected_sales_products_remain_available(): void
    {
        for ($index = 1; $index <= 55; $index++) {
            $this->product('BULK-'.$index, sprintf('صنف %03d', $index), 'Item '.$index);
        }
        foreach ([InvoiceForm::class, PurchaseForm::class] as $component) {
            Livewire::test($component)->assertViewHas('products', fn ($products) => $products->count() === 50);
        }
        Livewire::test(InvoiceForm::class)->call('selectProduct', 0, $this->soap->id)
            ->assertViewHas('products', fn ($products) => $products->contains('id', $this->soap->id));
    }

    public function test_foreign_and_inactive_products_are_not_search_results(): void
    {
        $inactive = $this->product('INACTIVE', 'صنف غير نشط', 'Inactive item');
        $inactive->update(['active' => false]);
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة أخرى', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($other, $this->owner);
        $foreignUnit = Unit::where('company_id', $other->id)->where('code', 'piece')->firstOrFail();
        Product::create(['company_id' => $other->id, 'sku' => 'FOREIGN-SECRET', 'name_ar' => 'صنف خاص',
            'name_en' => 'Foreign secret item', 'base_unit_id' => $foreignUnit->id, 'product_type' => Product::TYPE_STOCK,
            'track_stock' => true, 'active' => true, 'created_by' => $this->owner->id]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        session(['active_company_id' => $this->company->id]);
        foreach ([InvoiceForm::class, PurchaseForm::class] as $component) {
            foreach (['FOREIGN-SECRET', 'INACTIVE'] as $term) {
                Livewire::test($component)->set('productSearch', $term)
                    ->assertViewHas('products', fn ($products) => $products->isEmpty());
            }
        }
    }

    public function test_role_downgrade_prevents_further_search_in_either_form(): void
    {
        $sales = Livewire::test(InvoiceForm::class);
        $purchase = Livewire::test(PurchaseForm::class);
        $this->owner->syncRoles('Viewer');
        $sales->set('productSearch', 'soap')->assertForbidden();
        $purchase->set('productSearch', 'soap')->assertForbidden();
    }

    public function test_company_switch_prevents_stale_sales_form_search(): void
    {
        $sales = Livewire::test(InvoiceForm::class);
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة أخرى', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($other, $this->owner);
        session(['active_company_id' => $other->id]);
        $sales->set('productSearch', 'soap')->assertForbidden();
    }

    public function test_repeated_purchase_lines_load_product_units_once_without_economic_changes(): void
    {
        $form = Livewire::test(PurchaseForm::class)->call('selectProduct', 0, $this->soap->id);
        $instance = $form->instance();
        $before = $this->economicCounts();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $instance->render();
        $single = $this->productReads();
        $instance->lines = array_fill(0, 12, $instance->lines[0]);
        DB::flushQueryLog();
        $instance->render();
        $twelve = $this->productReads();
        DB::disableQueryLog();
        $this->assertSame($single, $twelve, 'Product/unit reads must not grow with repeated lines.');
        $this->assertSame(4, $twelve);
        $this->assertSame($before, $this->economicCounts());
    }

    private function product(string $sku, string $arabic, string $english): Product
    {
        $unit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $product = Product::create(['company_id' => $this->company->id, 'sku' => $sku, 'name_ar' => $arabic,
            'name_en' => $english, 'base_unit_id' => $unit->id, 'product_type' => Product::TYPE_STOCK,
            'track_stock' => true, 'active' => true, 'created_by' => $this->owner->id]);
        ProductUnit::create(['company_id' => $this->company->id, 'product_id' => $product->id, 'unit_id' => $unit->id,
            'conversion_to_base' => '1', 'is_base' => true, 'is_default_sale' => true, 'is_default_purchase' => true, 'active' => true]);

        return $product;
    }

    /** @return array<string, int> */
    private function economicCounts(): array
    {
        return collect(['sales_invoices', 'purchases', 'posting_batches', 'posting_lines', 'stock_movements'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    }

    private function productReads(): int
    {
        return count(array_filter(DB::getQueryLog(), fn ($query) => preg_match('/from `(products|product_units|units)`/', $query['query']) === 1));
    }
}
