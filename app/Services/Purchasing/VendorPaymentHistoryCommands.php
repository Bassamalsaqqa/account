<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Models\VendorPaymentApplicationEvent;
use App\Services\Sales\SalesPostingLines;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;

/** Read-only reconstruction of immutable intent; no current eligibility checks. */
final class VendorPaymentHistoryCommands
{
    public function __construct(private readonly PayableReliefHistory $history) {}

    public function payment(VendorPayment $payment): PostingCommand
    {
        $cid = (int) $payment->company_id;
        $company = Company::findOrFail($cid);
        $vendor = Vendor::withTrashed()->where('company_id', $cid)->findOrFail($payment->vendor_id);
        $money = MoneyAccount::withTrashed()->where('company_id', $cid)->findOrFail($payment->money_account_id);
        $amount = $this->positive($payment->amount, $payment->currency_code);
        $fx = ExchangeRate::from($payment->exchange_rate);
        $base = $amount->multipliedBy($fx->getValue())->toScale(6, RoundingMode::HALF_UP);
        $this->require($base->isPositive() && $base->isEqualTo($payment->amount_base), 'amount_base mismatch');
        $this->require($payment->base_currency_code === $company->base_currency_code
            && $money->currency_code === $payment->currency_code
            && in_array($money->account_type, [MoneyAccount::TYPE_CASH, MoneyAccount::TYPE_BANK], true)
            && ($payment->currency_code !== $payment->base_currency_code || $fx->getValue()->isEqualTo(1))
            && in_array($payment->payment_method, ['cash', 'bank_transfer'], true)
            && (($payment->payment_method === 'cash') === ($money->account_type === MoneyAccount::TYPE_CASH))
            && in_array($payment->document_locale, ['ar', 'en'], true)
            && trim($payment->payment_number) !== '' && trim((string) $payment->idempotency_key) !== '', 'Payment identity');
        $accounts = $this->accounts($cid);
        $lines = [];
        $locale = $payment->document_locale;
        $name = (string) ($payment->vendor_snapshot['name_'.$locale] ?? $payment->vendor_snapshot['name_ar'] ?? '');
        $this->append($lines, (int) $money->ledger_account_id, false, $base, "Vendor Payment {$payment->payment_number} - {$name}", $payment->currency_code, $amount, $fx);
        $used = BigDecimal::zero();
        $settled = BigDecimal::zero();
        $loss = BigDecimal::zero();
        $gain = BigDecimal::zero();
        $intent = [];
        $seen = [];
        $rows = $payment->allocations()->whereNull('application_event_id')->orderBy('id')->get();
        foreach ($rows as $row) {
            $this->require(! isset($seen[$row->purchase_id]), 'Duplicate initial allocation');
            $seen[$row->purchase_id] = true;
            $purchase = $this->purchase($payment, $row, $payment->payment_date->format('Y-m-d'));
            $value = $this->positive($row->allocated_amount, $payment->currency_code);
            $book = $this->book($purchase, $row, $value);
            $used = $used->plus($value);
            $this->require($used->isLessThanOrEqualTo($amount), 'Payment over-allocation');
            $target = $used->isEqualTo($amount) ? $base : $used->multipliedBy($fx->getValue())->toScale(6, RoundingMode::HALF_UP);
            $segment = $target->minus($settled);
            $settled = $target;
            $this->allocation($row, $purchase, $payment, $book, $segment);
            $delta = $segment->minus($book);
            if ($delta->isPositive()) {
                $loss = $loss->plus($delta);
            }
            if ($delta->isNegative()) {
                $gain = $gain->plus($delta->abs());
            }
            $this->append($lines, $accounts['accounts_payable'], true, $book, "Vendor Payment {$payment->payment_number} AP Relief Pur #{$purchase->id}", $payment->currency_code, $value, ExchangeRate::from($purchase->exchange_rate));
            $intent[] = ['purchase_id' => (int) $purchase->id, 'allocated_amount' => (string) $value->toScale(6)];
        }
        $advance = $amount->minus($used);
        if ($advance->isPositive()) {
            $this->append($lines, $accounts['accounts_payable'], true, $base->minus($settled), "Vendor Payment {$payment->payment_number} Advance/Unallocated", $payment->currency_code, $advance, $fx);
        }
        if ($loss->isPositive()) {
            $this->append($lines, $accounts['fx_loss'], true, $loss, "Vendor Payment {$payment->payment_number} Realized FX Loss");
        }
        if ($gain->isPositive()) {
            $this->append($lines, $accounts['fx_gain'], false, $gain, "Vendor Payment {$payment->payment_number} Realized FX Gain");
        }
        usort($intent, fn (array $a, array $b): int => $a['purchase_id'] <=> $b['purchase_id']);
        $payload = [
            'company_id' => $cid, 'actor_id' => (int) $payment->created_by,
            'vendor_id' => (int) $vendor->id, 'money_account_id' => (int) $money->id,
            'payment_date' => $payment->payment_date->format('Y-m-d'), 'payment_method' => $payment->payment_method,
            'amount' => (string) $amount->toScale(6), 'exchange_rate' => (string) $fx->getValue()->toScale(10),
            'reference_number' => $this->text($payment->reference_number), 'notes' => $this->text($payment->notes),
            'allocations' => $intent, 'document_locale' => null,
        ];
        $implicit = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $payload['document_locale'] = $payment->document_locale;
        $explicit = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->require($payment->request_hash !== null && in_array($payment->request_hash, [$implicit, $explicit], true), 'Payment request identity');

        return new PostingCommand($company, $payment->payment_date, 'vendor_payment', (int) $payment->id,
            $payment->currency_code, $payment->base_currency_code, $fx, "vendor_payment_{$payment->id}_posting",
            User::findOrFail($payment->created_by), "Vendor Payment {$payment->payment_number}", lines: $lines);
    }

