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

final class VendorPurchaseHistoryReportQuery
{
    public const string REPORT_TYPE = 'vendors.purchase_history';

    public const array SUPPORTED_FILTERS = [
        'vendor_id',
        'period',
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
            'vendors.view',
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::purchases($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS
        );

        $activityQuery = TradeEventActivity::purchaseDocumentActivityQuery($company, $validatedFilters);

        $query = DB::query()->fromSub($activityQuery, 'e')
            ->join('vendors as v', 'v.id', '=', 'e.vendor_id')
            ->select([
                'e.document_id',
                'e.document_number',
                'e.event_type',
                'e.business_date',
                'e.vendor_id',
                'e.vendor_name_ar',
                'e.vendor_name_en',
                'e.currency_code',
                'e.exchange_rate',
                'e.commercial_purchases_currency',
                'e.commercial_purchases_base',
                'e.returns_currency',
                'e.returns_base',
            ])
            ->orderBy('e.business_date', 'desc')
            ->orderBy('e.event_type')->orderBy('e.document_id', 'desc');

        $totalRecords = (clone $query)->count();

        $totalsRow = (clone $activityQuery)
            ->selectRaw('
                COALESCE(SUM(commercial_purchases_base), 0) as total_purchases_base,
                COALESCE(SUM(returns_base), 0) as total_returns_base
            ')->first();

        $purchasesBase = BigDecimal::of((string) ($totalsRow->total_purchases_base ?? '0'));
        $returnsBase = BigDecimal::of((string) ($totalsRow->total_returns_base ?? '0'));

        $totals = [
            'transaction_count' => $totalRecords,
            'commercial_purchases_base' => (string) $purchasesBase->toScale(6),
            'returns_base' => (string) $returnsBase->toScale(6),
            'net_purchases_base' => (string) $purchasesBase->minus($returnsBase)->toScale(6),
        ];

        $paged = $query
            ->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($paged as $row) {
            $rows[] = [
                'document_id' => (int) $row->document_id,
                'document_number' => (string) $row->document_number,
                'event_type' => (string) $row->event_type,
                'business_date' => (string) $row->business_date,
                'vendor_id' => (int) $row->vendor_id,
                'vendor_name_ar' => (string) $row->vendor_name_ar,
                'vendor_name_en' => (string) ($row->vendor_name_en ?? ''),
                'currency_code' => (string) $row->currency_code,
                'exchange_rate' => (string) $row->exchange_rate,
                'commercial_purchases_currency' => (string) BigDecimal::of((string) $row->commercial_purchases_currency)->toScale(6),
                'commercial_purchases_base' => (string) BigDecimal::of((string) $row->commercial_purchases_base)->toScale(6),
                'returns_currency' => (string) BigDecimal::of((string) $row->returns_currency)->toScale(6),
                'returns_base' => (string) BigDecimal::of((string) $row->returns_base)->toScale(6),
            ];
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $totalRecords
        );
    }
}
