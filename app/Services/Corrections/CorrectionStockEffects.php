<?php

declare(strict_types=1);

namespace App\Services\Corrections;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CorrectionStockEffects
{
    /** @return list<array<string, string>> */
    public function rows(Purchase|PurchaseReturn|SalesInvoice|SalesReturn $source): array
    {
        $company = (int) $source->company_id;
        // Keep references in SQL: no unbounded ID hydration before the row guard.
        $references = match (true) {
            $source instanceof Purchase => [['purchase_lines', 'purchase_id'], ['purchase_line_lots', null]],
            $source instanceof PurchaseReturn => [['purchase_return_allocations', 'purchase_return_id'], ['purchase_return_lines', 'purchase_return_id']],
            $source instanceof SalesInvoice => [['sales_invoice_lot_allocations', 'sales_invoice_id']],
            $source instanceof SalesReturn => [['sales_return_lot_allocations', 'sales_return_id']],
        };
        $rows = DB::table('stock_movements as m')
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'm.product_id')->on('p.company_id', '=', 'm.company_id'))
            ->join('warehouses as w', fn ($j) => $j->on('w.id', '=', 'm.warehouse_id')->on('w.company_id', '=', 'm.company_id'))
            ->leftJoin('inventory_lots as l', fn ($j) => $j->on('l.id', '=', 'm.lot_id')->on('l.company_id', '=', 'm.company_id'))
            ->where('m.company_id', $company)->where(function ($query) use ($references, $company, $source) {
                foreach ($references as [$table, $foreign]) {
                    $ids = DB::table($table)->select('stock_movement_id')->where('company_id', $company);
                    if ($foreign === null) {
                        $ids->whereIn('purchase_line_id', DB::table('purchase_lines')->select('id')->where('company_id', $company)->where('purchase_id', $source->id));
                    } else {
                        $ids->where($foreign, $source->id);
                    }
                    $query->orWhereIn('m.id', $ids);
                }
            })->orderBy('m.id')->limit(2001)
            ->get(['m.quantity_delta_base', 'p.name_ar as product_ar', 'p.name_en as product_en', 'w.name_ar as warehouse_ar', 'w.name_en as warehouse_en', 'l.lot_number']);

        if ($rows->count() > 2000) {
            throw ValidationException::withMessages(['correction' => __('corrections.graph_too_large')]);
        }

        return $rows->map(fn ($row) => ['product' => app()->getLocale() === 'ar' ? $row->product_ar : ($row->product_en ?? $row->product_ar),
            'warehouse' => app()->getLocale() === 'ar' ? $row->warehouse_ar : ($row->warehouse_en ?? $row->warehouse_ar), 'lot' => $row->lot_number ?? '',
            'quantity' => (string) BigDecimal::of((string) $row->quantity_delta_base)->negated()])->all();
    }
}
