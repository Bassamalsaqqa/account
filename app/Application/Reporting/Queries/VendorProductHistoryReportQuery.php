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

final class VendorProductHistoryReportQuery
{
    public const string REPORT_TYPE = 'vendors.product_history';

    public const array SUPPORTED_FILTERS = [
        'vendor_id',
        'product_id',
        'period',
        'warehouse_id',
        'page',
        'per_page',
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
            'vendors.view',
            'purchasing.purchase.view',
            ReportPermissionCatalog::PURCHASING_COST_VIEW,
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::purchases($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS
        );

        $linesQuery = TradeEventActivity::purchaseLineActivityQuery($company, $validatedFilters);

        $groupKey = ProductAggregationHelper::groupKeySql('l');
        $rankSql = ProductAggregationHelper::representativeRankSql('l');

        // D2: Unranked aggregation branch over lines query
        $aggQuery = DB::query()->fromSub($linesQuery, 'l')
            ->selectRaw('
                '.$groupKey.' as product_group_key,
                COALESCE(SUM(quantity_base), 0) as total_quantity_base,
                COALESCE(SUM(commercial_line_total_base), 0) as total_commercial_base,
                MAX(CASE WHEN event_type = \'purchase_line\' THEN business_date ELSE NULL END) as last_purchased_date
            ')
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
                'agg.total_quantity_base',
                'agg.total_commercial_base',
                'agg.last_purchased_date',
            ])
            ->orderBy('agg.total_commercial_base', 'desc')
            ->orderBy('rep.product_id')
            ->orderBy('rep.product_sku')
            ->orderBy('rep.product_name_ar')
            ->orderBy('rep.product_name_en')
            ->orderBy('rep.item_description')
            ->orderBy('bu.name_ar')
            ->orderBy('bu.name_en')
            ->orderBy('rep.product_group_key', 'asc');

        $totalProducts = DB::query()->fromSub($aggQuery, 'pa')->count();

        $totalsRow = DB::query()->fromSub($aggQuery, 'pa')
            ->selectRaw('
                COALESCE(SUM(total_quantity_base), 0) as total_quantity,
                COALESCE(SUM(total_commercial_base), 0) as total_commercial
            ')->first();

        $totals = [
            'product_count' => $totalProducts,
            'total_quantity_base' => (string) BigDecimal::of((string) ($totalsRow->total_quantity ?? '0'))->toScale(6),
            'total_commercial_base' => (string) BigDecimal::of((string) ($totalsRow->total_commercial ?? '0'))->toScale(6),
        ];

        $paged = $productAggQuery
            ->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($paged as $row) {
            $rows[] = [
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'product_sku' => $row->product_sku !== null ? (string) $row->product_sku : null,
                'product_name_ar' => $row->product_name_ar !== null ? (string) $row->product_name_ar : null,
                'product_name_en' => $row->product_name_en !== null ? (string) $row->product_name_en : null,
                'item_description' => (string) ($row->item_description ?? ''),
                'unit_name_ar' => $row->unit_name_ar !== null ? (string) $row->unit_name_ar : null,
                'unit_name_en' => $row->unit_name_en !== null ? (string) $row->unit_name_en : null,
                'total_quantity_base' => (string) BigDecimal::of((string) $row->total_quantity_base)->toScale(6),
                'total_commercial_base' => (string) BigDecimal::of((string) $row->total_commercial_base)->toScale(6),
                'last_purchased_date' => $row->last_purchased_date !== null ? (string) $row->last_purchased_date : null,
            ];
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $totalProducts
        );
    }
}
