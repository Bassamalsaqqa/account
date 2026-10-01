<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InventorySecurityException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryOperation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryIdempotencyAndTenantBoundariesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $companyA;

    protected Company $companyB;

    protected Warehouse $warehouseA1;

    protected Warehouse $warehouseA2;

    protected Unit $unitPiece;

    protected Product $productStandard;

    protected Product $productExpiry;

    protected InventoryMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['locale' => 'ar']);

        // Create Company A
        $this->companyA = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة أ للاختبارات',
            'base_currency_code' => 'ILS',
        ]);

        // Create Company B (clear context first)
        $this->companyB = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة ب للاختبارات',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->companyA, $this->owner);

        $this->warehouseA1 = Warehouse::where('company_id', $this->companyA->id)->where('is_default', true)->firstOrFail();
        $this->warehouseA2 = Warehouse::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'مستودع فرعي أ2',
            'code' => 'WH-A2',
            'is_default' => false,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $this->unitPiece = Unit::where('company_id', $this->companyA->id)->where('code', 'piece')->firstOrFail();

        $this->actingAs($this->owner);

        $this->productStandard = Product::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'منتج قياسي',
            'sku' => 'PROD-STD-1',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->productStandard->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
        ]);

        $this->productExpiry = Product::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'منتج بصلاحية',
            'sku' => 'PROD-EXP-1',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->productExpiry->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
        ]);

        $this->service = app(InventoryMovementService::class);
    }

    public function test_exact_retry_of_multiline_command_returns_identical_movements_without_duplication(): void
    {
        $idempotencyKey = (string) Str::ulid();

        $command = new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(50),
                    unitCostBase: '10.000000',
                ),
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA2->id,
                    quantity: Quantity::of(30),
                    unitCostBase: '12.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 101,
            idempotencyKey: $idempotencyKey,
            createdBy: $this->owner->id,
            reason: 'Opening stock batch'
        );

        // First execution
        $movements1 = $this->service->record($command);
        $this->assertCount(2, $movements1);
        $this->assertSame(2, StockMovement::where('company_id', $this->companyA->id)->count());

        // Exact retry with identical payload and key
        $movements2 = $this->service->record($command);
        $this->assertCount(2, $movements2);

        // Verify returned IDs match original
        $this->assertSame($movements1[0]->id, $movements2[0]->id);
        $this->assertSame($movements1[1]->id, $movements2[1]->id);

        // No new rows inserted
        $this->assertSame(2, StockMovement::where('company_id', $this->companyA->id)->count());

        // Balance remains 80 total
        $costState = InventoryCostState::where('product_id', $this->productStandard->id)->firstOrFail();
        $this->assertSame('80.000000', $costState->quantity_base);
    }

    public function test_exact_retry_of_transfer_command_returns_identical_paired_movements(): void
    {
        // First deposit 100 units in warehouse A1
        $this->service->record(new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(100),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 102,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id
        ));

        $transferKey = (string) Str::ulid();
        $transferCommand = new StockTransferCommand(
            companyId: $this->companyA->id,
            sourceWarehouseId: $this->warehouseA1->id,
            destinationWarehouseId: $this->warehouseA2->id,
            movementDate: '2026-10-02',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->productStandard->id,
                    quantity: Quantity::of(40)
                ),
            ],
            idempotencyKey: $transferKey,
            createdBy: $this->owner->id,
            reason: 'Replenish branch'
        );

        $transferMovements1 = $this->service->transfer($transferCommand);
        $this->assertCount(2, $transferMovements1); // 1 OUT, 1 IN

        // Exact retry
        $transferMovements2 = $this->service->transfer($transferCommand);
        $this->assertCount(2, $transferMovements2);
        $this->assertSame($transferMovements1[0]->id, $transferMovements2[0]->id);
        $this->assertSame($transferMovements1[1]->id, $transferMovements2[1]->id);

        // Balances: warehouse A1 has 60, warehouse A2 has 40
        $bal1 = InventoryBalance::where('product_id', $this->productStandard->id)->where('warehouse_id', $this->warehouseA1->id)->firstOrFail();
        $bal2 = InventoryBalance::where('product_id', $this->productStandard->id)->where('warehouse_id', $this->warehouseA2->id)->firstOrFail();
        $this->assertSame('60.000000', $bal1->quantity_base);
        $this->assertSame('40.000000', $bal2->quantity_base);
    }

    public function test_multiline_retry_with_changed_quantity_is_rejected(): void
    {
        $idempotencyKey = (string) Str::ulid();

        $commandOriginal = new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(50),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 103,
            idempotencyKey: $idempotencyKey,
            createdBy: $this->owner->id
        );

        $this->service->record($commandOriginal);

        // Retry with changed quantity (55 instead of 50)
        $commandModified = new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(55),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 103,
            idempotencyKey: $idempotencyKey,
            createdBy: $this->owner->id
        );

        $this->expectException(IdempotencyConflictException::class);
        $this->service->record($commandModified);
    }

    public function test_retry_with_changed_cost_is_rejected(): void
    {
        $idempotencyKey = (string) Str::ulid();

        $this->service->record(new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 104,
            idempotencyKey: $idempotencyKey,
            createdBy: $this->owner->id
        ));

        $commandModified = new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '15.000000', // changed cost
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 104,
            idempotencyKey: $idempotencyKey,
            createdBy: $this->owner->id
        );

        $this->expectException(IdempotencyConflictException::class);
        $this->service->record($commandModified);
    }

    public function test_transfer_retry_with_changed_destination_is_rejected(): void
    {
        $this->service->record(new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(50),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 105,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id
        ));

        $transferKey = (string) Str::ulid();

        $this->service->transfer(new StockTransferCommand(
            companyId: $this->companyA->id,
            sourceWarehouseId: $this->warehouseA1->id,
            destinationWarehouseId: $this->warehouseA2->id,
            movementDate: '2026-10-02',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->productStandard->id,
                    quantity: Quantity::of(10)
                ),
            ],
            idempotencyKey: $transferKey,
            createdBy: $this->owner->id
        ));

        // Create third warehouse to test destination mismatch
        $warehouseA3 = Warehouse::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'مستودع فرعي أ3',
            'code' => 'WH-A3',
            'is_default' => false,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $this->expectException(IdempotencyConflictException::class);
        $this->service->transfer(new StockTransferCommand(
            companyId: $this->companyA->id,
            sourceWarehouseId: $this->warehouseA1->id,
            destinationWarehouseId: $warehouseA3->id, // changed destination
            movementDate: '2026-10-02',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->productStandard->id,
                    quantity: Quantity::of(10)
                ),
            ],
            idempotencyKey: $transferKey,
            createdBy: $this->owner->id
        ));
    }

    public function test_direct_db_uniqueness_backstop_prevents_duplicate_key_insertion(): void
    {
        $key = (string) Str::ulid();

        $op = InventoryOperation::create([
            'company_id' => $this->companyA->id,
            'operation_type' => 'movement',
            'idempotency_key' => $key,
            'request_hash' => hash('sha256', 'test_key_'.$key),
            'line_count' => 1,
            'created_by' => $this->owner->id,
        ]);

        // Directly insert row into stock_movements
        StockMovement::create([
            'company_id' => $this->companyA->id,
            'inventory_operation_id' => $op->id,
            'product_id' => $this->productStandard->id,
            'warehouse_id' => $this->warehouseA1->id,
            'movement_type' => StockMovement::TYPE_OPENING_BALANCE,
            'movement_date' => '2026-10-01',
            'quantity_delta_base' => '10.000000',
            'unit_cost_base' => '5.000000',
            'value_delta_base' => '50.000000',
            'average_cost_after' => '5.000000',
            'quantity_after_product_company' => '10.000000',
            'source_type' => 'manual',
            'source_id' => 1,
            'idempotency_key' => $key,
            'created_by' => $this->owner->id,
            'created_at' => now(),
        ]);

        // Attempt duplicate insert with same (company_id, idempotency_key)
        $this->expectException(UniqueConstraintViolationException::class);
        StockMovement::create([
            'company_id' => $this->companyA->id,
            'inventory_operation_id' => $op->id,
            'product_id' => $this->productStandard->id,
            'warehouse_id' => $this->warehouseA1->id,
            'movement_type' => StockMovement::TYPE_OPENING_BALANCE,
            'movement_date' => '2026-10-01',
            'quantity_delta_base' => '10.000000',
            'unit_cost_base' => '5.000000',
            'value_delta_base' => '50.000000',
            'average_cost_after' => '5.000000',
            'quantity_after_product_company' => '20.000000',
            'source_type' => 'manual',
            'source_id' => 2,
            'idempotency_key' => $key, // Duplicate
            'created_by' => $this->owner->id,
            'created_at' => now(),
        ]);
    }

    public function test_foreign_actor_is_strictly_rejected_with_zero_writes(): void
    {
        $foreignUser = User::factory()->create(['locale' => 'ar']);
        // foreignUser has owner role but is NOT a member in company_user for Company A
        setPermissionsTeamId($this->companyA->id);
        $foreignUser->assignRole('owner');
        $this->actingAs($foreignUser);

        try {
            $this->service->record(new StockMovementCommand(
                companyId: $this->companyA->id,
                movementType: StockMovement::TYPE_OPENING_BALANCE,
                movementDate: '2026-10-01',
                lines: [
                    new StockMovementLineCommand(
                        productId: $this->productStandard->id,
                        warehouseId: $this->warehouseA1->id,
                        quantity: Quantity::of(20),
                        unitCostBase: '10.000000',
                    ),
                ],
                sourceType: 'opening_balance',
                sourceId: 106,
                idempotencyKey: (string) Str::ulid(),
                createdBy: $foreignUser->id // Foreign user
            ));
            $this->fail('Expected InvalidInventoryMovementException was not thrown.');
        } catch (InvalidInventoryMovementException $e) {
            $this->assertStringContainsString('not an active member', $e->getMessage());
        }

        // Reachable assertion: Zero movements inserted
        $this->assertSame(0, StockMovement::where('company_id', $this->companyA->id)->count());
    }

    public function test_inactive_member_is_rejected_with_zero_writes(): void
    {
        $inactiveMember = User::factory()->create(['locale' => 'ar']);
        CompanyUser::create([
            'company_id' => $this->companyA->id,
            'user_id' => $inactiveMember->id,
            'role' => 'inventory_clerk',
            'status' => 'suspended', // Inactive status
        ]);
        setPermissionsTeamId($this->companyA->id);
        $inactiveMember->assignRole('owner');
        $this->actingAs($inactiveMember);

        try {
            $this->service->record(new StockMovementCommand(
                companyId: $this->companyA->id,
                movementType: StockMovement::TYPE_OPENING_BALANCE,
                movementDate: '2026-10-01',
                lines: [
                    new StockMovementLineCommand(
                        productId: $this->productStandard->id,
                        warehouseId: $this->warehouseA1->id,
                        quantity: Quantity::of(20),
                        unitCostBase: '10.000000',
                    ),
                ],
                sourceType: 'opening_balance',
                sourceId: 107,
                idempotencyKey: (string) Str::ulid(),
                createdBy: $inactiveMember->id
            ));
            $this->fail('Expected InvalidInventoryMovementException was not thrown.');
        } catch (InvalidInventoryMovementException $e) {
            $this->assertStringContainsString('not an active member', $e->getMessage());
        }

        // Reachable assertion: Zero movements inserted
        $this->assertSame(0, StockMovement::where('company_id', $this->companyA->id)->count());
    }

    public function test_impersonating_actor_is_strictly_rejected_with_zero_writes(): void
    {
        $otherMember = User::factory()->create(['locale' => 'ar']);
        CompanyUser::create([
            'company_id' => $this->companyA->id,
            'user_id' => $otherMember->id,
            'role' => 'inventory_clerk',
            'status' => 'active',
        ]);

        // Authenticated as owner, but passing otherMember as createdBy
        $this->actingAs($this->owner);

        try {
            $this->service->record(new StockMovementCommand(
                companyId: $this->companyA->id,
                movementType: StockMovement::TYPE_OPENING_BALANCE,
                movementDate: '2026-10-01',
                lines: [
                    new StockMovementLineCommand(
                        productId: $this->productStandard->id,
                        warehouseId: $this->warehouseA1->id,
                        quantity: Quantity::of(20),
                        unitCostBase: '10.000000',
                    ),
                ],
                sourceType: 'opening_balance',
                sourceId: 109,
                idempotencyKey: (string) Str::ulid(),
                createdBy: $otherMember->id
            ));
            $this->fail('Expected InventorySecurityException was not thrown.');
        } catch (InventorySecurityException $e) {
            $this->assertStringContainsString('cannot post inventory movements on behalf of', $e->getMessage());
        }

        $this->assertSame(0, StockMovement::where('company_id', $this->companyA->id)->count());
    }

    public function test_invalid_movement_type_is_strictly_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a recognized Phase3 canonical type');

        new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: 'unauthorized_fake_sale_type', // Invalid type
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(5),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'manual',
            sourceId: 108,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id
        );
    }

    public function test_idempotency_key_validation_rejects_oversized_whitespace_or_reserved_patterns(): void
    {
        // Oversized (>128)
        try {
            $this->service->record(new StockMovementCommand(
                companyId: $this->companyA->id,
                movementType: StockMovement::TYPE_OPENING_BALANCE,
                movementDate: '2026-10-01',
                lines: [new StockMovementLineCommand(productId: $this->productStandard->id, warehouseId: $this->warehouseA1->id, quantity: Quantity::of(1), unitCostBase: '1.000000')],
                sourceType: 'opening_balance',
                sourceId: 901,
                idempotencyKey: str_repeat('a', 129),
                createdBy: $this->owner->id,
            ));
            $this->fail('Oversized key should be rejected');
        } catch (InvalidInventoryMovementException $e) {
            $this->assertStringContainsString('exceeds maximum length', $e->getMessage());
        }

        // Whitespace/newline
        try {
            $this->service->record(new StockMovementCommand(
                companyId: $this->companyA->id,
                movementType: StockMovement::TYPE_OPENING_BALANCE,
                movementDate: '2026-10-01',
                lines: [new StockMovementLineCommand(productId: $this->productStandard->id, warehouseId: $this->warehouseA1->id, quantity: Quantity::of(1), unitCostBase: '1.000000')],
                sourceType: 'opening_balance',
                sourceId: 902,
                idempotencyKey: "bad key\nnewline",
                createdBy: $this->owner->id,
            ));
            $this->fail('Whitespace key should be rejected');
        } catch (InvalidInventoryMovementException $e) {
            $this->assertStringContainsString('invalid whitespace characters', $e->getMessage());
        }

        // Reserved suffix
        try {
            $this->service->record(new StockMovementCommand(
                companyId: $this->companyA->id,
                movementType: StockMovement::TYPE_OPENING_BALANCE,
                movementDate: '2026-10-01',
                lines: [new StockMovementLineCommand(productId: $this->productStandard->id, warehouseId: $this->warehouseA1->id, quantity: Quantity::of(1), unitCostBase: '1.000000')],
                sourceType: 'opening_balance',
                sourceId: 903,
                idempotencyKey: 'custom-key:0',
                createdBy: $this->owner->id,
            ));
            $this->fail('Reserved child suffix key should be rejected');
        } catch (InvalidInventoryMovementException $e) {
            $this->assertStringContainsString('reserved for child operation keys', $e->getMessage());
        }
    }

    public function test_null_cost_retry_after_cost_is_payload_conflict(): void
    {
        $key = 'cost-conflict-'.Str::ulid();
        $this->service->record(new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [new StockMovementLineCommand(productId: $this->productStandard->id, warehouseId: $this->warehouseA1->id, quantity: Quantity::of(2), unitCostBase: '1.000000')],
            sourceType: 'opening_balance',
            sourceId: 904,
            idempotencyKey: $key,
            createdBy: $this->owner->id,
        ));

        $this->expectException(IdempotencyConflictException::class);
        $this->service->record(new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [new StockMovementLineCommand(productId: $this->productStandard->id, warehouseId: $this->warehouseA1->id, quantity: Quantity::of(2), unitCostBase: null)],
            sourceType: 'opening_balance',
            sourceId: 904,
            idempotencyKey: $key,
            createdBy: $this->owner->id,
        ));
    }

    public function test_unnumbered_expiry_lot_exact_retry_succeeds(): void
    {
        $key = 'unnumbered-lot-'.Str::ulid();
        $command = new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [new StockMovementLineCommand(
                productId: $this->productExpiry->id,
                warehouseId: $this->warehouseA1->id,
                quantity: Quantity::of(2),
                unitCostBase: '1.000000',
                lotNumber: null,
                expiryDate: '2027-03-31'
            )],
            sourceType: 'opening_balance',
            sourceId: 905,
            idempotencyKey: $key,
            createdBy: $this->owner->id,
        );

        $first = $this->service->record($command);
        $retry = $this->service->record($command);
        $this->assertSame($first[0]->id, $retry[0]->id);
    }

    public function test_model_tenant_guards_enforce_boundaries(): void
    {
        // 1. Cross-company category parent
        $foreignCat = CompanyScope::executeWithoutScope(fn () => ProductCategory::create([
            'company_id' => $this->companyB->id,
            'name_ar' => 'B Category',
            'active' => true,
        ]));

        try {
            ProductCategory::create([
                'company_id' => $this->companyA->id,
                'name_ar' => 'A Child',
                'parent_id' => $foreignCat->id,
                'active' => true,
            ]);
            $this->fail('Cross-company category parent should be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('does not belong to the same company', $e->getMessage());
        }

        // 2. Cross-company product base unit
        $foreignUnit = CompanyScope::executeWithoutScope(fn () => Unit::create([
            'company_id' => $this->companyB->id,
            'name_ar' => 'B Unit',
            'code' => 'bunit',
            'active' => true,
        ]));

        try {
            Product::create([
                'company_id' => $this->companyA->id,
                'name_ar' => 'Test Product',
                'base_unit_id' => $foreignUnit->id,
                'product_type' => Product::TYPE_STOCK,
                'created_by' => $this->owner->id,
            ]);
            $this->fail('Cross-company base unit should be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('does not belong to the same company', $e->getMessage());
        }
    }

    // ── Regression: Correction-04 – shape-change idempotency conflicts ────────

    public function test_single_to_multi_line_retry_conflicts(): void
    {
        $key = (string) Str::ulid();

        // First call: 1 line
        $this->service->record(new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '5.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 901,
            idempotencyKey: $key,
            createdBy: $this->owner->id,
        ));

        // Second call: 2 lines with same key → must conflict
        $this->expectException(IdempotencyConflictException::class);

        $this->service->record(new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '5.000000',
                ),
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA2->id,
                    quantity: Quantity::of(5),
                    unitCostBase: '5.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 902,
            idempotencyKey: $key,
            createdBy: $this->owner->id,
        ));
    }

    public function test_multi_to_single_line_retry_conflicts(): void
    {
        $key = (string) Str::ulid();

        // First call: 2 lines
        $this->service->record(new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '5.000000',
                ),
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA2->id,
                    quantity: Quantity::of(5),
                    unitCostBase: '5.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 903,
            idempotencyKey: $key,
            createdBy: $this->owner->id,
        ));

        // Second call: 1 line with same key → must conflict
        $this->expectException(IdempotencyConflictException::class);

        $this->service->record(new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '5.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 904,
            idempotencyKey: $key,
            createdBy: $this->owner->id,
        ));
    }

    public function test_record_key_reused_for_transfer_conflicts(): void
    {
        // First deposit stock so transfer is possible
        $this->service->record(new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(100),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 905,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id,
        ));

        $sharedKey = (string) Str::ulid();

        // Use key for a movement
        $this->service->record(new StockMovementCommand(
            companyId: $this->companyA->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            movementDate: '2026-10-02',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA1->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'adjustment',
            sourceId: 906,
            idempotencyKey: $sharedKey,
            createdBy: $this->owner->id,
        ));

        // Reuse the same key for a transfer → must conflict (cross-kind)
        $this->expectException(IdempotencyConflictException::class);

        $this->service->transfer(new StockTransferCommand(
            companyId: $this->companyA->id,
            sourceWarehouseId: $this->warehouseA1->id,
            destinationWarehouseId: $this->warehouseA2->id,
            movementDate: '2026-10-02',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->productStandard->id,
                    quantity: Quantity::of(5),
                ),
            ],
            idempotencyKey: $sharedKey,
            createdBy: $this->owner->id,
        ));
    }
}
