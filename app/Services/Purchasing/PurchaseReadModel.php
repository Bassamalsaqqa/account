<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Services\Sales\DocumentDescription;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;

final class PurchaseReadModel
{
    /** @return array<string, mixed> */
    public function detail(Purchase $purchase, bool $withCost): array
    {
        return DB::transaction(function () use ($purchase, $withCost): array {
            abort_unless(auth()->check(), 403);
            app(SalesActorGuard::class)->lockAndAuthorize((int) $purchase->company_id, auth()->user(), 'purchasing.purchase.view');
            $purchase = Purchase::where('company_id', $purchase->company_id)->whereKey($purchase->id)->firstOrFail();

            return $this->buildDetail($purchase, $withCost && auth()->user()->hasPermissionTo('purchasing.cost.view'));
        });
    }

    /** @return array<string, mixed> */
    private function buildDetail(Purchase $purchase, bool $withCost): array
    {
        $data = $purchase->only(['public_id', 'purchase_number', 'vendor_invoice_number', 'status', 'currency_code', 'document_locale', 'notes']);
        $data['posted_at'] = $purchase->posted_at?->format('Y-m-d H:i');
        $data['purchase_date'] = $purchase->purchase_date->format('Y-m-d');
        $data['due_date'] = $purchase->due_date?->format('Y-m-d');
        $data['vendor_name'] = $purchase->vendor->displayName();
        if (! $purchase->isDraft()) {
            $snapshot = $purchase->vendor_snapshot ?? [];
            $data['vendor_name'] = app()->getLocale() === 'en' ? ($snapshot['name_en'] ?? $snapshot['name_ar'] ?? '') : ($snapshot['name_ar'] ?? '');
        }
        $data['warehouse_name'] = $purchase->warehouse->displayName();
        if ($withCost) {
            $data += $purchase->only(['exchange_rate', 'subtotal_currency', 'discount_total_currency', 'tax_total_currency', 'grand_total_currency', 'base_currency_code']);
            $data['total_landed_cost_base'] = (string) $purchase->lines()->sum('landed_cost_allocated_base');
        }
        $fields = ['id', 'public_id', 'company_id', 'purchase_id', 'line_number', 'item_description', 'quantity', 'quantity_base',
            'unit_name_ar', 'unit_name_en', 'product_sku', 'product_name_ar', 'product_name_en'];
        if ($withCost) {
            $fields = [...$fields, 'unit_cost', 'line_discount', 'line_tax', 'line_total', 'landed_cost_allocated_base', 'inventory_unit_cost_base'];
        }
        $data['lines'] = $purchase->lines()->where('company_id', $purchase->company_id)->select($fields)
            ->with(['lots' => fn ($q) => $q->where('company_id', $purchase->company_id)->select(['id', 'public_id', 'purchase_line_id', 'lot_number', 'expiry_date', 'quantity'])])
            ->get()->map(function (PurchaseLine $line) use ($withCost): array {
                $data = $line->only(['id', 'public_id', 'item_description', 'quantity', 'quantity_base', 'product_sku']);
                $data['item_description'] = DocumentDescription::choose($line->item_description, $line->product_name_ar, $line->product_name_en, app()->getLocale());
                $data['unit_name'] = app()->getLocale() === 'en' ? ($line->unit_name_en ?? $line->unit_name_ar) : $line->unit_name_ar;
                $data['lots'] = $line->lots->map(fn ($lot) => ['public_id' => $lot->public_id,
                    'lot_number' => $lot->lot_number, 'expiry_date' => $lot->expiry_date?->format('Y-m-d'), 'quantity' => $lot->quantity])->all();
                if ($withCost) {
                    $data += $line->only(['unit_cost', 'line_discount', 'line_tax', 'line_total', 'landed_cost_allocated_base', 'inventory_unit_cost_base']);
                }

                return $data;
            })->all();

        return $data;
    }
}
