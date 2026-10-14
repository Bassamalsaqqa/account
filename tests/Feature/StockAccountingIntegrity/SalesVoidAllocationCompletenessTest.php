<?php

declare(strict_types=1);

namespace Tests\Feature\StockAccountingIntegrity;

use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Actions\Sales\VoidSalesReturnAction;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLotAllocation;
use App\Models\SalesReturn;
use App\Models\SalesReturnLotAllocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Sales\SalesReconciliationService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\StockAccountingIntegrity\StockAccountingIntegritySupportTrait;
use Tests\TestCase;

/**
 * Diagnostic regression test suite for PR47: Partial Allocation Loss Completeness.
 *
 * Core Concern:
 * When a posted stock-bearing SalesInvoice or SalesReturn has multiple lot allocations
 * (e.g., spanning multiple lots or multiple lines) and exactly ONE allocation is lost
 * while another remains, attempting canonical void built from the remaining allocations
 * must NOT proceed to reverse full GL or partially compensate stock.
 *
 * Existence-only guards (e.g. `isEmpty()`) and zero aggregate stock value cannot prove
 * quantity completeness. Void must reject with InvalidInventoryMovementException and exact
 * diagnostic 'sales_void_stock_provenance_incomplete', leaving the post-injection snapshot
 * byte-for-byte unchanged.
 *
 * Matrix: 12 complete/partial-loss, 6 inconsistent-allocation and 2 partial-compensation scenarios:
 * - 2 Document Kinds: Sales Invoice, Sales Return
 * - 3 Fixture Profiles:
 *     (1) Two lots, zero cost ('two_lots_zero_cost')
 *     (2) Two lots, nonzero cost ('two_lots_nonzero_cost')
 *     (3) Two distinct products, mixed cost, delete zero-cost allocation ('two_products_mixed_cost')
 * - 2 Outcomes: Positive (complete allocations) and Negative (partial loss injection)
 */
final class SalesVoidAllocationCompletenessTest extends TestCase
{
    use RefreshDatabase;
    use StockAccountingIntegritySupportTrait;

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    // =========================================================================
    // Data Providers (12 Scenarios Total: 6 Positive + 6 Negative)
    // =========================================================================

