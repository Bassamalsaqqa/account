<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\Expense;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyEventScope;
use App\Services\Money\MoneyValues;
use App\Services\Phase7\OwnsPhase7Event;
use App\Services\Phase7\Phase7EventOwner;
use App\Services\Phase7\Phase7History;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class ReverseExpenseAction implements Phase7EventOwner
{
    use OwnsPhase7Event;

    public function execute(Expense $expense, User $actor, ?string $reason = null, ?string $businessDate = null): Expense
    {
        $reason = MoneyValues::text($reason, 500);
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $expense->company_id) {
            throw new NoActiveCompanyException("Active company context does not match expense company [{$expense->company_id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $actor->belongsToCompany($expense->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$expense->company_id}].");
        }

        setPermissionsTeamId($expense->company_id);
        if (! $actor->hasPermissionTo('money.expense.reverse')) {
            throw new AuthorizationException('User does not have permission to reverse expenses.');
        }

        return $this->canonicalTransaction((int) $expense->company_id, $actor, function () use ($expense, $actor, $reason, $businessDate): Expense {
            $lockedCompany = Company::where('id', $expense->company_id)->lockForUpdate()->firstOrFail();
            app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'money.expense.reverse');

            $lockedExpense = Expense::where('id', $expense->id)->lockForUpdate()->firstOrFail();

            if ($lockedExpense->classification === 'landed_cost') {
                app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'purchasing.cost.view');
                app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'purchasing.landed_cost.manage');
            }

            if ($lockedExpense->check_id !== null) {
                app(MoneyEventScope::class)->assertCheckTransition((int) $lockedExpense->check_id, (int) $lockedCompany->id, $actor);
            }

            app(Phase7History::class)->validate($lockedExpense);

            if ($lockedExpense->status === 'reversed') {
                app(Phase7History::class)->validate($lockedExpense);

                return $lockedExpense->load(['category', 'vendor', 'moneyAccount', 'postingBatch', 'reversalPostingBatch']);
            }

            if ($lockedExpense->posting_batch_id === null || $lockedExpense->posting_batch_id === 0) {
                throw new ImmutableRecordException('Cannot reverse unposted expense.');
            }

            // Blocker check for landed costs: cannot reverse if capitalized into a posted purchase
            if ($lockedExpense->classification === 'landed_cost') {
                $hasLockedAllocations = $lockedExpense->landedCostAllocations()
                    ->where('status', 'locked')
                    ->exists();

                if ($hasLockedAllocations) {
                    throw new InvalidArgumentException('Capitalized landed expense cannot be reversed.');
                }

                // Deactivate any draft allocations
                foreach ($lockedExpense->landedCostAllocations()->where('status', 'draft')->lockForUpdate()->get() as $allocation) {
                    $allocation->fill(['status' => 'cancelled', 'cancelled_at' => now()]);
                    $this->persistPhase7($allocation);
                }

            }

            $reversalDate = $businessDate ?? Carbon::now($lockedCompany->timezone)->toDateString();
            app(SalesDocumentRules::class)->date($reversalDate);

            if ($reversalDate < Carbon::parse($lockedExpense->expense_date)->toDateString()) {
                throw new InvalidArgumentException('Expense reversal date cannot precede original expense date.');
            }

            $batch = PostingBatch::where('company_id', $lockedCompany->id)->findOrFail($lockedExpense->posting_batch_id);
            $reversalBatch = $this->reversePhase7($batch, $actor, $reason, $reversalDate);

            $lockedExpense->fill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => (int) $actor->id,
                'reversal_posting_batch_id' => (int) $reversalBatch->id,
                'reversal_reason' => $reason,
            ]);
            $this->persistPhase7($lockedExpense);

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'expense.reversed',
                "Reversed expense {$lockedExpense->expense_number}",
                (int) $actor->id,
                $lockedExpense,
                meta: [
                    'expense_id' => $lockedExpense->id,
                    'expense_number' => $lockedExpense->expense_number,
                    'reversal_batch_id' => $reversalBatch->id,
                    'reversal_reason' => $reason,
                ]
            );

            app(Phase7History::class)->validate($lockedExpense);

            return $lockedExpense->load(['category', 'vendor', 'moneyAccount', 'postingBatch', 'reversalPostingBatch']);
        });
    }
}
