<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\PurchaseReturnLine;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;

final class PurchaseReturnReadModel
{
    /** @return array<string, mixed> */
    public function detail(PurchaseReturn $return, bool $withCost): array
    {
        return DB::transaction(function () use ($return, $withCost): array {
            abort_unless(auth()->check(), 403);
            app(SalesActorGuard::class)->lockAndAuthorize((int) $return->company_id, auth()->user(), 'purchasing.purchase.view');
            $return = PurchaseReturn::where('company_id', $return->company_id)->whereKey($return->id)->firstOrFail();

            return $this->buildDetail($return, $withCost && auth()->user()->hasPermissionTo('purchasing.cost.view'));
        });
    }

    /** @return array<string, mixed> */
    private function buildDetail(PurchaseReturn $return, bool $withCost): array
    {
        $data = $return->only(['public_id', 'return_number', 'status', 'currency_code', 'document_locale', 'reason', 'notes']);
        $data['posted_at'] = $return->posted_at?->format('Y-m-d H:i');
        $data['return_date'] = $return->return_date->format('Y-m-d');
        $data['vendor_name'] = $return->vendor->displayName();

        if (! $return->isDraft()) {
            $snapshot = $return->vendor_snapshot ?? [];
            $data['vendor_name'] = app()->getLocale() === 'en'
                ? ($snapshot['name_en'] ?? $snapshot['name_ar'] ?? '')
                : ($snapshot['name_ar'] ?? '');
        }

        $data['warehouse_name'] = $return->warehouse->displayName();
        $data['purchase_number'] = $return->purchase->purchase_number ?? '';
        $data['purchase_public_id'] = $return->purchase->public_id;
        $data['purchase_date'] = $return->purchase->purchase_date->format('Y-m-d');

        if ($withCost) {
            $data += $return->only([
                'exchange_rate',
                'subtotal_currency',
                'discount_total_currency',
                'tax_total_currency',
                'grand_total_currency',
                'subtotal_base',
                'discount_total_base',
                'tax_total_base',
                'grand_total_base',
                'posting_batch_id',
            ]);
        }

        $fields = [
            'id', 'public_id', 'company_id', 'purchase_return_id', 'purchase_line_id',
            'line_number', 'item_description', 'quantity', 'quantity_base',
            'unit_name_ar', 'unit_name_en', 'product_sku', 'product_name_ar', 'product_name_en',
        ];

        if ($withCost) {
            $fields = [
                ...$fields,
                'unit_cost', 'line_discount', 'line_tax', 'line_total', 'line_subtotal',
                'line_subtotal_base', 'line_discount_base', 'line_tax_base', 'line_total_base',
                'historical_receipt_value_base', 'inventory_value_removed_base', 'valuation_adjustment_base',
            ];
        }

        $data['lines'] = $return->lines()
            ->where('company_id', $return->company_id)
            ->select($fields)
            ->with(['allocations' => function ($q) use ($return, $withCost): void {
                $allocFields = ['id', 'purchase_return_line_id', 'purchase_line_lot_id', 'inventory_lot_id', 'quantity', 'quantity_base'];
                if ($withCost) {
                    $allocFields = [...$allocFields, 'historical_value_base', 'inventory_value_removed_base'];
                }
                $q->where('company_id', $return->company_id)
                    ->select($allocFields)
                    ->with('inventoryLot');
            }])
            ->get()
            ->map(function (PurchaseReturnLine $line) use ($withCost): array {
                $lineData = $line->only(['public_id', 'item_description', 'quantity', 'quantity_base', 'product_sku']);
                $lineData['unit_name'] = app()->getLocale() === 'en'
                    ? ($line->unit_name_en ?? $line->unit_name_ar)
                    : $line->unit_name_ar;

                $lineData['allocations'] = $line->allocations->map(function (PurchaseReturnAllocation $alloc) use ($withCost): array {
                    $allocData = [
                        'quantity' => $alloc->quantity,
                        'quantity_base' => $alloc->quantity_base,
                        'lot_number' => $alloc->inventoryLot?->lot_number,
                        'expiry_date' => $alloc->inventoryLot?->expiry_date?->format('Y-m-d'),
                    ];

                    if ($withCost) {
                        $allocData += $alloc->only(['historical_value_base', 'inventory_value_removed_base']);
                    }

                    return $allocData;
                })->all();

                if ($withCost) {
                    $lineData += $line->only([
                        'unit_cost', 'line_discount', 'line_tax', 'line_total', 'line_subtotal',
                        'line_subtotal_base', 'line_discount_base', 'line_tax_base', 'line_total_base',
                        'historical_receipt_value_base', 'inventory_value_removed_base', 'valuation_adjustment_base',
                    ]);
                }

                return $lineData;
            })->all();

        return $data;
    }
}
