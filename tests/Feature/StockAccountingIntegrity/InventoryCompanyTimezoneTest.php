<?php

declare(strict_types=1);

namespace Tests\Feature\StockAccountingIntegrity;

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\DisposeExpiredStockAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use Carbon\CarbonImmutable;
use Tests\Feature\Phase8\Phase8TestCase;

final class InventoryCompanyTimezoneTest extends Phase8TestCase
{
    public function test_default_opening_date_uses_company_day_and_explicit_date_is_unchanged(): void
    {
        $this->company->update(['timezone' => 'Asia/Hebron']);
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 23:30:00', 'UTC'));
        $default = app(PostOpeningStockAction::class)->execute($this->company, $this->product, $this->warehouse,
            Quantity::of('2'), '1', $this->owner, 'timezone-opening-default');
        $explicit = app(PostOpeningStockAction::class)->execute($this->company, $this->product, $this->warehouse,
            Quantity::of('2'), '1', $this->owner, 'timezone-opening-explicit', movementDate: '2026-10-13');
        $this->assertSame('2026-10-15', $default['movement']->movement_date->format('Y-m-d'));
        $this->assertSame('2026-10-15', $default['batch']->posting_date->format('Y-m-d'));
        $this->assertSame('2026-10-13', $explicit['movement']->movement_date->format('Y-m-d'));
        $this->assertSame('2026-10-13', $explicit['batch']->posting_date->format('Y-m-d'));
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
    }

    public function test_default_adjustment_date_uses_company_previous_day(): void
    {
        $this->company->update(['timezone' => 'America/Los_Angeles']);
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 00:30:00', 'UTC'));
        app(PostOpeningStockAction::class)->execute($this->company, $this->product, $this->warehouse,
            Quantity::of('2'), '1', $this->owner, 'timezone-adjustment-opening', movementDate: '2026-10-13');
        $result = app(AdjustStockAction::class)->execute($this->company, $this->product, $this->warehouse,
            StockMovement::TYPE_ADJUSTMENT_DECREASE, Quantity::of('1'), 'Synthetic adjustment', $this->owner, 'timezone-adjustment');
        $this->assertSame('2026-10-14', $result['movement']->movement_date->format('Y-m-d'));
        $this->assertSame('2026-10-14', $result['batch']->posting_date->format('Y-m-d'));
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
    }

    public function test_disposal_expiry_boundary_uses_company_day(): void
    {
        $this->company->update(['timezone' => 'Asia/Hebron']);
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 23:30:00', 'UTC'));
        $product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'Synthetic expiry product', 'sku' => 'TZ-EXP', 'product_type' => 'stock',
            'track_stock' => true, 'track_expiry' => true, 'base_unit_id' => $this->unit->unit_id,
        ], $this->owner->id);
        $opening = app(PostOpeningStockAction::class)->execute($this->company, $product, $this->warehouse,
            Quantity::of('2'), '1', $this->owner, 'timezone-disposal-opening', lotNumber: 'TZ-LOT',
            expiryDate: '2026-10-14', movementDate: '2026-10-13');
        $result = app(DisposeExpiredStockAction::class)->execute($this->company, $product, $this->warehouse,
            $opening['movement']->lot_id, Quantity::of('1'), 'Synthetic expiry disposal', $this->owner, 'timezone-disposal');
        $this->assertSame('2026-10-15', $result['movement']->movement_date->format('Y-m-d'));
        $this->assertSame('2026-10-15', $result['batch']->posting_date->format('Y-m-d'));
        $this->assertSame('-1.000000', $result['movement']->quantity_delta_base);
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
    }
}
