<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Security\ReportPermissionCatalog;
use App\Application\Reporting\Support\TradeEventActivity;
use App\Application\Reporting\Support\TradeFilterValidator;
use App\Application\Reporting\Support\TradeProvenance;
use App\Application\Reporting\Support\TradeResultPresenter;
use App\Models\Company;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class SalesSummaryReportQuery
{
    public const string REPORT_TYPE = 'sales.summary';

    public const array SUPPORTED_FILTERS = [
        'period',
        'currency_code',
        'customer_id',
        'warehouse_id',
        'grouping',
        'page',
        'per_page',
    ];

    public const array ALLOWED_GROUPINGS = [
        'daily',
        'weekly',
        'monthly',
    ];

    public function __construct(
        protected ReportingGuard $guard
    ) {}

    /**
     * @param  ReportFilters|array<string, mixed>  $filters
     */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $this->guard->authorize($company, $actor, [
            ReportPermissionCatalog::REPORTS_SALES_VIEW,
            'sales.invoice.view',
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS,
            self::ALLOWED_GROUPINGS
        );

        $activityQuery = TradeEventActivity::salesDocumentActivityQuery($company, $validatedFilters);

        // 1. Overall base totals
        $totalsRow = (clone $activityQuery)->selectRaw('
            COALESCE(SUM(gross_sales_base), 0) as gross_sales_base,
            COALESCE(SUM(revenue_base), 0) as revenue_base,
            COALESCE(SUM(discounts_base), 0) as discounts_base,
            COALESCE(SUM(returns_base), 0) as returns_base,
            COALESCE(SUM(tax_base), 0) as tax_base,
            COALESCE(SUM(is_issued_invoice), 0) as original_invoice_count
        ')->first();

        $grossSalesBase = BigDecimal::of((string) ($totalsRow->gross_sales_base ?? '0'));
        $revenueBase = BigDecimal::of((string) ($totalsRow->revenue_base ?? '0'));
        $discountsBase = BigDecimal::of((string) ($totalsRow->discounts_base ?? '0'));
        $returnsBase = BigDecimal::of((string) ($totalsRow->returns_base ?? '0'));
        $taxBase = BigDecimal::of((string) ($totalsRow->tax_base ?? '0'));
        $invoiceCount = (int) ($totalsRow->original_invoice_count ?? 0);

        $commercialNetSalesBase = $grossSalesBase->minus($returnsBase);
        $averageInvoiceBase = $invoiceCount > 0
            ? (string) $grossSalesBase->dividedBy($invoiceCount, 6, RoundingMode::HALF_UP)
            : '0.000000';

        // 2. Original currency grouped totals
        $currencyGroupRows = (clone $activityQuery)
            ->selectRaw('
                currency_code,
                COALESCE(SUM(gross_sales_currency), 0) as gross_sales,
                COALESCE(SUM(discounts_currency), 0) as discounts,
                COALESCE(SUM(returns_currency), 0) as returns,
                COALESCE(SUM(tax_currency), 0) as tax,
                COALESCE(SUM(is_issued_invoice), 0) as invoice_count
            ')
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get();

        $currencyTotals = [];
        foreach ($currencyGroupRows as $cg) {
            $cgGross = BigDecimal::of((string) $cg->gross_sales);
            $cgRet = BigDecimal::of((string) $cg->returns);
            $cgNet = $cgGross->minus($cgRet);

            $currencyTotals[(string) $cg->currency_code] = [
                'currency_code' => (string) $cg->currency_code,
                'gross_sales' => (string) $cgGross->toScale(6),
                'discounts' => (string) BigDecimal::of((string) $cg->discounts)->toScale(6),
                'returns' => (string) $cgRet->toScale(6),
                'commercial_net_sales' => (string) $cgNet->toScale(6),
                'net_sales' => (string) $cgNet->minus((string) $cg->tax)->toScale(6),
                'tax' => (string) BigDecimal::of((string) $cg->tax)->toScale(6),
                'invoice_count' => (int) $cg->invoice_count,
            ];
        }

        $totals = [
            'gross_sales_base' => (string) $grossSalesBase->toScale(6),
            'tax_inclusive_sales_base' => (string) $grossSalesBase->toScale(6),
            'commercial_net_sales_base' => (string) $commercialNetSalesBase->toScale(6),
            'revenue_base' => (string) $revenueBase->toScale(6),
            'discounts_base' => (string) $discountsBase->toScale(6),
            'returns_base' => (string) $returnsBase->toScale(6),
            'net_sales_base' => (string) $revenueBase->toScale(6),
            'tax_base' => (string) $taxBase->toScale(6),
            'original_invoice_count' => $invoiceCount,
            'average_invoice_base' => $averageInvoiceBase,
            'currencies' => $currencyTotals,
        ];

        // 3. Paginated detail rows (by grouping or event rows)
        $grouping = $validatedFilters->grouping;
        if ($grouping !== null) {
            $groupExpr = match ($grouping) {
                'weekly' => "DATE_FORMAT(business_date, '%x-W%v')",
                'monthly' => "DATE_FORMAT(business_date, '%Y-%m')",
                'daily' => 'business_date',
                default => 'business_date',
            };

            $groupedQuery = (clone $activityQuery)
                ->selectRaw("
                    {$groupExpr} as period_key,
                    COALESCE(SUM(gross_sales_base), 0) as gross_sales_base,
                    COALESCE(SUM(revenue_base), 0) as revenue_base,
                    COALESCE(SUM(discounts_base), 0) as discounts_base,
                    COALESCE(SUM(returns_base), 0) as returns_base,
                    COALESCE(SUM(tax_base), 0) as tax_base,
                    COALESCE(SUM(is_issued_invoice), 0) as invoice_count
                ")
                ->groupBy(DB::raw($groupExpr))
                ->orderBy(DB::raw($groupExpr), 'desc');

            $totalRecords = DB::query()->fromSub($groupedQuery, 'grps')->count();

            $pagedRows = $groupedQuery
                ->forPage($validatedFilters->page, $validatedFilters->perPage)
                ->get();

            $rows = [];
            foreach ($pagedRows as $row) {
                $rGross = BigDecimal::of((string) $row->gross_sales_base);
                $rRev = BigDecimal::of((string) $row->revenue_base);
                $rRet = BigDecimal::of((string) $row->returns_base);
                $rInvCount = (int) $row->invoice_count;

                $rows[] = [
                    'period_key' => (string) $row->period_key,
                    'gross_sales_base' => (string) $rGross->toScale(6),
                    'revenue_base' => (string) $rRev->toScale(6),
                    'discounts_base' => (string) BigDecimal::of((string) $row->discounts_base)->toScale(6),
                    'returns_base' => (string) $rRet->toScale(6),
                    'net_sales_base' => (string) $rRev->toScale(6),
                    'tax_base' => (string) BigDecimal::of((string) $row->tax_base)->toScale(6),
                    'original_invoice_count' => $rInvCount,
                    'average_invoice_base' => $rInvCount > 0
                        ? (string) $rGross->dividedBy($rInvCount, 6, RoundingMode::HALF_UP)
                        : '0.000000',
                ];
            }
        } else {
            // Document activity rows
            $detailQuery = (clone $activityQuery)
                ->orderBy('business_date', 'desc')
                ->orderBy('event_type', 'asc')
                ->orderBy('document_id', 'desc');

            $totalRecords = (clone $detailQuery)->count();

            $pagedRows = $detailQuery
                ->forPage($validatedFilters->page, $validatedFilters->perPage)
                ->get();

            $rows = [];
            foreach ($pagedRows as $row) {
                $rGross = BigDecimal::of((string) $row->gross_sales_base);
                $rRet = BigDecimal::of((string) $row->returns_base);

                $rows[] = [
                    'document_id' => (int) $row->document_id,
                    'document_number' => (string) $row->document_number,
                    'event_type' => (string) $row->event_type,
                    'business_date' => (string) $row->business_date,
                    'customer_id' => (int) $row->customer_id,
                    'currency_code' => (string) $row->currency_code,
                    'gross_sales_base' => (string) $rGross->toScale(6),
                    'revenue_base' => (string) BigDecimal::of((string) $row->revenue_base)->toScale(6),
                    'discounts_base' => (string) BigDecimal::of((string) $row->discounts_base)->toScale(6),
                    'returns_base' => (string) $rRet->toScale(6),
                    'net_sales_base' => (string) BigDecimal::of((string) $row->revenue_base)->toScale(6),
                    'tax_base' => (string) BigDecimal::of((string) $row->tax_base)->toScale(6),
                ];
            }
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $totalRecords,
            meta: [
                'revenue_definition' => 'Tax-exclusive commercial revenue = grand_total_base - tax_total_base',
                'grouping' => $grouping,
            ]
        );
    }
}
