<?php

declare(strict_types=1);

namespace Tests\Feature\MobileRelease;

use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\ProductBarcode;
use App\Models\Unit;
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
