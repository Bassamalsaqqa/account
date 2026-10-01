<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class WarehouseTransferTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Warehouse $warehouseA;

    protected Warehouse $warehouseB;

    protected Unit $unitPiece;

    protected Product $product;

    protected Product $productExpiry;

    protected InventoryMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة تجارب المناقلات المخزنية',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->warehouseA = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();

        $this->warehouseB = Warehouse::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مستودع فرع الشمال',
            'code' => 'WH-NORTH',
            'is_default' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->actingAs($this->user);

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'شاي سيلاني 100 كيس',
            'sku' => 'TEA-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->productExpiry = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'جبنة بيضاء بلدية',
            'sku' => 'CHEESE-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->service = app(InventoryMovementService::class);
    }

    public function test_warehouse_transfer_conserves_total_quantity_value_and_average_cost(): void
    {
        // 1. Inbound 100 units @ 10 ILS to Warehouse A
        $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouseA->id,
                    quantity: Quantity::of(100),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $costBefore = InventoryCostState::where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('100.000000', $costBefore->quantity_base);
        $this->assertSame('10.000000', $costBefore->average_cost_base);
        $this->assertSame('1000.000000', $costBefore->inventory_value_base);

        // 2. Transfer 40 units from Warehouse A to Warehouse B
        $movements = $this->service->transfer(new StockTransferCommand(
            companyId: $this->company->id,
            sourceWarehouseId: $this->warehouseA->id,
            destinationWarehouseId: $this->warehouseB->id,
            movementDate: '2026-10-02',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->product->id,
                    quantity: Quantity::of(40),
                ),
            ],
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
            sourceType: 'warehouse_transfer',
            sourceId: 101,
        ));

        $this->assertCount(2, $movements);

        // Outbound movement
        $out = $movements[0];
        $this->assertSame(StockMovement::TYPE_TRANSFER_OUT, $out->movement_type);
        $this->assertSame($this->warehouseA->id, $out->warehouse_id);
        $this->assertSame('-40.000000', $out->quantity_delta_base);
        $this->assertSame('10.000000', $out->unit_cost_base);
        $this->assertSame('-400.000000', $out->value_delta_base);

        // Inbound movement
        $in = $movements[1];
        $this->assertSame(StockMovement::TYPE_TRANSFER_IN, $in->movement_type);
        $this->assertSame($this->warehouseB->id, $in->warehouse_id);
        $this->assertSame('40.000000', $in->quantity_delta_base);
        $this->assertSame('10.000000', $in->unit_cost_base);
        $this->assertSame('400.000000', $in->value_delta_base);

        // Check Warehouse Balances
        $balA = InventoryBalance::where('warehouse_id', $this->warehouseA->id)->where('product_id', $this->product->id)->firstOrFail();
        $balB = InventoryBalance::where('warehouse_id', $this->warehouseB->id)->where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('60.000000', $balA->quantity_base);
        $this->assertSame('40.000000', $balB->quantity_base);

        // Check Company-Wide Valuation is conserved
        $costAfter = InventoryCostState::where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('100.000000', $costAfter->quantity_base);
        $this->assertSame('10.000000', $costAfter->average_cost_base);
        $this->assertSame('1000.000000', $costAfter->inventory_value_base);
    }

    public function test_transfer_with_lot_tracking_updates_both_warehouse_lot_balances(): void
    {
        // Inbound 50 units with Lot LOT-CHEESE to Warehouse A
        $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productExpiry->id,
                    warehouseId: $this->warehouseA->id,
                    quantity: Quantity::of(50),
                    unitCostBase: '12.000000',
                    lotNumber: 'LOT-CHEESE',
                    expiryDate: '2027-04-30',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 2,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $lot = InventoryLot::where('lot_number', 'LOT-CHEESE')->firstOrFail();

        // Transfer 20 units of LOT-CHEESE to Warehouse B
        $this->service->transfer(new StockTransferCommand(
            companyId: $this->company->id,
            sourceWarehouseId: $this->warehouseA->id,
            destinationWarehouseId: $this->warehouseB->id,
            movementDate: '2026-10-02',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->productExpiry->id,
                    quantity: Quantity::of(20),
                    lotId: $lot->id,
                ),
            ],
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
            sourceType: 'warehouse_transfer',
            sourceId: 102,
        ));

        $lotBalA = InventoryLotBalance::where('warehouse_id', $this->warehouseA->id)->where('lot_id', $lot->id)->firstOrFail();
        $lotBalB = InventoryLotBalance::where('warehouse_id', $this->warehouseB->id)->where('lot_id', $lot->id)->firstOrFail();

        $this->assertSame('30.000000', $lotBalA->quantity_base);
        $this->assertSame('20.000000', $lotBalB->quantity_base);
    }

    public function test_transfer_between_same_warehouse_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StockTransferCommand(
            companyId: $this->company->id,
            sourceWarehouseId: $this->warehouseA->id,
            destinationWarehouseId: $this->warehouseA->id,
            movementDate: '2026-10-02',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->product->id,
                    quantity: Quantity::of(10),
                ),
            ],
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        );
    }

    public function test_transfer_with_inactive_warehouse_is_rejected(): void
    {
        $inactiveWarehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مستودع معطل',
            'code' => 'WH-INACTIVE',
            'is_default' => false,
            'active' => false,
            'created_by' => $this->user->id,
        ]);

        $this->expectException(InvalidInventoryMovementException::class);
        $this->service->transfer(new StockTransferCommand(
            companyId: $this->company->id,
            sourceWarehouseId: $this->warehouseA->id,
            destinationWarehouseId: $inactiveWarehouse->id,
            movementDate: '2026-10-02',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->product->id,
                    quantity: Quantity::of(10),
                ),
            ],
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));
    }

    public function test_transfer_with_insufficient_source_stock_throws_exception(): void
    {
        // Warehouse A has 0 stock of product
        $this->expectException(InsufficientStockException::class);
        $this->service->transfer(new StockTransferCommand(
            companyId: $this->company->id,
            sourceWarehouseId: $this->warehouseA->id,
            destinationWarehouseId: $this->warehouseB->id,
            movementDate: '2026-10-02',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->product->id,
                    quantity: Quantity::of(10),
                ),
            ],
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
            sourceType: 'warehouse_transfer',
            sourceId: 104,
        ));
    }
}
