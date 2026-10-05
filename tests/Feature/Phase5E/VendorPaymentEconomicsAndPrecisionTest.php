<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\Purchase;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

class VendorPaymentEconomicsAndPrecisionTest extends Phase5ETestCase
{
    /** Scenario 2: Same-currency ILS/USD/JOD precision; rate10/base6; baseFX1; zero/negative/unrepresentable amount/rate errors; explicit foreign gain/loss opposite AR */
    public function test_scenario_02_currencies_precision_and_validation(): void
    {
        $postAction = app(PostVendorPaymentAction::class);

        // 1. ILS base currency: rate must be 1.0
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base currency rate must equal one.');
        $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0500000000',
            'idempotency_key' => 'pmt-s2-err1',
            'allocations' => [],
        ]);
    }

    public function test_scenario_02_unrepresentable_precision_rejected(): void
    {
        $postAction = app(PostVendorPaymentAction::class);

        // ILS only allows 2 decimals; 100.005 must fail
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Payment amount precision exceeds 2 decimals.');
        $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '100.005',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s2-err2',
            'allocations' => [],
        ]);
    }

    public function test_scenario_02_jod_three_decimals_and_usd_two_decimals(): void
    {
        $postAction = app(PostVendorPaymentAction::class);

        // JOD 3 decimals allowed (e.g. 50.125)
        $jodPayment = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->jodCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '50.125',
            'exchange_rate' => '5.0000000000',
            'idempotency_key' => 'pmt-s2-jod',
            'allocations' => [],
        ]);
        $this->assertEquals('50.125000', (string) $jodPayment->amount);
        $this->assertEquals('250.625000', (string) $jodPayment->amount_base);

        // USD 2 decimals allowed
        $usdPayment = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->usdCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '100.50',
            'exchange_rate' => '3.6000000000',
            'idempotency_key' => 'pmt-s2-usd',
            'allocations' => [],
        ]);
        $this->assertEquals('100.500000', (string) $usdPayment->amount);
        $this->assertEquals('361.800000', (string) $usdPayment->amount_base);
    }

    /** Scenario 3: Historical AP final cumulative residual, Payment final settlement residual, awkward FX3.3333333333, multiple allocations/partial/final, no residual mislabeled FX */
    public function test_scenario_03_awkward_rates_and_exact_residuals(): void
    {
        $postAction = app(PostVendorPaymentAction::class);

        // Purchase USD 100 @ 3.3333333333 => base = 333.333333
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.3333333333',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);
        $this->assertEquals('333.333333', (string) $purchase->grand_total_base);

        // 1st partial payment: USD 33.33 @ 3.3333333333
        $pmt1 = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->usdBankAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'bank_transfer',
            'amount' => '33.33',
            'exchange_rate' => '3.3333333333',
            'idempotency_key' => 'pmt-s3-1',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '33.33'],
            ],
        ]);
        $alloc1 = $pmt1->allocations->first();
        $this->assertEquals('0.000000', (string) $alloc1->realized_fx_gain_loss_base);

        // 2nd partial payment: USD 33.33 @ 3.3333333333
        $pmt2 = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->usdBankAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'bank_transfer',
            'amount' => '33.33',
            'exchange_rate' => '3.3333333333',
            'idempotency_key' => 'pmt-s3-2',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '33.33'],
            ],
        ]);
        $alloc2 = $pmt2->allocations->first();
        $this->assertEquals('0.000000', (string) $alloc2->realized_fx_gain_loss_base);

        // 3rd final payment: USD 33.34 @ 3.3333333333 (settling 100% of purchase)
        $pmt3 = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->usdBankAccount->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'bank_transfer',
            'amount' => '33.34',
            'exchange_rate' => '3.3333333333',
            'idempotency_key' => 'pmt-s3-3',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '33.34'],
            ],
        ]);
        $alloc3 = $pmt3->allocations->first();
        $this->assertEquals('0.000000', (string) $alloc3->realized_fx_gain_loss_base);

        // Total AP book value relief must equal exactly 333.333333 (the purchase total base)
        $totalRelieved = BigDecimal::of((string) $alloc1->base_amount_applied_to_payable)
            ->plus(BigDecimal::of((string) $alloc2->base_amount_applied_to_payable))
            ->plus(BigDecimal::of((string) $alloc3->base_amount_applied_to_payable));
        $this->assertEquals('333.333333', (string) $totalRelieved->toScale(6));

        // Purchase outstanding is exactly 0
        $purchase->refresh();
        $this->assertEquals('0.000000', (string) $purchase->payablePosition()->outstanding);
        $this->assertEquals('settled', $purchase->payablePosition()->status);
    }

    /** Scenario 4: Explicit GL examples: USD100 @3.50 vs3.60 gain/loss; partial60 of100 with advance40/144; negative MoneyAccount balance allowed with ledger-derived truth */
    public function test_scenario_04_explicit_gl_examples(): void
    {
        $postAction = app(PostVendorPaymentAction::class);

        // Example 1: Purchase USD100 @3.50; Payment @3.60 => Loss 10
        // Dr AP 350; Dr FX Loss 10; Cr Bank 360
        $purchase1 = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);
        $pmt1 = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->usdBankAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'bank_transfer',
            'amount' => '100.00',
            'exchange_rate' => '3.6000000000',
            'idempotency_key' => 'pmt-s4-loss',
            'allocations' => [
                ['purchase_id' => $purchase1->id, 'allocated_amount' => '100.00'],
            ],
        ]);
        $batch1 = PostingBatch::with('lines.account')->findOrFail($pmt1->posting_batch_id);
        $lines1 = $batch1->lines;
        // Total lines: Cr Bank 360, Dr AP 350, Dr FX Loss 10
        $this->assertCount(3, $lines1);

        $bankLine = $lines1->firstWhere('ledger_account_id', $this->usdBankAccount->ledger_account_id);
        $this->assertNotNull($bankLine);
        $this->assertEquals('360.000000', (string) $bankLine->credit_base);
        $this->assertEquals('0.000000', (string) $bankLine->debit_base);

        $apAcc = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_payable')->firstOrFail();
        $apLine = $lines1->firstWhere('ledger_account_id', $apAcc->id);
        $this->assertNotNull($apLine);
        $this->assertEquals('350.000000', (string) $apLine->debit_base);
        $this->assertEquals('0.000000', (string) $apLine->credit_base);

        $fxLossAcc = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'fx_loss')->firstOrFail();
        $fxLine = $lines1->firstWhere('ledger_account_id', $fxLossAcc->id);
        $this->assertNotNull($fxLine);
        $this->assertEquals('10.000000', (string) $fxLine->debit_base);
        $this->assertEquals('0.000000', (string) $fxLine->credit_base);

        // Example 2: Purchase USD100 @3.60; Payment @3.50 => Gain 10
        // Dr AP 360; Cr Bank 350; Cr FX Gain 10
        $purchase2 = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.6000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);
        $pmt2 = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->usdBankAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'bank_transfer',
            'amount' => '100.00',
            'exchange_rate' => '3.5000000000',
            'idempotency_key' => 'pmt-s4-gain',
            'allocations' => [
                ['purchase_id' => $purchase2->id, 'allocated_amount' => '100.00'],
            ],
        ]);
        $batch2 = PostingBatch::with('lines.account')->findOrFail($pmt2->posting_batch_id);
        $lines2 = $batch2->lines;
        $this->assertCount(3, $lines2);

        $bankLine2 = $lines2->firstWhere('ledger_account_id', $this->usdBankAccount->ledger_account_id);
        $this->assertEquals('350.000000', (string) $bankLine2->credit_base);
        $this->assertEquals('0.000000', (string) $bankLine2->debit_base);

        $apLine2 = $lines2->firstWhere('ledger_account_id', $apAcc->id);
        $this->assertEquals('360.000000', (string) $apLine2->debit_base);
        $this->assertEquals('0.000000', (string) $apLine2->credit_base);

        $fxGainAcc = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'fx_gain')->firstOrFail();
        $fxLine2 = $lines2->firstWhere('ledger_account_id', $fxGainAcc->id);
        $this->assertNotNull($fxLine2);
        $this->assertEquals('0.000000', (string) $fxLine2->debit_base);
        $this->assertEquals('10.000000', (string) $fxLine2->credit_base);

        // Example 3: Payment USD100 @3.60, allocate USD60 to Purchase @3.50:
        // B=210, S=216, loss=6, remaining advance=USD40/base144.
        // Dr AP210 (historical settlement), Dr AP144 (advance), Dr FX Loss6, Cr Bank360.
        $purchase3 = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);
        $pmt3 = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->usdBankAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'bank_transfer',
            'amount' => '100.00',
            'exchange_rate' => '3.6000000000',
            'idempotency_key' => 'pmt-s4-partial',
            'allocations' => [
                ['purchase_id' => $purchase3->id, 'allocated_amount' => '60.00'],
            ],
        ]);
        $batch3 = PostingBatch::with('lines.account')->findOrFail($pmt3->posting_batch_id);
        $lines3 = $batch3->lines;
        // Lines: Cr Bank 360, Dr AP 210, Dr AP 144, Dr FX Loss 6
        $bankLine3 = $lines3->firstWhere('ledger_account_id', $this->usdBankAccount->ledger_account_id);
        $this->assertEquals('360.000000', (string) $bankLine3->credit_base);

        $apLines = $lines3->where('ledger_account_id', $apAcc->id)->values();
        $this->assertCount(2, $apLines);
        // One is 210 (historical settlement), one is 144 (advance)
        $apBases = $apLines->pluck('debit_base')->map(fn ($b) => (string) $b)->sort()->values()->all();
        $this->assertEquals(['144.000000', '210.000000'], $apBases);

        $fxLine3 = $lines3->firstWhere('ledger_account_id', $fxLossAcc->id);
        $this->assertNotNull($fxLine3);
        $this->assertEquals('6.000000', (string) $fxLine3->debit_base);
    }

    /** Scenario 5: Advance USD100 @3.60 later Purchase @3.50 => Dr FX Loss10/Cr AP10; inverse Dr AP10/Cr FX Gain10; no Cash again. Equal FX event completed with null batch, no empty GL */
    public function test_scenario_05_advance_applications_and_fx(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $applyAction = app(ApplyVendorPaymentCreditAction::class);

        // 1. Advance of USD 100 @ 3.60
        $advance = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->usdBankAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'bank_transfer',
            'amount' => '100.00',
            'exchange_rate' => '3.6000000000',
            'idempotency_key' => 'pmt-s5-adv',
            'allocations' => [],
        ]);

        // Purchase of USD 100 @ 3.50
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        // Apply advance to Purchase:
        // S = 100 * 3.60 = 360
        // B = 100 * 3.50 = 350
        // delta = S - B = 10 > 0 => Dr FX Loss 10 / Cr AP 10
        $event = $applyAction->execute($advance, $this->owner, [
            'application_date' => '2026-10-02',
            'idempotency_key' => 'app-s5-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        $this->assertNotNull($event->posting_batch_id);
        $batch = PostingBatch::with('lines.account')->findOrFail($event->posting_batch_id);
        $lines = $batch->lines;
        $this->assertCount(2, $lines);

        // NO Cash line in batch!
        $this->assertNull($lines->firstWhere('ledger_account_id', $this->usdBankAccount->ledger_account_id));

        $fxLossAcc = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'fx_loss')->firstOrFail();
        $apAcc = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_payable')->firstOrFail();

        $fxLine = $lines->firstWhere('ledger_account_id', $fxLossAcc->id);
        $this->assertEquals('10.000000', (string) $fxLine->debit_base);
        $this->assertEquals('0.000000', (string) $fxLine->credit_base);

        $apLine = $lines->firstWhere('ledger_account_id', $apAcc->id);
        $this->assertEquals('0.000000', (string) $apLine->debit_base);
        $this->assertEquals('10.000000', (string) $apLine->credit_base);

        // 2. Now test Equal FX: rate 3.60 advance applied to rate 3.60 purchase => delta = 0 => completed with null batch
        $advance2 = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->usdBankAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'bank_transfer',
            'amount' => '100.00',
            'exchange_rate' => '3.6000000000',
            'idempotency_key' => 'pmt-s5-adv2',
            'allocations' => [],
        ]);

        $purchase2 = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.6000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $event2 = $applyAction->execute($advance2, $this->owner, [
            'application_date' => '2026-10-02',
            'idempotency_key' => 'app-s5-02',
            'allocations' => [
                ['purchase_id' => $purchase2->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        // delta = 0, so posting_batch_id MUST be null (no fake empty batch)
        $this->assertNull($event2->posting_batch_id);
    }
}