    public function application(VendorPaymentApplicationEvent $event): ?PostingCommand
    {
        $payment = VendorPayment::where('company_id', $event->company_id)->findOrFail($event->vendor_payment_id);
        // Validate the original financial event separately; avoid event recursion.
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment, includeApplications: false);
        $date = $event->application_date->format('Y-m-d');
        $this->require($date >= $payment->payment_date->format('Y-m-d') && trim($event->idempotency_key) !== '', 'Application date/key');
        $rows = $event->allocations()->orderBy('id')->get();
        $this->require($rows->isNotEmpty(), 'Application allocations');
        $first = (int) $rows->first()->id;
        $used = BigDecimal::zero();
        $usedBase = BigDecimal::zero();
        foreach ($payment->allocations()->where('id', '<', $first)->get() as $prior) {
            $used = $used->plus($prior->allocated_amount);
            $usedBase = $usedBase->plus($prior->settlement_base_value);
        }
        $accounts = $this->accounts((int) $event->company_id);
        $lines = [];
        $intent = [];
        $seen = [];
        foreach ($rows as $row) {
            $this->require((int) $row->vendor_payment_id === (int) $payment->id
                && (int) $row->application_event_id === (int) $event->id && ! isset($seen[$row->purchase_id]), 'Application parent/set');
            $seen[$row->purchase_id] = true;
            $purchase = $this->purchase($payment, $row, $date);
            $value = $this->positive($row->allocated_amount, $payment->currency_code);
            $book = $this->book($purchase, $row, $value);
            $used = $used->plus($value);
            $this->require($used->isLessThanOrEqualTo($payment->amount), 'Advance over-consumption');
            $target = $used->isEqualTo($payment->amount) ? BigDecimal::of($payment->amount_base)
                : $used->multipliedBy($payment->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
            $segment = $target->minus($usedBase);
            $usedBase = $target;
            $this->allocation($row, $purchase, $payment, $book, $segment);
            $delta = $segment->minus($book);
            if ($delta->isPositive()) {
                $this->append($lines, $accounts['fx_loss'], true, $delta, 'Vendor advance application realized FX loss');
                $this->append($lines, $accounts['accounts_payable'], false, $delta, 'Vendor advance application AP delta');
            } elseif ($delta->isNegative()) {
                $this->append($lines, $accounts['accounts_payable'], true, $delta->abs(), 'Vendor advance application AP delta');
                $this->append($lines, $accounts['fx_gain'], false, $delta->abs(), 'Vendor advance application realized FX gain');
            }
            $intent[] = ['purchase_id' => (int) $purchase->id, 'allocated_amount' => (string) $value->toScale(6)];
        }
        usort($intent, fn (array $a, array $b): int => $a['purchase_id'] <=> $b['purchase_id']);
        $this->require($event->request_hash === ApplyVendorPaymentCreditAction::requestHash((int) $event->company_id,
            (int) $payment->id, (int) $event->applied_by, $date, $intent), 'Application request identity');

        return $lines === [] ? null : new PostingCommand(Company::findOrFail($event->company_id), Carbon::parse($date),
            'vendor_payment_application', (int) $event->id, $payment->currency_code, $payment->base_currency_code,
            ExchangeRate::from($payment->exchange_rate), "vendor-credit:{$event->id}:apply:v1", User::findOrFail($event->applied_by), 'Vendor advance application', lines: $lines);
    }

