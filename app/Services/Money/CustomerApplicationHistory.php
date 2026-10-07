<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentApplicationEvent;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Purchasing\VendorPaymentHistoryCommands;
use App\Services\Sales\ReceivableReliefHistory;
use App\Services\Sales\SalesPostingLines;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CustomerApplicationHistory
{
    public function validate(CustomerPaymentApplicationEvent $event): void
    {
        $payment = CustomerPayment::where('company_id', $event->company_id)->findOrFail($event->customer_payment_id);
        app(CustomerPaymentHistory::class)->validate($payment);
        $date = Carbon::parse($event->application_date)->toDateString();
        $rows = $event->allocations()->orderBy('id')->get();
        if ($event->applied_at === null || $rows->isEmpty() || $date < Carbon::parse($payment->payment_date)->toDateString()) {
            throw new InvalidArgumentException('Credit application lifecycle is incoherent.');
        }
        $first = $rows->firstOrFail();
        if ($event->posting_batch_id !== null && (int) PostingBatch::where('company_id', $event->company_id)->where('id', '<', $event->posting_batch_id)->max('id') !== (int) $first->prior_posting_batch_id) {
            throw new InvalidArgumentException('Credit application accounting boundary is not its exact prior batch.');
        }
        $priorRows = DB::table('customer_payment_allocations as a')->leftJoin('customer_payment_application_events as e', 'e.id', '=', 'a.application_event_id')
            ->where('a.company_id', $event->company_id)->where('a.customer_payment_id', $payment->id)->where('a.id', '<', $first->id)
            ->where(fn ($q) => $q->whereNull('a.application_event_id')->orWhereNotNull('e.applied_at'))
            ->where(fn ($q) => $q->whereNull('e.reversal_posting_batch_id')->orWhere('e.reversal_posting_batch_id', '>', $first->prior_posting_batch_id))->get();
        $used = BigDecimal::zero();
        $usedBase = BigDecimal::zero();
        foreach ($priorRows as $priorRow) {
            $used = $used->plus($priorRow->payment_currency_amount);
            $usedBase = $usedBase->plus($priorRow->settlement_base_value);
        }
        $accounts = LedgerAccount::where('company_id', $event->company_id)->whereIn('system_key', ['accounts_receivable', 'fx_gain', 'fx_loss'])->pluck('id', 'system_key');
        $lines = [];
        $intent = [];
        foreach ($rows as $row) {
            $invoice = SalesInvoice::where('company_id', $event->company_id)->findOrFail($row->sales_invoice_id);
            if ($row->company_id !== $event->company_id || $row->customer_payment_id !== $payment->id || $invoice->customer_id !== $payment->customer_id
                || $row->prior_posting_batch_id !== $first->prior_posting_batch_id
                || $date < Carbon::parse($invoice->issue_date)->toDateString() || $row->prior_posting_batch_id < $invoice->posting_batch_id
                || ! PostingBatch::where('company_id', $event->company_id)->whereKey($row->prior_posting_batch_id)->exists()) {
                throw new InvalidArgumentException('Credit allocation historical provenance mismatch.');
            }
            $values = PaymentAllocationIntent::amounts(['allocated_amount' => $row->allocated_amount, 'payment_currency_amount' => $row->payment_currency_amount], $invoice->currency_code, $payment->currency_code);
            $prior = app(ReceivableReliefHistory::class)->before((int) $event->company_id, (int) $invoice->id, (int) $row->prior_posting_batch_id, (int) $row->id);
            $cumulative = $prior['amount']->plus($values['document']);
            $book = ($cumulative->isEqualTo($invoice->grand_total_currency) ? BigDecimal::of($invoice->grand_total_base) : $cumulative->multipliedBy($invoice->exchange_rate)->toScale(6, RoundingMode::HALF_UP))->minus($prior['base']);
            $used = $used->plus($values['payment']);
            $target = $used->isEqualTo($payment->amount) ? BigDecimal::of($payment->amount_base) : $used->multipliedBy($payment->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
            $settlement = $target->minus($usedBase);
            $usedBase = $target;
            $delta = $settlement->minus($book);
            if ($used->isGreaterThan($payment->amount) || $cumulative->isGreaterThan($invoice->grand_total_currency) || ! $book->isPositive() || ! $settlement->isPositive()
                || ! $book->isEqualTo($row->base_amount_applied_to_receivable) || ! $settlement->isEqualTo($row->settlement_base_value)
                || ! $delta->isEqualTo($row->realized_fx_gain_loss_base) || ! BigDecimal::of($row->invoice_exchange_rate)->isEqualTo($invoice->exchange_rate)
                || ! BigDecimal::of($row->payment_exchange_rate)->isEqualTo($payment->exchange_rate)) {
                throw new InvalidArgumentException('Credit allocation exact values mismatch.');
            }
            $intent[] = PaymentAllocationIntent::historicalRow('sales_invoice_id', (int) $invoice->id, $row->allocated_amount, $row->payment_currency_amount, (int) $event->allocation_version);
            if (! $delta->isZero()) {
                $amount = MoneyAmount::from($delta->abs());
                $zero = MoneyAmount::zero();
                app(SalesPostingLines::class)->append($lines, count($lines) + 1, (int) $accounts['accounts_receivable'], $delta->isPositive() ? $amount : $zero, $delta->isNegative() ? $amount : $zero, description: 'Customer Credit application AR difference');
                app(SalesPostingLines::class)->append($lines, count($lines) + 1, (int) $accounts[$delta->isPositive() ? 'fx_gain' : 'fx_loss'], $delta->isNegative() ? $amount : $zero, $delta->isPositive() ? $amount : $zero, description: 'Customer Credit application realized FX');
            }
        }
        usort($intent, fn ($a, $b) => $a['sales_invoice_id'] <=> $b['sales_invoice_id']);
        if ($event->request_hash !== ApplyCustomerPaymentCreditAction::requestHash((int) $event->company_id, (int) $payment->id, (int) $event->applied_by, $date, $intent)) {
            throw new InvalidArgumentException('Credit application request identity mismatch.');
        }
        if ($lines === []) {
            if ($event->posting_batch_id !== null || $event->reversal_posting_batch_id !== null) {
                throw new InvalidArgumentException('Zero-effect credit application must not own a batch.');
            }
        } else {
            $company = Company::findOrFail($event->company_id);
            $command = new PostingCommand($company, Carbon::parse($date), 'customer_payment_application', (int) $event->id, $payment->currency_code,
                $company->base_currency_code, ExchangeRate::from($payment->exchange_rate), "customer-credit:{$event->id}:apply:v1", User::findOrFail($event->applied_by), 'Customer Credit application', lines: $lines);
            $batch = PostingBatch::where('company_id', $event->company_id)->findOrFail($event->posting_batch_id);
            app(VendorPaymentHistoryCommands::class)->assertBatch($command, $batch);
            if ($event->reversed_at !== null) {
                app(VendorPaymentHistoryCommands::class)->assertReversal($batch, (int) $event->reversal_posting_batch_id, (int) $event->reversed_by);
            } elseif ($batch->status !== 'posted' || $event->reversal_posting_batch_id !== null) {
                throw new InvalidArgumentException('Credit application unexpected reversal.');
            }
        }
        if ($event->reversed_at !== null && (! $payment->is_reversed || $event->reversed_by === null)) {
            throw new InvalidArgumentException('Credit application reversal lifecycle mismatch.');
        }
    }
}
