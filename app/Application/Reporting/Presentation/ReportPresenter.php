<?php

declare(strict_types=1);

namespace App\Application\Reporting\Presentation;

use App\Application\Reporting\DTO\ReportResult;
use App\Support\Tenancy\CompanyContext;

final class ReportPresenter
{
    /** @param array<string, mixed> $row */
    public function value(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null && in_array($key, ['name', 'product_name', 'customer_name', 'vendor_name', 'category_name', 'unit_name'], true)) {
            $preferred = app()->getLocale() === 'ar' ? 'ar' : 'en';
            $fallback = $preferred === 'ar' ? 'en' : 'ar';
            $value = $row[$key.'_'.$preferred] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $value = $row[$key.'_'.$fallback] ?? null;
            }
            if ((! is_string($value) || trim($value) === '') && $key === 'product_name') {
                $value = $row['item_description'] ?? null;
            }
        }
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? __('reports.yes') : __('reports.no');
        }

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /** @param list<array{key:string,type:string}> $columns
     *  @return list<array{key:string,label:string,type:'text'|'decimal'}> */
    public function columns(array $columns, ReportResult $result): array
    {
        $company = app(CompanyContext::class)->company();
        $columns = app(ReportPresentationPolicy::class)->columns($company, $columns, $result->reportType);
        $out = [];
        foreach ($columns as $column) {
            $key = $column['key'];
            // A redacted optional column is absent from row keys, never populated from another source.
            if ($result->rows !== [] && ! array_filter($result->rows, fn (array $row): bool => $this->isColumnPresentInRow($row, $key))) {
                continue;
            }
            $label = __('reports.columns.'.$key);
            if ($this->baseAmount($key) || ($result->reportType === 'profit' && $key === 'amount')) {
                $label .= ' ('.($result->currency['base_currency_code'] ?? $result->currency['base_currency'] ?? '').')';
            }
            $out[] = ['key' => $key, 'label' => $label, 'type' => $column['type'] === 'decimal' ? 'decimal' : 'text'];
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    public function isColumnPresentInRow(array $row, string $key): bool
    {
        if (array_key_exists($key, $row)) {
            return true;
        }
        if (in_array($key, ['name', 'product_name', 'customer_name', 'vendor_name', 'category_name', 'unit_name'], true)) {
            if (array_key_exists($key.'_ar', $row) || array_key_exists($key.'_en', $row)) {
                return true;
            }
            if ($key === 'product_name' && array_key_exists('item_description', $row)) {
                return true;
            }
        }

        return false;
    }

    private function baseAmount(string $key): bool
    {
        return ! str_contains($key, 'quantity')
            && ! str_contains($key, 'count')
            && ! str_contains($key, 'margin')
            && ! str_contains($key, 'percent')
            && (str_contains($key, '_base') || str_starts_with($key, 'base_'));
    }

    /** @param array<string, mixed> $totals
     *  @return list<array{label:string,value:string,currency:string}> */
    public function totals(array $totals, string $baseCurrency, string $context = ''): array
    {
        $out = [];
        foreach ($totals as $key => $value) {
            if (is_array($value)) {
                $nestedContext = preg_match('/^[A-Z]{3}$/D', (string) $key) ? (string) $key : ($context !== '' && preg_match('/^[A-Z]{3}$/D', $context) ? $context : (string) $key);
                array_push($out, ...$this->totals($value, $baseCurrency, $nestedContext));
            } elseif ((is_string($value) || is_int($value)) && preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $value)) {
                $isCurrencyKey = (bool) preg_match('/^[A-Z]{3}$/D', (string) $key);
                $isContextCurrency = (bool) preg_match('/^[A-Z]{3}$/D', $context);

                $metricKey = $isCurrencyKey ? $context : (string) $key;
                $label = $metricKey !== '' ? __('reports.totals.'.$metricKey) : __('reports.summary');
                if ($label === 'reports.totals.'.$metricKey) {
                    $label = __('reports.summary');
                }

                $isCountOrQty = str_contains((string) $key, 'count')
                    || str_contains((string) $key, 'quantity')
                    || str_contains((string) $key, 'margin')
                    || str_contains((string) $key, 'percent')
                    || in_array($key, ['total_checks'], true);

                if ($isCountOrQty) {
                    $currency = '';
                } elseif ($this->baseAmount((string) $key)) {
                    $currency = $baseCurrency;
                } elseif ($isContextCurrency) {
                    $currency = $context;
                } elseif ($isCurrencyKey) {
                    $currency = (string) $key;
                } elseif (in_array($key, ['sales_revenue', 'sales_returns', 'net_sales', 'cogs', 'gross_profit', 'operating_expenses', 'salary_expense', 'inventory_loss', 'expiry_loss', 'fx_gain', 'fx_loss', 'other_revenue', 'other_expense', 'net_profit', 'total_valuation'], true)) {
                    $currency = $baseCurrency;
                } else {
                    $currency = '';
                }

                $out[] = ['label' => $label, 'value' => (string) $value, 'currency' => $currency];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $row
     * @param list<array{key:string,label:string,type:string}> $columns
     *  @return array<string, string|null> */
    public function exportRow(array $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            $out[$column['key']] = $this->value($row, $column['key']);
        }

        return $out;
    }
}
