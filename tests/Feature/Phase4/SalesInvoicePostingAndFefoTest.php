<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLotAllocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesInvoicePostingAndFefoTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Unit $unitPiece;

    protected Product $product;

    protected ProductUnit $productUnit;

    protected InventoryMovementService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->company = $creator->execute($this->user, [
            'name_ar' => 'شركة فواتير المبيعات',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->actingAs($this->user);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل فواتير معتمد',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عصير برتقال طبيعي',
            'sku' => 'JUICE-ORANGE-01',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => 'stock',
            'track_stock' => true,
            'track_expiry' => true,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->productUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'suggested_sale_price' => '50.000000',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->inventoryService = app(InventoryMovementService::class);
    }

    public function test_draft_sales_invoice_is_side_effect_free(): void
    {
        $draftAction = app(CreateSalesInvoiceDraftAction::class);

        $data = [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->productUnit->id,
                    'item_description' => 'عصير برتقال طبيعي',
                    'quantity' => '5.000000',
                    'unit_price' => '50.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ];

        $invoice = $draftAction->execute($this->company, $this->user, $data);

        // Invariants of a draft
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertNull($invoice->invoice_number);
        $this->assertNull($invoice->posting_batch_id);
        $this->assertSame('250.000000', $invoice->grand_total);

        // Strict side-effect freedom:
        // 0 stock movements
        $this->assertSame(0, StockMovement::where('company_id', $this->company->id)->count());
        // 0 lot allocations
        $this->assertSame(0, SalesInvoiceLotAllocation::where('sales_invoice_id', $invoice->id)->count());
        // 0 GL batches
        $this->assertSame(0, PostingBatch::where('company_id', $this->company->id)->count());
        // 0 financial outstanding effect
        $this->assertTrue($invoice->calculateOutstanding()->isZero());
    }

    public function test_atomic_invoice_posting_with_fefo_allocations_and_balanced_gl(): void
    {
        // 1. Seed two dated lots:
        // Lot 1: Exp 2027-01-01, 10 units at 20.00 ILS cost
        $this->inventoryService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '20.000000',
                    lotNumber: 'LOT-JAN-27',
                    expiryDate: '2027-01-01',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // Lot 2: Exp 2027-06-01, 20 units at 25.00 ILS cost
        $this->inventoryService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(20),
                    unitCostBase: '25.000000',
                    lotNumber: 'LOT-JUN-27',
                    expiryDate: '2027-06-01',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 2,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // 2. Create and Post Invoice for 15 units at 50.00 ILS
        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $postAction = app(PostSalesInvoiceAction::class);

        $invoice = $draftAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(15)->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->productUnit->id,
                    'item_description' => 'عصير برتقال طبيعي',
                    'quantity' => '15.000000',
                    'unit_price' => '50.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        $postedInvoice = $postAction->execute($invoice, $this->user);

        // Assert posted state
        $this->assertSame(SalesInvoice::STATUS_POSTED, $postedInvoice->status);
        $this->assertNotNull($postedInvoice->invoice_number);
        $this->assertStringContainsString('INV-', $postedInvoice->invoice_number);
        $this->assertNotNull($postedInvoice->posting_batch_id);

        // FEFO Allocation: 10 units from Lot 1 (cost 20) + 5 units from Lot 2 (cost 25)
        // Total COGS: (10 * 20) + (5 * 25) = 200 + 125 = 325.000000
        $lotAllocs = SalesInvoiceLotAllocation::where('sales_invoice_id', $postedInvoice->id)->get();
        $this->assertCount(2, $lotAllocs);

        $lot1Alloc = $lotAllocs->firstWhere('lot_number', 'LOT-JAN-27');
        $this->assertNotNull($lot1Alloc);
        $this->assertSame('10.000000', $lot1Alloc->quantity_allocated_base);
        $this->assertSame('23.333333', $lot1Alloc->unit_cost_base);

        $lot2Alloc = $lotAllocs->firstWhere('lot_number', 'LOT-JUN-27');
        $this->assertNotNull($lot2Alloc);
        $this->assertSame('5.000000', $lot2Alloc->quantity_allocated_base);
        $this->assertSame('23.333333', $lot2Alloc->unit_cost_base);

        // Line COGS derived directly from movements (10 * 23.333333 + 5 * 23.333333 = 349.999995)
        $line = $postedInvoice->lines()->firstOrFail();
        $this->assertSame('349.999995', $line->cogs_total_base);

        // Verify balanced GL posting batch
        $batch = PostingBatch::with('lines.account')->findOrFail($postedInvoice->posting_batch_id);
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);

        $totalDr = '0.000000';
        $totalCr = '0.000000';
        foreach ($batch->lines as $glLine) {
            $totalDr = bcadd($totalDr, (string) $glLine->debit_base, 6);
            $totalCr = bcadd($totalCr, (string) $glLine->credit_base, 6);
        }

        // Dr AR 750 + Dr COGS 349.999995 = 1099.999995
        // Cr Revenue 750 + Cr Inventory 349.999995 = 1099.999995
        $this->assertSame('1099.999995', $totalDr);
        $this->assertSame('1099.999995', $totalCr);

        // Financial Outstanding is now 750.00 ILS
        $this->assertSame('750.000000', (string) $postedInvoice->calculateOutstanding());
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_UNPAID, $postedInvoice->derivedPaymentStatus());
    }

    public function test_insufficient_stock_fails_closed(): void
    {
        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $postAction = app(PostSalesInvoiceAction::class);

        $invoice = $draftAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(15)->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->productUnit->id,
                    'item_description' => 'عصير برتقال طبيعي',
                    'quantity' => '9999.000000',
                    'unit_price' => '50.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        $this->expectException(InsufficientStockException::class);
        $postAction->execute($invoice, $this->user);

        // Assert invoice is still draft
        $invoice->refresh();
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $invoice->status);
    }

    public function test_voiding_posted_invoice_reverses_stock_and_gl(): void
    {
        // 1. Inbound 10 units at 20.00 cost
        $this->inventoryService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '20.000000',
                    lotNumber: 'LOT-VOID-TEST',
                    expiryDate: '2027-12-31',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 3,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        ));

        // 2. Post invoice for 4 units
        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $postAction = app(PostSalesInvoiceAction::class);
        $voidAction = app(VoidSalesInvoiceAction::class);

        $invoice = $draftAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(15)->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->productUnit->id,
                    'item_description' => 'عصير برتقال طبيعي',
                    'quantity' => '4.000000',
                    'unit_price' => '50.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        $posted = $postAction->execute($invoice, $this->user);

        // Check lot remaining before void: 10 - 4 = 6
        $lot = InventoryLot::where('lot_number', 'LOT-VOID-TEST')->firstOrFail();
        $lotBalance = InventoryLotBalance::where('lot_id', $lot->id)->where('warehouse_id', $this->warehouse->id)->firstOrFail();
        $this->assertSame('6.000000', $lotBalance->quantity_base);

        // 3. Void invoice
        $voided = $voidAction->execute($posted, $this->user, 'طلب العميل إلغاء الفاتورة');

        $this->assertSame(SalesInvoice::STATUS_VOID, $voided->status);
        $this->assertNotNull($voided->void_posting_batch_id);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame($this->user->id, $voided->voided_by);

        // Lot stock restored: 6 + 4 = 10
        $lotBalance->refresh();
        $this->assertSame('10.000000', $lotBalance->quantity_base);

        // Outstanding balance drops to 0
        $this->assertTrue($voided->calculateOutstanding()->isZero());
    }
}
