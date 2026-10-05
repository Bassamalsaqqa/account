<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\DocumentSequence;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\PurchaseReturnLine;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Purchasing\PurchaseReturnIssueCapability;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseReturnImmutabilityIdempotencyTest extends Phase5DTestCase
{
    /** Scenario 71: posted return immutable */
    public function test_scenario_71_posted_return_immutable(): void
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

        $posted = $this->postReturn($draft);
        $this->assertTrue($posted->isPosted());

        // 1. PurchaseReturn model update
        try {
            $posted->notes = 'Attempted update on posted return';
            $posted->save();
            $this->fail('Expected ImmutableRecordException when updating posted return');
        } catch (ImmutableRecordException) {
            $this->assertTrue(true);
        }

        // 2. PurchaseReturn model delete
        try {
            $posted->delete();
            $this->fail('Expected ImmutableRecordException when deleting posted return');
        } catch (ImmutableRecordException) {
            $this->assertTrue(true);
        }

        // 3. PurchaseReturnLine update
        $returnLine = $posted->lines->first();
        try {
            $returnLine->quantity = '4.000000';
            $returnLine->save();
            $this->fail('Expected ImmutableRecordException when updating posted return line');
        } catch (ImmutableRecordException) {
            $this->assertTrue(true);
        }

        // 4. PurchaseReturnLine delete
        try {
            $returnLine->delete();
            $this->fail('Expected ImmutableRecordException when deleting posted return line');
        } catch (ImmutableRecordException) {
            $this->assertTrue(true);
        }

        // 5. PurchaseReturnAllocation update
        $allocation = $returnLine->allocations->first();
        try {
            $allocation->quantity = '4.000000';
            $allocation->save();
            $this->fail('Expected ImmutableRecordException when updating posted return allocation');
        } catch (ImmutableRecordException) {
            $this->assertTrue(true);
        }

        // 6. PurchaseReturnAllocation delete
        try {
            $allocation->delete();
            $this->fail('Expected ImmutableRecordException when deleting posted return allocation');
        } catch (ImmutableRecordException) {
            $this->assertTrue(true);
        }
    }

    /** Scenario 72: direct line provenance rejected */
    public function test_scenario_72_direct_line_provenance_rejected(): void
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

        $draftLine = $draft->lines->first();

        foreach (PurchaseReturnLine::PROVENANCE_FIELDS as $field) {
            $draftLine->$field = $field === 'stock_movement_id' ? 1 : '10.000000';
            try {
                $draftLine->save();
                $this->fail("Expected ImmutableRecordException when setting provenance field [{$field}] directly on draft line");
            } catch (ImmutableRecordException) {
                $this->assertTrue(true);
            }
            $draftLine->refresh();
        }
    }

    /** Scenario 73: direct allocation provenance rejected */
    public function test_scenario_73_direct_allocation_provenance_rejected(): void
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

        $draftAllocation = $draft->lines->first()->allocations->first();

        foreach (PurchaseReturnAllocation::PROVENANCE_FIELDS as $field) {
            $draftAllocation->$field = $field === 'stock_movement_id' ? 1 : '10.000000';
            try {
                $draftAllocation->save();
                $this->fail("Expected ImmutableRecordException when setting provenance field [{$field}] directly on draft allocation");
            } catch (ImmutableRecordException) {
                $this->assertTrue(true);
            }
            $draftAllocation->refresh();
        }
    }

    /** Scenario 74: coherent retry noeffects */
    public function test_scenario_74_coherent_retry_noeffects(): void
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

        $posted = $this->postReturn($draft);
        $beforeRetry = $this->snapshotState();

        // Retry post
        $retried = $this->postReturn($posted);

        $this->assertSame($posted->id, $retried->id);
        $this->assertSame($posted->return_number, $retried->return_number);
        $afterRetry = $this->snapshotState();

        $this->assertSame($beforeRetry, $afterRetry);
    }

    /** Scenario 75: incoherent retry fails */
    public function test_scenario_75_incoherent_retry_fails(): void
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

        $posted = $this->postReturn($draft);

        // Case 1: Tampered posting_batch_id
        DB::table('purchase_returns')->where('id', $posted->id)->update(['posting_batch_id' => null]);
        $beforeState = $this->snapshotState();

        try {
            $this->postReturn($posted);
            $this->fail('Expected InvalidArgumentException on incoherent batch retry');
        } catch (InvalidArgumentException) {
            $this->assertSame($beforeState, $this->snapshotState());
        }

        // Restore batch
        DB::table('purchase_returns')->where('id', $posted->id)->update(['posting_batch_id' => $posted->getRawOriginal('posting_batch_id')]);

        // Case 2: Tampered return_number
        DB::table('purchase_returns')->where('id', $posted->id)->update(['return_number' => 'PRT-TAMPERED']);
        $beforeState = $this->snapshotState();

        try {
            $this->postReturn($posted);
            $this->fail('Expected InvalidArgumentException on incoherent return_number retry');
        } catch (InvalidArgumentException) {
            $this->assertSame($beforeState, $this->snapshotState());
        }
    }

    /** Scenario 76: changed idempotency payload fails */
    public function test_scenario_76_changed_idempotency_payload_fails(): void
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

        $posted = $this->postReturn($draft);
        $postedLine = $posted->lines->first();
        $postedAlloc = $postedLine->allocations->first();

        // Prepare conflicting inventory movement command with the same idempotency key but different quantity
        $conflictingCommand = new StockMovementCommand(
            companyId: (int) $this->company->id,
            movementType: StockMovement::TYPE_PURCHASE_RETURN,
            movementDate: $posted->return_date->format('Y-m-d'),
            lines: [
                new StockMovementLineCommand(
                    productId: (int) $postedLine->product_id,
                    warehouseId: (int) $posted->warehouse_id,
                    quantity: Quantity::of('5.000000'), // changed from 3
                    unitId: (int) $this->product->base_unit_id,
                    originalMovementId: (int) $postedAlloc->original_stock_movement_id
                ),
            ],
            sourceType: 'purchase_return',
            sourceId: (int) $posted->id,
            idempotencyKey: 'purchase_return_'.$posted->id.'_line_'.$postedLine->id.'_stock',
            createdBy: (int) $this->owner->id,
            sourceLineId: (int) $postedLine->id
        );

        $beforeState = $this->snapshotState();

        $this->withinTestReturnPostingScope($posted, function (PurchaseReturnIssueCapability $capability) use ($conflictingCommand, $beforeState) {
            try {
                app(InventoryMovementService::class)->recordPurchaseReturnIssue($conflictingCommand, $capability);
                $this->fail('Expected IdempotencyConflictException on changed idempotency payload');
            } catch (IdempotencyConflictException) {
                $this->assertSame($beforeState, $this->snapshotState());
            }
        });
    }

    /** Scenario 77: same-return concurrency once */
    public function test_scenario_77_same_return_concurrency_once(): void
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

        // Post once
        $first = $this->postReturn($draft);
        $beforeSecond = $this->snapshotState();

        // Second post on same return converges
        $second = $this->postReturn($draft);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->return_number, $second->return_number);
        $this->assertSame($beforeSecond, $this->snapshotState());

        // Verify only 1 document sequence number consumed (next_number is now 2)
        $seq = DocumentSequence::where('company_id', $this->company->id)
            ->where('document_type', DocumentSequence::TYPE_PURCHASE_RETURN)
            ->first();
        $this->assertSame(2, $seq?->next_number);

        // Verify only 1 stock movement for this return
        $movementCount = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase_return')
            ->where('source_id', $draft->id)
            ->count();
        $this->assertSame(1, $movementCount);
    }

    /** Scenario 78: two different drafts concurrent over-return bounded */
    public function test_scenario_78_two_different_drafts_concurrent_over_return_bounded(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '15'],
            ],
        ]);

        $line = $purchase->lines->first();

        // Draft A requests 6 units
        $draftA = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '6']],
        ]);

        // Draft B also requests 6 units (total 12 > 10)
        $draftB = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '6']],
        ]);

        // Post Draft A successfully
        $postedA = $this->postReturn($draftA);
        $this->assertTrue($postedA->isPosted());

        // Attempt to post Draft B: must fail because only 4 remain
        try {
            $this->postReturn($draftB);
            $this->fail('Expected InvalidArgumentException when Draft B over-returns');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exceeds remaining returnable quantity', $e->getMessage());
        }

        $this->assertFalse($draftB->fresh()->isPosted());
    }

    /** C2-4: Posted retry rejects invented line history and adjustment */
    public function test_c2_4_posted_retry_rejects_invented_line_history_and_adjustment(): void
    {
        $purchase = $this->createAndPostPurchase();
        $posted = $this->postReturn($this->createReturnDraft($purchase));

        DB::table('purchase_return_lines')->where('purchase_return_id', $posted->id)->update([
            'historical_receipt_value_base' => '999',
            'valuation_adjustment_base' => '999',
        ]);

        $before = $this->snapshotState();
        $error = null;

        try {
            $this->postReturn($posted);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Retry checked only non-null history/adjustment and accepted arbitrary non-null values.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** Review regression: Direct completion cannot post nonzero draft without effects */
    public function test_direct_completion_cannot_post_nonzero_draft_without_effects(): void
    {
        $purchase = $this->createAndPostPurchase();
        $return = $this->createReturnDraft($purchase);
        $before = $this->snapshotState();
        $error = null;

        try {
            DB::transaction(fn () => $return->completeCanonicalPost(null, 'PRT-FORGED', $this->owner));
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Authorized arbitrary transaction finalized nonzero Return with no stock, GL, or number allocation.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** Review regression: Full return coherent posted retry succeeds */
    public function test_full_return_coherent_posted_retry_succeeds(): void
    {
        $purchase = $this->createAndPostPurchase();
        $return = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '10']],
        ]);
        $posted = $this->postReturn($return);
        $before = $this->snapshotState();

        $retry = $this->postReturn($posted);
        $this->assertSame($posted->id, $retry->id);
        $this->assertSame($before, $this->snapshotState());
    }

    /** Review regression: Posted retry rejects missing line provenance */
    public function test_posted_retry_rejects_missing_line_provenance(): void
    {
        $purchase = $this->createAndPostPurchase();
        $posted = $this->postReturn($this->createReturnDraft($purchase));

        DB::table('purchase_return_lines')->where('purchase_return_id', $posted->id)->update([
            'stock_movement_id' => null,
            'historical_receipt_value_base' => null,
            'inventory_value_removed_base' => null,
            'valuation_adjustment_base' => null,
        ]);

        $before = $this->snapshotState();
        $error = null;

        try {
            $this->postReturn($posted);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Posted retry accepts missing line receipt/value/adjustment provenance.');
        $this->assertSame($before, $this->snapshotState());
    }
}
