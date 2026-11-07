<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Purchasing\VoidPurchaseReturnAction;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Purchasing\Exceptions\PurchasingReversalBlockedException;
use App\Models\InventoryBalance;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\PurchaseReturnReadModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Feature\Phase5D\Phase5DTestCase;

final class VoidPurchaseReturnTest extends Phase5DTestCase
{
    private VoidPurchaseReturnAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(VoidPurchaseReturnAction::class);
    }

    public function test_successful_purchase_return_void_restores_stock_and_accounting(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '30.000000',
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '4']],
        ]);
        $postedReturn = $this->postReturn($returnDraft);

        $this->assertSame(PurchaseReturn::STATUS_POSTED, $postedReturn->status);
        $originalBatchId = $postedReturn->posting_batch_id;
        $this->assertNotNull($originalBatchId);

        // After return of 4, stock balance should be 6
        $midBalance = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('6.000000', (string) $midBalance->quantity_base);

        // Void the return
        $voidedReturn = $this->action->execute($postedReturn, $this->owner, 'Vendor refused to accept return', '2026-11-06');

        $this->assertSame(PurchaseReturn::STATUS_VOID, $voidedReturn->status);
        $this->assertSame('Vendor refused to accept return', $voidedReturn->void_reason);
        $this->assertSame($this->owner->id, $voidedReturn->voided_by);
        $this->assertNotNull($voidedReturn->voided_at);
        $this->assertNotNull($voidedReturn->void_posting_batch_id);

        // Verify GL reversal batch
        $reversalBatch = PostingBatch::where('company_id', $this->company->id)->findOrFail($voidedReturn->void_posting_batch_id);
        $this->assertSame(PostingBatch::STATUS_POSTED, $reversalBatch->status);
        $this->assertSame('reversal', $reversalBatch->source_type);
        $this->assertSame($originalBatchId, $reversalBatch->reversal_of_id);

        $originalBatch = PostingBatch::where('company_id', $this->company->id)->findOrFail($originalBatchId);
        $this->assertSame(PostingBatch::STATUS_REVERSED, $originalBatch->status);
        $this->assertSame($reversalBatch->id, $originalBatch->reversed_by_batch_id);

        // Verify compensating stock movement: restores +4
        $voidMovements = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase_return_void')
            ->where('source_id', $postedReturn->id)
            ->get();
        $this->assertCount(1, $voidMovements);
        $voidMove = $voidMovements->sole();
        $this->assertSame('4.000000', (string) $voidMove->quantity_delta_base);

        // Verify balance restored to 10
        $finalBalance = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('10.000000', (string) $finalBalance->quantity_base);

        // Reconciliation checks
        $reconReport = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($reconReport->isHealthy, json_encode($reconReport->discrepancies));

        $accReport = app(AccountingReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($accReport->isHealthy, 'Accounting reconciliation failed: '.json_encode($accReport->violations));

        $payables = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($payables->isHealthy, json_encode($payables->violations));

        // A void status alone cannot excuse missing reciprocal compensation history.
        DB::table('posting_batches')->where('id', $originalBatchId)->update(['reversed_by_batch_id' => null]);
        $corrupted = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertFalse($corrupted->isHealthy);
        $this->assertNotEmpty($corrupted->violations);
    }

    public function test_idempotent_retry_and_mismatched_payload_refusal(): void
    {
        $purchase = $this->createAndPostPurchase();
        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);
        $postedReturn = $this->postReturn($returnDraft);

        $firstVoid = $this->action->execute($postedReturn, $this->owner, 'First return void reason', '2026-11-06');
        $this->assertSame(PurchaseReturn::STATUS_VOID, $firstVoid->status);

        // Retry with same payload
        $secondVoid = $this->action->execute($postedReturn, $this->owner, 'First return void reason', '2026-11-06');
        $this->assertSame($firstVoid->id, $secondVoid->id);
        $this->assertSame($firstVoid->void_posting_batch_id, $secondVoid->void_posting_batch_id);

        // Mismatched reason
        $this->expectException(IdempotencyConflictException::class);
        $this->action->execute($postedReturn, $this->owner, 'Mismatched reason', '2026-11-06');
    }

    public function test_zero_cost_purchase_return_void(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '0.000000',
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '3']],
        ]);
        $postedReturn = $this->postReturn($returnDraft);
        $this->assertNull($postedReturn->posting_batch_id);

        $voidedReturn = $this->action->execute($postedReturn, $this->owner, 'Zero-cost return cancelled');

        $this->assertSame(PurchaseReturn::STATUS_VOID, $voidedReturn->status);
        $this->assertNull($voidedReturn->void_posting_batch_id);

        // Stock restored
        $voidMovements = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase_return_void')
            ->where('source_id', $postedReturn->id)
            ->get();
        $this->assertCount(1, $voidMovements);
        $this->assertSame('3.000000', (string) $voidMovements->sole()->quantity_delta_base);

        // Reconciliations pass
        $reconReport = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($reconReport->isHealthy, json_encode($reconReport->discrepancies));
    }

    public function test_blocker_detection_when_parent_purchase_is_not_posted(): void
    {
        $purchase = $this->createAndPostPurchase();
        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);
        $postedReturn = $this->postReturn($returnDraft);

        // If parent purchase was somehow voided or changed
        DB::table('purchases')->where('id', $purchase->id)->update(['status' => 'draft']);
        $postedReturn->unsetRelation('purchase');

        $blockers = $this->action->inspectBlockers($postedReturn);
        $this->assertNotEmpty($blockers);
        $this->assertSame('parent_purchase_not_posted', $blockers[0]['code']);

        $this->expectException(PurchasingReversalBlockedException::class);
        $this->action->execute($postedReturn, $this->owner, 'Should fail');
    }

    public function test_permission_boundaries(): void
    {
        $purchase = $this->createAndPostPurchase();
        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);
        $postedReturn = $this->postReturn($returnDraft);

        $unauthorized = User::factory()->create();
        $this->company->users()->attach($unauthorized);
        $this->activate($unauthorized);

        $this->expectException(AuthorizationException::class);
        $this->action->execute($postedReturn, $unauthorized, 'Unauthorized');
    }

    public function test_read_model_includes_void_metadata(): void
    {
        $purchase = $this->createAndPostPurchase();
        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);
        $postedReturn = $this->postReturn($returnDraft);

        $voided = $this->action->execute($postedReturn, $this->owner, 'Audited return reversal', '2026-11-06');

        $readModel = app(PurchaseReturnReadModel::class);
        $detail = $readModel->detail($voided, true);

        $this->assertSame(PurchaseReturn::STATUS_VOID, $detail['status']);
        $this->assertSame('Audited return reversal', $detail['void_reason']);
        $this->assertSame($this->owner->id, $detail['voided_by']);
        $this->assertSame($voided->voided_at->format('Y-m-d H:i'), $detail['voided_at']);
    }

    public function test_migration_round_trip(): void
    {
        $migration = require database_path('migrations/2026_11_07_000001_add_void_columns_to_purchase_returns_and_permissions.php');

        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('purchase_returns', 'voided_at'));

        $migration->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('purchase_returns', 'voided_at'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('purchase_returns', 'void_reason'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('purchase_returns', 'voided_by'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('purchase_returns', 'void_posting_batch_id'));
    }

    public function test_void_return_with_repeated_product_lines_restores_stock_cleanly(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '20.000000',
                ],
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5',
                    'unit_cost' => '20.000000',
                ],
            ],
        ]);

        $line1 = $purchase->lines[0];
        $line2 = $purchase->lines[1];

        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [
                ['purchase_line_id' => $line1->id, 'quantity' => '3'],
                ['purchase_line_id' => $line2->id, 'quantity' => '2'],
            ],
        ]);
        $postedReturn = $this->postReturn($returnDraft);
        $this->assertSame(PurchaseReturn::STATUS_POSTED, $postedReturn->status);

        // Pre-void balance: 15 - 5 = 10
        $balanceMid = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('10.000000', (string) $balanceMid->quantity_base);

        $voidedReturn = $this->action->execute($postedReturn, $this->owner, 'Voiding multi-line return');
        $this->assertSame(PurchaseReturn::STATUS_VOID, $voidedReturn->status);

        // Restored balance: 10 + 5 = 15
        $balanceFinal = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('15.000000', (string) $balanceFinal->quantity_base);

        $reconReport = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($reconReport->isHealthy, json_encode($reconReport->discrepancies));
    }

    public function test_inactive_warehouse_blocks_purchase_return_void(): void
    {
        $purchase = $this->createAndPostPurchase();
        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);
        $postedReturn = $this->postReturn($returnDraft);

        $this->warehouse->active = false;
        $this->warehouse->save();

        $blockers = $this->action->inspectBlockers($postedReturn);
        $codes = array_column($blockers, 'code');
        $this->assertContains('inactive_warehouse', $codes);

        $this->expectException(PurchasingReversalBlockedException::class);
        $this->action->execute($postedReturn, $this->owner, 'Should fail due to inactive warehouse');
    }

    public function test_inactive_product_blocks_purchase_return_void(): void
    {
        $purchase = $this->createAndPostPurchase();
        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);
        $postedReturn = $this->postReturn($returnDraft);

        $this->product->active = false;
        $this->product->save();

        $blockers = $this->action->inspectBlockers($postedReturn);
        $codes = array_column($blockers, 'code');
        $this->assertContains('inactive_product', $codes);

        $this->expectException(PurchasingReversalBlockedException::class);
        $this->action->execute($postedReturn, $this->owner, 'Should fail due to inactive product');
    }

    public function test_parent_purchase_voided_blocks_return_void(): void
    {
        $purchase = $this->createAndPostPurchase();
        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);
        $postedReturn = $this->postReturn($returnDraft);

        // Simulate parent purchase voided
        DB::table('purchases')->where('id', $purchase->id)->update(['status' => Purchase::STATUS_VOID]);

        $blockers = $this->action->inspectBlockers($postedReturn);
        $codes = array_column($blockers, 'code');
        $this->assertContains('parent_purchase_voided', $codes);

        $this->expectException(PurchasingReversalBlockedException::class);
        $this->action->execute($postedReturn, $this->owner, 'Should fail due to voided parent');
    }

    public function test_idempotent_retry_rejects_date_mismatch(): void
    {
        $purchase = $this->createAndPostPurchase();
        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);
        $postedReturn = $this->postReturn($returnDraft);

        $firstVoid = $this->action->execute($postedReturn, $this->owner, 'First return void reason', '2026-11-06');
        $this->assertSame(PurchaseReturn::STATUS_VOID, $firstVoid->status);

        $this->expectException(IdempotencyConflictException::class);
        $this->action->execute($postedReturn, $this->owner, 'First return void reason', '2026-11-07');
    }

    public function test_void_date_before_return_date_throws_invalid_argument(): void
    {
        $purchase = $this->createAndPostPurchase();
        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);
        $postedReturn = $this->postReturn($returnDraft);

        $this->expectException(InvalidArgumentException::class);
        $this->action->execute($postedReturn, $this->owner, 'Should fail', '2020-01-01');
    }

    public function test_return_void_uses_original_unit_metadata_after_configuration_changes(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [[
            'product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '30',
        ]]]);
        $return = $this->postReturn($this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '4']],
        ]));
        DB::table('product_units')->where('product_id', $this->product->id)->update(['conversion_to_base' => '5']);
        $otherUnit = DB::table('units')->where('id', '!=', $this->product->base_unit_id)->value('id');
        $this->assertNotNull($otherUnit);
        DB::table('products')->where('id', $this->product->id)->update(['base_unit_id' => $otherUnit]);
        $this->action->execute($return, $this->owner, 'Historical return', '2026-11-06');
        $original = StockMovement::where('source_type', 'purchase_return')->where('source_id', $return->id)->sole();
        $inverse = StockMovement::where('source_type', 'purchase_return_void')->where('source_id', $return->id)->sole();
        $this->assertSame('4.000000', $inverse->quantity_delta_base);
        $this->assertSame($original->unit_id, $inverse->unit_id);
        $this->assertSame($original->conversion_to_base, $inverse->conversion_to_base);
    }

    public function test_downgrades_preserve_real_void_history(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [[
            'product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '30',
        ]]]);
        $return = $this->postReturn($this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '4']],
        ]));
        $voided = $this->action->execute($return, $this->owner, 'Preserve history', '2026-11-06');
        $before = DB::table('purchase_returns')->where('id', $voided->id)->first();
        $stock = DB::table('stock_movements')->count();
        $gl = DB::table('posting_lines')->count();
        foreach (['2026_11_07_000001_add_void_columns_to_purchase_returns_and_permissions.php', '2026_11_07_000002_add_purchase_void_business_dates.php'] as $file) {
            $migration = require database_path('migrations/'.$file);
            try {
                $migration->down();
                $this->fail('History downgrade must refuse.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Cannot remove', $exception->getMessage());
            }
            $this->assertEquals($before, DB::table('purchase_returns')->where('id', $voided->id)->first());
            $this->assertSame($stock, DB::table('stock_movements')->count());
            $this->assertSame($gl, DB::table('posting_lines')->count());
        }
    }

    public function test_void_columns_migration_round_trip(): void
    {
        $this->assertTrue(Schema::hasColumn('purchase_returns', 'voided_at'));
        $this->assertTrue(Schema::hasColumn('purchase_returns', 'void_reason'));
        $this->assertTrue(Schema::hasColumn('purchase_returns', 'voided_by'));
        $this->assertTrue(Schema::hasColumn('purchase_returns', 'void_posting_batch_id'));

        $this->artisan('migrate:rollback', ['--step' => 5])->assertExitCode(0);

        $this->assertFalse(Schema::hasColumn('purchase_returns', 'voided_at'));
        $this->assertFalse(Schema::hasColumn('purchase_returns', 'void_reason'));
        $this->assertFalse(Schema::hasColumn('purchase_returns', 'voided_by'));
        $this->assertFalse(Schema::hasColumn('purchase_returns', 'void_posting_batch_id'));

        $this->artisan('migrate');

        $this->assertTrue(Schema::hasColumn('purchase_returns', 'voided_at'));
        $this->assertTrue(Schema::hasColumn('purchase_returns', 'void_reason'));
        $this->assertTrue(Schema::hasColumn('purchase_returns', 'voided_by'));
        $this->assertTrue(Schema::hasColumn('purchase_returns', 'void_posting_batch_id'));
    }
}
