<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\User;
use App\Models\VendorPayment;
use App\Services\Money\Sources\CustomerPaymentCheckSourceAdapter;
use App\Services\Money\Sources\VendorPaymentCheckSourceAdapter;
use App\Services\Purchasing\VendorPaymentHistoryCommands;
use App\Services\Sales\SalesPostingLines;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class CheckHistory
{
    public function clearance(Check $check, CheckEvent $event): PostingCommand
    {
        $company = Company::findOrFail($check->company_id);
        $bank = MoneyAccount::withTrashed()->where('company_id', $company->id)->findOrFail($event->money_account_id);
        $ledger = app(MoneyAccountLedger::class)->validate($bank);
        if ($bank->account_type !== 'bank' || $bank->currency_code !== $check->currency_code || (int) $event->ledger_account_id !== (int) $ledger->id) {
            throw new InvalidArgumentException('Check clearance Bank identity mismatch.');
        }
        $rate = MoneyValues::rate($event->exchange_rate, $check->currency_code, $company->base_currency_code);
        $amount = MoneyValues::amount($check->amount, $check->currency_code);
        $settlement = MoneyValues::base($amount, $rate);
        $book = BigDecimal::of($check->amount_base);
        $incoming = $check->direction === 'incoming';
        $gain = $incoming ? $settlement->minus($book) : $book->minus($settlement);
        if (! $settlement->isEqualTo($event->settlement_base) || ! $gain->isEqualTo($event->fx_gain_loss_base)) {
            throw new InvalidArgumentException('Check clearance base/FX mismatch.');
        }
        $control = app(CheckPaymentSource::class)->ledger($check, false);
        $lines = [];
        $zero = MoneyAmount::from('0');
        app(SalesPostingLines::class)->append($lines, count($lines) + 1, (int) $ledger->id,
            $incoming ? MoneyAmount::from($settlement) : $zero, $incoming ? $zero : MoneyAmount::from($settlement),
            $check->currency_code, MoneyAmount::from($amount), ExchangeRate::from($rate), 'Check cleared '.$check->check_number);
        app(SalesPostingLines::class)->append($lines, count($lines) + 1, (int) $control->id,
            $incoming ? $zero : MoneyAmount::from($book), $incoming ? MoneyAmount::from($book) : $zero,
            $check->currency_code, MoneyAmount::from($amount), ExchangeRate::from($check->exchange_rate), 'Check carrying value '.$check->check_number);
        if (! $gain->isZero()) {
            $fxAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', $gain->isPositive() ? 'fx_gain' : 'fx_loss')->firstOrFail();
            app(SalesPostingLines::class)->append($lines, count($lines) + 1, (int) $fxAccount->id,
                $gain->isNegative() ? MoneyAmount::from($gain->abs()) : $zero, $gain->isPositive() ? MoneyAmount::from($gain) : $zero,
                description: 'Check clearance realized FX');
        }

        return new PostingCommand($company, $event->event_date, 'check_event', (int) $event->id, $check->currency_code,
            $company->base_currency_code, ExchangeRate::from($rate), 'check-event:'.$event->public_id,
            User::findOrFail($event->actor_id), 'Check clearance '.$check->check_number, lines: $lines);
    }

    public function validate(Check $check): void
    {
        $company = Company::findOrFail($check->company_id);
        $incoming = $check->direction === 'incoming';
        $payload = $check->getAttribute('request_payload');
        if (! is_array($payload) || hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)) !== $check->request_hash
            || ($payload['company_id'] ?? null) !== (int) $check->company_id || ($payload['actor_id'] ?? null) !== (int) $check->created_by
            || ($payload['direction'] ?? null) !== $check->direction || ($payload['date'] ?? null) !== $check->received_issued_date->toDateString()
            || ($payload['due_date'] ?? null) !== $check->due_date->toDateString() || ($payload['currency_code'] ?? null) !== $check->currency_code
            || ($payload['check_number'] ?? null) !== $check->check_number
            || ($payload['bank_name'] ?? null) !== $check->bank_name || ($payload['drawer'] ?? null) !== $check->drawer
            || ($payload['notes'] ?? null) !== $check->notes || ($payload['drawn_money_account_id'] ?? null) !== $check->drawn_money_account_id
            || ! BigDecimal::of($payload['amount'] ?? '0')->isEqualTo($check->amount) || ! BigDecimal::of($payload['exchange_rate'] ?? '0')->isEqualTo($check->exchange_rate)) {
            throw new InvalidArgumentException('Check request identity mismatch.');
        }

        $source = app(CheckFinancialSourceResolver::class)->resolve($check);

        // Check party_id if legacy payment
        if ($source instanceof CustomerPaymentCheckSourceAdapter || $source instanceof VendorPaymentCheckSourceAdapter) {
            if (($payload['party_id'] ?? null) !== (int) $check->getAttribute($incoming ? 'customer_id' : 'vendor_id')) {
                throw new InvalidArgumentException('Check request party identity mismatch.');
            }
        }

        $amount = MoneyValues::amount($check->amount, $check->currency_code);
        $rate = MoneyValues::rate($check->exchange_rate, $check->currency_code, $company->base_currency_code);
        if (! in_array($check->direction, ['incoming', 'outgoing'], true) || ! MoneyValues::base($amount, $rate)->isEqualTo($check->amount_base)
            || $check->base_currency_code !== $company->base_currency_code || trim($check->check_number) === '') {
            throw new InvalidArgumentException('Check immutable identity/base mismatch.');
        }

        if ($incoming) {
            if ($check->customer_id === null || $check->vendor_id !== null || $check->drawn_money_account_id !== null) {
                throw new InvalidArgumentException('Incoming check party/drawn account mismatch.');
            }
        } else {
            if ($check->customer_id !== null || $check->drawn_money_account_id === null) {
                throw new InvalidArgumentException('Outgoing check customer/drawn account mismatch.');
            }
            if ($source instanceof VendorPaymentCheckSourceAdapter && $check->vendor_id === null) {
                throw new InvalidArgumentException('Vendor payment check requires non-null vendor.');
            }
        }

        if (! $incoming) {
            $drawnBank = MoneyAccount::withTrashed()->where('company_id', $company->id)->findOrFail($check->drawn_money_account_id);
            app(MoneyAccountLedger::class)->validate($drawnBank);
            if ($drawnBank->account_type !== 'bank' || $drawnBank->currency_code !== $check->currency_code) {
                throw new InvalidArgumentException('Outgoing Check historical Bank provenance mismatch.');
            }
        }

        // Delegate source integrity check to the adapter
        $source->validateIntegrity($check);

        // Legacy allocations intent check for CustomerPayment / VendorPayment
        if ($source instanceof CustomerPaymentCheckSourceAdapter || $source instanceof VendorPaymentCheckSourceAdapter) {
            /** @var CustomerPayment|VendorPayment $payment */
            $payment = $source->sourceModel();
            $documentKey = $incoming ? 'sales_invoice_id' : 'purchase_id';
            $initialIntent = [];
            foreach ($payment->allocations()->whereNull('application_event_id')->orderBy($documentKey)->get() as $allocation) {
                $initialIntent[] = PaymentAllocationIntent::historicalRow($documentKey, (int) $allocation->getAttribute($documentKey),
                    $allocation->allocated_amount, $allocation->payment_currency_amount, (int) $payment->allocation_version);
            }
            if ($initialIntent !== PaymentAllocationIntent::normalize($payload['allocations'] ?? [], $documentKey)
                || $payment->idempotency_key !== 'check-payment:'.$check->public_id
                || $payment->reference_number !== $check->check_number || $payment->notes !== $check->notes) {
                throw new InvalidArgumentException('Check request does not own its linked Payment initial intent.');
            }
        }

        $batch = PostingBatch::where('company_id', $check->company_id)->findOrFail($source->postingBatchId());
        if ($batch->source_type !== $source->sourceType() || (int) $batch->source_id !== (int) $source->sourceId()
            || $batch->posting_date->toDateString() !== $check->received_issued_date->toDateString()) {
            throw new InvalidArgumentException('Check original Payment batch provenance mismatch.');
        }
        $controlId = app(CheckPaymentSource::class)->ledger($check, false)->id;
        $controlNet = $batch->lines()->where('ledger_account_id', $controlId)->selectRaw('SUM(debit_base - credit_base) AS net')->value('net');
        if (! BigDecimal::of((string) ($controlNet ?? '0'))->isEqualTo($incoming ? $check->amount_base : BigDecimal::of($check->amount_base)->negated())) {
            throw new InvalidArgumentException('Check initial control carrying value mismatch.');
        }

        $state = null;
        $lastDate = $check->received_issued_date->toDateString();
        $cleared = null;
        $depositBankId = null;
        $events = $check->events()->get();
        if ($events->isEmpty()) {
            throw new InvalidArgumentException('Check event history is missing.');
        }

        foreach ($events as $index => $event) {
            $eventPayload = $event->getAttribute('request_payload');
            if (! is_array($eventPayload) || hash('sha256', json_encode($eventPayload, JSON_THROW_ON_ERROR)) !== $event->request_hash) {
                throw new InvalidArgumentException('Check event request identity mismatch.');
            }
            $date = $event->event_date->toDateString();
            if ((int) $event->company_id !== (int) $check->company_id || $event->completed_at === null || $event->from_status !== $state || $date < $lastDate) {
                throw new InvalidArgumentException('Check event chronology/completion mismatch.');
            }
            if ($index === 0) {
                if ($event->request_hash !== $check->request_hash || $event->actor_id !== $check->created_by
                    || $event->event_type !== ($incoming ? 'received' : 'issued') || $event->to_status !== $event->event_type || $date !== $lastDate || $event->posting_batch_id !== null
                    || $event->money_account_id !== null || $event->ledger_account_id !== null || $event->exchange_rate !== null || $event->settlement_base !== null || $event->fx_gain_loss_base !== null) {
                    throw new InvalidArgumentException('Check initial event mismatch.');
                }
            } elseif ($event->event_type === 'deposit') {
                if (! $incoming || $state !== 'received' || $event->to_status !== 'deposited' || $event->posting_batch_id !== null) {
                    throw new InvalidArgumentException('Check deposit provenance mismatch.');
                }
                if ($event->money_account_id === null) {
                    throw new InvalidArgumentException('Check deposit history is missing its settlement Bank.');
                }
                $bank = MoneyAccount::withTrashed()->where('company_id', $check->company_id)->find($event->money_account_id);
                if ($bank === null) {
                    throw new InvalidArgumentException('Check deposit history has no same-company settlement Bank.');
                }
                $ledger = app(MoneyAccountLedger::class)->validate($bank);
                if ($bank->account_type !== 'bank' || $bank->currency_code !== $check->currency_code || $event->ledger_account_id !== $ledger->id
                    || $event->exchange_rate !== null || $event->settlement_base !== null || $event->fx_gain_loss_base !== null) {
                    throw new InvalidArgumentException('Deposit bank currency mismatch.');
                }
                $depositBankId = $bank->id;
            } elseif ($event->event_type === 'clear') {
                if (! in_array($state, $incoming ? ['deposited'] : ['issued'], true) || $event->to_status !== 'cleared' || $date < $check->due_date->toDateString()) {
                    throw new InvalidArgumentException('Check clearance lifecycle mismatch.');
                }
                if ($event->money_account_id !== ($incoming ? $depositBankId : $check->drawn_money_account_id)) {
                    throw new InvalidArgumentException('Check clearance substituted Bank.');
                }
                $clearBatch = PostingBatch::where('company_id', $check->company_id)->findOrFail($event->posting_batch_id);
                app(VendorPaymentHistoryCommands::class)->assertBatch($this->clearance($check, $event), $clearBatch);
                $cleared = $event;
            } elseif (in_array($event->event_type, ['return', 'cancel'], true)) {
                if ($event->money_account_id !== null || $event->ledger_account_id !== null || $event->exchange_rate !== null || $event->settlement_base !== null || $event->fx_gain_loss_base !== null) {
                    throw new InvalidArgumentException('Terminal Check event cannot invent settlement values.');
                }
                if (! in_array($state, $incoming ? ['received', 'deposited', 'cleared'] : ['issued'], true) || ($state === 'cleared' && $event->event_type === 'cancel')
                    || $event->to_status !== ($event->event_type === 'return' ? 'returned' : 'cancelled') || $event->posting_batch_id !== null || ! $source->isReversed()
                    || (int) $event->payment_reversal_posting_batch_id !== (int) $source->reversalPostingBatchId()) {
                    throw new InvalidArgumentException('Check terminal Payment reversal mismatch.');
                }
                app(VendorPaymentHistoryCommands::class)->assertReversal($batch, (int) $event->payment_reversal_posting_batch_id, (int) $event->actor_id, $date);
                if ($cleared !== null) {
                    $original = PostingBatch::where('company_id', $check->company_id)->findOrFail($cleared->posting_batch_id);
                    app(VendorPaymentHistoryCommands::class)->assertReversal($original, (int) $event->reversal_posting_batch_id, (int) $event->actor_id, $date);
                } elseif ($event->reversal_posting_batch_id !== null) {
                    throw new InvalidArgumentException('Unexpected Check clearance reversal.');
                }
            } else {
                throw new InvalidArgumentException('Unknown Check event.');
            }
            if ($index > 0 && (($eventPayload['company_id'] ?? null) !== (int) $check->company_id || ($eventPayload['check_id'] ?? null) !== (int) $check->id
                || ($eventPayload['actor_id'] ?? null) !== (int) $event->actor_id || ($eventPayload['event_type'] ?? null) !== $event->event_type
                || ($eventPayload['event_date'] ?? null) !== $date || ($eventPayload['notes'] ?? null) !== $event->notes
                || (($eventPayload['money_account_id'] ?? null) !== null && $eventPayload['money_account_id'] !== $event->money_account_id)
                || ($event->event_type === 'clear' && ! BigDecimal::of($eventPayload['exchange_rate'] ?? '0')->isEqualTo($event->exchange_rate)))) {
                throw new InvalidArgumentException('Check event facts differ from request identity.');
            }
            if (! in_array($event->event_type, ['return', 'cancel'], true) && ($event->reversal_posting_batch_id !== null || $event->payment_reversal_posting_batch_id !== null)) {
                throw new InvalidArgumentException('Unexpected Check reversal provenance.');
            }
            $state = $event->to_status;
            $lastDate = $date;
        }

        if ($state !== $check->status || $source->isReversed() !== in_array($state, ['returned', 'cancelled'], true)
            || ($cleared !== null && $state === 'cleared' && PostingBatch::findOrFail($cleared->posting_batch_id)->status !== 'posted')) {
            throw new InvalidArgumentException('Check lifecycle projection mismatch.');
        }
    }
}
