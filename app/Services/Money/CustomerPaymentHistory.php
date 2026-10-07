<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Models\Check;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Purchasing\VendorPaymentHistoryCommands;
use App\Services\Sales\ReceivableReliefHistory;
use App\Services\Sales\SalesPostingLines;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use InvalidArgumentException;

/** Exact original receipt reconstruction; later application rows are separate events. */
final class CustomerPaymentHistory
{
    public function validate(CustomerPayment $payment): void
    {
        $company = Company::findOrFail($payment->company_id);
        $batch = PostingBatch::where('company_id', $company->id)->findOrFail($payment->posting_batch_id);
        $amount = MoneyValues::amount($payment->amount, $payment->currency_code);
        $rate = MoneyValues::rate($payment->exchange_rate, $payment->currency_code, $company->base_currency_code);
        $base = MoneyValues::base($amount, $rate);
        if (! $base->isEqualTo($payment->amount_base) || trim($payment->payment_number) === ''
            || ! Customer::withTrashed()->where('company_id', $company->id)->whereKey($payment->customer_id)->exists()) {
            throw new InvalidArgumentException('Receipt immutable identity is incoherent.');
        }
        if ($payment->payment_method === 'check') {
            $check = Check::where('company_id', $company->id)->findOrFail($payment->check_id);
            if ($payment->money_account_id !== null || $check->direction !== 'incoming' || $check->customer_id !== $payment->customer_id
                || $check->currency_code !== $payment->currency_code || ! $amount->isEqualTo($check->amount) || ! $rate->isEqualTo($check->exchange_rate)) {
                throw new InvalidArgumentException('Receipt Check identity is incoherent.');
            }
            $moneyLedger = app(CheckPaymentSource::class)->ledger($check, false);
        } else {
            $account = MoneyAccount::withTrashed()->where('company_id', $company->id)->findOrFail($payment->money_account_id);
            $moneyLedger = app(MoneyAccountLedger::class)->validate($account);
            if ($payment->check_id !== null || $account->currency_code !== $payment->currency_code
                || $payment->payment_method !== ($account->account_type === 'cash' ? 'cash' : 'bank_transfer')) {
                throw new InvalidArgumentException('Receipt MoneyAccount identity is incoherent.');
            }
        }
        $accounts = LedgerAccount::where('company_id', $company->id)->whereIn('system_key', ['accounts_receivable', 'fx_gain', 'fx_loss'])->pluck('id', 'system_key');
        $lines = [];
        $actual = $batch->lines()->orderBy('line_number')->get();
        // Descriptions were localized using mutable display names in legacy receipts. Financial metadata remains exact.
        $append = function (int $account, bool $debit, BigDecimal $value, ?string $currency = null, ?BigDecimal $transaction = null, ?string $fx = null) use (&$lines, $actual): void {
            if (! $value->isPositive()) {
                throw new InvalidArgumentException('Receipt has an unrepresentable component.');
            }
            app(SalesPostingLines::class)->append($lines, count($lines) + 1, $account,
                $debit ? MoneyAmount::from($value) : MoneyAmount::zero(), $debit ? MoneyAmount::zero() : MoneyAmount::from($value),
                $currency, $transaction === null ? null : MoneyAmount::from($transaction), $fx === null ? null : ExchangeRate::from($fx),
                $actual->get(count($lines))?->description);
        };
        $append((int) $moneyLedger->id, true, $base, $payment->currency_code, $amount, (string) $rate);
        $used = BigDecimal::zero();
        $usedBase = BigDecimal::zero();
        $gain = BigDecimal::zero();
        $loss = BigDecimal::zero();
        $intent = [];
        foreach ($payment->allocations()->whereNull('application_event_id')->orderBy('id')->get() as $row) {
            $invoice = SalesInvoice::where('company_id', $company->id)->findOrFail($row->sales_invoice_id);
            $boundary = PostingBatch::where('company_id', $company->id)->findOrFail($row->prior_posting_batch_id);
            $previous = PostingBatch::where('company_id', $company->id)->where('id', '<', $batch->id)->max('id');
            if ($row->company_id !== $payment->company_id || $invoice->customer_id !== $payment->customer_id
                || ! in_array($invoice->status, ['posted', 'void'], true) || $boundary->id < $invoice->posting_batch_id || (int) $previous !== (int) $boundary->id
                || Carbon::parse($payment->payment_date)->toDateString() < Carbon::parse($invoice->issue_date)->toDateString()) {
                throw new InvalidArgumentException('Receipt allocation provenance is incoherent.');
            }
            $values = PaymentAllocationIntent::amounts(['allocated_amount' => $row->allocated_amount, 'payment_currency_amount' => $row->payment_currency_amount], $invoice->currency_code, $payment->currency_code);
            $prior = app(ReceivableReliefHistory::class)->before((int) $company->id, (int) $invoice->id, (int) $boundary->id, (int) $row->id);
            $cumulative = $prior['amount']->plus($values['document']);
            if ($cumulative->isGreaterThan($invoice->grand_total_currency)) {
                throw new InvalidArgumentException('Historical receipt over-allocation.');
            }
            $book = ($cumulative->isEqualTo($invoice->grand_total_currency) ? BigDecimal::of($invoice->grand_total_base)
                : $cumulative->multipliedBy($invoice->exchange_rate)->toScale(6, RoundingMode::HALF_UP))->minus($prior['base']);
            $used = $used->plus($values['payment']);
            $target = $used->isEqualTo($amount) ? $base : $used->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP);
            $segment = $target->minus($usedBase);
            $usedBase = $target;
            $delta = $segment->minus($book);
            if ($used->isGreaterThan($amount) || ! $segment->isPositive() || ! $segment->isEqualTo($row->settlement_base_value)
                || ! $book->isEqualTo($row->base_amount_applied_to_receivable) || ! $delta->isEqualTo($row->realized_fx_gain_loss_base)
                || ! BigDecimal::of($row->invoice_exchange_rate)->isEqualTo($invoice->exchange_rate) || ! BigDecimal::of($row->payment_exchange_rate)->isEqualTo($rate)) {
                throw new InvalidArgumentException('Receipt allocation exact values mismatch.');
            }
            $append((int) $accounts['accounts_receivable'], false, $book, $invoice->currency_code, $values['document'], $invoice->exchange_rate);
            if ($delta->isPositive()) {
                $gain = $gain->plus($delta);
            } else {
                $loss = $loss->plus($delta->abs());
            }
            $intent[] = PaymentAllocationIntent::historicalRow('sales_invoice_id', (int) $invoice->id, $row->allocated_amount, $row->payment_currency_amount, $payment->allocation_version);
        }
        if ($used->isLessThan($amount)) {
            $append((int) $accounts['accounts_receivable'], false, $base->minus($usedBase), $payment->currency_code, $amount->minus($used), (string) $rate);
        }
        if ($gain->isPositive()) {
            $append((int) $accounts['fx_gain'], false, $gain);
        }
        if ($loss->isPositive()) {
            $append((int) $accounts['fx_loss'], true, $loss);
        }
        usort($intent, fn ($a, $b) => $a['sales_invoice_id'] <=> $b['sales_invoice_id']);
        $request = ['company_id' => (int) $payment->company_id, 'actor_id' => (int) $payment->created_by,
            'customer_id' => (int) $payment->customer_id, 'money_account_id' => $payment->money_account_id,
            'payment_date' => Carbon::parse($payment->payment_date)->toDateString(), 'payment_method' => $payment->payment_method,
            'amount' => (string) $amount->toScale(6), 'exchange_rate' => (string) $rate->toScale(10),
            'reference_number' => MoneyValues::text($payment->reference_number), 'notes' => MoneyValues::text($payment->notes),
            'allocations' => $intent, 'document_locale' => null];
        if ($payment->check_id !== null) {
            $request['check_id'] = (int) $payment->check_id;
        }
        $hashes = [];
        // Legacy requests hashed the optional locale override, before applying the historical default.
        foreach ([null, 'ar', 'en'] as $locale) {
            $request['document_locale'] = $locale;
            $hashes[] = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
        }
        if (! in_array($payment->getAttribute('request_hash'), $hashes, true)) {
            throw new InvalidArgumentException('Receipt request identity mismatch.');
        }
        $command = new PostingCommand($company, Carbon::parse($payment->payment_date), 'customer_payment', (int) $payment->id,
            $payment->currency_code, $company->base_currency_code, ExchangeRate::from($rate), "customer_payment_{$payment->id}_posting",
            User::findOrFail($payment->created_by), "Customer Receipt {$payment->payment_number}", lines: $lines);
        app(VendorPaymentHistoryCommands::class)->assertBatch($command, $batch);
        if ($payment->is_reversed) {
            if ($payment->reversed_at === null || $payment->reversed_by === null) {
                throw new InvalidArgumentException('Receipt reversal metadata missing.');
            }
            app(VendorPaymentHistoryCommands::class)->assertReversal($batch, (int) $payment->reversal_posting_batch_id, (int) $payment->reversed_by);
        } elseif ($batch->status !== 'posted' || $payment->reversal_posting_batch_id !== null || $payment->reversed_at !== null || $payment->reversed_by !== null) {
            throw new InvalidArgumentException('Receipt unexpected reversal metadata.');
        }
    }
}
