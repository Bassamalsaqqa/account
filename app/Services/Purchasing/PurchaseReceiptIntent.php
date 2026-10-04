<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Models\Purchase;
use App\Models\TaxRate;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** A new Purchase movement must be the exact receipt intent of its persisted Draft line. */
final class PurchaseReceiptIntent
{
    public function validate(StockMovementCommand $command): void
    {
        $purchase = Purchase::where('company_id', $command->companyId)->lockForUpdate()->findOrFail($command->sourceId);
        $purchase->assertMutableDraft();
        $line = $purchase->lines()->where('company_id', $command->companyId)->lockForUpdate()->findOrFail($command->sourceLineId);
        $line->load('lots', 'productUnit');
        $tax = $line->tax_rate_id === null ? null : TaxRate::where('company_id', $command->companyId)->where('active', true)->lockForUpdate()->findOrFail($line->tax_rate_id);
        $account = app(PurchaseInputTaxAccount::class)->resolve($command->companyId, $tax?->purchase_tax_account_id);
        $value = app(PurchaseAcquisitionValue::class)->line($line, $account?->id);
        $cost = app(PurchaseAcquisitionValue::class)->unitCost($line, $value);
        $values = $line->lots->isEmpty() ? [$value] : app(PurchaseAcquisitionValue::class)->lots($line, $value);
        if ($command->movementDate !== $purchase->purchase_date->format('Y-m-d') || count($command->lines) !== count($values)
            || $line->stock_movement_id !== null || $line->inventory_unit_cost_base !== null) {
            $this->fail();
        }
        foreach ($command->lines as $index => $part) {
            $quantity = $line->quantity;
            $lotNumber = null;
            $expiryDate = null;
            if ($line->lots->isNotEmpty()) {
                $lot = $line->lots->get($index);
                if ($lot === null) {
                    $this->fail();
                }
                $quantity = $lot->quantity;
                $lotNumber = $lot->lot_number;
                $expiryDate = $lot->expiry_date?->format('Y-m-d');
            }
            if ($part->productId !== (int) $line->product_id || $part->warehouseId !== (int) $purchase->warehouse_id
                || $part->unitId !== (int) $line->productUnit->unit_id || $part->lotId !== null
                || ! $part->quantity->toBigDecimal()->isEqualTo($quantity)
                || ! BigDecimal::of($part->unitCostBase)->isEqualTo($cost)
                || ! BigDecimal::of($part->valueDeltaBase)->isEqualTo($values[$index])
                || $part->lotNumber !== $lotNumber || $part->expiryDate !== $expiryDate) {
                $this->fail();
            }
        }
    }

    private function fail(): never
    {
        throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
    }
}
