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

final class SalesReturnsReportQuery
{
    public const string REPORT_TYPE = 'sales.returns';

    public const array SUPPORTED_FILTERS = [
        'period',
        'customer_id',
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
            'sales.return.view',
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS
        );

        $startDate = $validatedFilters->period->startDate;
        $endDate = $validatedFilters->period->endDate;

        // 1. Original posted returns
        $orig = DB::table('sales_returns as sr')
            ->leftJoin('sales_invoices as si', 'si.id', '=', 'sr.sales_invoice_id')
            ->join('customers as c', 'c.id', '=', 'sr.customer_id')
            ->where('sr.company_id', $company->id)
            ->whereIn('sr.status', ['posted', 'void'])->whereNotNull('sr.posting_batch_id')
            ->whereBetween('sr.issue_date', [$startDate, $endDate])
            ->select([
                'sr.id as return_id',
                'sr.return_number',
                'si.invoice_number',
                'sr.customer_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar')), ''), JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en'))) as customer_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en')), ''), JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar'))) as customer_name_en"),
                'sr.issue_date as business_date',
                'sr.currency_code',
                'sr.grand_total_currency',
                'sr.grand_total_base',
                'sr.reason',
                DB::raw("'return' as event_type"),
                DB::raw('1 as sign'),
            ]);

        // 2. Inverse returns
        $inv = DB::table('sales_returns as sr')
            ->join('posting_batches as vb', function ($join) use ($company): void {
                $join->on('vb.id', '=', 'sr.void_posting_batch_id')
                    ->where('vb.company_id', '=', $company->id);
            })
            ->leftJoin('sales_invoices as si', 'si.id', '=', 'sr.sales_invoice_id')
            ->join('customers as c', 'c.id', '=', 'sr.customer_id')
            ->where('sr.company_id', $company->id)
            ->whereNotNull('sr.void_posting_batch_id')
            ->whereBetween('vb.posting_date', [$startDate, $endDate])
            ->select([
                'sr.id as return_id',
                'sr.return_number',
                'si.invoice_number',
                'sr.customer_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar')), ''), JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en'))) as customer_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_en')), ''), JSON_UNQUOTE(JSON_EXTRACT(sr.customer_snapshot, '$.name_ar'))) as customer_name_en"),
                'vb.posting_date as business_date',
                'sr.currency_code',
                DB::raw('(-sr.grand_total_currency) as grand_total_currency'),
                DB::raw('(-sr.grand_total_base) as grand_total_base'),
                'sr.reason',
                DB::raw("'inverse_return' as event_type"),
                DB::raw('-1 as sign'),
            ]);

        if ($validatedFilters->customerId !== null) {
            $orig->where('sr.customer_id', $validatedFilters->customerId);
            $inv->where('sr.customer_id', $validatedFilters->customerId);
        }

        if ($validatedFilters->warehouseId !== null) {
            $orig->where('sr.warehouse_id', $validatedFilters->warehouseId);
            $inv->where('sr.warehouse_id', $validatedFilters->warehouseId);
        }

        if ($validatedFilters->currencyCode !== null) {
            $orig->where('sr.currency_code', $validatedFilters->currencyCode);
            $inv->where('sr.currency_code', $validatedFilters->currencyCode);
        }

        $union = $orig->unionAll($inv);
        $returnsQuery = DB::query()->fromSub($union, 'r');

        $totalsRow = (clone $returnsQuery)
            ->selectRaw('
                COUNT(*) as return_count,
                COALESCE(SUM(grand_total_base), 0) as total_returns_base
            ')->first();

        $returnCount = (int) ($totalsRow->return_count ?? 0);
        $totalReturnsBase = BigDecimal::of((string) ($totalsRow->total_returns_base ?? '0'));

        $currencyRows = (clone $returnsQuery)
            ->selectRaw('
                currency_code,
                COALESCE(SUM(grand_total_currency), 0) as total_currency,
                COUNT(*) as count
            ')
            ->groupBy('currency_code')
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

        $pagedRows = (clone $returnsQuery)
            ->orderBy('business_date', 'desc')
            ->orderBy('event_type')->orderBy('return_id', 'desc')
            ->orderBy('return_id')->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($pagedRows as $row) {
            $rows[] = [
                'return_id' => (int) $row->return_id,
                'return_number' => (string) $row->return_number,
                'invoice_number' => $row->invoice_number !== null ? (string) $row->invoice_number : null,
                'event_type' => (string) $row->event_type,
                'customer_id' => (int) $row->customer_id,
                'customer_name_ar' => (string) $row->customer_name_ar,
                'customer_name_en' => (string) ($row->customer_name_en ?? ''),
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
