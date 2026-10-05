<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\StockMovement;
use App\Models\Vendor;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Shared read-only posted Purchase Return integrity validator.
 * Validates immutable document quantities, allocations, movements, snapshots,
 * and chronological commercial history.
 */
final class PurchaseReturnPostedIntegrityValidator
{
    /** @var list<string> */
    private const COMPONENTS = ['subtotal', 'discount', 'tax', 'total'];

    public function validate(PurchaseReturn $return): void
    {
        if ($return->status !== PurchaseReturn::STATUS_POSTED
            || ! $return->return_number
            || trim($return->return_number) === ''
            || $return->posted_at === null
            || $return->posted_by === null) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        /** @var Purchase|null $purchase */
        $purchase = Purchase::withoutGlobalScopes()
            ->where('company_id', $return->company_id)
            ->find($return->purchase_id);

        if ($purchase === null || ! $purchase->isPosted()) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        app(PurchasePostingCommandBuilder::class)->validatePosted($purchase);

        if ((int) $return->vendor_id !== (int) $purchase->vendor_id
            || (int) $return->warehouse_id !== (int) $purchase->warehouse_id
            || $return->currency_code !== $purchase->currency_code
            || $return->base_currency_code !== $purchase->base_currency_code
            || ! BigDecimal::of((string) $return->exchange_rate)->isEqualTo(BigDecimal::of((string) $purchase->exchange_rate))
            || $return->return_date->lt($purchase->purchase_date)) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        $vendor = Vendor::withTrashed()->where('company_id', $return->company_id)->find($return->vendor_id);
        $warehouse = Warehouse::where('company_id', $return->company_id)->find($return->warehouse_id);
        if ($vendor === null || $warehouse === null) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        $firstMovementId = StockMovement::withoutGlobalScopes()
            ->where('company_id', $return->company_id)
            ->where('source_type', 'purchase_return')
            ->where('source_id', $return->id)
            ->min('id');

        if ($firstMovementId === null) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        $lines = $return->lines()->withoutGlobalScopes()->orderBy('id')->get();
        if ($lines->isEmpty()) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        $seen = [];
        $totalsCurrency = [
            'subtotal' => BigDecimal::zero(),
            'discount' => BigDecimal::zero(),
            'tax' => BigDecimal::zero(),
            'total' => BigDecimal::zero(),
        ];
        $totalsBase = [
            'subtotal' => BigDecimal::zero(),
            'discount' => BigDecimal::zero(),
            'tax' => BigDecimal::zero(),
            'total' => BigDecimal::zero(),
        ];
        $currencyScale = $return->currency_code === 'JOD' ? 3 : 2;
        $totalReturnMovementsCount = 0;

        foreach ($lines as $line) {
            if (isset($seen[$line->purchase_line_id])) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
            $seen[$line->purchase_line_id] = true;

            if ((int) $line->company_id !== (int) $return->company_id) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            /** @var PurchaseLine|null $originalLine */
            $originalLine = PurchaseLine::withoutGlobalScopes()
                ->where('company_id', $return->company_id)
                ->where('purchase_id', $purchase->id)
                ->find($line->purchase_line_id);

            if ($originalLine === null) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            // Verify stored snapshots matching original purchase line
            if ((int) $line->product_id !== (int) $originalLine->product_id
                || (int) $line->product_unit_id !== (int) $originalLine->product_unit_id
                || ! BigDecimal::of((string) $line->unit_cost)->isEqualTo(BigDecimal::of((string) $originalLine->unit_cost))
                || $line->discount_type !== $originalLine->discount_type
                || ! BigDecimal::of((string) $line->discount_value)->isEqualTo(BigDecimal::of((string) $originalLine->discount_value))
                || $line->tax_rate_snapshot !== $originalLine->tax_rate_snapshot
                || (bool) $line->tax_inclusive !== (bool) $originalLine->tax_inclusive
                || $line->purchase_tax_account_id !== $originalLine->purchase_tax_account_id
                || ! BigDecimal::of((string) $line->unit_conversion_ratio)->isEqualTo(BigDecimal::of((string) $originalLine->unit_conversion_ratio))) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            $lineQty = BigDecimal::of((string) $line->quantity);
            if ($lineQty->isLessThanOrEqualTo(0)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            $conversionRatio = BigDecimal::of((string) $line->unit_conversion_ratio);
            if ($conversionRatio->isLessThanOrEqualTo(0)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            $expectedLineQtyBase = $lineQty->multipliedBy($conversionRatio)->toScale(6);
            if (! BigDecimal::of((string) $line->quantity_base)->isEqualTo($expectedLineQtyBase)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            // Prior posted return lines for original line at event chronological cutoff
            $priorLines = PurchaseReturnLine::withoutGlobalScopes()
                ->where('company_id', $return->company_id)
                ->where('purchase_line_id', $originalLine->id)
                ->where('purchase_return_id', '!=', $return->id)
                ->whereHas('purchaseReturn', fn ($q) => $q->where('status', PurchaseReturn::STATUS_POSTED))
                ->where('stock_movement_id', '<', (int) $firstMovementId)
                ->get();

            $priorQty = BigDecimal::zero();
            foreach ($priorLines as $prev) {
                $priorQty = $priorQty->plus($prev->quantity);
            }

            $origQty = BigDecimal::of((string) $originalLine->quantity);
            $remainingQty = $origQty->minus($priorQty);

            if ($lineQty->isGreaterThan($remainingQty)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            // Validate commercial component amounts against original and prior history
            foreach (self::COMPONENTS as $field) {
                $col = 'line_'.$field;
                $colBase = $col.'_base';

                // Currency
                $priorCurrency = BigDecimal::zero();
                foreach ($priorLines as $prev) {
                    $priorCurrency = $priorCurrency->plus((string) $prev->getAttribute($col));
                }
                $origCurrency = BigDecimal::of((string) $originalLine->getAttribute($col));
                $remainingCurrency = $origCurrency->minus($priorCurrency);

                if ($lineQty->isEqualTo($remainingQty)) {
                    $expectedCurrency = $remainingCurrency;
                } else {
                    $expectedCurrency = $remainingCurrency->multipliedBy($lineQty)
                        ->dividedBy($remainingQty, $currencyScale, RoundingMode::HALF_UP);
                }
                $expectedCurrencyScaled = $expectedCurrency->toScale(6);

                if (! BigDecimal::of((string) $line->getAttribute($col))->isEqualTo($expectedCurrencyScaled)) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }
                $totalsCurrency[$field] = $totalsCurrency[$field]->plus($expectedCurrencyScaled);

                // Base
                $priorBase = BigDecimal::zero();
                foreach ($priorLines as $prev) {
                    $priorBase = $priorBase->plus((string) $prev->getAttribute($colBase));
                }
                $origBase = BigDecimal::of((string) $originalLine->getAttribute($colBase));
                $remainingBase = $origBase->minus($priorBase);

                if ($lineQty->isEqualTo($remainingQty)) {
                    $expectedBase = $remainingBase;
                } else {
                    $expectedBase = $remainingBase->multipliedBy($lineQty)
                        ->dividedBy($remainingQty, 6, RoundingMode::HALF_UP);
                }
                $expectedBaseScaled = $expectedBase->toScale(6);

                if (! BigDecimal::of((string) $line->getAttribute($colBase))->isEqualTo($expectedBaseScaled)) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }
                $totalsBase[$field] = $totalsBase[$field]->plus($expectedBaseScaled);
            }

            // Allocations and movements validation
            $allocations = $line->allocations()->withoutGlobalScopes()->orderBy('id')->get();
            if ($allocations->isEmpty()) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            $movements = StockMovement::withoutGlobalScopes()
                ->where('company_id', $return->company_id)
                ->where('source_type', 'purchase_return')
                ->where('source_id', $return->id)
                ->where('source_line_id', $line->id)
                ->orderBy('id')
                ->get();

            if ($movements->count() !== $allocations->count()) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            if ((int) $line->stock_movement_id !== (int) $movements->first()->id) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            $allocSumQty = BigDecimal::zero();
            $allocSumQtyBase = BigDecimal::zero();
            $allocSumHistorical = BigDecimal::zero();
            $allocSumActual = BigDecimal::zero();
            $movementSumQtyBase = BigDecimal::zero();
            $movementSumActual = BigDecimal::zero();

            foreach ($allocations as $idx => $alloc) {
                if ((int) $alloc->company_id !== (int) $return->company_id
                    || (int) $alloc->purchase_return_id !== (int) $return->id
                    || (int) $alloc->purchase_return_line_id !== (int) $line->id
                    || $alloc->stock_movement_id === null
                    || $alloc->historical_value_base === null
                    || $alloc->inventory_value_removed_base === null) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }

                $aQty = BigDecimal::of((string) $alloc->quantity);
                $aQtyBase = BigDecimal::of((string) $alloc->quantity_base);
                if ($aQty->isLessThanOrEqualTo(0) || $aQtyBase->isLessThanOrEqualTo(0)) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }

                if (! $aQtyBase->isEqualTo($aQty->multipliedBy($conversionRatio)->toScale(6))) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }

                $mov = $movements[$idx];
                if ((int) $alloc->stock_movement_id !== (int) $mov->id
                    || (int) $mov->company_id !== (int) $return->company_id
                    || (int) $mov->warehouse_id !== (int) $return->warehouse_id
                    || (int) $mov->product_id !== (int) $line->product_id
                    || (int) $mov->reversal_of_id !== (int) $alloc->original_stock_movement_id) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }

                if ($alloc->inventory_lot_id !== null) {
                    if ((int) $mov->lot_id !== (int) $alloc->inventory_lot_id) {
                        throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                    }
                } else {
                    if ($mov->lot_id !== null) {
                        throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                    }
                }

                $mQtyBase = BigDecimal::of((string) $mov->quantity_delta_base)->abs();
                $mValBase = BigDecimal::of((string) $mov->value_delta_base)->abs();

                if (! $mQtyBase->isEqualTo($aQtyBase)
                    || ! $mValBase->isEqualTo(BigDecimal::of((string) $alloc->inventory_value_removed_base))) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }

                PurchaseReturnStockProvenance::validateAllocationLotChain(
                    (int) $return->company_id,
                    (int) $return->warehouse_id,
                    (int) $purchase->id,
                    $originalLine,
                    $alloc,
                    $mov
                );

                $expectedTarget = app(HistoricalPurchaseReceiptValue::class)->target(
                    companyId: (int) $return->company_id,
                    originalMovementId: (int) $mov->reversal_of_id,
                    requestedBaseQty: $aQtyBase,
                    beforeMovementId: (int) $mov->id,
                    expectedPurchaseId: (int) $return->purchase_id,
                    expectedPurchaseLineId: (int) $line->purchase_line_id,
                    expectedProductId: (int) $line->product_id,
                    expectedWarehouseId: (int) $return->warehouse_id,
                    expectedLotId: $alloc->inventory_lot_id
                );

                if (! BigDecimal::of((string) $alloc->historical_value_base)->isEqualTo($expectedTarget)) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }

                $allocSumQty = $allocSumQty->plus($aQty);
                $allocSumQtyBase = $allocSumQtyBase->plus($aQtyBase);
                $allocSumHistorical = $allocSumHistorical->plus($alloc->historical_value_base);
                $allocSumActual = $allocSumActual->plus($alloc->inventory_value_removed_base);
                $movementSumQtyBase = $movementSumQtyBase->plus($mQtyBase);
                $movementSumActual = $movementSumActual->plus($mValBase);
            }

            if (! $allocSumQty->isEqualTo($lineQty)
                || ! $allocSumQtyBase->isEqualTo($expectedLineQtyBase)
                || ! $movementSumQtyBase->isEqualTo($expectedLineQtyBase)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            if ($line->stock_movement_id === null
                || $line->historical_receipt_value_base === null
                || ! BigDecimal::of((string) $line->historical_receipt_value_base)->isEqualTo($allocSumHistorical)
                || $line->inventory_value_removed_base === null
                || ! BigDecimal::of((string) $line->inventory_value_removed_base)->isEqualTo($allocSumActual)
                || ! BigDecimal::of((string) $line->inventory_value_removed_base)->isEqualTo($movementSumActual)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            $commercialH = BigDecimal::of((string) $line->line_total_base)
                ->minus($line->purchase_tax_account_id !== null ? (string) $line->line_tax_base : '0');
            $expectedAdjustment = $allocSumActual->minus($commercialH);

            if ($line->valuation_adjustment_base === null
                || ! BigDecimal::of((string) $line->valuation_adjustment_base)->isEqualTo($expectedAdjustment)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            $totalReturnMovementsCount += $movements->count();
        }

        // Validate header totals
        $headerMap = [
            'subtotal' => 'subtotal',
            'discount' => 'discount_total',
            'tax' => 'tax_total',
            'total' => 'grand_total',
        ];

        foreach ($headerMap as $field => $header) {
            $expectedCur = $totalsCurrency[$field]->toScale(6);
            $storedCur = BigDecimal::of((string) $return->getAttribute($header.'_currency'));
            if (! $storedCur->isEqualTo($expectedCur)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            $expectedB = $totalsBase[$field]->toScale(6);
            $storedB = BigDecimal::of((string) $return->getAttribute($header.'_base'));
            if (! $storedB->isEqualTo($expectedB)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
        }

        $allMovementsCount = StockMovement::withoutGlobalScopes()
            ->where('company_id', $return->company_id)
            ->where('source_type', 'purchase_return')
            ->where('source_id', $return->id)
            ->count();

        if ($allMovementsCount !== $totalReturnMovementsCount) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }
    }
}