    public function assertBatch(PostingCommand $command, PostingBatch $batch): void
    {
        $this->require((int) $batch->company_id === (int) $command->company->id
            && in_array($batch->status, ['posted', 'reversed'], true)
            && $batch->posted_at !== null && (int) $batch->posted_by === (int) $command->postedBy?->id
            && $batch->idempotency_key === $command->idempotencyKey && $command->matchesBatch($batch), 'Canonical accounting payload');
        foreach ($batch->lines()->get() as $line) {
            $this->require((int) $line->company_id === (int) $batch->company_id, 'Accounting line company');
        }
    }

    public function assertReversal(PostingBatch $original, int $reversalId, int $actorId, ?string $expectedPostingDate = null): void
    {
        $rev = PostingBatch::where('company_id', $original->company_id)->findOrFail($reversalId);
        $this->require($original->status === 'reversed' && (int) $original->reversed_by_batch_id === $reversalId
            && $rev->status === 'posted' && $rev->source_type === 'reversal'
            && (int) $rev->source_id === (int) $original->id && (int) $rev->reversal_of_id === (int) $original->id
            && $rev->reversed_by_batch_id === null && $rev->posted_at !== null && (int) $rev->posted_by === $actorId
            && $rev->transaction_currency_code === $original->transaction_currency_code && $rev->base_currency_code === $original->base_currency_code
            && BigDecimal::of($rev->exchange_rate)->isEqualTo($original->exchange_rate), 'Canonical reversal linkage');
        if ($expectedPostingDate !== null) {
            $this->require($rev->posting_date->toDateString() === $expectedPostingDate, 'Canonical reversal business date');
        }
        $rows = $original->lines()->orderBy('line_number')->get();
        $inverse = $rev->lines()->orderBy('line_number')->get();
        $this->require($rows->count() === $inverse->count(), 'Reversal line count');
        foreach ($rows as $i => $line) {
            $other = $inverse[$i];
            $this->require((int) $other->company_id === (int) $original->company_id
                && $line->ledger_account_id === $other->ledger_account_id && $line->line_number === $other->line_number
                && BigDecimal::of($line->debit_base)->isEqualTo($other->credit_base)
                && BigDecimal::of($line->credit_base)->isEqualTo($other->debit_base)
                && $line->transaction_currency_code === $other->transaction_currency_code
                && $line->transaction_amount === $other->transaction_amount && $line->exchange_rate === $other->exchange_rate, 'Exact accounting inverse');
        }
    }

    private function purchase(VendorPayment $payment, VendorPaymentAllocation $row, string $date): Purchase
    {
        $purchase = Purchase::where('company_id', $payment->company_id)->findOrFail($row->purchase_id);
        $this->require((int) $row->company_id === (int) $payment->company_id
            && (int) $row->vendor_payment_id === (int) $payment->id
            && (int) $purchase->vendor_id === (int) $payment->vendor_id && $purchase->currency_code === $payment->currency_code
            && $date >= $purchase->purchase_date->format('Y-m-d'), 'Allocation source identity/date');
        app(PurchasePostingCommandBuilder::class)->validatePosted($purchase);

        return $purchase;
    }

