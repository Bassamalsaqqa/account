<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Livewire\Pages\Inventory\InventoryOverview;
use App\Livewire\Pages\Products\ProductDetail;
use App\Models\PostingBatch;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

final class InventoryPresentationTest extends Phase5ETestCase
{
    public function test_location_search_uses_real_barcodes_and_shared_page_sizes_without_writes(): void
    {
        $this->createAndPostPurchase(['lines' => [['quantity' => '3', 'unit_cost' => '13.57']]]);
        $this->product->update(['minimum_stock_base' => '5']);
        ProductBarcode::create(['company_id' => $this->company->id, 'product_id' => $this->product->id,
            'barcode' => 'SYNTHETIC-LOCATION-BARCODE', 'type' => 'code128', 'is_primary' => true]);
        $before = [PostingBatch::count(), StockMovement::count()];
        $this->activate($this->customActor(['inventory.stock.view']));
        Livewire::test(InventoryOverview::class)
            ->assertViewHas('stockBalances', fn ($rows) => $rows->perPage() === 50)
            ->set('perPage', 200)
            ->assertViewHas('stockBalances', fn ($rows) => $rows->perPage() === 200)
            ->set('search', 'SYNTHETIC-LOCATION-BARCODE')
            ->assertViewHas('stockBalances', fn ($rows) => $rows->total() === 1)
            ->set('stockFilter', 'low_stock')
            ->assertViewHas('stockBalances', fn ($rows) => $rows->total() === 1)
            ->set('stockFilter', 'out_of_stock')
            ->assertViewHas('stockBalances', fn ($rows) => $rows->total() === 0)
            ->assertDontSee('13.57');
        $this->assertSame($before, [PostingBatch::count(), StockMovement::count()]);
    }

    public function test_stock_packages_and_warehouse_remainders_are_bilingual_read_only_and_cost_redacted(): void
    {
        $carton = Unit::create(['company_id' => $this->company->id, 'code' => 'carton-test', 'name_ar' => 'كرتونة', 'name_en' => 'Carton', 'active' => true, 'allows_fraction' => false, 'decimal_places' => 0]);
        ProductUnit::create(['company_id' => $this->company->id, 'product_id' => $this->product->id, 'unit_id' => $carton->id,
            'conversion_to_base' => '24', 'active' => true]);
        $this->product->update(['minimum_stock_base' => '60']);
        $this->createAndPostPurchase(['lines' => [['quantity' => '53', 'unit_cost' => '13.57']]]);
        $before = [PostingBatch::count(), StockMovement::count()];
        $reader = $this->customActor(['inventory.stock.view']);
        $this->activate($reader);
        foreach (['ar' => 'كرتونة', 'en' => 'Carton'] as $locale => $label) {
            app()->setLocale($locale);
            Livewire::test(ProductDetail::class, ['publicId' => $this->product->public_id])->assertViewHas('stockTotal', '53.000000')->assertSee('53')->assertDontSee('53.000000')->assertSee($label)->assertDontSee('13.57')
                ->call('setTab', 'warehouses')->assertSee($label);
            Livewire::test(InventoryOverview::class)->assertSee($label)->assertDontSee('13.57');
        }
        $this->assertSame($before, [PostingBatch::count(), StockMovement::count()]);
    }
}
