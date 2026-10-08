<?php

declare(strict_types=1);

namespace App\Services\Phase7;

use App\Models\User;
use App\Services\Money\MoneyActorGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class Phase7FinancialRead
{
    public function allows(int $companyId, string $permission): bool
    {
        try {
            app(MoneyActorGuard::class)->authorize($companyId, $permission);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function actor(int $companyId, User $actor, string $permission): void
    {
        $current = app(MoneyActorGuard::class)->authorize($companyId, $permission);
        if ((int) $actor->id !== (int) $current->id) {
            throw new AuthorizationException('Financial read actor mismatch.');
        }
    }

    public function advance(int $companyId): bool
    {
        return $this->allows($companyId, 'payroll.salary.view') || $this->allows($companyId, 'payroll.advance.manage');
    }

    public function visibleExpenseIds(int $companyId): Builder
    {
        $view = $this->allows($companyId, 'money.expense.view');
        $cost = $this->allows($companyId, 'purchasing.cost.view');

        return DB::table('expenses')->where('company_id', $companyId)->when(! $view, fn ($q) => $q->whereRaw('0=1'))
            ->when(! $cost, fn ($q) => $q->where('classification', 'operating'))->select('id');
    }

    /** Shared financial source filter, applied before pagination, aggregates and recent limits. */
    public function visibleCheckIds(int $companyId, bool $requireCheckRead = true): Builder
    {
        $check = $this->allows($companyId, 'money.check.view');
        $cost = $this->allows($companyId, 'purchasing.cost.view');
        $expense = $this->allows($companyId, 'money.expense.view');
        $advance = $this->advance($companyId);
        $salary = $this->allows($companyId, 'payroll.salary.view');

        return DB::table('checks as visible_check')->where('visible_check.company_id', $companyId)
            ->when($requireCheckRead && ! $check, fn ($q) => $q->whereRaw('0=1'))
            ->where(function (Builder $q) use ($companyId, $check, $cost, $expense, $advance, $salary): void {
                $q->where('visible_check.direction', 'incoming');
                if (! $check) {
                    return;
                }
                $q->orWhere(function (Builder $out) use ($companyId, $cost, $expense, $advance, $salary): void {
                    $out->where('visible_check.direction', 'outgoing')->where(function (Builder $sources) use ($companyId, $cost, $expense, $advance, $salary): void {
                        $sources->whereRaw('0=1');
                        foreach (['vendor_payments' => $cost, 'expenses' => $expense, 'employee_advances' => $advance, 'salary_payments' => $salary] as $table => $allowed) {
                            if (! $allowed) {
                                continue;
                            }
                            $sources->orWhereExists(function (Builder $source) use ($table, $companyId, $cost): void {
                                $source->selectRaw('1')->from($table)->where($table.'.company_id', $companyId)
                                    ->whereColumn($table.'.check_id', 'visible_check.id');
                                if ($table === 'expenses' && ! $cost) {
                                    $source->where('classification', 'operating');
                                }
                            });
                        }
                    });
                });
            })->select('visible_check.id');
    }
}
