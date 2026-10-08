<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Exceptions\CompanyReassignmentException;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EnsurePhase7FoundationAction
{
    public const array DEFAULT_CATEGORIES = [
        [
            'code' => 'fuel',
            'name_ar' => 'وقود ومحروقات',
            'name_en' => 'Fuel',
            'system_key' => 'expense_fuel',
        ],
        [
            'code' => 'transport',
            'name_ar' => 'مواصلات ونقل',
            'name_en' => 'Transport',
            'system_key' => 'expense_transport',
        ],
        [
            'code' => 'delivery',
            'name_ar' => 'خدمات التوصيل والشحن',
            'name_en' => 'Delivery & Shipping',
            'system_key' => 'expense_delivery',
        ],
        [
            'code' => 'rent',
            'name_ar' => 'إيجار',
            'name_en' => 'Rent',
            'system_key' => 'expense_rent',
        ],
        [
            'code' => 'electricity',
            'name_ar' => 'كهرباء',
            'name_en' => 'Electricity',
            'system_key' => 'expense_electricity',
        ],
        [
            'code' => 'water',
            'name_ar' => 'مياه',
            'name_en' => 'Water',
            'system_key' => 'expense_water',
        ],
        [
            'code' => 'telephone',
            'name_ar' => 'هاتف',
            'name_en' => 'Telephone',
            'system_key' => 'expense_telephone',
        ],
        [
            'code' => 'internet',
            'name_ar' => 'إنترنت',
            'name_en' => 'Internet',
            'system_key' => 'expense_internet',
        ],
        [
            'code' => 'maintenance',
            'name_ar' => 'صيانة',
            'name_en' => 'Maintenance',
            'system_key' => 'expense_maintenance',
        ],
        [
            'code' => 'office',
            'name_ar' => 'نثريات ومكتبية',
            'name_en' => 'Office Supplies',
            'system_key' => 'expense_office',
        ],
        [
            'code' => 'marketing',
            'name_ar' => 'تسويق ودعاية',
            'name_en' => 'Marketing',
            'system_key' => 'expense_marketing',
        ],
        [
            'code' => 'salary',
            'name_ar' => 'رواتب وأجور',
            'name_en' => 'Salary',
            'system_key' => 'salary_expense',
        ],
        [
            'code' => 'other',
            'name_ar' => 'أخرى',
            'name_en' => 'Other',
            'system_key' => 'expense_other',
        ],
    ];

    /**
     * Idempotently provision Phase 7 foundation:
     * - System accounts (employee advances, landed cost clearing, child expense accounts)
     * - 13 default expense categories
     * - Document sequences (EXP, ADV, SAL, SLP)
     * - Owner catalog upgrade (preserves non-owner custom roles)
     */
    public function execute(Company $company): void
    {
        $context = app(CompanyContext::class);
        $isSystem = ! $context->hasCompany();

        if (! $isSystem && $context->companyId() !== $company->id) {
            throw new CompanyReassignmentException("Cannot provision Phase 7 foundation for company [{$company->id}] when active company is [{$context->companyId()}].");
        }

        CompanyScope::executeWithoutScope(function () use ($company, $isSystem): void {
            DB::transaction(function () use ($company, $isSystem): void {
                $lockedCompany = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();

                // 1. Ensure system accounts exist
                app(EnsureSystemLedgerAccountsAction::class)->execute($lockedCompany, isSystem: $isSystem);

                // 2. Provision 13 default Expense categories
                $accounts = LedgerAccount::where('company_id', $lockedCompany->id)
                    ->whereNotNull('system_key')
                    ->get()
                    ->keyBy('system_key');

                $existingCategories = ExpenseCategory::withoutGlobalScopes()->where('company_id', $lockedCompany->id)->get();
                $existingCodes = $existingCategories->pluck('code')->filter()->all();
                $existingNamesAr = $existingCategories->pluck('name_ar')->filter()->all();

                foreach (self::DEFAULT_CATEGORIES as $def) {
                    if (in_array($def['code'], $existingCodes, true) || in_array($def['name_ar'], $existingNamesAr, true)) {
                        continue;
                    }

                    $ledger = $accounts->get($def['system_key']);
                    if ($ledger === null) {
                        continue;
                    }

                    ExpenseCategory::create([
                        'public_id' => (string) Str::ulid(),
                        'company_id' => $lockedCompany->id,
                        'code' => $def['code'],
                        'name_ar' => $def['name_ar'],
                        'name_en' => $def['name_en'],
                        'ledger_account_id' => $ledger->id,
                        'active' => true,
                        'created_by' => null,
                    ]);
                }

                // 3. Ensure Phase 7 document sequences
                app(DocumentSequenceService::class)->ensurePhase7Sequences($lockedCompany->id);

                // 4. Upgrade catalog permissions for Owner only (preserves customized non-owner roles)
                app(CompanyRoleService::class)->upgradeSalesCatalog($lockedCompany);
            });
        });
    }
}
