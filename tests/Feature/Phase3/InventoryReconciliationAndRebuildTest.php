<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\InventoryRebuildService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryReconciliationAndRebuildTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Warehouse $warehouseA;

    protected Warehouse $warehouseB;

    protected Unit $unitPiece;

    protected Product $productStandard;

    protected Product $productExpiry;

    protected InventoryMovementService $movementService;

    protected InventoryReconciliationService $reconciler;

    protected InventoryRebuildService $rebuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة تجارب المطابقة وإعادة البناء',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->warehouseA = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();

        $this->warehouseB = Warehouse::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مستودع تجارب ب',
            'code' => 'WH-TEST-B',
            'is_default' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->actingAs($this->user);

        $this->productStandard = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'معكرونة 500 جم',
            'sku' => 'PASTA-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->productExpiry = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مايونيز برطمان',
            'sku' => 'MAYO-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->movementService = app(InventoryMovementService::class);
        $this->reconciler = app(InventoryReconciliationService::class);
        $this->rebuilder = app(InventoryRebuildService::class);
    }

    public function test_audit_reports_healthy_on_consistent_inventory_state(): void
    {
        // 1. Inbound standard product: 100 @ 10 ILS
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
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

        // 2. Transfer 30 units to Warehouse B
        $this->movementService->transfer(new StockTransferCommand(
            companyId: $this->company->id,
            sourceWarehouseId: $this->warehouseA->id,
            destinationWarehouseId: $this->warehouseB->id,
            movementDate: '2026-10-02',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->productStandard->id,
                    quantity: Quantity::of(30),
                ),
            ],
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
            sourceType: 'warehouse_transfer',
            sourceId: 2,
        ));

        // 3. Inbound expiry product with Lot LOT-M1: 50 @ 4 ILS
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productExpiry->id,
                    warehouseId: $this->warehouseA->id,
                    quantity: Quantity::of(50),
                    unitCostBase: '4.000000',
                    lotNumber: 'LOT-M1',
                    expiryDate: '2027-01-01',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 3,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // Audit via service
        $report = $this->reconciler->auditCompany($this->company);

        $this->assertTrue($report->isHealthy);
        $this->assertEmpty($report->discrepancies);
        // Total valuation: 100 * 10 + 50 * 4 = 1000 + 200 = 1200 ILS
        $this->assertSame('1200.000000', $report->totalValuationBase);

        // Audit via artisan command
        $this->artisan('inventory:reconcile', ['companyPublicId' => $this->company->public_id])
            ->assertExitCode(0);
    }

    public function test_reconciliation_detects_corrupted_balances_and_cost_states(): void
    {
        // 1. Initial movement
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
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

        // 2. Artificially tamper with cached inventory_balances.quantity_base directly in DB
        CompanyScope::executeWithoutScope(function () {
            DB::table('inventory_balances')
                ->where('product_id', $this->productStandard->id)
                ->where('warehouse_id', $this->warehouseA->id)
                ->update(['quantity_base' => '999.000000']); // Corrupted value
        });

        // Audit must detect discrepancy
        $report = $this->reconciler->auditCompany($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertStringContainsString('mismatch', $report->discrepancies[0]);

        // Artisan command must fail with exit code 1
        $this->artisan('inventory:reconcile', ['companyPublicId' => $this->company->public_id])
            ->assertExitCode(1);
    }

    public function test_reconciliation_detects_phantom_cache_row_without_movements(): void
    {
        // Create an un-moved product with an artificial positive balance in cache
        $productPhantom = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'صنف وهمي',
            'sku' => 'PHANTOM-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        // Insert artificial phantom cache row
        InventoryBalance::create([
            'company_id' => $this->company->id,
            'product_id' => $productPhantom->id,
            'warehouse_id' => $this->warehouseA->id,
            'quantity_base' => '50.000000',
        ]);

        $report = $this->reconciler->auditCompany($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertTrue(
            collect($report->discrepancies)->contains(fn ($d) => str_contains($d, 'Phantom balance cache row') || str_contains($d, 'mismatch'))
        );
    }

    public function test_inventory_rebuild_zeroes_phantom_cache_rows(): void
    {
        $productPhantom = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'صنف وهمي للتصفير',
            'sku' => 'PHANTOM-ZERO-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        InventoryBalance::create([
            'company_id' => $this->company->id,
            'product_id' => $productPhantom->id,
            'warehouse_id' => $this->warehouseA->id,
            'quantity_base' => '75.000000',
        ]);

        // Rebuild
        $this->rebuilder->rebuildForCompany($this->company);

        // Phantom balance must be zeroed
        $bal = InventoryBalance::where('product_id', $productPhantom->id)->where('warehouse_id', $this->warehouseA->id)->firstOrFail();
        $this->assertSame('0.000000', $bal->quantity_base);
    }

    public function test_inventory_rebuild_regenerates_caches_from_immutable_movements(): void
    {
        // 1. Inbound 80 @ 15 ILS
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA->id,
                    quantity: Quantity::of(80),
                    unitCostBase: '15.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // 2. Corrupt both balance and cost state in DB
        CompanyScope::executeWithoutScope(function () {
            DB::table('inventory_balances')
                ->where('product_id', $this->productStandard->id)
                ->update(['quantity_base' => '0.000000']);

            DB::table('inventory_cost_states')
                ->where('product_id', $this->productStandard->id)
                ->update([
                    'quantity_base' => '0.000000',
                    'average_cost_base' => '0.000000',
                    'inventory_value_base' => '0.000000',
                ]);
        });

        // Verify corrupted
        $reportCorrupt = $this->reconciler->auditCompany($this->company);
        $this->assertFalse($reportCorrupt->isHealthy);

        // 3. Execute Rebuild via service
        $rebuildStats = $this->rebuilder->rebuildForCompany($this->company);
        $this->assertGreaterThan(0, $rebuildStats['rebuilt_balances']);
        $this->assertGreaterThan(0, $rebuildStats['rebuilt_cost_states']);

        // 4. Verify fully restored to healthy
        $reportHealthy = $this->reconciler->auditCompany($this->company);
        $this->assertTrue($reportHealthy->isHealthy);
        $this->assertEmpty($reportHealthy->discrepancies);
        $this->assertSame('1200.000000', $reportHealthy->totalValuationBase); // 80 * 15

        $restoredBalance = InventoryBalance::where('product_id', $this->productStandard->id)->where('warehouse_id', $this->warehouseA->id)->firstOrFail();
        $this->assertSame('80.000000', $restoredBalance->quantity_base);

        $restoredCost = InventoryCostState::where('product_id', $this->productStandard->id)->firstOrFail();
        $this->assertSame('80.000000', $restoredCost->quantity_base);
        $this->assertSame('15.000000', $restoredCost->average_cost_base);
        $this->assertSame('1200.000000', $restoredCost->inventory_value_base);

        // 5. Test artisan rebuild command
        $this->artisan('inventory:rebuild', ['companyPublicId' => $this->company->public_id])
            ->assertExitCode(0);
    }

    public function test_reconciliation_rejects_system_mode_when_any_ambient_context_is_present(): void
    {
        // 1. Same-company ambient context must be rejected in system mode
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        try {
            $this->reconciler->auditCompany($this->company, fromCli: true);
            $this->fail('Expected RuntimeException for system mode with same-company ambient context.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('System reconciliation mode rejected', $e->getMessage());
        }

        // 2. Different-company ambient context must also be rejected
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة أخرى للتداخل',
            'base_currency_code' => 'USD',
        ]);
        app(CompanyContext::class)->setCompany($otherCompany, $this->user);
        try {
            $this->reconciler->auditCompany($this->company, fromCli: true);
            $this->fail('Expected RuntimeException for system mode with different-company ambient context.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('System reconciliation mode rejected', $e->getMessage());
        }

        // 3. System mode succeeds when ambient context is completely cleared
        app(CompanyContext::class)->clear();
        $report = $this->reconciler->auditCompany($this->company, fromCli: true);
        $this->assertTrue($report->isHealthy);
    }

    public function test_rebuild_rejects_system_mode_when_any_ambient_context_is_present(): void
    {
        // 1. Same-company ambient context must be rejected in system mode
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        try {
            $this->rebuilder->rebuildForCompany($this->company, fromCli: true);
            $this->fail('Expected RuntimeException for rebuild system mode with same-company ambient context.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('System rebuild mode rejected', $e->getMessage());
        }

        // 2. Different-company ambient context must also be rejected
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة أخرى للتداخل 2',
            'base_currency_code' => 'USD',
        ]);
        app(CompanyContext::class)->setCompany($otherCompany, $this->user);
        try {
            $this->rebuilder->rebuildForCompany($this->company, fromCli: true);
            $this->fail('Expected RuntimeException for rebuild system mode with different-company ambient context.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('System rebuild mode rejected', $e->getMessage());
        }

        // 3. System mode succeeds when ambient context is cleared
        app(CompanyContext::class)->clear();
        $stats = $this->rebuilder->rebuildForCompany($this->company, fromCli: true);
        $this->assertIsArray($stats);
    }

    public function test_rebuild_refuses_to_normalize_corrupted_movement_history(): void
    {
        // 1. Initial movement
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '5.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 501,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // 2. Directly corrupt movement value_delta_base in database
        CompanyScope::executeWithoutScope(function () {
            DB::table('stock_movements')
                ->where('company_id', $this->company->id)
                ->where('source_id', 501)
                ->update(['value_delta_base' => '999.000000']); // Corrupted value
        });

        // 3. Rebuild must refuse corrupt history rather than normalizing it
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        try {
            $this->rebuilder->rebuildForCompany($this->company, fromCli: false);
            $this->fail('Expected RuntimeException when rebuilding company with corrupted movement history.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('history corruption detected', $e->getMessage());
        }
    }

    // ── Regression: Correction-04 – outbound unit_cost_base corruption detection

    public function test_reconciliation_detects_corrupted_outbound_unit_cost_snapshot(): void
    {
        // 1. Deposit 10 units at cost 5.00
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '5.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 601,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // 2. Partial outbound (adjustment_decrease of 3 units)
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_DECREASE,
            movementDate: '2026-10-02',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA->id,
                    quantity: Quantity::of(3),
                    unitCostBase: null,
                ),
            ],
            sourceType: 'adjustment',
            sourceId: 602,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // 3. Corrupt the outbound movement's unit_cost_base
        CompanyScope::executeWithoutScope(function () {
            DB::table('stock_movements')
                ->where('company_id', $this->company->id)
                ->where('movement_type', StockMovement::TYPE_ADJUSTMENT_DECREASE)
                ->update(['unit_cost_base' => '99.000000']); // Should be 5.000000
        });

        // 4. Reconciliation must detect corruption
        $report = $this->reconciler->auditCompany($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertNotEmpty($report->historyCorruptions);
    }

    public function test_rebuild_refuses_corrupted_outbound_cost_snapshot(): void
    {
        // 1. Deposit 10 units at cost 5.00
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '5.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 603,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // 2. Outbound 3 units via adjustment_decrease
        $this->movementService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_DECREASE,
            movementDate: '2026-10-02',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->productStandard->id,
                    warehouseId: $this->warehouseA->id,
                    quantity: Quantity::of(3),
                    unitCostBase: null,
                ),
            ],
            sourceType: 'adjustment',
            sourceId: 604,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // 3. Corrupt outbound unit_cost_base
        CompanyScope::executeWithoutScope(function () {
            DB::table('stock_movements')
                ->where('company_id', $this->company->id)
                ->where('movement_type', StockMovement::TYPE_ADJUSTMENT_DECREASE)
                ->update(['unit_cost_base' => '77.000000']);
        });

        // 4. Rebuild must refuse
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        try {
            $this->rebuilder->rebuildForCompany($this->company, fromCli: false);
            $this->fail('Expected RuntimeException for corrupted outbound cost snapshot.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('history corruption detected', $e->getMessage());
        }
    }
}