    /**
     * Positive complete allocation scenarios (6 cases).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function positiveVoidScenariosProvider(): array
    {
        return [
            'sales_invoice_two_lots_zero_cost_positive' => ['sales_invoice', 'two_lots_zero_cost'],
            'sales_invoice_two_lots_nonzero_cost_positive' => ['sales_invoice', 'two_lots_nonzero_cost'],
            'sales_invoice_two_products_mixed_cost_positive' => ['sales_invoice', 'two_products_mixed_cost'],
            'sales_return_two_lots_zero_cost_positive' => ['sales_return', 'two_lots_zero_cost'],
            'sales_return_two_lots_nonzero_cost_positive' => ['sales_return', 'two_lots_nonzero_cost'],
            'sales_return_two_products_mixed_cost_positive' => ['sales_return', 'two_products_mixed_cost'],
        ];
    }

    /**
     * Negative partial allocation loss scenarios (6 cases).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function negativeVoidScenariosProvider(): array
    {
        return [
            'sales_invoice_two_lots_zero_cost_negative' => ['sales_invoice', 'two_lots_zero_cost'],
            'sales_invoice_two_lots_nonzero_cost_negative' => ['sales_invoice', 'two_lots_nonzero_cost'],
            'sales_invoice_two_products_mixed_cost_negative' => ['sales_invoice', 'two_products_mixed_cost'],
            'sales_return_two_lots_zero_cost_negative' => ['sales_return', 'two_lots_zero_cost'],
            'sales_return_two_lots_nonzero_cost_negative' => ['sales_return', 'two_lots_nonzero_cost'],
            'sales_return_two_products_mixed_cost_negative' => ['sales_return', 'two_products_mixed_cost'],
        ];
    }

    // =========================================================================
    // Positive Control Tests: Complete Allocations Void Cleanly
    // =========================================================================

    /**
     * Positive controls: When lot allocations are fully complete, canonical void must
     * succeed cleanly, compensating all original quantities and lot identities, verifying
     * that Accounting, Inventory, and Sales reconciliations remain healthy, and confirming
     * that idempotent retry leaves the post-void snapshot byte-for-byte unchanged.
     */
    #[DataProvider('positiveVoidScenariosProvider')]
    public function test_positive_sales_void_with_complete_allocations_succeeds_reconciled_and_idempotent(
        string $documentKind,
        string $fixtureProfile
    ): void {
        $fixture = $this->buildDocumentFixture($documentKind, $fixtureProfile);
        /** @var Company $company */
        $company = $fixture['company'];
        /** @var User $owner */
        $owner = $fixture['owner'];
        /** @var SalesInvoice|SalesReturn $document */
        $document = $fixture['document'];
        /** @var Collection<int, SalesInvoiceLotAllocation|SalesReturnLotAllocation> $allocations */
        $allocations = $fixture['allocations'];

        // Confirm actors authorized, document posted, complete fixture canonical and healthy
        $this->assertTrue(
            $owner->hasPermissionTo($documentKind === 'sales_invoice' ? 'sales.invoice.void' : 'sales.return.void'),
            'Actor must have canonical void permission.'
        );
        $this->assertTrue($document->isPosted(), 'Document must be posted prior to void.');
        $this->assertCount(2, $allocations, 'Fixture must have exactly two complete allocations.');

        // Execute canonical void
        if ($documentKind === 'sales_invoice') {
            /** @var SalesInvoice $voidedDoc */
            $voidedDoc = app(VoidSalesInvoiceAction::class)->execute($document, $owner, 'Positive control canonical invoice void');
            $this->assertSame(SalesInvoice::STATUS_VOID, $voidedDoc->status);
            $this->assertNotNull($voidedDoc->void_posting_batch_id);

            // Verify compensating stock movements (type: sale_return, source: sales_invoice_void)
            $compMovements = StockMovement::where('company_id', $company->id)
                ->where('source_type', 'sales_invoice_void')
                ->where('source_id', $document->id)
                ->get();

            $this->assertCount($allocations->count(), $compMovements, 'Every allocation must receive a compensating stock movement.');
            foreach ($allocations as $alloc) {
                /** @var SalesInvoiceLotAllocation $alloc */
                $matching = $compMovements->first(fn (StockMovement $m) => (int) $m->reversal_of_id === (int) $alloc->stock_movement_id);
                $this->assertNotNull($matching, "Compensating movement must reverse original stock movement [{$alloc->stock_movement_id}].");
                $this->assertSame((int) $alloc->inventory_lot_id, (int) $matching->lot_id);
                $this->assertSame(
                    (string) BigDecimal::of((string) $alloc->quantity_allocated_base)->toScale(6),
                    (string) BigDecimal::of((string) $matching->quantity_delta_base)->toScale(6),
                    'Compensating quantity must match allocated quantity exactly.'
                );
            }
        } else {
            /** @var SalesReturn $voidedDoc */
            $voidedDoc = app(VoidSalesReturnAction::class)->execute($document, $owner, 'Positive control canonical return void');
            $this->assertSame(SalesReturn::STATUS_VOID, $voidedDoc->status);
            $this->assertNotNull($voidedDoc->void_posting_batch_id);

            // Verify compensating stock movements (type: sale, source: sales_return_void)
            $compMovements = StockMovement::where('company_id', $company->id)
                ->where('source_type', 'sales_return_void')
                ->where('source_id', $document->id)
                ->get();

            $this->assertCount($allocations->count(), $compMovements, 'Every return allocation must receive a compensating stock movement.');
            foreach ($allocations as $alloc) {
                /** @var SalesReturnLotAllocation $alloc */
                $originalMovement = $alloc->stockMovement;
                $this->assertNotNull($originalMovement, 'Return allocation must have referenced original stock movement.');
                $matching = $compMovements->first(
                    fn (StockMovement $m) => (int) $m->product_id === (int) $originalMovement->product_id
                        && (int) ($m->lot_id ?? 0) === (int) ($alloc->inventory_lot_id ?? 0)
                );
                $this->assertNotNull($matching, "Compensating movement must match returned product [{$originalMovement->product_id}] and lot [{$alloc->inventory_lot_id}].");
                $this->assertSame(
                    (string) BigDecimal::of((string) $alloc->quantity_allocated_base)->negated()->toScale(6),
                    (string) BigDecimal::of((string) $matching->quantity_delta_base)->toScale(6),
                    'Compensating quantity must match return allocation quantity (outbound removal).'
                );
            }
        }

        // Reconciliations: Accounting, Inventory, Sales
        $this->assertTrue(
            app(AccountingReconciliationService::class)->reconcile($company)->isHealthy,
            'Accounting reconciliation must remain healthy after positive void.'
        );
        $this->assertTrue(
            app(InventoryReconciliationService::class)->auditCompany($company)->isHealthy,
            'Inventory reconciliation must remain healthy after positive void.'
        );
        $this->assertTrue(
            app(SalesReconciliationService::class)->reconcile($company)->isHealthy,
            'Sales reconciliation must remain healthy after positive void.'
        );

        // Snapshot state immediately after positive void
        $postVoidSnapshot = $this->snapshotAccountingStockState();

        // Idempotent retry: must return voided document without additional side effects
        if ($documentKind === 'sales_invoice') {
            $retryDoc = app(VoidSalesInvoiceAction::class)->execute($voidedDoc->fresh(), $owner, 'Positive control retry void');
        } else {
            $retryDoc = app(VoidSalesReturnAction::class)->execute($voidedDoc->fresh(), $owner, 'Positive control retry void');
        }

        $this->assertSame($voidedDoc->status, $retryDoc->status);
        $this->assertSame($voidedDoc->id, $retryDoc->id);
        $this->assertAccountingStockStateUnchanged($postVoidSnapshot);
    }

