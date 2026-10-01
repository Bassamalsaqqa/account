<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Livewire\Pages\Inventory\OpeningStockForm;
use App\Livewire\Pages\Inventory\StockAdjustmentForm;
use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\CompanyUser;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\InventoryRebuildService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Phase3IntegrityCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $warehouseStaff;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Unit $unitPiece;

    protected Unit $unitBox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة التصحيح المتكامل',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        // Ensure permissions exist
        Permission::findOrCreate('inventory.stock.adjust', 'web');
        Permission::findOrCreate('inventory.cost.view', 'web');
        Permission::findOrCreate('inventory.stock.transfer', 'web');

        // Create warehouse staff with stock.adjust ONLY (no cost.view)
        $this->warehouseStaff = User::factory()->create(['locale' => 'ar']);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->warehouseStaff->id,
            'role' => 'warehouse',
            'status' => 'active',
        ]);
        setPermissionsTeamId($this->company->id);
        $this->warehouseStaff->givePermissionTo('inventory.stock.adjust');
        $this->warehouseStaff->givePermissionTo('inventory.stock.transfer');

        // Owner gets all permissions
        $this->owner->givePermissionTo('inventory.stock.adjust');
        $this->owner->givePermissionTo('inventory.cost.view');
        $this->owner->givePermissionTo('inventory.stock.transfer');

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->unitBox = Unit::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'box'],
            [
                'name_ar' => 'صندوق',
                'name_en' => 'Box',
                'allow_fractions' => false,
                'decimal_places' => 0,
                'active' => true,
                'created_by' => $this->owner->id,
            ]
        );
    }

    private function createTrackedProduct(string $sku = 'PRD-INT-01', bool $trackExpiry = false): Product
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج تجريبي '.$sku,
            'sku' => $sku,
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => $trackExpiry,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'active' => true,
        ]);

        return $product;
    }

    // ==========================================
    // 1. Opening Stock Valuation Authorization
    // ==========================================

    public function test_opening_stock_denied_without_cost_view_permission(): void
    {
        $product = $this->createTrackedProduct('PRD-OP-01');

        $this->actingAs($this->warehouseStaff);

        // 1. Direct action call fails
        $thrown = false;
        try {
            app(PostOpeningStockAction::class)->execute(
                company: $this->company,
                product: $product,
                warehouse: $this->warehouse,
                quantity: Quantity::of(10),
                unitCostBase: '15.000000',
                user: $this->warehouseStaff,
                idempotencyKey: 'OS-UNAUTH-01',
            );
        } catch (AuthorizationException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Action must throw AuthorizationException when user lacks cost view permission.');

        // 2. Ensure zero database writes across operations/movements/caches/GL
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, InventoryOperation::count());
        $this->assertSame(0, PostingBatch::count());
        $this->assertSame(0, InventoryBalance::where('quantity_base', '>', 0)->count());
        $this->assertSame(0, InventoryCostState::where('quantity_base', '>', 0)->count());
    }

    public function test_opening_stock_form_aborts_without_cost_view(): void
    {
        app(CompanyContext::class)->setCompany($this->company, $this->warehouseStaff);

        // OpeningStockForm must deny access before sensitive state/HTML is serialized
        Livewire::actingAs($this->warehouseStaff)
            ->test(OpeningStockForm::class)
            ->assertForbidden();
    }

    public function test_authorized_opening_stock_with_deliberate_zero_cost(): void
    {
        $product = $this->createTrackedProduct('PRD-OP-ZERO');
        $this->actingAs($this->owner);

        $res = app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(10),
            unitCostBase: '0.000000',
            user: $this->owner,
            idempotencyKey: 'OS-ZERO-COST-01',
        );

        $movement = $res['movement'];
        $this->assertSame('10.000000', $movement->quantity_delta_base);
        $this->assertSame('0.000000', $movement->unit_cost_base);
        $this->assertSame('0.000000', $movement->value_delta_base);

        // No zero/zero GL posting lines
        $this->assertNull($res['batch']);
        $this->assertSame(0, PostingLine::count());

        // InventoryCostState has 10 units with 0 average
        $cs = InventoryCostState::where('product_id', $product->id)->firstOrFail();
        $this->assertSame('10.000000', $cs->quantity_base);
        $this->assertSame('0.000000', $cs->average_cost_base);
        $this->assertSame('0.000000', $cs->inventory_value_base);
    }

    // ==========================================
    // 2. Adjustment Increase Cost Policy
    // ==========================================

    public function test_adjustment_increase_explicit_cost_requires_cost_view(): void
    {
        $product = $this->createTrackedProduct('PRD-ADJ-01');
        $this->actingAs($this->warehouseStaff);

        $thrown = false;
        try {
            app(AdjustStockAction::class)->execute(
                company: $this->company,
                product: $product,
                warehouse: $this->warehouse,
                type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
                quantity: Quantity::of(5),
                reason: 'Explicit cost unauthorized',
                user: $this->warehouseStaff,
                idempotencyKey: 'ADJ-UNAUTH-01',
                unitCostBase: '25.000000',
            );
        } catch (AuthorizationException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Specifying explicit unit cost on stock adjustment increase requires cost view permission.');
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, InventoryOperation::count());
        $this->assertSame(0, PostingBatch::count());
        $this->assertSame(0, InventoryBalance::where('quantity_base', '>', 0)->count());
        $this->assertSame(0, InventoryCostState::where('quantity_base', '>', 0)->count());
    }

    public function test_adjustment_increase_implicit_cost_resolves_from_positive_basis(): void
    {
        $product = $this->createTrackedProduct('PRD-ADJ-02');

        // Setup positive stock basis (10 @ 20.000000) by owner
        $this->actingAs($this->owner);
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(10),
            unitCostBase: '20.000000',
            user: $this->owner,
            idempotencyKey: 'OS-BASIS-01',
        );

        // Warehouse staff without cost.view performs adjustment increase with omitted cost
        $this->actingAs($this->warehouseStaff);
        $res = app(AdjustStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $this->warehouse,
            type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            quantity: Quantity::of(5),
            reason: 'Found extra items during cycle count',
            user: $this->warehouseStaff,
            idempotencyKey: 'ADJ-IMPLICIT-01',
            unitCostBase: null, // Omitted
        );

        $movement = $res['movement'];
        $this->assertSame('5.000000', $movement->quantity_delta_base);
        $this->assertSame('20.000000', $movement->unit_cost_base);
        $this->assertSame('100.000000', $movement->value_delta_base);

        $cs = InventoryCostState::where('product_id', $product->id)->firstOrFail();
        $this->assertSame('15.000000', $cs->quantity_base);
        $this->assertSame('20.000000', $cs->average_cost_base);
        $this->assertSame('300.000000', $cs->inventory_value_base);
    }

    public function test_adjustment_increase_implicit_cost_rejects_without_positive_basis(): void
    {
        $product = $this->createTrackedProduct('PRD-ADJ-NOBASIS');
        $this->actingAs($this->warehouseStaff);

        $initialMovements = StockMovement::count();
        $initialOps = InventoryOperation::count();
        $initialBatches = PostingBatch::count();

        $thrown = false;
        try {
            app(AdjustStockAction::class)->execute(
                company: $this->company,
                product: $product,
                warehouse: $this->warehouse,
                type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
                quantity: Quantity::of(5),
                reason: 'No basis available',
                user: $this->warehouseStaff,
                idempotencyKey: 'ADJ-NOBASIS-01',
                unitCostBase: null,
            );
        } catch (InvalidInventoryMovementException $e) {
            $thrown = true;
            $this->assertStringContainsString('requires positive existing company stock', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Adjustment increase without cost must fail when product balance is zero.');
        $this->assertSame($initialMovements, StockMovement::count());
        $this->assertSame($initialOps, InventoryOperation::count());
        $this->assertSame($initialBatches, PostingBatch::count());
    }

    // ==========================================
    // 3. Durable Fingerprint and Idempotency Retry
    // ==========================================

    public function test_implicit_retry_after_average_cost_changes_returns_original_operation(): void
    {
        $product = $this->createTrackedProduct('PRD-RETRY-01');

        // Initial opening stock: 10 @ 10.000000
        $this->actingAs($this->owner);
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(10),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'OS-RETRY-INIT',
        );

        // Adjustment increase +5 with implicit cost under key ABC
        $this->actingAs($this->warehouseStaff);
        $cmd = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(5),
                    unitCostBase: null,
                ),
            ],
            sourceType: 'stock_adjustment',
            sourceId: $this->company->id,
            idempotencyKey: 'KEY-ABC',
            createdBy: $this->warehouseStaff->id,
            reason: 'Implicit adjustment +5',
        );

        $service = app(InventoryMovementService::class);
        $m1 = $service->record($cmd);
        $this->assertCount(1, $m1);
        $this->assertSame('10.000000', $m1[0]->unit_cost_base);
        $this->assertSame('50.000000', $m1[0]->value_delta_base);

        // Later receipt changes moving average: +10 @ 20.000000 by owner
        $this->actingAs($this->owner);
        $receiptCmd = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-02',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '20.000000',
                ),
            ],
            sourceType: 'opening_stock',
            sourceId: $this->company->id,
            idempotencyKey: 'RECEIPT-KEY',
            createdBy: $this->owner->id,
            reason: 'New receipt at higher cost',
        );
        $service->record($receiptCmd);

        // Company average is now: (150 + 200) / 25 = 350 / 25 = 14.000000
        $cs = InventoryCostState::where('product_id', $product->id)->firstOrFail();
        $this->assertSame('25.000000', $cs->quantity_base);
        $this->assertSame('14.000000', $cs->average_cost_base);

        // Retry identical command KEY-ABC with implicit cost (null)
        $this->actingAs($this->warehouseStaff);
        $mRetry = $service->record($cmd);

        $this->assertCount(1, $mRetry);
        $this->assertSame($m1[0]->id, $mRetry[0]->id);
        $this->assertSame('10.000000', $mRetry[0]->unit_cost_base, 'Original cost-10 operation returned');
        $this->assertSame('50.000000', $mRetry[0]->value_delta_base);

        // Total movements for product must be exactly 3 (opening 10, adjustment 5, receipt 10)
        $this->assertSame(3, StockMovement::where('product_id', $product->id)->count());
    }

    public function test_retry_with_changed_intent_conflicts(): void
    {
        $product = $this->createTrackedProduct('PRD-RETRY-02');
        $this->actingAs($this->owner);

        // Initial setup
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(10),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'OS-INIT-2',
        );

        $service = app(InventoryMovementService::class);
        $cmd1 = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(5),
                    unitCostBase: null,
                ),
            ],
            sourceType: 'stock_adjustment',
            sourceId: $this->company->id,
            idempotencyKey: 'KEY-CONFLICT',
            createdBy: $this->owner->id,
            reason: 'First attempt',
        );
        $service->record($cmd1);

        // Retry with changed quantity (6 instead of 5)
        $cmdDifferentQty = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(6),
                    unitCostBase: null,
                ),
            ],
            sourceType: 'stock_adjustment',
            sourceId: $this->company->id,
            idempotencyKey: 'KEY-CONFLICT',
            createdBy: $this->owner->id,
            reason: 'First attempt',
        );

        $this->expectException(IdempotencyConflictException::class);
        $service->record($cmdDifferentQty);
    }

    // ==========================================
    // 4. Operation Immutability
    // ==========================================

    public function test_inventory_operation_rejects_update_and_delete(): void
    {
        $product = $this->createTrackedProduct('PRD-OP-IMMUT');
        $this->actingAs($this->owner);

        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(10),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'OS-IMMUT',
        );

        /** @var InventoryOperation $op */
        $op = InventoryOperation::where('company_id', $this->company->id)->firstOrFail();

        // 1. Update rejected
        try {
            $op->update(['line_count' => 99]);
            $this->fail('Expected ImmutableRecordException on InventoryOperation update');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('cannot be updated', $e->getMessage());
        }

        // 2. Delete rejected
        try {
            $op->delete();
            $this->fail('Expected ImmutableRecordException on InventoryOperation delete');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }
    }

    // ==========================================
    // 5. Fail-Closed ProductUnit & Pristine Base Change
    // ==========================================

    public function test_posting_fails_closed_when_product_unit_missing(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج بدون وحدة',
            'sku' => 'NO-PU-01',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
        // Deliberately do NOT create ProductUnit row

        $this->actingAs($this->owner);
        $cmd = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'opening_stock',
            sourceId: $this->company->id,
            idempotencyKey: 'FAIL-CLOSED-01',
            createdBy: $this->owner->id,
            reason: 'Should fail closed',
        );

        $this->expectException(InvalidInventoryMovementException::class);
        $this->expectExceptionMessage('not an active configured unit');

        app(InventoryMovementService::class)->record($cmd);
    }

    // ==========================================
    // 6. Master Data Lifecycle Guards
    // ==========================================

    public function test_cannot_deactivate_product_with_positive_stock(): void
    {
        $product = $this->createTrackedProduct('PRD-DEACT-01');
        $this->actingAs($this->owner);

        // Add stock
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(10),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'OS-DEACT-01',
        );

        $catalog = app(ProductCatalogService::class);

        $this->expectException(InvalidInventoryMovementException::class);
        $this->expectExceptionMessage('Cannot deactivate product');

        $catalog->updateProduct($product, ['active' => false], $this->owner->id);
    }

    public function test_can_deactivate_product_with_zero_stock(): void
    {
        $product = $this->createTrackedProduct('PRD-DEACT-ZERO');
        $this->actingAs($this->owner);

        $catalog = app(ProductCatalogService::class);
        $updated = $catalog->updateProduct($product, ['active' => false], $this->owner->id);

        $this->assertFalse($updated->active);
    }

    public function test_cannot_deactivate_warehouse_with_positive_stock(): void
    {
        $product = $this->createTrackedProduct('PRD-WH-DEACT');
        $this->actingAs($this->owner);

        $secondWarehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'WH-2',
            'name_ar' => 'مستودع 2',
            'is_default' => false,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $secondWarehouse,
            quantity: Quantity::of(10),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'OS-WH2-01',
        );

        $catalog = app(ProductCatalogService::class);

        $this->expectException(InvalidInventoryMovementException::class);
        $this->expectExceptionMessage('Cannot deactivate warehouse');

        $catalog->deactivateWarehouse($this->company, $secondWarehouse);
    }

    public function test_cannot_deactivate_unit_used_as_base_by_active_product(): void
    {
        $product = $this->createTrackedProduct('PRD-UNIT-DEACT');
        $this->actingAs($this->owner);

        $catalog = app(ProductCatalogService::class);

        $this->expectException(InvalidInventoryMovementException::class);
        $this->expectExceptionMessage('Cannot deactivate unit');

        $catalog->deactivateUnit($this->company, $this->unitPiece);
    }

    // ==========================================
    // 7. Expiry Boundary Validation
    // ==========================================

    public function test_expiry_boundary_valid_on_expiry_day_and_expired_next_day(): void
    {
        $product = $this->createTrackedProduct('PRD-EXP-BOUND', trackExpiry: true);
        $this->actingAs($this->owner);

        // Receive stock with expiry date 2026-10-15
        $expiryDate = '2026-10-15';
        $res = app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(10),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'OS-EXP-01',
            lotNumber: 'LOT-EXP-BOUND',
            expiryDate: $expiryDate,
            movementDate: '2026-10-01',
        );

        $lot = InventoryLot::where('product_id', $product->id)->firstOrFail();
        $service = app(InventoryMovementService::class);

        // 1. On expiry day (2026-10-15):
        // Expiry disposal is FORBIDDEN (lot is not expired yet)
        try {
            $service->record(new StockMovementCommand(
                companyId: $this->company->id,
                movementType: StockMovement::TYPE_EXPIRY_DISPOSAL,
                movementDate: '2026-10-15',
                lines: [
                    new StockMovementLineCommand(
                        productId: $product->id,
                        warehouseId: $this->warehouse->id,
                        quantity: Quantity::of(1),
                        lotId: $lot->id,
                    ),
                ],
                sourceType: 'stock_disposal',
                sourceId: $this->company->id,
                idempotencyKey: 'DISP-ON-DAY',
                createdBy: $this->owner->id,
                reason: 'Disposal on expiry day',
            ));
            $this->fail('Expected InvalidInventoryMovementException: disposal on expiry day must be rejected');
        } catch (InvalidInventoryMovementException $e) {
            $this->assertStringContainsString('is not expired as of movement date', $e->getMessage());
        }

        // Normal consumption on expiry day (2026-10-15) is ALLOWED
        $useMovement = $service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_DECREASE,
            movementDate: '2026-10-15',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(2),
                    lotId: $lot->id,
                ),
            ],
            sourceType: 'stock_adjustment',
            sourceId: $this->company->id,
            idempotencyKey: 'USE-ON-DAY',
            createdBy: $this->owner->id,
            reason: 'Normal use on expiry day',
        ));
        $this->assertCount(1, $useMovement);

        // 2. Next day (2026-10-16):
        // Normal consumption is FORBIDDEN (lot is expired)
        try {
            $service->record(new StockMovementCommand(
                companyId: $this->company->id,
                movementType: StockMovement::TYPE_ADJUSTMENT_DECREASE,
                movementDate: '2026-10-16',
                lines: [
                    new StockMovementLineCommand(
                        productId: $product->id,
                        warehouseId: $this->warehouse->id,
                        quantity: Quantity::of(1),
                        lotId: $lot->id,
                    ),
                ],
                sourceType: 'stock_adjustment',
                sourceId: $this->company->id,
                idempotencyKey: 'USE-AFTER-EXP',
                createdBy: $this->owner->id,
                reason: 'Normal use after expiry',
            ));
            $this->fail('Expected InvalidInventoryMovementException: normal consumption after expiry must be rejected');
        } catch (InvalidInventoryMovementException $e) {
            $this->assertStringContainsString('has expired as of', $e->getMessage());
        }

        // Expiry disposal on next day (2026-10-16) is ALLOWED
        $dispMovement = $service->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_EXPIRY_DISPOSAL,
            movementDate: '2026-10-16',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(8),
                    lotId: $lot->id,
                ),
            ],
            sourceType: 'stock_disposal',
            sourceId: $this->company->id,
            idempotencyKey: 'DISP-NEXT-DAY',
            createdBy: $this->owner->id,
            reason: 'Disposal after expiry date',
        ));
        $this->assertCount(1, $dispMovement);
    }

    // ==========================================
    // 8. Reconciliation Provenance and Master Data
    // ==========================================

    public function test_reconciliation_detects_provenance_and_master_data_discrepancies(): void
    {
        $product = $this->createTrackedProduct('PRD-RECON-01');
        $this->actingAs($this->owner);

        // Record a valid opening stock
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(10),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'OS-RECON-INIT',
        );

        $reconciliation = app(InventoryReconciliationService::class);
        $cleanReport = $reconciliation->auditCompany($this->company);
        $this->assertTrue($cleanReport->isHealthy);
        $this->assertEmpty($cleanReport->discrepancies);

        // Introduce deliberate master data discrepancy: mark active product inactive while having positive stock
        $product->update(['active' => false]);

        $reportWithDiscrepancy = $reconciliation->auditCompany($this->company);
        $this->assertFalse($reportWithDiscrepancy->isHealthy);
        $this->assertNotEmpty($reportWithDiscrepancy->cacheDiscrepancies);
        $this->assertStringContainsString('marked inactive', $reportWithDiscrepancy->cacheDiscrepancies[0]);
    }

    // ==========================================
    // 9. Base Unit Pristine Integrity & Configuration Validation
    // ==========================================

    public function test_base_unit_change_rejects_missing_or_corrupt_current_base_configuration(): void
    {
        $product = $this->createTrackedProduct('PRD-BASE-HEALTH');

        // 1. Missing current base ProductUnit row
        ProductUnit::where('product_id', $product->id)->delete();
        $thrown = false;
        try {
            app(ProductCatalogService::class)->updateProduct($product, ['base_unit_id' => $this->unitBox->id], $this->owner->id);
        } catch (InvalidUnitConversionException $e) {
            $thrown = true;
            $this->assertStringContainsString('expected exactly 1 base ProductUnit', $e->getMessage());
        }
        $this->assertTrue($thrown, 'Base change must fail closed when current base ProductUnit is missing.');
        $this->assertSame($this->unitPiece->id, $product->fresh()->base_unit_id);
        $this->assertSame(0, ProductUnit::where('product_id', $product->id)->count());

        // Restore healthy base
        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
        ]);

        // 2. Corrupt conversion factor on current base row
        ProductUnit::where('product_id', $product->id)->update(['conversion_to_base' => '2.500000']);
        $thrown = false;
        try {
            app(ProductCatalogService::class)->updateProduct($product, ['base_unit_id' => $this->unitBox->id], $this->owner->id);
        } catch (InvalidUnitConversionException $e) {
            $thrown = true;
            $this->assertStringContainsString('invalid conversion_to_base', $e->getMessage());
        }
        $this->assertTrue($thrown, 'Base change must fail closed when current base factor != 1.');
        $this->assertSame($this->unitPiece->id, $product->fresh()->base_unit_id);
        $this->assertSame('2.500000', ProductUnit::where('product_id', $product->id)->firstOrFail()->conversion_to_base);

        // 3. Inactive current base row
        ProductUnit::where('product_id', $product->id)->update([
            'conversion_to_base' => '1.000000',
            'active' => false,
        ]);
        $thrown = false;
        try {
            app(ProductCatalogService::class)->updateProduct($product, ['base_unit_id' => $this->unitBox->id], $this->owner->id);
        } catch (InvalidUnitConversionException $e) {
            $thrown = true;
            $this->assertStringContainsString('base ProductUnit is inactive', $e->getMessage());
        }
        $this->assertTrue($thrown, 'Base change must fail closed when current base row is inactive.');
        $this->assertSame($this->unitPiece->id, $product->fresh()->base_unit_id);

        // 4. Healthy pristine base switch succeeds
        ProductUnit::where('product_id', $product->id)->update(['active' => true]);
        $updated = app(ProductCatalogService::class)->updateProduct($product, ['base_unit_id' => $this->unitBox->id], $this->owner->id);
        $this->assertSame($this->unitBox->id, $updated->base_unit_id);
        $newBase = ProductUnit::where('product_id', $product->id)->firstOrFail();
        $this->assertSame($this->unitBox->id, $newBase->unit_id);
        $this->assertTrue($newBase->is_base);
        $this->assertSame('1.000000', $newBase->conversion_to_base);
    }

    // ==========================================
    // 10. Explicit Zero Cost Intent in StockAdjustmentForm
    // ==========================================

    public function test_adjustment_form_preserves_explicit_zero_cost_on_pristine_product(): void
    {
        $product = $this->createTrackedProduct('PRD-ADJ-ZERO-PRISTINE');
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(StockAdjustmentForm::class)
            ->set('product_id', $product->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->set('type', StockMovement::TYPE_ADJUSTMENT_INCREASE)
            ->set('quantity', '5')
            ->set('unit_cost_base', '0')
            ->set('reason', 'Deliberate zero acquisition value')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('errorMessage', null);

        $this->assertSame(1, StockMovement::count());
        $m = StockMovement::firstOrFail();
        $this->assertSame('5.000000', $m->quantity_delta_base);
        $this->assertSame('0.000000', $m->unit_cost_base);
        $this->assertSame('0.000000', $m->value_delta_base);

        // Zero-value adjustment does not generate an empty GL batch
        $this->assertSame(0, PostingBatch::count());

        $cs = InventoryCostState::where('product_id', $product->id)->firstOrFail();
        $this->assertSame('5.000000', $cs->quantity_base);
        $this->assertSame('0.000000', $cs->average_cost_base);
        $this->assertSame('0.000000', $cs->inventory_value_base);
    }

    public function test_adjustment_form_preserves_explicit_zero_cost_on_positive_cost_basis(): void
    {
        $product = $this->createTrackedProduct('PRD-ADJ-ZERO-DILUTE');

        // Existing basis: 10 units @ 20.000000 = 200.000000
        $this->actingAs($this->owner);
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(10),
            unitCostBase: '20.000000',
            user: $this->owner,
            idempotencyKey: 'OS-DILUTE-INIT',
        );

        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // Deliberate zero-cost increase of 10 units: value added = 0, new total qty = 20, new avg = 10.000000
        Livewire::actingAs($this->owner)
            ->test(StockAdjustmentForm::class)
            ->set('product_id', $product->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->set('type', StockMovement::TYPE_ADJUSTMENT_INCREASE)
            ->set('quantity', '10')
            ->set('unit_cost_base', '0.000000')
            ->set('reason', 'Zero cost dilution')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('errorMessage', null);

        $this->assertSame(2, StockMovement::count());
        $m = StockMovement::orderBy('id', 'desc')->firstOrFail();
        $this->assertSame('10.000000', $m->quantity_delta_base);
        $this->assertSame('0.000000', $m->unit_cost_base);
        $this->assertSame('0.000000', $m->value_delta_base);

        $cs = InventoryCostState::where('product_id', $product->id)->firstOrFail();
        $this->assertSame('20.000000', $cs->quantity_base);
        $this->assertSame('10.000000', $cs->average_cost_base);
        $this->assertSame('200.000000', $cs->inventory_value_base);
    }

    public function test_adjustment_form_denies_explicit_zero_without_cost_view(): void
    {
        $product = $this->createTrackedProduct('PRD-ADJ-ZERO-UNAUTH');
        app(CompanyContext::class)->setCompany($this->company, $this->warehouseStaff);

        Livewire::actingAs($this->warehouseStaff)
            ->test(StockAdjustmentForm::class)
            ->set('product_id', $product->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->set('type', StockMovement::TYPE_ADJUSTMENT_INCREASE)
            ->set('quantity', '5')
            ->set('unit_cost_base', '0')
            ->set('reason', 'Unauthorized explicit zero')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, InventoryOperation::count());
    }

    // ==========================================
    // 11. Domain Warehouse Update Invariant
    // ==========================================

    public function test_domain_warehouse_update_cannot_create_inactive_default(): void
    {
        $secondaryWarehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'wh-secondary-guard',
            'name_ar' => 'مستودع ثانوي',
            'active' => true,
            'is_default' => false,
            'created_by' => $this->owner->id,
        ]);

        $initialDefaultId = CompanyInventorySettings::where('company_id', $this->company->id)->value('default_warehouse_id');

        // Attempting to set is_default=true and active=false must be rejected
        $thrown = false;
        try {
            app(ProductCatalogService::class)->updateWarehouse($this->company, $secondaryWarehouse, [
                'is_default' => true,
                'active' => false,
            ]);
        } catch (InvalidInventoryMovementException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Domain guard must reject creating or maintaining an inactive default warehouse.');
        $this->assertSame(1, Warehouse::where('company_id', $this->company->id)->where('is_default', true)->where('active', true)->count());
        $this->assertSame($this->warehouse->id, $this->warehouse->fresh()->id);
        $this->assertTrue($this->warehouse->fresh()->is_default);
        $this->assertFalse($secondaryWarehouse->fresh()->is_default);
        $this->assertTrue($secondaryWarehouse->fresh()->active);
        $this->assertSame($initialDefaultId, CompanyInventorySettings::where('company_id', $this->company->id)->value('default_warehouse_id'));
    }

    public function test_domain_warehouse_update_cannot_deactivate_default_warehouse(): void
    {
        $thrown = false;
        try {
            app(ProductCatalogService::class)->updateWarehouse($this->company, $this->warehouse, [
                'active' => false,
            ]);
        } catch (InvalidInventoryMovementException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Cannot deactivate default warehouse.');
        $this->assertTrue($this->warehouse->fresh()->active);
        $this->assertTrue($this->warehouse->fresh()->is_default);
    }

    // ==========================================
    // 12. Reconciliation Operation Kind Validation
    // ==========================================

    public function test_reconciliation_rejects_unknown_operation_types_and_rebuild_refuses(): void
    {
        $product = $this->createTrackedProduct('PRD-RECON-UNKNOWN-OP');
        $this->actingAs($this->owner);

        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(2),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'OS-UNKNOWN-OP-01',
        );

        // Corrupt operation_type
        DB::table('inventory_operations')->where('company_id', $this->company->id)->update(['operation_type' => 'unexpected_kind']);

        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertFalse($report->isHealthy, 'Unknown operation kind must be flagged as history corruption.');
        $this->assertNotEmpty($report->historyCorruptions);
        $this->assertTrue($report->hasHistoryCorruption());

        // Rebuild must refuse corrupt provenance
        $rebuildThrown = false;
        try {
            app(InventoryRebuildService::class)->rebuildForCompany($this->company);
        } catch (\RuntimeException $e) {
            $rebuildThrown = true;
            $this->assertStringContainsString('history corruption detected', $e->getMessage());
        }
        $this->assertTrue($rebuildThrown, 'Rebuild must refuse to execute when history corruption is detected.');
    }

    // ==========================================
    // 13. Multibyte Character Limits
    // ==========================================

    public function test_multibyte_character_limits_for_movement_and_transfer_reasons_and_lots(): void
    {
        $product = $this->createTrackedProduct('PRD-MB-TEXT');

        // 300 Arabic chars (600 bytes) in movement command: valid within VARCHAR(512)
        $arabicReason300 = str_repeat('ع', 300);
        $cmdMove300 = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(1),
                    unitCostBase: '1.000000',
                ),
            ],
            sourceType: 'audit',
            sourceId: 1,
            idempotencyKey: 'mb-move-300',
            createdBy: $this->owner->id,
            reason: $arabicReason300,
        );
        $this->assertSame($arabicReason300, $cmdMove300->reason);

        // 512 Arabic chars: valid boundary
        $arabicReason512 = str_repeat('ع', 512);
        $cmdMove512 = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(1),
                    unitCostBase: '1.000000',
                ),
            ],
            sourceType: 'audit',
            sourceId: 1,
            idempotencyKey: 'mb-move-512',
            createdBy: $this->owner->id,
            reason: $arabicReason512,
        );
        $this->assertSame($arabicReason512, $cmdMove512->reason);

        // 513 Arabic chars: rejected
        $this->expectException(\InvalidArgumentException::class);
        new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(1),
                    unitCostBase: '1.000000',
                ),
            ],
            sourceType: 'audit',
            sourceId: 1,
            idempotencyKey: 'mb-move-513',
            createdBy: $this->owner->id,
            reason: str_repeat('ع', 513),
        );
    }

    public function test_multibyte_character_limits_for_transfer_reason_and_lot_number(): void
    {
        $product = $this->createTrackedProduct('PRD-MB-TRANSFER');

        // 512 Arabic chars in transfer reason: valid
        $arabicReason512 = str_repeat('ن', 512);
        $cmdTrans512 = new StockTransferCommand(
            companyId: $this->company->id,
            sourceWarehouseId: $this->warehouse->id,
            destinationWarehouseId: $this->warehouse->id + 1,
            movementDate: '2026-10-01',
            lines: [
                new StockTransferLineCommand(
                    productId: $product->id,
                    quantity: Quantity::of(1),
                ),
            ],
            sourceType: 'audit',
            sourceId: 1,
            idempotencyKey: 'mb-trans-512',
            createdBy: $this->owner->id,
            reason: $arabicReason512,
        );
        $this->assertSame($arabicReason512, $cmdTrans512->reason);

        // 128 Arabic chars in lot number: valid
        $arabicLot128 = str_repeat('ط', 128);
        $lineWithLot = new StockMovementLineCommand(
            productId: $product->id,
            warehouseId: $this->warehouse->id,
            quantity: Quantity::of(1),
            unitCostBase: '1.000000',
            lotNumber: $arabicLot128,
        );
        $this->assertSame($arabicLot128, $lineWithLot->lotNumber);

        // 129 Arabic chars in lot number: rejected
        $this->expectException(\InvalidArgumentException::class);
        new StockMovementLineCommand(
            productId: $product->id,
            warehouseId: $this->warehouse->id,
            quantity: Quantity::of(1),
            unitCostBase: '1.000000',
            lotNumber: str_repeat('ط', 129),
        );
    }
}
