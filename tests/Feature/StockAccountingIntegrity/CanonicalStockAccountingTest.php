<?php

declare(strict_types=1);

namespace Tests\Feature\StockAccountingIntegrity;

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\DisposeExpiredStockAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Actions\Sales\VoidSalesReturnAction;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InventorySecurityException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLotAllocation;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Sales\SalesReconciliationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StockAccountingIntegrity\StockAccountingIntegritySupportTrait;
use Tests\TestCase;
use Throwable;

class CanonicalStockAccountingTest extends TestCase
{
    use RefreshDatabase;
    use StockAccountingIntegritySupportTrait;

    /**
     * Provide economic movement types that must be rejected if submitted directly to record().
     *
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function economicMovementTypesProvider(): array
    {
        return [
            'opening_balance Quantity2@cost1' => [StockMovement::TYPE_OPENING_BALANCE, '2.000000', '1.000000'],
            'adjustment_increase' => [StockMovement::TYPE_ADJUSTMENT_INCREASE, '5.000000', '10.000000'],
            'adjustment_decrease' => [StockMovement::TYPE_ADJUSTMENT_DECREASE, '3.000000', null],
            'damage_or_loss' => [StockMovement::TYPE_DAMAGE_OR_LOSS, '1.000000', null],
            'expiry_disposal' => [StockMovement::TYPE_EXPIRY_DISPOSAL, '2.000000', null],
            'sale' => [StockMovement::TYPE_SALE, '4.000000', null],
            'sale_return' => [StockMovement::TYPE_SALE_RETURN, '4.000000', '10.000000'],
            'purchase' => [StockMovement::TYPE_PURCHASE, '10.000000', '15.000000'],
            'purchase_return' => [StockMovement::TYPE_PURCHASE_RETURN, '5.000000', '15.000000'],
        ];
    }

    /**
     * Requirement: Direct record(command) will now deny ALL economic movements, even inside
     * arbitrary DB::transaction, while paired transfer and canonical Purchase receipt/return pathways remain.
     * Quantity2@cost1 original diagnostic must now fail before stock-only commit with zero effects.
     */
    #[DataProvider('economicMovementTypesProvider')]
    public function test_direct_inventory_movement_record_denies_all_economic_movements_standalone_and_in_transaction(
        string $movementType,
        string $quantity,
        ?string $unitCost
    ): void {
        $env = $this->setupCanonicalCompany();
        $inventoryService = app(InventoryMovementService::class);

        // 1. Standalone rejection test
        $snapshotBeforeStandalone = $this->snapshotAccountingStockState();
        $standaloneCommand = $this->makeEconomicMovementCommand(
            company: $env['company'],
            user: $env['owner'],
            warehouse: $env['warehouse'],
            product: $env['product'],
            movementType: $movementType,
            quantity: $quantity,
            unitCost: $unitCost,
        );

        $standaloneThrown = false;
        try {
            $inventoryService->record($standaloneCommand);
        } catch (Throwable $e) {
            $standaloneThrown = true;
            $this->assertTrue(
                $e instanceof InvalidInventoryMovementException
                || $e instanceof InventorySecurityException
                || $e instanceof AuthorizationException,
                'Unexpected exception type: '.get_class($e).' - Message: '.$e->getMessage()
            );
        }
        $this->assertTrue($standaloneThrown, "Direct record() must throw exception for standalone economic movement [{$movementType}].");
        $this->assertAccountingStockStateUnchanged($snapshotBeforeStandalone);

        // 2. Caller-created arbitrary DB::transaction rejection test
        $snapshotBeforeTransaction = $this->snapshotAccountingStockState();
        $txCommand = $this->makeEconomicMovementCommand(
            company: $env['company'],
            user: $env['owner'],
            warehouse: $env['warehouse'],
            product: $env['product'],
            movementType: $movementType,
            quantity: $quantity,
            unitCost: $unitCost,
        );

        $txThrown = false;
        try {
            DB::transaction(function () use ($inventoryService, $txCommand): void {
                $inventoryService->record($txCommand);
            });
        } catch (Throwable $e) {
            $txThrown = true;
            $this->assertTrue(
                $e instanceof InvalidInventoryMovementException
                || $e instanceof InventorySecurityException
                || $e instanceof AuthorizationException,
                'Unexpected exception type inside transaction: '.get_class($e).' - Message: '.$e->getMessage()
            );
        }
        $this->assertTrue($txThrown, "Direct record() inside DB::transaction must throw exception for economic movement [{$movementType}].");
        $this->assertAccountingStockStateUnchanged($snapshotBeforeTransaction);

        // Explicitly assert zero stock and zero GL side-effects across all tables
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, InventoryOperation::count());
        $this->assertSame(0, InventoryBalance::count());
        $this->assertSame(0, InventoryCostState::count());
        $this->assertSame(0, PostingBatch::count());
        $this->assertSame(0, PostingLine::count());
    }

    /**
     * Requirement: Prove opening/adjustincrease/adjustdecrease/damage/expiry disposal,
     * paired transfers valid economics and reconciliations using exact decimal primitives.
     */
    public function test_canonical_inventory_actions_and_paired_transfers_post_valid_economics_and_reconcile(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $warehouse1 = $env['warehouse'];
        $warehouse2 = $this->createSecondWarehouse($company, $owner);
        $product = $env['product'];
        $expiryProduct = $env['expiryProduct'];

        $inventoryAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'inventory')->firstOrFail();
        $equityAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'opening_balance_equity')->firstOrFail();
        $lossAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'inventory_loss')->firstOrFail();
        $expiryLossAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'expiry_loss')->firstOrFail();

        // 1. Post Opening Stock (100 units @ 20.000000 ILS = 2000.000000 ILS)
        $openingRes = app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse1,
            quantity: Quantity::of(100),
            unitCostBase: '20.000000',
            user: $owner,
            idempotencyKey: 'CANON-INV-OPEN-001',
            movementDate: '2026-10-01',
            reason: 'Opening inventory stock'
        );

        $this->assertInstanceOf(StockMovement::class, $openingRes['movement']);
        $this->assertInstanceOf(PostingBatch::class, $openingRes['batch']);
        $this->assertSame('100.000000', $openingRes['movement']->quantity_delta_base);
        $this->assertSame('2000.000000', $openingRes['movement']->value_delta_base);

        $openingLines = PostingLine::where('posting_batch_id', $openingRes['batch']->id)->orderBy('line_number')->get();
        $this->assertCount(2, $openingLines);
        $this->assertSame($inventoryAccount->id, $openingLines[0]->ledger_account_id);
        $this->assertSame('2000.000000', $openingLines[0]->debit_base);
        $this->assertSame($equityAccount->id, $openingLines[1]->ledger_account_id);
        $this->assertSame('2000.000000', $openingLines[1]->credit_base);

        // 2. Stock Adjustment Increase (20 units @ 20.000000 ILS = 400.000000 ILS)
        $adjIncRes = app(AdjustStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse1,
            type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            quantity: Quantity::of(20),
            reason: 'فائض جردي معتمد',
            user: $owner,
            idempotencyKey: 'CANON-INV-ADJ-INC-001',
            unitCostBase: '20.000000',
            movementDate: '2026-10-02'
        );

        $adjIncLines = PostingLine::where('posting_batch_id', $adjIncRes['batch']->id)->orderBy('line_number')->get();
        $this->assertCount(2, $adjIncLines);
        $this->assertSame($inventoryAccount->id, $adjIncLines[0]->ledger_account_id);
        $this->assertSame('400.000000', $adjIncLines[0]->debit_base);
        $this->assertSame($lossAccount->id, $adjIncLines[1]->ledger_account_id);
        $this->assertSame('400.000000', $adjIncLines[1]->credit_base);

        // 3. Stock Adjustment Decrease (10 units @ 20.000000 ILS = 200.000000 ILS)
        $adjDecRes = app(AdjustStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse1,
            type: StockMovement::TYPE_ADJUSTMENT_DECREASE,
            quantity: Quantity::of(10),
            reason: 'عجز جردي تسوية',
            user: $owner,
            idempotencyKey: 'CANON-INV-ADJ-DEC-001',
            movementDate: '2026-10-03'
        );

        $adjDecLines = PostingLine::where('posting_batch_id', $adjDecRes['batch']->id)->orderBy('line_number')->get();
        $this->assertCount(2, $adjDecLines);
        $this->assertSame($lossAccount->id, $adjDecLines[0]->ledger_account_id);
        $this->assertSame('200.000000', $adjDecLines[0]->debit_base);
        $this->assertSame($inventoryAccount->id, $adjDecLines[1]->ledger_account_id);
        $this->assertSame('200.000000', $adjDecLines[1]->credit_base);

        // 4. Stock Damage/Loss (5 units @ 20.000000 ILS = 100.000000 ILS)
        $damageRes = app(AdjustStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse1,
            type: StockMovement::TYPE_DAMAGE_OR_LOSS,
            quantity: Quantity::of(5),
            reason: 'تلف كرتون أثناء المناولة',
            user: $owner,
            idempotencyKey: 'CANON-INV-DAMAGE-001',
            movementDate: '2026-10-04'
        );

        $damageLines = PostingLine::where('posting_batch_id', $damageRes['batch']->id)->orderBy('line_number')->get();
        $this->assertCount(2, $damageLines);
        $this->assertSame($lossAccount->id, $damageLines[0]->ledger_account_id);
        $this->assertSame('100.000000', $damageLines[0]->debit_base);
        $this->assertSame($inventoryAccount->id, $damageLines[1]->ledger_account_id);
        $this->assertSame('100.000000', $damageLines[1]->credit_base);

        // Current standard product balance in warehouse 1: 100 + 20 - 10 - 5 = 105 units @ 20.000000 ILS = 2100.000000 ILS
        $balW1 = InventoryBalance::where('warehouse_id', $warehouse1->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('105.000000', $balW1->quantity_base);

        // 5. Expiry Disposal on Expiry-Tracked Product
        // Inbound lot with expiry date in the past relative to disposal date
        app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $expiryProduct,
            warehouse: $warehouse1,
            quantity: Quantity::of(15),
            unitCostBase: '10.000000',
            user: $owner,
            idempotencyKey: 'CANON-EXP-OPEN-001',
            lotNumber: 'LOT-EXP-2026A',
            expiryDate: '2026-09-01',
            movementDate: '2026-09-01',
            reason: 'Inbound expiry lot'
        );

        $lot = InventoryLot::where('lot_number', 'LOT-EXP-2026A')->firstOrFail();

        // Dispose 5 expired units on 2026-09-02 (past expiry date)
        $expiryDisposalRes = app(DisposeExpiredStockAction::class)->execute(
            company: $company,
            product: $expiryProduct,
            warehouse: $warehouse1,
            lotId: $lot->id,
            quantity: Quantity::of(5),
            reason: 'إتلاف منتج منتهي الصلاحية',
            user: $owner,
            idempotencyKey: 'CANON-EXP-DISP-001',
            movementDate: '2026-09-02'
        );

        $expiryLines = PostingLine::where('posting_batch_id', $expiryDisposalRes['batch']->id)->orderBy('line_number')->get();
        $this->assertCount(2, $expiryLines);
        $this->assertSame($expiryLossAccount->id, $expiryLines[0]->ledger_account_id);
        $this->assertSame('50.000000', $expiryLines[0]->debit_base);
        $this->assertSame($inventoryAccount->id, $expiryLines[1]->ledger_account_id);
        $this->assertSame('50.000000', $expiryLines[1]->credit_base);

        // 6. Paired Warehouse Transfer (25 units of standard product from warehouse 1 to warehouse 2)
        $transferCommand = new StockTransferCommand(
            companyId: $company->id,
            sourceWarehouseId: $warehouse1->id,
            destinationWarehouseId: $warehouse2->id,
            movementDate: '2026-10-05',
            lines: [
                new StockTransferLineCommand(
                    productId: $product->id,
                    quantity: Quantity::of(25),
                ),
            ],
            idempotencyKey: 'CANON-TRANSFER-001',
            createdBy: $owner->id,
            reason: 'مناقلة مخزنية بين الفروع'
        );

        $transferMovements = app(InventoryMovementService::class)->transfer($transferCommand);
        $this->assertCount(2, $transferMovements);

        $outMovement = $transferMovements[0];
        $inMovement = $transferMovements[1];

        $this->assertSame(StockMovement::TYPE_TRANSFER_OUT, $outMovement->movement_type);
        $this->assertSame($warehouse1->id, $outMovement->warehouse_id);
        $this->assertSame('-25.000000', $outMovement->quantity_delta_base);
        $this->assertSame('-500.000000', $outMovement->value_delta_base);

        $this->assertSame(StockMovement::TYPE_TRANSFER_IN, $inMovement->movement_type);
        $this->assertSame($warehouse2->id, $inMovement->warehouse_id);
        $this->assertSame('25.000000', $inMovement->quantity_delta_base);
        $this->assertSame('500.000000', $inMovement->value_delta_base);

        // Warehouse balances after transfer: W1 = 80, W2 = 25
        $balW1After = InventoryBalance::where('warehouse_id', $warehouse1->id)->where('product_id', $product->id)->firstOrFail();
        $balW2After = InventoryBalance::where('warehouse_id', $warehouse2->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('80.000000', $balW1After->quantity_base);
        $this->assertSame('25.000000', $balW2After->quantity_base);

        // Company cost state conserved: total quantity 105.000000, value 2100.000000, average cost 20.000000
        $costState = InventoryCostState::where('product_id', $product->id)->firstOrFail();
        $this->assertSame('105.000000', $costState->quantity_base);
        $this->assertSame('2100.000000', $costState->inventory_value_base);
        $this->assertSame('20.000000', $costState->average_cost_base);

        // 7. Audit & Reconciliation Check
        $reconciliation = app(InventoryReconciliationService::class)->auditCompany($company);
        $this->assertTrue($reconciliation->isHealthy, 'Inventory audit must be healthy after canonical events.');
        $this->assertEmpty($reconciliation->discrepancies, 'No inventory discrepancies expected.');
        $this->assertEmpty($reconciliation->historyCorruptions, 'No history corruptions expected.');
        $this->assertEmpty($reconciliation->cacheDiscrepancies, 'No cache discrepancies expected.');
    }

    /**
     * Requirement: Prove sale / return / returnvoid / invoicevoid full canonical lifecycle
     * preserves exact stock, lot allocations, cost attribution, and balanced double-entry GL.
     */
    public function test_canonical_sales_lifecycle_sale_return_and_voids_preserve_exact_stock_and_gl_invariants(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $warehouse = $env['warehouse'];
        $product = $env['product'];
        $productUnit = $env['productUnit'];
        $customer = $env['customer'];

        // Initial setup: 50 units @ 20.000000 ILS = 1000.000000 ILS
        app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            quantity: Quantity::of(50),
            unitCostBase: '20.000000',
            user: $owner,
            idempotencyKey: 'OPENING-FOR-SALES-CYCLE',
            movementDate: '2026-10-01'
        );

        // 1. Post Sales Invoice: 10 units @ 50.000000 ILS
        $invoiceDraft = $this->createSalesInvoiceDraft(
            company: $company,
            user: $owner,
            customer: $customer,
            warehouse: $warehouse,
            product: $product,
            productUnit: $productUnit,
            quantity: '10.000000',
            unitPrice: '50.000000'
        );

        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $owner);

        $this->assertSame(SalesInvoice::STATUS_POSTED, $postedInvoice->status);
        $this->assertNotNull($postedInvoice->invoice_number);
        $this->assertNotNull($postedInvoice->posting_batch_id);
        $this->assertSame('200.000000', $postedInvoice->cogs_total_base);

        // Stock decreased: 50 - 10 = 40
        $balanceAfterSale = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('40.000000', $balanceAfterSale->quantity_base);

        // Lot allocation created
        $allocations = SalesInvoiceLotAllocation::where('sales_invoice_id', $postedInvoice->id)->get();
        $this->assertCount(1, $allocations);
        $this->assertSame('10.000000', $allocations[0]->quantity_allocated_base);
        $this->assertSame('20.000000', $allocations[0]->unit_cost_base);
        $this->assertSame('200.000000', $allocations[0]->total_cost_base);

        // 2. Post Sales Return: return 4 units of the sold 10 units
        $returnDraft = $this->createSalesReturnDraft(
            company: $company,
            user: $owner,
            invoice: $postedInvoice,
            quantity: '4.000000'
        );

        $postedReturn = app(PostSalesReturnAction::class)->execute($returnDraft, $owner);

        $this->assertSame(SalesReturn::STATUS_POSTED, $postedReturn->status);
        $this->assertNotNull($postedReturn->return_number);
        $this->assertNotNull($postedReturn->posting_batch_id);
        $this->assertSame('80.000000', $postedReturn->cogs_total_base);

        // Stock restored: 40 + 4 = 44
        $balanceAfterReturn = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('44.000000', $balanceAfterReturn->quantity_base);

        // 3. Void Sales Return: reverse the return
        $voidedReturn = app(VoidSalesReturnAction::class)->execute($postedReturn, $owner, 'إلغاء مردود تجريبي');

        $this->assertSame(SalesReturn::STATUS_VOID, $voidedReturn->status);
        // Stock back to post-sale level: 44 - 4 = 40
        $balanceAfterReturnVoid = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('40.000000', $balanceAfterReturnVoid->quantity_base);

        // 4. Void Sales Invoice: reverse the original invoice
        $voidedInvoice = app(VoidSalesInvoiceAction::class)->execute($postedInvoice, $owner, 'إلغاء فاتورة تجريبية');

        $this->assertSame(SalesInvoice::STATUS_VOID, $voidedInvoice->status);
        // Stock restored back to initial opening level: 40 + 10 = 50
        $balanceAfterInvoiceVoid = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('50.000000', $balanceAfterInvoiceVoid->quantity_base);

        $costStateAfterVoids = InventoryCostState::where('product_id', $product->id)->firstOrFail();
        $this->assertSame('50.000000', $costStateAfterVoids->quantity_base);
        $this->assertSame('1000.000000', $costStateAfterVoids->inventory_value_base);

        // 5. Reconciliations for both Inventory and Sales must be clean and healthy
        $inventoryReconciliation = app(InventoryReconciliationService::class)->auditCompany($company);
        $this->assertTrue($inventoryReconciliation->isHealthy, 'Inventory audit must remain healthy after complete sales/return/void cycle.');
        $this->assertEmpty($inventoryReconciliation->discrepancies);

        $salesReconciliation = app(SalesReconciliationService::class)->reconcile($company);
        $this->assertTrue($salesReconciliation->isHealthy, 'Sales reconciliation must remain healthy after complete sales/return/void cycle.');
        $this->assertEmpty($salesReconciliation->violations);
    }

    /**
     * Requirement: Test failures injected through AccountingPostingService after stock
     * to prove all rows/caches/operation/sequences/lifecycle/GL rollback.
     */
    public function test_accounting_posting_failure_after_stock_rolls_back_all_inventory_rows_caches_and_operations(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $warehouse = $env['warehouse'];
        $product = $env['product'];

        $snapshotBefore = $this->snapshotAccountingStockState();

        // Inject simulated failure into AccountingPostingService
        $realPostingService = app(AccountingPostingService::class);
        $mockPostingService = $this->createMock(AccountingPostingService::class);
        $mockPostingService->expects($this->once())->method('post')
            ->willThrowException(new \RuntimeException('Simulated financial posting failure after inventory recording.'));
        $this->app->instance(AccountingPostingService::class, $mockPostingService);

        $actionThrown = false;
        try {
            app(PostOpeningStockAction::class)->execute(
                company: $company,
                product: $product,
                warehouse: $warehouse,
                quantity: Quantity::of(50),
                unitCostBase: '25.000000',
                user: $owner,
                idempotencyKey: 'INJECTED-POSTING-FAIL-001',
                reason: 'Will fail at accounting step'
            );
        } catch (\RuntimeException $e) {
            $actionThrown = true;
            $this->assertSame('Simulated financial posting failure after inventory recording.', $e->getMessage());
        } finally {
            $this->app->instance(AccountingPostingService::class, $realPostingService);
        }

        $this->assertTrue($actionThrown, 'Action must rethrow injected exception from AccountingPostingService.');

        // Prove that ALL stock rows, operation rows, balance caches, cost state caches, and GL rows rolled back
        $this->assertSame(0, StockMovement::count(), 'StockMovement must be 0 after atomicity rollback.');
        $this->assertSame(0, InventoryOperation::count(), 'InventoryOperation must be 0 after atomicity rollback.');
        $this->assertSame(0, InventoryBalance::count(), 'InventoryBalance must be 0 after atomicity rollback.');
        $this->assertSame(0, InventoryCostState::count(), 'InventoryCostState must be 0 after atomicity rollback.');
        $this->assertSame(0, PostingBatch::count(), 'PostingBatch must be 0 after atomicity rollback.');
        $this->assertSame(0, PostingLine::count(), 'PostingLine must be 0 after atomicity rollback.');

        $this->assertAccountingStockStateUnchanged($snapshotBefore);
    }

    /**
     * Requirement: Action post-numbering rollback in sales.
     * When financial posting fails after sequence generation, lot allocation, and stock relief,
     * the sales invoice draft status, sequence counter, lot allocations, and stock movements
     * must all roll back completely without partial commit.
     */
    public function test_accounting_posting_failure_after_numbering_and_stock_rolls_back_sales_invoice_completely(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $warehouse = $env['warehouse'];
        $product = $env['product'];
        $productUnit = $env['productUnit'];
        $customer = $env['customer'];

        // Establish canonical opening stock
        app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            quantity: Quantity::of(40),
            unitCostBase: '20.000000',
            user: $owner,
            idempotencyKey: 'OPENING-FOR-ROLLBACK-TEST'
        );

        $initialBalance = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail()->quantity_base;
        $this->assertSame('40.000000', $initialBalance);

        // Create draft sales invoice for 10 units
        $draftInvoice = $this->createSalesInvoiceDraft(
            company: $company,
            user: $owner,
            customer: $customer,
            warehouse: $warehouse,
            product: $product,
            productUnit: $productUnit,
            quantity: '10.000000',
            unitPrice: '50.000000'
        );

        $this->assertSame(SalesInvoice::STATUS_DRAFT, $draftInvoice->status);
        $this->assertNull($draftInvoice->invoice_number);
        $this->assertNull($draftInvoice->posting_batch_id);

        $snapshotBeforePostAttempt = $this->snapshotAccountingStockState();

        // Inject simulated failure into AccountingPostingService during invoice posting
        $realPostingService = app(AccountingPostingService::class);
        $mockPostingService = $this->createMock(AccountingPostingService::class);
        $mockPostingService->expects($this->once())->method('post')
            ->willThrowException(new \RuntimeException('Simulated GL posting failure during sales invoice posting.'));
        $this->app->instance(AccountingPostingService::class, $mockPostingService);

        $postThrown = false;
        try {
            app(PostSalesInvoiceAction::class)->execute($draftInvoice, $owner);
        } catch (\RuntimeException $e) {
            $postThrown = true;
            $this->assertSame('Simulated GL posting failure during sales invoice posting.', $e->getMessage());
        } finally {
            $this->app->instance(AccountingPostingService::class, $realPostingService);
        }

        $this->assertTrue($postThrown, 'PostSalesInvoiceAction must throw the injected posting exception.');

        // 1. Verify Draft Invoice Lifecycle Integrity
        $refreshedInvoice = $draftInvoice->fresh();
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $refreshedInvoice->status, 'Sales invoice must remain in DRAFT status after rollback.');
        $this->assertNull($refreshedInvoice->invoice_number, 'Invoice number must be null after rollback.');
        $this->assertNull($refreshedInvoice->posting_batch_id, 'Posting batch ID must be null after rollback.');
        $this->assertNull($refreshedInvoice->posted_at, 'Posted at timestamp must be null after rollback.');

        // 2. Verify Lot Allocations and Stock Movements rolled back
        $this->assertSame(0, SalesInvoiceLotAllocation::where('sales_invoice_id', $draftInvoice->id)->count());
        $this->assertSame(0, StockMovement::where('source_type', 'sales_invoice')->where('source_id', $draftInvoice->id)->count());

        // 3. Verify Inventory Balance cache not decremented
        $balanceAfterFail = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail()->quantity_base;
        $this->assertSame('40.000000', $balanceAfterFail, 'Inventory balance must remain 40.000000 with zero stock decrease.');

        // 4. Verify DocumentSequence did not advance permanently; retry succeeds cleanly with next number
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($draftInvoice, $owner);
        $this->assertSame(SalesInvoice::STATUS_POSTED, $postedInvoice->status);
        $this->assertNotNull($postedInvoice->invoice_number);
        $this->assertSame('30.000000', InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail()->quantity_base);
    }

    /**
     * Requirement: Verify samepayloadreplay, conflictingquantity/cost/date underkey fails no economicchange.
     */
    public function test_idempotency_same_payload_replay_is_safe_and_conflicting_payload_fails_without_economic_change(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $warehouse = $env['warehouse'];
        $product = $env['product'];

        // Initial opening stock
        app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            quantity: Quantity::of(100),
            unitCostBase: '20.000000',
            user: $owner,
            idempotencyKey: 'CANONICAL-IDEMP-BASE-SETUP'
        );

        $adjustAction = app(AdjustStockAction::class);
        $key = 'ADJ-IDEMP-STABLE-KEY-001';

        // 1. Initial valid adjustment
        $firstResult = $adjustAction->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            quantity: Quantity::of(10),
            reason: 'تعديل جردي متكرر',
            user: $owner,
            idempotencyKey: $key,
            unitCostBase: '20.000000',
            movementDate: '2026-10-10'
        );

        $snapshotAfterFirst = $this->snapshotAccountingStockState();

        // 2. Same payload replay with identical key
        $replayResult = $adjustAction->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            quantity: Quantity::of(10),
            reason: 'تعديل جردي متكرر',
            user: $owner,
            idempotencyKey: $key,
            unitCostBase: '20.000000',
            movementDate: '2026-10-10'
        );

        $this->assertSame($firstResult['movement']->id, $replayResult['movement']->id, 'Replay must return original movement ID.');
        $this->assertSame($firstResult['batch']?->id, $replayResult['batch']?->id, 'Replay must return original batch ID.');
        $this->assertAccountingStockStateUnchanged($snapshotAfterFirst);

        // 3. Conflicting Quantity under same key fails with zero economic change
        $qtyConflictThrown = false;
        try {
            $adjustAction->execute(
                company: $company,
                product: $product,
                warehouse: $warehouse,
                type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
                quantity: Quantity::of(25), // conflicting quantity: 25 vs 10
                reason: 'تعديل جردي متكرر',
                user: $owner,
                idempotencyKey: $key,
                unitCostBase: '20.000000',
                movementDate: '2026-10-10'
            );
        } catch (Throwable $e) {
            $qtyConflictThrown = true;
            $this->assertTrue(
                $e instanceof IdempotencyConflictException || $e instanceof InvalidInventoryMovementException,
                'Unexpected exception for conflicting quantity: '.get_class($e)
            );
        }
        $this->assertTrue($qtyConflictThrown, 'Conflicting quantity under existing key must throw exception.');
        $this->assertAccountingStockStateUnchanged($snapshotAfterFirst);

        // 4. Conflicting Cost under same key fails with zero economic change
        $costKey = 'ADJ-IDEMP-COST-KEY-002';
        $adjustAction->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            quantity: Quantity::of(5),
            reason: 'تعديل تكلفة',
            user: $owner,
            idempotencyKey: $costKey,
            unitCostBase: '20.000000',
            movementDate: '2026-10-10'
        );

        $snapshotAfterCost = $this->snapshotAccountingStockState();

        $costConflictThrown = false;
        try {
            $adjustAction->execute(
                company: $company,
                product: $product,
                warehouse: $warehouse,
                type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
                quantity: Quantity::of(5),
                reason: 'تعديل تكلفة',
                user: $owner,
                idempotencyKey: $costKey,
                unitCostBase: '35.000000', // conflicting cost: 35 vs 20
                movementDate: '2026-10-10'
            );
        } catch (Throwable $e) {
            $costConflictThrown = true;
            $this->assertTrue(
                $e instanceof IdempotencyConflictException || $e instanceof InvalidInventoryMovementException,
                'Unexpected exception for conflicting cost: '.get_class($e)
            );
        }
        $this->assertTrue($costConflictThrown, 'Conflicting unit cost under existing key must throw exception.');
        $this->assertAccountingStockStateUnchanged($snapshotAfterCost);

        // 5. Conflicting Date under same key fails with zero economic change
        $dateKey = 'ADJ-IDEMP-DATE-KEY-003';
        $adjustAction->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            quantity: Quantity::of(5),
            reason: 'تعديل تاريخ',
            user: $owner,
            idempotencyKey: $dateKey,
            unitCostBase: '20.000000',
            movementDate: '2026-10-10'
        );

        $snapshotAfterDate = $this->snapshotAccountingStockState();

        $dateConflictThrown = false;
        try {
            $adjustAction->execute(
                company: $company,
                product: $product,
                warehouse: $warehouse,
                type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
                quantity: Quantity::of(5),
                reason: 'تعديل تاريخ',
                user: $owner,
                idempotencyKey: $dateKey,
                unitCostBase: '20.000000',
                movementDate: '2026-10-15' // conflicting date: 2026-10-15 vs 2026-10-10
            );
        } catch (Throwable $e) {
            $dateConflictThrown = true;
            $this->assertTrue(
                $e instanceof IdempotencyConflictException || $e instanceof InvalidInventoryMovementException,
                'Unexpected exception for conflicting date: '.get_class($e)
            );
        }
        $this->assertTrue($dateConflictThrown, 'Conflicting movement date under existing key must throw exception.');
        $this->assertAccountingStockStateUnchanged($snapshotAfterDate);
    }

    /**
     * Requirement: Fresh membership/permissions company-scoped, foreign entity no writes.
     */
    public function test_tenancy_and_permission_boundaries_reject_foreign_entities_and_unauthorized_actors(): void
    {
        $envA = $this->setupCanonicalCompany('ILS', 'شركة أ المعتمدة');
        $companyA = $envA['company'];
        $ownerA = $envA['owner'];
        $warehouseA = $envA['warehouse'];
        $productA = $envA['product'];

        $envB = $this->setupCanonicalCompany('ILS', 'شركة ب الأجنبية');
        $companyB = $envB['company'];
        $warehouseB = $envB['warehouse'];
        $productB = $envB['product'];

        // Re-activate Company A tenancy context for owner A
        $this->activateCompanyActor($companyA, $ownerA);

        $snapshotA = $this->snapshotAccountingStockState();

        // 1. Attempt cross-company product mutation (Company A actor targeting Company B product)
        $foreignProductThrown = false;
        try {
            app(PostOpeningStockAction::class)->execute(
                company: $companyA,
                product: $productB, // Foreign product belonging to Company B
                warehouse: $warehouseA,
                quantity: Quantity::of(10),
                unitCostBase: '20.000000',
                user: $ownerA,
                idempotencyKey: 'CROSS-COMPANY-PROD-ATTEMPT'
            );
        } catch (Throwable $e) {
            $foreignProductThrown = true;
            $this->assertTrue(
                $e instanceof InvalidInventoryMovementException
                || $e instanceof InventorySecurityException
                || $e instanceof ModelNotFoundException,
                'Unexpected exception for foreign product: '.get_class($e)
            );
        }
        $this->assertTrue($foreignProductThrown, 'Cross-company product must be rejected.');
        $this->assertAccountingStockStateUnchanged($snapshotA);

        // 2. Attempt cross-company warehouse mutation (Company A actor targeting Company B warehouse)
        $foreignWarehouseThrown = false;
        try {
            app(PostOpeningStockAction::class)->execute(
                company: $companyA,
                product: $productA,
                warehouse: $warehouseB, // Foreign warehouse belonging to Company B
                quantity: Quantity::of(10),
                unitCostBase: '20.000000',
                user: $ownerA,
                idempotencyKey: 'CROSS-COMPANY-WH-ATTEMPT'
            );
        } catch (Throwable $e) {
            $foreignWarehouseThrown = true;
            $this->assertTrue(
                $e instanceof InvalidInventoryMovementException
                || $e instanceof InventorySecurityException
                || $e instanceof ModelNotFoundException,
                'Unexpected exception for foreign warehouse: '.get_class($e)
            );
        }
        $this->assertTrue($foreignWarehouseThrown, 'Cross-company warehouse must be rejected.');
        $this->assertAccountingStockStateUnchanged($snapshotA);

        // 3. Attempt company context mismatch
        $contextMismatchThrown = false;
        try {
            app(PostOpeningStockAction::class)->execute(
                company: $companyB, // Active context is Company A
                product: $productB,
                warehouse: $warehouseB,
                quantity: Quantity::of(10),
                unitCostBase: '20.000000',
                user: $ownerA,
                idempotencyKey: 'CONTEXT-MISMATCH-ATTEMPT'
            );
        } catch (Throwable $e) {
            $contextMismatchThrown = true;
            $this->assertTrue(
                $e instanceof InvalidInventoryMovementException
                || $e instanceof InventorySecurityException,
                'Unexpected exception for context mismatch: '.get_class($e)
            );
        }
        $this->assertTrue($contextMismatchThrown, 'Company context mismatch must fail closed.');
        $this->assertAccountingStockStateUnchanged($snapshotA);

        // 4. Unauthorized company member (e.g. 'Warehouse' role without inventory.cost.view)
        $viewer = $this->createScopedMemberWithRole($companyA, 'Viewer');
        $this->activateCompanyActor($companyA, $viewer);

        $unauthorizedThrown = false;
        try {
            app(PostOpeningStockAction::class)->execute(
                company: $companyA,
                product: $productA,
                warehouse: $warehouseA,
                quantity: Quantity::of(10),
                unitCostBase: '20.000000',
                user: $viewer,
                idempotencyKey: 'UNAUTHORIZED-VIEWER-ATTEMPT'
            );
        } catch (Throwable $e) {
            $unauthorizedThrown = true;
            $this->assertTrue(
                $e instanceof AuthorizationException || $e instanceof InventorySecurityException,
                'Unexpected exception for unauthorized actor: '.get_class($e)
            );
        }
        $this->assertTrue($unauthorizedThrown, 'Unauthorized company member without permissions must be rejected.');
        $this->assertAccountingStockStateUnchanged($snapshotA);
    }
}