    // =========================================================================
    // Negative Regression Tests: Partial Allocation Loss Refusal
    // =========================================================================

    /**
     * Negative regression tests: When exactly ONE allocation row is deliberately deleted
     * via DB::table SQL (simulating partial allocation loss) while another remains,
     * VoidSalesInvoiceAction / VoidSalesReturnAction MUST throw InvalidInventoryMovementException
     * with the exact diagnostic message 'sales_void_stock_provenance_incomplete'.
     *
     * The post-injection economic and provenance snapshot must remain byte-for-byte unchanged,
     * and the document must remain in POSTED status with no void posting batch.
     */
    #[DataProvider('negativeVoidScenariosProvider')]
    public function test_negative_sales_void_rejects_partial_allocation_loss_with_state_unchanged(
        string $documentKind,
        string $fixtureProfile
    ): void {
        $fixture = $this->buildDocumentFixture($documentKind, $fixtureProfile);
        /** @var Company $company */
        $company = $fixture['company'];
        /** @var User $owner */
        $owner = $fixture['owner'];
        /** @var SalesInvoice|SalesReturn $document */
        $document = $fixture['document'];
        /** @var Collection<int, SalesInvoiceLotAllocation|SalesReturnLotAllocation> $allocations */
        $allocations = $fixture['allocations'];

        // 1. Confirm actors authorized, document posted, complete fixture canonical and healthy before injection
        $this->assertTrue(
            $owner->hasPermissionTo($documentKind === 'sales_invoice' ? 'sales.invoice.void' : 'sales.return.void'),
            'Actor must have canonical void permission before injection.'
        );
        $this->assertTrue($document->isPosted(), 'Document must be posted prior to injection.');
        $this->assertCount(2, $allocations, 'Pre-injection fixture must have exactly two allocations.');
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($company)->isHealthy);
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($company)->isHealthy);
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($company)->isHealthy);

        // 2. Select exactly ONE allocation to delete, keeping another
        if ($fixtureProfile === 'two_products_mixed_cost') {
            // Distinct products mixed cost: explicitly target zero-cost allocation for deletion
            $allocToDelete = $allocations->firstWhere('unit_cost_base', '0.000000');
            $this->assertNotNull($allocToDelete, 'Must locate zero-cost allocation to delete in mixed-cost fixture.');
        } else {
            // Two lots of same cost: delete first allocation, keeping second
            $allocToDelete = $allocations->first();
        }

        $remainingAlloc = $allocations->firstWhere('id', '!=', $allocToDelete->id);
        $this->assertNotNull($remainingAlloc, 'A second allocation must remain untouched.');

        // 3. Preserve original immutable stock movement and assert missing allocation really belonged to it
        $originalStockMovementId = (int) $allocToDelete->stock_movement_id;
        $this->assertGreaterThan(0, $originalStockMovementId, 'Allocation must reference a valid stock movement.');

        /** @var StockMovement $originalMovement */
        $originalMovement = StockMovement::where('company_id', $company->id)
            ->where('id', $originalStockMovementId)
            ->firstOrFail();

        $this->assertSame((int) $allocToDelete->stock_movement_id, (int) $originalMovement->id);
        $this->assertSame((int) $company->id, (int) $originalMovement->company_id);

        $allocQty = BigDecimal::of((string) $allocToDelete->quantity_allocated_base)->abs()->toScale(6);
        $movementQty = BigDecimal::of((string) $originalMovement->quantity_delta_base)->abs()->toScale(6);
        $this->assertSame(
            (string) $allocQty,
            (string) $movementQty,
            'Missing allocation quantity must match its referenced stock movement quantity delta.'
        );

        DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
        // 4. Deliberate DB::table SQL delete exactly ONE allocation, keeping another
        $table = $documentKind === 'sales_invoice' ? 'sales_invoice_lot_allocations' : 'sales_return_lot_allocations';
        $fkColumn = $documentKind === 'sales_invoice' ? 'sales_invoice_id' : 'sales_return_id';

        $deleted = DB::table($table)->where('id', $allocToDelete->id)->delete();
        $this->assertSame(1, $deleted, "Exactly one allocation row must be deleted from [{$table}].");

        $remainingCount = DB::table($table)->where($fkColumn, $document->id)->count();
        $this->assertSame(1, $remainingCount, "Exactly one allocation must remain for document [{$document->id}] in [{$table}].");

        // Assert original immutable stock movement remains in database untouched
        $this->assertNotNull(
            StockMovement::where('company_id', $company->id)->where('id', $originalStockMovementId)->first(),
            "Original immutable stock movement [{$originalStockMovementId}] must be preserved in database."
        );

        // 5. Snapshot all economic/provenance rows AFTER injection
        $snapshot = $this->snapshotAccountingStockState();

        // 6. Require InvalidInventoryMovementException with exact diagnostic message 'sales_void_stock_provenance_incomplete'
        // Do not accept unrelated exception or catch PHPUnit failures.
        $caught = null;
        try {
            if ($documentKind === 'sales_invoice') {
                app(VoidSalesInvoiceAction::class)->execute($document, $owner, 'Fault injection: partial allocation void attempt');
            } else {
                app(VoidSalesReturnAction::class)->execute($document, $owner, 'Fault injection: partial allocation void attempt');
            }
        } catch (InvalidInventoryMovementException $e) {
            $caught = $e;
        }

        $this->assertNotNull(
            $caught,
            "Attempting to void [{$documentKind}] under [{$fixtureProfile}] with partial allocation loss must throw InvalidInventoryMovementException."
        );
        $this->assertSame(
            'sales_void_stock_provenance_incomplete',
            $caught->getMessage(),
            "Exception diagnostic message must match 'sales_void_stock_provenance_incomplete' exactly."
        );

        // 7. Byte-for-byte unchanged snapshots after rejection
        $this->assertAccountingStockStateUnchanged($snapshot);
        $this->assertFalse(app(InventoryReconciliationService::class)->auditCompany($company)->isHealthy);
        $this->assertAccountingStockStateUnchanged($snapshot);

        // 8. Document remains POSTED and void batch is null
        $freshDoc = $document->fresh();
        $this->assertSame(
            $documentKind === 'sales_invoice' ? SalesInvoice::STATUS_POSTED : SalesReturn::STATUS_POSTED,
            $freshDoc->status,
            'Document status must remain POSTED upon void rejection.'
        );
        $this->assertNull(
            $freshDoc->void_posting_batch_id,
            'Document void posting batch ID must remain null upon void rejection.'
        );
    }

    /** @return array<string, array{string, string}> */
    public static function inconsistentAllocationCases(): array
    {
        $cases = [];
        foreach (['sales_invoice', 'sales_return'] as $kind) {
            foreach (['quantity', 'lot', 'movement_identity'] as $damage) {
                $cases[$kind.'_'.$damage] = [$kind, $damage];
            }
        }

        return $cases;
    }

    #[DataProvider('inconsistentAllocationCases')]
    public function test_existing_but_inconsistent_allocation_is_refused_precisely(string $kind, string $damage): void
    {
        $fixture = $this->buildDocumentFixture($kind, 'two_lots_zero_cost');
        DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
        $document = $fixture['document'];
        $allocations = $fixture['allocations'];
        $this->assertCount(2, $allocations);
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($fixture['company'])->isHealthy);
        $first = $allocations->first();
        $second = $allocations->last();
        $replacement = match ($damage) {
            'quantity' => ['quantity_allocated_base' => '1.000000'],
            'lot' => ['inventory_lot_id' => $second->inventory_lot_id],
            'movement_identity' => ['stock_movement_id' => $second->stock_movement_id],
        };
        $table = $kind === 'sales_invoice' ? 'sales_invoice_lot_allocations' : 'sales_return_lot_allocations';
        DB::table($table)->where('id', $first->id)->update($replacement); // Explicit synthetic history corruption.
        $snapshot = $this->snapshotAccountingStockState();
        $failure = null;
        try {
            app($kind === 'sales_invoice' ? VoidSalesInvoiceAction::class : VoidSalesReturnAction::class)
                ->execute($document, $fixture['owner'], 'Synthetic mismatched allocation');
        } catch (InvalidInventoryMovementException $caught) {
            $failure = $caught;
        }
        $this->assertAccountingStockStateUnchanged($snapshot);
        $this->assertInstanceOf(InvalidInventoryMovementException::class, $failure);
        $this->assertSame('sales_void_stock_provenance_incomplete', $failure->getMessage());
        $this->assertSame('posted', $document->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function documentKinds(): array
    {
        return ['invoice' => ['sales_invoice'], 'return' => ['sales_return']];
    }

    #[DataProvider('documentKinds')]
    public function test_partial_persisted_compensation_rolls_back_all_started_effects(string $kind): void
    {
        $fixture = $this->buildDocumentFixture($kind, 'two_lots_zero_cost');
        DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
        $snapshot = $this->snapshotAccountingStockState();
        $real = app(InventoryMovementService::class);
        $this->mock(InventoryMovementService::class, function ($mock) use ($real): void {
            $mock->shouldReceive('recordCanonicalEvent')->once()->andReturnUsing(function ($command, $capability) use ($real) {
                $movements = $real->recordCanonicalEvent($command, $capability);
                $this->assertCount(2, $movements);
                // Fault injection after real canonical stock/cache/lot effects, before GL.
                $this->assertSame(1, DB::table('stock_movements')->where('id', $movements[0]->id)->delete());

                return $movements;
            });
        });
        $failure = null;
        try {
            app($kind === 'sales_invoice' ? VoidSalesInvoiceAction::class : VoidSalesReturnAction::class)
                ->execute($fixture['document'], $fixture['owner'], 'Synthetic partial compensation');
        } catch (InvalidInventoryMovementException $caught) {
            $failure = $caught;
        }
        $this->assertAccountingStockStateUnchanged($snapshot);
        $this->assertInstanceOf(InvalidInventoryMovementException::class, $failure);
        $this->assertSame('sales_void_stock_provenance_incomplete', $failure->getMessage());
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($fixture['company'])->isHealthy);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($fixture['company'])->isHealthy);
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($fixture['company'])->isHealthy);
    }

    // =========================================================================
    // Canonical Fixture Builder Helper
    // =========================================================================

    /**
     * Build canonical fixtures for positive and negative scenarios.
     *
     * @return array{
     *     company: Company,
     *     owner: User,
     *     warehouse: Warehouse,
     *     document: SalesInvoice|SalesReturn,
     *     allocations: Collection<int, SalesInvoiceLotAllocation|SalesReturnLotAllocation>
     * }
     */
    protected function buildDocumentFixture(string $documentKind, string $fixtureProfile): array
    {
        $env = $this->setupCanonicalCompany();
        /** @var Company $company */
        $company = $env['company'];
        /** @var User $owner */
        $owner = $env['owner'];
        /** @var Warehouse $warehouse */
        $warehouse = $env['warehouse'];
        /** @var Customer $customer */
        $customer = $env['customer'];

        if ($fixtureProfile === 'two_lots_zero_cost') {
            /** @var Product $expiryProduct */
            $expiryProduct = $env['expiryProduct'];
            /** @var ProductUnit $expiryProductUnit */
            $expiryProductUnit = $env['expiryProductUnit'];

            // Opening stock Lot 1: 10 units @ 0.000000 ILS
            app(PostOpeningStockAction::class)->execute(
                company: $company,
                product: $expiryProduct,
                warehouse: $warehouse,
                quantity: Quantity::of('10.000000'),
                unitCostBase: '0.000000',
                user: $owner,
                idempotencyKey: 'OPENING-LOT-ZERO-1',
                lotNumber: 'LOT-ZERO-1',
                expiryDate: '2027-01-01',
                movementDate: '2026-10-01'
            );

            // Opening stock Lot 2: 10 units @ 0.000000 ILS
            app(PostOpeningStockAction::class)->execute(
                company: $company,
                product: $expiryProduct,
                warehouse: $warehouse,
                quantity: Quantity::of('10.000000'),
                unitCostBase: '0.000000',
                user: $owner,
                idempotencyKey: 'OPENING-LOT-ZERO-2',
                lotNumber: 'LOT-ZERO-2',
                expiryDate: '2027-06-01',
                movementDate: '2026-10-01'
            );

            // Sales invoice selling 15 units (spans Lot 1 [10 units] and Lot 2 [5 units] via FEFO)
            $draftInvoice = $this->createSalesInvoiceDraft(
                company: $company,
                user: $owner,
                customer: $customer,
                warehouse: $warehouse,
                product: $expiryProduct,
                productUnit: $expiryProductUnit,
                quantity: '15.000000',
                unitPrice: '50.000000',
                issueDate: '2026-10-05'
            );
            $postedInvoice = app(PostSalesInvoiceAction::class)->execute($draftInvoice, $owner);

            if ($documentKind === 'sales_invoice') {
                $allocations = SalesInvoiceLotAllocation::where('sales_invoice_id', $postedInvoice->id)->orderBy('id')->get();

                return [
                    'company' => $company,
                    'owner' => $owner,
                    'warehouse' => $warehouse,
                    'document' => $postedInvoice,
                    'allocations' => $allocations,
                ];
            }

            // Sales Return returning all 15 units
            $draftReturn = $this->createSalesReturnDraft(
                company: $company,
                user: $owner,
                invoice: $postedInvoice,
                quantity: '15.000000',
                reason: 'Canonical complete return of two-lot zero-cost sale'
            );
            $postedReturn = app(PostSalesReturnAction::class)->execute($draftReturn, $owner);
            $allocations = SalesReturnLotAllocation::where('sales_return_id', $postedReturn->id)->orderBy('id')->get();

            return [
                'company' => $company,
                'owner' => $owner,
                'warehouse' => $warehouse,
                'document' => $postedReturn,
                'allocations' => $allocations,
            ];
        }

        if ($fixtureProfile === 'two_lots_nonzero_cost') {
            /** @var Product $expiryProduct */
            $expiryProduct = $env['expiryProduct'];
            /** @var ProductUnit $expiryProductUnit */
            $expiryProductUnit = $env['expiryProductUnit'];

            // Opening stock Lot 1: 10 units @ 15.000000 ILS
            app(PostOpeningStockAction::class)->execute(
                company: $company,
                product: $expiryProduct,
                warehouse: $warehouse,
                quantity: Quantity::of('10.000000'),
                unitCostBase: '15.000000',
                user: $owner,
                idempotencyKey: 'OPENING-LOT-NZ-1',
                lotNumber: 'LOT-NZ-1',
                expiryDate: '2027-01-01',
                movementDate: '2026-10-01'
            );

            // Opening stock Lot 2: 10 units @ 15.000000 ILS
            app(PostOpeningStockAction::class)->execute(
                company: $company,
                product: $expiryProduct,
                warehouse: $warehouse,
                quantity: Quantity::of('10.000000'),
                unitCostBase: '15.000000',
                user: $owner,
                idempotencyKey: 'OPENING-LOT-NZ-2',
                lotNumber: 'LOT-NZ-2',
                expiryDate: '2027-06-01',
                movementDate: '2026-10-01'
            );

            // Sales invoice selling 15 units (spans Lot 1 [10 units] and Lot 2 [5 units] via FEFO)
            $draftInvoice = $this->createSalesInvoiceDraft(
                company: $company,
                user: $owner,
                customer: $customer,
                warehouse: $warehouse,
                product: $expiryProduct,
                productUnit: $expiryProductUnit,
                quantity: '15.000000',
                unitPrice: '50.000000',
                issueDate: '2026-10-05'
            );
            $postedInvoice = app(PostSalesInvoiceAction::class)->execute($draftInvoice, $owner);

            if ($documentKind === 'sales_invoice') {
                $allocations = SalesInvoiceLotAllocation::where('sales_invoice_id', $postedInvoice->id)->orderBy('id')->get();

                return [
                    'company' => $company,
                    'owner' => $owner,
                    'warehouse' => $warehouse,
                    'document' => $postedInvoice,
                    'allocations' => $allocations,
                ];
            }

            // Sales Return returning all 15 units
            $draftReturn = $this->createSalesReturnDraft(
                company: $company,
                user: $owner,
                invoice: $postedInvoice,
                quantity: '15.000000',
                reason: 'Canonical complete return of two-lot nonzero-cost sale'
            );
            $postedReturn = app(PostSalesReturnAction::class)->execute($draftReturn, $owner);
            $allocations = SalesReturnLotAllocation::where('sales_return_id', $postedReturn->id)->orderBy('id')->get();

            return [
                'company' => $company,
                'owner' => $owner,
                'warehouse' => $warehouse,
                'document' => $postedReturn,
                'allocations' => $allocations,
            ];
        }

        if ($fixtureProfile === 'two_products_mixed_cost') {
            // Distinct products required because moving-average costing blends single-product lots
            /** @var Product $zeroProduct */
            $zeroProduct = $env['product'];
            /** @var ProductUnit $zeroProductUnit */
            $zeroProductUnit = $env['productUnit'];

            /** @var Product $costedProduct */
            $costedProduct = app(ProductCatalogService::class)->createProduct($company, [
                'name_ar' => 'منتج تجريبي بتكلفة محددة',
                'name_en' => 'Costed Test Product',
                'sku' => 'SKU-COST-002',
                'product_type' => Product::TYPE_STOCK,
                'track_stock' => true,
                'track_expiry' => false,
                'base_unit_id' => $env['unit']->id,
            ], $owner->id);

            /** @var ProductUnit $costedProductUnit */
            $costedProductUnit = ProductUnit::where('product_id', $costedProduct->id)
                ->where('is_base', true)
                ->firstOrFail();

            // Opening stock for Product A (zero cost): 10 units @ 0.000000 ILS
            app(PostOpeningStockAction::class)->execute(
                company: $company,
                product: $zeroProduct,
                warehouse: $warehouse,
                quantity: Quantity::of('10.000000'),
                unitCostBase: '0.000000',
                user: $owner,
                idempotencyKey: 'OPENING-MIXED-ZERO',
                movementDate: '2026-10-01'
            );

            // Opening stock for Product B (costed): 10 units @ 20.000000 ILS
            app(PostOpeningStockAction::class)->execute(
                company: $company,
                product: $costedProduct,
                warehouse: $warehouse,
                quantity: Quantity::of('10.000000'),
                unitCostBase: '20.000000',
                user: $owner,
                idempotencyKey: 'OPENING-MIXED-COSTED',
                movementDate: '2026-10-01'
            );

            // Multi-line Sales Invoice: Line 1 (Zero Cost) + Line 2 (Nonzero Cost)
            $draftInvoice = app(CreateSalesInvoiceDraftAction::class)->execute($company, $owner, [
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'currency_code' => $company->base_currency_code,
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-10-05',
                'due_date' => '2026-11-04',
                'lines' => [
                    [
                        'product_id' => $zeroProduct->id,
                        'product_unit_id' => $zeroProductUnit->id,
                        'item_description' => $zeroProduct->name_ar,
                        'quantity' => '10.000000',
                        'unit_price' => '40.000000',
                        'discount_type' => 'none',
                        'discount_value' => '0.000000',
                        'tax_rate_id' => null,
                    ],
                    [
                        'product_id' => $costedProduct->id,
                        'product_unit_id' => $costedProductUnit->id,
                        'item_description' => $costedProduct->name_ar,
                        'quantity' => '10.000000',
                        'unit_price' => '60.000000',
                        'discount_type' => 'none',
                        'discount_value' => '0.000000',
                        'tax_rate_id' => null,
                    ],
                ],
            ]);
            $postedInvoice = app(PostSalesInvoiceAction::class)->execute($draftInvoice, $owner);

            if ($documentKind === 'sales_invoice') {
                $allocations = SalesInvoiceLotAllocation::where('sales_invoice_id', $postedInvoice->id)->orderBy('id')->get();

                return [
                    'company' => $company,
                    'owner' => $owner,
                    'warehouse' => $warehouse,
                    'document' => $postedInvoice,
                    'allocations' => $allocations,
                ];
            }

            // Multi-line Sales Return returning both sold lines in full
            $invLines = $postedInvoice->lines()->orderBy('line_number')->get();
            $returnLinesPayload = [];
            foreach ($invLines as $invLine) {
                $returnLinesPayload[] = [
                    'sales_invoice_line_id' => $invLine->id,
                    'quantity' => (string) $invLine->quantity,
                ];
            }

            $draftReturn = app(CreateSalesReturnDraftAction::class)->execute($company, $owner, [
                'sales_invoice_id' => $postedInvoice->id,
                'issue_date' => '2026-10-06',
                'reason' => 'Canonical complete return of multi-product mixed-cost sale',
                'lines' => $returnLinesPayload,
            ]);
            $postedReturn = app(PostSalesReturnAction::class)->execute($draftReturn, $owner);
            $allocations = SalesReturnLotAllocation::where('sales_return_id', $postedReturn->id)->orderBy('id')->get();

            return [
                'company' => $company,
                'owner' => $owner,
                'warehouse' => $warehouse,
                'document' => $postedReturn,
                'allocations' => $allocations,
            ];
        }

        throw new \InvalidArgumentException("Unsupported fixture profile [{$fixtureProfile}].");
    }
}