    private function book(Purchase $purchase, VendorPaymentAllocation $row, BigDecimal $value): BigDecimal
    {
        $boundary = PostingBatch::where('company_id', $row->company_id)->find($row->prior_posting_batch_id);
        $this->require($boundary !== null && (int) $boundary->id >= (int) $purchase->posting_batch_id, 'Historical accounting boundary');
        $ownBatch = PostingBatch::where('company_id', $row->company_id)
            ->where('source_type', $row->application_event_id === null ? 'vendor_payment' : 'vendor_payment_application')
            ->where('source_id', $row->application_event_id ?? $row->vendor_payment_id)->first();
        if ($ownBatch !== null) {
            $previous = PostingBatch::where('company_id', $row->company_id)->where('id', '<', $ownBatch->id)->max('id');
            $this->require((int) $previous === (int) $row->prior_posting_batch_id, 'Exact prior accounting boundary');
        }
        $prior = $this->history->before((int) $purchase->company_id, (int) $purchase->id, (int) $row->prior_posting_batch_id, (int) $row->id);
        $cumulative = $prior['amount']->plus($value);
        $this->require($cumulative->isLessThanOrEqualTo($purchase->grand_total_currency), 'Historical over-allocation');
        $target = $cumulative->isEqualTo($purchase->grand_total_currency) ? BigDecimal::of($purchase->grand_total_base)
            : $cumulative->multipliedBy($purchase->exchange_rate)->toScale(6, RoundingMode::HALF_UP);

        return $target->minus($prior['base']);
    }

    private function allocation(VendorPaymentAllocation $row, Purchase $purchase, VendorPayment $payment, BigDecimal $book, BigDecimal $segment): void
    {
        $this->require($segment->isEqualTo($row->settlement_base_value), 'settlement base mismatch');
        $this->require($book->isPositive() && $segment->isPositive()
            && $book->isEqualTo($row->base_amount_applied_to_payable)
            && $segment->minus($book)->isEqualTo($row->realized_fx_gain_loss_base)
            && BigDecimal::of($row->purchase_exchange_rate)->isEqualTo($purchase->exchange_rate)
            && BigDecimal::of($row->payment_exchange_rate)->isEqualTo($payment->exchange_rate), 'Allocation exact values');
    }

    /** @return array<string, int> */
    private function accounts(int $companyId): array
    {
        $result = [];
        foreach (['accounts_payable', 'fx_gain', 'fx_loss'] as $key) {
            $result[$key] = (int) LedgerAccount::where('company_id', $companyId)->where('system_key', $key)->firstOrFail()->id;
        }

        return $result;
    }

    /** @param list<PostingLineCommand> $lines */
    private function append(array &$lines, int $account, bool $debit, BigDecimal $base, string $description, ?string $currency = null, ?BigDecimal $amount = null, ?ExchangeRate $fx = null): void
    {
        $this->require($base->isPositive() && ($amount === null || ($fx !== null && $fx->toBase(MoneyAmount::from($amount))->isPositive())), 'Unrepresentable accounting component');
        $value = MoneyAmount::from($base);
        app(SalesPostingLines::class)->append($lines, count($lines) + 1, $account, $debit ? $value : MoneyAmount::zero(), $debit ? MoneyAmount::zero() : $value,
            $currency, $amount !== null ? MoneyAmount::from($amount) : null, $fx, $description);
    }

    private function positive(string $value, string $currency): BigDecimal
    {
        $result = BigDecimal::of($value)->toScale($currency === 'JOD' ? 3 : 2, RoundingMode::UNNECESSARY);
        $this->require($result->isPositive(), 'Positive precise transaction amount');

        return $result;
    }

    private function text(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    private function require(bool $condition, string $invariant): void
    {
        if (! $condition) {
            throw new ImmutableRecordException("Vendor payment history integrity failed: {$invariant}.");
        }
    }
}
