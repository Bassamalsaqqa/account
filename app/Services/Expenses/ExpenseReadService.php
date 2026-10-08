<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\User;
use App\Services\Phase7\Phase7FinancialRead;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ExpenseReadService
{
    /**
     * @param array{
     *     search?: string,
     *     category_id?: int|string|null,
     *     classification?: string|null,
     *     payment_method?: string|null,
     *     status?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
     * } $filters
     * @return LengthAwarePaginator<int, Expense>
     */
    public function paginate(int $companyId, User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        app(Phase7FinancialRead::class)->actor($companyId, $actor, 'money.expense.view');
        setPermissionsTeamId($companyId);
        $hasCostView = app(Phase7FinancialRead::class)->allows($companyId, 'purchasing.cost.view');

        $query = Expense::where('company_id', $companyId)->whereIn('id', app(Phase7FinancialRead::class)->visibleExpenseIds($companyId))
            ->with(['category', 'moneyAccount', 'check']);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('expense_number', 'like', "%{$search}%")
                    ->orWhere('payee_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (! empty($filters['classification']) && in_array($filters['classification'], [Expense::CLASSIFICATION_OPERATING, Expense::CLASSIFICATION_LANDED_COST], true)) {
            $query->where('classification', $filters['classification']);
        }

        if (! empty($filters['payment_method']) && in_array($filters['payment_method'], [Expense::METHOD_CASH, Expense::METHOD_BANK, Expense::METHOD_CHECK], true)) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (! empty($filters['status']) && in_array($filters['status'], [Expense::STATUS_POSTED, Expense::STATUS_REVERSED], true)) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from_date'])) {
            $query->where('expense_date', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->where('expense_date', '<=', $filters['to_date']);
        }

        $paginator = $query->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        if (! $hasCostView) {
            $paginator->getCollection()->transform(function (Expense $expense) {
                if ($expense->classification === Expense::CLASSIFICATION_LANDED_COST) {
                    $expense->setAttribute('amount', null);
                    $expense->setAttribute('base_amount', null);
                    $expense->setAttribute('exchange_rate', null);
                }

                return $expense;
            });
        }

        return $paginator;
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Expense $expense, User $actor): array
    {
        $companyId = (int) $expense->company_id;
        app(Phase7FinancialRead::class)->actor($companyId, $actor, 'money.expense.view');
        setPermissionsTeamId($companyId);
        $hasCostView = app(Phase7FinancialRead::class)->allows($companyId, 'purchasing.cost.view');

        if ($expense->classification === 'landed_cost' && ! $hasCostView) {
            throw new AuthorizationException('Landed financial read requires cost authority.');
        }
        $isCapitalized = $expense->landedCostAllocations()
            ->where('status', 'locked')
            ->exists();

        $allocations = collect();
        if ($hasCostView && $expense->classification === Expense::CLASSIFICATION_LANDED_COST) {
            $allocations = $expense->landedCostAllocations()
                ->with(['purchase', 'purchaseLine'])
                ->get();
        }

        $redactCost = ($expense->getAttribute('classification') === Expense::CLASSIFICATION_LANDED_COST && ! $hasCostView);

        return [
            'expense' => $expense,
            'has_cost_view' => $hasCostView,
            'redact_cost' => $redactCost,
            'is_capitalized' => $isCapitalized,
            'allocations' => $allocations,
            'category' => $expense->category,
            'vendor' => $expense->vendor,
            'check' => $expense->check,
            'moneyAccount' => $expense->moneyAccount,
        ];
    }
}
