<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Presentation\ReportPresentationPolicy;
use App\Application\Reporting\Presentation\ReportPresenter;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;

final class AssistantReportExecutor
{
    public const MAX_OUTPUT_BYTES = 65536;

    public const MAX_STRING_LENGTH = 255;

    public const SALES_CURRENCY_FIELDS = [
        'currency_code', 'gross_sales', 'discounts', 'returns',
        'commercial_net_sales', 'net_sales', 'tax', 'invoice_count',
    ];

    public const PURCHASE_CURRENCY_FIELDS = [
        'currency_code', 'commercial_purchases', 'discounts', 'returns',
        'net_purchases', 'tax', 'purchase_count',
    ];

    public const SAFE_COLUMNS = [
        'sales.summary' => [
            'document_number', 'business_date', 'event_type', 'currency_code',
            'gross_sales_base', 'returns_base', 'net_sales_base', 'tax_base',
        ],
        'purchases.summary' => [
            'document_number', 'business_date', 'event_type', 'currency_code',
            'commercial_purchases_base', 'returns_base', 'net_purchases_base', 'tax_base',
        ],
        'customers.balances' => [
            'customer_name', 'customer_code', 'currency_code', 'invoiced_amount', 'outstanding_balance',
        ],
        'vendors.balances' => [
            'vendor_name', 'vendor_code', 'currency_code', 'purchased_amount', 'returned_amount', 'paid_amount', 'balance',
        ],
        'inventory.low-stock' => [
            'product_name', 'sku', 'unit_code', 'quantity_on_hand', 'minimum_stock', 'shortage_quantity', 'stock_status',
        ],
        'inventory.low_stock' => [
            'product_name', 'sku', 'unit_code', 'quantity_on_hand', 'minimum_stock', 'shortage_quantity', 'stock_status',
        ],
        'inventory.expiry' => [
            'product_name', 'sku', 'warehouse_name', 'lot_number', 'expiry_date', 'expiry_status', 'remaining_quantity',
        ],
        'money.balances' => [
            'name', 'account_type', 'currency_code', 'balance_currency', 'balance_base', 'currency_balance_available', 'is_active', 'bank_name',
        ],
    ];

    public const SAFE_TOTALS = [
        'sales.summary' => [
            'gross_sales_base', 'tax_inclusive_sales_base', 'commercial_net_sales_base',
            'revenue_base', 'discounts_base', 'returns_base', 'net_sales_base',
            'tax_base', 'original_invoice_count', 'average_invoice_base', 'currencies',
        ],
        'purchases.summary' => [
            'commercial_purchases_base', 'discounts_base', 'returns_base',
            'net_purchases_base', 'tax_base', 'purchase_count', 'average_purchase_base', 'currencies',
        ],
        'customers.balances' => [
            'total_customers', 'outstanding_by_currency',
        ],
        'vendors.balances' => [
            'total_vendors', 'balance_by_currency',
        ],
        'inventory.low-stock' => [
            'low_stock_count', 'out_of_stock_count', 'total_alert_count',
        ],
        'inventory.low_stock' => [
            'low_stock_count', 'out_of_stock_count', 'total_alert_count',
        ],
        'inventory.expiry' => [
            'expired_count', 'soon_count', 'valid_count', 'unknown_count', 'total_active_lots',
        ],
        'money.balances' => [
            'total_base_balance', 'currency_balances', 'accounts_count',
        ],
    ];

    public function execute(Company $company, User $actor, AssistantReportPlan $plan): AssistantReportDto
    {
        $catalog = app(AssistantReportCatalog::class);
        $registry = app(ReportRegistry::class);
        $guard = app(ReportingGuard::class);

        // 1. Fresh check before query
        $catalog->authorize($company, $actor, $plan->reportKey);

        // 2. Resolve persisted company configuration (ignoring caller attributes)
        $persistedCompany = $guard->company($company, $actor);

        // 3. Purchasing and vendor queries strictly require purchasing.cost.view: deny without alternative projection
        if (in_array($plan->reportKey, ['purchases.summary', 'vendors.balances'], true)) {
            if (! $guard->canViewPurchasingCost($actor)) {
                throw new AuthorizationException('Purchasing cost read permission is required.');
            }
        }

        // 4. Canonical query execution exclusively via ReportRegistry
        $result = $registry->execute($persistedCompany, $plan->reportKey, $plan->queryFilters());

        // 5. Project result (independently guards, checks result type, and rechecks permission immediately before delivery)
        return $this->project($persistedCompany, $actor, $plan, $result);
    }

