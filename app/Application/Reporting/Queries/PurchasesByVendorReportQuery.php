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

final class PurchasesByVendorReportQuery
{
    public const string REPORT_TYPE = 'purchases.by_vendor';

    public const array SUPPORTED_FILTERS = [
        'period',
        'vendor_id',
        'currency_code',
        'sort',
        'page',
        'per_page',
    ];

    public const array ALLOWED_SORTS = [
        'purchases_desc',
        'net_purchases_desc',
        'name_asc',
        'count_desc',
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
            'vendors.view',
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::purchases($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS,
            allowedSorts: self::ALLOWED_SORTS
        );

        $activityQuery = TradeEventActivity::purchaseDocumentActivityQuery($company, $validatedFilters);

        $vendorAggQuery = DB::query()->fromSub($activityQuery, 'e')
            ->joinSub(DB::query()->fromSub(clone $activityQuery, 'snap')->selectRaw('snap.*, ROW_NUMBER() OVER (PARTITION BY vendor_id ORDER BY business_date DESC, document_id DESC, event_type DESC) as snapshot_rank'), 'identity', fn ($join) => $join->on('identity.vendor_id', '=', 'e.vendor_id')->where('identity.snapshot_rank', '=', 1))
            ->selectRaw('
                e.vendor_id,
                identity.vendor_name_ar,
                identity.vendor_name_en,
                NULL as vendor_code,
                COALESCE(SUM(e.commercial_purchases_base), 0) as commercial_purchases_base,
                COALESCE(SUM(e.returns_base), 0) as returns_base,
                COALESCE(SUM(e.commercial_purchases_base - e.returns_base), 0) as net_purchases_base,
                COALESCE(SUM(e.is_issued_purchase), 0) as purchase_count
            ')
            ->groupBy('e.vendor_id', 'identity.vendor_name_ar', 'identity.vendor_name_en');

        $sort = $validatedFilters->sort ?? 'purchases_desc';
        match ($sort) {
            'net_purchases_desc' => $vendorAggQuery->orderBy('net_purchases_base', 'desc'),
            'name_asc' => $vendorAggQuery->orderBy('identity.vendor_name_ar', 'asc'),
            'count_desc' => $vendorAggQuery->orderBy('purchase_count', 'desc'),
            default => $vendorAggQuery->orderBy('commercial_purchases_base', 'desc'),
        };

        $vendorAggQuery->orderBy('e.vendor_id');

        $totalsRow = DB::query()->fromSub($vendorAggQuery, 'va')
            ->selectRaw('
                COUNT(*) as vendor_count,
                COALESCE(SUM(commercial_purchases_base), 0) as commercial_purchases_base,
                COALESCE(SUM(returns_base), 0) as returns_base,
                COALESCE(SUM(net_purchases_base), 0) as net_purchases_base,
                COALESCE(SUM(purchase_count), 0) as purchase_count
            ')->first();

        $totalVendors = (int) ($totalsRow->vendor_count ?? 0);
        $totalPurchases = BigDecimal::of((string) ($totalsRow->commercial_purchases_base ?? '0'));
        $totalReturns = BigDecimal::of((string) ($totalsRow->returns_base ?? '0'));
        $totalNet = BigDecimal::of((string) ($totalsRow->net_purchases_base ?? '0'));
        $totalCount = (int) ($totalsRow->purchase_count ?? 0);

        $totals = [
            'vendor_count' => $totalVendors,
            'commercial_purchases_base' => (string) $totalPurchases->toScale(6),
            'returns_base' => (string) $totalReturns->toScale(6),
            'net_purchases_base' => (string) $totalNet->toScale(6),
            'purchase_count' => $totalCount,
        ];

        $pagedRows = $vendorAggQuery
            ->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($pagedRows as $row) {
            $rows[] = [
                'vendor_id' => (int) $row->vendor_id,
                'vendor_name_ar' => (string) $row->vendor_name_ar,
                'vendor_name_en' => (string) ($row->vendor_name_en ?? ''),
                'vendor_code' => (string) ($row->vendor_code ?? ''),
                'purchase_count' => (int) $row->purchase_count,
                'commercial_purchases_base' => (string) BigDecimal::of((string) $row->commercial_purchases_base)->toScale(6),
                'returns_base' => (string) BigDecimal::of((string) $row->returns_base)->toScale(6),
                'net_purchases_base' => (string) BigDecimal::of((string) $row->net_purchases_base)->toScale(6),
            ];
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $totalVendors,
            meta: [
                'sort' => $sort,
            ]
        );
    }
}
