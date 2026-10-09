<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Queries\ExpiryReportQuery;
use App\Application\Reporting\Queries\InventoryCostHistoryReportQuery;
use App\Application\Reporting\Queries\InventoryMovementReportQuery;
use App\Application\Reporting\Queries\InventoryStockOnHandReportQuery;
use App\Application\Reporting\Queries\InventoryValuationReportQuery;
use App\Application\Reporting\Queries\LowStockReportQuery;
use App\Application\Reporting\Queries\TransferHistoryReportQuery;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\User;
use App\Services\Inventory\InventoryMovementService;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;

class PositionInventoryReportTest extends PositionTestCase
{
    private function filters(array $values = []): array
    {
        return ['from' => '2026-10-01', 'to' => '2026-10-10', ...$values];
    }

    public function test_canonical_openings_are_asof_with_exact_value_and_bounded_full_totals(): void
    {
        $this->postOpeningStock($this->stockProduct, $this->warehouseA, '15', '50', '2026-10-01', 'LOT-A', '2026-12-01');
        $this->postOpeningStock($this->stockProduct, $this->warehouseB, '10', '60', '2026-10-15', 'LOT-B', '2026-12-01');
        $result = $this->assertZeroEconomicWrites(fn () => app(InventoryStockOnHandReportQuery::class)->execute($this->company, $this->filters(), $this->owner));
        $this->assertSame('15.000000', $result->totals['total_quantity']);
        $this->assertSame('750.000000', $result->totals['total_valuation']);
        $this->assertSame('50.000000', $result->rows[0]['average_unit_cost_base']);
        $valuation = app(InventoryValuationReportQuery::class)->execute($this->company, $this->filters(), $this->owner);
        $this->assertSame($result->totals['total_valuation'], $valuation->totals['total_valuation']);
        $later = app(InventoryStockOnHandReportQuery::class)->execute($this->company, $this->filters(['to' => '2026-10-20', 'grouping' => 'warehouse', 'per_page' => 1]), $this->owner);
        $this->assertCount(1, $later->rows);
        $this->assertSame(2, $later->pagination['total']);
        $this->assertSame('1350.000000', $later->totals['total_valuation']);
    }

    public function test_quantity_reader_gets_no_cost_payload_and_source_stock_authority_is_required(): void
    {
        $this->postOpeningStock($this->stockProduct, $this->warehouseA, '10', '50', '2026-10-01', 'LOT-Q', '2026-12-01');
        $reader = User::factory()->create();
        $this->company->users()->attach($reader->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'quantity-reader', 'guard_name' => 'web']);
        $role->syncPermissions(['reports.inventory.view', 'inventory.stock.view']);
        $reader->assignRole($role);
        $this->activate($reader);
        $result = app(InventoryStockOnHandReportQuery::class)->execute($this->company, $this->filters(), $reader);
        $this->assertNull($result->rows[0]['value_base']);
        $this->assertArrayNotHasKey('total_valuation', $result->totals);
        $role->revokePermissionTo('inventory.stock.view');
        $this->expectException(AuthorizationException::class);
        app(InventoryStockOnHandReportQuery::class)->execute($this->company, $this->filters(), $reader);
    }

    public function test_canonical_lot_expiry_is_strictly_before_cutoff_and_null_unknown(): void
    {
        foreach (['expired' => '2026-10-09', 'valid' => '2026-10-10', 'soon' => '2026-10-25', 'unknown' => null] as $status => $date) {
            $this->postOpeningStock($this->stockProduct, $this->warehouseA, '5', '2', '2026-10-01', 'LOT-'.$status, $date);
        }
        $result = $this->assertZeroEconomicWrites(fn () => app(ExpiryReportQuery::class)->execute($this->company, $this->filters(), $this->owner));
        foreach (['expired', 'valid', 'soon', 'unknown'] as $status) {
            $this->assertSame(1, $result->totals[$status.'_count']);
        }
        $filtered = app(ExpiryReportQuery::class)->execute($this->company, $this->filters(['status' => 'expired']), $this->owner);
        $this->assertCount(1, $filtered->rows);
        $this->assertSame(1, $filtered->totals['total_active_lots']);
    }

    public function test_canonical_transfer_pairs_lots_without_cartesian_duplication(): void
    {
        $a = $this->postOpeningStock($this->stockProduct, $this->warehouseA, '5', '50', '2026-10-01', 'LOT-A', '2026-12-01');
        $b = $this->postOpeningStock($this->stockProduct, $this->warehouseA, '5', '50', '2026-10-01', 'LOT-B', '2026-12-01');
        $cmd = new StockTransferCommand((int) $this->company->id, (int) $this->warehouseA->id, (int) $this->warehouseB->id, '2026-10-04', [
            new StockTransferLineCommand((int) $this->stockProduct->id, Quantity::of('2'), lotId: (int) $a->lot_id),
            new StockTransferLineCommand((int) $this->stockProduct->id, Quantity::of('3'), lotId: (int) $b->lot_id),
        ], 'report-transfer', (int) $this->owner->id);
        app(InventoryMovementService::class)->transfer($cmd);
        $result = $this->assertZeroEconomicWrites(fn () => app(TransferHistoryReportQuery::class)->execute($this->company, $this->filters(), $this->owner));
        $this->assertCount(2, $result->rows);
        $this->assertSame('5.000000', $result->totals['total_transferred_quantity']);
        $this->assertSame('0.000000', $result->totals['company_net_quantity_delta']);
        $stock = app(InventoryStockOnHandReportQuery::class)->execute($this->company, $this->filters(['warehouse_id' => $this->warehouseB->id]), $this->owner);
        $this->assertSame('5.000000', $stock->totals['total_quantity']);
    }

    public function test_low_stock_includes_zero_stock_products_and_exact_threshold_equality(): void
    {
        $this->postOpeningStock($this->stockProduct, $this->warehouseA, '10', '2', '2026-10-01', 'LOT-L', '2026-12-01');
        $result = app(LowStockReportQuery::class)->execute($this->company, $this->filters(['product_id' => $this->stockProduct->id]), $this->owner);
        $this->assertSame('low', $result->rows[0]['stock_status']);
        $this->assertSame('0.000000', $result->rows[0]['shortage_quantity']);
        $empty = app(LowStockReportQuery::class)->execute($this->company, $this->filters(['product_id' => $this->product->id]), $this->owner);
        $this->assertSame('out', $empty->rows[0]['stock_status']);
    }

    public function test_movement_and_cost_history_use_bounded_sql_pages_and_complete_totals(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->postOpeningStock($this->stockProduct, $this->warehouseA, '1', '1.123456', '2026-10-0'.$i, 'LOT-'.$i, '2026-12-01');
        }
        $result = app(InventoryMovementReportQuery::class)->execute($this->company, $this->filters(['per_page' => 1, 'page' => 2]), $this->owner);
        $this->assertCount(1, $result->rows);
        $this->assertSame(3, $result->pagination['total']);
        $this->assertSame('3.000000', $result->totals['net_quantity_delta']);
        $this->assertSame('3.370368', $result->totals['total_value_delta']);
        $cost = app(InventoryCostHistoryReportQuery::class)->execute($this->company, $this->filters(['per_page' => 1]), $this->owner);
        $this->assertSame('1.123456',$cost->rows[0]['unit_cost_base']);
    }

    public function test_unsupported_and_malformed_filters_are_rejected_even_for_typed_inputs(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        app(InventoryStockOnHandReportQuery::class)->execute($this->company,new ReportFilters(ReportPeriod::custom('2026-10-01','2026-10-10',$this->company),currencyCode: 'USD'),$this->owner);
    }
}
