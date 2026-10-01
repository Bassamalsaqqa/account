<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\FefoAllocationService;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FefoAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Unit $unitPiece;

    protected Product $product;

    protected FefoAllocationService $fefoService;

    protected InventoryMovementService $movementService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة تجارب الصلاحية',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->actingAs($this->user);

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'حليب مبستر',
            'sku' => 'MILK-EXP-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
        ]);

        $this->fefoService = app(FefoAllocationService::class);
        $this->movementService = app(InventoryMovementService::class);
    }

    public function test_fefo_allocation_order_and_multi_lot_depletion(): void
    {
        // 1. Inbound Lot A: exp 2027-03-31, qty 20
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(20),
                    unitCostBase: '5.000000',
                    lotNumber: 'LOT-A',
                    expiryDate: '2027-03-31',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // 2. Inbound Lot B: exp 2027-08-31, qty 50
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(50),
                    unitCostBase: '5.500000',
                    lotNumber: 'LOT-B',
                    expiryDate: '2027-08-31',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 2,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $lotA = InventoryLot::where('lot_number', 'LOT-A')->firstOrFail();
        $lotB = InventoryLot::where('lot_number', 'LOT-B')->firstOrFail();

        // 3. Issue 10 -> Must allocate entirely from Lot A (earlier expiry)
        $alloc1 = $this->fefoService->allocate($this->product, $this->warehouse, Quantity::of(10));
        $this->assertCount(1, $alloc1);
        $this->assertSame($lotA->id, $alloc1[0]['lot_id']);
        $this->assertSame('10.000000', $alloc1[0]['quantity']->toScale());

        // Execute outbound of 10 from Lot A
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_DECREASE,
            movementDate: '2026-10-02',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: $alloc1[0]['quantity'],
                    lotId: $alloc1[0]['lot_id'],
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 3,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // Now Lot A has 10 remaining, Lot B has 50 remaining.
        // 4. Issue 25 -> Must allocate 10 from Lot A, and 15 from Lot B.
        $alloc2 = $this->fefoService->allocate($this->product, $this->warehouse, Quantity::of(25));
        $this->assertCount(2, $alloc2);

        $this->assertSame($lotA->id, $alloc2[0]['lot_id']);
        $this->assertSame('10.000000', $alloc2[0]['quantity']->toScale());

        $this->assertSame($lotB->id, $alloc2[1]['lot_id']);
        $this->assertSame('15.000000', $alloc2[1]['quantity']->toScale());
    }

    public function test_fefo_excludes_expired_lots_by_default_and_allows_them_when_requested(): void
    {
        Carbon::setTestNow('2026-10-15');

        // Create expired lot (expired yesterday)
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '4.000000',
                    lotNumber: 'EXPIRED-LOT',
                    expiryDate: '2026-10-14',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 10,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // Create valid active lot
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(20),
                    unitCostBase: '4.000000',
                    lotNumber: 'VALID-LOT',
                    expiryDate: '2026-11-01',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 11,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $validLot = InventoryLot::where('lot_number', 'VALID-LOT')->firstOrFail();
        $expiredLot = InventoryLot::where('lot_number', 'EXPIRED-LOT')->firstOrFail();

        // When excludeExpired is true (default), expired lot is skipped
        $allocDefault = $this->fefoService->allocate($this->product, $this->warehouse, Quantity::of(5), excludeExpired: true);
        $this->assertCount(1, $allocDefault);
        $this->assertSame($validLot->id, $allocDefault[0]['lot_id']);

        // When excludeExpired is false, expired lot is allocated first because its expiry date is earlier
        $allocWithExpired = $this->fefoService->allocate($this->product, $this->warehouse, Quantity::of(5), excludeExpired: false);
        $this->assertCount(1, $allocWithExpired);
        $this->assertSame($expiredLot->id, $allocWithExpired[0]['lot_id']);

        Carbon::setTestNow();
    }

    public function test_fefo_tie_breaking_orders_by_received_date_then_id(): void
    {
        // Two lots with exact same expiry date 2027-01-01
        // Lot 1 received on 2026-10-01
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '5.000000',
                    lotNumber: 'SAME-EXP-1',
                    expiryDate: '2027-01-01',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 21,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // Lot 2 received on 2026-10-05
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-05',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '5.000000',
                    lotNumber: 'SAME-EXP-2',
                    expiryDate: '2027-01-01',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 22,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $lot1 = InventoryLot::where('lot_number', 'SAME-EXP-1')->firstOrFail();

        $alloc = $this->fefoService->allocate($this->product, $this->warehouse, Quantity::of(5));
        $this->assertCount(1, $alloc);
        $this->assertSame($lot1->id, $alloc[0]['lot_id'], 'Lot with earlier received date should be allocated first on expiry tie');
    }

    public function test_fefo_insufficient_stock_across_lots_throws_exception(): void
    {
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(15),
                    unitCostBase: '5.000000',
                    lotNumber: 'SHORT-LOT',
                    expiryDate: '2027-06-30',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 31,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $this->expectException(InsufficientStockException::class);
        $this->fefoService->allocate($this->product, $this->warehouse, Quantity::of(20));
    }
}
