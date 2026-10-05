<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Actions\Purchasing\UpdatePurchaseReturnDraftAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Livewire\Pages\Purchasing\PurchaseReturnForm;
use App\Models\PurchaseReturnAllocation;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\InventoryRebuildService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Purchasing\PurchaseReturnIssueCapability;
use App\Services\Purchasing\PurchaseReturnPostingCommandBuilder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Authoritative regression test suite covering Phase 5D Review Corrections 01, 02, 03, and 04.
 */
class PurchaseReturnCorrectionRegressionTest extends Phase5DTestCase
{
    /** C2-1: Completion scope and capability cannot cross Return or attach forged movement */
    public function test_c2_1_completion_capability_cannot_cross_return_or_attach_unpersisted_movement(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draftA = $this->createReturnDraft($purchase);
        $draftB = $this->createReturnDraft($purchase);
        $lineB = $draftB->lines->first();
        $originalReceipt = $purchase->lines->first()->stock_movement_id;
        $before = $this->snapshotState();
        $error = null;

        try {
            $this->withinTestReturnPostingScope($draftA, function (PurchaseReturnIssueCapability $capability) use ($draftB, $lineB, $originalReceipt) {
                $forged = new StockMovement;
                $forged->id = $originalReceipt;
                $forged->company_id = $this->company->id;
                $forged->movement_type = StockMovement::TYPE_PURCHASE_RETURN;
                $forged->source_type = 'purchase_return';
                $forged->source_id = $draftB->id;
                $forged->source_line_id = $lineB->id;
                $forged->product_id = $this->product->id;
                $forged->warehouse_id = $this->warehouse->id;
                $lineB->completeCanonicalReturn($forged, '999', '999', '999', $this->owner, $capability);
            });
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Capability for Return A completed Return B line using fake unsaved movement metadata.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C2-2: Dedicated issue API does not authorize command quantity outside persisted intent */
    public function test_c2_2_capability_does_not_authorize_command_quantity_outside_persisted_intent(): void
    {
        $purchase = $this->createAndPostPurchase();
        $draft = $this->createReturnDraft($purchase);
        $line = $draft->lines->first();
        $allocation = $line->allocations->first();
        $before = $this->snapshotState();
        $error = null;

        try {
            $this->withinTestReturnPostingScope($draft, function (PurchaseReturnIssueCapability $capability) use ($draft, $line, $allocation) {
                app(InventoryMovementService::class)->recordPurchaseReturnIssue(new StockMovementCommand(
                    companyId: $this->company->id,
                    movementType: StockMovement::TYPE_PURCHASE_RETURN,
                    movementDate: $draft->return_date->format('Y-m-d'),
                    lines: [
                        new StockMovementLineCommand(
                            productId: $this->product->id,
                            warehouseId: $this->warehouse->id,
                            quantity: Quantity::of('2'),
                            unitId: $this->product->base_unit_id,
                            originalMovementId: $allocation->original_stock_movement_id
                        ),
                    ],
                    sourceType: 'purchase_return',
                    sourceId: $draft->id,
                    idempotencyKey: 'review-wrong-intent',
                    createdBy: $this->owner->id,
                    sourceLineId: $line->id
                ), $capability);
            });
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Dedicated API accepted qty 2 under capability for persisted qty 1 Return.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C2-3: Return cannot deplete other original lot */
    public function test_c2_3_return_cannot_deplete_other_original_lot(): void
    {
        $purchase = $this->createAndPostExpiryPurchase([
            ['lot_number' => 'A', 'expiry_date' => '2026-12-31', 'quantity' => '5'],
            ['lot_number' => 'B', 'expiry_date' => '2026-12-31', 'quantity' => '5'],
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

    /** C2-4: Posted retry rejects invented line history and adjustment */
    public function test_c2_4_posted_retry_rejects_invented_line_history_and_adjustment(): void
    {
        $purchase = $this->createAndPostPurchase();
        $posted = $this->postReturn($this->createReturnDraft($purchase));

        DB::table('purchase_return_lines')
            ->where('purchase_return_id', $posted->id)
            ->update([
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

    /** C2-4: Reconciliation rejects invented line history and adjustment */
    public function test_c2_4_reconciliation_rejects_invented_line_history_and_adjustment(): void
    {
        $purchase = $this->createAndPostPurchase();
        $posted = $this->postReturn($this->createReturnDraft($purchase));

        DB::table('purchase_return_lines')
            ->where('purchase_return_id', $posted->id)
            ->update([
                'historical_receipt_value_base' => '999',
                'valuation_adjustment_base' => '999',
            ]);

        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertFalse($report->isHealthy, 'Reconciliation accepts arbitrary non-null immutable line values.');
        $this->assertTrue($report->hasHistoryCorruption());
    }

    /** C2-5: Fully discounted zero value stock return posts without GL */
    public function test_c2_5_fully_discounted_zero_value_stock_return_posts_without_gl(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '10', 'discount_type' => 'percent', 'discount_value' => '100'],
            ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '10'],
        ]]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, ['lines' => [['purchase_line_id' => $line->id, 'quantity' => '1']]]);
        $posted = $this->postReturn($draft);

        $this->assertTrue($posted->isPosted());
        $this->assertNull($posted->posting_batch_id);
        $this->assertSame('0.000000', $posted->lines->first()->inventory_value_removed_base);

        // Retry succeeds
        $retried = $this->postReturn($posted);
        $this->assertSame($posted->id, $retried->id);
    }

    /** C2-6: Stale form denies reads after purchasing.purchase.view permission revoked */
    public function test_c2_6_stale_form_denies_reads_after_purchase_view_permission_revoked(): void
    {
        $purchase = $this->createAndPostPurchase();
        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id])->assertOk();

        $role = $this->owner->roles->first();
        $role->revokePermissionTo('purchasing.purchase.view');
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');

        $component->call('$refresh')->assertForbidden();
    }

    /** Direct completion cannot post nonzero draft without effects */
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

    /** Full return coherent posted retry succeeds */
    public function test_full_return_coherent_posted_retry_succeeds(): void
    {
        $purchase = $this->createAndPostPurchase();
        $return = $this->createReturnDraft($purchase, ['lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '10']]]);
        $posted = $this->postReturn($return);
        $before = $this->snapshotState();

        $retry = $this->postReturn($posted);
        $this->assertSame($posted->id, $retry->id);
        $this->assertSame($before, $this->snapshotState());
    }

    /** Create return rejects incoherent original purchase */
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

    /** Post rejects allocation sum different from line quantity */
    public function test_post_rejects_allocation_sum_different_from_line_quantity(): void
    {
        $purchase = $this->createAndPostPurchase();
        $return = $this->createReturnDraft($purchase);
        $return->lines->first()->allocations->first()->update(['quantity' => '2', 'quantity_base' => '2']);
        $before = $this->snapshotState();
        $error = null;

        try {
            $this->postReturn($return);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Posting issued 2 units for a Return line/AP relief of 1 unit.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** Post rejects corrupt draft component even when grand total unchanged */
    public function test_post_rejects_corrupt_draft_component_even_when_grand_total_unchanged(): void
    {
        $purchase = $this->createAndPostPurchase();
        $return = $this->createReturnDraft($purchase);
        $return->lines->first()->update(['line_subtotal' => '11']);
        $before = $this->snapshotState();
        $error = null;

        try {
            $this->postReturn($return);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Post silently rewrote corrupt agreed Draft component while checking only grand total.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** Line adjustment equals actual minus commercial component */
    public function test_line_adjustment_equals_actual_minus_commercial_component(): void
    {
        $tax = $this->tax($this->account('tax_input')->id);
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.3333333333',
            'lines' => [['quantity' => '3', 'unit_cost' => '1', 'tax_rate_id' => $tax->id]],
        ]);

        $posted = $this->postReturn($this->createReturnDraft($purchase));
        $line = $posted->lines->first();
        $commercial = BigDecimal::of($line->line_total_base)->minus($line->line_tax_base);
        $expected = BigDecimal::of($line->inventory_value_removed_base)->minus($commercial)->toScale(6);
        $this->assertSame((string) $expected, $line->valuation_adjustment_base);
    }

    /** Expiry return form create loads actual lot balance */
    public function test_expiry_return_form_create_loads_actual_lot_balance(): void
    {
        $purchase = $this->createAndPostExpiryPurchase();
        Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id])->assertOk();
    }

    /** Stale return form denies reads after cost permission revoked */
    public function test_stale_return_form_denies_reads_after_cost_permission_revoked(): void
    {
        $purchase = $this->createAndPostPurchase();
        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id])->assertOk();

        $role = $this->owner->roles->first();
        $role->revokePermissionTo('purchasing.cost.view');
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');

        $component->call('$refresh')->assertForbidden();
    }

    /** Positive currency zero base return fails atomically */
    public function test_positive_currency_zero_base_return_fails_atomically(): void
    {
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '0.0000000001',
            'lines' => [['quantity' => '100000', 'unit_cost' => '1']],
        ]);

        $return = $this->createReturnDraft($purchase);
        $this->assertSame('1.000000', $return->grand_total_currency);
        $this->assertSame('0.000000', $return->grand_total_base);

        $before = $this->snapshotState();
        $error = null;

        try {
            $this->postReturn($return);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Positive USD supplier relief was silently treated as stock-only zero-value Return.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** Post rejects receipt from different original purchase */
    public function test_post_rejects_receipt_from_different_original_purchase(): void
    {
        $purchaseA = $this->createAndPostPurchase();
        $purchaseB = $this->createAndPostPurchase(['lines' => [['unit_cost' => '20']]]);
        $return = $this->createReturnDraft($purchaseA);
        $line = $return->lines->first();
        $line->allocations->first()->delete();

        PurchaseReturnAllocation::create([
            'company_id' => $this->company->id,
            'purchase_return_id' => $return->id,
            'purchase_return_line_id' => $line->id,
            'original_stock_movement_id' => $purchaseB->lines->first()->stock_movement_id,
            'quantity' => '1',
            'quantity_base' => '1',
        ]);

        $before = $this->snapshotState();
        $error = null;

        try {
            $this->postReturn($return);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Return of Purchase A used Purchase B receipt value/provenance.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** Reconciliation detects missing posted allocation values */
    public function test_reconciliation_detects_missing_posted_allocation_values(): void
    {
        $purchase = $this->createAndPostPurchase();
        $posted = $this->postReturn($this->createReturnDraft($purchase));

        DB::table('purchase_return_allocations')
            ->where('purchase_return_id', $posted->id)
            ->update([
                'historical_value_base' => null,
                'inventory_value_removed_base' => null,
            ]);

        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertFalse($report->isHealthy, 'Reconciliation accepts missing immutable historical and carrying-value provenance.');
        $this->assertTrue($report->hasHistoryCorruption());
    }

    /** Posted retry rejects missing line provenance */
    public function test_posted_retry_rejects_missing_line_provenance(): void
    {
        $purchase = $this->createAndPostPurchase();
        $posted = $this->postReturn($this->createReturnDraft($purchase));

        DB::table('purchase_return_lines')
            ->where('purchase_return_id', $posted->id)
            ->update([
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

    /** Draft save refreshes current identity snapshots */
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

    /** C3-1: Retry rejects posted line quantity inconsistent with issues */
    public function test_c3_1_retry_rejects_posted_line_quantity_inconsistent_with_issues(): void
    {
        $posted = $this->postReturn($this->createReturnDraft($this->createAndPostPurchase()));
        DB::table('purchase_return_lines')->where('purchase_return_id', $posted->id)->update(['quantity' => '2', 'quantity_base' => '2']);
        $before = $this->snapshotState();
        $error = null;
        try {
            $this->postReturn($posted);
        } catch (\Throwable $e) {
            $error = $e;
        }
        $this->assertNotNull($error, 'Retry accepted quantity 2 with allocations/stock/AP for quantity 1.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C3-1: Reconciliation rejects posted line quantity inconsistent with issues */
    public function test_c3_1_reconciliation_rejects_posted_line_quantity_inconsistent_with_issues(): void
    {
        $posted = $this->postReturn($this->createReturnDraft($this->createAndPostPurchase()));
        DB::table('purchase_return_lines')->where('purchase_return_id', $posted->id)->update(['quantity' => '2', 'quantity_base' => '2']);
        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertFalse($report->isHealthy, 'Reconciliation accepted document qty 2 although only 1 issued.');
        $this->assertTrue($report->hasHistoryCorruption());
    }

    /** C3-1: Retry rejects corrupt historical commercial snapshots */
    public function test_c3_1_retry_rejects_corrupt_historical_commercial_snapshots(): void
    {
        $posted = $this->postReturn($this->createReturnDraft($this->createAndPostPurchase()));
        DB::table('purchase_return_lines')->where('purchase_return_id', $posted->id)->update(['unit_cost' => '999', 'line_subtotal' => '999']);
        DB::table('purchase_returns')->where('id', $posted->id)->update(['subtotal_currency' => '999']);
        $before = $this->snapshotState();
        $error = null;
        try {
            $this->postReturn($posted);
        } catch (\Throwable $e) {
            $error = $e;
        }
        $this->assertNotNull($error, 'Retry accepted corrupted historical unit cost/subtotal with unchanged AP.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C3-1: Rebuild refuses posted document quantity corruption */
    public function test_c3_1_rebuild_refuses_posted_document_quantity_corruption(): void
    {
        $posted = $this->postReturn($this->createReturnDraft($this->createAndPostPurchase()));
        DB::table('purchase_return_lines')->where('purchase_return_id', $posted->id)->update(['quantity' => '2', 'quantity_base' => '2']);
        $before = $this->snapshotState();
        $error = null;
        try {
            app(InventoryRebuildService::class)->rebuildForCompany($this->company);
        } catch (\Throwable $e) {
            $error = $e;
        }
        $this->assertNotNull($error, 'Rebuild accepted corrupt immutable Return quantity.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C3-2: Dedicated issue validates original lot chain before mutation */
    public function test_c3_2_dedicated_issue_validates_original_lot_chain_before_mutation(): void
    {
        $purchase = $this->createAndPostExpiryPurchase([
            ['lot_number' => 'A', 'expiry_date' => '2026-12-31', 'quantity' => '5'],
            ['lot_number' => 'B', 'expiry_date' => '2026-12-31', 'quantity' => '5'],
        ]);
        $draft = $this->createReturnDraft($purchase);
        $wrongLot = $purchase->lines->first()->lots->last()->created_inventory_lot_id;
        DB::table('purchase_return_allocations')->where('purchase_return_id', $draft->id)->update(['inventory_lot_id' => $wrongLot]);
        $before = $this->snapshotState();
        $error = null;
        try {
            $this->withinTestReturnPostingScope($draft, function (PurchaseReturnIssueCapability $cap) use ($draft): void {
                app(InventoryMovementService::class)->recordPurchaseReturnIssue($this->returnMovementCommandFor($draft), $cap);
            });
        } catch (\Throwable $e) {
            $error = $e;
        }
        $this->assertNotNull($error, 'Dedicated API matched corrupted allocation B and issued B against original receipt A.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C3-3: Parent completion requires matching number and financial batch */
    public function test_c3_3_parent_completion_requires_matching_number_and_financial_batch(): void
    {
        $draft = $this->createReturnDraft($this->createAndPostPurchase());
        $before = $this->snapshotState();
        $error = null;
        try {
            $this->withinTestReturnPostingScope($draft, function (PurchaseReturnIssueCapability $cap) use ($draft): void {
                $movement = app(InventoryMovementService::class)->recordPurchaseReturnIssue($this->returnMovementCommandFor($draft), $cap)[0];
                $line = $draft->lines()->first();
                $alloc = $line->allocations()->first();
                $alloc->completeCanonicalAllocation($movement, '10', '10', $this->owner, $cap);
                $line->completeCanonicalReturn($movement, '10', '10', '0', $this->owner, $cap);
                $command = app(PurchaseReturnPostingCommandBuilder::class)->build($this->company, $draft, 'PRT-EXPECTED', $this->owner);
                $batch = app(AccountingPostingService::class)->post($command);
                $draft->completeCanonicalPost($batch, 'PRT-FORGED', $this->owner, $cap);
            });
        } catch (\Throwable $e) {
            $error = $e;
        }
        $this->assertNotNull($error, 'Completion accepted a number different from its accounting batch and consumed no PRT sequence.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C3-4: Stale Arabic form uses localized remaining quantity error */
    public function test_c3_4_stale_arabic_form_uses_localized_remaining_quantity_error(): void
    {
        app()->setLocale('ar');
        $purchase = $this->createAndPostPurchase();
        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);
        $component->set('lines.0.return_quantity', '6');
        $this->postReturn($this->createReturnDraft($purchase, ['lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '6']]]));
        $before = $this->snapshotState();
        $component->call('saveDraft')->assertHasErrors(['save'])->assertDontSee('Requested return quantity');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C4-1: Prior partial retry remains coherent after final return */
    public function test_c4_1_prior_partial_retry_remains_coherent_after_final_return(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.3333333333', 'lines' => [['quantity' => '3', 'unit_cost' => '1']]]);
        $first = $this->postReturn($this->createReturnDraft($purchase));
        $this->postReturn($this->createReturnDraft($purchase, ['lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '2']]]));
        $before = $this->snapshotState();
        $this->assertSame($first->id, $this->postReturn($first)->id);
        $this->assertSame($before, $this->snapshotState());
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
    }

    /** C4-1: Posted retry rejects incoherent original purchase */
    public function test_c4_1_posted_retry_rejects_incoherent_original_purchase(): void
    {
        $purchase = $this->createAndPostPurchase();
        $posted = $this->postReturn($this->createReturnDraft($purchase));
        DB::table('purchases')->where('id', $purchase->id)->update(['posting_batch_id' => null]);
        $before = $this->snapshotState();
        $error = null;
        try {
            $this->postReturn($posted);
        } catch (\Throwable $e) {
            $error = $e;
        }
        $this->assertNotNull($error, 'Posted retry accepted an original Purchase missing its canonical posting batch.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** C4-1: Reconciliation rejects incoherent original purchase */
    public function test_c4_1_reconciliation_rejects_incoherent_original_purchase(): void
    {
        $purchase = $this->createAndPostPurchase();
        $this->postReturn($this->createReturnDraft($purchase));
        DB::table('purchases')->where('id', $purchase->id)->update(['posting_batch_id' => null]);
        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertFalse($report->isHealthy, 'Return history audit accepted an original Purchase missing canonical accounting provenance.');
        $this->assertTrue($report->hasHistoryCorruption());
    }

    /** C4-1: Rebuild refuses incoherent original purchase */
    public function test_c4_1_rebuild_refuses_incoherent_original_purchase(): void
    {
        $purchase = $this->createAndPostPurchase();
        $this->postReturn($this->createReturnDraft($purchase));
        DB::table('purchases')->where('id', $purchase->id)->update(['posting_batch_id' => null]);
        $before = $this->snapshotState();
        $error = null;
        try {
            app(InventoryRebuildService::class)->rebuildForCompany($this->company);
        } catch (\Throwable $e) {
            $error = $e;
        }
        $this->assertNotNull($error, 'Rebuild accepted Return history whose original Purchase has no canonical batch link.');
        $this->assertSame($before, $this->snapshotState());
    }

    private function returnMovementCommandFor($draft): StockMovementCommand
    {
        $line = $draft->lines()->first();
        $alloc = $line->allocations()->first();
        $product = $line->product;

        return new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_PURCHASE_RETURN,
            movementDate: $draft->return_date->format('Y-m-d'),
            lines: [new StockMovementLineCommand(
                productId: $line->product_id,
                warehouseId: $draft->warehouse_id,
                quantity: Quantity::of($alloc->quantity_base),
                unitId: $product->base_unit_id,
                lotId: $alloc->inventory_lot_id,
                originalMovementId: $alloc->original_stock_movement_id,
            )],
            sourceType: 'purchase_return',
            sourceId: $draft->id,
            idempotencyKey: 'purchase_return_'.$draft->id.'_line_'.$line->id.'_stock',
            createdBy: $this->owner->id,
            sourceLineId: $line->id,
        );
    }
}
