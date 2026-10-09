<?php

declare(strict_types=1);

namespace App\Application\Reporting\Presentation;

use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ReportFilterOptions
{
    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $selected
     * @param  array<string, mixed>  $search
     * @return array<string, list<array{id: string, label: string}>>
     */
    public function forReport(Company $company, array $definition, array $selected, array $search = []): array
    {
        $guard = app(ReportingGuard::class);
        $guard->authorize($company);
        $registry = app(ReportRegistry::class);
        if (! $registry->allows($company, $definition['key'])) {
            throw new AuthorizationException("Unauthorized for report [{$definition['key']}].");
        }
        $definition = app(ReportPresentationPolicy::class)->definition($company, $definition);
        $result = [];

        $tables = [
            'customer_id' => 'customers',
            'vendor_id' => 'vendors',
            'product_id' => 'products',
            'warehouse_id' => 'warehouses',
            'employee_id' => 'employees',
            'money_account_id' => 'money_accounts',
            'category_id' => $definition['group'] === 'expenses' ? 'expense_categories' : 'product_categories',
        ];

        foreach ($search as $searchField => $term) {
            if (! isset($tables[$searchField]) || ! in_array($searchField, $definition['filters'], true)) {
                throw new \InvalidArgumentException("Invalid search filter field: [{$searchField}].");
            }
            if (! is_string($term) && $term !== null) {
                throw new \InvalidArgumentException("Search term for [{$searchField}] must be a string.");
            }
            if (is_string($term) && mb_strlen($term) > 100) {
                throw new \InvalidArgumentException("Search term for [{$searchField}] exceeds maximum length of 100 characters.");
            }
        }

        foreach ($tables as $field => $table) {
            if (! in_array($field, $definition['filters'], true)) {
                continue;
            }

            // Intra-company master option privacy check
            if (! $this->canViewMasterOptions($guard, $company, $field, $definition)) {
                continue;
            }

            $query = DB::table($table)->where('company_id', $company->id)->whereNull('deleted_at');

            if ($field === 'money_account_id') {
                $types = [];
                if ($guard->allows($company, 'money.cash.view')) {
                    $types[] = 'cash';
                }
                if ($guard->allows($company, 'money.bank.view')) {
                    $types[] = 'bank';
                }
                if ($types === []) {
                    continue;
                }
                $query->whereIn('account_type', $types);
            }

            $nameColumns = $field === 'employee_id' ? ['name'] : ['name_ar', 'name_en'];
            $selectColumns = ['id', ...$nameColumns];
            if (in_array($table, ['customers', 'vendors', 'warehouses', 'employees', 'expense_categories'], true)) {
                $selectColumns[] = 'code';
            } elseif ($table === 'products') {
                $selectColumns[] = 'sku';
            } elseif ($table === 'money_accounts') {
                $selectColumns[] = 'account_number';
            }

            // Search filtering if term is supplied
            $searchTerm = trim((string) ($search[$field] ?? ''));
            if ($searchTerm !== '') {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchTerm);
                $like = '%'.$escaped.'%';

                $query->where(function ($q) use ($like, $field, $table) {
                    if ($field === 'employee_id') {
                        $q->where('name', 'LIKE', $like);
                    } else {
                        $q->where('name_ar', 'LIKE', $like)
                            ->orWhere('name_en', 'LIKE', $like);
                    }

                    if (in_array($table, ['customers', 'vendors', 'warehouses', 'employees', 'expense_categories'], true)) {
                        $q->orWhere('code', 'LIKE', $like);
                    } elseif ($table === 'products') {
                        $q->orWhere('sku', 'LIKE', $like);
                    } elseif ($table === 'money_accounts') {
                        $q->orWhere('account_number', 'LIKE', $like);
                    }
                });
            }

            $rows = (clone $query)->select($selectColumns)->orderBy('id')->limit(25)->get();

            // Retain selected authorized identity even if beyond search page or archived with financial history
            $selectedId = $selected[$field] ?? null;
            if ((is_string($selectedId) && preg_match('/^[1-9]\d*$/D', $selectedId)) || is_int($selectedId)) {
                $selectedIdInt = (int) $selectedId;
                if (! $rows->contains('id', $selectedIdInt)) {
                    $extraQuery = DB::table($table)
                        ->where('company_id', $company->id)
                        ->where('id', $selectedIdInt);
                    if ($field === 'money_account_id') {
                        $extraQuery->whereIn('account_type', $types);
                    }
                    $extra = $extraQuery->select($selectColumns)->first();
                    if ($extra !== null) {
                        $rows->prepend($extra);
                    }
                }
            }

            $result[$field] = [];
            foreach ($rows as $row) {
                $ar = (string) ($row->name_ar ?? $row->name ?? '');
                $en = (string) ($row->name_en ?? $row->name ?? '');
                $name = app()->getLocale() === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);

                $code = (string) ($row->code ?? $row->sku ?? $row->account_number ?? '');
                $label = $code !== '' ? "{$name} ({$code})" : $name;

                $result[$field][] = ['id' => (string) $row->id, 'label' => $label];
            }
        }

        if (in_array('currency_code', $definition['filters'], true)) {
            $result['currency_code'] = DB::table('company_currencies')
                ->where('company_id', $company->id)
                ->orderBy('currency_code')
                ->pluck('currency_code')
                ->map(fn ($code): array => ['id' => (string) $code, 'label' => (string) $code])
                ->all();
        }

        return $result;
    }

    /**
     * Determine if current actor is authorized to enumerate master list records.
     *
     * @param  array<string, mixed>  $definition
     */
    private function canViewMasterOptions(ReportingGuard $guard, Company $company, string $field, array $definition): bool
    {
        return match ($field) {
            'customer_id' => $guard->allows($company, 'customers.view'),
            'vendor_id' => $guard->allows($company, 'vendors.view'),
            'employee_id' => $guard->allows($company, 'employees.view'),
            'product_id', 'warehouse_id' => $guard->allows($company, 'inventory.stock.view') || $guard->allows($company, 'inventory.product.manage'),
            'money_account_id' => $guard->allows($company, 'money.cash.view') || $guard->allows($company, 'money.bank.view'),
            'category_id' => $definition['group'] === 'expenses'
                ? ($guard->allows($company, 'money.expense.view') || $guard->allows($company, 'reports.expenses.view'))
                : ($guard->allows($company, 'inventory.stock.view') || $guard->allows($company, 'inventory.product.manage')),
            default => true,
        };
    }
}
