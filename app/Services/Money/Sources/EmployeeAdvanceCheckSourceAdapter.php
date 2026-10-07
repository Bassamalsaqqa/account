<?php

declare(strict_types=1);

namespace App\Services\Money\Sources;

use App\Actions\Payroll\ReverseEmployeeAdvanceAction;
use App\Models\Check;
use App\Models\EmployeeAdvance;
use App\Models\User;
use App\Services\Money\Contracts\CheckFinancialSourceAdapter;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyEventCapability;
use App\Services\Phase7\Phase7FinancialRead;
use App\Services\Phase7\Phase7History;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class EmployeeAdvanceCheckSourceAdapter implements CheckFinancialSourceAdapter
{
    public function __construct(
        private readonly EmployeeAdvance $advance,
    ) {}

    public function sourceType(): string
    {
        return 'employee_advance';
    }

    public function sourceId(): int
    {
        return (int) $this->advance->id;
    }

    public function sourcePublicId(): string
    {
        return (string) $this->advance->public_id;
    }

    public function sourceModel(): Model
    {
        return $this->advance;
    }

    public function direction(): string
    {
        return 'outgoing';
    }

    public function currencyCode(): string
    {
        return (string) $this->advance->currency_code;
    }

    public function amount(): BigDecimal
    {
        return BigDecimal::of((string) $this->advance->amount);
    }

    public function exchangeRate(): BigDecimal
    {
        return BigDecimal::of((string) $this->advance->exchange_rate);
    }

    public function amountBase(): BigDecimal
    {
        return BigDecimal::of((string) $this->advance->base_amount);
    }

    public function businessDate(): string
    {
        return Carbon::parse($this->advance->advance_date)->toDateString();
    }

    public function createdBy(): int
    {
        return (int) $this->advance->created_by;
    }

    public function postingBatchId(): ?int
    {
        return $this->advance->posting_batch_id !== null ? (int) $this->advance->posting_batch_id : null;
    }

    public function isReversed(): bool
    {
        return $this->advance->status === 'reversed';
    }

    public function reversalPostingBatchId(): ?int
    {
        return $this->advance->reversal_posting_batch_id !== null ? (int) $this->advance->reversal_posting_batch_id : null;
    }

    /** @return array<string, mixed> */
    public function partySnapshot(): array
    {
        return $this->advance->employee_snapshot;
    }

    public function partyDisplayName(): string
    {
        return (string) ($this->advance->employee_snapshot['name'] ?? 'Employee');
    }

    public function validateIntegrity(Check $check): void
    {
        app(Phase7History::class)->validate($this->advance);
        if ((int) $this->advance->company_id !== (int) $check->company_id || $this->advance->payment_method !== 'check'
            || $this->advance->money_account_id !== null || (int) $this->advance->check_id !== (int) $check->id
            || $this->advance->currency_code !== $check->currency_code
            || ! $this->amount()->isEqualTo($check->amount)
            || ! $this->exchangeRate()->isEqualTo($check->exchange_rate)
            || ! $this->amountBase()->isEqualTo($check->amount_base)
            || $this->businessDate() !== $check->received_issued_date->toDateString()
            || (int) $this->advance->created_by !== (int) $check->created_by) {
            throw new InvalidArgumentException('Check has no exact canonical linked Employee Advance.');
        }

        if ($this->advance->posting_batch_id === null) {
            throw new InvalidArgumentException('Check linked Employee Advance has no canonical posting batch.');
        }
    }

    public function authorizeRead(int $companyId, User $actor): void
    {
        app(Phase7FinancialRead::class)->actor($companyId, $actor, app(Phase7FinancialRead::class)->allows($companyId, 'payroll.salary.view') ? 'payroll.salary.view' : 'payroll.advance.manage');
        setPermissionsTeamId($companyId);
        if (! app(Phase7FinancialRead::class)->allows($companyId, 'payroll.salary.view') && ! app(Phase7FinancialRead::class)->allows($companyId, 'payroll.advance.manage')) {
            throw new AuthorizationException('User does not have permission to view employee advance.');
        }
    }

    public function authorizeReverse(int $companyId, User $actor): void
    {
        app(MoneyActorGuard::class)->authorize($companyId, 'payroll.advance.manage');
    }

    public function assertCanReverse(Check $check, ?string $reason = null): void
    {
        $hasActiveAllocations = $this->advance->salaryAdvanceAllocations()
            ->where('status', 'active')
            ->exists();

        if ($hasActiveAllocations) {
            throw new InvalidArgumentException('Cannot reverse an employee advance consumed by active salary entries.');
        }
    }

    public function executeReversal(Check $check, User $actor, ?string $reason, string $date, MoneyEventCapability $capability): int
    {
        $reversed = app(ReverseEmployeeAdvanceAction::class)->execute($this->advance, $actor, $reason, $date);

        return (int) $reversed->reversal_posting_batch_id;
    }
}
