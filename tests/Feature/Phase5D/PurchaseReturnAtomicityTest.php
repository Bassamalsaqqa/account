<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Posting\AccountingPostingService;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;

class PurchaseReturnAtomicityTest extends Phase5DTestCase
{
    /** Scenario 54: accounting rollback stock/PRT/snapshots/provenance */
    public function test_scenario_54_accounting_rollback_stock_prt_snapshots_provenance(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '15'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '3']],
        ]);

        $beforeState = $this->snapshotState();

        // Bind mock AccountingPostingService that throws
        $mock = Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->andThrow(new RuntimeException('Simulated accounting failure'));
        $this->app->instance(AccountingPostingService::class, $mock);

        try {
            $this->postReturn($draft);
            $this->fail('Expected RuntimeException on accounting failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated accounting failure', $e->getMessage());
        }

        $afterState = $this->snapshotState();
        $this->assertSame($beforeState, $afterState);
    }

    /** Scenario 55: later-line rollback earlier issue */
    public function test_scenario_55_later_line_rollback_earlier_issue(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '20'],
            ],
        ]);

        $line1 = $purchase->lines[0];
        $line2 = $purchase->lines[1];

        // Create return draft with both lines
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                ['purchase_line_id' => $line1->id, 'quantity' => '5'],
                ['purchase_line_id' => $line2->id, 'quantity' => '5'],
            ],
        ]);

        // Now maliciously or via race, deplete the warehouse stock of the product so that line 2 cannot be satisfied
        // Total warehouse stock is 15. Line 1 needs 5, line 2 needs 5.
        // If we reduce warehouse stock to 7 by selling 8 units:
        // Line 1 takes 5 (leaving 2). Line 2 needs 5, but only 2 remain!
        app(InventoryMovementService::class)->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_SALE,
            movementDate: '2026-10-04',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(8),
                ),
            ],
            sourceType: 'sales_invoice',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id,
        ));

        $beforeState = $this->snapshotState();

        try {
            $this->postReturn($draft);
            $this->fail('Expected InsufficientStockException on second line issue.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Insufficient stock', $e->getMessage());
        }

        $afterState = $this->snapshotState();
        $this->assertSame($beforeState, $afterState);
    }

    /** Scenario 56: sequence rollback */
    public function test_scenario_56_sequence_rollback(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '15'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '3']],
        ]);

        $beforeState = $this->snapshotState();

        $mock = Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->andThrow(new RuntimeException('GL failure after numbering'));
        $this->app->instance(AccountingPostingService::class, $mock);

        try {
            $this->postReturn($draft);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('GL failure after numbering', $e->getMessage());
        }

        $afterState = $this->snapshotState();
        $this->assertSame($beforeState['document_sequences'], $afterState['document_sequences']);
    }

    /** Scenario 57: lot balance rollback */
    public function test_scenario_57_lot_balance_rollback(): void
    {
        $purchase = $this->createAndPostExpiryPurchase([
            ['lot_number' => 'LOT-ATOMIC', 'expiry_date' => '2027-12-31', 'quantity' => '10'],
        ]);

        $line = $purchase->lines->first();
        $lot = $line->lots->first();

        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '4',
                    'allocations' => [
                        [
                            'original_stock_movement_id' => $lot->stock_movement_id,
                            'purchase_line_lot_id' => $lot->id,
                            'inventory_lot_id' => $lot->created_inventory_lot_id,
                            'quantity' => '4',
                        ],
                    ],
                ],
            ],
        ]);

        $beforeState = $this->snapshotState();

        $mock = Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->andThrow(new RuntimeException('Accounting failure for expiry return'));
        $this->app->instance(AccountingPostingService::class, $mock);

        try {
            $this->postReturn($draft);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $afterState = $this->snapshotState();
        $this->assertSame($beforeState['inventory_lot_balances'], $afterState['inventory_lot_balances']);
        $this->assertSame($beforeState['inventory_lots'], $afterState['inventory_lots']);
    }

    /** Scenario 58: cost state rollback */
    public function test_scenario_58_cost_state_rollback(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '25'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '5']],
        ]);

        $beforeState = $this->snapshotState();

        $mock = Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->andThrow(new RuntimeException('GL failure rolling back cost state'));
        $this->app->instance(AccountingPostingService::class, $mock);

        try {
            $this->postReturn($draft);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $afterState = $this->snapshotState();
        $this->assertSame($beforeState['inventory_cost_states'], $afterState['inventory_cost_states']);
    }

    /** Scenario 59: identity snapshot rollback */
    public function test_scenario_59_identity_snapshot_rollback(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);

        $beforeState = $this->snapshotState();

        $mock = Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->andThrow(new RuntimeException('Simulated failure during posting'));
        $this->app->instance(AccountingPostingService::class, $mock);

        try {
            $this->postReturn($draft);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $afterState = $this->snapshotState();
        $this->assertSame($beforeState['purchase_returns'], $afterState['purchase_returns']);
    }
}
