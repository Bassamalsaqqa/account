<?php

declare(strict_types=1);

namespace Tests\Feature\TableRefinement;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Presentation\ReportPresenter;
use App\Domain\Purchasing\Queries\ProductPurchaseHistoryQuery;
use App\Domain\Sales\Formatters\SalesMoneyFormatter as Display;
use App\Livewire\Pages\Products\ProductDetail;
use App\Livewire\Pages\Products\ProductIndex;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Support\TablePageSize;
use App\Support\Tenancy\CompanyContext;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

final class TableDisplayTest extends Phase5ETestCase
{
    public function test_display_preserves_exact_large_values_and_meaningful_quantity_fractions(): void
    {
        $this->assertSame('280.00', Display::displayMoney('280.000000', 'ILS'));
        $this->assertSame('280.00', Display::displayMoney('280.001000', 'JOD'));
        $this->assertSame('280.001', Display::format('280.001000', 'JOD'));
        $this->assertSame('9,007,199,254,740,993.13', Display::displayMoney('9007199254740993.125000'));
        $this->assertSame('0.00', Display::displayMoney('-0.000001'));
        $this->assertSame('1', Display::displayQuantity('1.000000'));
        $this->assertSame('1.25', Display::displayQuantity('1.250000'));
        $this->assertSame('0.000001', Display::displayQuantity('0.000001'));
        $this->assertSame('0', Display::displayQuantity('0.000000'));
        $this->assertSame('—', Display::displayMoney(null));
        $this->assertSame('Restricted', Display::displayMoney('Restricted'));
    }

    public function test_report_display_does_not_change_export_precision_or_text(): void
    {
        $presenter = new ReportPresenter;
        $row = ['unit_cost' => '280.125000', 'quantity_base' => '1.250000', 'exchange_rate' => '3.6250000000', 'name' => 'Example'];
        $columns = [];
        foreach ($row as $key => $value) {
            $columns[] = ['key' => $key, 'label' => $key, 'type' => $key === 'name' ? 'text' : 'decimal'];
        }
        $this->assertSame('280.13', $presenter->displayValue($row, $columns[0]));
        $this->assertSame('1.25', $presenter->displayValue($row, $columns[1]));
        $this->assertSame('3.625', $presenter->displayValue($row, $columns[2]));
        $this->assertSame('Example', $presenter->displayValue($row, $columns[3]));
        $this->assertSame($row, $presenter->exportRow($row, $columns));
    }

    public function test_page_size_is_bounded_for_both_http_and_livewire(): void
    {
        foreach ([50, '50', 100, '100', 200, '200'] as $size) {
            $this->assertSame((int) $size, TablePageSize::resolve($size));
        }
        foreach ([null, '', -1, 0, 15, 201, 1000000, '1e2', [], true] as $size) {
            $this->assertSame(50, TablePageSize::resolve($size));
        }
        $component = Livewire::test(ProductIndex::class)
            ->assertViewHas('products', fn ($products) => $products->perPage() === 50)
            ->set('perPage', 100)
            ->assertViewHas('products', fn ($products) => $products->perPage() === 100)
            ->set('perPage', 200)
            ->assertViewHas('products', fn ($products) => $products->perPage() === 200)
            ->set('perPage', 1000000)
            ->assertSet('perPage', 50)
            ->assertViewHas('products', fn ($products) => $products->perPage() === 50);
        $component->call('setPage', 2)->set('perPage', 100)->assertSet('paginators.page', 1);
    }

    public function test_larger_product_pages_and_search_keep_precise_prices_and_filter_records(): void
    {
        for ($i = 0; $i < 51; $i++) {
            app(ProductCatalogService::class)->createProduct($this->company, [
                'name_en' => sprintf('Page Item %03d', $i), 'name_ar' => null,
                'sku' => 'PAGE-'.$i, 'product_type' => Product::TYPE_STOCK,
                'track_stock' => true, 'track_expiry' => false,
                'base_unit_id' => $this->product->base_unit_id,
                'default_sale_price_base' => '280.123456',
            ], $this->owner->id);
        }
        Livewire::test(ProductIndex::class)
            ->assertViewHas('products', fn ($p) => $p->count() === 50 && $p->total() === 52)
            ->set('perPage', 100)
            ->assertViewHas('products', fn ($p) => $p->count() === 52)
            ->call('setPage', 2)->set('search', 'Page Item 050')
            ->assertSet('paginators.page', 1)
            ->assertViewHas('products', fn ($p) => $p->count() === 1 && $p->first()->sku === 'PAGE-50');
        $this->assertSame('280.123456', (string) Product::where('sku', 'PAGE-50')->firstOrFail()->default_sale_price_base);
    }

    public function test_report_accepts_200_but_retains_page_window_protection(): void
    {
        $period = new ReportPeriod('custom', '2026-10-01', '2026-10-31', $this->company->timezone);
        $this->assertSame(200, (new ReportFilters($period, perPage: 200))->perPage);
        $this->expectException(InvalidReportFilterException::class);
        new ReportFilters($period, page: PHP_INT_MAX, perPage: 200);
    }

    public function test_malformed_url_page_sizes_cannot_break_or_unbound_the_product_list(): void
    {
        foreach (['garbage', '999999', ['200']] as $size) {
            Livewire::withQueryParams(['per_page' => $size])->test(ProductIndex::class)
                ->assertViewHas('products', fn ($p) => $p->perPage() === 50);
        }
    }

    public function test_paginated_product_history_keeps_canonical_posted_snapshot_precision(): void
    {
        $purchase = $this->createAndPostPurchase();
        $history = app(ProductPurchaseHistoryQuery::class)
            ->paginate($this->product, $this->company->id, 200);
        $this->assertSame(200, $history->perPage());
        $this->assertSame(1, $history->total());
        $this->assertSame('10.000000', $history->first()->unit_cost);
        Livewire::test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->call('setTab', 'purchase_history')->set('perPage', 200)
            ->assertViewHas('purchaseHistory', fn ($p) => $p->total() === 1 && $p->perPage() === 200);
        $this->assertSame('100.000000', (string) $purchase->fresh()->grand_total_currency);
    }

    public function test_large_pages_preserve_tenant_filtering_and_server_side_cost_redaction(): void
    {
        $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '513.123456']]]);
        app(CompanyContext::class)->clear();
        $foreignOwner = User::factory()->create();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, ['name_ar' => 'Synthetic Foreign', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($foreignCompany, $foreignOwner);
        $this->actingAs($foreignOwner);
        setPermissionsTeamId($foreignCompany->id);
        app(ProductCatalogService::class)->createProduct($foreignCompany, [
            'name_en' => 'FOREIGN-PRIVATE-PRODUCT', 'product_type' => Product::TYPE_STOCK,
            'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => Unit::where('company_id', $foreignCompany->id)->where('code', 'piece')->firstOrFail()->id,
        ], $foreignOwner->id);
        $this->activate($this->owner);
        $viewer = $this->customActor(['inventory.stock.view']);
        $this->activate($viewer);
        Livewire::test(ProductIndex::class)->set('perPage', 200)
            ->assertViewHas('canViewCost', false)
            ->assertViewHas('products', fn ($p) => $p->total() === 1 && $p->first()->company_id === $this->company->id)
            ->assertDontSee('513.12')->assertDontSee('FOREIGN-PRIVATE-PRODUCT')
            ->set('search', 'FOREIGN-PRIVATE-PRODUCT')
            ->assertViewHas('products', fn ($p) => $p->total() === 0);
    }
}
