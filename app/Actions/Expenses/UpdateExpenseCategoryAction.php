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
use InvalidArgumentException;

final class UpdateExpenseCategoryAction
{
    /** @param array<string, mixed> $data */
    public function execute(ExpenseCategory $category, User $actor, array $data): ExpenseCategory
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

        return DB::transaction(function () use ($category, $actor, $data): ExpenseCategory {
            $lockedCompany = Company::where('id', $category->company_id)->lockForUpdate()->firstOrFail();
            app(Phase7FinancialRead::class)->actor((int) $lockedCompany->id, $actor, 'money.expense.manage');
            $lockedCategory = ExpenseCategory::where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($category->id);

            $updates = [];

            if (array_key_exists('code', $data)) {
                $code = trim((string) $data['code']);
                if ($code === '' || mb_strlen($code) > 32) {
                    throw new InvalidArgumentException('Expense category code is required and must not exceed 32 characters.');
                }
                if ($code !== $lockedCategory->code && ExpenseCategory::where('company_id', $lockedCompany->id)->where('code', $code)->where('id', '!=', $lockedCategory->id)->exists()) {
                    throw new InvalidArgumentException("Expense category with code [{$code}] already exists.");
                }
                $updates['code'] = $code;
            }

            if (array_key_exists('name_ar', $data)) {
                $nameAr = trim((string) $data['name_ar']);
                if ($nameAr === '' || mb_strlen($nameAr) > 255) {
                    throw new InvalidArgumentException('Expense category Arabic name is required and must not exceed 255 characters.');
                }
                $updates['name_ar'] = $nameAr;
            }

            if (array_key_exists('name_en', $data)) {
                $nameEn = isset($data['name_en']) && trim((string) $data['name_en']) !== '' ? trim((string) $data['name_en']) : null;
                if ($nameEn !== null && mb_strlen($nameEn) > 255) {
                    throw new InvalidArgumentException('Expense category English name must not exceed 255 characters.');
                }
                $updates['name_en'] = $nameEn;
            }

            if (array_key_exists('ledger_account_id', $data)) {
                $ledgerAccountId = ReceiptRequestValues::id($data['ledger_account_id']);
                $ledger = LedgerAccount::where('company_id', $lockedCompany->id)->findOrFail($ledgerAccountId);
                app(ExpenseLedger::class)->validate($ledger, true);
                if (! $ledger->active || $ledger->is_control || $ledger->account_type !== 'expense') {
                    throw new InvalidArgumentException('Expense category requires an active, non-control expense ledger account.');
                }
                $updates['ledger_account_id'] = (int) $ledger->id;
            }

            if (array_key_exists('active', $data)) {
                $updates['active'] = (bool) $data['active'];
            }

            $lockedCategory->update($updates);

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'expense_category.updated',
                "Updated expense category [{$lockedCategory->code}]",
                (int) $actor->id,
                $lockedCategory,
                meta: ['category_id' => $lockedCategory->id, 'changes' => array_keys($updates)]
            );

            return $lockedCategory;
        });
    }
}
