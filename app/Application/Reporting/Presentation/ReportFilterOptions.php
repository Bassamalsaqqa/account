<?php

declare(strict_types=1);

namespace App\Application\Reporting\Presentation;

use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

final class ReportFilterOptions
{
    /** @param array<string,mixed> $definition
     * @param array<string,mixed> $selected
     * @return array<string,list<array{id:string,label:string}>> */
    public function forReport(Company $company, array $definition, array $selected): array
    {
        $guard = app(ReportingGuard::class);
        $guard->authorize($company);
        $result = [];
        $tables = ['customer_id' => 'customers', 'vendor_id' => 'vendors', 'product_id' => 'products', 'warehouse_id' => 'warehouses', 'employee_id' => 'employees', 'money_account_id' => 'money_accounts', 'category_id' => $definition['group'] === 'expenses' ? 'expense_categories' : 'product_categories'];
        foreach ($tables as $field => $table) {
            if (! in_array($field, $definition['filters'], true)) {
                continue;
            }
            $query = DB::table($table)->where('company_id', $company->id);
            if ($field === 'money_account_id') {
                $types = [];
                if ($guard->allows($company, 'money.cash.view')) {
                    $types[] = 'cash';
                }
                if ($guard->allows($company, 'money.bank.view')) {
                    $types[] = 'bank';
                }
                $query->whereIn('account_type', $types);
            }
            $nameColumns = $field === 'employee_id' ? ['name'] : ['name_ar', 'name_en'];
            $rows = (clone $query)->select(['id', ...$nameColumns])->orderBy('id')->limit(100)->get();
            $selectedId = $selected[$field] ?? null;
            if ((is_string($selectedId) && preg_match('/^[1-9]\d*$/D', $selectedId)) || is_int($selectedId)) {
                if (! $rows->contains('id', $selectedId)) {
                    $extra = (clone $query)->select(['id', ...$nameColumns])->where('id', $selectedId)->first();
                    if ($extra !== null) {
                        $rows->push($extra);
                    }
                }
            }
            $result[$field] = [];
            foreach ($rows as $row) {
                $ar = (string) ($row->name_ar ?? $row->name ?? '');
                $en = (string) ($row->name_en ?? $row->name ?? '');
                $name = app()->getLocale() === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);
                $result[$field][] = ['id' => (string) $row->id, 'label' => $name];
            }
        }
        if (in_array('currency_code', $definition['filters'], true)) {
            $result['currency_code'] = DB::table('company_currencies')->where('company_id', $company->id)->orderBy('currency_code')->pluck('currency_code')->map(fn ($code): array => ['id' => (string) $code, 'label' => (string) $code])->all();
        }

        return $result;
    }
}
