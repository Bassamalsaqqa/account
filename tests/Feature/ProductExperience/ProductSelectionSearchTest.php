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
use App\Services\Purchasing\VendorCatalogService;
use App\Support\DocumentIdentity;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

    public function test_non_base_zero_duplicate_edit_remove_and_save_preserves_exact_intent(): void
    {
        $box = Unit::create(['company_id' => $this->company->id, 'code' => 'test-box', 'name_ar' => 'صندوق', 'name_en' => 'Box', 'allows_fraction' => false, 'decimal_places' => 0, 'active' => true]);
        $unit = ProductUnit::create(['company_id' => $this->company->id, 'product_id' => $this->soap->id, 'unit_id' => $box->id,
            'conversion_to_base' => '12', 'active' => true]);
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'مورد', 'name_en' => 'Vendor']);
        foreach ([[InvoiceForm::class, 'unit_price', 'saveDraft', 'sales_invoices'], [PurchaseForm::class, 'unit_cost', 'saveDraft', 'purchases']] as [$component, $amount, $save, $table]) {
            $form = Livewire::test($component)->call('selectProduct', 0, $this->soap->id);
            if ($component === InvoiceForm::class) {
                $form->set('customer_id', $this->customer->id);
            } else {
                $form->set('vendor_id', $vendor->id);
            }
            $form->call('changeUnit', 0, $unit->id)->set('lines.0.'.$amount, '0.00')
                ->call('addLine')->call('selectProduct', 1, $this->soap->id)
                ->set('lines.1.'.$amount, '7.50')->set('lines.1.quantity', '3');
            $key = $form->get('lines.0.row_key');
            $form->call('addLine')->call('removeLine', 2)->call('recalculate')
                ->assertSet('lines.0.row_key', $key)->assertSet('lines.0.product_unit_id', $unit->id)
                ->assertSet('lines.0.'.$amount, '0.00')->assertSet('lines.1.'.$amount, '7.50')
                ->call($save)->assertHasNoErrors();
            $documentId = DB::table($table)->where('company_id', $this->company->id)->latest('id')->value('id');
            $this->assertNotNull($documentId);
            $lineTable = $component === InvoiceForm::class ? 'sales_invoice_lines' : 'purchase_lines';
            $foreignKey = $component === InvoiceForm::class ? 'sales_invoice_id' : 'purchase_id';
            $saved = DB::table($lineTable)->where($foreignKey, $documentId)->orderBy('id')->get();
            $this->assertCount(2, $saved);
            $this->assertSame($unit->id, (int) $saved[0]->product_unit_id);
            $this->assertTrue(BigDecimal::of($saved[0]->{$amount})->isZero());
            $this->assertTrue(BigDecimal::of($saved[1]->{$amount})->isEqualTo('7.50'));
            $this->assertTrue(BigDecimal::of($saved[1]->quantity)->isEqualTo('3'));
            // Reopen the saved draft and enter canonical review without executing it.
            $publicId = DB::table($table)->where('id', $documentId)->value('public_id');
            $review = Livewire::test($component, ['publicId' => $publicId]);
            $review->call('save', true);
            $review->assertHasNoErrors()->assertSet('showPreview', true);
            $this->assertNull($review->get('previewError'));
            $this->assertSame($unit->id, $review->get('lines.0.product_unit_id'));
            $this->assertTrue(BigDecimal::of($review->get('lines.0.'.$amount))->isZero());
        }
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_search_result_payload_cannot_be_replaced_by_client(): void
    {
        foreach ([InvoiceForm::class, PurchaseForm::class] as $component) {
            $form = Livewire::test($component);
            try {
                $form->set('searchResults', [['id' => $this->soap->id, 'cost' => '987654.32']]);
                $this->fail('Client must not replace authorized search results.');
            } catch (CannotUpdateLockedPropertyException $exception) {
                $this->assertStringContainsString('searchResults', $exception->getMessage());
            }
        }
    }

    public function test_cost_revocation_redacts_existing_purchase_search_on_refresh(): void
    {
        $this->soap->update(['default_purchase_cost_base' => '987654.32']);
        $form = Livewire::test(PurchaseForm::class)->set('productSearch', 'SOAP-LOOKUP');
        $this->assertSame('987654.32', $form->get('searchResults')[0]['cost']);
        foreach ($this->owner->roles as $role) {
            $role->revokePermissionTo('purchasing.cost.view');
        }
        $this->owner->revokePermissionTo('purchasing.cost.view');
        // Purchasing forms require cost access; revocation denies the entire response.
        $form->call('$refresh')->assertForbidden()->assertDontSee('987654.32');
    }

    public function test_identical_duplicate_rows_keep_distinct_identity_after_removal(): void
    {
        foreach ([InvoiceForm::class, PurchaseForm::class] as $component) {
            $form = Livewire::test($component)->call('addLine')->call('addLine');
            $lines = $form->get('lines');
            $this->assertNotSame($lines[0]['row_key'], $lines[1]['row_key']);
            $identity = [];
            foreach (DocumentIdentity::FIELDS as $field) {
                $identity[$field] = $lines[0][$field] ?? null;
            }
            $survivor = $lines[1]['row_key'];
            $form->call('removeLine', 0);
            $this->assertSame($survivor, $form->get('lines')[0]['row_key']);
            $form->call('batchStepQuantity', 0, '1', $identity, ['up'])
                ->assertHasErrors('lines.0.quantity');
            $this->assertSame('1', $form->get('lines')[0]['quantity']);
        }
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
            Livewire::test($component)->assertViewHas('products', fn ($products) => $products->isEmpty());
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
        $this->assertLessThanOrEqual(4, $twelve);
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
