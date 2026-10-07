<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DeleteExpenseCategoryAction
{
    public function execute(ExpenseCategory $category, User $actor): void
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $category->company_id) {
            throw new NoActiveCompanyException("Active company context does not match category company [{$category->company_id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $actor->belongsToCompany($category->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$category->company_id}].");
        }

        setPermissionsTeamId($category->company_id);
        if (! $actor->hasPermissionTo('money.expense.manage')) {
            throw new AuthorizationException('User does not have permission to manage expense categories.');
        }

        DB::transaction(function () use ($category, $actor): void {
            $lockedCompany = Company::where('id', $category->company_id)->lockForUpdate()->firstOrFail();
            $lockedCategory = ExpenseCategory::where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($category->id);

            // Used mapping protection
            if (Expense::where('company_id', $lockedCompany->id)->where('category_id', $lockedCategory->id)->exists()) {
                throw new InvalidArgumentException("Cannot delete expense category [{$lockedCategory->code}] because expenses have already been recorded under it. Deactivate the category instead.");
            }

            $lockedCategory->delete();

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'expense_category.deleted',
                "Deleted expense category [{$lockedCategory->code}]",
                (int) $actor->id,
                $lockedCategory,
                meta: ['category_id' => $lockedCategory->id, 'code' => $lockedCategory->code]
            );
        });
    }
}
