<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\InventoryReportHelper;
use App\Application\Reporting\Support\OperationalReportRead as Read;
use App\Models\Company;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class LowStockReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.inventory.view', 'inventory.stock.view']);
        $f = Read::filters($company, $filters, ['product_id', 'warehouse_id', 'category_id', 'status'], ['status' => ['low', 'out']]);
        InventoryReportHelper::validateFilters($company, $f);

        $moves = DB::table('stock_movements')->where('company_id', $company->id)->where('movement_date', '<=', $f->period->endDate)
            ->groupBy('product_id')->selectRaw('product_id,SUM(quantity_delta_base) AS on_hand');
        if ($f->warehouseId !== null) {
            $moves->where('warehouse_id', $f->warehouseId);
        }
        $q = DB::table('products as p')->join('units as u', 'u.id', '=', 'p.base_unit_id')->leftJoinSub($moves, 'stock', 'stock.product_id', '=', 'p.id')
            ->where('p.company_id', $company->id)->where('u.company_id', $company->id)->where('p.track_stock', true)->whereNull('p.deleted_at')
            ->select('p.id as product_id', 'p.sku', 'p.name_ar', 'p.name_en', 'u.code as unit_code', 'p.minimum_stock_base')
            ->selectRaw('COALESCE(stock.on_hand,0) AS on_hand')
            ->selectRaw("CASE WHEN COALESCE(stock.on_hand,0)<=0 THEN 'out' WHEN stock.on_hand<=p.minimum_stock_base THEN 'low' ELSE 'ok' END AS stock_status");
        if ($f->productId !== null) {
            $q->where('p.id', $f->productId);
        }
        if ($f->categoryId !== null) {
            $q->where('p.category_id', $f->categoryId);
        }
        $scope = DB::query()->fromSub($q, 'stock_alerts')->whereIn('stock_status', ['low', 'out']);
        if ($f->status !== null) {
            $scope->where('stock_status', $f->status);
        }

        return Read::result('inventory.low_stock', $company, $f, $scope->orderBy('sku')->orderBy('product_id'), [
            'low_stock_count' => (clone $scope)->where('stock_status', 'low')->count(),
            'out_of_stock_count' => (clone $scope)->where('stock_status', 'out')->count(), 'total_alert_count' => (clone $scope)->count()],
            static fn (object $r): array => ['product_id' => (int) $r->product_id, 'sku' => (string) $r->sku, 'product_name' => Read::name($r->name_ar, $r->name_en),
                'unit_code' => (string) $r->unit_code, 'quantity_on_hand' => Read::decimal((string) $r->on_hand),
                'minimum_stock' => Read::decimal($r->minimum_stock_base), 'shortage_quantity' => (string) BigDecimal::max(0,
                    BigDecimal::of((string) ($r->minimum_stock_base ?? '0'))->minus((string) $r->on_hand))->toScale(6),
                'stock_status' => (string) $r->stock_status], ['as_of_date' => $f->period->endDate, 'threshold_semantics' => 'current_configured_minimum']);

    }
}
