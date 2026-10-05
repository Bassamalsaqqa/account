<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\PurchaseLineLot;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\PurchaseReturnLine;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Read-only provenance validation for purchase return stock movements. */
final class PurchaseReturnStockProvenance
{
    public static function validateAllocationLotChain(
        int $companyId,
        int $warehouseId,
        int $purchaseId,
        PurchaseLine $originalLine,
        PurchaseReturnAllocation $alloc,
        ?StockMovement $outboundMovement = null
    ): void {
        /** @var Product|null $product */
        $product = Product::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($originalLine->product_id);

        if ($product === null) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        if ($product->track_expiry) {
            if ($alloc->purchase_line_lot_id === null || $alloc->inventory_lot_id === null) {
                throw new InvalidArgumentException(__('purchasing.allocations_required_for_expiry'));
            }

            /** @var PurchaseLineLot|null $lotLine */
            $lotLine = PurchaseLineLot::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('purchase_line_id', $originalLine->id)
                ->where('id', $alloc->purchase_line_lot_id)
                ->first();

            if ($lotLine === null
                || (int) $lotLine->stock_movement_id !== (int) $alloc->original_stock_movement_id
                || (int) $lotLine->created_inventory_lot_id !== (int) $alloc->inventory_lot_id) {
                throw new InvalidArgumentException(__('purchasing.invalid_lot_movement'));
            }

            /** @var StockMovement|null $receipt */
            $receipt = StockMovement::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->find($alloc->original_stock_movement_id);

            if ($receipt === null
                || $receipt->movement_type !== StockMovement::TYPE_PURCHASE
                || $receipt->source_type !== 'purchase'
                || (int) $receipt->source_id !== $purchaseId
                || (int) $receipt->source_line_id !== (int) $originalLine->id
                || (int) $receipt->product_id !== (int) $originalLine->product_id
                || (int) $receipt->warehouse_id !== $warehouseId
                || (int) $receipt->lot_id !== (int) $alloc->inventory_lot_id) {
                throw new InvalidArgumentException(__('purchasing.invalid_lot_movement'));
            }

            /** @var InventoryLot|null $invLot */
            $invLot = InventoryLot::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->find($alloc->inventory_lot_id);

            if ($invLot === null
                || (int) $invLot->product_id !== (int) $originalLine->product_id
                || $invLot->source_type !== 'purchase'
                || (int) $invLot->source_id !== $purchaseId
                || (int) $invLot->source_line_id !== (int) $originalLine->id) {
                throw new InvalidArgumentException(__('purchasing.invalid_lot_movement'));
            }

            if ($outboundMovement !== null && (int) $outboundMovement->lot_id !== (int) $alloc->inventory_lot_id) {
                throw new InvalidArgumentException(__('purchasing.invalid_lot_movement'));
            }

        } else {
            if ($alloc->purchase_line_lot_id !== null || $alloc->inventory_lot_id !== null) {
                throw new InvalidArgumentException(__('purchasing.allocations_unsupported_for_non_expiry'));
            }

            if ((int) $alloc->original_stock_movement_id !== (int) $originalLine->stock_movement_id) {
                throw new InvalidArgumentException(__('purchasing.invalid_lot_movement'));
            }

            /** @var StockMovement|null $receipt */
            $receipt = StockMovement::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->find($alloc->original_stock_movement_id);

            if ($receipt === null
                || $receipt->movement_type !== StockMovement::TYPE_PURCHASE
                || $receipt->source_type !== 'purchase'
                || (int) $receipt->source_id !== $purchaseId
                || (int) $receipt->source_line_id !== (int) $originalLine->id
                || (int) $receipt->product_id !== (int) $originalLine->product_id
                || (int) $receipt->warehouse_id !== $warehouseId
                || $receipt->lot_id !== null) {
                throw new InvalidArgumentException(__('purchasing.invalid_lot_movement'));
            }

            if ($outboundMovement !== null && $outboundMovement->lot_id !== null) {
                throw new InvalidArgumentException(__('purchasing.invalid_lot_movement'));
            }
        }
    }

