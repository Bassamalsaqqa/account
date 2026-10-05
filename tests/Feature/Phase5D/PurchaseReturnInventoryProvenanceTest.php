<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PurchaseReturnInventoryProvenanceTest extends Phase5DTestCase
{
    /** Scenario 24: non-expiry original movement */
    public function test_scenario_24_non_expiry_original_movement(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '15'],
        ]]);

        $receiptMovement = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase')
            ->where('source_id', $purchase->id)
            ->sole();

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                ['purchase_line_id' => $line->id, 'quantity' => '3'],
            ],
        ]);

        $posted = $this->postReturn($draft);

        $outboundMovement = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase_return')
            ->where('source_id', $posted->id)
            ->sole();

        $this->assertSame((int) $receiptMovement->id, (int) $outboundMovement->reversal_of_id);
        $this->assertSame(StockMovement::TYPE_PURCHASE_RETURN, $outboundMovement->movement_type);
        $this->assertNull($outboundMovement->lot_id);

        $returnLine = $posted->lines->sole();
        $this->assertSame((int) $outboundMovement->id, (int) $returnLine->stock_movement_id);

        $allocation = $returnLine->allocations->sole();
        $this->assertSame((int) $receiptMovement->id, (int) $allocation->original_stock_movement_id);
        $this->assertSame((int) $outboundMovement->id, (int) $allocation->stock_movement_id);
        $this->assertNull($allocation->inventory_lot_id);
    }

    /** Scenario 25: expiry exact lot/movement */
    public function test_scenario_25_expiry_exact_lot_movement(): void
    {
        $purchase = $this->createAndPostExpiryPurchase([
            [
                'lot_number' => 'LOT-EXP-1',
                'expiry_date' => '2027-06-30',
                'quantity' => '6',
            ],
            [
                'lot_number' => 'LOT-EXP-2',
                'expiry_date' => '2027-12-31',
                'quantity' => '4',
            ],
        ]);

        $line = $purchase->lines->first();
        $lot1 = $line->lots->where('lot_number', 'LOT-EXP-1')->first();
        $lot2 = $line->lots->where('lot_number', 'LOT-EXP-2')->first();

        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '5',
                    'allocations' => [
                        [
                            'original_stock_movement_id' => $lot1->stock_movement_id,
                            'purchase_line_lot_id' => $lot1->id,
                            'inventory_lot_id' => $lot1->created_inventory_lot_id,
                            'quantity' => '3',
                        ],
                        [
                            'original_stock_movement_id' => $lot2->stock_movement_id,
                            'purchase_line_lot_id' => $lot2->id,
                            'inventory_lot_id' => $lot2->created_inventory_lot_id,
                            'quantity' => '2',
                        ],
                    ],
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);

        $allocations = $posted->lines->sole()->allocations;
        $this->assertCount(2, $allocations);

        $alloc1 = $allocations->where('purchase_line_lot_id', $lot1->id)->sole();
        $this->assertSame('3.000000', $alloc1->quantity);
        $this->assertSame((int) $lot1->created_inventory_lot_id, (int) $alloc1->inventory_lot_id);
        $this->assertSame((int) $lot1->stock_movement_id, (int) $alloc1->original_stock_movement_id);

        $alloc2 = $allocations->where('purchase_line_lot_id', $lot2->id)->sole();
        $this->assertSame('2.000000', $alloc2->quantity);
        $this->assertSame((int) $lot2->created_inventory_lot_id, (int) $alloc2->inventory_lot_id);
        $this->assertSame((int) $lot2->stock_movement_id, (int) $alloc2->original_stock_movement_id);

        $movements = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase_return')
            ->where('source_id', $posted->id)
            ->get();
        $this->assertCount(2, $movements);
    }

    /** Scenario 26: expired original lot return allowed */
    public function test_scenario_26_expired_original_lot_return_allowed(): void
    {
        $purchase = $this->createAndPostExpiryPurchase([
            [
                'lot_number' => 'LOT-EXPIRED',
                'expiry_date' => '2020-01-01', // Expired
                'quantity' => '10',
            ],
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

        $posted = $this->postReturn($draft);

        $this->assertSame(PurchaseReturn::STATUS_POSTED, $posted->status);
        $this->assertSame('4.000000', $posted->lines->sole()->quantity);
    }

    /** Scenario 27: other lot cannot substitute */
    public function test_scenario_27_other_lot_cannot_substitute(): void
    {
        $purchase1 = $this->createAndPostExpiryPurchase([
            ['lot_number' => 'LOT-AAA', 'expiry_date' => '2027-01-01', 'quantity' => '10'],
        ]);
        $purchase2 = $this->createAndPostExpiryPurchase([
            ['lot_number' => 'LOT-BBB', 'expiry_date' => '2027-01-01', 'quantity' => '10'],
        ]);

        $line1 = $purchase1->lines->first();
        $foreignLot = $purchase2->lines->first()->lots->first();

        $this->expectException(InvalidArgumentException::class);
        $this->createReturnDraft($purchase1, [
            'lines' => [
                [
                    'purchase_line_id' => $line1->id,
                    'quantity' => '2',
                    'allocations' => [
                        [
                            'original_stock_movement_id' => $foreignLot->stock_movement_id,
                            'purchase_line_lot_id' => $foreignLot->id,
                            'inventory_lot_id' => $foreignLot->created_inventory_lot_id,
                            'quantity' => '2',
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** Scenario 28: insufficient lot rejected */
    public function test_scenario_28_insufficient_lot_rejected(): void
    {
        $purchase = $this->createAndPostExpiryPurchase([
            ['lot_number' => 'LOT-SHORT', 'expiry_date' => '2027-01-01', 'quantity' => '10'],
        ]);

        $line = $purchase->lines->first();
        $lot = $line->lots->first();

        // Reduce available balance in warehouse by transferring 8 units out to another warehouse
        $warehouseB = Warehouse::create([
            'company_id' => $this->company->id,
            'name_ar' => 'فرع آخر',
            'code' => 'WH-OTHER',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        app(InventoryMovementService::class)->transfer(new StockTransferCommand(
            companyId: $this->company->id,
            sourceWarehouseId: $this->warehouse->id,
            destinationWarehouseId: $warehouseB->id,
            movementDate: '2026-10-04',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->expiryProduct->id,
                    quantity: Quantity::of(8),
                    unitId: $this->expiryUnit->unit_id,
                    lotId: $lot->created_inventory_lot_id,
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id,
        ));

        // Now only 2 units remain in warehouse A for this lot.
        // Attempting to return 5 units from this lot must fail
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '5',
                    'allocations' => [
                        [
                            'original_stock_movement_id' => $lot->stock_movement_id,
                            'purchase_line_lot_id' => $lot->id,
                            'inventory_lot_id' => $lot->created_inventory_lot_id,
                            'quantity' => '5',
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(InsufficientStockException::class);
        $this->postReturn($draft);
    }

    /** Scenario 29: transferred stock fails until back in original warehouse */
    public function test_scenario_29_transferred_stock_fails_until_back_in_original_warehouse(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
        ]]);

        $warehouseB = Warehouse::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مستودع التحويل',
            'code' => 'WH-TRANS',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $service = app(InventoryMovementService::class);

        // Transfer 9 units out of original warehouse (only 1 unit left in original)
        $service->transfer(new StockTransferCommand(
            companyId: $this->company->id,
            sourceWarehouseId: $this->warehouse->id,
            destinationWarehouseId: $warehouseB->id,
            movementDate: '2026-10-04',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->product->id,
                    quantity: Quantity::of(9),
                    unitId: $this->unit->unit_id,
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id,
        ));

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                ['purchase_line_id' => $line->id, 'quantity' => '5'],
            ],
        ]);

        // Attempting to return 5 units from original warehouse fails because only 1 is present there
        try {
            $this->postReturn($draft);
            $this->fail('Expected InsufficientStockException in original warehouse.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Insufficient stock', $e->getMessage());
        }

        // Transfer 5 units back from warehouse B to original warehouse
        $service->transfer(new StockTransferCommand(
            companyId: $this->company->id,
            sourceWarehouseId: $warehouseB->id,
            destinationWarehouseId: $this->warehouse->id,
            movementDate: '2026-10-04',
            lines: [
                new StockTransferLineCommand(
                    productId: $this->product->id,
                    quantity: Quantity::of(5),
                    unitId: $this->unit->unit_id,
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 2,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id,
        ));

        // Now return succeeds
        $posted = $this->postReturn($draft);
        $this->assertSame(PurchaseReturn::STATUS_POSTED, $posted->status);
    }

    /** Scenario 30: inactive stock Product allowed */
    public function test_scenario_30_inactive_stock_product_allowed(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
        ]]);

        // Deactivate product
        $this->product->update(['active' => false]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                ['purchase_line_id' => $line->id, 'quantity' => '3'],
            ],
        ]);

        $posted = $this->postReturn($draft);
        $this->assertSame(PurchaseReturn::STATUS_POSTED, $posted->status);
    }

    /** Scenario 31: inactive Vendor allowed */
    public function test_scenario_31_inactive_vendor_allowed(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
        ]]);

        // Deactivate vendor
        $this->vendor->update(['status' => 'inactive']);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                ['purchase_line_id' => $line->id, 'quantity' => '3'],
            ],
        ]);

        $posted = $this->postReturn($draft);
        $this->assertSame(PurchaseReturn::STATUS_POSTED, $posted->status);
    }

    /** Scenario 32: inactive warehouse fails */
    public function test_scenario_32_inactive_warehouse_fails(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
        ]]);

        // Deactivate warehouse
        $this->warehouse->update(['active' => false]);

        $line = $purchase->lines->first();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('warehouse is inactive');
        $this->createReturnDraft($purchase, [
            'lines' => [
                ['purchase_line_id' => $line->id, 'quantity' => '3'],
            ],
        ]);
    }

    /** Scenario 33: original movement cumulative over-return rejected */
    public function test_scenario_33_original_movement_cumulative_over_return_rejected(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
        ]]);

        $line = $purchase->lines->first();

        // Return 1: 6 units posted
        $r1 = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '6']],
        ]);
        $this->postReturn($r1);

        // Return 2: attempt 5 units (6 + 5 = 11 > 10)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds remaining returnable quantity');
        $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '5']],
        ]);
    }

    /** C2-3: Return cannot deplete another original lot while referencing receipt A */
    public function test_c2_3_return_cannot_deplete_other_original_lot(): void
    {
        $purchase = $this->createAndPostExpiryPurchase([
            ['lot_number' => 'LOT-A', 'expiry_date' => '2026-12-31', 'quantity' => '5'],
            ['lot_number' => 'LOT-B', 'expiry_date' => '2026-12-31', 'quantity' => '5'],
        ]);
        $draft = $this->createReturnDraft($purchase);
        $wrongLot = $purchase->lines->first()->lots->last()->created_inventory_lot_id;

        DB::table('purchase_return_allocations')
            ->where('purchase_return_id', $draft->id)
            ->update(['inventory_lot_id' => $wrongLot]);

        $before = $this->snapshotState();
        $error = null;

        try {
            $this->postReturn($draft);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Return retained original receipt A but depleted stock lot B.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C2-3: Non-expiry allocation cannot carry lot fields */
    public function test_c2_3_non_expiry_allocation_cannot_carry_lot_fields(): void
    {
        $expiryPurchase = $this->createAndPostExpiryPurchase();
        $validLotId = $expiryPurchase->lines->first()->lots->first()->created_inventory_lot_id;

        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);

        DB::table('purchase_return_allocations')
            ->where('purchase_return_id', $draft->id)
            ->update(['inventory_lot_id' => $validLotId]);

        $before = $this->snapshotState();
        $error = null;

        try {
            $this->postReturn($draft);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Non-expiry allocation with injected lot ID was accepted.');
        $this->assertSame($before, $this->snapshotState());
    }
}
