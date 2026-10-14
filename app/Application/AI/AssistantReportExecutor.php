<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Presentation\ReportPresentationPolicy;
use App\Application\Reporting\Presentation\ReportPresenter;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
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

    public const AGING_CURRENCY_FIELDS = [
        'currency_code', 'unspecified', 'current', 'days_1_30',
        'days_31_60', 'days_61_90', 'days_90_plus', 'total_outstanding', 'gross_open',
    ];

    public const SAFE_COLUMNS = [
        'sales.summary' => [
            'document_number', 'business_date', 'event_type', 'currency_code',
            'gross_sales_base', 'returns_base', 'net_sales_base', 'tax_base',
            'period_key', 'original_invoice_count',
        ],
        'sales.by-period' => [
            'period_key', 'gross_sales_base', 'returns_base', 'net_sales_base', 'original_invoice_count',
        ],
        'sales.by-customer' => [
            'customer_name', 'customer_code', 'original_invoice_count', 'net_sales_base', 'returns_base',
        ],
        'sales.by-product' => [
            'product_name', 'product_sku', 'quantity_base', 'sales_revenue_base', 'unit_name',
            'cogs_base', 'gross_profit_base', 'gross_margin',
        ],
        'sales.unpaid' => [
            'invoice_number', 'issue_date', 'due_date', 'customer_name', 'currency_code',
            'grand_total_currency', 'outstanding_currency',
        ],
        'customers.balances' => [
            'customer_name', 'customer_code', 'currency_code', 'invoiced_amount', 'outstanding_balance',
        ],
        'customers.top' => [
            'rank', 'customer_name', 'original_invoice_count', 'net_sales_base', 'revenue_base', 'returns_base',
        ],
        'customers.aging' => [
            'customer_name', 'currency_code', 'current', 'days_1_30', 'days_31_60', 'days_61_90',
            'days_90_plus', 'unspecified', 'total_outstanding',
        ],
        'purchases.summary' => [
            'document_number', 'business_date', 'event_type', 'currency_code',
            'commercial_purchases_base', 'returns_base', 'net_purchases_base', 'tax_base',
            'period_key', 'purchase_count',
        ],
        'purchases.by-period' => [
            'period_key', 'commercial_purchases_base', 'returns_base', 'net_purchases_base', 'purchase_count',
        ],
        'purchases.by-vendor' => [
            'vendor_name', 'vendor_code', 'purchase_count', 'commercial_purchases_base', 'returns_base', 'net_purchases_base',
        ],
        'purchases.by-product' => [
            'product_name', 'product_sku', 'unit_name', 'quantity_base', 'commercial_total_base',
            'landed_cost_allocated_base', 'total_acquisition_cost_base',
        ],
        'purchases.unpaid' => [
            'purchase_number', 'purchase_date', 'due_date', 'vendor_name', 'currency_code',
            'grand_total_currency', 'outstanding_currency',
        ],
        'vendors.balances' => [
            'vendor_name', 'vendor_code', 'currency_code', 'purchased_amount', 'returned_amount', 'paid_amount', 'balance',
        ],
        'vendors.aging' => [
            'vendor_name', 'currency_code', 'current', 'days_1_30', 'days_31_60', 'days_61_90',
            'days_90_plus', 'unspecified', 'gross_open',
        ],
        'inventory.stock' => [
            'product_name', 'sku', 'warehouse_name', 'unit_code', 'quantity_on_hand',
            'value_base', 'average_unit_cost_base',
        ],
        'inventory.movements' => [
            'movement_date', 'product_name', 'sku', 'warehouse_name', 'movement_type',
            'quantity_delta_base', 'unit_cost_base', 'value_delta_base', 'reason',
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
        'expenses.summary' => [
            'category_name', 'category_code', 'total_base', 'transaction_count',
            'period', 'currency_code', 'total_amount',
        ],
        'expenses.by-period' => [
            'period', 'total_base', 'transaction_count',
            'category_name', 'category_code', 'currency_code', 'total_amount',
        ],
        'expenses.by-category' => [
            'category_name', 'category_code', 'total_base', 'transaction_count',
            'period', 'currency_code', 'total_amount',
        ],
        'money.balances' => [
            'name', 'account_type', 'currency_code', 'balance_currency', 'balance_base',
            'currency_balance_available', 'is_active', 'bank_name',
        ],
    ];

    public const SAFE_TOTALS = [
        'sales.summary' => [
            'gross_sales_base', 'tax_inclusive_sales_base', 'commercial_net_sales_base',
            'revenue_base', 'discounts_base', 'returns_base', 'net_sales_base',
            'tax_base', 'original_invoice_count', 'average_invoice_base', 'currencies',
        ],
        'sales.by-period' => [
            'gross_sales_base', 'tax_inclusive_sales_base', 'commercial_net_sales_base',
            'revenue_base', 'discounts_base', 'returns_base', 'net_sales_base',
            'tax_base', 'original_invoice_count', 'average_invoice_base', 'currencies',
        ],
        'sales.by-customer' => [
            'customer_count', 'gross_sales_base', 'revenue_base', 'returns_base',
            'net_sales_base', 'original_invoice_count',
        ],
        'sales.by-product' => [
            'product_count', 'quantity_base', 'sales_revenue_base',
            'cogs_base', 'gross_profit_base', 'gross_margin',
        ],
        'sales.unpaid' => [
            'unpaid_invoice_count', 'outstanding_by_currency',
        ],
        'customers.balances' => [
            'total_customers', 'outstanding_by_currency',
        ],
        'customers.top' => [
            'total_ranked_customers', 'gross_sales_base', 'revenue_base', 'returns_base',
            'net_sales_base', 'original_invoice_count',
        ],
        'customers.aging' => [
            'customer_count', 'currencies',
        ],
        'purchases.summary' => [
            'commercial_purchases_base', 'discounts_base', 'returns_base',
            'net_purchases_base', 'tax_base', 'purchase_count', 'average_purchase_base', 'currencies',
        ],
        'purchases.by-period' => [
            'commercial_purchases_base', 'discounts_base', 'returns_base',
            'net_purchases_base', 'tax_base', 'purchase_count', 'average_purchase_base', 'currencies',
        ],
        'purchases.by-vendor' => [
            'vendor_count', 'commercial_purchases_base', 'returns_base', 'net_purchases_base', 'purchase_count',
        ],
        'purchases.by-product' => [
            'product_count', 'quantity_base', 'commercial_total_base',
            'landed_cost_allocated_base', 'total_acquisition_cost_base',
        ],
        'purchases.unpaid' => [
            'unpaid_purchase_count', 'outstanding_by_currency',
        ],
        'vendors.balances' => [
            'total_vendors', 'balance_by_currency',
        ],
        'vendors.aging' => [
            'vendor_count', 'currencies',
        ],
        'inventory.stock' => [
            'total_quantity', 'products_count', 'in_stock_count', 'total_valuation',
        ],
        'inventory.movements' => [
            'total_inbound', 'total_outbound', 'net_quantity_delta', 'movement_count', 'total_value_delta',
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
        'expenses.summary' => [
            'ordinary_operating_expense_base', 'operating_currency_totals', 'legs_count',
            'fuel_total_base', 'delivery_total_base', 'transport_total_base',
            'landed_cost_clearing_base', 'landed_cost_currency_totals',
        ],
        'expenses.by-period' => [
            'ordinary_operating_expense_base', 'operating_currency_totals', 'legs_count',
            'fuel_total_base', 'delivery_total_base', 'transport_total_base',
            'landed_cost_clearing_base', 'landed_cost_currency_totals',
        ],
        'expenses.by-category' => [
            'ordinary_operating_expense_base', 'operating_currency_totals', 'legs_count',
            'fuel_total_base', 'delivery_total_base', 'transport_total_base',
            'landed_cost_clearing_base', 'landed_cost_currency_totals',
        ],
        'money.balances' => [
            'total_base_balance', 'currency_balances', 'accounts_count',
        ],
    ];

    public const EXPECTED_RESULT_TYPES = [
        'sales.summary' => ['sales.summary'],
        'sales.by-period' => ['sales.summary', 'sales.by_period'],
        'sales.by-customer' => ['sales.by_customer'],
        'sales.by-product' => ['sales.by_product'],
        'sales.unpaid' => ['sales.unpaid_invoices', 'sales.unpaid'],
        'customers.balances' => ['customers.balances'],
        'customers.top' => ['customers.top'],
        'customers.aging' => ['customers.receivables_aging', 'customers.aging'],
        'purchases.summary' => ['purchases.summary'],
        'purchases.by-period' => ['purchases.summary', 'purchases.by_period'],
        'purchases.by-vendor' => ['purchases.by_vendor'],
        'purchases.by-product' => ['purchases.by_product'],
        'purchases.unpaid' => ['purchases.unpaid'],
        'vendors.balances' => ['vendors.balances'],
        'vendors.aging' => ['vendors.payables_aging', 'vendors.aging'],
        'inventory.stock' => ['inventory.stock_on_hand', 'inventory.stock'],
        'inventory.movements' => ['inventory.movement', 'inventory.movements'],
        'inventory.low-stock' => ['inventory.low_stock', 'inventory.low-stock'],
        'inventory.low_stock' => ['inventory.low_stock', 'inventory.low-stock'],
        'inventory.expiry' => ['inventory.expiry'],
        'expenses.summary' => ['expenses.summary'],
        'expenses.by-period' => ['expenses.summary', 'expenses.by_period'],
        'expenses.by-category' => ['expenses.summary', 'expenses.by_category'],
        'money.balances' => ['money.balances'],
    ];

    public function execute(Company $company, User $actor, AssistantReportPlan $plan): AssistantReportDto
    {
        if ($plan->reportKey === 'sales.last-invoice') {
            return app(AssistantReadTools::class)->latestSalesInvoice($company, $actor, $plan);
        }

        $catalog = app(AssistantReportCatalog::class);
        $registry = app(ReportRegistry::class);
        $guard = app(ReportingGuard::class);

        // 1. Fresh check before query
        $catalog->authorize($company, $actor, $plan->reportKey);

        // 2. Resolve persisted company configuration (ignoring caller attributes)
        $persistedCompany = $guard->company($company, $actor);

        // 3. Purchasing and vendor queries strictly require purchasing.cost.view: deny without alternative projection
        if (str_starts_with($plan->reportKey, 'purchases.') || str_starts_with($plan->reportKey, 'vendors.')) {
            if (! $guard->canViewPurchasingCost($actor)) {
                throw new AuthorizationException('Purchasing cost read permission is required.');
            }
        }

        // 4. Resolve human entity names strictly to same-company IDs before passing to ReportRegistry
        $canonicalFilters = AssistantReportEntityResolver::resolve($persistedCompany, $actor, $plan);

        // 5. Canonical query execution exclusively via ReportRegistry
        $result = $registry->execute($persistedCompany, $plan->reportKey, $canonicalFilters);

        // 6. Project primary result
        $dto = $this->project($persistedCompany, $actor, $plan, $result, $canonicalFilters);

        // 7. Comparison execution for temporal reports (if requested)
        if (isset($plan->filters['compare'])) {
            $comparisonPeriod = $this->resolvePreviousPeriod($result, $persistedCompany, $canonicalFilters, $plan->filters['compare']);
            $comparisonFilters = $canonicalFilters;
            unset($comparisonFilters['preset'], $comparisonFilters['period']);
            $comparisonFilters['start_date'] = $comparisonPeriod['start_date'];
            $comparisonFilters['end_date'] = $comparisonPeriod['end_date'];

            // Re-authorize immediately before adjacent query
            $catalog->authorize($persistedCompany, $actor, $plan->reportKey);
            $comparisonResult = $registry->execute($persistedCompany, $plan->reportKey, $comparisonFilters);
            $comparisonDto = $this->project($persistedCompany, $actor, $plan, $comparisonResult, $comparisonFilters);

            $dto = new AssistantReportDto(
                reportKey: $dto->reportKey,
                title: $dto->title,
                periodLabel: $dto->periodLabel,
                timezone: $dto->timezone,
                generatedAt: $dto->generatedAt,
                currency: $dto->currency,
                totals: $dto->totals,
                rows: $dto->rows,
                totalRows: $dto->totalRows,
                sourceUrl: $dto->sourceUrl,
                isCurrent: $dto->isCurrent,
                period: $dto->period,
                asOf: $dto->asOf,
                scope: $dto->scope,
                comparison: [
                    'mode' => $plan->filters['compare'],
                    'period' => $comparisonDto->period,
                    'period_label' => $comparisonDto->periodLabel,
                    'totals' => $comparisonDto->totals,
                    'total_rows' => $comparisonDto->totalRows,
                    'source_url' => $comparisonDto->sourceUrl,
                ],
            );
        }

        // Fail-closed byte limit check on complete output
        $encoded = json_encode($dto->toArray(), JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_OUTPUT_BYTES) {
            throw new AssistantUnavailable('output_limit_exceeded');
        }

        // Recheck permission immediately after projection before return
        $catalog->authorize($persistedCompany, $actor, $plan->reportKey);

        return $dto;
    }

    /**
     * @param  array<string, mixed>|null  $canonicalFilters
     */
    public function project(
        Company $company,
        User $actor,
        AssistantReportPlan $plan,
        ReportResult $result,
        ?array $canonicalFilters = null,
    ): AssistantReportDto {
        $catalog = app(AssistantReportCatalog::class);
        $registry = app(ReportRegistry::class);
        $guard = app(ReportingGuard::class);
        $policy = app(ReportPresentationPolicy::class);
        $presenter = app(ReportPresenter::class);

        // 1. Fresh check before projection: catalog & company authority
        $catalog->authorize($company, $actor, $plan->reportKey);
        $persistedCompany = $guard->company($company, $actor);

        // 2. Validate result type matching plan against expected canonical queries
        $normResultType = str_replace('-', '_', $result->reportType);
        $expectedTypes = self::EXPECTED_RESULT_TYPES[$plan->reportKey] ?? [str_replace('-', '_', $plan->reportKey)];
        $normalizedExpected = array_map(fn (string $t): string => str_replace('-', '_', $t), $expectedTypes);

        if (! in_array($result->reportType, $expectedTypes, true) && ! in_array($normResultType, $normalizedExpected, true)) {
            throw new \InvalidArgumentException("Report result type [{$result->reportType}] does not match plan [{$plan->reportKey}].");
        }

        // 3. Purchasing and vendor queries strictly require purchasing.cost.view: deny without alternative projection
        if (str_starts_with($plan->reportKey, 'purchases.') || str_starts_with($plan->reportKey, 'vendors.')) {
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

            // Sensitive cost/profit redaction on totals
            if (in_array($key, ['cogs_base', 'gross_profit_base', 'gross_margin'], true)) {
                if (! $guard->allows($persistedCompany, ['reports.cost.view', 'reports.profit.view', 'inventory.cost.view'], $actor)) {
                    continue;
                }
            }
            if (in_array($key, ['total_valuation', 'total_value_delta'], true)) {
                if (! $guard->canViewInventoryCost($actor)) {
                    continue;
                }
            }
            if (in_array($key, ['landed_cost_clearing_base', 'landed_cost_currency_totals'], true)) {
                if (! $guard->canViewPurchasingCost($actor)) {
                    continue;
                }
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
        $columnDefinition = $definition;
        $grouping = $canonicalFilters['grouping'] ?? $plan->filters['grouping'] ?? null;
        if ($grouping !== null && in_array($plan->reportKey, ['sales.summary', 'purchases.summary'], true)) {
            $columnDefinition = $registry->definition(str_starts_with($plan->reportKey, 'sales.') ? 'sales.by-period' : 'purchases.by-period');
        } elseif ($grouping !== null && str_starts_with($plan->reportKey, 'expenses.')) {
            $columnDefinition = $registry->definition(match ($grouping) {
                'currency' => 'expenses.by-currency',
                'period', 'trend' => 'expenses.by-period',
                default => 'expenses.by-category',
            });
        }
        $policyColumns = $policy->columns($persistedCompany, $columnDefinition['columns'], $plan->reportKey);
        $allowedColKeys = array_column($policyColumns, 'key');
        $safeColKeys = self::SAFE_COLUMNS[$plan->reportKey] ?? self::SAFE_COLUMNS[$normalizedKey] ?? [];
        $effectiveColumns = array_values(array_filter($allowedColKeys, fn (string $k): bool => in_array($k, $safeColKeys, true)));
        if ($plan->reportKey === 'money.balances') {
            $effectiveColumns = array_values(array_unique([...$effectiveColumns, 'currency_balance_available', 'is_active']));
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
                'preset' => (string) ($canonicalPeriod['preset'] ?? ($plan->filters['preset'] ?? 'custom')),
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

        $appliedNames = array_intersect_key($plan->filters, array_flip([
            'customer_name', 'vendor_name', 'product_name', 'warehouse_name', 'category_name', 'grouping',
        ]));
        if ($appliedNames !== []) {
            $scope = [...($scope ?? []), 'filters' => $appliedNames];
        }

        $periodLabel = $isCurrent
            ? ($definition['name_'.app()->getLocale()] ?? $definition['name_ar'])
            : ($period !== null ? $period['start_date'].' - '.$period['end_date'] : 'period');

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

        // Append all applied canonical entity and grouping filters to source URL
        $filtersSource = $canonicalFilters ?? $plan->filters;
        foreach (['customer_id', 'vendor_id', 'product_id', 'warehouse_id', 'category_id', 'grouping'] as $appliedKey) {
            if (isset($filtersSource[$appliedKey])) {
                $urlFilters[$appliedKey] = $filtersSource[$appliedKey];
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
     * @param  array<string, mixed>  $canonicalFilters
     * @return array{start_date: string, end_date: string}
     */
    private function resolvePreviousPeriod(ReportResult $result, Company $company, array $canonicalFilters, string $mode): array
    {
        $canonicalPeriod = $result->filters['period'] ?? null;
        $preset = $canonicalFilters['preset'] ?? $canonicalPeriod['preset'] ?? null;
        $startDate = $canonicalPeriod['start_date'] ?? $canonicalFilters['start_date'] ?? null;
        $endDate = $canonicalPeriod['end_date'] ?? $canonicalFilters['end_date'] ?? null;
        if (! is_string($preset) || ! in_array($preset, ReportPeriod::ALLOWED_PRESETS, true)
            || ! is_string($startDate) || ! is_string($endDate)) {
            throw new AssistantUnavailable('question_invalid');
        }
        try {
            // Canonical validation refuses impossible dates instead of Carbon normalization.
            AssistantReportPlan::assertBoundedPeriod(ReportPeriod::custom($startDate, $endDate, $company));
            $start = CarbonImmutable::parse($startDate, $company->timezone);
            $end = CarbonImmutable::parse($endDate, $company->timezone);
        } catch (\InvalidArgumentException $e) {
            throw new AssistantUnavailable('question_invalid');
        }

        [$previousStart, $previousEnd] = $mode === 'previous_year'
            ? match ($preset) {
                'this_month', 'last_month' => [$start->subYearNoOverflow()->startOfMonth(), $end->subYearNoOverflow()->endOfMonth()],
                'this_quarter' => [$start->subYearNoOverflow()->startOfQuarter(), $end->subYearNoOverflow()->endOfQuarter()],
                'this_year', 'last_year' => [$start->subYearNoOverflow()->startOfYear(), $end->subYearNoOverflow()->endOfYear()],
                default => [$start->subYearNoOverflow(), $end->subYearNoOverflow()],
            }
        : match ($preset) {
            'this_month', 'last_month' => [$start->subMonthNoOverflow()->startOfMonth(), $start->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$start->subMonthsNoOverflow(3)->startOfQuarter(), $start->subMonthsNoOverflow(3)->endOfQuarter()],
            'this_year', 'last_year' => [$start->subYearsNoOverflow(1)->startOfYear(), $start->subYearsNoOverflow(1)->endOfYear()],
            'this_week', 'last_week' => [$start->subWeek()->startOfWeek(CarbonImmutable::MONDAY), $start->subWeek()->endOfWeek(CarbonImmutable::SUNDAY)],
            'today', 'yesterday' => [$start->subDay(), $start->subDay()],
            // Explicit custom ranges retain equal calendar-day duration, including full-month ranges.
            default => [$start->subDays((int) $start->toDateTimeImmutable()->diff($end->toDateTimeImmutable())->days + 1), $start->subDay()],
        };

        AssistantReportPlan::assertBoundedPeriod(ReportPeriod::custom($previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d'), $company));

        return ['start_date' => $previousStart->format('Y-m-d'), 'end_date' => $previousEnd->format('Y-m-d')];
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  list<string>  $configuredCurrencies
     * @return array<string, mixed>
     */
    private function projectNestedCurrencyMap(string $reportKey, string $totalKey, array $map, array $configuredCurrencies): array
    {
        $projected = [];
        $isSales = in_array($reportKey, ['sales.summary', 'sales.by-period'], true) && $totalKey === 'currencies';
        $isPurchases = in_array($reportKey, ['purchases.summary', 'purchases.by-period'], true) && $totalKey === 'currencies';
        $isAging = in_array($reportKey, ['customers.aging', 'vendors.aging'], true) && $totalKey === 'currencies';

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
            } elseif ($isAging) {
                if (! is_array($val)) {
                    continue;
                }
                $entry = [];
                foreach (self::AGING_CURRENCY_FIELDS as $field) {
                    if (! array_key_exists($field, $val)) {
                        continue;
                    }
                    $subVal = $val[$field];
                    $entry[$field] = is_int($subVal) ? $subVal : (string) $subVal;
                }
                $projected[$currKeyStr] = $entry;
            } else {
                // Balance maps (outstanding_by_currency, balance_by_currency, currency_balances, operating_currency_totals):
                // exact decimal string or null only
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
