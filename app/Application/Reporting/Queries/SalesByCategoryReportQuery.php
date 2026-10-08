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

final class SalesByCategoryReportQuery
{
    public const string REPORT_TYPE = 'sales.by_category';

    public const array SUPPORTED_FILTERS = [
        'period',
        'category_id',
        'currency_code',
        'warehouse_id',
        'sort',
        'page',
        'per_page',
    ];

    public const array ALLOWED_SORTS = [
        'revenue_desc',
        'quantity_desc',
        'profit_desc',
        'name_asc',
    ];

    public function __construct(
        protected ReportingGuard $guard
    ) {}

    /**
     * @param  ReportFilters|array<string, mixed>  $filters
     */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $user = $this->guard->authorize($company, $actor, [
            ReportPermissionCatalog::REPORTS_SALES_VIEW,
            'sales.invoice.view',
        ]);
        $company = $this->guard->company($company, $actor);

        $hasCostAuthority = $this->guard->canViewCost($user)
            && $user->hasPermissionTo(ReportPermissionCatalog::REPORTS_PROFIT_VIEW)
            && $user->hasPermissionTo(ReportPermissionCatalog::INVENTORY_COST_VIEW);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS,
            allowedSorts: self::ALLOWED_SORTS
        );

        if ($validatedFilters->sort === 'profit_desc' && ! $hasCostAuthority) {
            throw InvalidReportFilterException::invalidValue('sort', 'profit_desc', 'Profit sorting requires cost view authority.');
        }

        $linesQuery = TradeEventActivity::salesLineActivityQuery($company, $validatedFilters, $hasCostAuthority);

        $costSelect = $hasCostAuthority
            ? ', COALESCE(SUM(l.cogs_total_base), 0) as cogs_base'
            : '';

        $categoryAggQuery = DB::query()->fromSub($linesQuery, 'l')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'l.category_id')
            ->selectRaw("
                l.category_id,
                COALESCE(pc.name_ar, 'غير مصنف') as category_name_ar,
                COALESCE(pc.name_en, 'Uncategorized') as category_name_en,
                COALESCE(SUM(l.quantity_base), 0) as quantity_base,
                COALESCE(SUM(l.line_revenue_base), 0) as sales_revenue_base
                {$costSelect}
            ")
            ->groupBy('l.category_id', 'pc.name_ar', 'pc.name_en');

        $sort = $validatedFilters->sort ?? 'revenue_desc';
        match ($sort) {
            'quantity_desc' => $categoryAggQuery->orderBy('quantity_base', 'desc'),
            'profit_desc' => $categoryAggQuery->orderBy(DB::raw('(sales_revenue_base - cogs_base)'), 'desc'),
            'name_asc' => $categoryAggQuery->orderBy('category_name_ar', 'asc'),
            default => $categoryAggQuery->orderBy('sales_revenue_base', 'desc'),
        };

        // Totals
        $totalsSelect = '
            COUNT(*) as category_count,
            COALESCE(SUM(quantity_base), 0) as quantity_base,
            COALESCE(SUM(sales_revenue_base), 0) as sales_revenue_base
        '.($hasCostAuthority ? ', COALESCE(SUM(cogs_base), 0) as cogs_base' : '');

        $categoryAggQuery->orderBy('l.category_id');

        $totalsRow = DB::query()->fromSub($categoryAggQuery, 'ca')
            ->selectRaw($totalsSelect)
            ->first();

        $totalCount = (int) ($totalsRow->category_count ?? 0);
        $totalQty = BigDecimal::of((string) ($totalsRow->quantity_base ?? '0'));
        $totalRev = BigDecimal::of((string) ($totalsRow->sales_revenue_base ?? '0'));

        $totals = [
            'category_count' => $totalCount,
            'quantity_base' => (string) $totalQty->toScale(6),
            'sales_revenue_base' => (string) $totalRev->toScale(6),
        ];

        if ($hasCostAuthority) {
            $totalCogs = BigDecimal::of((string) ($totalsRow->cogs_base ?? '0'));
            $totalProfit = $totalRev->minus($totalCogs);
            $totals['cogs_base'] = (string) $totalCogs->toScale(6);
            $totals['gross_profit_base'] = (string) $totalProfit->toScale(6);
            $totals['gross_margin'] = $totalRev->isPositive()
                ? (string) $totalProfit->dividedBy($totalRev, 4, RoundingMode::HALF_UP)
                : null;
        }

        $pagedRows = $categoryAggQuery
            ->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($pagedRows as $row) {
            $qty = BigDecimal::of((string) $row->quantity_base);
            $rev = BigDecimal::of((string) $row->sales_revenue_base);

            $rowArr = [
                'category_id' => $row->category_id !== null ? (int) $row->category_id : null,
                'category_name_ar' => (string) $row->category_name_ar,
                'category_name_en' => (string) $row->category_name_en,
                'quantity_base' => (string) $qty->toScale(6),
                'sales_revenue_base' => (string) $rev->toScale(6),
            ];

            if ($hasCostAuthority) {
                $cogs = BigDecimal::of((string) $row->cogs_base);
                $profit = $rev->minus($cogs);
                $margin = $rev->isPositive()
                    ? (string) $profit->dividedBy($rev, 4, RoundingMode::HALF_UP)
                    : null;

                $rowArr['cogs_base'] = (string) $cogs->toScale(6);
                $rowArr['gross_profit_base'] = (string) $profit->toScale(6);
                $rowArr['gross_margin'] = $margin;
            }

            $rows[] = $rowArr;
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $totalCount,
            meta: [
                'has_cost_authority' => $hasCostAuthority,
                'category_classification_policy' => 'current_product_category_master',
                'sort' => $sort,
            ]
        );
    }
}
