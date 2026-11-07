<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\VoidPurchaseAction;
use App\Actions\Purchasing\VoidPurchaseReturnAction;
use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Support\TradeEventActivity;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Purchasing\Exceptions\PurchasingReversalBlockedException;
use App\Models\InventoryBalance;
use App\Models\LandedCostAllocation;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\PurchaseReadModel;
use App\Services\Purchasing\PurchaseVoidIntegrity;
use App\Services\Purchasing\PurchaseVoidScope;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Phase5D\Phase5DTestCase;

final class VoidPurchaseTest extends Phase5DTestCase
{
    public function test_void_actions_are_sealed(): void
    {
        $this->assertTrue((new \ReflectionClass(VoidPurchaseAction::class))->isFinal());
        $this->assertTrue((new \ReflectionClass(VoidPurchaseReturnAction::class))->isFinal());
    }

    public function test_historical_compensation_ignores_changed_conversion(): void
    {
        DB::table('units')->where('id', $this->product->base_unit_id)->update(['allows_fraction' => true]);
        $purchase = $this->createAndPostPurchase(['lines' => [[
            'product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '10',
        ]]]);
        DB::table('product_units')->where('company_id', $this->company->id)
            ->where('product_id', $this->product->id)->update(['conversion_to_base' => '5']);
        $voided = $this->action->execute($purchase, $this->owner, 'Historical units', '2026-11-06');
        $original = StockMovement::where('source_type', 'purchase')->where('source_id', $purchase->id)->sole();
        $inverse = StockMovement::where('source_type', 'purchase_void')->where('source_id', $purchase->id)->sole();
        $this->assertSame((string) BigDecimal::of($original->quantity_delta_base)->negated(), $inverse->quantity_delta_base);
        $this->assertSame($original->conversion_to_base, $inverse->conversion_to_base);
        $this->assertSame('2026-11-06', $voided->void_business_date->format('Y-m-d'));
        $this->assertSame($voided->id, $this->action->execute($purchase, $this->owner, 'Historical units', '2026-11-06')->id);
    }

    public function test_unauthenticated_inspector_rejects_before_reading_document(): void
    {
        auth()->logout();
        $this->expectException(AuthorizationException::class);
        $this->action->inspectBlockers(new Purchase(['company_id' => $this->company->id]));
    }

