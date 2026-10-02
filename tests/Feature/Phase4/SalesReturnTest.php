<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\VoidSalesReturnAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SalesReturnTest extends TestCase
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
            'name_ar' => 'شركة مرتجعات المبيعات',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->actingAs($this->user);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل مرتجعات معتمد',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'سلعة مرتجعات',
            'sku' => 'RET-PROD-01',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => 'stock',
            'track_stock' => true,
            'track_expiry' => false,
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

    private function postSampleInvoice(string $qty = '10.000000', string $unitPrice = '50.000000', string $unitCost = '20.000000'): SalesInvoice
    {
        // Seed initial stock
        $this->inventoryService->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: Carbon::now()->toDateString(),
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of($qty),
                    unitId: $this->unitPiece->id,
                    unitCostBase: $unitCost,
                ),
            ],
            sourceType: 'manual_seed',
            sourceId: 1,
            idempotencyKey: 'seed_stock_'.uniqid(),
            createdBy: $this->user->id,
            reason: 'Initial stock for sales return test',
        ));

        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $postAction = app(PostSalesInvoiceAction::class);

        $invoice = $draftAction->execute($this->company, $this->user, [
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
                    'item_description' => 'سلعة مرتجعات',
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        return $postAction->execute($invoice, $this->user);
    }

    public function test_cannot_return_unposted_invoice(): void
    {
        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $invoice = $draftAction->execute($this->company, $this->user, [
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
                    'item_description' => 'سلعة مرتجعات',
                    'quantity' => '5.000000',
                    'unit_price' => '50.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        $returnDraftAction = app(CreateSalesReturnDraftAction::class);

        $this->expectException(InvalidArgumentException::class);
        $returnDraftAction->execute($this->company, $this->user, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoice->lines()->firstOrFail()->id,
                    'quantity' => '1.000000',
                ],
            ],
        ]);
    }

    public function test_cannot_return_more_than_sold_quantity(): void
    {
        $postedInvoice = $this->postSampleInvoice('10.000000');
        $invoiceLine = $postedInvoice->lines()->firstOrFail();

        $returnDraftAction = app(CreateSalesReturnDraftAction::class);

        $this->expectException(InvalidArgumentException::class);
        $returnDraftAction->execute($this->company, $this->user, [
            'sales_invoice_id' => $postedInvoice->id,
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoiceLine->id,
                    'quantity' => '11.000000', // Exceeds 10 sold
                ],
            ],
        ]);
    }

    public function test_post_sales_return_restores_stock_and_creates_balanced_gl(): void
    {
        // 10 units @ 50 ILS sold = 500 ILS total. COGS = 10 * 20 ILS = 200 ILS.
        $postedInvoice = $this->postSampleInvoice('10.000000', '50.000000', '20.000000');
        $invoiceLine = $postedInvoice->lines()->firstOrFail();

        // Warehouse stock is now 0 after selling all 10 units
        $whBalance = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('0.000000', $whBalance->quantity_base);

        // Create return draft for 4 units
        $returnDraftAction = app(CreateSalesReturnDraftAction::class);
        $return = $returnDraftAction->execute($this->company, $this->user, [
            'sales_invoice_id' => $postedInvoice->id,
            'issue_date' => Carbon::now()->toDateString(),
            'reason' => 'عطل في البضاعة',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoiceLine->id,
                    'quantity' => '4.000000',
                ],
            ],
        ]);

        $this->assertSame(SalesReturn::STATUS_DRAFT, $return->status);
        $this->assertStringStartsWith('DRAFT-', $return->return_number);
        $this->assertSame('200.000000', $return->grand_total); // 4 * 50 = 200

        // Post return
        $postReturnAction = app(PostSalesReturnAction::class);
        $postedReturn = $postReturnAction->execute($return, $this->user);

        $this->assertSame(SalesReturn::STATUS_POSTED, $postedReturn->status);
        $this->assertNotNull($postedReturn->return_number);
        $this->assertNotNull($postedReturn->posting_batch_id);

        // Verify stock is restored by 4 units
        $whBalance->refresh();
        $this->assertSame('4.000000', $whBalance->quantity_base);

        // Verify balanced GL batch
        $batch = PostingBatch::with('lines')->findOrFail($postedReturn->posting_batch_id);
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);

        $totalDr = '0.000000';
        $totalCr = '0.000000';
        foreach ($batch->lines as $glLine) {
            $totalDr = bcadd($totalDr, (string) $glLine->debit_base, 6);
            $totalCr = bcadd($totalCr, (string) $glLine->credit_base, 6);
        }

        // Dr Sales Returns 200 + Dr Inventory 80 = 280
        // Cr AR 200 + Cr COGS 80 = 280
        $this->assertSame('280.000000', $totalDr);
        $this->assertSame('280.000000', $totalCr);

        // Invoice outstanding should now be 500 - 200 = 300 ILS
        $this->assertSame('300.000000', (string) $postedInvoice->calculateOutstanding());
    }

    public function test_cannot_return_more_than_remaining_after_partial_return(): void
    {
        $postedInvoice = $this->postSampleInvoice('10.000000');
        $invoiceLine = $postedInvoice->lines()->firstOrFail();

        // 1st return of 4 units
        $returnDraftAction = app(CreateSalesReturnDraftAction::class);
        $postReturnAction = app(PostSalesReturnAction::class);

        $return1 = $returnDraftAction->execute($this->company, $this->user, [
            'sales_invoice_id' => $postedInvoice->id,
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoiceLine->id,
                    'quantity' => '4.000000',
                ],
            ],
        ]);
        $postReturnAction->execute($return1, $this->user);

        // Attempt 2nd return of 7 units (only 6 remaining out of 10)
        $this->expectException(InvalidArgumentException::class);
        $returnDraftAction->execute($this->company, $this->user, [
            'sales_invoice_id' => $postedInvoice->id,
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoiceLine->id,
                    'quantity' => '7.000000',
                ],
            ],
        ]);
    }

    public function test_void_sales_return_reverses_stock_and_gl(): void
    {
        $postedInvoice = $this->postSampleInvoice('10.000000', '50.000000', '20.000000');
        $invoiceLine = $postedInvoice->lines()->firstOrFail();

        $returnDraftAction = app(CreateSalesReturnDraftAction::class);
        $postReturnAction = app(PostSalesReturnAction::class);
        $voidReturnAction = app(VoidSalesReturnAction::class);

        $return = $returnDraftAction->execute($this->company, $this->user, [
            'sales_invoice_id' => $postedInvoice->id,
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoiceLine->id,
                    'quantity' => '4.000000',
                ],
            ],
        ]);
        $postedReturn = $postReturnAction->execute($return, $this->user);

        $originalBatch = PostingBatch::findOrFail($postedReturn->posting_batch_id);
        $this->assertSame(PostingBatch::STATUS_POSTED, $originalBatch->status);

        // Void the return
        $voidedReturn = $voidReturnAction->execute($postedReturn, $this->user, 'مرتجع ملغى بالخطأ');

        $this->assertSame(SalesReturn::STATUS_VOID, $voidedReturn->status);
        $this->assertNotNull($voidedReturn->voided_at);
        $this->assertSame($this->user->id, $voidedReturn->voided_by);

        // Warehouse stock back to 0
        $whBalance = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('0.000000', $whBalance->quantity_base);

        // GL batch reversed
        $originalBatch->refresh();
        $this->assertSame(PostingBatch::STATUS_REVERSED, $originalBatch->status);
        $this->assertNotNull($originalBatch->reversed_by_batch_id);

        // Outstanding restored to 500 ILS
        $this->assertSame('500.000000', (string) $postedInvoice->calculateOutstanding());

        // Idempotent void check
        $voidAgain = $voidReturnAction->execute($voidedReturn, $this->user);
        $this->assertSame(SalesReturn::STATUS_VOID, $voidAgain->status);
    }
}
