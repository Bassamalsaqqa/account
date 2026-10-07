<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Money\ReverseMoneyTransferAction;
use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Domain\Purchasing\Queries\VendorBalanceQuery;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Domain\Sales\Queries\CustomerBalanceQuery;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\LedgerAccount;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Money\MoneyTransferHistory;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class CrossCurrencyConvergenceTest extends Phase6TestCase
{
    public static function transfers(): array
    {
        return [['USD', '100', '3.5', 'JOD', '50', '4.8', '-110.000000'], ['USD', '100', '3.5', 'JOD', '100', '4.8', '130.000000'], ['USD', '0.01', '3.5', 'JOD', '0.001', '4.8', '-0.030200']];
    }

    #[DataProvider('transfers')]
    public function test_cross_currency_transfer_has_two_rates_exact_fx_and_inverse(string $from, string $fromAmount, string $fromRate, string $to, string $toAmount, string $toRate, string $delta): void
    {
        $bank = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, ['name_ar' => 'بنك الدينار', 'account_type' => 'bank', 'currency_code' => $to]);
        $transfer = app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, ['from_money_account_id' => $this->usdCashAccount->id,
            'to_money_account_id' => $bank->id, 'transfer_date' => '2026-10-03', 'from_amount' => $fromAmount, 'to_amount' => $toAmount,
            'from_exchange_rate' => $fromRate, 'to_exchange_rate' => $toRate, 'idempotency_key' => 'dual-transfer']);
        $this->assertSame($delta, $transfer->fx_gain_loss_base);
        $lines = $transfer->postingBatch->lines;
        $this->assertSame($to, $lines[0]->transaction_currency_code);
        $this->assertSame($from, $lines[1]->transaction_currency_code);
        $this->assertSame($delta[0] === '-' ? 'fx_loss' : 'fx_gain', LedgerAccount::findOrFail($lines[2]->ledger_account_id)->system_key);
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();
        foreach ($lines as $line) {
            $debit = $debit->plus($line->debit_base);
            $credit = $credit->plus($line->credit_base);
        }
        $this->assertTrue($debit->isEqualTo($credit));
        $reversed = app(ReverseMoneyTransferAction::class)->execute($transfer, $this->owner, '2026-10-04');
        app(MoneyTransferHistory::class)->validate($reversed);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public static function settlementOrientations(): array
    {
        return [['vendor', '360', '10.000000', 'fx_loss'], ['vendor', '330', '-20.000000', 'fx_gain'], ['customer', '360', '10.000000', 'fx_gain'], ['customer', '330', '-20.000000', 'fx_loss']];
    }

    #[DataProvider('settlementOrientations')]
    public function test_document_and_payment_currencys_clear_independently_and_statements_reverse_correctly(string $domain, string $paid, string $delta, string $fxAccount): void
    {
        $vendor = $domain === 'vendor';
        $document = $vendor ? $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']) : $this->invoice();
        $party = $vendor ? $this->vendor : $document->customer;
        $data = [($vendor ? 'vendor_id' : 'customer_id') => $party->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_method' => 'cash', 'payment_date' => '2026-10-03', 'amount' => $paid, 'exchange_rate' => '1', 'idempotency_key' => 'dual-settle',
            'allocations' => [[($vendor ? 'purchase_id' : 'sales_invoice_id') => $document->id, 'allocated_amount' => '100', 'payment_currency_amount' => $paid]]];
        $payment = app($vendor ? PostVendorPaymentAction::class : PostCustomerPaymentAction::class)->execute($this->company, $this->owner, $data);
        $this->assertSame($delta, $payment->allocations->sole()->realized_fx_gain_loss_base);
        $this->assertSame(1, $payment->postingBatch->lines()->where('ledger_account_id', LedgerAccount::where('system_key', $fxAccount)->sole()->id)->count());
        $balances = $vendor ? app(VendorBalanceQuery::class)->execute([$party->id]) : app(CustomerBalanceQuery::class)->execute([$party->id]);
        $field = $vendor ? 'balance' : 'outstanding';
        $this->assertTrue(BigDecimal::of($balances[$party->id]['USD'][$field])->isZero());
        $this->assertTrue(BigDecimal::of($balances[$party->id]['ILS'][$field])->isZero());
        $statement = $vendor ? app(VendorStatementQuery::class)->execute($party, null, '2026-10-03') : app(CustomerStatementQuery::class)->execute($party, null, '2026-10-03');
        $this->assertTrue(BigDecimal::of($statement['currencies']['USD']['closing_balance'])->isZero());
        $this->assertTrue(BigDecimal::of($statement['currencies']['ILS']['closing_balance'])->isZero());
        $vendor ? app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner, 'Correction', '2026-10-05')
            : app(ReverseCustomerPaymentAction::class)->execute($payment, $this->owner, 'Correction', '2026-10-05');
        $past = $vendor ? app(VendorStatementQuery::class)->execute($party, null, '2026-10-04') : app(CustomerStatementQuery::class)->execute($party, null, '2026-10-04');
        $this->assertTrue(BigDecimal::of($past['currencies']['USD']['closing_balance'])->isZero());
        $now = $vendor ? app(VendorStatementQuery::class)->execute($party, null, '2026-10-06') : app(CustomerStatementQuery::class)->execute($party, null, '2026-10-06');
        $this->assertSame('100.00', $now['currencies']['USD']['closing_balance']);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue($vendor ? app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy : app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public static function advances(): array
    {
        return [['vendor'], ['customer']];
    }

    #[DataProvider('advances')]
    public function test_cross_currency_advance_consumption_is_append_only_and_reversal_restores_both_currencies(string $domain): void
    {
        $vendor = $domain === 'vendor';
        $document = $vendor ? $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']) : $this->invoice();
        $payment = app($vendor ? PostVendorPaymentAction::class : PostCustomerPaymentAction::class)->execute($this->company, $this->owner,
            [($vendor ? 'vendor_id' : 'customer_id') => $vendor ? $this->vendor->id : $document->customer_id,
                'money_account_id' => $this->ilsCashAccount->id, 'payment_method' => 'cash', 'payment_date' => '2026-10-02', 'amount' => '360', 'exchange_rate' => '1', 'idempotency_key' => 'advance']);
        $moneyBefore = DB::table('posting_lines')->where('ledger_account_id', $this->ilsCashAccount->ledger_account_id)->count();
        $action = app($vendor ? ApplyVendorPaymentCreditAction::class : ApplyCustomerPaymentCreditAction::class);
        $events = [];
        foreach ([['40', '144'], ['60', '216']] as $i => [$relieved, $consumed]) {
            $data = ['application_date' => '2026-10-03', 'idempotency_key' => 'apply-'.$i, 'allocations' => [
                [($vendor ? 'purchase_id' : 'sales_invoice_id') => $document->id, 'allocated_amount' => $relieved, 'payment_currency_amount' => $consumed]]];
            $events[] = $event = $action->execute($payment, $this->owner, $data);
            $this->assertSame($event->id, $action->execute($payment, $this->owner, $data)->id);
        }
        $this->assertSame($moneyBefore, DB::table('posting_lines')->where('ledger_account_id', $this->ilsCashAccount->ledger_account_id)->count());
        $this->assertSame('0.000000', $payment->fresh()->unallocated_amount);
        $this->assertSame('0.000000', $payment->fresh()->unallocated_amount_base);
        $vendor ? app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner, 'Correction', '2026-10-04') : app(ReverseCustomerPaymentAction::class)->execute($payment, $this->owner, 'Correction', '2026-10-04');
        $this->assertGreaterThan($events[1]->fresh()->reversal_posting_batch_id, $events[0]->fresh()->reversal_posting_batch_id);
        $this->assertGreaterThan($events[0]->fresh()->reversal_posting_batch_id, $payment->fresh()->reversal_posting_batch_id);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }
}
