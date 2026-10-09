<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Security\ReportPermissionCatalog;
use App\Application\Reporting\Support\TradeFilterValidator;
use App\Application\Reporting\Support\TradeProvenance;
use App\Application\Reporting\Support\TradeResultPresenter;
use App\Models\Company;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class PurchaseReturnsReportQuery
{
    public const string REPORT_TYPE = 'purchases.returns';

    public const array SUPPORTED_FILTERS = [
        'period',
        'vendor_id',
        'currency_code',
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

        $startDate = $validatedFilters->period->startDate;
        $endDate = $validatedFilters->period->endDate;

        $query = DB::table('purchase_returns as pr')
            ->join('purchases as p', 'p.id', '=', 'pr.purchase_id')
            ->join('vendors as v', 'v.id', '=', 'pr.vendor_id')
            ->where('pr.company_id', $company->id)
            ->where('pr.status', 'posted')
            ->whereBetween('pr.return_date', [$startDate, $endDate]);

        if ($validatedFilters->vendorId !== null) {
            $query->where('pr.vendor_id', $validatedFilters->vendorId);
        }

        if ($validatedFilters->warehouseId !== null) {
            $query->where('pr.warehouse_id', $validatedFilters->warehouseId);
        }

        if ($validatedFilters->currencyCode !== null) {
            $query->where('pr.currency_code', $validatedFilters->currencyCode);
        }

        $totalsRow = (clone $query)
            ->selectRaw('
                COUNT(*) as return_count,
                COALESCE(SUM(pr.grand_total_base), 0) as total_returns_base
            ')->first();

        $returnCount = (int) ($totalsRow->return_count ?? 0);
        $totalReturnsBase = BigDecimal::of((string) ($totalsRow->total_returns_base ?? '0'));

        $currencyRows = (clone $query)
            ->selectRaw('
                pr.currency_code,
                COALESCE(SUM(pr.grand_total_currency), 0) as total_currency,
                COUNT(*) as count
            ')
            ->groupBy('pr.currency_code')
            ->get();

        $currencies = [];
        foreach ($currencyRows as $cr) {
            $currencies[(string) $cr->currency_code] = [
                'currency_code' => (string) $cr->currency_code,
                'total_currency' => (string) BigDecimal::of((string) $cr->total_currency)->toScale(6),
                'count' => (int) $cr->count,
            ];
        }

        $totals = [
            'return_count' => $returnCount,
            'total_returns_base' => (string) $totalReturnsBase->toScale(6),
            'currencies' => $currencies,
        ];

        $pagedRows = (clone $query)
            ->select([
                'pr.id as return_id',
                'pr.return_number',
                'p.purchase_number',
                'pr.vendor_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_ar')), ''), JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_en'))) as vendor_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_en')), ''), JSON_UNQUOTE(JSON_EXTRACT(pr.vendor_snapshot, '$.name_ar'))) as vendor_name_en"),
                'pr.return_date as business_date',
                'pr.currency_code',
                'pr.grand_total_currency',
                'pr.grand_total_base',
                'pr.reason',
            ])
            ->orderBy('pr.return_date', 'desc')
            ->orderBy('pr.id', 'desc')
            ->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($pagedRows as $row) {
            $rows[] = [
                'return_id' => (int) $row->return_id,
                'return_number' => (string) $row->return_number,
                'purchase_number' => (string) $row->purchase_number,
                'vendor_id' => (int) $row->vendor_id,
                'vendor_name_ar' => (string) $row->vendor_name_ar,
                'vendor_name_en' => (string) ($row->vendor_name_en ?? ''),
                'business_date' => (string) $row->business_date,
                'currency_code' => (string) $row->currency_code,
                'grand_total_currency' => (string) BigDecimal::of((string) $row->grand_total_currency)->toScale(6),
                'grand_total_base' => (string) BigDecimal::of((string) $row->grand_total_base)->toScale(6),
                'reason' => $row->reason !== null ? (string) $row->reason : null,
            ];
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $returnCount
        );
    }
}
