<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Models\InventoryCostState;
use App\Models\StockMovement;
use App\Services\Purchasing\PurchaseReturnValuation;
use Brick\Math\BigDecimal;

class PurchaseReturnValuationTest extends Phase5DTestCase
{
    /** Scenario 34: partial exact attributable history */
    public function test_scenario_34_partial_exact_attributable_history(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                ['purchase_line_id' => $line->id, 'quantity' => '3'],
            ],
        ]);

        $posted = $this->postReturn($draft);

        $returnLine = $posted->lines->sole();
        $this->assertSame('30.000000', $returnLine->historical_receipt_value_base);
        $this->assertSame('30.000000', $returnLine->inventory_value_removed_base);
        $this->assertSame('0.000000', $returnLine->valuation_adjustment_base);

        $costState = InventoryCostState::where('product_id', $this->product->id)->sole();
        $this->assertSame('7.000000', $costState->quantity_base);
        $this->assertSame('70.000000', $costState->inventory_value_base);
        $this->assertSame('10.000000', $costState->average_cost_base);
    }

    /** Scenario 35: intervening higher-cost Purchase changes remaining average correctly */
    public function test_scenario_35_intervening_higher_cost_purchase_changes_remaining_average_correctly(): void
    {
        // Purchase 1: 10 @ 10 = 100
        $p1 = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        // Purchase 2: 10 @ 20 = 200 (Total = 20 @ 300, avg = 15)
        $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '20'],
            ],
        ]);

        $costBefore = InventoryCostState::where('product_id', $this->product->id)->sole();
        $this->assertSame('20.000000', $costBefore->quantity_base);
        $this->assertSame('300.000000', $costBefore->inventory_value_base);
        $this->assertSame('15.000000', $costBefore->average_cost_base);

        // Return 5 units from Purchase 1 (historical target = 50)
        $line1 = $p1->lines->first();
        $draft = $this->createReturnDraft($p1, [
            'lines' => [
                ['purchase_line_id' => $line1->id, 'quantity' => '5'],
            ],
        ]);

        $posted = $this->postReturn($draft);

        $returnLine = $posted->lines->sole();
        $this->assertSame('50.000000', $returnLine->historical_receipt_value_base);
        $this->assertSame('50.000000', $returnLine->inventory_value_removed_base);

        $costAfter = InventoryCostState::where('product_id', $this->product->id)->sole();
        $this->assertSame('15.000000', $costAfter->quantity_base);
        $this->assertSame('250.000000', $costAfter->inventory_value_base);
        // Average increases: 250 / 15 = 16.666667
        $this->assertSame('16.666667', $costAfter->average_cost_base);
    }

    /** Scenario 36: lower-cost Purchase likewise */
    public function test_scenario_36_lower_cost_purchase_likewise(): void
    {
        // Purchase 1: 10 @ 20 = 200
        $p1 = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '20'],
            ],
        ]);

        // Purchase 2: 10 @ 10 = 100 (Total = 20 @ 300, avg = 15)
        $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        // Return 5 units from Purchase 1 (historical target = 100)
        $line1 = $p1->lines->first();
        $draft = $this->createReturnDraft($p1, [
            'lines' => [
                ['purchase_line_id' => $line1->id, 'quantity' => '5'],
            ],
        ]);

        $posted = $this->postReturn($draft);

        $returnLine = $posted->lines->sole();
        $this->assertSame('100.000000', $returnLine->historical_receipt_value_base);
        $this->assertSame('100.000000', $returnLine->inventory_value_removed_base);

        $costAfter = InventoryCostState::where('product_id', $this->product->id)->sole();
        $this->assertSame('15.000000', $costAfter->quantity_base);
        $this->assertSame('200.000000', $costAfter->inventory_value_base);
        // Average decreases: 200 / 15 = 13.333333
        $this->assertSame('13.333333', $costAfter->average_cost_base);
    }

    /** Scenario 37: final depletion removes exact old value */
    public function test_scenario_37_final_depletion_removes_exact_old_value(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '3', 'unit_cost' => '3.333333'],
            ],
        ]);

        $line = $purchase->lines->first();

        // Return 2 units first
        $r1 = $this->postReturn($this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]));

        $costMid = InventoryCostState::where('product_id', $this->product->id)->sole();
        $this->assertSame('1.000000', $costMid->quantity_base);

        // Return the final 1 unit
        $r2 = $this->postReturn($this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '1']],
        ]));

        $finalMov = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase_return')
            ->where('source_id', $r2->id)
            ->sole();

        // Final movement removes exactly the remaining inventory value
        $this->assertSame((string) BigDecimal::of((string) $costMid->inventory_value_base)->negated()->toScale(6), $finalMov->value_delta_base);
    }

    /** Scenario 38: qty/value/average all zero */
    public function test_scenario_38_qty_value_average_all_zero(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '12.5'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '5']],
        ]);
        $this->postReturn($draft);

        $costState = InventoryCostState::where('product_id', $this->product->id)->sole();
        $this->assertSame('0.000000', $costState->quantity_base);
        $this->assertSame('0.000000', $costState->inventory_value_base);
        $this->assertSame('0.000000', $costState->average_cost_base);
    }

    /** Scenario 39: historical target > current value partial fails */
    public function test_scenario_39_historical_target_greater_than_current_value_partial_fails(): void
    {
        $this->expectException(InvalidInventoryMovementException::class);
        $this->expectExceptionMessage('Historical receipt target exceeds current inventory carrying value.');

        PurchaseReturnValuation::compute(
            oldCompanyQty: BigDecimal::of('10'),
            oldCompanyVal: BigDecimal::of('40'),
            returnQty: BigDecimal::of('5'),
            historicalTarget: BigDecimal::of('50')
        );
    }

    /** Scenario 40: remaining positive qty/zero value coherent */
    public function test_scenario_40_remaining_positive_qty_zero_value_coherent(): void
    {
        $result = PurchaseReturnValuation::compute(
            oldCompanyQty: BigDecimal::of('10'),
            oldCompanyVal: BigDecimal::of('50'),
            returnQty: BigDecimal::of('5'),
            historicalTarget: BigDecimal::of('50')
        );

        $this->assertSame('5.000000', (string) $result->newCompanyQty);
        $this->assertSame('0.000000', (string) $result->newCompanyVal);
        $this->assertSame('0.000000', (string) $result->newCompanyAvg);
        $this->assertSame('50.000000', (string) $result->actualRemoved);
    }

    /** Scenario 41: multi-lot historical residual deterministic final remaining allocation */
    public function test_scenario_41_multi_lot_historical_residual_deterministic_final_remaining_allocation(): void
    {
        $purchase = $this->createAndPostExpiryPurchase([
            [
                'lot_number' => 'LOT-RES-1',
                'expiry_date' => '2027-01-01',
                'quantity' => '3',
            ],
            [
                'lot_number' => 'LOT-RES-2',
                'expiry_date' => '2027-06-01',
                'quantity' => '1',
            ],
        ], [
            'lines' => [
                [
                    'product_id' => $this->expiryProduct->id,
                    'quantity' => '4',
                    'unit_cost' => '2.500000',
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $lot1 = $line->lots->where('lot_number', 'LOT-RES-1')->first();
        $lot2 = $line->lots->where('lot_number', 'LOT-RES-2')->first();

        // Return all 4 units across both lots
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '4',
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
                            'quantity' => '1',
                        ],
                    ],
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);

        $allocations = $posted->lines->sole()->allocations;
        $this->assertCount(2, $allocations);

        $sumHistorical = BigDecimal::zero();
        foreach ($allocations as $alloc) {
            $sumHistorical = $sumHistorical->plus($alloc->historical_value_base);
        }

        $this->assertTrue($sumHistorical->isEqualTo(BigDecimal::of('10.000000')));
    }

    /** Scenario 42: actual movement value exact */
    public function test_scenario_42_actual_movement_value_exact(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '15.555555'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '3']],
        ]);

        $posted = $this->postReturn($draft);

        $movement = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase_return')
            ->where('source_id', $posted->id)
            ->sole();

        $returnLine = $posted->lines->sole();
        $this->assertSame((string) BigDecimal::of($returnLine->inventory_value_removed_base)->negated()->toScale(6), $movement->value_delta_base);
    }
}
