<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Derives exact attributable historical receipt value for purchase returns,
 * guaranteeing cumulative limits and exact final residual.
 */
final class HistoricalPurchaseReceiptValue
{
    public function target(
        int $companyId,
        int $originalMovementId,
        BigDecimal $requestedBaseQty,
        ?int $beforeMovementId = null,
        ?int $expectedPurchaseId = null,
        ?int $expectedPurchaseLineId = null,
        ?int $expectedProductId = null,
        ?int $expectedWarehouseId = null,
        ?int $expectedLotId = null
    ): BigDecimal {
        /** @var StockMovement|null $original */
        $original = StockMovement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($originalMovementId);

        if ($original === null || $original->movement_type !== StockMovement::TYPE_PURCHASE || $original->source_type !== 'purchase') {
            throw new InvalidInventoryMovementException('Historical purchase return must reference the original same-company purchase receipt movement.');
        }

        /** @var Purchase|null $purchase */
        $purchase = Purchase::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($original->source_id);

        if ($purchase === null) {
            throw new InvalidInventoryMovementException('Historical purchase is missing.');
        }

        if ($expectedPurchaseId !== null && (int) $purchase->id !== $expectedPurchaseId) {
            throw new InvalidInventoryMovementException('Original receipt movement does not belong to the return purchase.');
        }

        if ($beforeMovementId === null && ! $purchase->isPosted()) {
            throw new InvalidInventoryMovementException('Only a posted purchase can be returned.');
        }

        /** @var PurchaseLine|null $purchaseLine */
        $purchaseLine = PurchaseLine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('purchase_id', $purchase->id)
            ->find($original->source_line_id);

        if ($purchaseLine === null) {
            throw new InvalidInventoryMovementException('Original purchase line is missing.');
        }

        if ($expectedPurchaseLineId !== null && (int) $purchaseLine->id !== $expectedPurchaseLineId) {
            throw new InvalidInventoryMovementException('Original receipt movement does not belong to the return purchase line.');
        }

        if ($expectedProductId !== null && (int) $original->product_id !== $expectedProductId) {
            throw new InvalidInventoryMovementException('Original receipt movement does not belong to the return product.');
        }

        if ($expectedWarehouseId !== null && (int) $original->warehouse_id !== $expectedWarehouseId) {
            throw new InvalidInventoryMovementException('Original receipt movement does not belong to the return warehouse.');
        }

        if ($expectedLotId !== null && (int) $original->lot_id !== $expectedLotId) {
            throw new InvalidInventoryMovementException('Original receipt movement does not belong to the return lot.');
        }

        $originalReceiptQty = BigDecimal::of((string) $original->quantity_delta_base)->abs();
        $originalReceiptVal = BigDecimal::of((string) $original->value_delta_base)->abs();

        $query = PurchaseReturnAllocation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('original_stock_movement_id', $originalMovementId);

        if ($beforeMovementId !== null) {
            $query->whereNotNull('stock_movement_id')
                ->where('stock_movement_id', '<', $beforeMovementId)
                ->whereHas('purchaseReturn', fn ($q) => $q->where('status', PurchaseReturn::STATUS_POSTED));
        } else {
            $query->whereHas('purchaseReturn', fn ($q) => $q->where('status', PurchaseReturn::STATUS_POSTED));
        }

        $priorQty = BigDecimal::zero();
        $priorVal = BigDecimal::zero();

        foreach ($query->get() as $alloc) {
            $priorQty = $priorQty->plus($alloc->quantity_base);
            $priorVal = $priorVal->plus($alloc->historical_value_base ?? '0');
        }

        $remainingQty = $originalReceiptQty->minus($priorQty);
        $remainingVal = $originalReceiptVal->minus($priorVal);

        if ($requestedBaseQty->isLessThanOrEqualTo(0)) {
            throw new InvalidInventoryMovementException('Requested return quantity must be positive.');
        }

        if ($remainingQty->isNegative() || $remainingVal->isNegative()) {
            throw new InvalidInventoryMovementException('Negative remaining receipt quantity or value detected.');
        }

        if ($requestedBaseQty->isGreaterThan($remainingQty)) {
            throw new InvalidInventoryMovementException('Requested return quantity exceeds original receipt remaining quantity.');
        }

        if ($requestedBaseQty->isEqualTo($remainingQty)) {
            $target = $remainingVal;
        } else {
            $target = $remainingVal->multipliedBy($requestedBaseQty)->dividedBy($remainingQty, 6, RoundingMode::HALF_UP);
        }

        return $target->toScale(6);
    }
}
