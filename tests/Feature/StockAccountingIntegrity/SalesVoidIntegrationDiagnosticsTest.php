<?php

declare(strict_types=1);

namespace Tests\Feature\StockAccountingIntegrity;

use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Actions\Sales\VoidSalesReturnAction;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\InventoryBalance;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLotAllocation;
use App\Models\SalesReturn;
use App\Models\SalesReturnLotAllocation;
use App\Models\StockMovement;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Sales\SalesReconciliationService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StockAccountingIntegrity\StockAccountingIntegritySupportTrait;
use Tests\TestCase;

/**
 * Diagnostic regression tests for Sales Invoice and Sales Return void integration concerns:
 *
 * Concern 1:
 * Posted stock-bearing SalesInvoice and SalesReturn with missing allocation rows
 * must reject voiding to prevent GL reversals without compensating inventory movements.
 * Post-corruption economic snapshots must remain unchanged upon rejection.
 * Zero-cost stock must preserve physical presence checks.
 * Legitimate service-only / no-stock documents serve as positive controls.
 *
 * Concern 2:
 * Company timezone boundaries (e.g. Asia/Hebron at UTC 2026-10-14 23:30 = local day 2026-10-15)
 * must govern both compensating stock movements and GL reversal batches.
 * Stock movement and GL reversal posting dates must be identical and idempotent on retry.
 */
final class SalesVoidIntegrationDiagnosticsTest extends TestCase
{
    use RefreshDatabase;
    use StockAccountingIntegritySupportTrait;

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    // =========================================================================
    // Concern 1: Missing Allocation Rows Fault Injection & Rejection Requirements
    // =========================================================================

    /**
     * Concern 1: A posted stock-bearing SalesInvoice with nonzero-cost stock
     * whose lot allocation rows are missing must reject voiding, leaving the post-corruption
     * economic snapshot completely unchanged.
     */
    public function test_void_sales_invoice_rejects_when_nonzero_cost_allocations_missing_and_state_remains_unchanged(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $warehouse = $env['warehouse'];
        $product = $env['product'];
        $productUnit = $env['productUnit'];
        $customer = $env['customer'];

        // 1. Establish canonical opening stock: 50 units @ 20.000000 ILS = 1000.000000 ILS
        app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            quantity: Quantity::of(50),
            unitCostBase: '20.000000',
            user: $owner,
            idempotencyKey: 'OPENING-NONZERO-INVOICE-VOID',
            movementDate: '2026-10-01'
        );

