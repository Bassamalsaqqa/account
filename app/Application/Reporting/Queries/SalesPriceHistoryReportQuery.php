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
use Illuminate\Support\Facades\DB;

final class SalesPriceHistoryReportQuery
{
    public const string REPORT_TYPE = 'sales.price_history';

    public const array SUPPORTED_FILTERS = [
        'period',
        'customer_id',
        'product_id',
        'currency_code',
        'sort',
        'page',
        'per_page',
    ];

    public const array ALLOWED_SORTS = [
        'date_desc',
        'price_desc',
        'price_asc',
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
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS,
            allowedSorts: self::ALLOWED_SORTS
        );

        $query = DB::table('sales_invoice_lines as sil')
            ->join('sales_invoices as si', 'si.id', '=', 'sil.sales_invoice_id')
            ->join('customers as c', 'c.id', '=', 'si.customer_id')
            ->where('sil.company_id', $company->id)
            ->where('si.company_id', $company->id)
            ->whereIn('si.status', ['posted', 'void'])->whereNotNull('si.posting_batch_id')
            ->whereBetween('si.issue_date', [$validatedFilters->period->startDate, $validatedFilters->period->endDate])
            ->select([
                'sil.id as line_id',
                'si.id as invoice_id',
                'si.invoice_number',
                'si.issue_date',
                'si.customer_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_ar')), ''), JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_en'))) as customer_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_en')), ''), JSON_UNQUOTE(JSON_EXTRACT(si.customer_snapshot, '$.name_ar'))) as customer_name_en"),
                'sil.product_id',
                'sil.item_description',
                'sil.product_sku',
                'sil.product_name_ar',
                'sil.product_name_en',
                'sil.unit_name_ar',
                'sil.unit_name_en',
                'sil.quantity',
                'sil.quantity_base',
                'sil.unit_price',
                'si.currency_code',
                'si.exchange_rate',
                'sil.line_total',
                'sil.line_total_base',
            ]);

        if ($validatedFilters->customerId !== null) {
            $query->where('si.customer_id', $validatedFilters->customerId);
        }

        if ($validatedFilters->productId !== null) {
            $query->where('sil.product_id', $validatedFilters->productId);
        }

        if ($validatedFilters->currencyCode !== null) {
            $query->where('si.currency_code', $validatedFilters->currencyCode);
        }

        $totalCount = (clone $query)->count();

        $sort = $validatedFilters->sort ?? 'date_desc';
        match ($sort) {
            'price_desc' => $query->orderBy('sil.unit_price', 'desc'),
            'price_asc' => $query->orderBy('sil.unit_price', 'asc'),
            default => $query->orderBy('si.issue_date', 'desc')->orderBy('sil.id', 'desc'),
        };

        $paged = $query
            ->orderBy('sil.id')->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($paged as $row) {
            $rows[] = [
                'line_id' => (int) $row->line_id,
                'invoice_id' => (int) $row->invoice_id,
                'invoice_number' => (string) $row->invoice_number,
                'issue_date' => (string) $row->issue_date,
                'customer_id' => (int) $row->customer_id,
                'customer_name_ar' => (string) $row->customer_name_ar,
                'customer_name_en' => (string) ($row->customer_name_en ?? ''),
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'item_description' => (string) ($row->item_description ?? ''),
                'product_sku' => $row->product_sku !== null ? (string) $row->product_sku : null,
                'product_name_ar' => $row->product_name_ar !== null ? (string) $row->product_name_ar : null,
                'product_name_en' => $row->product_name_en !== null ? (string) $row->product_name_en : null,
                'unit_name_ar' => $row->unit_name_ar !== null ? (string) $row->unit_name_ar : null,
                'quantity' => (string) $row->quantity,
                'unit_price' => (string) $row->unit_price,
                'currency_code' => (string) $row->currency_code,
                'exchange_rate' => (string) $row->exchange_rate,
                'line_total' => (string) $row->line_total,
                'line_total_base' => (string) $row->line_total_base,
            ];
        }

        $totals = [
            'total_price_records' => $totalCount,
        ];

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $totalCount,
            meta: [
                'snapshot_source' => 'persisted_sales_invoice_lines',
                'sort' => $sort,
            ]
        );
    }
}
