<?php

declare(strict_types=1);

namespace App\Services\Money\Sources;

use App\Actions\Expenses\ReverseExpenseAction;
use App\Models\Check;
use App\Models\Expense;
use App\Models\User;
use App\Services\Money\Contracts\CheckFinancialSourceAdapter;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyEventCapability;
use App\Services\Phase7\Phase7History;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class ExpenseCheckSourceAdapter implements CheckFinancialSourceAdapter
{
    public function __construct(
        private readonly Expense $expense,
    ) {}

    public function sourceType(): string
    {
        return 'expense';
    }

    public function sourceId(): int
    {
        return (int) $this->expense->id;
    }

    public function sourcePublicId(): string
    {
        return (string) $this->expense->public_id;
    }

    public function sourceModel(): Model
    {
        return $this->expense;
    }

    public function direction(): string
    {
        return 'outgoing';
    }

    public function currencyCode(): string
    {
        return (string) $this->expense->currency_code;
    }

    public function amount(): BigDecimal
    {
        return BigDecimal::of((string) $this->expense->amount);
    }

    public function exchangeRate(): BigDecimal
    {
        return BigDecimal::of((string) $this->expense->exchange_rate);
    }

    public function amountBase(): BigDecimal
    {
        return BigDecimal::of((string) $this->expense->base_amount);
    }

    public function businessDate(): string
    {
        return Carbon::parse($this->expense->expense_date)->toDateString();
    }

    public function createdBy(): int
    {
        return (int) $this->expense->created_by;
    }

    public function postingBatchId(): ?int
    {
        return $this->expense->posting_batch_id !== null ? (int) $this->expense->posting_batch_id : null;
    }

    public function isReversed(): bool
    {
        return $this->expense->status === 'reversed';
    }

    public function reversalPostingBatchId(): ?int
    {
        return $this->expense->reversal_posting_batch_id !== null ? (int) $this->expense->reversal_posting_batch_id : null;
    }

    /** @return array<string, mixed>|null */
    public function partySnapshot(): ?array
    {
        if ($this->expense->vendor_snapshot !== null) {
            return $this->expense->vendor_snapshot;
        }

        if ($this->expense->payee_name !== null) {
            return ['payee_name' => $this->expense->payee_name];
        }

        return null;
    }

    public function partyDisplayName(): string
    {
        if ($this->expense->payee_name !== null && trim($this->expense->payee_name) !== '') {
            return $this->expense->payee_name;
        }

        $snapshot = $this->expense->vendor_snapshot;
        if (is_array($snapshot)) {
            return (string) ($snapshot['name_ar'] ?? $snapshot['name_en'] ?? $snapshot['business_name_ar'] ?? $snapshot['business_name_en'] ?? 'Vendor');
        }

        return 'Expense Payee';
    }

    public function validateIntegrity(Check $check): void
    {
        app(Phase7History::class)->validate($this->expense);
        if ((int) $this->expense->company_id !== (int) $check->company_id || $this->expense->payment_method !== 'check'
            || $this->expense->money_account_id !== null || (int) $this->expense->check_id !== (int) $check->id
            || $this->expense->currency_code !== $check->currency_code
            || ! $this->amount()->isEqualTo($check->amount)
            || ! $this->exchangeRate()->isEqualTo($check->exchange_rate)
            || ! $this->amountBase()->isEqualTo($check->amount_base)
            || $this->businessDate() !== $check->received_issued_date->toDateString()
            || (int) $this->expense->created_by !== (int) $check->created_by) {
            throw new InvalidArgumentException('Check has no exact canonical linked Expense.');
        }

        if ($this->expense->posting_batch_id === null) {
            throw new InvalidArgumentException('Check linked Expense has no canonical posting batch.');
        }
    }

    public function authorizeRead(int $companyId, User $actor): void
    {
        app(MoneyActorGuard::class)->authorize($companyId, 'money.expense.view');
        if ($this->expense->classification === 'landed_cost') {
            app(MoneyActorGuard::class)->authorize($companyId, 'purchasing.cost.view');
        }
    }

    public function authorizeReverse(int $companyId, User $actor): void
    {
        app(MoneyActorGuard::class)->authorize($companyId, 'money.expense.reverse');
        if ($this->expense->classification === 'landed_cost') {
            app(MoneyActorGuard::class)->authorize($companyId, 'purchasing.cost.view');
            app(MoneyActorGuard::class)->authorize($companyId, 'purchasing.landed_cost.manage');
        }
    }

    public function assertCanReverse(Check $check, ?string $reason = null): void
    {
        if ($this->expense->classification === 'landed_cost') {
            // Check if capitalized into a posted purchase
            $isLocked = $this->expense->landedCostAllocations()
                ->where('status', 'locked')
                ->exists();

            if ($isLocked) {
                throw new InvalidArgumentException('Capitalized landed expense cannot be reversed.');
            }
        }
    }

    public function executeReversal(Check $check, User $actor, ?string $reason, string $date, MoneyEventCapability $capability): int
    {
        $reversed = app(ReverseExpenseAction::class)->execute($this->expense, $actor, $reason, $date);

        return (int) $reversed->reversal_posting_batch_id;
    }
}
