<?php

declare(strict_types=1);

namespace Tests\Feature\Fin1;

use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Application\Commands\ImmediateSaleCommands;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\CustomerPayment;
use App\Models\InventoryBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

final class ImmediateSaleStockAndFefoTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    private Warehouse $warehouse;

    private Unit $unitPiece;

    private Product $product;

    private ProductUnit $productUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'سلعة مجربة للمخزون',
            'name_en' => 'Tracked Stock Item',
            'sku' => 'STOCK-FIN1-01',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => 'stock',
            'track_stock' => true,
            'track_expiry' => true,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $this->productUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'suggested_sale_price' => '10.000000',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
    }

    private function seedLots(): void
    {
        // Lot 1: Earlier expiry, 10 units at 4.00 ILS cost
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(10),
            unitCostBase: '4.000000',
            user: $this->owner,
            idempotencyKey: (string) Str::ulid(),
            unitId: $this->productUnit->unit_id,
            lotNumber: 'LOT-EARLY',
            expiryDate: '2027-01-01',
            movementDate: '2026-10-01',
        );

        // Lot 2: Later expiry, 20 units at 5.00 ILS cost
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of(20),
            unitCostBase: '5.000000',
            user: $this->owner,
            idempotencyKey: (string) Str::ulid(),
            unitId: $this->productUnit->unit_id,
            lotNumber: 'LOT-LATER',
            expiryDate: '2027-06-01',
            movementDate: '2026-10-01',
        );
    }

    private function stockDraft(string $quantity = '15', string $unitPrice = '10'): SalesInvoice
    {
        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-29',
            'document_locale' => 'en',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->productUnit->id,
                    'item_description' => 'Stock item line',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_type' => 'none',
                    'discount_value' => '0',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
    }

    private function assertSixReconciliations(): void
    {
        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $reconcile) {
            $this->assertSame(0, Artisan::call($reconcile, ['companyPublicId' => $this->company->public_id]), Artisan::output());
        }
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_immediate_sale_with_stocked_product_and_fefo_lot_allocates_correctly_and_reconciles(): void
    {
        $this->seedLots();
        $invoice = $this->stockDraft('15', '10'); // Total = 150 ILS
        $this->assertSame('150.000000', (string) $invoice->grand_total_currency);

        $baseCash = MoneyAccount::where('company_id', $this->company->id)->where('currency_code', 'ILS')->firstOrFail();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, [
            'mode' => 'full',
            'money_account_id' => (int) $baseCash->id,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'payment_date' => '2026-10-29',
            'amount' => '150',
            'exchange_rate' => '1',
        ]);

        $this->assertSame('0.000000', $preview['settlement']['outstanding_invoice_amount']);
        $result = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'fin1-stock-full');

        $posted = $result['invoice'];
        $payment = $result['payment'];

        $this->assertTrue($posted->isPosted());
        $this->assertNotNull($payment);
        $this->assertSame('150.000000', (string) $payment->amount);

        // Verify FEFO: Earlier lot (10) fully consumed, later lot (20) has 15 remaining
        $lot1 = InventoryLot::where('company_id', $this->company->id)->where('lot_number', 'LOT-EARLY')->firstOrFail();
        $lot2 = InventoryLot::where('company_id', $this->company->id)->where('lot_number', 'LOT-LATER')->firstOrFail();

        $balance1 = InventoryLotBalance::where('company_id', $this->company->id)->where('lot_id', $lot1->id)->firstOrFail();
        $balance2 = InventoryLotBalance::where('company_id', $this->company->id)->where('lot_id', $lot2->id)->firstOrFail();

        $this->assertSame('0.000000', (string) $balance1->quantity_base);
        $this->assertSame('15.000000', (string) $balance2->quantity_base);

        // Overall product balance is 15
        $totalBalance = InventoryBalance::where('company_id', $this->company->id)->where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('15.000000', (string) $totalBalance->quantity_base);

        $this->assertSixReconciliations();
    }

    public function test_deterministic_receipt_failure_after_stock_and_posting_rolls_back_every_effect(): void
    {
        $this->seedLots();
        $invoice = $this->stockDraft('10', '10');

        $baseCash = MoneyAccount::where('company_id', $this->company->id)->where('currency_code', 'ILS')->firstOrFail();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, [
            'mode' => 'full',
            'money_account_id' => (int) $baseCash->id,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'payment_date' => '2026-10-29',
            'amount' => '100',
            'exchange_rate' => '1',
        ]);

        $beforeInvoice = $invoice->fresh()->toArray();
        $beforeSequences = DB::table('document_sequences')->orderBy('id')->get()->toJson();
        $beforeMovementsCount = StockMovement::count();
        $beforeBalances = DB::table('inventory_balances')->orderBy('id')->get()->toJson();
        $beforeLotBalances = DB::table('inventory_lot_balances')->orderBy('id')->get()->toJson();
        $beforeBatchesCount = DB::table('posting_batches')->count();

        $this->mock(PostCustomerPaymentAction::class, function ($mock) use ($invoice): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(function () use ($invoice): never {
                // At this point, the invoice has posted its lines, stock movements, and posting batch
                $this->assertTrue($invoice->fresh()->isPosted());
                throw new RuntimeException('Synthetic injected receipt failure after stock deduction');
            });
        });

        $commandWithMock = app(ImmediateSaleCommands::class);

        try {
            $commandWithMock->execute($invoice, $this->owner, $preview['confirmation_token'], 'fin1-rollback-stock');
            $this->fail('Sale committed despite receipt failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic injected receipt failure after stock deduction', $exception->getMessage());
        }

        // Entire transaction must have rolled back
        $this->assertSame($beforeInvoice, $invoice->fresh()->toArray());
        $this->assertTrue($invoice->fresh()->isDraft());
        $this->assertSame($beforeSequences, DB::table('document_sequences')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeMovementsCount, StockMovement::count());
        $this->assertSame($beforeBalances, DB::table('inventory_balances')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeLotBalances, DB::table('inventory_lot_balances')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeBatchesCount, DB::table('posting_batches')->count());
        $this->assertDatabaseCount('customer_payments', 0);
        $this->assertDatabaseCount('command_receipts', 0);

        // Opening stock history intact and healthy
        $this->assertSixReconciliations();
    }

    public function test_immediate_sale_with_partial_payment_and_stock_preserves_canonical_return_and_reversal(): void
    {
        $this->seedLots();
        $invoice = $this->stockDraft('10', '20'); // 10 units at 20 ILS = 200 ILS total

        $baseCash = MoneyAccount::where('company_id', $this->company->id)->where('currency_code', 'ILS')->firstOrFail();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, [
            'mode' => 'partial',
            'money_account_id' => (int) $baseCash->id,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'payment_date' => '2026-10-29',
            'amount' => '120',
            'invoice_amount' => '120',
            'exchange_rate' => '1',
        ]);

        $this->assertSame('80.000000', $preview['settlement']['outstanding_invoice_amount']);
        $result = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'fin1-partial-return');

        $posted = $result['invoice'];
        $payment = $result['payment'];
        $this->assertSame('120.000000', (string) $payment->amount);

        // 1. Canonical Sales Return of 5 units
        $line = $posted->lines->first();
        $returnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $posted->id,
            'customer_id' => $posted->customer_id,
            'warehouse_id' => $posted->warehouse_id,
            'currency_code' => $posted->currency_code,
            'exchange_rate' => $posted->exchange_rate,
            'issue_date' => '2026-10-29',
            'document_locale' => 'en',
            'lines' => [
                [
                    'sales_invoice_line_id' => $line->id,
                    'quantity' => '5.000000',
                    'unit_price' => $line->unit_price,
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        $postedReturn = app(PostSalesReturnAction::class)->execute($returnDraft, $this->owner);
        $this->assertTrue($postedReturn->isPosted());

        // Stock restored by 5 units: 20 remaining + 5 restored = 25 total
        $totalBalance = InventoryBalance::where('company_id', $this->company->id)->where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('25.000000', (string) $totalBalance->quantity_base);

        // 2. Canonical Customer Payment Reversal
        $reversedPayment = app(ReverseCustomerPaymentAction::class)->execute(
            $payment,
            $this->owner,
            'Customer refunded after return',
            '2026-10-29'
        );
        $this->assertTrue($reversedPayment->is_reversed);

        // All 6 reconciliations healthy after return and payment reversal
        $this->assertSixReconciliations();
    }

    public function test_immediate_sale_cross_currency_with_stock_allocates_and_reconciles(): void
    {
        $this->seedLots();

        // USD Invoice: 5 units at 10 USD each = 50 USD total. Rate 3.5 = 175 ILS base.
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'issue_date' => '2026-10-29',
            'document_locale' => 'en',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->productUnit->id,
                    'item_description' => 'USD stock line',
                    'quantity' => '5',
                    'unit_price' => '10',
                    'discount_type' => 'none',
                    'discount_value' => '0',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        // Customer pays in ILS cash: 175 ILS for 50 USD invoice amount
        $baseCash = MoneyAccount::where('company_id', $this->company->id)->where('currency_code', 'ILS')->firstOrFail();
        $command = app(ImmediateSaleCommands::class);
        $preview = $command->preview($invoice, $this->owner, [
            'mode' => 'full',
            'money_account_id' => (int) $baseCash->id,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'payment_date' => '2026-10-29',
            'amount' => '175',
            'exchange_rate' => '1',
            'payment_currency_amount' => '175',
        ]);

        $result = $command->execute($invoice, $this->owner, $preview['confirmation_token'], 'fin1-cross-fx-stock');
        $this->assertTrue($result['invoice']->isPosted());
        $this->assertSame('175.000000', (string) $result['payment']->amount);
        $this->assertSame('ILS', $result['payment']->currency_code);

        $this->assertSixReconciliations();
    }
}
