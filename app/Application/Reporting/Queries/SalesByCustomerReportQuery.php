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

final class SalesByCustomerReportQuery
{
    public const string REPORT_TYPE = 'sales.by_customer';

    public const array SUPPORTED_FILTERS = [
        'period',
        'customer_id',
        'currency_code',
        'sort',
        'page',
        'per_page',
    ];

    public const array ALLOWED_SORTS = [
        'net_sales_desc',
        'net_sales_asc',
        'gross_sales_desc',
        'name_asc',
        'invoice_count_desc',
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
            'sales.invoice.view',
            'customers.view',
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS,
            allowedSorts: self::ALLOWED_SORTS
        );

        $activityQuery = TradeEventActivity::salesDocumentActivityQuery($company, $validatedFilters);

        $customerAggQuery = DB::query()->fromSub($activityQuery, 'e')
            ->joinSub(DB::query()->fromSub(clone $activityQuery, 'snap')->selectRaw('snap.*, ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY business_date DESC, document_id DESC, event_type DESC) as snapshot_rank'), 'identity', fn ($join) => $join->on('identity.customer_id', '=', 'e.customer_id')->where('identity.snapshot_rank', '=', 1))
            ->leftJoin('customers as c', function ($join) use ($company): void {
                $join->on('c.id', '=', 'e.customer_id')
                    ->where('c.company_id', '=', $company->id);
            })
            ->selectRaw('
                e.customer_id,
                identity.customer_name_ar,
                identity.customer_name_en,
                c.code as customer_code,
                COALESCE(SUM(e.gross_sales_base), 0) as gross_sales_base,
                COALESCE(SUM(e.revenue_base), 0) as revenue_base,
                COALESCE(SUM(e.returns_base), 0) as returns_base,
                COALESCE(SUM(e.revenue_base), 0) as net_sales_base,
                COALESCE(SUM(e.is_issued_invoice), 0) as original_invoice_count
            ')
            ->groupBy('e.customer_id', 'identity.customer_name_ar', 'identity.customer_name_en', 'c.code');

        // Apply sort
        $sort = $validatedFilters->sort ?? 'net_sales_desc';
        match ($sort) {
            'net_sales_asc' => $customerAggQuery->orderBy('net_sales_base', 'asc'),
            'gross_sales_desc' => $customerAggQuery->orderBy('gross_sales_base', 'desc'),
            'name_asc' => $customerAggQuery->orderBy('identity.customer_name_ar', 'asc'),
            'invoice_count_desc' => $customerAggQuery->orderBy('original_invoice_count', 'desc'),
            default => $customerAggQuery->orderBy('net_sales_base', 'desc')->orderBy('e.customer_id', 'asc'),
        };

        // Totals across all customers
        $customerAggQuery->orderBy('e.customer_id');

        $totalsRow = DB::query()->fromSub($customerAggQuery, 'ca')
            ->selectRaw('
                COUNT(*) as customer_count,
                COALESCE(SUM(gross_sales_base), 0) as gross_sales_base,
                COALESCE(SUM(revenue_base), 0) as revenue_base,
                COALESCE(SUM(returns_base), 0) as returns_base,
                COALESCE(SUM(net_sales_base), 0) as net_sales_base,
                COALESCE(SUM(original_invoice_count), 0) as original_invoice_count
            ')->first();

        $grossSalesBase = BigDecimal::of((string) ($totalsRow->gross_sales_base ?? '0'));
        $revenueBase = BigDecimal::of((string) ($totalsRow->revenue_base ?? '0'));
        $returnsBase = BigDecimal::of((string) ($totalsRow->returns_base ?? '0'));
        $netSalesBase = BigDecimal::of((string) ($totalsRow->net_sales_base ?? '0'));
        $invoiceCount = (int) ($totalsRow->original_invoice_count ?? 0);
        $totalCustomers = (int) ($totalsRow->customer_count ?? 0);

        $totals = [
            'customer_count' => $totalCustomers,
            'gross_sales_base' => (string) $grossSalesBase->toScale(6),
            'revenue_base' => (string) $revenueBase->toScale(6),
            'returns_base' => (string) $returnsBase->toScale(6),
            'net_sales_base' => (string) $netSalesBase->toScale(6),
            'original_invoice_count' => $invoiceCount,
        ];

        $pagedRows = $customerAggQuery
            ->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($pagedRows as $row) {
            $rows[] = [
                'customer_id' => (int) $row->customer_id,
                'customer_name_ar' => (string) $row->customer_name_ar,
                'customer_name_en' => (string) ($row->customer_name_en ?? ''),
                'customer_code' => $row->customer_code !== null ? (string) $row->customer_code : null,
                'original_invoice_count' => (int) $row->original_invoice_count,
                'gross_sales_base' => (string) BigDecimal::of((string) $row->gross_sales_base)->toScale(6),
                'returns_base' => (string) BigDecimal::of((string) $row->returns_base)->toScale(6),
                'net_sales_base' => (string) BigDecimal::of((string) $row->net_sales_base)->toScale(6),
                'revenue_base' => (string) BigDecimal::of((string) $row->revenue_base)->toScale(6),
            ];
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $totalCustomers,
            meta: [
                'ranking_metric' => 'net_sales_base',
                'sort' => $sort,
            ]
        );
    }
}
