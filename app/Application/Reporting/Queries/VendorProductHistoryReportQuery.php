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

        $productAggQuery = DB::query()->fromSub($linesQuery, 'l')
            ->selectRaw('
                l.product_id,
                l.product_sku,
                l.product_name_ar,
                l.product_name_en,
                l.item_description,
                l.unit_name_ar,
                l.unit_name_en,
                COALESCE(SUM(l.quantity_base), 0) as total_quantity_base,
                COALESCE(SUM(l.commercial_line_total_base), 0) as total_commercial_base,
                MAX(l.business_date) as last_purchased_date
            ')
            ->groupBy(
                'l.product_id',
                'l.product_sku',
                'l.product_name_ar',
                'l.product_name_en',
                'l.item_description',
                'l.unit_name_ar',
                'l.unit_name_en'
            )
            ->orderBy('total_commercial_base', 'desc');

        $totalProducts = DB::query()->fromSub($productAggQuery, 'pa')->count();

        $productAggQuery->orderBy('l.product_id')->orderBy('l.product_sku')->orderBy('l.product_name_ar')->orderBy('l.product_name_en')->orderBy('l.item_description')->orderBy('l.unit_name_ar')->orderBy('l.unit_name_en');

        $totalsRow = DB::query()->fromSub($productAggQuery, 'pa')
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
                'total_quantity_base' => (string) BigDecimal::of((string) $row->total_quantity_base)->toScale(6),
                'total_commercial_base' => (string) BigDecimal::of((string) $row->total_commercial_base)->toScale(6),
                'last_purchased_date' => (string) $row->last_purchased_date,
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
