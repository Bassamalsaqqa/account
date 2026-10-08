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

final class CustomerBuyingHistoryReportQuery
{
    public const string REPORT_TYPE = 'customers.buying_history';

    public const array SUPPORTED_FILTERS = [
        'customer_id',
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
            ReportPermissionCatalog::REPORTS_SALES_VIEW,
            'customers.view',
            'sales.invoice.view',
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS
        );

        $activityQuery = TradeEventActivity::salesDocumentActivityQuery($company, $validatedFilters);

        $query = DB::query()->fromSub($activityQuery, 'e')
            ->join('customers as c', 'c.id', '=', 'e.customer_id')
            ->select([
                'e.document_id',
                'e.document_number',
                'e.event_type',
                'e.business_date',
                'e.customer_id',
                'e.customer_name_ar',
                'e.customer_name_en',
                'e.currency_code',
                'e.exchange_rate',
                'e.gross_sales_currency',
                'e.gross_sales_base',
                'e.returns_currency',
                'e.returns_base',
                'e.revenue_base',
            ])
            ->orderBy('e.business_date', 'desc')
            ->orderBy('e.event_type')->orderBy('e.document_id', 'desc');

        $totalRecords = (clone $query)->count();

        $totalsRow = (clone $activityQuery)
            ->selectRaw('
                COALESCE(SUM(gross_sales_base), 0) as total_gross_base,
                COALESCE(SUM(returns_base), 0) as total_returns_base,
                COALESCE(SUM(revenue_base), 0) as total_revenue_base
            ')->first();

        $grossBase = BigDecimal::of((string) ($totalsRow->total_gross_base ?? '0'));
        $returnsBase = BigDecimal::of((string) ($totalsRow->total_returns_base ?? '0'));
        $revenueBase = BigDecimal::of((string) ($totalsRow->total_revenue_base ?? '0'));

        $totals = [
            'transaction_count' => $totalRecords,
            'gross_sales_base' => (string) $grossBase->toScale(6),
            'returns_base' => (string) $returnsBase->toScale(6),
            'commercial_net_sales_base' => (string) $grossBase->minus($returnsBase)->toScale(6),
            'net_sales_base' => (string) $revenueBase->toScale(6),
            'revenue_base' => (string) $revenueBase->toScale(6),
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
                'customer_id' => (int) $row->customer_id,
                'customer_name_ar' => (string) $row->customer_name_ar,
                'customer_name_en' => (string) ($row->customer_name_en ?? ''),
                'currency_code' => (string) $row->currency_code,
                'exchange_rate' => (string) $row->exchange_rate,
                'gross_sales_currency' => (string) BigDecimal::of((string) $row->gross_sales_currency)->toScale(6),
                'gross_sales_base' => (string) BigDecimal::of((string) $row->gross_sales_base)->toScale(6),
                'returns_currency' => (string) BigDecimal::of((string) $row->returns_currency)->toScale(6),
                'returns_base' => (string) BigDecimal::of((string) $row->returns_base)->toScale(6),
                'revenue_base' => (string) BigDecimal::of((string) $row->revenue_base)->toScale(6),
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
