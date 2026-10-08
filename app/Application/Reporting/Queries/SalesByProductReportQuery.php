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

final class SalesByProductReportQuery
{
    public const string REPORT_TYPE = 'sales.by_product';

    public const array SUPPORTED_FILTERS = [
        'period',
        'product_id',
        'category_id',
        'warehouse_id',
        'currency_code',
        'sort',
        'page',
        'per_page',
    ];

    public const array ALLOWED_SORTS = [
        'quantity_desc',
        'revenue_desc',
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

        $productAggQuery = DB::query()->fromSub($linesQuery, 'l')
            ->selectRaw("
                l.product_id,
                l.item_description,
                l.product_sku,
                l.product_name_ar,
                l.product_name_en,
                l.unit_name_ar,
                l.unit_name_en,
                COALESCE(SUM(l.quantity_base), 0) as quantity_base,
                COALESCE(SUM(l.line_revenue_base), 0) as sales_revenue_base
                {$costSelect}
            ")
            ->groupBy(
                'l.product_id',
                'l.item_description',
                'l.product_sku',
                'l.product_name_ar',
                'l.product_name_en',
                'l.unit_name_ar',
                'l.unit_name_en'
            );

        $sort = $validatedFilters->sort ?? 'revenue_desc';
        match ($sort) {
            'quantity_desc' => $productAggQuery->orderBy('quantity_base', 'desc'),
            'profit_desc' => $productAggQuery->orderBy(DB::raw('(sales_revenue_base - cogs_base)'), 'desc'),
            'name_asc' => $productAggQuery->orderBy(DB::raw('COALESCE(l.product_name_ar, l.item_description)'), 'asc'),
            default => $productAggQuery->orderBy('sales_revenue_base', 'desc'),
        };

        // Totals
        $totalsSelect = '
            COUNT(*) as product_count,
            COALESCE(SUM(quantity_base), 0) as quantity_base,
            COALESCE(SUM(sales_revenue_base), 0) as sales_revenue_base
        '.($hasCostAuthority ? ', COALESCE(SUM(cogs_base), 0) as cogs_base' : '');

        $productAggQuery->orderBy('l.product_id')->orderBy('l.product_sku')->orderBy('l.product_name_ar')->orderBy('l.product_name_en')->orderBy('l.item_description')->orderBy('l.unit_name_ar')->orderBy('l.unit_name_en');

        $totalsRow = DB::query()->fromSub($productAggQuery, 'pa')
            ->selectRaw($totalsSelect)
            ->first();

        $totalCount = (int) ($totalsRow->product_count ?? 0);
        $totalQty = BigDecimal::of((string) ($totalsRow->quantity_base ?? '0'));
        $totalRev = BigDecimal::of((string) ($totalsRow->sales_revenue_base ?? '0'));

        $totals = [
            'product_count' => $totalCount,
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

        $pagedRows = $productAggQuery
            ->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($pagedRows as $row) {
            $qty = BigDecimal::of((string) $row->quantity_base);
            $rev = BigDecimal::of((string) $row->sales_revenue_base);

            $rowArr = [
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'item_description' => (string) ($row->item_description ?? ''),
                'product_sku' => $row->product_sku !== null ? (string) $row->product_sku : null,
                'product_name_ar' => $row->product_name_ar !== null ? (string) $row->product_name_ar : null,
                'product_name_en' => $row->product_name_en !== null ? (string) $row->product_name_en : null,
                'unit_name_ar' => $row->unit_name_ar !== null ? (string) $row->unit_name_ar : null,
                'unit_name_en' => $row->unit_name_en !== null ? (string) $row->unit_name_en : null,
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
                'sort' => $sort,
            ]
        );
    }
}