    public function value(StockMovement $movement, bool $requirePosted = true, ?int $beforeMovementId = null): BigDecimal
    {
        /** @var PurchaseReturn|null $return */
        $return = PurchaseReturn::withoutGlobalScopes()
            ->where('company_id', $movement->company_id)
            ->find($movement->source_id);

        /** @var PurchaseReturnLine|null $line */
        $line = PurchaseReturnLine::withoutGlobalScopes()
            ->where('company_id', $movement->company_id)
            ->where('purchase_return_id', $movement->source_id)
            ->find($movement->source_line_id);

        if ($return === null || $line === null || ($requirePosted && $return->status !== PurchaseReturn::STATUS_POSTED)
            || $movement->source_type !== 'purchase_return' || $movement->movement_type !== StockMovement::TYPE_PURCHASE_RETURN
            || (int) $movement->warehouse_id !== (int) $return->warehouse_id
            || (int) $movement->product_id !== (int) $line->product_id
            || $movement->reversal_of_id === null
            || ($requirePosted && (int) $movement->created_by !== (int) $return->posted_by)
            || $movement->movement_date->format('Y-m-d') !== $return->return_date->format('Y-m-d')) {
            $this->fail();
        }

        // Validate that original receipt movement belongs to this return's original purchase & line
        /** @var PurchaseLine|null $originalLine */
        $originalLine = PurchaseLine::withoutGlobalScopes()
            ->where('company_id', $return->company_id)
            ->where('purchase_id', $return->purchase_id)
            ->find($line->purchase_line_id);

        if ($originalLine === null) {
            $this->fail();
        }

        $allocationQuery = PurchaseReturnAllocation::withoutGlobalScopes()
            ->where('company_id', $return->company_id)
            ->where('purchase_return_line_id', $line->id)
            ->where('original_stock_movement_id', $movement->reversal_of_id);

        if ($requirePosted) {
            $allocationQuery->where('stock_movement_id', $movement->id);
        }

        /** @var PurchaseReturnAllocation|null $allocation */
        $allocation = $allocationQuery->first();

        if ($allocation === null) {
            $this->fail();
        }

        self::validateAllocationLotChain(
            (int) $movement->company_id,
            (int) $return->warehouse_id,
            (int) $return->purchase_id,
            $originalLine,
            $allocation,
            $movement
        );

        $qtyBase = BigDecimal::of((string) $movement->quantity_delta_base)->abs();
        if (! $qtyBase->isEqualTo(BigDecimal::of((string) $allocation->quantity_base))) {
            $this->fail();
        }

        $cutoffMovementId = $beforeMovementId ?? ($requirePosted ? (int) $movement->id : null);

        $expectedTarget = app(HistoricalPurchaseReceiptValue::class)->target(
            (int) $movement->company_id,
            (int) $movement->reversal_of_id,
            $qtyBase,
            $cutoffMovementId,
            (int) $return->purchase_id,
            (int) $line->purchase_line_id
        );

        if ($requirePosted) {
            if ($allocation->historical_value_base === null
                || ! BigDecimal::of((string) $allocation->historical_value_base)->isEqualTo($expectedTarget)) {
                $this->fail();
            }
        }

        $actualRemoved = BigDecimal::of((string) $movement->value_delta_base)->abs();

        if ($requirePosted) {
            if ($allocation->inventory_value_removed_base === null
                || ! BigDecimal::of((string) $allocation->inventory_value_removed_base)->isEqualTo($actualRemoved)) {
                $this->fail();
            }

            // Also validate line-level historical receipt value and signed valuation adjustment
            $allAllocations = PurchaseReturnAllocation::withoutGlobalScopes()
                ->where('company_id', $return->company_id)
                ->where('purchase_return_line_id', $line->id)
                ->get();

            $sumAllocHistorical = BigDecimal::zero();
            $sumAllocActual = BigDecimal::zero();

            foreach ($allAllocations as $a) {
                if ($a->stock_movement_id === null
                    || $a->historical_value_base === null
                    || $a->inventory_value_removed_base === null) {
                    $this->fail();
                }
                $sumAllocHistorical = $sumAllocHistorical->plus($a->historical_value_base);
                $sumAllocActual = $sumAllocActual->plus($a->inventory_value_removed_base);
            }

            if ($line->stock_movement_id === null
                || $line->historical_receipt_value_base === null
                || ! BigDecimal::of((string) $line->historical_receipt_value_base)->isEqualTo($sumAllocHistorical)) {
                $this->fail();
            }

            if ($line->inventory_value_removed_base === null
                || ! BigDecimal::of((string) $line->inventory_value_removed_base)->isEqualTo($sumAllocActual)) {
                $this->fail();
            }

            $commercialH = BigDecimal::of((string) $line->line_total_base)
                ->minus($line->purchase_tax_account_id !== null ? (string) $line->line_tax_base : '0');
            $expectedAdjustment = $sumAllocActual->minus($commercialH);

            if ($line->valuation_adjustment_base === null
                || ! BigDecimal::of((string) $line->valuation_adjustment_base)->isEqualTo($expectedAdjustment)) {
                $this->fail();
            }
        }

        return $actualRemoved;
    }

    public static function validatePostedReturnIntegrity(PurchaseReturn $return): void
    {
        app(PurchaseReturnPostedIntegrityValidator::class)->validate($return);
    }

    private function fail(): never
    {
        throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
    }
}
