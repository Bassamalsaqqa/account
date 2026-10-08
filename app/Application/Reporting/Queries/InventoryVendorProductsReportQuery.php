<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\InventoryReportHelper;
use App\Application\Reporting\Support\MoneyReportHelper;
use App\Application\Reporting\Support\OperationalReportRead as Read;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class InventoryVendorProductsReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.inventory.view', 'inventory.stock.view', 'purchasing.purchase.view']);
        $f = Read::filters($company, $filters, ['vendor_id', 'product_id', 'category_id', 'warehouse_id', 'currency_code'], []);
        InventoryReportHelper::validateFilters($company, $f);

        $cost = $this->guard->allows($company, ['purchasing.cost.view', 'reports.cost.view'], $actor);
        $q = DB::table('purchase_lines as pl')->join('purchases as p', 'p.id', '=', 'pl.purchase_id')
            ->join('products as product', 'product.id', '=', 'pl.product_id')->join('posting_batches as b', 'b.id', '=', 'p.posting_batch_id')
            ->where('pl.company_id', $company->id)->where('p.company_id', $company->id)->where('product.company_id', $company->id)
            ->where('b.company_id', $company->id)->where('b.source_type', 'purchase')->whereColumn('b.source_id', 'p.id')
            ->whereIn('b.status', ['posted', 'reversed'])->where('p.status', 'posted')->whereNotNull('p.purchase_number')
            ->whereNotNull('p.posted_at')->whereNotNull('p.posted_by')->whereBetween('p.purchase_date', [$f->period->startDate, $f->period->endDate]);
        foreach (['p.vendor_id' => $f->vendorId, 'pl.product_id' => $f->productId, 'product.category_id' => $f->categoryId,
            'p.warehouse_id' => $f->warehouseId, 'p.currency_code' => $f->currencyCode] as $key => $value) {
            if ($value !== null) {
                $q->where($key, $value);
            }
        }
        $aggregate = (clone $q)->groupBy('p.vendor_id', 'pl.product_id')->selectRaw('p.vendor_id,pl.product_id,
            COUNT(DISTINCT p.id) AS purchase_count,SUM(pl.quantity_base) AS total_quantity_base,MAX(p.purchase_date) AS last_purchase_date')
            ->selectRaw($cost ? 'SUM(pl.line_total_base) AS total_spend_base' : 'NULL AS total_spend_base');
        $latest = (clone $q)->select('p.vendor_id', 'pl.product_id', 'p.vendor_snapshot', 'pl.product_name_ar', 'pl.product_name_en', 'pl.product_sku')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY p.vendor_id,pl.product_id ORDER BY p.purchase_date DESC,p.id DESC,pl.id DESC) AS position');
        $scope = DB::query()->fromSub($aggregate, 'supplier_totals')->joinSub($latest, 'identity', function (JoinClause $j): void {
            $j->on('identity.vendor_id', '=', 'supplier_totals.vendor_id')->on('identity.product_id', '=', 'supplier_totals.product_id')->where('identity.position', 1);
        })->select('supplier_totals.*', 'identity.vendor_snapshot', 'identity.product_name_ar', 'identity.product_name_en', 'identity.product_sku');
        $totals = ['total_quantity' => Read::decimal((string) (clone $scope)->sum('total_quantity_base')), 'records_count' => (clone $scope)->count()];
        if ($cost) {
            $totals['total_spend'] = Read::decimal((string) (clone $scope)->sum('total_spend_base'));
        }

        return Read::result('inventory.vendor_products', $company, $f, $scope->orderBy('supplier_totals.vendor_id')->orderBy('supplier_totals.product_id'), $totals,
            static fn (object $r): array => ['vendor_id' => (int) $r->vendor_id, 'product_id' => (int) $r->product_id,
                'vendor_name' => MoneyReportHelper::extractPartyName(Read::snapshot($r->vendor_snapshot)),
                'product_name' => Read::name($r->product_name_ar, $r->product_name_en), 'sku' => $r->product_sku === null ? null : (string) $r->product_sku,
                'purchase_count' => (int) $r->purchase_count, 'total_quantity_base' => Read::decimal((string) $r->total_quantity_base),
                'total_spend_base' => $cost ? Read::decimal((string) $r->total_spend_base) : null, 'last_purchase_date' => (string) $r->last_purchase_date],
            ['cost_redacted' => ! $cost, 'identity_semantics' => 'latest_selected_posted_snapshot']);

    }
}
