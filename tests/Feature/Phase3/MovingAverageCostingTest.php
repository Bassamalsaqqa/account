<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\InventoryCostState;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MovingAverageCostingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Unit $unitPiece;

    protected Product $product;

    protected InventoryMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة تجارب متوسط التكلفة المرجح',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->actingAs($this->user);

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'زيت زيتون بكر 1 لتر',
            'sku' => 'OIL-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
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

        $this->service = app(InventoryMovementService::class);
    }

    public function test_exact_weighted_average_lifecycle_from_specification(): void
    {
        // 1. Inbound 100 @ 10 -> 100 qty, 10.000000 avg, 1000.000000 val
        $cmd1 = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(100),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        );
        $this->service->record($cmd1);

        $costState = InventoryCostState::where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('100.000000', $costState->quantity_base);
        $this->assertSame('10.000000', $costState->average_cost_base);
        $this->assertSame('1000.000000', $costState->inventory_value_base);

        // 2. Inbound 100 @ 12 -> 200 qty, 11.000000 avg, 2200.000000 val
        $cmd2 = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            movementDate: '2026-10-02',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(100),
                    unitCostBase: '12.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 2,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        );
        $this->service->record($cmd2);

        $costState->refresh();
        $this->assertSame('200.000000', $costState->quantity_base);
        $this->assertSame('11.000000', $costState->average_cost_base);
        $this->assertSame('2200.000000', $costState->inventory_value_base);

        // 3. Outbound 20 -> 180 qty, 11.000000 avg, 1980.000000 val (cost of issue = 220)
        $cmd3 = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_DECREASE,
            movementDate: '2026-10-03',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(20),
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 3,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        );
        $movements3 = $this->service->record($cmd3);

        $this->assertSame('11.000000', $movements3[0]->unit_cost_base);
        $this->assertSame('-220.000000', $movements3[0]->value_delta_base);

        $costState->refresh();
        $this->assertSame('180.000000', $costState->quantity_base);
        $this->assertSame('11.000000', $costState->average_cost_base);
        $this->assertSame('1980.000000', $costState->inventory_value_base);

        // 4. Inbound 20 @ 14 -> 200 qty, 11.300000 avg, 2260.000000 val
        $cmd4 = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            movementDate: '2026-10-04',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(20),
                    unitCostBase: '14.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 4,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        );
        $this->service->record($cmd4);

        $costState->refresh();
        $this->assertSame('200.000000', $costState->quantity_base);
        $this->assertSame('11.300000', $costState->average_cost_base);
        $this->assertSame('2260.000000', $costState->inventory_value_base);
    }

    public function test_non_terminating_division_rounding_to_six_decimals(): void
    {
        // 100 @ 10 (1000) + 200 @ 15 (3000) = 4000 / 300 = 13.333333
        $cmd = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(100),
                    unitCostBase: '10.000000',
                ),
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(200),
                    unitCostBase: '15.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 10,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        );
        $this->service->record($cmd);

        $costState = InventoryCostState::where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('300.000000', $costState->quantity_base);
        $this->assertSame('13.333333', $costState->average_cost_base);
        $this->assertSame('4000.000000', $costState->inventory_value_base);
    }

    public function test_zero_stock_policy_resets_valuation_and_average_without_division_by_zero(): void
    {
        // Add 50 @ 20
        $cmd1 = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(50),
                    unitCostBase: '20.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 21,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        );
        $this->service->record($cmd1);

        // Outbound all 50
        $cmd2 = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_DAMAGE_OR_LOSS,
            movementDate: '2026-10-02',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(50),
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 22,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        );
        $this->service->record($cmd2);

        $costState = InventoryCostState::where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('0.000000', $costState->quantity_base);
        $this->assertSame('0.000000', $costState->average_cost_base);
        $this->assertSame('0.000000', $costState->inventory_value_base);
    }

    public function test_full_depletion_eliminates_gl_residual_on_non_terminating_average(): void
    {
        // Inbound 1 @ 1.000000 ILS (val 1.000000)
        $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(1),
                    unitCostBase: '1.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 301,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // Inbound 2 @ 0.000000 ILS (val 0.000000) -> total qty 3, total val 1.000000, avg 0.333333
        $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(2),
                    unitCostBase: '0.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 302,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $costState = InventoryCostState::where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('3.000000', $costState->quantity_base);
        $this->assertSame('1.000000', $costState->inventory_value_base);
        $this->assertSame('0.333333', $costState->average_cost_base);

        // Now issue all 3 units (full depletion)
        // With standard 3 * 0.333333 = 0.999999, which would leave a 0.000001 residual.
        // Our kernel policy explicitly ensures that on full depletion, movement value delta equals
        // the EXACT remaining cached value (-1.000000), leaving zero residual in GL and cache!
        $outMovements = $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_DECREASE,
            movementDate: '2026-10-02',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(3),
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 303,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $this->assertSame('-1.000000', $outMovements[0]->value_delta_base, 'Value delta on full depletion must equal negative of exact cached value');

        $costState->refresh();
        $this->assertSame('0.000000', $costState->quantity_base);
        $this->assertSame('0.000000', $costState->inventory_value_base);
        $this->assertSame('0.000000', $costState->average_cost_base);

        // Sum of all movement value deltas must equal exactly zero
        $movements = StockMovement::where('product_id', $this->product->id)->get();
        $totalMovementValue = $movements->reduce(
            fn (BigDecimal $carry, StockMovement $m) => $carry->plus(BigDecimal::of((string) $m->value_delta_base)),
            BigDecimal::zero()
        );
        $this->assertSame('0.000000', (string) $totalMovementValue->toScale(6), 'Total movement value delta sum must be exactly 0 after full depletion');
    }

    public function test_negative_unit_cost_is_strictly_rejected(): void
    {
        $this->expectException(InvalidInventoryMovementException::class);
        $this->expectExceptionMessage('Inbound unit cost cannot be negative');

        $cmd = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '-5.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 30,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        );
        $this->service->record($cmd);
    }

    // ── Regression: Correction-04 – full depletion unit cost snapshot ─────────

    public function test_full_depletion_unit_cost_snapshot_matches_pre_depletion_average(): void
    {
        // Buy 3 units at 1.00 each: avg = 1.000000
        $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(3),
                    unitCostBase: '1.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 801,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // Deplete all 3 units: should record unit_cost_base = pre-depletion avg = 1.000000
        $movements = $this->service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_DECREASE,
            movementDate: '2026-10-02',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(3),
                    unitCostBase: null,
                ),
            ],
            sourceType: 'adjustment',
            sourceId: 802,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        $depletionMovement = $movements[0]->fresh();
        // unit_cost_base must equal the running average BEFORE depletion, not 0
        $this->assertSame('1.000000', (string) $depletionMovement->unit_cost_base);
        // After full depletion, balance is 0 quantity and 0 value
        $costState = InventoryCostState::where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('0.000000', (string) $costState->quantity_base);
        $this->assertSame('0.000000', (string) $costState->inventory_value_base);
    }
}
