<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\ProductUnit;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Phase5E\Phase5ETestCase;

final class QuickProductSelectionAtomicityTest extends Phase5ETestCase
{
    public function test_disabled_currency_with_positive_rate_rolls_back_creation_and_retains_input_for_retry(): void
    {
        $this->company->currencies()->where('currency_code', 'USD')->update(['enabled' => false]);
        $this->assertFalse($this->company->currencies()->where('currency_code', 'USD')->value('enabled'));
        $page = Livewire::test(PurchaseForm::class)->set('lines.0.item_description', 'Keep my edited line')
            ->set('lines.0.quantity', '3')->set('lines.0.unit_cost', '12.34')
            ->set('currency_code', 'USD')->set('exchange_rate', '3.5000000000')->call('openQuickProductModal')
            ->set('quick_product_name_ar', 'Atomic product')->set('quick_product_name_en', 'Retain English')
            ->set('quick_product_sku', 'ATOMIC-RETRY');
        $lines = $page->get('lines');
        $token = $page->get('quickProductToken');
        $before = $this->effects();
        $page->call('saveQuickProduct');
        $this->assertSame($before, $this->effects(), 'Selection refusal must roll back every database effect.');
        $page->assertSet('showQuickProductModal', true)->assertSet('quickProductToken', $token)
            ->assertSet('quick_product_name_ar', 'Atomic product')->assertSet('quick_product_name_en', 'Retain English')
            ->assertSet('quick_product_sku', 'ATOMIC-RETRY')->assertSet('lines', $lines)
            ->assertHasErrors('quick_product_name_ar')->assertNotDispatched('quick-product-created');
        $this->company->currencies()->where('currency_code', 'USD')->update(['enabled' => true]);
        $page->call('saveQuickProduct')->assertHasNoErrors()->assertSet('showQuickProductModal', false)->assertDispatched('quick-product-created');
        $this->assertDatabaseHas('products', ['company_id' => $this->company->id, 'sku' => 'ATOMIC-RETRY']);
        $this->assertSame(1, DB::table('product_creation_requests')->where('request_key', $token)->count());
        $this->assertSame($lines[0], $page->get('lines.0'));
        $this->assertNotNull($page->get('lines.1.product_id'));
    }

    public function test_disabled_company_language_refusal_keeps_master_and_document_state_unchanged(): void
    {
        $page = Livewire::test(PurchaseForm::class)->call('openQuickProductModal')
            ->set('quick_product_name_ar', 'Language retry')->set('quick_product_sku', 'LANGUAGE-RETRY');
        $this->company->languages()->where('locale', $this->company->default_locale)->update(['enabled' => false]);
        $before = $this->effects();
        $lines = $page->get('lines');
        $page->call('saveQuickProduct')->assertHasErrors('quick_product_name_ar')->assertSet('showQuickProductModal', true)
            ->assertSet('lines', $lines)->assertNotDispatched('quick-product-created');
        $this->assertSame($before, $this->effects());
    }

    /** @return array<string, array{class-string<Component>}> */
    public static function entryForms(): array
    {
        return ['purchase' => [PurchaseForm::class], 'invoice' => [InvoiceForm::class], 'quotation' => [QuotationForm::class]];
    }

    /** @param class-string<Component> $component */
    #[DataProvider('entryForms')]
    public function test_downstream_unit_refusal_rolls_back_all_master_effects_in_each_form(string $component): void
    {
        $page = Livewire::test($component)->set('lines.0.item_description', 'Existing edited row')->call('openQuickProductModal')
            ->set('quick_product_name_ar', 'Refused unit')->set('quick_product_sku', 'UNIT-REFUSED');
        $lines = $page->get('lines');
        $before = $this->effects();
        // Simulate downstream refusal after the canonical master/base-unit create, not pre-creation validation.
        ProductUnit::created(function (ProductUnit $unit): void {
            DB::table('product_units')->where('id', $unit->id)->update(['active' => false]);
        });
        $page->call('saveQuickProduct')->assertHasErrors('quick_product_name_ar')->assertSet('showQuickProductModal', true)
            ->assertSet('quick_product_sku', 'UNIT-REFUSED')->assertSet('lines', $lines)->assertNotDispatched('quick-product-created');
        $this->assertSame($before, $this->effects());
    }

    /** @return array<string, string> */
    private function effects(): array
    {
        $hashes = [];
        foreach (['products', 'product_units', 'product_creation_requests', 'audit_events', 'purchases', 'purchase_lines',
            'sales_invoices', 'quotations', 'posting_batches', 'posting_lines', 'stock_movements', 'inventory_balances',
            'inventory_cost_states', 'inventory_lots', 'inventory_lot_balances', 'document_sequences'] as $table) {
            $hashes[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $hashes;
    }
}
