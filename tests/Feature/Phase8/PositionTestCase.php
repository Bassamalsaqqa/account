<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Inventory\PostOpeningStockAction;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class PositionTestCase extends Phase8TestCase
{
    protected Warehouse $warehouseA;

    protected Warehouse $warehouseB;

    protected Unit $baseUnit;

    protected Product $stockProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouseA = Warehouse::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $this->company->id,
            'code' => 'WH-A',
            'name_ar' => 'المستودع الرئيسي',
            'name_en' => 'Main Warehouse',
            'is_default' => true,
            'is_active' => true,
            'created_by' => (int) $this->owner->id,
        ]);

        $this->warehouseB = Warehouse::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $this->company->id,
            'code' => 'WH-B',
            'name_ar' => 'مستودع الفرع',
            'name_en' => 'Branch Warehouse',
            'is_default' => false,
            'is_active' => true,
            'created_by' => (int) $this->owner->id,
        ]);

        $this->baseUnit = Unit::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $this->company->id,
            'code' => 'PCS',
            'name_ar' => 'قطعة',
            'name_en' => 'Piece',
            'symbol_ar' => 'قطعة',
            'symbol_en' => 'pcs',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);

        $this->stockProduct = Product::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $this->company->id,
            'sku' => 'SKU-001',
            'name_ar' => 'منتج اختبار',
            'name_en' => 'Test Product',
            'product_type' => Product::TYPE_STOCK,
            'base_unit_id' => $this->baseUnit->id,
            'track_stock' => true,
            'track_expiry' => true,
            'minimum_stock_base' => '10.000000',
            'default_purchase_cost_base' => '50.000000',
            'default_sale_price_base' => '80.000000',
            'active' => true,
            'created_by' => (int) $this->owner->id,
        ]);
        ProductUnit::create(['company_id' => $this->company->id, 'product_id' => $this->stockProduct->id,
            'unit_id' => $this->baseUnit->id, 'conversion_to_base' => '1.000000', 'is_base' => true,
            'is_default_purchase' => true, 'is_default_sale' => true, 'active' => true]);
    }

    /**
     * Canonical post opening stock action.
     */
    protected function postOpeningStock(
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        string $unitCost,
        string $date = '2026-10-01',
        ?string $lotNumber = null,
        ?string $expiryDate = null
    ): StockMovement {
        $action = app(PostOpeningStockAction::class);
        $res = $action->execute(
            company: $this->company,
            product: $product,
            warehouse: $warehouse,
            quantity: Quantity::of($quantity),
            unitCostBase: $unitCost,
            user: $this->owner,
            idempotencyKey: 'opening-'.Str::ulid(),
            lotNumber: $lotNumber,
            expiryDate: $expiryDate,
            movementDate: $date
        );

        return $res['movement'];
    }

    /**
     * Canonical stock transfer action using InventoryMovementService.
     *
     * @return array<string, mixed>
     */
    protected function postStockTransfer(
        Warehouse $fromWarehouse,
        Warehouse $toWarehouse,
        Product $product,
        string $quantity,
        string $date = '2026-10-04',
        ?int $lotId = null
    ): array {
        setPermissionsTeamId($this->company->id);
        $cmd = new StockTransferCommand(
            companyId: (int) $this->company->id,
            sourceWarehouseId: (int) $fromWarehouse->id,
            destinationWarehouseId: (int) $toWarehouse->id,
            movementDate: $date,
            lines: [
                new StockTransferLineCommand(
                    productId: (int) $product->id,
                    quantity: Quantity::of($quantity),
                    lotId: $lotId
                ),
            ],
            idempotencyKey: 'transfer-'.Str::ulid(),
            createdBy: (int) $this->owner->id
        );

        return app(InventoryMovementService::class)->transfer($cmd);
    }

    /**
     * Helper to assert that a report query produces zero financial or stock writes.
     * Deterministically compares count and full JSON serialization hash of all economic tables.
     */
    protected function assertZeroEconomicWrites(callable $action): mixed
    {
        $tables = [
            'posting_batches',
            'posting_lines',
            'stock_movements',
            'inventory_operations',
            'inventory_balances',
            'inventory_lots',
            'expenses',
            'salary_entries',
            'salary_payments',
            'salary_advance_allocations',
            'salary_payment_allocations',
            'employee_advances',
            'checks',
            'check_events',
            'money_transfers',
        ];

        $initialStates = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $initialStates[$table] = [
                'count' => $rows->count(),
                'hash' => md5(json_encode($rows->toArray())),
            ];
        }

        $result = $action();

        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $currentState = [
                'count' => $rows->count(),
                'hash' => md5(json_encode($rows->toArray())),
            ];

            $this->assertSame(
                $initialStates[$table]['count'],
                $currentState['count'],
                "Economic count change detected on table [{$table}] during report execution."
            );
            $this->assertSame(
                $initialStates[$table]['hash'],
                $currentState['hash'],
                "Economic state mutation/update detected on table [{$table}] during report execution."
            );
        }

        return $result;
    }
}
