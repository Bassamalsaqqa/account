<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Security\ReportPermissionCatalog;
use App\Application\Reporting\Support\ProductAggregationHelper;
use App\Application\Reporting\Support\TradeEventActivity;
use App\Application\Reporting\Support\TradeFilterValidator;
use App\Application\Reporting\Support\TradeProvenance;
use App\Application\Reporting\Support\TradeResultPresenter;
use App\Models\Company;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class PurchasesByProductReportQuery
{
    public const string REPORT_TYPE = 'purchases.by_product';

    public const array SUPPORTED_FILTERS = [
        'period',
        'product_id',
        'vendor_id',
        'warehouse_id',
        'currency_code',
        'sort',
        'page',
        'per_page',
    ];

    public const array ALLOWED_SORTS = [
        'amount_desc',
        'quantity_desc',
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
        $this->guard->authorize($company, $actor, [
            ReportPermissionCatalog::REPORTS_PURCHASES_VIEW,
            'purchasing.purchase.view',
            ReportPermissionCatalog::PURCHASING_COST_VIEW,
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::purchases($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS,
            allowedSorts: self::ALLOWED_SORTS
        );

        $linesQuery = TradeEventActivity::purchaseLineActivityQuery($company, $validatedFilters);

        $groupKey = ProductAggregationHelper::groupKeySql('l');
        $rankSql = ProductAggregationHelper::representativeRankSql('l');

        // D2: Unranked aggregation branch over lines query
        $aggQuery = DB::query()->fromSub($linesQuery, 'l')
            ->selectRaw("
                {$groupKey} as product_group_key,
                COALESCE(SUM(quantity_base), 0) as quantity_base,
                COALESCE(SUM(commercial_line_total_base), 0) as commercial_total_base,
                COALESCE(SUM(landed_cost_allocated_base), 0) as landed_cost_base,
                COALESCE(SUM(inventory_acquisition_base), 0) as inventory_acquisition_base
            ")
            ->groupBy(DB::raw($groupKey));

        // D2: Ranked representative identity branch
        $linesWithRank = DB::query()->fromSub($linesQuery, 'l')
            ->selectRaw("
                l.product_id,
                l.product_sku,
                l.product_name_ar,
                l.product_name_en,
                l.item_description,
                {$groupKey} as product_group_key,
                {$rankSql} as rep_rank
            ");

        $repQuery = DB::query()->fromSub($linesWithRank, 'rep_lines')
            ->where('rep_rank', 1)
            ->select([
                'product_group_key',
                'product_id',
                'product_sku',
                'product_name_ar',
                'product_name_en',
                'item_description',
            ]);

        // G3: Canonical base-unit join to Product and base Unit
        $productAggQuery = DB::query()->fromSub($repQuery, 'rep')
            ->joinSub($aggQuery, 'agg', 'agg.product_group_key', '=', 'rep.product_group_key')
            ->leftJoin('products as p', function ($join) use ($company): void {
                $join->on('p.id', '=', 'rep.product_id')
                    ->where('p.company_id', '=', $company->id);
            })
            ->leftJoin('units as bu', function ($join) use ($company): void {
                $join->on('bu.id', '=', 'p.base_unit_id')
                    ->where('bu.company_id', '=', $company->id);
            })
            ->select([
                'rep.product_group_key',
                'rep.product_id',
                'rep.product_sku',
                'rep.product_name_ar',
                'rep.product_name_en',
                'rep.item_description',
                'bu.name_ar as unit_name_ar',
                'bu.name_en as unit_name_en',
                'agg.quantity_base',
                'agg.commercial_total_base',
                'agg.landed_cost_base',
                'agg.inventory_acquisition_base',
            ]);

        $sort = $validatedFilters->sort ?? 'amount_desc';
        match ($sort) {
            'quantity_desc' => $productAggQuery->orderBy('agg.quantity_base', 'desc'),
            'name_asc' => $productAggQuery->orderBy(DB::raw('COALESCE(rep.product_name_ar, rep.item_description)'), 'asc'),
            default => $productAggQuery->orderBy('agg.commercial_total_base', 'desc'),
        };

        $productAggQuery
            ->orderBy('rep.product_id')
            ->orderBy('rep.product_sku')
            ->orderBy('rep.product_name_ar')
            ->orderBy('rep.product_name_en')
            ->orderBy('rep.item_description')
            ->orderBy('bu.name_ar')
            ->orderBy('bu.name_en')
            ->orderBy('rep.product_group_key', 'asc');

        $totalsRow = DB::query()->fromSub($aggQuery, 'pa')
            ->selectRaw('
                COUNT(*) as product_count,
                COALESCE(SUM(quantity_base), 0) as quantity_base,
                COALESCE(SUM(commercial_total_base), 0) as commercial_total_base,
                COALESCE(SUM(landed_cost_base), 0) as landed_cost_base,
                COALESCE(SUM(inventory_acquisition_base), 0) as inventory_acquisition_base
            ')->first();

        $prodCount = (int) ($totalsRow->product_count ?? 0);
        $totalQty = BigDecimal::of((string) ($totalsRow->quantity_base ?? '0'));
        $totalComm = BigDecimal::of((string) ($totalsRow->commercial_total_base ?? '0'));
        $totalLanded = BigDecimal::of((string) ($totalsRow->landed_cost_base ?? '0'));
        $totalAcquisition = BigDecimal::of((string) ($totalsRow->inventory_acquisition_base ?? '0'));

        $totals = [
            'product_count' => $prodCount,
            'quantity_base' => (string) $totalQty->toScale(6),
            'commercial_total_base' => (string) $totalComm->toScale(6),
            'landed_cost_allocated_base' => (string) $totalLanded->toScale(6),
            'total_acquisition_cost_base' => (string) $totalAcquisition->toScale(6),
        ];

        $pagedRows = $productAggQuery
            ->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($pagedRows as $row) {
            $comm = BigDecimal::of((string) $row->commercial_total_base);
            $landed = BigDecimal::of((string) $row->landed_cost_base);
            $acq = BigDecimal::of((string) $row->inventory_acquisition_base);

            $rows[] = [
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'product_sku' => $row->product_sku !== null ? (string) $row->product_sku : null,
                'product_name_ar' => $row->product_name_ar !== null ? (string) $row->product_name_ar : null,
                'product_name_en' => $row->product_name_en !== null ? (string) $row->product_name_en : null,
                'item_description' => (string) ($row->item_description ?? ''),
                'unit_name_ar' => $row->unit_name_ar !== null ? (string) $row->unit_name_ar : null,
                'unit_name_en' => $row->unit_name_en !== null ? (string) $row->unit_name_en : null,
                'quantity_base' => (string) BigDecimal::of((string) $row->quantity_base)->toScale(6),
                'commercial_total_base' => (string) $comm->toScale(6),
                'landed_cost_allocated_base' => (string) $landed->toScale(6),
                'total_acquisition_cost_base' => (string) $acq->toScale(6),
            ];
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $prodCount,
            meta: [
                'acquisition_breakdown' => 'historical inventory acquisition less actual return carrying value; vendor commercial amounts remain separate',
                'sort' => $sort,
            ]
        );
    }
}
