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
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class PurchaseSummaryReportQuery
{
    public const string REPORT_TYPE = 'purchases.summary';

    public const array SUPPORTED_FILTERS = [
        'period',
        'vendor_id',
        'currency_code',
        'warehouse_id',
        'grouping',
        'page',
        'per_page',
    ];

    public const array ALLOWED_GROUPINGS = [
        'daily',
        'weekly',
        'monthly',
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
            self::ALLOWED_GROUPINGS
        );

        $activityQuery = TradeEventActivity::purchaseDocumentActivityQuery($company, $validatedFilters);

        $totalsRow = (clone $activityQuery)->selectRaw('
            COALESCE(SUM(commercial_purchases_base), 0) as commercial_purchases_base,
            COALESCE(SUM(discounts_base), 0) as discounts_base,
            COALESCE(SUM(returns_base), 0) as returns_base,
            COALESCE(SUM(tax_base), 0) as tax_base,
            COALESCE(SUM(is_issued_purchase), 0) as purchase_count
        ')->first();

        $purchasesBase = BigDecimal::of((string) ($totalsRow->commercial_purchases_base ?? '0'));
        $discountsBase = BigDecimal::of((string) ($totalsRow->discounts_base ?? '0'));
        $returnsBase = BigDecimal::of((string) ($totalsRow->returns_base ?? '0'));
        $taxBase = BigDecimal::of((string) ($totalsRow->tax_base ?? '0'));
        $purchaseCount = (int) ($totalsRow->purchase_count ?? 0);
        $netPurchasesBase = $purchasesBase->minus($returnsBase);

        $averagePurchaseBase = $purchaseCount > 0
            ? (string) $purchasesBase->dividedBy($purchaseCount, 6, RoundingMode::HALF_UP)
            : '0.000000';

        // Original currency grouped totals
        $currencyGroupRows = (clone $activityQuery)
            ->selectRaw('
                currency_code,
                COALESCE(SUM(commercial_purchases_currency), 0) as purchases,
                COALESCE(SUM(discounts_currency), 0) as discounts,
                COALESCE(SUM(returns_currency), 0) as returns,
                COALESCE(SUM(tax_currency), 0) as tax,
                COALESCE(SUM(is_issued_purchase), 0) as count
            ')
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get();

        $currencyTotals = [];
        foreach ($currencyGroupRows as $cg) {
            $cgPurchases = BigDecimal::of((string) $cg->purchases);
            $cgRet = BigDecimal::of((string) $cg->returns);
            $currencyTotals[(string) $cg->currency_code] = [
                'currency_code' => (string) $cg->currency_code,
                'commercial_purchases' => (string) $cgPurchases->toScale(6),
                'discounts' => (string) BigDecimal::of((string) $cg->discounts)->toScale(6),
                'returns' => (string) $cgRet->toScale(6),
                'net_purchases' => (string) $cgPurchases->minus($cgRet)->toScale(6),
                'tax' => (string) BigDecimal::of((string) $cg->tax)->toScale(6),
                'purchase_count' => (int) $cg->count,
            ];
        }

        $totals = [
            'commercial_purchases_base' => (string) $purchasesBase->toScale(6),
            'discounts_base' => (string) $discountsBase->toScale(6),
            'returns_base' => (string) $returnsBase->toScale(6),
            'net_purchases_base' => (string) $netPurchasesBase->toScale(6),
            'tax_base' => (string) $taxBase->toScale(6),
            'purchase_count' => $purchaseCount,
            'average_purchase_base' => $averagePurchaseBase,
            'currencies' => $currencyTotals,
        ];

        $grouping = $validatedFilters->grouping;
        if ($grouping !== null) {
            $groupExpr = match ($grouping) {
                'weekly' => "DATE_FORMAT(business_date, '%x-W%v')",
                'monthly' => "DATE_FORMAT(business_date, '%Y-%m')",
                'daily' => 'business_date',
                default => 'business_date',
            };

            $groupedQuery = (clone $activityQuery)
                ->selectRaw("
                    {$groupExpr} as period_key,
                    COALESCE(SUM(commercial_purchases_base), 0) as commercial_purchases_base,
                    COALESCE(SUM(discounts_base), 0) as discounts_base,
                    COALESCE(SUM(returns_base), 0) as returns_base,
                    COALESCE(SUM(tax_base), 0) as tax_base,
                    COALESCE(SUM(is_issued_purchase), 0) as purchase_count
                ")
                ->groupBy(DB::raw($groupExpr))
                ->orderBy(DB::raw($groupExpr), 'desc');

            $totalRecords = DB::query()->fromSub($groupedQuery, 'grps')->count();

            $pagedRows = $groupedQuery
                ->forPage($validatedFilters->page, $validatedFilters->perPage)
                ->get();

            $rows = [];
            foreach ($pagedRows as $row) {
                $rPurch = BigDecimal::of((string) $row->commercial_purchases_base);
                $rRet = BigDecimal::of((string) $row->returns_base);
                $rNet = $rPurch->minus($rRet);
                $rCount = (int) $row->purchase_count;

                $rows[] = [
                    'period_key' => (string) $row->period_key,
                    'commercial_purchases_base' => (string) $rPurch->toScale(6),
                    'discounts_base' => (string) BigDecimal::of((string) $row->discounts_base)->toScale(6),
                    'returns_base' => (string) $rRet->toScale(6),
                    'net_purchases_base' => (string) $rNet->toScale(6),
                    'tax_base' => (string) BigDecimal::of((string) $row->tax_base)->toScale(6),
                    'purchase_count' => $rCount,
                    'average_purchase_base' => $rCount > 0
                        ? (string) $rPurch->dividedBy($rCount, 6, RoundingMode::HALF_UP)
                        : '0.000000',
                ];
            }
        } else {
            $detailQuery = (clone $activityQuery)
                ->orderBy('business_date', 'desc')
                ->orderBy('event_type', 'asc')
                ->orderBy('document_id', 'desc');

            $totalRecords = (clone $detailQuery)->count();

            $pagedRows = $detailQuery
                ->forPage($validatedFilters->page, $validatedFilters->perPage)
                ->get();

            $rows = [];
            foreach ($pagedRows as $row) {
                $rPurch = BigDecimal::of((string) $row->commercial_purchases_base);
                $rRet = BigDecimal::of((string) $row->returns_base);

                $rows[] = [
                    'document_id' => (int) $row->document_id,
                    'document_number' => (string) $row->document_number,
                    'event_type' => (string) $row->event_type,
                    'business_date' => (string) $row->business_date,
                    'vendor_id' => (int) $row->vendor_id,
                    'currency_code' => (string) $row->currency_code,
                    'commercial_purchases_base' => (string) $rPurch->toScale(6),
                    'discounts_base' => (string) BigDecimal::of((string) $row->discounts_base)->toScale(6),
                    'returns_base' => (string) $rRet->toScale(6),
                    'net_purchases_base' => (string) $rPurch->minus($rRet)->toScale(6),
                    'tax_base' => (string) BigDecimal::of((string) $row->tax_base)->toScale(6),
                ];
            }
        }

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $rows,
            totalRecords: $totalRecords,
            meta: [
                'scope' => 'commercial_purchases',
                'grouping' => $grouping,
            ]
        );
    }
}
