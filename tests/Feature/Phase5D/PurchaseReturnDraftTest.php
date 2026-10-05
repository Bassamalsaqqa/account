<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\UpdatePurchaseReturnDraftAction;
use App\Models\DocumentSequence;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseReturnDraftTest extends Phase5DTestCase
{
    /** Scenario 1: coherent Posted Purchase only */
    public function test_scenario_01_requires_coherent_posted_purchase(): void
    {
        // 1. Draft purchase
        $draftPurchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-04',
            'currency_code' => 'ILS',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->createReturnDraft($draftPurchase);
    }

    /** Scenario 2: no Draft PRT consumption */
    public function test_scenario_02_draft_does_not_consume_prt_number(): void
    {
        $purchase = $this->createAndPostPurchase();

        $draft1 = $this->createReturnDraft($purchase, ['lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '2']]]);
        $draft2 = $this->createReturnDraft($purchase, ['lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '3']]]);

        $this->assertNull($draft1->return_number);
        $this->assertNull($draft2->return_number);

        $seq = DocumentSequence::where('company_id', $this->company->id)
            ->where('document_type', DocumentSequence::TYPE_PURCHASE_RETURN)
            ->first();

        // Sequence counter should not exist or be 1 (default initial next_number)
        $this->assertTrue($seq === null || $seq->next_number === 1);
    }

    /** Scenario 3: no Draft stock/GL effects */
    public function test_scenario_03_draft_creates_no_stock_or_gl_effects(): void
    {
        $purchase = $this->createAndPostPurchase();
        $movementCountBefore = StockMovement::count();
        $batchCountBefore = PostingBatch::count();

        $draft = $this->createReturnDraft($purchase);

        $this->assertSame($movementCountBefore, StockMovement::count());
        $this->assertSame($batchCountBefore, PostingBatch::count());
        $this->assertNull($draft->posting_batch_id);
    }

    /** Scenario 4: inherited Vendor/warehouse/currency/FX */
    public function test_scenario_04_inherits_vendor_warehouse_currency_fx(): void
    {
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.650000',
        ]);

        $draft = $this->createReturnDraft($purchase);

        $this->assertSame((int) $purchase->vendor_id, (int) $draft->vendor_id);
        $this->assertSame((int) $purchase->warehouse_id, (int) $draft->warehouse_id);
        $this->assertSame('USD', $draft->currency_code);
        $this->assertSame('ILS', $draft->base_currency_code);
        $this->assertTrue(BigDecimal::of($draft->exchange_rate)->isEqualTo('3.650000'));
    }

    /** Scenario 5: no editable FX */
    public function test_scenario_05_no_editable_fx(): void
    {
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.500000',
        ]);

        // Attempting to pass a different exchange rate in draft creation must be ignored or rejected
        $draft = $this->createReturnDraft($purchase, [
            'exchange_rate' => '4.000000',
        ]);

        $this->assertTrue(BigDecimal::of($draft->exchange_rate)->isEqualTo('3.500000'));
    }

    /** Scenario 6: earlier date rejected */
    public function test_scenario_06_earlier_return_date_rejected(): void
    {
        $purchase = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-10',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->createReturnDraft($purchase, [
            'return_date' => '2026-10-09',
        ]);
    }

    /** Scenario 7: over-remaining quantity rejected */
    public function test_scenario_07_over_remaining_quantity_rejected(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10']],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '6']],
        ]);
    }

    /** Scenario 8: overlapping Drafts allowed/race bounded */
    public function test_scenario_08_overlapping_drafts_allowed_and_race_bounded(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10']],
        ]);

        $lineId = $purchase->lines->first()->id;

        // Draft A for 5
        $draftA = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $lineId, 'quantity' => '5']],
        ]);

        // Draft B for 5 is also allowed to exist as draft
        $draftB = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $lineId, 'quantity' => '5']],
        ]);

        $this->assertInstanceOf(PurchaseReturn::class, $draftA);
        $this->assertInstanceOf(PurchaseReturn::class, $draftB);

        // Posting Draft A succeeds
        $this->postReturn($draftA);
        $this->assertTrue($draftA->fresh()->isPosted());

        // Attempting to post Draft B now fails because remaining quantity is 0
        $this->expectException(InvalidArgumentException::class);
        $this->postReturn($draftB);
    }

    /** Scenario 9: final cumulative commercial residual exact */
    public function test_scenario_09_final_cumulative_commercial_residual_exact(): void
    {
        // Purchase with total = 100 base
        $purchase = $this->createAndPostPurchase([
            'lines' => [['product_id' => $this->product->id, 'quantity' => '3', 'unit_cost' => '33.333333']],
        ]);
        $line = $purchase->lines->first();
        $origTotal = BigDecimal::of((string) $line->line_total);

        // Return 1
        $return1 = $this->postReturn($this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '1']],
        ]));

        // Return 1
        $return2 = $this->postReturn($this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '1']],
        ]));

        // Final Return 1 (total returned = 3)
        $return3 = $this->postReturn($this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '1']],
        ]));

        $sumTotal = BigDecimal::of((string) $return1->lines->first()->line_total)
            ->plus($return2->lines->first()->line_total)
            ->plus($return3->lines->first()->line_total);

        $this->assertTrue($sumTotal->isEqualTo($origTotal), "Expected {$origTotal} but got {$sumTotal}");
    }

    /** Review regression: Create return rejects incoherent original purchase */
    public function test_create_return_rejects_incoherent_original_purchase(): void
    {
        $purchase = $this->createAndPostPurchase();
        DB::table('purchases')->where('id', $purchase->id)->update(['posting_batch_id' => null]);
        $purchase->refresh();
        $before = $this->snapshotState();
        $error = null;

        try {
            $this->createReturnDraft($purchase);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Draft created against incoherent Posted Purchase with no accounting provenance.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** Review regression: Draft save refreshes current identity snapshots */
    public function test_draft_save_refreshes_current_identity_snapshots(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);

        $this->vendor->update(['name_en' => 'Current Return Vendor']);
        $this->company->update(['name_en' => 'Current Return Company']);

        $saved = app(UpdatePurchaseReturnDraftAction::class)->execute($draft, $this->owner, ['notes' => 'Changed notes']);
        $this->assertSame('Current Return Vendor', $saved->vendor_snapshot['name_en']);
        $this->assertSame('Current Return Company', $saved->company_snapshot['name_en']);
    }
}
