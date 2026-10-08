<?php

declare(strict_types=1);

namespace App\Services\Money\Sources;

use App\Actions\Payroll\ReverseSalaryPaymentAction;
use App\Models\Check;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Services\Money\Contracts\CheckFinancialSourceAdapter;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyEventCapability;
use App\Services\Phase7\Phase7History;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class SalaryPaymentCheckSourceAdapter implements CheckFinancialSourceAdapter
{
    public function __construct(
        private readonly SalaryPayment $salaryPayment,
    ) {}

    public function sourceType(): string
    {
        return 'salary_payment';
    }

    public function sourceId(): int
    {
        return (int) $this->salaryPayment->id;
    }

    public function sourcePublicId(): string
    {
        return (string) $this->salaryPayment->public_id;
    }

    public function sourceModel(): Model
    {
        return $this->salaryPayment;
    }

    public function direction(): string
    {
        return 'outgoing';
    }

    public function currencyCode(): string
    {
        return (string) $this->salaryPayment->currency_code;
    }

    public function amount(): BigDecimal
    {
        return BigDecimal::of((string) $this->salaryPayment->amount);
    }

    public function exchangeRate(): BigDecimal
    {
        return BigDecimal::of((string) $this->salaryPayment->exchange_rate);
    }

    public function amountBase(): BigDecimal
    {
        return BigDecimal::of((string) $this->salaryPayment->base_amount);
    }

    public function businessDate(): string
    {
        return Carbon::parse($this->salaryPayment->payment_date)->toDateString();
    }

    public function createdBy(): int
    {
        return (int) $this->salaryPayment->created_by;
    }

    public function postingBatchId(): ?int
    {
        return $this->salaryPayment->posting_batch_id !== null ? (int) $this->salaryPayment->posting_batch_id : null;
    }

    public function isReversed(): bool
    {
        return $this->salaryPayment->status === 'reversed';
    }

    public function reversalPostingBatchId(): ?int
    {
        return $this->salaryPayment->reversal_posting_batch_id !== null ? (int) $this->salaryPayment->reversal_posting_batch_id : null;
    }

    /** @return array<string, mixed> */
    public function partySnapshot(): array
    {
        return $this->salaryPayment->employee_snapshot;
    }

    public function partyDisplayName(): string
    {
        return (string) ($this->salaryPayment->employee_snapshot['name'] ?? 'Employee');
    }

    public function validateIntegrity(Check $check): void
    {
        app(Phase7History::class)->validate($this->salaryPayment);
        if ((int) $this->salaryPayment->company_id !== (int) $check->company_id || $this->salaryPayment->payment_method !== 'check'
            || $this->salaryPayment->money_account_id !== null || (int) $this->salaryPayment->check_id !== (int) $check->id
            || $this->salaryPayment->currency_code !== $check->currency_code
            || ! $this->amount()->isEqualTo($check->amount)
            || ! $this->exchangeRate()->isEqualTo($check->exchange_rate)
            || ! $this->amountBase()->isEqualTo($check->amount_base)
            || $this->businessDate() !== $check->received_issued_date->toDateString()
            || (int) $this->salaryPayment->created_by !== (int) $check->created_by) {
            throw new InvalidArgumentException('Check has no exact canonical linked Salary Payment.');
        }

        if ($this->salaryPayment->posting_batch_id === null) {
            throw new InvalidArgumentException('Check linked Salary Payment has no canonical posting batch.');
        }
    }

    public function authorizeRead(int $companyId, User $actor): void
    {
        app(MoneyActorGuard::class)->authorize($companyId, 'payroll.salary.view');
    }

    public function authorizeReverse(int $companyId, User $actor): void
    {
        app(MoneyActorGuard::class)->authorize($companyId, 'payroll.salary.reverse');
    }

    public function assertCanReverse(Check $check, ?string $reason = null): void
    {
        // No additional domain blockers for salary payment reversal
    }

    public function executeReversal(Check $check, User $actor, ?string $reason, string $date, MoneyEventCapability $capability): int
    {
        $reversed = app(ReverseSalaryPaymentAction::class)->execute($this->salaryPayment, $actor, $reason, $date);

        return (int) $reversed->reversal_posting_batch_id;
    }
}
