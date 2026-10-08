<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Expenses\ExpenseLedger;
use App\Services\Phase7\Phase7FinancialRead;
use App\Services\Sales\ReceiptRequestValues;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateExpenseCategoryAction
{
    /** @param array<string, mixed> $data */
    public function execute(Company $company, User $actor, array $data): ExpenseCategory
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $company->id) {
            throw new NoActiveCompanyException("Active company context does not match company [{$company->id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $actor->belongsToCompany($company->id)) {
            throw new AuthorizationException("User does not belong to company [{$company->id}].");
        }

        setPermissionsTeamId($company->id);
        if (! $actor->hasPermissionTo('money.expense.manage')) {
            throw new AuthorizationException('User does not have permission to manage expense categories.');
        }

        $code = trim((string) ($data['code'] ?? ''));
        $nameAr = trim((string) ($data['name_ar'] ?? ''));
        $nameEn = isset($data['name_en']) && trim((string) $data['name_en']) !== '' ? trim((string) $data['name_en']) : null;
        $ledgerAccountId = ReceiptRequestValues::id($data['ledger_account_id'] ?? null);

        if ($code === '' || mb_strlen($code) > 32) {
            throw new InvalidArgumentException('Expense category code is required and must not exceed 32 characters.');
        }

        if ($nameAr === '' || mb_strlen($nameAr) > 255) {
            throw new InvalidArgumentException('Expense category Arabic name is required and must not exceed 255 characters.');
        }

        if ($nameEn !== null && mb_strlen($nameEn) > 255) {
            throw new InvalidArgumentException('Expense category English name must not exceed 255 characters.');
        }

        return DB::transaction(function () use ($company, $actor, $code, $nameAr, $nameEn, $ledgerAccountId, $data): ExpenseCategory {
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            app(Phase7FinancialRead::class)->actor((int) $lockedCompany->id, $actor, 'money.expense.manage');

            if (ExpenseCategory::where('company_id', $lockedCompany->id)->where('code', $code)->exists()) {
                throw new InvalidArgumentException("Expense category with code [{$code}] already exists.");
            }

            $ledger = LedgerAccount::where('company_id', $lockedCompany->id)->findOrFail($ledgerAccountId);
            app(ExpenseLedger::class)->validate($ledger, true);
            if (! $ledger->active || $ledger->is_control || $ledger->account_type !== 'expense') {
                throw new InvalidArgumentException('Expense category requires an active, non-control expense ledger account.');
            }

            $category = ExpenseCategory::create([
                'public_id' => (string) Str::ulid(),
                'company_id' => (int) $lockedCompany->id,
                'code' => $code,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'ledger_account_id' => (int) $ledger->id,
                'active' => isset($data['active']) ? (bool) $data['active'] : true,
                'created_by' => (int) $actor->id,
            ]);

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'expense_category.created',
                "Created expense category [{$category->code}]",
                (int) $actor->id,
                $category,
                meta: ['category_id' => $category->id, 'code' => $category->code]
            );

            return $category;
        });
    }
}
