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
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class PurchasePriceHistoryReportQuery
{
    public const string REPORT_TYPE = 'purchases.price_history';

    public const array SUPPORTED_FILTERS = [
        'period',
        'vendor_id',
        'product_id',
        'currency_code',
        'sort',
        'page',
        'per_page',
    ];

    public const array ALLOWED_SORTS = [
        'date_desc',
        'cost_desc',
        'cost_asc',
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
            self::SUPPORTED_FILTERS,
            allowedSorts: self::ALLOWED_SORTS
        );

        $query = DB::table('purchase_lines as pl')
            ->join('purchases as p', 'p.id', '=', 'pl.purchase_id')
            ->join('vendors as v', 'v.id', '=', 'p.vendor_id')
            ->where('pl.company_id', $company->id)
            ->where('p.company_id', $company->id)
            ->where('p.status', 'posted')->whereNotNull('p.posting_batch_id')
            ->whereBetween('p.purchase_date', [$validatedFilters->period->startDate, $validatedFilters->period->endDate])
            ->select([
                'pl.id as line_id',
                'p.id as purchase_id',
                'p.purchase_number',
                'p.purchase_date',
                'p.vendor_id',
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_ar')), ''), JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_en'))) as vendor_name_ar"),
                DB::raw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_en')), ''), JSON_UNQUOTE(JSON_EXTRACT(p.vendor_snapshot, '$.name_ar'))) as vendor_name_en"),
                'pl.product_id',
                'pl.item_description',
                'pl.product_sku',
                'pl.product_name_ar',
                'pl.product_name_en',
                'pl.unit_name_ar',
                'pl.unit_name_en',
                'pl.quantity',
                'pl.quantity_base',
                'pl.line_tax_base',
                'pl.purchase_tax_account_id',
                'pl.unit_cost',
                'p.currency_code',
                'p.exchange_rate',
                'pl.line_total',
                'pl.line_total_base as commercial_line_total_base',
                DB::raw('COALESCE(pl.landed_cost_allocated_base, 0.000000) as landed_cost_allocated_base'),
                'pl.inventory_unit_cost_base',
            ]);

        if ($validatedFilters->vendorId !== null) {
            $query->where('p.vendor_id', $validatedFilters->vendorId);
        }

        if ($validatedFilters->productId !== null) {
            $query->where('pl.product_id', $validatedFilters->productId);
        }

        if ($validatedFilters->currencyCode !== null) {
            $query->where('p.currency_code', $validatedFilters->currencyCode);
        }

        $totalCount = (clone $query)->count();

        $sort = $validatedFilters->sort ?? 'date_desc';
        match ($sort) {
            'cost_desc' => $query->orderBy('pl.unit_cost', 'desc'),
            'cost_asc' => $query->orderBy('pl.unit_cost', 'asc'),
            default => $query->orderBy('p.purchase_date', 'desc')->orderBy('pl.id', 'desc'),
        };

        $paged = $query
            ->orderBy('pl.id')->forPage($validatedFilters->page, $validatedFilters->perPage)
            ->get();

        $rows = [];
        foreach ($paged as $row) {
            $commBase = BigDecimal::of((string) $row->commercial_line_total_base);
            $landedBase = BigDecimal::of((string) $row->landed_cost_allocated_base);
            $acquisitionCommercial = $commBase->minus($row->purchase_tax_account_id === null ? '0' : (string) $row->line_tax_base);
            $totalAcqBase = $acquisitionCommercial->plus($landedBase);
            $normalized = $commBase->minus((string) $row->line_tax_base)->dividedBy((string) $row->quantity_base, 6, RoundingMode::HALF_UP);

            $rows[] = [
                'line_id' => (int) $row->line_id,
                'purchase_id' => (int) $row->purchase_id,
                'purchase_number' => (string) $row->purchase_number,
                'purchase_date' => (string) $row->purchase_date,
                'vendor_id' => (int) $row->vendor_id,
                'vendor_name_ar' => (string) $row->vendor_name_ar,
                'vendor_name_en' => (string) ($row->vendor_name_en ?? ''),
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'item_description' => (string) ($row->item_description ?? ''),
                'product_sku' => $row->product_sku !== null ? (string) $row->product_sku : null,
                'product_name_ar' => $row->product_name_ar !== null ? (string) $row->product_name_ar : null,
                'product_name_en' => $row->product_name_en !== null ? (string) $row->product_name_en : null,
                'unit_name_ar' => $row->unit_name_ar !== null ? (string) $row->unit_name_ar : null,
                'quantity' => (string) $row->quantity,
                'commercial_unit_price' => (string) $row->unit_cost,
                'currency_code' => (string) $row->currency_code,
                'exchange_rate' => (string) $row->exchange_rate,
                'commercial_line_total' => (string) $row->line_total,
                'commercial_line_total_base' => (string) $commBase->toScale(6),
                'net_commercial_price_per_base_unit' => (string) $normalized,
                'landed_cost_allocated_base' => (string) $landedBase->toScale(6),
                'total_acquisition_cost_base' => (string) $totalAcqBase->toScale(6),
                'inventory_unit_cost_base' => $row->inventory_unit_cost_base !== null
                    ? (string) BigDecimal::of((string) $row->inventory_unit_cost_base)->toScale(6)
                    : null,
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
                'rule' => 'Vendor commercial price strictly separate from Landed Cost',
                'sort' => $sort,
            ]
        );
    }
}
