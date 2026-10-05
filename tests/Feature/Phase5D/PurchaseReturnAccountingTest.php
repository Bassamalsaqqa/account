<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\LedgerAccount;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryMovementService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PurchaseReturnAccountingTest extends Phase5DTestCase
{
    /** Scenario 43: recoverable tax Dr AP / Cr tax / Cr inventory */
    public function test_scenario_43_recoverable_tax_dr_ap_cr_tax_cr_inventory(): void
    {
        $tax = $this->tax($this->account('tax_input')->id, mode: 'exclusive', rate: '16.000000');
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '100', 'tax_rate_id' => $tax->id],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '3']],
        ]);

        $posted = $this->postReturn($draft);

        $this->assertNotNull($posted->posting_batch_id);

        // A = 348, T = 48, H = 300, I = 300
        $apDebit = $posted->postingBatch->lines()->where('ledger_account_id', $this->account('accounts_payable')->id)->sole()->debit_base;
        $taxCredit = $posted->postingBatch->lines()->where('ledger_account_id', $this->account('tax_input')->id)->sole()->credit_base;
        $invCredit = $posted->postingBatch->lines()->where('ledger_account_id', $this->account('inventory')->id)->sole()->credit_base;

        $this->assertSame('348.000000', $apDebit);
        $this->assertSame('48.000000', $taxCredit);
        $this->assertSame('300.000000', $invCredit);
    }

    /** Scenario 44: nonrecoverable tax Dr AP / Cr inventory */
    public function test_scenario_44_nonrecoverable_tax_dr_ap_cr_inventory(): void
    {
        $tax = $this->tax(account: null, mode: 'exclusive', code: 'NONREC16', rate: '16.000000');
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '100', 'tax_rate_id' => $tax->id],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);

        $posted = $this->postReturn($draft);

        // In non-recoverable tax, tax is capitalized into inventory. Total AP = 232, Cr Inventory = 232. No tax line.
        $this->assertSame('232.000000', $this->returnAccountNet($posted, 'accounts_payable'));
        $this->assertSame('-232.000000', $this->returnAccountNet($posted, 'inventory'));
        $this->assertSame('0.000000', $this->returnAccountNet($posted, 'tax_input'));
    }

    /** Scenario 45: Vendor credit allowed */
    public function test_scenario_45_vendor_credit_allowed(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '50'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '10']],
        ]);

        $posted = $this->postReturn($draft);

        // AP was debited by 500
        $this->assertSame('500.000000', $this->returnAccountNet($posted, 'accounts_payable'));
        $this->assertSame(PurchaseReturn::STATUS_POSTED, $posted->status);
    }

    /** Scenario 46: I > H Dr COGS / Cr Inventory */
    public function test_scenario_46_i_greater_than_h_dr_cogs_cr_inventory(): void
    {
        // 1. Purchase: 10 units @ 10 = 100
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        // 2. Intervening adjustment increase: 10 units @ 20 = 200 (Total = 20 units @ 300, avg = 15)
        app(InventoryMovementService::class)->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            movementDate: '2026-10-04',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '20.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id,
        ));

        // 3. Sell 10 units @ avg 15 = 150 removed (Leaves 10 units @ 150 value)
        app(InventoryMovementService::class)->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_SALE,
            movementDate: '2026-10-04',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                ),
            ],
            sourceType: 'sales_invoice',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id,
        ));

        // 4. Return all 10 units of original purchase (final depletion -> newQty = 0)
        // I = remaining carrying value = 150
        // H = commercial cost = 100
        // D = I - H = +50 -> Dr COGS 50, Cr Inventory 50
        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '10']],
        ]);

        $posted = $this->postReturn($draft);

        $returnLine = $posted->lines->sole();
        $this->assertSame('100.000000', $returnLine->historical_receipt_value_base);
        $this->assertSame('150.000000', $returnLine->inventory_value_removed_base);
        $this->assertSame('50.000000', $returnLine->valuation_adjustment_base);

        // COGS has net debit of 50
        $this->assertSame('50.000000', $this->returnAccountNet($posted, 'cogs'));

        // Net Inventory credit = -150 (equals I)
        $this->assertSame('-150.000000', $this->returnAccountNet($posted, 'inventory'));
    }

    /** Scenario 47: I < H Dr Inventory / Cr COGS */
    public function test_scenario_47_i_less_than_h_dr_inventory_cr_cogs(): void
    {
        // 1. Purchase: 10 units @ 20 = 200
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '20'],
            ],
        ]);

        // 2. Intervening adjustment increase: 10 units @ 10 = 100 (Total = 20 units @ 300, avg = 15)
        app(InventoryMovementService::class)->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            movementDate: '2026-10-04',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '10.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id,
        ));

        // 3. Sell 10 units @ avg 15 = 150 removed (Leaves 10 units @ 150 value)
        app(InventoryMovementService::class)->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_SALE,
            movementDate: '2026-10-04',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                ),
            ],
            sourceType: 'sales_invoice',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->owner->id,
        ));

        // 4. Return all 10 units of original purchase (final depletion -> newQty = 0)
        // I = remaining carrying value = 150
        // H = commercial cost = 200
        // D = I - H = -50 -> Dr Inventory 50, Cr COGS 50
        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '10']],
        ]);

        $posted = $this->postReturn($draft);

        $returnLine = $posted->lines->sole();
        $this->assertSame('200.000000', $returnLine->historical_receipt_value_base);
        $this->assertSame('150.000000', $returnLine->inventory_value_removed_base);
        $this->assertSame('-50.000000', $returnLine->valuation_adjustment_base);

        // COGS has credit (negative net) of -50
        $this->assertSame('-50.000000', $this->returnAccountNet($posted, 'cogs'));

        // Net Inventory credit = -150 (equals I)
        $this->assertSame('-150.000000', $this->returnAccountNet($posted, 'inventory'));
    }

    /** Scenario 48: exact net Inventory GL = movement */
    public function test_scenario_48_exact_net_inventory_gl_equals_movement(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '17.75'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '4']],
        ]);

        $posted = $this->postReturn($draft);

        $movement = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase_return')
            ->where('source_id', $posted->id)
            ->sole();

        $movementVal = BigDecimal::of((string) $movement->value_delta_base)->abs();
        $glInventoryNet = BigDecimal::of($this->returnAccountNet($posted, 'inventory'))->abs();

        $this->assertTrue($glInventoryNet->isEqualTo($movementVal));
    }

    /** Scenario 49: no FX gain/loss */
    public function test_scenario_49_no_fx_gain_loss(): void
    {
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '3']],
        ]);

        $posted = $this->postReturn($draft);

        $fxAccount = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'fx_gain_loss')
            ->first();

        if ($fxAccount !== null) {
            $fxLines = $posted->postingBatch->lines()->where('ledger_account_id', $fxAccount->id)->get();
            $this->assertTrue($fxLines->isEmpty());
        } else {
            $this->assertTrue(true);
        }
    }

    /** Scenario 50: currency AP = Inventory commercial + tax */
    public function test_scenario_50_currency_ap_equals_inventory_commercial_plus_tax(): void
    {
        $tax = $this->tax($this->account('tax_input')->id, mode: 'exclusive', rate: '16.000000');
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '20', 'tax_rate_id' => $tax->id],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '4']],
        ]);

        $posted = $this->postReturn($draft);

        $batchLines = $posted->postingBatch->lines;
        $apDebitTx = $batchLines->where('ledger_account_id', $this->account('accounts_payable')->id)->sole()->transaction_amount;
        $taxCreditTx = $batchLines->where('ledger_account_id', $this->account('tax_input')->id)->sole()->transaction_amount;
        $invCreditTx = $batchLines->where('ledger_account_id', $this->account('inventory')->id)->sole()->transaction_amount;

        $this->assertTrue(BigDecimal::of((string) $apDebitTx)->isEqualTo(
            BigDecimal::of((string) $taxCreditTx)->plus((string) $invCreditTx)
        ));
    }

    /** Scenario 51: historical allocated base authority */
    public function test_scenario_51_historical_allocated_base_authority(): void
    {
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2']],
        ]);

        $posted = $this->postReturn($draft);

        $returnLine = $posted->lines->sole();
        $this->assertSame('70.000000', $returnLine->line_total_base);

        $apDebit = $posted->postingBatch->lines()
            ->where('ledger_account_id', $this->account('accounts_payable')->id)
            ->sole()
            ->debit_base;

        $this->assertSame('70.000000', $apDebit);
    }

    /** Scenario 52: zero-value stock-only no fake GL */
    public function test_scenario_52_zero_value_stock_only_no_fake_gl(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '0'],
                ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '10'],
            ],
        ]);

        $zeroCostLine = $purchase->lines()->where('unit_cost', '0.000000')->firstOrFail();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $zeroCostLine->id, 'quantity' => '5']],
        ]);

        $posted = $this->postReturn($draft);

        $this->assertSame(PurchaseReturn::STATUS_POSTED, $posted->status);
        $this->assertNull($posted->posting_batch_id);

        $movement = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase_return')
            ->where('source_id', $posted->id)
            ->sole();

        $this->assertSame('0.000000', $movement->value_delta_base);
        $this->assertSame('-5.000000', $movement->quantity_delta_base);
    }

    /** Scenario 53: extreme FX component atomic failure */
    public function test_scenario_53_extreme_fx_component_atomic_failure(): void
    {
        $tax = $this->tax($this->account('tax_input')->id, rate: '0.010000');
        $draft = $this->createReturnDraft(
            $this->createAndPostPurchase([
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '100'],
                ],
            ])
        );

        // Corrupt return exchange rate to extreme value where tax base rounds to 0 while transaction tax is positive
        DB::table('purchase_returns')->where('id', $draft->id)->update([
            'currency_code' => 'USD',
            'exchange_rate' => '0.0000000001',
        ]);
        DB::table('purchase_return_lines')->where('id', $draft->lines->first()->id)->update([
            'line_tax' => '0.010000',
            'line_tax_base' => '0.000000',
            'purchase_tax_account_id' => $tax->purchase_tax_account_id,
        ]);

        $beforeState = $this->snapshotState();

        try {
            $this->postReturn($draft);
            $this->fail('Expected atomic failure on zero base recoverable tax line with positive transaction currency.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $afterState = $this->snapshotState();
        $this->assertSame($beforeState, $afterState);
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

        // Coherent retry succeeds
        $retried = $this->postReturn($posted);
        $this->assertSame($posted->id, $retried->id);
    }

    /** Review regression: Line adjustment equals actual minus commercial component */
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

    /** Review regression: Positive currency zero base return fails atomically */
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
}
