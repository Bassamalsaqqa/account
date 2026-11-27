<?php

declare(strict_types=1);

namespace Tests\Feature\MobileRelease;

use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Services\Inventory\ProductCatalogService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class DocumentTouchControlsTest extends Phase5ETestCase
{
    public function test_exact_unit_steps_preserve_prices_and_no_financial_or_stock_effects(): void
    {
        $before = $this->economicCounts();
        $unit = Unit::findOrFail($this->unit->unit_id);
        $unit->update(['allows_fraction' => true, 'decimal_places' => 2]);
        foreach ([InvoiceForm::class, QuotationForm::class, PurchaseForm::class] as $type) {
            $component = Livewire::test($type)->call('selectProduct', 0, $this->product->id)
                ->set('lines.0.quantity', '1.25');
            $price = $component->get('lines.0');
            $component->call('incrementQuantity', 0)->assertSet('lines.0.quantity', '1.26')
                ->call('decrementQuantity', 0)->assertSet('lines.0.quantity', '1.25');
            $after = $component->get('lines.0');
            $field = $type === PurchaseForm::class ? 'unit_cost' : 'unit_price';
            $this->assertSame($price[$field], $after[$field]);
            $component->set('lines.0.quantity', '0.01')->call('decrementQuantity', 0)
                ->assertSet('lines.0.quantity', '0.01')->assertHasErrors('lines.0.quantity');
        }
        $this->assertSame($before, $this->economicCounts());
    }

    public function test_whole_unit_database_scale_and_inactive_or_mismatched_unit_are_safe(): void
    {
        Unit::findOrFail($this->unit->unit_id)->update(['allows_fraction' => false, 'decimal_places' => 0]);
        foreach ([InvoiceForm::class, QuotationForm::class, PurchaseForm::class] as $type) {
            $form = Livewire::test($type)->call('selectProduct', 0, $this->product->id)
                ->set('lines.0.quantity', '2.000000')->call('incrementQuantity', 0)
                ->assertSet('lines.0.quantity', '3');
            $form->set('lines.0.quantity', '1.5')->call('incrementQuantity', 0)
                ->assertSet('lines.0.quantity', '1.5')->assertHasErrors('lines.0.quantity');
            $form->set('lines.0.product_unit_id', 999999)->set('lines.0.quantity', '2')
                ->call('incrementQuantity', 0)->assertSet('lines.0.quantity', '2')->assertHasErrors('lines.0.quantity');
        }
    }

    public function test_camera_lookup_respects_barcode_unit_and_reports_quantity_refusal(): void
    {
        ProductBarcode::create(['company_id' => $this->company->id, 'product_id' => $this->product->id,
            'unit_id' => $this->unit->unit_id, 'barcode' => 'MOBILE-EXACT', 'type' => 'code128', 'is_primary' => true]);
        foreach ([InvoiceForm::class, QuotationForm::class, PurchaseForm::class] as $type) {
            $form = Livewire::test($type)->call('scanCameraBarcode', 'MOBILE-EXACT')
                ->assertReturned(['ok' => true, 'message' => __('camera.scan_success')])
                ->assertSet('lines.0.product_id', $this->product->id);
            $form->set('lines.0.quantity', 'not-exact')->call('scanCameraBarcode', 'MOBILE-EXACT')
                ->assertHasErrors('lines.0.quantity')->assertSet('lines.0.quantity', 'not-exact');
            $this->assertFalse($form->instance()->scanCameraBarcode('MOBILE-EXACT')['ok']);
        }
    }

    public function test_camera_and_quantity_refuse_fresh_company_or_membership_revocation(): void
    {
        $form = Livewire::test(PurchaseForm::class)->call('selectProduct', 0, $this->product->id);
        $this->company->users()->updateExistingPivot($this->owner->id, ['status' => 'inactive']);
        $form->call('scanCameraBarcode', 'ANY')->assertForbidden();
    }

    public function test_scan_beside_invalid_line_warns_and_withholds_stale_totals_until_corrected(): void
    {
        $otherProduct = $this->product->replicate(['public_id'])->fill(['sku' => 'MOBILE-OTHER']);
        $otherProduct->save();
        $this->unit->replicate(['public_id'])->fill(['product_id' => $otherProduct->id])->save();
        ProductBarcode::create(['company_id' => $this->company->id, 'product_id' => $otherProduct->id,
            'unit_id' => $this->unit->unit_id, 'barcode' => 'MOBILE-OTHER', 'type' => 'code128', 'is_primary' => true]);
        $before = $this->economicCounts();
        foreach ([InvoiceForm::class, QuotationForm::class, PurchaseForm::class] as $type) {
            $form = Livewire::test($type)->call('selectProduct', 0, $this->product->id)
                ->set('lines.0.quantity', 'not-exact')->call('recalculate');
            $form->call('scanCameraBarcode', 'MOBILE-OTHER')
                ->assertReturned(['ok' => true, 'requiresReview' => true, 'message' => __('camera.scan_added_review')])
                ->assertSet('lines.1.product_id', $otherProduct->id)->assertSet('documentTotalsValid', false)
                ->assertSee(__('camera.totals_unavailable'));
            $this->assertStringNotContainsString('data-document-total', $form->html());
            $this->assertSame('1', $form->get('lines.1.quantity'));
            $form->set('lines.0.quantity', '1')->call('recalculate')
                ->assertSet('documentTotalsValid', true)->assertDontSee(__('camera.totals_unavailable'));
            $this->assertStringContainsString('data-document-total', $form->html());
        }
        $this->assertSame($before, $this->economicCounts());
    }

    public function test_touch_quantity_stepper_uses_shared_controller_with_safe_js_config_and_accessibility_attrs(): void
    {
        $html = (string) $this->withViewErrors(['lines.0.return_quantity' => 'Quantity invalid'])
            ->blade(
                '<x-touch-quantity-stepper :index="0" field="return_quantity" :allows-fraction="true" step="0.01" min="0" :max="$adversarialMax" id="custom-qty-0" aria-label="Return Qty 1" />',
                ['adversarialMax' => "10'\"<script>"]
            );

        $this->assertStringContainsString('x-data="touchQuantityStepper($wire,', $html);
        $this->assertStringContainsString('@document-operation-state.window="onOperationState($event.detail)"', $html);
        $this->assertStringContainsString('id="custom-qty-0"', $html);
        $this->assertStringContainsString('aria-label="Return Qty 1"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="custom-qty-0-error"', $html);
        $this->assertStringContainsString('inputmode="decimal"', $html);
        $this->assertStringContainsString(':readonly="pending > 0"', $html);
        $this->assertStringContainsString('min-h-[44px] min-w-[44px]', $html);
        // Safe JS encoding via @js / Js::from escapes quotes and angle brackets; no raw inline controller
        $this->assertStringNotContainsString('optimisticQty', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('\\u0027\\u0022\\u003Cscript\\u003E', $html);
    }

    public function test_stepper_meta_updates_quantum_bounds_on_retained_row_across_product_and_unit_switches(): void
    {
        $kgUnit = Unit::create([
            'company_id' => $this->company->id,
            'code' => 'KG-META',
            'name_ar' => 'كغم',
            'name_en' => 'Kilogram',
            'allows_fraction' => true,
            'decimal_places' => 2,
            'active' => true,
        ]);
        $pieceUnit = Unit::create([
            'company_id' => $this->company->id,
            'code' => 'PC-META',
            'name_ar' => 'قطعة',
            'name_en' => 'Piece',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);

        $productCatalog = app(ProductCatalogService::class);
        $fractionalProduct = $productCatalog->createProduct($this->company, [
            'sku' => 'META-FRAC-1',
            'name_ar' => 'سكر وزن',
            'name_en' => 'Bulk Sugar',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $kgUnit->id,
            'default_purchase_cost_base' => '4.000000',
            'default_sale_price_base' => '6.000000',
        ], $this->owner->id);

        $altPieceUnit = $productCatalog->addOrUpdateAlternateUnit(
            $fractionalProduct,
            $pieceUnit->id,
            '10.000000',
            false,
            false
        );

        $wholeOnlyProduct = $productCatalog->createProduct($this->company, [
            'sku' => 'META-WHOLE-1',
            'name_ar' => 'علبة كاملة',
            'name_en' => 'Single Pack',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id,
            'default_purchase_cost_base' => '8.000000',
            'default_sale_price_base' => '12.000000',
        ], $this->owner->id);

        $fractionalBaseUnit = ProductUnit::where('product_id', $fractionalProduct->id)->where('is_base', true)->firstOrFail();

        foreach ([InvoiceForm::class, QuotationForm::class, PurchaseForm::class] as $componentClass) {
            $page = Livewire::test($componentClass);
            $initialRowKey = $page->get('lines.0.row_key');

            $page->call('selectProduct', 0, $fractionalProduct->id);
            $fractionalHtml = $page->html();
            $this->assertMatchesRegularExpression(
                '/data-line-index="0"[^>]*data-row-key='.preg_quote('"'.$initialRowKey.'"', '/').'[^>]*data-quantum="0\.01"[^>]*data-min="0"[^>]*data-max=""/',
                $fractionalHtml
            );

            $page->call('changeUnit', 0, $altPieceUnit->id);
            $wholeHtml = $page->html();
            $this->assertSame($initialRowKey, $page->get('lines.0.row_key'));
            $this->assertMatchesRegularExpression(
                '/data-line-index="0"[^>]*data-row-key='.preg_quote('"'.$initialRowKey.'"', '/').'[^>]*data-quantum="1"[^>]*data-min="0"[^>]*data-max=""/',
                $wholeHtml
            );

            $page->call('changeUnit', 0, $fractionalBaseUnit->id);
            $this->assertSame($initialRowKey, $page->get('lines.0.row_key'));
            $this->assertMatchesRegularExpression(
                '/data-line-index="0"[^>]*data-row-key='.preg_quote('"'.$initialRowKey.'"', '/').'[^>]*data-quantum="0\.01"[^>]*data-min="0"[^>]*data-max=""/',
                $page->html()
            );

            $page->call('selectProduct', 0, $wholeOnlyProduct->id);
            $this->assertSame($initialRowKey, $page->get('lines.0.row_key'));
            $this->assertMatchesRegularExpression(
                '/data-line-index="0"[^>]*data-row-key='.preg_quote('"'.$initialRowKey.'"', '/').'[^>]*data-quantum="1"[^>]*data-min="0"[^>]*data-max=""/',
                $page->html()
            );
        }
    }

    /** @return array<string,int> */
    private function economicCounts(): array
    {
        $out = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'purchases', 'sales_invoices', 'quotations', 'product_creation_requests'] as $table) {
            $out[$table] = DB::table($table)->count();
        }

        return $out;
    }
}
