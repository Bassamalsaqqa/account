<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Models\InventoryCostState;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryRebuildService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseReturnReconciliationTest extends Phase5DTestCase
{
    /** Scenario 79: inventory reconciliation healthy for purchase return */
    public function test_scenario_79_inventory_reconciliation_healthy_for_purchase_return(): void
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

        $this->postReturn($draft);

        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);

        $this->assertTrue($report->isHealthy);
        $this->assertFalse($report->hasHistoryCorruption());
        $this->assertFalse($report->hasCacheDiscrepancies());
        $this->assertEmpty($report->discrepancies);
    }

    /** Scenario 80: accounting reconciliation healthy for purchase return */
    public function test_scenario_80_accounting_reconciliation_healthy_for_purchase_return(): void
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

        $this->postReturn($draft);

        $report = app(AccountingReconciliationService::class)->reconcile($this->company);

        $this->assertTrue($report->isHealthy);
        $this->assertEmpty($report->violations);
    }

    /** Scenario 81: sales reconciliation healthy for purchase return */
    public function test_scenario_81_sales_reconciliation_healthy_for_purchase_return(): void
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

        $this->postReturn($draft);

        $report = app(SalesReconciliationService::class)->reconcile($this->company);

        $this->assertTrue($report->isHealthy);
        $this->assertEmpty($report->violations);
    }

    /** Scenario 82: inventory rebuild reproduces purchase return */
    public function test_scenario_82_inventory_rebuild_reproduces_purchase_return(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '20'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '4']],
        ]);

        $this->postReturn($draft);

        // Before rebuild: cost state has 6 units, 120 value, 20 avg
        $costBefore = InventoryCostState::where('company_id', $this->company->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();

        $this->assertSame('6.000000', $costBefore->quantity_base);
        $this->assertSame('120.000000', $costBefore->inventory_value_base);
        $this->assertSame('20.000000', $costBefore->average_cost_base);

        // Rebuild inventory for company
        app(InventoryRebuildService::class)->rebuildForCompany($this->company);

        // Verify cost state was accurately reproduced
        $costAfter = InventoryCostState::where('company_id', $this->company->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();

        $this->assertSame('6.000000', $costAfter->quantity_base);
        $this->assertSame('120.000000', $costAfter->inventory_value_base);
        $this->assertSame('20.000000', $costAfter->average_cost_base);

        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($report->isHealthy);
    }

    /** Scenario 83: corrupt provenance detected by reconciliation */
    public function test_scenario_83_corrupt_provenance_detected_by_reconciliation(): void
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
        $movId = $posted->lines->first()->stock_movement_id;

        // Corrupt movement's reversal_of_id to point to an existing non-purchase movement (itself)
        DB::table('stock_movements')->where('id', $movId)->update(['reversal_of_id' => $movId]);

        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);

        $this->assertFalse($report->isHealthy);
        $this->assertTrue($report->hasHistoryCorruption());
    }

    /** Scenario 84: corrupt historical value detected and rebuild refused */
    public function test_scenario_84_corrupt_historical_value_detected_and_rebuild_refused(): void
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
        $movId = $posted->lines->first()->stock_movement_id;

        // Corrupt movement's value_delta_base
        DB::table('stock_movements')->where('id', $movId)->update(['value_delta_base' => '-999.000000']);

        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);

        $this->assertFalse($report->isHealthy);
        $this->assertTrue($report->hasHistoryCorruption());

        // Rebuild must refuse when historical values are corrupted
        try {
            app(InventoryRebuildService::class)->rebuildForCompany($this->company);
            $this->fail('Expected RuntimeException on corrupted movement rebuild');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot rebuild inventory: history corruption detected', $e->getMessage());
            $this->assertStringContainsString('purchase return value delta corrupt', $e->getMessage());
        }
    }

    /** C2-4: Reconciliation rejects invented line history and adjustment */
    public function test_c2_4_reconciliation_rejects_invented_line_history_and_adjustment(): void
    {
        $purchase = $this->createAndPostPurchase();
        $posted = $this->postReturn($this->createReturnDraft($purchase));

        DB::table('purchase_return_lines')->where('purchase_return_id', $posted->id)->update([
            'historical_receipt_value_base' => '999',
            'valuation_adjustment_base' => '999',
        ]);

        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertFalse($report->isHealthy, 'Reconciliation accepts arbitrary non-null immutable line values.');
        $this->assertTrue($report->hasHistoryCorruption());
    }

    /** Review regression: Reconciliation detects missing posted allocation values */
    public function test_reconciliation_detects_missing_posted_allocation_values(): void
    {
        $purchase = $this->createAndPostPurchase();
        $posted = $this->postReturn($this->createReturnDraft($purchase));

        DB::table('purchase_return_allocations')->where('purchase_return_id', $posted->id)->update([
            'historical_value_base' => null,
            'inventory_value_removed_base' => null,
        ]);

        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertFalse($report->isHealthy, 'Reconciliation accepts missing immutable historical and carrying-value provenance.');
        $this->assertTrue($report->hasHistoryCorruption());
    }
}
