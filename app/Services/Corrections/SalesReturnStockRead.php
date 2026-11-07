<?php

declare(strict_types=1);

namespace App\Services\Corrections;

use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only quantity diagnostics. Canonical actions retain the final quantity/value verifier. */
final class SalesReturnStockRead
{
    /**
     * @param  list<int>  $returnIds
     * @return array{blockers: list<string>, links: list<array{label: string, url: string}>}
     */
    public function inspect(int $company, array $returnIds, User $actor): array
    {
        $rows = DB::table('sales_return_lot_allocations as a')
            ->join('stock_movements as m', fn ($j) => $j->on('m.id', '=', 'a.stock_movement_id')->on('m.company_id', '=', 'a.company_id'))
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'm.product_id')->on('p.company_id', '=', 'm.company_id'))
            ->join('warehouses as w', fn ($j) => $j->on('w.id', '=', 'm.warehouse_id')->on('w.company_id', '=', 'm.company_id'))
            ->where('a.company_id', $company)->whereIn('a.sales_return_id', $returnIds)->orderBy('a.id')->limit(2001)
            ->get(['m.product_id', 'm.warehouse_id', 'm.lot_id', 'a.quantity_allocated_base', 'p.public_id', 'p.name_ar', 'p.name_en', 'w.name_ar as warehouse_ar', 'w.name_en as warehouse_en']);
        if ($rows->count() > 2000) {
            throw ValidationException::withMessages(['correction' => __('corrections.graph_too_large')]);
        }
        $blockers = [];
        $links = [];
        foreach ($rows->groupBy('product_id') as $productId => $productRows) {
            $required = BigDecimal::zero();
            foreach ($productRows as $row) {
                $required = $required->plus((string) $row->quantity_allocated_base);
            }
            $companyQty = DB::table('inventory_cost_states')->where('company_id', $company)->where('product_id', $productId)->value('quantity_base') ?? '0';
            $insufficient = BigDecimal::of((string) $companyQty)->isLessThan($required);
            foreach ($productRows->groupBy('warehouse_id') as $warehouseId => $warehouseRows) {
                $quantity = BigDecimal::zero();
                foreach ($warehouseRows as $row) {
                    $quantity = $quantity->plus((string) $row->quantity_allocated_base);
                }
                $available = DB::table('inventory_balances')->where('company_id', $company)->where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity_base') ?? '0';
                $short = $insufficient || BigDecimal::of((string) $available)->isLessThan($quantity);
                foreach ($warehouseRows->whereNotNull('lot_id')->groupBy('lot_id') as $lotId => $lotRows) {
                    $lotQuantity = BigDecimal::zero();
                    foreach ($lotRows as $row) {
                        $lotQuantity = $lotQuantity->plus((string) $row->quantity_allocated_base);
                    }
                    $lotAvailable = DB::table('inventory_lot_balances')->where('company_id', $company)->where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('lot_id', $lotId)->value('quantity_base') ?? '0';
                    $short = $short || BigDecimal::of((string) $lotAvailable)->isLessThan($lotQuantity);
                }
                if ($short) {
                    $first = $warehouseRows->first();
                    $name = app()->getLocale() === 'ar' ? $first->name_ar : ($first->name_en ?? $first->name_ar);
                    $warehouse = app()->getLocale() === 'ar' ? $first->warehouse_ar : ($first->warehouse_en ?? $first->warehouse_ar);
                    $blockers[] = __('corrections.stock_shortage', ['product' => $name, 'warehouse' => $warehouse]);
                    if ($actor->can('inventory.stock.view')) {
                        $links[] = ['label' => $name, 'url' => route('products.show', $first->public_id)];
                    }
                }
            }
        }

        return compact('blockers', 'links');
    }
}
