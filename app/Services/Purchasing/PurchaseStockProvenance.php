<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\InventoryLot;
use App\Models\LedgerAccount;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Read-only validation shared by completion, retries and inventory history replay. */
final class PurchaseStockProvenance
{
    public function value(StockMovement $movement, bool $requirePosted = true): BigDecimal
    {
        $purchase = Purchase::withoutGlobalScopes()->where('company_id', $movement->company_id)->find($movement->source_id);
        $line = PurchaseLine::withoutGlobalScopes()->where('company_id', $movement->company_id)
            ->where('purchase_id', $movement->source_id)->find($movement->source_line_id);
        if ($purchase === null || $line === null || ($requirePosted && $purchase->status !== Purchase::STATUS_POSTED)
            || $movement->source_type !== 'purchase' || $movement->movement_type !== StockMovement::TYPE_PURCHASE
            || (int) $movement->warehouse_id !== (int) $purchase->warehouse_id
            || (int) $movement->product_id !== (int) $line->product_id || $movement->reversal_of_id !== null
            || ($requirePosted && (int) $movement->created_by !== (int) $purchase->posted_by)
            || $movement->movement_date->format('Y-m-d') !== $purchase->purchase_date->format('Y-m-d')) {
            $this->fail();
        }
        $value = app(PurchaseAcquisitionValue::class)->line($line, $line->purchase_tax_account_id);
        if ($line->purchase_tax_account_id !== null && ($line->tax_rate_snapshot === null
            || ! LedgerAccount::withoutGlobalScopes()->where('company_id', $line->company_id)->whereKey($line->purchase_tax_account_id)->exists())) {
            $this->fail();
        }
        $unitCost = app(PurchaseAcquisitionValue::class)->unitCost($line, $value);
        $unitId = $line->productUnit()->withoutGlobalScopes()->where('company_id', $line->company_id)->value('unit_id');
        if (! BigDecimal::of($movement->unit_cost_base)->isEqualTo($unitCost)
            || ! BigDecimal::of($movement->conversion_to_base)->isEqualTo($line->unit_conversion_ratio)
            || (int) $movement->unit_id !== (int) $unitId) {
            $this->fail();
        }
        $lots = $line->lots()->withoutGlobalScopes()->where('company_id', $line->company_id)->get();
        $line->setRelation('lots', $lots);
        $quantity = $line->quantity;
        $base = $line->quantity_base;
        if ($lots->isNotEmpty()) {
            $index = $lots->search(fn ($lot) => (int) $lot->stock_movement_id === (int) $movement->id);
            if ($index === false) {
                $this->fail();
            }
            $intent = $lots[$index];
            $lot = InventoryLot::withoutGlobalScopes()->where('company_id', $purchase->company_id)->find($movement->lot_id);
            if ($lot === null || (int) $intent->created_inventory_lot_id !== (int) $lot->id
                || (int) $lot->product_id !== (int) $line->product_id || $lot->source_type !== 'purchase'
                || (int) $lot->source_id !== (int) $purchase->id || (int) $lot->source_line_id !== (int) $line->id
                || $lot->received_date->format('Y-m-d') !== $purchase->purchase_date->format('Y-m-d')
                || $lot->lot_number !== $intent->lot_number || $lot->expiry_date?->format('Y-m-d') !== $intent->expiry_date?->format('Y-m-d')) {
                $this->fail();
            }
            $quantity = $intent->quantity;
            $base = $intent->quantity_base;
            $value = app(PurchaseAcquisitionValue::class)->lots($line, $value)[$index];
        } elseif ($movement->lot_id !== null || (int) $line->stock_movement_id !== (int) $movement->id) {
            $this->fail();
        }
        if (! BigDecimal::of($movement->source_quantity)->isEqualTo($quantity)
            || ! BigDecimal::of($movement->quantity_delta_base)->isEqualTo($base)
            || ! BigDecimal::of($movement->value_delta_base)->isEqualTo($value)) {
            $this->fail();
        }

        return $value;
    }

    private function fail(): never
    {
        throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
    }
}
