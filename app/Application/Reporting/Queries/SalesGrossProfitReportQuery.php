<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
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

final class SalesGrossProfitReportQuery
{
    public const string REPORT_TYPE = 'sales.gross_profit';

    public const array SUPPORTED_FILTERS = [
        'period',
        'grouping',
        'customer_id',
        'product_id',
        'currency_code',
        'sort',
        'page',
        'per_page',
    ];

    public const array ALLOWED_GROUPINGS = [
        'by_invoice',
        'by_product',
    ];

    public const array ALLOWED_SORTS = [
        'profit_desc',
        'profit_asc',
        'revenue_desc',
        'margin_desc',
        'date_desc',
    ];

    public function __construct(
        protected ReportingGuard $guard
    ) {}

    /**
     * @param  ReportFilters|array<string, mixed>  $filters
     */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        // Strictly requires reports.profit.view, reports.cost.view, inventory.cost.view, reports.sales.view, sales.invoice.view
        $this->guard->authorize($company, $actor, [
            ReportPermissionCatalog::REPORTS_SALES_VIEW,
            'sales.invoice.view',
            ReportPermissionCatalog::REPORTS_PROFIT_VIEW,
            ReportPermissionCatalog::REPORTS_COST_VIEW,
            ReportPermissionCatalog::INVENTORY_COST_VIEW,
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS,
            allowedGroupings: self::ALLOWED_GROUPINGS,
            allowedSorts: self::ALLOWED_SORTS
        );

        $grouping = $validatedFilters->grouping ?? 'by_invoice';
        $linesQuery = TradeEventActivity::salesLineActivityQuery($company, $validatedFilters, true);

        if ($grouping === 'by_invoice') {
            $aggQuery = DB::query()->fromSub($linesQuery, 'l')

                ->selectRaw('
                    l.document_id,
                    l.event_type,
                    l.document_number,
                    l.business_date,
                    l.customer_id,
                    l.customer_name_ar,
                    l.customer_name_en,
                    COALESCE(SUM(l.line_revenue_base), 0) as revenue_base,
                    COALESCE(SUM(l.cogs_total_base), 0) as cogs_base,
                    COALESCE(SUM(l.line_revenue_base - l.cogs_total_base), 0) as gross_profit_base
                ')
                ->groupBy(
                    'l.document_id',
                    'l.event_type',
                    'l.document_number',
                    'l.business_date',
                    'l.customer_id',
                    'l.customer_name_ar',
                    'l.customer_name_en'
                );

            $sort = $validatedFilters->sort ?? 'profit_desc';
            match ($sort) {
                'profit_asc' => $aggQuery->orderBy('gross_profit_base', 'asc'),
                'revenue_desc' => $aggQuery->orderBy('revenue_base', 'desc'),
                'margin_desc' => $aggQuery->orderByRaw('gross_profit_base / NULLIF(revenue_base, 0) DESC'),
                'date_desc' => $aggQuery->orderBy('business_date', 'desc'),
                default => $aggQuery->orderBy('gross_profit_base', 'desc'),
            };

            $aggQuery->orderBy('l.event_type')->orderBy('l.document_id');
            $totalsRow = DB::query()->fromSub($aggQuery, 'iq')
                ->selectRaw('
                    COUNT(*) as record_count,
                    COALESCE(SUM(revenue_base), 0) as total_revenue,
                    COALESCE(SUM(cogs_base), 0) as total_cogs,
                    COALESCE(SUM(gross_profit_base), 0) as total_profit
                ')->first();

            $recordCount = (int) ($totalsRow->record_count ?? 0);
            $totalRev = BigDecimal::of((string) ($totalsRow->total_revenue ?? '0'));
            $totalCogs = BigDecimal::of((string) ($totalsRow->total_cogs ?? '0'));
            $totalProfit = BigDecimal::of((string) ($totalsRow->total_profit ?? '0'));

            $totals = [
                'record_count' => $recordCount,
                'total_revenue_base' => (string) $totalRev->toScale(6),
                'total_cogs_base' => (string) $totalCogs->toScale(6),
                'total_gross_profit_base' => (string) $totalProfit->toScale(6),
                'overall_gross_margin' => $totalRev->isPositive()
                    ? (string) $totalProfit->dividedBy($totalRev, 4, RoundingMode::HALF_UP)
                    : null,
            ];

            $pagedRows = $aggQuery
                ->forPage($validatedFilters->page, $validatedFilters->perPage)
                ->get();

            $rows = [];
            foreach ($pagedRows as $row) {
                $rev = BigDecimal::of((string) $row->revenue_base);
                $cogs = BigDecimal::of((string) $row->cogs_base);
                $profit = BigDecimal::of((string) $row->gross_profit_base);
                $margin = $rev->isPositive()
                    ? (string) $profit->dividedBy($rev, 4, RoundingMode::HALF_UP)
                    : null;

                $rows[] = [
                    'document_id' => (int) $row->document_id,
                    'event_type' => (string) $row->event_type,
                    'document_number' => (string) $row->document_number,
                    'business_date' => (string) $row->business_date,
                    'customer_id' => (int) $row->customer_id,
                    'customer_name_ar' => (string) $row->customer_name_ar,
                    'customer_name_en' => (string) ($row->customer_name_en ?? ''),
                    'revenue_base' => (string) $rev->toScale(6),
                    'cogs_base' => (string) $cogs->toScale(6),
                    'gross_profit_base' => (string) $profit->toScale(6),
                    'gross_margin' => $margin,
                ];
            }
        } else {
            if ($validatedFilters->sort === 'date_desc') {
                throw new InvalidReportFilterException('Date sort is unavailable for a product aggregate.');
            }
            // by_product
            $aggQuery = DB::query()->fromSub($linesQuery, 'l')
                ->selectRaw('
                    l.product_id,
                    l.product_sku,
                    l.product_name_ar,
                    l.product_name_en,
                    l.item_description,
                    COALESCE(SUM(l.quantity_base), 0) as quantity_base,
                    COALESCE(SUM(l.line_revenue_base), 0) as revenue_base,
                    COALESCE(SUM(l.cogs_total_base), 0) as cogs_base,
                    COALESCE(SUM(l.line_revenue_base - l.cogs_total_base), 0) as gross_profit_base
                ')
                ->groupBy(
                    'l.product_id',
                    'l.product_sku',
                    'l.product_name_ar',
                    'l.product_name_en',
                    'l.item_description'
                );

            $sort = $validatedFilters->sort ?? 'profit_desc';
            match ($sort) {
                'profit_asc' => $aggQuery->orderBy('gross_profit_base', 'asc'),
                'revenue_desc' => $aggQuery->orderBy('revenue_base', 'desc'),
                'margin_desc' => $aggQuery->orderByRaw('gross_profit_base / NULLIF(revenue_base, 0) DESC'),
                default => $aggQuery->orderBy('gross_profit_base', 'desc'),
            };

            $aggQuery->orderBy('l.product_id')->orderBy('l.product_sku')->orderBy('l.product_name_ar')->orderBy('l.item_description');
            $totalsRow = DB::query()->fromSub($aggQuery, 'pq')
                ->selectRaw('
                    COUNT(*) as record_count,
                    COALESCE(SUM(quantity_base), 0) as total_quantity,
                    COALESCE(SUM(revenue_base), 0) as total_revenue,
                    COALESCE(SUM(cogs_base), 0) as total_cogs,
                    COALESCE(SUM(gross_profit_base), 0) as total_profit
                ')->first();

            $recordCount = (int) ($totalsRow->record_count ?? 0);
            $totalQty = BigDecimal::of((string) ($totalsRow->total_quantity ?? '0'));
            $totalRev = BigDecimal::of((string) ($totalsRow->total_revenue ?? '0'));
            $totalCogs = BigDecimal::of((string) ($totalsRow->total_cogs ?? '0'));
            $totalProfit = BigDecimal::of((string) ($totalsRow->total_profit ?? '0'));

            $totals = [
                'record_count' => $recordCount,
                'total_quantity_base' => (string) $totalQty->toScale(6),
                'total_revenue_base' => (string) $totalRev->toScale(6),
                'total_cogs_base' => (string) $totalCogs->toScale(6),
                'total_gross_profit_base' => (string) $totalProfit->toScale(6),
                'overall_gross_margin' => $totalRev->isPositive()
                    ? (string) $totalProfit->dividedBy($totalRev, 4, RoundingMode::HALF_UP)
                    : null,
            ];

            $pagedRows = $aggQuery
                ->forPage($validatedFilters->page, $validatedFilters->perPage)
                ->get();

            $rows = [];
            foreach ($pagedRows as $row) {
                $rev = BigDecimal::of((string) $row->revenue_base);
                $cogs = BigDecimal::of((string) $row->cogs_base);
                $profit = BigDecimal::of((string) $row->gross_profit_base);
                $margin = $rev->isPositive()
                    ? (string) $profit->dividedBy($rev, 4, RoundingMode::HALF_UP)
                    : null;

                $rows[] = [
                    'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                    'product_sku' => $row->product_sku !== null ? (string) $row->product_sku : null,
                    'product_name_ar' => $row->product_name_ar !== null ? (string) $row->product_name_ar : null,
                    'product_name_en' => $row->product_name_en !== null ? (string) $row->product_name_en : null,
                    'item_description' => (string) ($row->item_description ?? ''),
                    'quantity_base' => (string) BigDecimal::of((string) $row->quantity_base)->toScale(6),
                    'revenue_base' => (string) $rev->toScale(6),
                    'cogs_base' => (string) $cogs->toScale(6),
                    'gross_profit_base' => (string) $profit->toScale(6),
                    'gross_margin' => $margin,
                ];
            }
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $recordCount,
            meta: [
                'grouping' => $grouping,
                'cogs_provenance' => 'historical_lines_cogs_total_base',
            ]
        );
    }
}
