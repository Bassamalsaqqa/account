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

final class SalesDiscountsReportQuery
{
    public const string REPORT_TYPE = 'sales.discounts';

    public const array SUPPORTED_FILTERS = [
        'period',
        'customer_id',
        'product_id',
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
            'sales.invoice.view',
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS
        );

        $linesQuery = TradeEventActivity::salesLineActivityQuery($company, $validatedFilters)
            ->where('line_discount_base', '!=', 0);

        $totalsRow = (clone $linesQuery)
            ->selectRaw('
                COUNT(*) as discount_lines_count,
                COALESCE(SUM(line_discount_base), 0) as total_discount_base,
                COALESCE(SUM(line_total_base), 0) as total_discounted_sales_base
            ')->first();

        $linesCount = (int) ($totalsRow->discount_lines_count ?? 0);
        $totalDisc = BigDecimal::of((string) ($totalsRow->total_discount_base ?? '0'));
        $totalSales = BigDecimal::of((string) ($totalsRow->total_discounted_sales_base ?? '0'));

        $totals = [
            'discount_lines_count' => $linesCount,
            'total_discount_base' => (string) $totalDisc->toScale(6),
            'total_discounted_sales_base' => (string) $totalSales->toScale(6),
        ];

        $pagedRows = (clone $linesQuery)
            ->orderBy('business_date', 'desc')
            ->orderBy('event_type')->orderBy('document_id', 'desc')->orderBy('line_id', 'desc')
            ->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($pagedRows as $row) {
            $rows[] = [
                'line_id' => (int) $row->line_id,
                'document_id' => (int) $row->document_id,
                'document_number' => (string) $row->document_number,
                'event_type' => (string) $row->event_type,
                'business_date' => (string) $row->business_date,
                'customer_id' => (int) $row->customer_id,
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'item_description' => (string) ($row->item_description ?? ''),
                'product_sku' => $row->product_sku !== null ? (string) $row->product_sku : null,
                'product_name_ar' => $row->product_name_ar !== null ? (string) $row->product_name_ar : null,
                'unit_name_ar' => $row->unit_name_ar !== null ? (string) $row->unit_name_ar : null,
                'quantity_base' => (string) BigDecimal::of((string) $row->quantity_base)->toScale(6),
                'line_total_base' => (string) BigDecimal::of((string) $row->line_total_base)->toScale(6),
                'line_discount_base' => (string) BigDecimal::of((string) $row->line_discount_base)->toScale(6),
            ];
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $linesCount
        );
    }
}
