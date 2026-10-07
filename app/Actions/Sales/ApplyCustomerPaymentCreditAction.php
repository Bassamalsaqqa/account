<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Exceptions\IdempotencyConflictException;
use App\Models\CompanyCurrency;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentApplicationEvent;
use App\Models\LedgerAccount;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Money\CustomerApplicationHistory;
use App\Services\Money\PaymentAllocationIntent;
use App\Services\Posting\AccountingPostingService;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\ReceivableBookValue;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Services\Sales\SalesPostingLines;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ApplyCustomerPaymentCreditAction
{
    /** @param array{application_date: string, idempotency_key: string, allocations: list<array{sales_invoice_id: int, allocated_amount: string}>} $data */
    public function execute(CustomerPayment $payment, User $actor, array $data): CustomerPaymentApplicationEvent
    {
        $key = ReceiptRequestValues::key($data['idempotency_key']);
        app(SalesDocumentRules::class)->date($data['application_date']);
        $version = PaymentAllocationIntent::version($data['allocations']);
        $intent = PaymentAllocationIntent::normalize($data['allocations'], 'sales_invoice_id');
        if ($intent === []) {
            throw new InvalidArgumentException('Application requires a positive allocation.');
        }
        $hash = self::requestHash((int) $payment->company_id, (int) $payment->id, (int) $actor->id, $data['application_date'], $intent);

        return DB::transaction(function () use ($payment, $actor, $data, $key, $hash, $intent, $version): CustomerPaymentApplicationEvent {
            $company = app(SalesActorGuard::class)->lockAndAuthorize((int) $payment->company_id, $actor, 'money.receipt.allocate');
            $locked = CustomerPayment::where('company_id', $company->id)->lockForUpdate()->findOrFail($payment->id);
            $existing = CustomerPaymentApplicationEvent::where('company_id', $company->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw new IdempotencyConflictException('Customer Credit application key already has different caller intent.');
                }

                app(CustomerApplicationHistory::class)->validate($existing);

                return $existing->load('allocations');
            }
            if ($locked->is_reversed || $locked->posting_batch_id === null) {
                throw new InvalidArgumentException(__('sales.credit_receipt_inactive'));
            }
            CustomerPaymentApplicationEvent::where('customer_payment_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $prepared = $this->prepare($locked, $intent, $data['application_date']);
            $event = CustomerPaymentApplicationEvent::recordCanonicalApplication($locked, $actor,
                ['application_date' => $data['application_date'], 'idempotency_key' => $key, 'request_hash' => $hash, 'allocation_version' => $version], $prepared);
            $accounts = LedgerAccount::where('company_id', $company->id)->whereIn('system_key', ['accounts_receivable', 'fx_gain', 'fx_loss'])->get()->keyBy('system_key');
            $lines = [];
            foreach ($prepared as $allocation) {
                $fx = BigDecimal::of($allocation['realized_fx_gain_loss_base']);
                if ($fx->isZero()) {
                    continue;
                }
                $zero = MoneyAmount::from('0');
                $amount = MoneyAmount::from($fx->abs());
                app(SalesPostingLines::class)->append($lines, count($lines) + 1, $accounts['accounts_receivable']->id,
                    $fx->isPositive() ? $amount : $zero, $fx->isNegative() ? $amount : $zero,
                    description: 'Customer Credit application AR difference');
                app(SalesPostingLines::class)->append($lines, count($lines) + 1,
                    $accounts[$fx->isPositive() ? 'fx_gain' : 'fx_loss']->id,
                    $fx->isNegative() ? $amount : $zero, $fx->isPositive() ? $amount : $zero,
                    description: 'Customer Credit application realized FX');
            }
            $batch = $lines === [] ? null : app(AccountingPostingService::class)->post(new PostingCommand(
                company: $company, postingDate: Carbon::parse($data['application_date']), sourceType: 'customer_payment_application', sourceId: $event->id,
                transactionCurrencyCode: $locked->currency_code, baseCurrencyCode: $company->base_currency_code,
                exchangeRate: ExchangeRate::from($locked->exchange_rate), idempotencyKey: "customer-credit:{$event->id}:apply:v1", postedBy: $actor,
                description: 'Customer Credit application', lines: $lines));
            $event->completeCanonicalApplication($batch, $actor);
            app(CustomerApplicationHistory::class)->validate($event);
            app(AuditService::class)->log((int) $company->id, 'sales.receipt.credit_applied', 'Existing Customer Credit applied to invoices', $actor->id, $event);

            return $event->load('allocations');
        });
    }

    /** @param list<array{sales_invoice_id: int, allocated_amount: string}> $intent
     * @return list<array<string, mixed>>
     */
    public function prepare(CustomerPayment $payment, array $intent, ?string $applicationDate = null): array
    {
        $usedAmount = BigDecimal::of($payment->amount)->minus($payment->unallocated_amount);
        $usedBase = BigDecimal::of($payment->amount_base)->minus($payment->unallocated_amount_base);
        $remaining = BigDecimal::of($payment->unallocated_amount);
        $minor = $payment->currency_code === 'JOD' ? 3 : 2;
        $prepared = [];
        foreach ($intent as $input) {
            $invoice = SalesInvoice::where('company_id', $payment->company_id)->lockForUpdate()->find($input['sales_invoice_id']);
            if ($invoice === null || ! $invoice->isPosted() || (int) $invoice->customer_id !== (int) $payment->customer_id) {
                throw new InvalidArgumentException(__('sales.credit_invoice_mismatch'));
            }
            if (CompanyCurrency::where('company_id', $payment->company_id)->whereIn('currency_code', array_unique([$payment->currency_code, $invoice->currency_code]))->where('enabled', true)->count() !== count(array_unique([$payment->currency_code, $invoice->currency_code]))) {
                throw new InvalidArgumentException('Payment and document currencies must be enabled.');
            }
            $amounts = PaymentAllocationIntent::amounts($input, $invoice->currency_code, $payment->currency_code);
            $amount = $amounts['document'];
            $consumed = $amounts['payment'];
            if ($consumed->isGreaterThan($remaining) || $amount->isGreaterThan($invoice->calculateOutstanding())) {
                throw new InvalidArgumentException('Application exceeds payment credit or document outstanding.');
            }
            if ($applicationDate !== null && ($applicationDate < Carbon::parse($payment->payment_date)->toDateString() || $applicationDate < Carbon::parse($invoice->issue_date)->toDateString())) {
                throw new InvalidArgumentException('Application date cannot precede payment or invoice.');
            }
            $book = app(ReceivableBookValue::class)->relief($invoice, $amount);
            $usedAmount = $usedAmount->plus($consumed);
            $targetBase = $usedAmount->isEqualTo($payment->amount) ? BigDecimal::of($payment->amount_base)
                : $usedAmount->multipliedBy($payment->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
            $settlement = $targetBase->minus($usedBase);
            if (! $book->isPositive() || ! $settlement->isPositive()) {
                throw new InvalidArgumentException('Positive allocation must have representable positive base values.');
            }
            $prepared[] = ['sales_invoice_id' => $invoice->id, 'allocated_amount' => (string) $amount->toScale(6), 'payment_currency_amount' => (string) $consumed->toScale(6),
                'invoice_exchange_rate' => $invoice->exchange_rate, 'payment_exchange_rate' => $payment->exchange_rate,
                'base_amount_applied_to_receivable' => (string) $book->toScale(6), 'settlement_base_value' => (string) $settlement->toScale(6),
                'realized_fx_gain_loss_base' => (string) $settlement->minus($book)->toScale(6)];
            $usedBase = $targetBase;
            $remaining = $remaining->minus($consumed);
        }

        return $prepared;
    }

    /** @param list<array{sales_invoice_id: int, allocated_amount: string}> $intent */
    public static function requestHash(int $companyId, int $paymentId, int $actorId, string $date, array $intent): string
    {
        return hash('sha256', json_encode(['company_id' => $companyId, 'payment_id' => $paymentId, 'actor_id' => $actorId,
            'application_date' => $date, 'allocations' => $intent], JSON_THROW_ON_ERROR));
    }
}