        // 2. Post canonical stock-bearing SalesInvoice: 10 units @ 50.000000 ILS
        $invoiceDraft = $this->createSalesInvoiceDraft(
            company: $company,
            user: $owner,
            customer: $customer,
            warehouse: $warehouse,
            product: $product,
            productUnit: $productUnit,
            quantity: '10.000000',
            unitPrice: '50.000000',
            issueDate: '2026-10-05'
        );
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $owner);

        $this->assertSame(SalesInvoice::STATUS_POSTED, $postedInvoice->status);
        $this->assertSame('200.000000', $postedInvoice->cogs_total_base);

        // Confirm allocation row exists and stock balance reflects consumption
        $allocationsBefore = SalesInvoiceLotAllocation::where('sales_invoice_id', $postedInvoice->id)->get();
        $this->assertCount(1, $allocationsBefore);
        $this->assertSame('10.000000', $allocationsBefore->first()->quantity_allocated_base);

        $balanceBefore = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('40.000000', $balanceBefore->quantity_base);

        // 3. Test-only SQL fault injection: delete all allocation rows for this invoice
        DB::table('sales_invoice_lot_allocations')
            ->where('sales_invoice_id', $postedInvoice->id)
            ->delete();

        $this->assertCount(0, SalesInvoiceLotAllocation::where('sales_invoice_id', $postedInvoice->id)->get());

        // Snapshot economic state immediately after corruption
        $snapshot = $this->snapshotAccountingStockState();

        // 4. Attempt void: must reject to prevent GL void without compensating stock
        $failure = null;
        try {
            app(VoidSalesInvoiceAction::class)->execute($postedInvoice, $owner, 'محاولة إلغاء فاتورة مبيعات مع مخصصات مفقودة');
        } catch (InvalidInventoryMovementException $caught) {
            $failure = $caught;
        }
        $this->assertAccountingStockStateUnchanged($snapshot);
        $this->assertInstanceOf(InvalidInventoryMovementException::class, $failure);
        $this->assertSame('sales_void_stock_provenance_incomplete', $failure->getMessage());

        $freshInvoice = $postedInvoice->fresh();
        $this->assertSame(SalesInvoice::STATUS_POSTED, $freshInvoice->status);
        $this->assertNull($freshInvoice->void_posting_batch_id);

        $balanceAfter = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('40.000000', $balanceAfter->quantity_base);
    }

    /**
     * Concern 1: A posted stock-bearing SalesInvoice with zero-cost stock
     * whose lot allocation rows are missing must reject voiding to preserve physical
     * inventory balance quantity, leaving state unchanged.
     */
    public function test_void_sales_invoice_rejects_when_zero_cost_allocations_missing_and_state_remains_unchanged(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $warehouse = $env['warehouse'];
        $product = $env['product'];
        $productUnit = $env['productUnit'];
        $customer = $env['customer'];

        // 1. Establish canonical zero-cost opening stock: 50 units @ 0.000000 ILS = 0.000000 ILS
        app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            quantity: Quantity::of(50),
            unitCostBase: '0.000000',
            user: $owner,
            idempotencyKey: 'OPENING-ZERO-INVOICE-VOID',
            movementDate: '2026-10-01'
        );

        // 2. Post canonical stock-bearing SalesInvoice for zero-cost product: 10 units @ 50.000000 ILS
        $invoiceDraft = $this->createSalesInvoiceDraft(
            company: $company,
            user: $owner,
            customer: $customer,
            warehouse: $warehouse,
            product: $product,
            productUnit: $productUnit,
            quantity: '10.000000',
            unitPrice: '50.000000',
            issueDate: '2026-10-05'
        );
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $owner);

        $this->assertSame(SalesInvoice::STATUS_POSTED, $postedInvoice->status);
        $this->assertSame('0.000000', $postedInvoice->cogs_total_base);

        $allocationsBefore = SalesInvoiceLotAllocation::where('sales_invoice_id', $postedInvoice->id)->get();
        $this->assertCount(1, $allocationsBefore);
        $this->assertSame('10.000000', $allocationsBefore->first()->quantity_allocated_base);
        $this->assertSame('0.000000', $allocationsBefore->first()->unit_cost_base);

        $balanceBefore = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('40.000000', $balanceBefore->quantity_base);

        // 3. Test-only SQL fault injection: delete allocation rows
        DB::table('sales_invoice_lot_allocations')
            ->where('sales_invoice_id', $postedInvoice->id)
            ->delete();

        $snapshot = $this->snapshotAccountingStockState();

        // 4. Attempt void: must reject even at zero cost because physical quantity restoration is required
        $failure = null;
        try {
            app(VoidSalesInvoiceAction::class)->execute($postedInvoice, $owner, 'محاولة إلغاء فاتورة صفرية التكلفة مع مخصصات مفقودة');
        } catch (InvalidInventoryMovementException $caught) {
            $failure = $caught;
        }
        $this->assertAccountingStockStateUnchanged($snapshot);
        $this->assertInstanceOf(InvalidInventoryMovementException::class, $failure);
        $this->assertSame('sales_void_stock_provenance_incomplete', $failure->getMessage());

        $freshInvoice = $postedInvoice->fresh();
        $this->assertSame(SalesInvoice::STATUS_POSTED, $freshInvoice->status);
        $this->assertNull($freshInvoice->void_posting_batch_id);

        $balanceAfter = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('40.000000', $balanceAfter->quantity_base);
    }

    /**
     * Concern 1: A posted stock-bearing SalesReturn with nonzero-cost stock
     * whose lot allocation rows are missing must reject voiding to prevent GL reversal
     * without removing the returned inventory, leaving state unchanged.
     */
    public function test_void_sales_return_rejects_when_nonzero_cost_allocations_missing_and_state_remains_unchanged(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $warehouse = $env['warehouse'];
        $product = $env['product'];
        $productUnit = $env['productUnit'];
        $customer = $env['customer'];

        // 1. Establish opening stock: 50 units @ 20.000000 ILS
        app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            quantity: Quantity::of(50),
            unitCostBase: '20.000000',
            user: $owner,
            idempotencyKey: 'OPENING-NONZERO-RETURN-VOID',
            movementDate: '2026-10-01'
        );

        // 2. Post invoice (10 units) and return (4 units)
        $invoiceDraft = $this->createSalesInvoiceDraft(
            company: $company,
            user: $owner,
            customer: $customer,
            warehouse: $warehouse,
            product: $product,
            productUnit: $productUnit,
            quantity: '10.000000',
            unitPrice: '50.000000',
            issueDate: '2026-10-05'
        );
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $owner);

        $returnDraft = $this->createSalesReturnDraft(
            company: $company,
            user: $owner,
            invoice: $postedInvoice,
            quantity: '4.000000',
            reason: 'إرجاع تجريبي معتمد'
        );
        $postedReturn = app(PostSalesReturnAction::class)->execute($returnDraft, $owner);

        $this->assertSame(SalesReturn::STATUS_POSTED, $postedReturn->status);
        $this->assertSame('80.000000', $postedReturn->cogs_total_base);

        $returnAllocsBefore = SalesReturnLotAllocation::where('sales_return_id', $postedReturn->id)->get();
        $this->assertCount(1, $returnAllocsBefore);
        $this->assertSame('4.000000', $returnAllocsBefore->first()->quantity_allocated_base);

        $balanceBefore = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('44.000000', $balanceBefore->quantity_base);

        // 3. Test-only SQL fault injection: delete all return allocation rows
        DB::table('sales_return_lot_allocations')
            ->where('sales_return_id', $postedReturn->id)
            ->delete();

        $snapshot = $this->snapshotAccountingStockState();

        // 4. Attempt void: must reject to prevent GL reversal without compensating outbound stock movement
        $failure = null;
        try {
            app(VoidSalesReturnAction::class)->execute($postedReturn, $owner, 'محاولة إلغاء مردود مبيعات مع مخصصات مفقودة');
        } catch (InvalidInventoryMovementException $caught) {
            $failure = $caught;
        }
        $this->assertAccountingStockStateUnchanged($snapshot);
        $this->assertInstanceOf(InvalidInventoryMovementException::class, $failure);
        $this->assertSame('sales_void_stock_provenance_incomplete', $failure->getMessage());

        $freshReturn = $postedReturn->fresh();
        $this->assertSame(SalesReturn::STATUS_POSTED, $freshReturn->status);
        $this->assertNull($freshReturn->void_posting_batch_id);

        $balanceAfter = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('44.000000', $balanceAfter->quantity_base);
    }

    /**
     * Concern 1: A posted stock-bearing SalesReturn with zero-cost stock
     * whose lot allocation rows are missing must reject voiding to preserve physical
     * inventory balance quantity, leaving state unchanged.
     */
    public function test_void_sales_return_rejects_when_zero_cost_allocations_missing_and_state_remains_unchanged(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $warehouse = $env['warehouse'];
        $product = $env['product'];
        $productUnit = $env['productUnit'];
        $customer = $env['customer'];

        // 1. Establish zero-cost opening stock: 50 units @ 0.000000 ILS
        app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            quantity: Quantity::of(50),
            unitCostBase: '0.000000',
            user: $owner,
            idempotencyKey: 'OPENING-ZERO-RETURN-VOID',
            movementDate: '2026-10-01'
        );

        // 2. Post invoice (10 units) and return (4 units)
        $invoiceDraft = $this->createSalesInvoiceDraft(
            company: $company,
            user: $owner,
            customer: $customer,
            warehouse: $warehouse,
            product: $product,
            productUnit: $productUnit,
            quantity: '10.000000',
            unitPrice: '50.000000',
            issueDate: '2026-10-05'
        );
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $owner);

        $returnDraft = $this->createSalesReturnDraft(
            company: $company,
            user: $owner,
            invoice: $postedInvoice,
            quantity: '4.000000',
            reason: 'إرجاع صفري التكلفة معتمد'
        );
        $postedReturn = app(PostSalesReturnAction::class)->execute($returnDraft, $owner);

        $this->assertSame(SalesReturn::STATUS_POSTED, $postedReturn->status);
        $this->assertSame('0.000000', $postedReturn->cogs_total_base);

        $returnAllocsBefore = SalesReturnLotAllocation::where('sales_return_id', $postedReturn->id)->get();
        $this->assertCount(1, $returnAllocsBefore);
        $this->assertSame('4.000000', $returnAllocsBefore->first()->quantity_allocated_base);

        $balanceBefore = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('44.000000', $balanceBefore->quantity_base);

        // 3. Test-only SQL fault injection: delete return allocation rows
        DB::table('sales_return_lot_allocations')
            ->where('sales_return_id', $postedReturn->id)
            ->delete();

        $snapshot = $this->snapshotAccountingStockState();

        // 4. Attempt void: must reject to preserve physical inventory balance quantity
        $failure = null;
        try {
            app(VoidSalesReturnAction::class)->execute($postedReturn, $owner, 'محاولة إلغاء مردود صفري التكلفة مع مخصصات مفقودة');
        } catch (InvalidInventoryMovementException $caught) {
            $failure = $caught;
        }
        $this->assertAccountingStockStateUnchanged($snapshot);
        $this->assertInstanceOf(InvalidInventoryMovementException::class, $failure);
        $this->assertSame('sales_void_stock_provenance_incomplete', $failure->getMessage());

        $freshReturn = $postedReturn->fresh();
        $this->assertSame(SalesReturn::STATUS_POSTED, $freshReturn->status);
        $this->assertNull($freshReturn->void_posting_batch_id);

        $balanceAfter = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('44.000000', $balanceAfter->quantity_base);
    }

    /**
     * Concern 1 Positive Control: A legitimate service-only (no-stock) SalesInvoice
     * has no stock movements and no lot allocations. Its void must succeed cleanly,
     * reversing the GL batch without touching stock tables.
     */
    public function test_positive_control_service_only_sales_invoice_void_succeeds_without_stock_movement(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $unit = $env['unit'];
        $customer = $env['customer'];

        // 1. Create a canonical service product (track_stock = false)
        $serviceProduct = app(ProductCatalogService::class)->createProduct($company, [
            'name_ar' => 'خدمة صيانة واستشارات',
            'name_en' => 'Maintenance and Consulting Service',
            'sku' => 'SRV-TEST-001',
            'product_type' => Product::TYPE_SERVICE,
            'track_stock' => false,
            'track_expiry' => false,
            'base_unit_id' => $unit->id,
        ], $owner->id);

        $serviceProductUnit = ProductUnit::where('product_id', $serviceProduct->id)
            ->where('is_base', true)
            ->firstOrFail();

        // 2. Post draft service invoice
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($company, $owner, [
            'customer_id' => $customer->id,
            'currency_code' => $company->base_currency_code,
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $serviceProduct->id,
                    'product_unit_id' => $serviceProductUnit->id,
                    'item_description' => $serviceProduct->name_ar,
                    'quantity' => '1.000000',
                    'unit_price' => '150.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($draft, $owner);

        $this->assertSame(SalesInvoice::STATUS_POSTED, $postedInvoice->status);
        $this->assertNotNull($postedInvoice->posting_batch_id);
        $this->assertSame('0.000000', $postedInvoice->cogs_total_base);
        $this->assertCount(0, SalesInvoiceLotAllocation::where('sales_invoice_id', $postedInvoice->id)->get());
        $this->assertCount(0, StockMovement::where('company_id', $company->id)->get());

        // 3. Void service invoice: must succeed cleanly as positive control
        $voidedInvoice = app(VoidSalesInvoiceAction::class)->execute($postedInvoice, $owner, 'إلغاء فاتورة خدمة معتمدة');

        $this->assertSame(SalesInvoice::STATUS_VOID, $voidedInvoice->status);
        $this->assertNotNull($voidedInvoice->void_posting_batch_id);

        // Posting batch reversed
        $originalBatch = PostingBatch::where('id', $postedInvoice->posting_batch_id)->firstOrFail();
        $this->assertSame(PostingBatch::STATUS_REVERSED, $originalBatch->status);
        $this->assertSame($voidedInvoice->void_posting_batch_id, $originalBatch->reversed_by_batch_id);

        // Zero stock movements throughout
        $this->assertCount(0, StockMovement::where('company_id', $company->id)->get());

        // Reconciliations remain clean
        $salesAudit = app(SalesReconciliationService::class)->reconcile($company);
        $this->assertTrue($salesAudit->isHealthy);
    }

    /**
     * Concern 1 Positive Control: A legitimate service-only (no-stock) SalesReturn
     * has no stock movements and no lot allocations. Its void must succeed cleanly,
     * reversing the GL batch without touching stock tables.
     */
    public function test_positive_control_service_only_sales_return_void_succeeds_without_stock_movement(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $unit = $env['unit'];
        $customer = $env['customer'];

        // 1. Create a canonical service product
        $serviceProduct = app(ProductCatalogService::class)->createProduct($company, [
            'name_ar' => 'خدمة صيانة دورية',
            'name_en' => 'Periodic Maintenance Service',
            'sku' => 'SRV-TEST-002',
            'product_type' => Product::TYPE_SERVICE,
            'track_stock' => false,
            'track_expiry' => false,
            'base_unit_id' => $unit->id,
        ], $owner->id);

        $serviceProductUnit = ProductUnit::where('product_id', $serviceProduct->id)
            ->where('is_base', true)
            ->firstOrFail();

        // 2. Post invoice and return for service product
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($company, $owner, [
            'customer_id' => $customer->id,
            'currency_code' => $company->base_currency_code,
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $serviceProduct->id,
                    'product_unit_id' => $serviceProductUnit->id,
                    'item_description' => $serviceProduct->name_ar,
                    'quantity' => '1.000000',
                    'unit_price' => '200.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($draft, $owner);

        $returnDraft = $this->createSalesReturnDraft(
            company: $company,
            user: $owner,
            invoice: $postedInvoice,
            quantity: '1.000000',
            reason: 'إلغاء تقديم الخدمة'
        );
        $postedReturn = app(PostSalesReturnAction::class)->execute($returnDraft, $owner);

        $this->assertSame(SalesReturn::STATUS_POSTED, $postedReturn->status);
        $this->assertNotNull($postedReturn->posting_batch_id);
        $this->assertSame('0.000000', $postedReturn->cogs_total_base);
        $this->assertCount(0, SalesReturnLotAllocation::where('sales_return_id', $postedReturn->id)->get());
        $this->assertCount(0, StockMovement::where('company_id', $company->id)->get());

        // 3. Void service return: must succeed cleanly as positive control
        $voidedReturn = app(VoidSalesReturnAction::class)->execute($postedReturn, $owner, 'إلغاء مردود خدمة معتمد');

        $this->assertSame(SalesReturn::STATUS_VOID, $voidedReturn->status);
        $this->assertNotNull($voidedReturn->void_posting_batch_id);

        $returnBatch = PostingBatch::where('id', $postedReturn->posting_batch_id)->firstOrFail();
        $this->assertSame(PostingBatch::STATUS_REVERSED, $returnBatch->status);
        $this->assertSame($voidedReturn->void_posting_batch_id, $returnBatch->reversed_by_batch_id);

        // Zero stock movements throughout
        $this->assertCount(0, StockMovement::where('company_id', $company->id)->get());

        $salesAudit = app(SalesReconciliationService::class)->reconcile($company);
        $this->assertTrue($salesAudit->isHealthy);
    }

    // =========================================================================
    // Concern 2: Timezone Company-Date Stock, GL Reversal & Idempotent Retry
    // =========================================================================

    /**
     * Concern 2: In a company with timezone Asia/Hebron when the system time is 2026-10-14 23:30 UTC
     * (which corresponds to local business day 2026-10-15 in Asia/Hebron):
     * VoidSalesInvoiceAction must assign company day 2026-10-15 to:
     *   1. The compensating stock movement (movement_date).
     *   2. The accounting reversal batch (posting_date).
     * And an idempotent second call must succeed without creating extra records.
     *
     * Note: Expected to fail on current code because VoidSalesInvoiceAction uses Carbon::today()
     * (evaluating to UTC 2026-10-14), and reverseStock does not pass postingDate (evaluating to UTC 2026-10-14).
     */
    public function test_sales_invoice_void_uses_company_date_for_stock_and_reversal_gl_with_idempotent_retry(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $warehouse = $env['warehouse'];
        $product = $env['product'];
        $productUnit = $env['productUnit'];
        $customer = $env['customer'];

        // Configure Asia/Hebron timezone and UTC application clock
        $company->update(['timezone' => 'Asia/Hebron']);
        config(['app.timezone' => 'UTC']);

        // Historical document creation on 2026-10-10
        $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00:00', 'UTC'));

        app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            quantity: Quantity::of(50),
            unitCostBase: '20.000000',
            user: $owner,
            idempotencyKey: 'OPENING-TZ-INVOICE-VOID',
            movementDate: '2026-10-10'
        );

        $invoiceDraft = $this->createSalesInvoiceDraft(
            company: $company,
            user: $owner,
            customer: $customer,
            warehouse: $warehouse,
            product: $product,
            productUnit: $productUnit,
            quantity: '10.000000',
            unitPrice: '50.000000',
            issueDate: '2026-10-10'
        );
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $owner);

        // Advance clock to day boundary: 2026-10-14 23:30:00 UTC = 2026-10-15 02:30:00 Asia/Hebron
        $this->travelTo(CarbonImmutable::parse('2026-10-14 23:30:00', 'UTC'));

        // Execute void
        $voidedInvoice = app(VoidSalesInvoiceAction::class)->execute($postedInvoice, $owner, 'إلغاء فاتورة عند حد اليوم في توقيت الشركة');

        $this->assertSame(SalesInvoice::STATUS_VOID, $voidedInvoice->status);

        // Economic snapshot immediately after void
        $postVoidSnapshot = $this->snapshotAccountingStockState();

        // 1. Idempotent retry returns voided invoice with zero extra side-effects across all tables
        $retryVoid = app(VoidSalesInvoiceAction::class)->execute($voidedInvoice->fresh(), $owner, 'إلغاء مكرر');
        $this->assertSame(SalesInvoice::STATUS_VOID, $retryVoid->status);
        $this->assertSame($voidedInvoice->id, $retryVoid->id);
        $this->assertAccountingStockStateUnchanged($postVoidSnapshot);

        $this->assertCount(
            1,
            StockMovement::where('company_id', $company->id)
                ->where('source_type', 'sales_invoice_void')
                ->where('source_id', $postedInvoice->id)
                ->get()
        );
        $this->assertCount(
            1,
            PostingBatch::where('company_id', $company->id)
                ->where('reversal_of_id', $postedInvoice->posting_batch_id)
                ->get()
        );

        // 2. Multi-domain health checks on legitimate voided fixture
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($company)->isHealthy, 'Accounting reconciliation must remain healthy after invoice void.');
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($company)->isHealthy, 'Inventory reconciliation must remain healthy after invoice void.');
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($company)->isHealthy, 'Sales reconciliation must remain healthy after invoice void.');

        // 3. EXPECTED: Compensating stock movement must use company business date 2026-10-15
        $voidStockMovements = StockMovement::where('company_id', $company->id)
            ->where('source_type', 'sales_invoice_void')
            ->where('source_id', $postedInvoice->id)
            ->get();

        $this->assertCount(1, $voidStockMovements);
        $this->assertSame(
            '2026-10-15',
            $voidStockMovements->first()->movement_date->format('Y-m-d'),
            'Invoice void compensating stock movement must use company business date (2026-10-15), not UTC app date (2026-10-14).'
        );

        // 4. EXPECTED: GL reversal batch must use company business date 2026-10-15
        $reversalBatch = PostingBatch::where('company_id', $company->id)
            ->where('reversal_of_id', $postedInvoice->posting_batch_id)
            ->firstOrFail();

        $this->assertSame(
            '2026-10-15',
            $reversalBatch->posting_date->format('Y-m-d'),
            'Invoice void GL reversal batch must use company business date (2026-10-15), not UTC app date (2026-10-14).'
        );

        // 5. EXPECTED: Stock movement date and GL reversal posting date must be identical
        $this->assertSame(
            $voidStockMovements->first()->movement_date->format('Y-m-d'),
            $reversalBatch->posting_date->format('Y-m-d'),
            'Compensating stock movement and void reversal GL batch must have identical posting dates.'
        );
    }

    /**
     * Concern 2: In a company with timezone Asia/Hebron when the system time is 2026-10-14 23:30 UTC
     * (which corresponds to local business day 2026-10-15 in Asia/Hebron):
     * VoidSalesReturnAction must assign company day 2026-10-15 to:
     *   1. The compensating stock movement (movement_date).
     *   2. The accounting reversal batch (posting_date).
     * And an idempotent second call must succeed without creating extra records.
     *
     * Note: Expected to fail on current code because reverseStock does not pass postingDate,
     * causing the GL reversal batch to fall back to now()->toDateString() in UTC (2026-10-14),
     * diverging from the compensating stock movement date (2026-10-15).
     */
    public function test_sales_return_void_uses_company_date_for_stock_and_reversal_gl_with_idempotent_retry(): void
    {
        $env = $this->setupCanonicalCompany();
        $company = $env['company'];
        $owner = $env['owner'];
        $warehouse = $env['warehouse'];
        $product = $env['product'];
        $productUnit = $env['productUnit'];
        $customer = $env['customer'];

        $company->update(['timezone' => 'Asia/Hebron']);
        config(['app.timezone' => 'UTC']);

        // Historical document creation on 2026-10-10
        $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00:00', 'UTC'));

        app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            quantity: Quantity::of(50),
            unitCostBase: '20.000000',
            user: $owner,
            idempotencyKey: 'OPENING-TZ-RETURN-VOID',
            movementDate: '2026-10-10'
        );

        $invoiceDraft = $this->createSalesInvoiceDraft(
            company: $company,
            user: $owner,
            customer: $customer,
            warehouse: $warehouse,
            product: $product,
            productUnit: $productUnit,
            quantity: '10.000000',
            unitPrice: '50.000000',
            issueDate: '2026-10-10'
        );
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $owner);

        $returnDraft = $this->createSalesReturnDraft(
            company: $company,
            user: $owner,
            invoice: $postedInvoice,
            quantity: '4.000000',
            reason: 'إرجاع في تاريخ سابق'
        );
        $postedReturn = app(PostSalesReturnAction::class)->execute($returnDraft, $owner);

        // Advance clock to day boundary: 2026-10-14 23:30:00 UTC = 2026-10-15 02:30:00 Asia/Hebron
        $this->travelTo(CarbonImmutable::parse('2026-10-14 23:30:00', 'UTC'));

        // Execute void return
        $voidedReturn = app(VoidSalesReturnAction::class)->execute($postedReturn, $owner, 'إلغاء مردود عند حد اليوم في توقيت الشركة');

        $this->assertSame(SalesReturn::STATUS_VOID, $voidedReturn->status);

        // Economic snapshot immediately after return void
        $postVoidSnapshot = $this->snapshotAccountingStockState();

        // 1. Idempotent retry returns voided return with zero extra side-effects across all tables
        $retryVoid = app(VoidSalesReturnAction::class)->execute($voidedReturn->fresh(), $owner, 'إلغاء مردود مكرر');
        $this->assertSame(SalesReturn::STATUS_VOID, $retryVoid->status);
        $this->assertSame($voidedReturn->id, $retryVoid->id);
        $this->assertAccountingStockStateUnchanged($postVoidSnapshot);

        $this->assertCount(
            1,
            StockMovement::where('company_id', $company->id)
                ->where('source_type', 'sales_return_void')
                ->where('source_id', $postedReturn->id)
                ->get()
        );
        $this->assertCount(
            1,
            PostingBatch::where('company_id', $company->id)
                ->where('reversal_of_id', $postedReturn->posting_batch_id)
                ->get()
        );

        // 2. Multi-domain health checks on legitimate voided fixture
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($company)->isHealthy, 'Accounting reconciliation must remain healthy after return void.');
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($company)->isHealthy, 'Inventory reconciliation must remain healthy after return void.');
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($company)->isHealthy, 'Sales reconciliation must remain healthy after return void.');

        // 3. EXPECTED: Compensating stock movement must use company business date 2026-10-15
        $voidStockMovements = StockMovement::where('company_id', $company->id)
            ->where('source_type', 'sales_return_void')
            ->where('source_id', $postedReturn->id)
            ->get();

        $this->assertCount(1, $voidStockMovements);
        $this->assertSame(
            '2026-10-15',
            $voidStockMovements->first()->movement_date->format('Y-m-d'),
            'Sales return void stock movement must use company business date (2026-10-15).'
        );

        // 4. EXPECTED: GL reversal batch must use company business date 2026-10-15
        $reversalBatch = PostingBatch::where('company_id', $company->id)
            ->where('reversal_of_id', $postedReturn->posting_batch_id)
            ->firstOrFail();

        $this->assertSame(
            '2026-10-15',
            $reversalBatch->posting_date->format('Y-m-d'),
            'Sales return void GL reversal batch must use company business date (2026-10-15), not UTC app date (2026-10-14).'
        );

        // 5. EXPECTED: Stock movement date and GL reversal posting date must be identical
        $this->assertSame(
            $voidStockMovements->first()->movement_date->format('Y-m-d'),
            $reversalBatch->posting_date->format('Y-m-d'),
            'Compensating return stock movement and void reversal GL batch must have identical posting dates.'
        );
    }
}
