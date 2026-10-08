<?php

declare(strict_types=1);

namespace App\Services\Money\Sources;

use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Models\Check;
use App\Models\CustomerPayment;
use App\Models\User;
use App\Services\Money\Contracts\CheckFinancialSourceAdapter;
use App\Services\Money\CustomerPaymentHistory;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyEventCapability;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class CustomerPaymentCheckSourceAdapter implements CheckFinancialSourceAdapter
{
    public function __construct(
        private readonly CustomerPayment $payment,
    ) {}

    public function sourceType(): string
    {
        return 'customer_payment';
    }

    public function sourceId(): int
    {
        return (int) $this->payment->id;
    }

    public function sourcePublicId(): string
    {
        return (string) $this->payment->public_id;
    }

    public function sourceModel(): Model
    {
        return $this->payment;
    }

    public function direction(): string
    {
        return 'incoming';
    }

    public function currencyCode(): string
    {
        return (string) $this->payment->currency_code;
    }

    public function amount(): BigDecimal
    {
        return BigDecimal::of((string) $this->payment->amount);
    }

    public function exchangeRate(): BigDecimal
    {
        return BigDecimal::of((string) $this->payment->exchange_rate);
    }

    public function amountBase(): BigDecimal
    {
        return BigDecimal::of((string) $this->payment->amount_base);
    }

    public function businessDate(): string
    {
        return Carbon::parse($this->payment->payment_date)->toDateString();
    }

    public function createdBy(): int
    {
        return (int) $this->payment->created_by;
    }

    public function postingBatchId(): ?int
    {
        return $this->payment->posting_batch_id !== null ? (int) $this->payment->posting_batch_id : null;
    }

    public function isReversed(): bool
    {
        return (bool) $this->payment->is_reversed;
    }

    public function reversalPostingBatchId(): ?int
    {
        return $this->payment->reversal_posting_batch_id !== null ? (int) $this->payment->reversal_posting_batch_id : null;
    }

    /** @return array<string, mixed>|null */
    public function partySnapshot(): ?array
    {
        return $this->payment->customer?->only(['name_ar', 'name_en', 'business_name_ar', 'business_name_en', 'phone', 'email']);
    }

    public function partyDisplayName(): string
    {
        return (string) ($this->payment->customer?->displayName() ?? 'Customer');
    }

    public function validateIntegrity(Check $check): void
    {
        if ((int) $this->payment->company_id !== (int) $check->company_id || $this->payment->payment_method !== 'check'
            || $this->payment->money_account_id !== null || (int) $this->payment->customer_id !== (int) $check->customer_id
            || $this->payment->currency_code !== $check->currency_code
            || ! $this->amount()->isEqualTo($check->amount)
            || ! $this->exchangeRate()->isEqualTo($check->exchange_rate)
            || ! $this->amountBase()->isEqualTo($check->amount_base)
            || $this->businessDate() !== $check->received_issued_date->toDateString()
            || (int) $this->payment->created_by !== (int) $check->created_by) {
            throw new InvalidArgumentException('Check has no exact canonical linked Customer Payment.');
        }

        app(CustomerPaymentHistory::class)->validate($this->payment);
    }

    public function authorizeRead(int $companyId, User $actor): void
    {
        app(MoneyActorGuard::class)->authorize($companyId, 'money.check.view');
    }

    public function authorizeReverse(int $companyId, User $actor): void
    {
        app(MoneyActorGuard::class)->authorize($companyId, 'money.receipt.reverse');
    }

    public function assertCanReverse(Check $check, ?string $reason = null): void
    {
        // No additional domain blockers for customer payment receipt reversal.
    }

    public function executeReversal(Check $check, User $actor, ?string $reason, string $date, MoneyEventCapability $capability): int
    {
        $reversed = app(ReverseCustomerPaymentAction::class)->execute($this->payment, $actor, $reason, $date);

        return (int) $reversed->reversal_posting_batch_id;
    }
}