    public function project(Company $company, User $actor, AssistantReportPlan $plan, ReportResult $result): AssistantReportDto
    {
        $catalog = app(AssistantReportCatalog::class);
        $registry = app(ReportRegistry::class);
        $guard = app(ReportingGuard::class);
        $policy = app(ReportPresentationPolicy::class);
        $presenter = app(ReportPresenter::class);

        // 1. Fresh check before projection: catalog & company authority
        $catalog->authorize($company, $actor, $plan->reportKey);
        $persistedCompany = $guard->company($company, $actor);

        // 2. Validate result type matching plan, normalizing low_stock alias
        $normPlanKey = str_replace('-', '_', $plan->reportKey);
        $normResultType = str_replace('-', '_', $result->reportType);
        if ($normResultType !== $normPlanKey) {
            throw new \InvalidArgumentException("Report result type [{$result->reportType}] does not match plan [{$plan->reportKey}].");
        }

        // 3. Purchasing and vendor queries strictly require purchasing.cost.view: deny without alternative projection
        if (in_array($plan->reportKey, ['purchases.summary', 'vendors.balances'], true)) {
            if (! $guard->canViewPurchasingCost($actor)) {
                throw new AuthorizationException('Purchasing cost read permission is required.');
            }
        }

        $definition = $catalog->definition($plan->reportKey);
        $isCurrent = (bool) ($definition['current'] ?? false);

        // Disclose authorized scope for money accounts
        $permittedAccountTypes = [];
        if ($plan->reportKey === 'money.balances') {
            foreach (['cash', 'bank'] as $type) {
                if ($guard->allows($persistedCompany, 'money.'.$type.'.view', $actor)) {
                    $permittedAccountTypes[] = $type;
                }
            }
            if ($permittedAccountTypes === []) {
                throw new AuthorizationException('Cash or Bank read authority required.');
            }
        }

        // Configured company currencies for bounding nested currency maps (no SQL error swallowing)
        $configuredCurrencies = CompanyCurrency::withoutGlobalScopes()
            ->where('company_id', $persistedCompany->id)
            ->pluck('currency_code')
            ->all();

        $baseCode = (string) $persistedCompany->base_currency_code;
        if ($baseCode !== '' && ! in_array($baseCode, $configuredCurrencies, true)) {
            $configuredCurrencies[] = $baseCode;
        }

        // Redact and whitelist totals
        $normalizedKey = str_replace('_', '-', $plan->reportKey);
        $allowedTotals = self::SAFE_TOTALS[$plan->reportKey] ?? self::SAFE_TOTALS[$normalizedKey] ?? [];
        $totals = [];
        foreach ($result->totals as $key => $value) {
            if (! in_array($key, $allowedTotals, true)) {
                continue;
            }
            if ($value === null) {
                $totals[$key] = null;

                continue;
            }
            if (is_int($value)) {
                $totals[$key] = $value;

                continue;
            }
            if (is_bool($value)) {
                $totals[$key] = $value;

                continue;
            }
            if (is_array($value)) {
                $totals[$key] = $this->projectNestedCurrencyMap($plan->reportKey, $key, $value, $configuredCurrencies);

                continue;
            }
            $totals[$key] = (string) $value;
        }

        // Apply ReportPresentationPolicy and whitelist safe columns
        $policyColumns = $policy->columns($persistedCompany, $definition['columns'], $plan->reportKey);
        $allowedColKeys = array_column($policyColumns, 'key');
        $safeColKeys = self::SAFE_COLUMNS[$plan->reportKey] ?? self::SAFE_COLUMNS[$normalizedKey] ?? [];
        $effectiveColumns = array_values(array_filter($allowedColKeys, fn (string $k): bool => in_array($k, $safeColKeys, true)));
        if ($plan->reportKey === 'money.balances') {
            $effectiveColumns = [...$effectiveColumns, 'currency_balance_available', 'is_active'];
        }

        // Redact and whitelist rows, bounded to at most 5
        $rows = [];
        $rawRows = array_slice($result->rows, 0, 5);
        foreach ($rawRows as $rawRow) {
            $row = [];
            foreach ($effectiveColumns as $col) {
                $val = $rawRow[$col] ?? null;
                if ($val === null && in_array($col, ['name', 'product_name', 'customer_name', 'vendor_name', 'category_name', 'unit_name', 'warehouse_name'], true)) {
                    $val = $presenter->value($rawRow, $col);
                    if ($val === null) {
                        $preferred = app()->getLocale() === 'ar' ? 'ar' : 'en';
                        $fallback = $preferred === 'ar' ? 'en' : 'ar';
                        $val = $rawRow[$col.'_'.$preferred] ?? $rawRow[$col.'_'.$fallback] ?? null;
                    }
                }

                if ($val === null) {
                    $row[$col] = null;
                } elseif (is_bool($val)) {
                    $row[$col] = (bool) $val;
                } elseif (is_int($val)) {
                    $row[$col] = (int) $val;
                } else {
                    $row[$col] = $this->boundString((string) $val, $col);
                }
            }
            $rows[] = $row;
        }

        // Total rows from canonical pagination total, fallback to count
        $totalRows = (int) ($result->pagination['total'] ?? count($result->rows));

        // Currencies: base currency from persisted company, explicit filter currency
        $baseCurrency = (string) ($persistedCompany->base_currency_code ?? $result->currency['base_currency_code'] ?? '');
        $filterCurrency = isset($result->currency['filter_currency_code']) && is_string($result->currency['filter_currency_code']) && $result->currency['filter_currency_code'] !== ''
            ? $result->currency['filter_currency_code']
            : ($plan->filters['currency_code'] ?? null);

        // Period metadata
        $canonicalPeriod = $result->filters['period'] ?? null;
        $period = null;
        if (is_array($canonicalPeriod) && ! empty($canonicalPeriod['start_date']) && ! empty($canonicalPeriod['end_date'])) {
            $period = [
                'preset' => (string) ($canonicalPeriod['preset'] ?? ($plan->filters['preset'] ?? '')),
                'start_date' => (string) $canonicalPeriod['start_date'],
                'end_date' => (string) $canonicalPeriod['end_date'],
                'timezone' => (string) ($canonicalPeriod['timezone'] ?? $persistedCompany->timezone),
            ];
        }

        // As-of metadata: canonical end date for money/inventory, generated timestamp with explicit basis for current customer/vendor
        $asOf = match (true) {
            $plan->reportKey === 'money.balances' => (string) ($result->meta['as_of_date'] ?? $canonicalPeriod['end_date'] ?? Carbon::now($persistedCompany->timezone)->format('Y-m-d')),
            in_array($plan->reportKey, ['inventory.low-stock', 'inventory.expiry', 'inventory.low_stock'], true) => (string) ($result->meta['as_of_date'] ?? $canonicalPeriod['end_date'] ?? ''),
            $isCurrent => (string) $result->generatedAt,
            default => (string) ($canonicalPeriod['end_date'] ?? ''),
        };

        // Current customer and vendor reports have position basis without historical period
        if ($isCurrent && in_array($plan->reportKey, ['customers.balances', 'vendors.balances'], true)) {
            $period = null;
        }

        $scope = match ($plan->reportKey) {
            'money.balances' => ['account_types' => $permittedAccountTypes],
            default => null,
        };

        $periodLabel = $isCurrent
            ? ($definition['name_'.app()->getLocale()] ?? $definition['name_ar'])
            : ($plan->filters['preset'] ?? 'period');

        // Strictly server route reports.show with nested filters matching Livewire ReportView #[Url] filters
        $urlFilters = [];
        if (! $isCurrent) {
            if ($period !== null) {
                $urlFilters['preset'] = 'custom';
                $urlFilters['from'] = $period['start_date'];
                $urlFilters['to'] = $period['end_date'];
            }
            if ($filterCurrency !== null) {
                $urlFilters['currency_code'] = $filterCurrency;
            }
        } elseif ($plan->reportKey === 'money.balances') {
            if ($asOf !== '') {
                $urlFilters['preset'] = 'custom';
                $urlFilters['from'] = $asOf;
                $urlFilters['to'] = $asOf;
            }
            if ($filterCurrency !== null) {
                $urlFilters['currency_code'] = $filterCurrency;
            }
        } else {
            if ($filterCurrency !== null) {
                $urlFilters['currency_code'] = $filterCurrency;
            }
        }

        $sourceUrl = $urlFilters !== []
            ? route('reports.show', ['reportKey' => $plan->reportKey, 'filters' => $urlFilters])
            : route('reports.show', ['reportKey' => $plan->reportKey]);

        $title = $registry->title($plan->reportKey);

        $dto = new AssistantReportDto(
            reportKey: $plan->reportKey,
            title: $title,
            periodLabel: $periodLabel,
            timezone: (string) $persistedCompany->timezone,
            generatedAt: $result->generatedAt,
            currency: [
                'base_currency_code' => $baseCurrency,
                'filter_currency_code' => $filterCurrency,
            ],
            totals: $totals,
            rows: $rows,
            totalRows: $totalRows,
            sourceUrl: $sourceUrl,
            isCurrent: $isCurrent,
            period: $period,
            asOf: $asOf,
            scope: $scope
        );

        // Fail-closed byte limit check (never to LLM)
        $encoded = json_encode($dto->toArray(), JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_OUTPUT_BYTES) {
            throw new AssistantUnavailable('output_limit_exceeded');
        }

        // Recheck permission immediately after projection before return
        $catalog->authorize($persistedCompany, $actor, $plan->reportKey);

        return $dto;
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  list<string>  $configuredCurrencies
     * @return array<string, mixed>
     */
    private function projectNestedCurrencyMap(string $reportKey, string $totalKey, array $map, array $configuredCurrencies): array
    {
        $projected = [];
        $isSales = $reportKey === 'sales.summary' && $totalKey === 'currencies';
        $isPurchases = $reportKey === 'purchases.summary' && $totalKey === 'currencies';

        foreach ($map as $currKey => $val) {
            $currKeyStr = (string) $currKey;
            // Currency key must be 3-letter uppercase ISO code
            if (! preg_match('/^[A-Z]{3}$/D', $currKeyStr)) {
                continue;
            }
            // Strict company currency whitelist (fail closed against arbitrary keys)
            if ($configuredCurrencies !== [] && ! in_array($currKeyStr, $configuredCurrencies, true)) {
                continue;
            }

            if ($isSales) {
                if (! is_array($val)) {
                    continue;
                }
                $entry = [];
                foreach (self::SALES_CURRENCY_FIELDS as $field) {
                    if (! array_key_exists($field, $val)) {
                        continue;
                    }
                    $subVal = $val[$field];
                    $entry[$field] = is_int($subVal) ? $subVal : (string) $subVal;
                }
                $projected[$currKeyStr] = $entry;
            } elseif ($isPurchases) {
                if (! is_array($val)) {
                    continue;
                }
                $entry = [];
                foreach (self::PURCHASE_CURRENCY_FIELDS as $field) {
                    if (! array_key_exists($field, $val)) {
                        continue;
                    }
                    $subVal = $val[$field];
                    $entry[$field] = is_int($subVal) ? $subVal : (string) $subVal;
                }
                $projected[$currKeyStr] = $entry;
            } else {
                // Balance maps (outstanding_by_currency, balance_by_currency, currency_balances): exact decimal string or null only
                if ($val === null) {
                    $projected[$currKeyStr] = null;
                } elseif (is_string($val) && preg_match('/^-?\d+(?:\.\d+)?$/D', $val)) {
                    $projected[$currKeyStr] = $val;
                } elseif (is_int($val)) {
                    $projected[$currKeyStr] = (string) $val;
                }
            }
        }

        return $projected;
    }

    private function boundString(string $value, string $columnKey): string
    {
        // Monetary totals and rows are exact decimal strings; never truncate amounts, quantities, codes or status
        if (preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            return $value;
        }
        if (str_ends_with($columnKey, '_base')
            || str_ends_with($columnKey, '_currency')
            || str_contains($columnKey, 'amount')
            || str_contains($columnKey, 'balance')
            || str_contains($columnKey, 'quantity')
            || str_contains($columnKey, 'stock')) {
            return $value;
        }
        if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
            return mb_substr($value, 0, self::MAX_STRING_LENGTH);
        }

        return $value;
    }
}
