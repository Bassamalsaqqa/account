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

final class CustomerTopReportQuery
{
    public const string REPORT_TYPE = 'customers.top';

    public const array SUPPORTED_FILTERS = [
        'period',
        'currency_code',
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
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS
        );

        $activityQuery = TradeEventActivity::salesDocumentActivityQuery($company, $validatedFilters);

        $customerAggQuery = DB::query()->fromSub($activityQuery, 'e')
            ->joinSub(DB::query()->fromSub(clone $activityQuery, 'snap')->selectRaw('snap.*, ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY business_date DESC, document_id DESC, event_type DESC) as snapshot_rank'), 'identity', fn ($join) => $join->on('identity.customer_id', '=', 'e.customer_id')->where('identity.snapshot_rank', '=', 1))
            ->selectRaw('
                e.customer_id,
                identity.customer_name_ar,
                identity.customer_name_en,
                NULL as customer_code,
                COALESCE(SUM(e.gross_sales_base), 0) as gross_sales_base,
                COALESCE(SUM(e.revenue_base), 0) as revenue_base,
                COALESCE(SUM(e.returns_base), 0) as returns_base,
                COALESCE(SUM(e.revenue_base), 0) as net_sales_base,
                COALESCE(SUM(e.is_issued_invoice), 0) as original_invoice_count
            ')
            ->groupBy('e.customer_id', 'identity.customer_name_ar', 'identity.customer_name_en')
            ->orderBy('net_sales_base', 'desc')
            ->orderBy('e.customer_id', 'asc');

        $totalCustomers = DB::query()->fromSub($customerAggQuery, 'ca')->count();

        $customerAggQuery->orderBy('e.customer_id');

        $totalsRow = DB::query()->fromSub($customerAggQuery, 'ca')
            ->selectRaw('
                COALESCE(SUM(gross_sales_base), 0) as gross_sales_base,
                COALESCE(SUM(revenue_base), 0) as revenue_base,
                COALESCE(SUM(returns_base), 0) as returns_base,
                COALESCE(SUM(net_sales_base), 0) as net_sales_base,
                COALESCE(SUM(original_invoice_count), 0) as original_invoice_count
            ')->first();

        $totals = [
            'total_ranked_customers' => $totalCustomers,
            'gross_sales_base' => (string) BigDecimal::of((string) ($totalsRow->gross_sales_base ?? '0'))->toScale(6),
            'revenue_base' => (string) BigDecimal::of((string) ($totalsRow->revenue_base ?? '0'))->toScale(6),
            'returns_base' => (string) BigDecimal::of((string) ($totalsRow->returns_base ?? '0'))->toScale(6),
            'net_sales_base' => (string) BigDecimal::of((string) ($totalsRow->net_sales_base ?? '0'))->toScale(6),
            'original_invoice_count' => (int) ($totalsRow->original_invoice_count ?? 0),
        ];

        $pagedRows = $customerAggQuery
            ->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        $rankOffset = ($validatedFilters->page - 1) * $validatedFilters->perPage;
        $i = 1;
        foreach ($pagedRows as $row) {
            $rows[] = [
                'rank' => $rankOffset + $i,
                'customer_id' => (int) $row->customer_id,
                'customer_code' => (string) ($row->customer_code ?? ''),
                'customer_name_ar' => (string) $row->customer_name_ar,
                'customer_name_en' => (string) ($row->customer_name_en ?? ''),
                'original_invoice_count' => (int) $row->original_invoice_count,
                'gross_sales_base' => (string) BigDecimal::of((string) $row->gross_sales_base)->toScale(6),
                'returns_base' => (string) BigDecimal::of((string) $row->returns_base)->toScale(6),
                'net_sales_base' => (string) BigDecimal::of((string) $row->net_sales_base)->toScale(6),
                'revenue_base' => (string) BigDecimal::of((string) $row->revenue_base)->toScale(6),
            ];
            $i++;
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $totalCustomers,
            meta: [
                'ranking_metric' => 'selected_period_net_sales_base',
                'ranking_scope' => 'period',
            ]
        );
    }
}
