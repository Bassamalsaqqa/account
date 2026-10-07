<?php

declare(strict_types=1);

namespace App\Services\Money\Sources;

use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Models\Check;
use App\Models\User;
use App\Models\VendorPayment;
use App\Services\Money\Contracts\CheckFinancialSourceAdapter;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyEventCapability;
use App\Services\Purchasing\PurchaseIdentitySnapshot;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class VendorPaymentCheckSourceAdapter implements CheckFinancialSourceAdapter
{
    public function __construct(
        private readonly VendorPayment $payment,
    ) {}

    public function sourceType(): string
    {
        return 'vendor_payment';
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
        return 'outgoing';
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
        return $this->payment->vendor !== null
            ? app(PurchaseIdentitySnapshot::class)->vendor($this->payment->vendor)
            : null;
    }

    public function partyDisplayName(): string
    {
        return (string) ($this->payment->vendor?->displayName() ?? 'Vendor');
    }

    public function validateIntegrity(Check $check): void
    {
        if ((int) $this->payment->company_id !== (int) $check->company_id || $this->payment->payment_method !== 'check'
            || $this->payment->money_account_id !== null || (int) $this->payment->vendor_id !== (int) $check->vendor_id
            || $this->payment->currency_code !== $check->currency_code
            || ! $this->amount()->isEqualTo($check->amount)
            || ! $this->exchangeRate()->isEqualTo($check->exchange_rate)
            || ! $this->amountBase()->isEqualTo($check->amount_base)
            || $this->businessDate() !== $check->received_issued_date->toDateString()
            || (int) $this->payment->created_by !== (int) $check->created_by) {
            throw new InvalidArgumentException('Check has no exact canonical linked Vendor Payment.');
        }

        app(VendorPaymentPostedIntegrityValidator::class)->validate($this->payment);
    }

    public function authorizeRead(int $companyId, User $actor): void
    {
        app(MoneyActorGuard::class)->authorize($companyId, 'money.check.view');
        app(MoneyActorGuard::class)->authorize($companyId, 'purchasing.cost.view');
    }

    public function authorizeReverse(int $companyId, User $actor): void
    {
        app(MoneyActorGuard::class)->authorize($companyId, 'money.vendor_payment.reverse');
        app(MoneyActorGuard::class)->authorize($companyId, 'purchasing.cost.view');
    }

    public function assertCanReverse(Check $check, ?string $reason = null): void
    {
        // No additional domain blockers for vendor payment reversal.
    }

    public function executeReversal(Check $check, User $actor, ?string $reason, string $date, MoneyEventCapability $capability): int
    {
        $reversed = app(ReverseVendorPaymentAction::class)->execute($this->payment, $actor, $reason, $date);

        return (int) $reversed->reversal_posting_batch_id;
    }
}
