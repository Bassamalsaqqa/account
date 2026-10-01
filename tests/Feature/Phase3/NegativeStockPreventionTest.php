<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NegativeStockPreventionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Unit $unitPiece;

    protected Product $productStandard;

    protected Product $productExpiry;

    protected InventoryMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة تجارب منع الأرصدة السالبة',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->actingAs($this->user);

        $this->productStandard = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'سكر أبيض 1 كجم',
            'sku' => 'SUGAR-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->productExpiry = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'لبن رائب 1 لتر',
            'sku' => 'YOGURT-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->service = app(InventoryMovementService::class);
    }

    public function test_warehouse_balance_cannot_be_drawn_into_negative(): void
    {
        // 1. Inbound 10 units
        $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '5.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // 2. Outbound 15 units -> Must throw InsufficientStockException
        $this->expectException(InsufficientStockException::class);
        $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_DECREASE,
            movementDate: '2026-10-02',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(15),
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 2,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));
    }

    public function test_lot_balance_cannot_be_drawn_into_negative(): void
    {
        // Inbound 10 units to Lot L-01
        $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productExpiry->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '2.500000',
                    lotNumber: 'L-01',
                    expiryDate: '2027-01-01',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 11,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $lot = InventoryLot::where('lot_number', 'L-01')->firstOrFail();

        // Attempt outbound 12 units from Lot L-01
        $this->expectException(InsufficientStockException::class);
        $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_DECREASE,
            movementDate: '2026-10-02',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productExpiry->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(12),
                    lotId: $lot->id,
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 12,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));
    }

    public function test_stock_movement_record_is_immutable_and_cannot_be_updated(): void
    {
        $movements = $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(50),
                    unitCostBase: '4.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 21,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $movement = $movements[0];

        $this->expectException(ImmutableRecordException::class);
        $movement->update(['reason' => 'حاول التعديل']);
    }

    public function test_stock_movement_record_is_immutable_and_cannot_be_deleted(): void
    {
        $movements = $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(50),
                    unitCostBase: '4.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 31,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $movement = $movements[0];

        $this->expectException(ImmutableRecordException::class);
        $movement->delete();
    }
}
