<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Models\Company;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class InventoryReportRead
{
    public static function movements(Company $company, ReportFilters $f, bool $asOf = false): Builder
    {
        $q = DB::table('stock_movements as sm')->join('products as p', 'p.id', '=', 'sm.product_id')
            ->join('warehouses as w', 'w.id', '=', 'sm.warehouse_id')->join('units as u', 'u.id', '=', 'p.base_unit_id')
            ->where('sm.company_id', $company->id)->where('p.company_id', $company->id)->where('w.company_id', $company->id)
            ->where('u.company_id', $company->id)->where('sm.movement_date', '<=', $f->period->endDate);
        if (! $asOf) {
            $q->where('sm.movement_date', '>=', $f->period->startDate);
        }
        if ($f->productId !== null) {
            $q->where('sm.product_id', $f->productId);
        }
        if ($f->warehouseId !== null) {
            $q->where('sm.warehouse_id', $f->warehouseId);
        }
        if ($f->categoryId !== null) {
            $q->where('p.category_id', $f->categoryId);
        }

        return $q;
    }

    public static function positions(Company $company, ReportFilters $f, bool $withCost): Builder
    {
        $q = self::movements($company, $f, true)->select('p.id as product_id', 'p.sku', 'p.name_ar', 'p.name_en', 'u.code as unit_code')
            ->groupBy('p.id', 'p.sku', 'p.name_ar', 'p.name_en', 'u.code')->selectRaw('SUM(sm.quantity_delta_base) AS on_hand_base')
            ->selectRaw($withCost ? 'SUM(sm.value_delta_base) AS value_base' : 'NULL AS value_base');
        if ($f->grouping === 'warehouse') {
            $q->addSelect('sm.warehouse_id', 'w.name_ar as warehouse_name_ar', 'w.name_en as warehouse_name_en')
                ->groupBy('sm.warehouse_id', 'w.name_ar', 'w.name_en');
        } else {
            $q->selectRaw('NULL AS warehouse_id,NULL AS warehouse_name_ar,NULL AS warehouse_name_en');
        }

        return DB::query()->fromSub($q, 'stock_positions');
    }

    /** @return array<string,mixed> */
    public static function identity(object $r): array
    {
        return ['product_id' => (int) $r->product_id, 'sku' => (string) $r->sku,
            'product_name' => OperationalReportRead::name($r->name_ar, $r->name_en),
            'unit_code' => (string) $r->unit_code, 'warehouse_id' => $r->warehouse_id === null ? null : (int) $r->warehouse_id,
            'warehouse_name' => $r->warehouse_id === null ? null : OperationalReportRead::name($r->warehouse_name_ar,$r->warehouse_name_en)];
    }
}