    public function test_ordinary_transaction_cannot_activate_canonical_scope(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [[
            'product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '10',
        ]]]);
        $this->expectException(InvalidInventoryMovementException::class);
        DB::transaction(fn () => app(PurchaseVoidScope::class)->withinCanonicalVoid(
            $this->action, $purchase, $this->owner, fn () => null
        ));
    }

    public function test_completion_checker_rejects_missing_compensation_and_rolls_back(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            ['product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '10'],
            ['product_id' => $this->product->id, 'quantity' => '3', 'unit_cost' => '10'],
        ]]);
        $beforeStock = DB::table('stock_movements')->count();
        $beforeGl = DB::table('posting_lines')->count();
        try {
            DB::transaction(function () use ($purchase): void {
                $voided = $this->action->execute($purchase, $this->owner, 'Missing leg', '2026-11-06');
                DB::table('stock_movements')->where('source_type', 'purchase_void')->where('source_id', $purchase->id)->orderBy('id')->limit(1)->delete();
                app(PurchaseVoidIntegrity::class)->assertComplete($voided);
            });
            $this->fail('Missing leg must fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Incomplete', $exception->getMessage());
        }
        $this->assertSame(Purchase::STATUS_POSTED, $purchase->fresh()->status);
        $this->assertSame($beforeStock, DB::table('stock_movements')->count());
        $this->assertSame($beforeGl, DB::table('posting_lines')->count());
    }

    public function test_zero_journal_business_date_drives_periods_and_retry_across_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 00:15:00', 'UTC'));
        try {
            $purchase = $this->createAndPostPurchase(['purchase_date' => '2026-12-01', 'lines' => [[
                'product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '0',
            ]]]);
            $voided = $this->action->execute($purchase, $this->owner, 'Future correction', '2026-12-03');
            $this->assertSame('2026-11-07', $voided->voided_at->format('Y-m-d'));
            $this->assertSame('2026-12-03', $voided->void_business_date->format('Y-m-d'));
            foreach (['2026-12-02' => 0, '2026-12-03' => 1, '2026-12-04' => 0] as $date => $expected) {
                $filters = new ReportFilters(
                    new ReportPeriod('custom', $date, $date, 'America/Los_Angeles')
                );
                $rows = TradeEventActivity::purchaseDocumentActivityQuery($this->company, $filters)->get();
                $this->assertSame($expected, $rows->where('event_type', 'inverse_purchase')->count());
            }
            $this->assertSame($voided->id, $this->action->execute($purchase, $this->owner, 'Future correction', '2026-12-03')->id);
        } finally {
            $this->travelBack();
        }
    }

    public static function inspectorDenials(): array
    {
        return [['unauthenticated'], ['inactive'], ['wrong_tenant'], ['cost_restricted']];
    }

    #[DataProvider('inspectorDenials')]
    public function test_both_inspectors_authorize_before_document_reads(string $scenario): void
    {
        $companyId = $this->company->id;
        if ($scenario === 'unauthenticated') {
            auth()->logout();
        } elseif ($scenario === 'inactive') {
            DB::table('company_user')->where('company_id', $companyId)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        } elseif ($scenario === 'wrong_tenant') {
            $companyId++;
        } else {
            $permission = DB::table('permissions')->where('name', 'purchasing.cost.view')->value('id');
            DB::table('role_has_permissions')->where('permission_id', $permission)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permission)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        foreach ([
            [$this->action, new Purchase(['company_id' => $companyId])],
            [app(VoidPurchaseReturnAction::class), new PurchaseReturn(['company_id' => $companyId])],
        ] as [$action, $document]) {
            try {
                $action->inspectBlockers($document);
                $this->fail('Inspector must deny before loading the nonexistent document.');
            } catch (AuthorizationException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    private VoidPurchaseAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(VoidPurchaseAction::class);
    }

    public function test_successful_purchase_void_reverses_stock_and_accounting(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '25.000000',
                ],
            ],
        ]);

        $this->assertSame(Purchase::STATUS_POSTED, $purchase->status);
        $originalBatchId = $purchase->posting_batch_id;
        $this->assertNotNull($originalBatchId);

        $initialBalance = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('10.000000', (string) $initialBalance->quantity_base);

        // Execute void
        $voided = $this->action->execute($purchase, $this->owner, 'Vendor delivered incorrect items', '2026-11-06');

        $this->assertSame(Purchase::STATUS_VOID, $voided->status);
        $this->assertSame('Vendor delivered incorrect items', $voided->void_reason);
        $this->assertSame($this->owner->id, $voided->voided_by);
        $this->assertNotNull($voided->voided_at);
        $this->assertNotNull($voided->void_posting_batch_id);

        // Verify GL reversal batch
        $reversalBatch = PostingBatch::where('company_id', $this->company->id)->findOrFail($voided->void_posting_batch_id);
        $this->assertSame(PostingBatch::STATUS_POSTED, $reversalBatch->status);
        $this->assertSame('reversal', $reversalBatch->source_type);
        $this->assertSame($originalBatchId, $reversalBatch->reversal_of_id);

        $originalBatch = PostingBatch::where('company_id', $this->company->id)->findOrFail($originalBatchId);
        $this->assertSame(PostingBatch::STATUS_REVERSED, $originalBatch->status);
        $this->assertSame($reversalBatch->id, $originalBatch->reversed_by_batch_id);

        // Verify stock movement
        $voidMovements = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase_void')
            ->where('source_id', $purchase->id)
            ->get();
        $this->assertCount(1, $voidMovements);
        $voidMove = $voidMovements->sole();
        $this->assertSame('-10.000000', (string) $voidMove->quantity_delta_base);

        // Verify inventory balance returned to 0
        $finalBalance = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('0.000000', (string) $finalBalance->quantity_base);

        // Verify reconciliation passes
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

    public function test_idempotent_retry_returns_converged_model_and_rejects_payload_mismatch(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5',
                    'unit_cost' => '10.000000',
                ],
            ],
        ]);

        $firstVoid = $this->action->execute($purchase, $this->owner, 'First cancellation reason', '2026-11-06');

        $this->assertSame(Purchase::STATUS_VOID, $firstVoid->status);

        // Retry with same payload -> succeeds and returns fresh model
        $retriedVoid = $this->action->execute($purchase, $this->owner, 'First cancellation reason', '2026-11-06');
        $this->assertSame($firstVoid->id, $retriedVoid->id);
        $this->assertSame($firstVoid->void_posting_batch_id, $retriedVoid->void_posting_batch_id);

        // Retry with mismatched reason -> throws IdempotencyConflictException
        $this->expectException(IdempotencyConflictException::class);
        $this->action->execute($purchase, $this->owner, 'Completely different reason', '2026-11-06');
    }

    public function test_zero_cost_purchase_void_does_not_create_artificial_journal_batch(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5',
                    'unit_cost' => '0.000000',
                ],
            ],
        ]);

        $this->assertSame(Purchase::STATUS_POSTED, $purchase->status);
        $this->assertNull($purchase->posting_batch_id);

        $voided = $this->action->execute($purchase, $this->owner, 'Sample donation cancelled', '2026-11-06');
        $this->assertSame('2026-11-06', $voided->void_business_date->format('Y-m-d'));
        $this->assertSame($voided->id, $this->action->execute($purchase, $this->owner, 'Sample donation cancelled', '2026-11-06')->id);

        $this->assertSame(Purchase::STATUS_VOID, $voided->status);
        $this->assertNull($voided->void_posting_batch_id);

        // Verify stock movement was recorded
        $voidMovements = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase_void')
            ->where('source_id', $purchase->id)
            ->get();
        $this->assertCount(1, $voidMovements);
        $this->assertSame('-5.000000', (string) $voidMovements->sole()->quantity_delta_base);

        // Reconciliations pass cleanly
        $reconReport = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($reconReport->isHealthy, json_encode($reconReport->discrepancies));
    }

    public function test_blocker_detection_when_purchase_has_active_payment_allocation(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '10.000000',
                ],
            ],
        ]);

        $cashAccount = MoneyAccount::where('company_id', $this->company->id)
            ->where('account_type', MoneyAccount::TYPE_CASH)
            ->firstOrFail();

        // Create and post vendor payment allocated to this purchase
        app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $cashAccount->id,
            'payment_date' => '2026-11-06',
            'payment_method' => 'cash',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'amount' => '50.000000',
            'idempotency_key' => 'vp_test_'.uniqid(),
            'allocations' => [
                [
                    'purchase_id' => $purchase->id,
                    'allocated_amount' => '50.000000',
                ],
            ],
        ]);

        // Inspect blockers
        $blockers = $this->action->inspectBlockers($purchase);
        $this->assertNotEmpty($blockers);
        $this->assertSame('active_vendor_payments', $blockers[0]['code']);

        // Execute must throw PurchasingReversalBlockedException
        try {
            $this->action->execute($purchase, $this->owner, 'Should fail');
            $this->fail('Expected PurchasingReversalBlockedException was not thrown');
        } catch (PurchasingReversalBlockedException $e) {
            $this->assertSame('active_vendor_payments', $e->blockers[0]['code']);
        }

        // Purchase remains posted
        $this->assertSame(Purchase::STATUS_POSTED, $purchase->fresh()->status);
    }

    public function test_blocker_detection_when_purchase_has_active_return(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '10.000000',
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $returnDraft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);
        $this->postReturn($returnDraft);

        // Inspect blockers
        $blockers = $this->action->inspectBlockers($purchase);
        $this->assertNotEmpty($blockers);
        $this->assertSame('linked_purchase_returns', $blockers[0]['code']);

        $this->expectException(PurchasingReversalBlockedException::class);
        $this->action->execute($purchase, $this->owner, 'Should fail');
    }

    public function test_blocker_detection_when_warehouse_stock_insufficient(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '10.000000',
                ],
            ],
        ]);

        // Manually reduce balance to simulate downstream sales/consumption
        $balance = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $balance->quantity_base = 3.0;
        $balance->save();

        $blockers = $this->action->inspectBlockers($purchase);
        $codes = array_column($blockers, 'code');
        $this->assertContains('insufficient_warehouse_stock', $codes);

        $this->expectException(PurchasingReversalBlockedException::class);
        $this->action->execute($purchase, $this->owner, 'Should fail');
    }

    public function test_permission_and_cross_tenant_boundaries(): void
    {
        $purchase = $this->createAndPostPurchase();

        // Create user without purchasing.purchase.void permission
        $unauthorizedUser = User::factory()->create();
        $this->company->users()->attach($unauthorizedUser);
        $this->activate($unauthorizedUser);

        $this->expectException(AuthorizationException::class);
        $this->action->execute($purchase, $unauthorizedUser, 'Unauthorized');
    }

    public function test_read_model_includes_void_metadata(): void
    {
        $purchase = $this->createAndPostPurchase();
        $voided = $this->action->execute($purchase, $this->owner, 'Audited cancellation', '2026-11-06');

        $readModel = app(PurchaseReadModel::class);
        $detail = $readModel->detail($voided, true);

        $this->assertSame(Purchase::STATUS_VOID, $detail['status']);
        $this->assertSame('Audited cancellation', $detail['void_reason']);
        $this->assertSame($this->owner->id, $detail['voided_by']);
        $this->assertSame($voided->voided_at->format('Y-m-d H:i'), $detail['voided_at']);
    }

    public function test_void_with_repeated_product_lines_aggregates_stock_and_reverses_cleanly(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '6',
                    'unit_cost' => '15.000000',
                ],
                [
                    'product_id' => $this->product->id,
                    'quantity' => '4',
                    'unit_cost' => '15.000000',
                ],
            ],
        ]);

        $this->assertSame(Purchase::STATUS_POSTED, $purchase->status);
        $this->assertCount(2, $purchase->lines);

        $balance = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('10.000000', (string) $balance->quantity_base);

        $voided = $this->action->execute($purchase, $this->owner, 'Repeated lines cancellation');
        $this->assertSame(Purchase::STATUS_VOID, $voided->status);

        $balanceAfter = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('0.000000', (string) $balanceAfter->quantity_base);

        $reconReport = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($reconReport->isHealthy, json_encode($reconReport->discrepancies));
    }

    public function test_insufficient_stock_detected_across_aggregated_repeated_lines(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5',
                    'unit_cost' => '10.000000',
                ],
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5',
                    'unit_cost' => '10.000000',
                ],
            ],
        ]);

        // Total required is 10. Set warehouse balance to 7 (greater than either line alone, but less than aggregate 10)
        DB::table('inventory_balances')
            ->where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity_base' => '7.000000']);

        $blockers = $this->action->inspectBlockers($purchase);
        $codes = array_column($blockers, 'code');
        $this->assertContains('insufficient_warehouse_stock', $codes);

        $stockBlocker = collect($blockers)->firstWhere('code', 'insufficient_warehouse_stock');
        $this->assertSame('10.000000', (string) $stockBlocker['details']['required']);
        $this->assertSame('7.000000', (string) $stockBlocker['details']['available']);

        $this->expectException(PurchasingReversalBlockedException::class);
        $this->action->execute($purchase, $this->owner, 'Should fail due to aggregate deficit');
    }

    public function test_idempotent_retry_rejects_date_mismatch(): void
    {
        $purchase = $this->createAndPostPurchase();
        $firstVoid = $this->action->execute($purchase, $this->owner, 'Cancel reason', '2026-11-06');
        $this->assertSame(Purchase::STATUS_VOID, $firstVoid->status);

        $this->expectException(IdempotencyConflictException::class);
        $this->action->execute($purchase, $this->owner, 'Cancel reason', '2026-11-07');
    }

    public function test_void_date_before_purchase_date_throws_invalid_argument(): void
    {
        $purchase = $this->createAndPostPurchase();

        $this->expectException(InvalidArgumentException::class);
        $this->action->execute($purchase, $this->owner, 'Should fail', '2020-01-01');
    }

    public function test_locked_landed_costs_block_purchase_void(): void
    {
        $purchase = $this->createAndPostPurchase();
        $line = $purchase->lines->first();
        $ledgerAccountId = DB::table('ledger_accounts')->where('company_id', $this->company->id)->value('id');

        $catId = DB::table('expense_categories')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'name_ar' => 'شحن',
            'code' => 'EXP-SHIP-'.uniqid(),
            'ledger_account_id' => $ledgerAccountId,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $expId = DB::table('expenses')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'expense_number' => 'EXP-'.uniqid(),
            'expense_date' => '2026-10-01',
            'category_id' => $catId,
            'category_snapshot' => json_encode(['name_ar' => 'شحن']),
            'classification' => 'landed_cost',
            'description' => 'Landed shipping',
            'currency_code' => 'ILS',
            'amount' => '10.000000',
            'exchange_rate' => '1.0000000000',
            'base_amount' => '10.000000',
            'payment_method' => 'cash',
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $this->owner->id,
            'request_hash' => hash('sha256', 'test'),
            'idempotency_key' => 'exp-idem-'.uniqid(),
            'created_by' => $this->owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed a locked landed cost allocation on this purchase
        DB::table('landed_cost_allocations')->insert([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'expense_id' => $expId,
            'purchase_id' => $purchase->id,
            'purchase_line_id' => $line->id,
            'allocation_method' => LandedCostAllocation::METHOD_MANUAL,
            'allocated_base' => '10.000000',
            'status' => LandedCostAllocation::STATUS_LOCKED,
            'locked_at' => now(),
            'created_at' => now(),
        ]);

        $blockers = $this->action->inspectBlockers($purchase);
        $codes = array_column($blockers, 'code');
        $this->assertContains('locked_landed_costs', $codes);

        $this->expectException(PurchasingReversalBlockedException::class);
        $this->action->execute($purchase, $this->owner, 'Should fail due to locked landed costs');
    }
}
