<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLotAllocation;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Exact, cumulative compensation against an immutable sale, including its final residual. */
final class HistoricalSaleCost
{
    public function value(int $companyId, int $originalId, int $productId, int $warehouseId, ?int $lotId, string $sourceType, int $sourceId, BigDecimal $quantity, ?int $beforeMovementId = null): BigDecimal
    {
        $original = StockMovement::withoutGlobalScopes()->where('company_id', $companyId)->find($originalId);
        if ($original === null || $original->movement_type !== StockMovement::TYPE_SALE || $original->source_type !== 'sales_invoice'
            || (int) $original->product_id !== $productId || (int) $original->warehouse_id !== $warehouseId
            || $original->lot_id !== $lotId) {
            throw new InvalidInventoryMovementException('Historical compensation must reference the original same-company product, warehouse and lot sale movement.');
        }
        $invoice = SalesInvoice::withoutGlobalScopes()->where('company_id', $companyId)->find($original->source_id);
        if ($invoice === null) {
            throw new InvalidInventoryMovementException('Historical sale invoice is missing.');
        }
        $allocation = SalesInvoiceLotAllocation::withoutGlobalScopes()->where('company_id', $companyId)->where('sales_invoice_id', $invoice->id)->where('stock_movement_id', $originalId)->first();
        if ($allocation === null || ! BigDecimal::of($allocation->quantity_allocated_base)->isEqualTo(BigDecimal::of($original->quantity_delta_base)->abs())
            || ! BigDecimal::of($allocation->total_cost_base)->isEqualTo(BigDecimal::of($original->value_delta_base)->abs())) {
            throw new InvalidInventoryMovementException('Original immutable sale allocation must agree with its movement.');
        }
        if ($beforeMovementId === null && ! $invoice->isPosted()) {
            throw new InvalidInventoryMovementException('Only a posted sale can be compensated.');
        }
        if ($sourceType === 'sales_return') {
            $return = SalesReturn::withoutGlobalScopes()->where('company_id', $companyId)->find($sourceId);
            if ($return === null || (int) $return->sales_invoice_id !== (int) $invoice->id) {
                throw new InvalidInventoryMovementException('Return must refer to the original sale invoice.');
            }
            if ($beforeMovementId === null) {
                if (! $return->isDraft()) {
                    throw new InvalidInventoryMovementException('Stock restoration requires the canonical draft return posting.');
                }
                $requested = SalesReturnLine::withoutGlobalScopes()->where('company_id', $companyId)->where('sales_return_id', $return->id)
                    ->where('sales_invoice_line_id', $allocation->sales_invoice_line_id)->sum('quantity_base');
                $consumed = BigDecimal::zero();
                foreach (StockMovement::withoutGlobalScopes()->where('company_id', $companyId)->where('source_type', 'sales_return')->where('source_id', $return->id)->get() as $restored) {
                    $soldAllocation = SalesInvoiceLotAllocation::withoutGlobalScopes()->where('company_id', $companyId)->where('stock_movement_id', $restored->reversal_of_id)->first();
                    if ($soldAllocation !== null && (int) $soldAllocation->sales_invoice_line_id === (int) $allocation->sales_invoice_line_id) {
                        $consumed = $consumed->plus($restored->quantity_delta_base);
                    }
                }
                if ($consumed->plus($quantity)->isGreaterThan((string) $requested)) {
                    throw new InvalidInventoryMovementException('Compensation exceeds the requested linked return line.');
                }
            }
        } elseif ($sourceType !== 'sales_invoice_void' || $sourceId !== (int) $invoice->id) {
            throw new InvalidInventoryMovementException('Unsupported historical compensation source.');
        }
        $prior = StockMovement::withoutGlobalScopes()->where('company_id', $companyId)->where('reversal_of_id', $originalId)
            ->where('movement_type', StockMovement::TYPE_SALE_RETURN);
        if ($beforeMovementId !== null) {
            $prior->where('id', '<', $beforeMovementId);
        }
        $usedQty = BigDecimal::zero();
        $usedValue = BigDecimal::zero();
        foreach ($prior->get() as $movement) {
            // A voided return has been removed again; its restoration is no longer outstanding.
            if ($movement->source_type === 'sales_return') {
                $voids = StockMovement::withoutGlobalScopes()->where('company_id', $companyId)->where('source_type', 'sales_return_void')
                    ->where('source_id', $movement->source_id);
                if ($beforeMovementId !== null) {
                    $voids->where('id', '<', $beforeMovementId);
                }
                if ($voids->exists()) {
                    continue;
                }
            }
            $usedQty = $usedQty->plus($movement->quantity_delta_base);
            $usedValue = $usedValue->plus($movement->value_delta_base);
        }
        $soldQty = BigDecimal::of((string) $original->quantity_delta_base)->abs();
        $soldValue = BigDecimal::of((string) $original->value_delta_base)->abs();
        $cumulativeQty = $usedQty->plus($quantity);
        if (! $quantity->isPositive() || $cumulativeQty->isGreaterThan($soldQty)) {
            throw new InvalidInventoryMovementException('Historical stock restoration exceeds the original sold quantity.');
        }
        $cumulativeValue = $cumulativeQty->isEqualTo($soldQty) ? $soldValue
            : $soldValue->multipliedBy($cumulativeQty)->dividedBy($soldQty, 6, RoundingMode::HALF_UP);

        return $cumulativeValue->minus($usedValue)->toScale(6);
    }
}
