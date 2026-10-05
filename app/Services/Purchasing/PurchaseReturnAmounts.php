<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Historical commercial allocation and limitations for Purchase Returns.
 * Derives strictly from original Posted Purchase and prior Posted Returns.
 * Allocates base components independently (NOT return_currency * FX).
 */
final class PurchaseReturnAmounts
{
    /** @var list<string> */
    private const COMPONENTS = ['subtotal', 'discount', 'tax', 'total'];

    public function refresh(PurchaseReturn $return): void
    {
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
        $seen = [];

        foreach ($return->lines()->lockForUpdate()->get() as $line) {
            if (isset($seen[$line->purchase_line_id])) {
                throw new InvalidArgumentException('Combine repeated original purchase-line quantities into one return line.');
            }
            $seen[$line->purchase_line_id] = true;

            /** @var PurchaseLine $original */
            $original = PurchaseLine::where('company_id', $return->company_id)
                ->where('purchase_id', $return->purchase_id)
                ->whereKey($line->purchase_line_id)
                ->lockForUpdate()
                ->firstOrFail();

            $prior = PurchaseReturnLine::where('company_id', $return->company_id)
                ->where('purchase_line_id', $original->id)
                ->where('purchase_return_id', '!=', $return->id)
                ->whereHas('purchaseReturn', fn ($q) => $q->where('status', PurchaseReturn::STATUS_POSTED))
                ->get();

            $priorQty = BigDecimal::zero();
            foreach ($prior as $prev) {
                $priorQty = $priorQty->plus($prev->quantity);
            }

            $lineQty = BigDecimal::of((string) $line->quantity);
            $originalQty = BigDecimal::of((string) $original->quantity);
            $remainingQty = $originalQty->minus($priorQty);

            if ($lineQty->isLessThanOrEqualTo(0)) {
                throw new InvalidArgumentException('Return line quantity must be strictly positive.');
            }

            if ($lineQty->isGreaterThan($remainingQty)) {
                throw new PurchaseReturnValidationException(
                    'purchasing.return_quantity_exceeds_remaining',
                    "Requested return quantity [{$lineQty}] exceeds remaining returnable quantity [{$remainingQty}]."
                );
            }

            $conversionRatio = BigDecimal::of((string) $line->unit_conversion_ratio);
            $line->quantity_base = (string) $lineQty->multipliedBy($conversionRatio)->toScale(6);

            foreach (self::COMPONENTS as $field) {
                $col = 'line_'.$field;

                // 1. Transaction currency allocation
                $priorCurrency = BigDecimal::zero();
                foreach ($prior as $prev) {
                    $priorCurrency = $priorCurrency->plus((string) $prev->getAttribute($col));
                }
                $origCurrency = BigDecimal::of((string) $original->getAttribute($col));
                $remainingCurrency = $origCurrency->minus($priorCurrency);

                if ($lineQty->isEqualTo($remainingQty)) {
                    $allocatedCurrency = $remainingCurrency;
                } else {
                    $allocatedCurrency = $remainingCurrency->multipliedBy($lineQty)
                        ->dividedBy($remainingQty, $currencyScale, RoundingMode::HALF_UP);
                }
                $allocatedCurrencyScaled = $allocatedCurrency->toScale(6);
                $line->setAttribute($col, (string) $allocatedCurrencyScaled);
                $totalsCurrency[$field] = $totalsCurrency[$field]->plus($allocatedCurrencyScaled);

                // 2. Authoritative independent base component allocation (NOT return_currency * FX)
                $colBase = $col.'_base';
                $priorBase = BigDecimal::zero();
                foreach ($prior as $prev) {
                    $priorBase = $priorBase->plus((string) $prev->getAttribute($colBase));
                }
                $origBase = BigDecimal::of((string) $original->getAttribute($colBase));
                $remainingBase = $origBase->minus($priorBase);

                if ($lineQty->isEqualTo($remainingQty)) {
                    $allocatedBase = $remainingBase;
                } else {
                    $allocatedBase = $remainingBase->multipliedBy($lineQty)
                        ->dividedBy($remainingQty, 6, RoundingMode::HALF_UP);
                }
                $allocatedBaseScaled = $allocatedBase->toScale(6);
                $line->setAttribute($colBase, (string) $allocatedBaseScaled);
                $totalsBase[$field] = $totalsBase[$field]->plus($allocatedBaseScaled);
            }

            $line->save();
        }

        $headerMap = [
            'subtotal' => 'subtotal',
            'discount' => 'discount_total',
            'tax' => 'tax_total',
            'total' => 'grand_total',
        ];

        foreach ($headerMap as $field => $header) {
            $return->setAttribute($header.'_currency', (string) $totalsCurrency[$field]->toScale(6));
            $return->setAttribute($header.'_base', (string) $totalsBase[$field]->toScale(6));
        }

        $return->save();
        $return->unsetRelation('lines');
    }

    public function validateDraftIntegrity(PurchaseReturn $return, Purchase $purchase): void
    {
        if ((int) $return->purchase_id !== (int) $purchase->id
            || (int) $return->company_id !== (int) $purchase->company_id
            || (int) $return->vendor_id !== (int) $purchase->vendor_id
            || (int) $return->warehouse_id !== (int) $purchase->warehouse_id
            || $return->currency_code !== $purchase->currency_code
            || $return->base_currency_code !== $purchase->base_currency_code
            || ! BigDecimal::of((string) $return->exchange_rate)->isEqualTo(BigDecimal::of((string) $purchase->exchange_rate))) {
            throw new InvalidArgumentException('Draft header does not match original purchase provenance.');
        }

        $currencyScale = $return->currency_code === 'JOD' ? 3 : 2;
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

        $lines = $return->lines()->withoutGlobalScopes()->get();
        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Purchase return must contain at least one line.');
        }

        foreach ($lines as $line) {
            if (isset($seen[$line->purchase_line_id])) {
                throw new InvalidArgumentException('Combine repeated original purchase-line quantities into one return line.');
            }
            $seen[$line->purchase_line_id] = true;

            /** @var PurchaseLine|null $original */
            $original = PurchaseLine::where('company_id', $return->company_id)
                ->where('purchase_id', $return->purchase_id)
                ->whereKey($line->purchase_line_id)
                ->first();

            if ($original === null) {
                throw new InvalidArgumentException('Return line does not belong to original purchase.');
            }

            // Verify immutable snapshots on line matching original purchase line
            if ((int) $line->product_id !== (int) $original->product_id
                || (int) $line->product_unit_id !== (int) $original->product_unit_id
                || ! BigDecimal::of((string) $line->unit_cost)->isEqualTo(BigDecimal::of((string) $original->unit_cost))
                || $line->discount_type !== $original->discount_type
                || ! BigDecimal::of((string) $line->discount_value)->isEqualTo(BigDecimal::of((string) $original->discount_value))
                || $line->tax_rate_snapshot !== $original->tax_rate_snapshot
                || (bool) $line->tax_inclusive !== (bool) $original->tax_inclusive
                || $line->purchase_tax_account_id !== $original->purchase_tax_account_id
                || ! BigDecimal::of((string) $line->unit_conversion_ratio)->isEqualTo(BigDecimal::of((string) $original->unit_conversion_ratio))) {
                throw new InvalidArgumentException('Return line economic snapshots do not match original purchase line.');
            }

            // Must have NO provenance fields on draft
            if ($line->stock_movement_id !== null
                || $line->historical_receipt_value_base !== null
                || $line->inventory_value_removed_base !== null
                || $line->valuation_adjustment_base !== null) {
                throw new InvalidArgumentException('Purchase return draft lines cannot carry inventory or valuation effects.');
            }

            $lineQty = BigDecimal::of((string) $line->quantity);
            if ($lineQty->isLessThanOrEqualTo(0)) {
                throw new InvalidArgumentException('Return line quantity must be strictly positive.');
            }

            $conversionRatio = BigDecimal::of((string) $line->unit_conversion_ratio);
            $expectedQtyBase = $lineQty->multipliedBy($conversionRatio)->toScale(6);
            if (! BigDecimal::of((string) $line->quantity_base)->isEqualTo($expectedQtyBase)) {
                throw new InvalidArgumentException('Return line base quantity does not match conversion ratio.');
            }

            $prior = PurchaseReturnLine::where('company_id', $return->company_id)
                ->where('purchase_line_id', $original->id)
                ->where('purchase_return_id', '!=', $return->id)
                ->whereHas('purchaseReturn', fn ($q) => $q->where('status', PurchaseReturn::STATUS_POSTED))
                ->get();

            $priorQty = BigDecimal::zero();
            foreach ($prior as $prev) {
                $priorQty = $priorQty->plus($prev->quantity);
            }

            $originalQty = BigDecimal::of((string) $original->quantity);
            $remainingQty = $originalQty->minus($priorQty);

            if ($lineQty->isGreaterThan($remainingQty)) {
                throw new PurchaseReturnValidationException(
                    'purchasing.return_quantity_exceeds_remaining',
                    "Requested return quantity [{$lineQty}] exceeds remaining returnable quantity [{$remainingQty}]."
                );
            }

            // Check allocations
            $allocations = $line->allocations()->withoutGlobalScopes()->get();
            if ($allocations->isEmpty()) {
                throw new InvalidArgumentException('Purchase return lines must have allocations.');
            }

            $allocSumDoc = BigDecimal::zero();
            $allocSumBase = BigDecimal::zero();

            foreach ($allocations as $alloc) {
                if ((int) $alloc->company_id !== (int) $return->company_id
                    || (int) $alloc->purchase_return_id !== (int) $return->id
                    || (int) $alloc->purchase_return_line_id !== (int) $line->id) {
                    throw new InvalidArgumentException('Allocation tenant or line provenance mismatch.');
                }

                if ($alloc->stock_movement_id !== null
                    || $alloc->historical_value_base !== null
                    || $alloc->inventory_value_removed_base !== null) {
                    throw new InvalidArgumentException('Purchase return draft allocations cannot carry inventory or valuation effects.');
                }

                $aQty = BigDecimal::of((string) $alloc->quantity);
                $aQtyBase = BigDecimal::of((string) $alloc->quantity_base);

                if ($aQty->isLessThanOrEqualTo(0) || $aQtyBase->isLessThanOrEqualTo(0)) {
                    throw new InvalidArgumentException('Allocation quantity must be strictly positive.');
                }

                if (! $aQtyBase->isEqualTo($aQty->multipliedBy($conversionRatio)->toScale(6))) {
                    throw new InvalidArgumentException('Allocation base quantity does not match conversion ratio.');
                }

                // Expiry vs non-expiry complete lot chain provenance check (C2-3)
                PurchaseReturnStockProvenance::validateAllocationLotChain(
                    (int) $return->company_id,
                    (int) $return->warehouse_id,
                    (int) $return->purchase_id,
                    $original,
                    $alloc
                );

                $allocSumDoc = $allocSumDoc->plus($aQty);
                $allocSumBase = $allocSumBase->plus($aQtyBase);
            }

            if (! $allocSumDoc->isEqualTo($lineQty)) {
                throw new InvalidArgumentException("Allocation quantity sum [{$allocSumDoc}] does not equal line quantity [{$lineQty}].");
            }
            if (! $allocSumBase->isEqualTo($expectedQtyBase)) {
                throw new InvalidArgumentException("Allocation base quantity sum [{$allocSumBase}] does not equal line base quantity [{$expectedQtyBase}].");
            }

            // Check economic component amounts for the line
            foreach (self::COMPONENTS as $field) {
                $col = 'line_'.$field;

                // 1. Transaction currency allocation
                $priorCurrency = BigDecimal::zero();
                foreach ($prior as $prev) {
                    $priorCurrency = $priorCurrency->plus((string) $prev->getAttribute($col));
                }
                $origCurrency = BigDecimal::of((string) $original->getAttribute($col));
                $remainingCurrency = $origCurrency->minus($priorCurrency);

                if ($lineQty->isEqualTo($remainingQty)) {
                    $expectedCurrency = $remainingCurrency;
                } else {
                    $expectedCurrency = $remainingCurrency->multipliedBy($lineQty)
                        ->dividedBy($remainingQty, $currencyScale, RoundingMode::HALF_UP);
                }
                $expectedCurrencyScaled = $expectedCurrency->toScale(6);

                $storedCurrency = BigDecimal::of((string) $line->getAttribute($col));
                if (! $storedCurrency->isEqualTo($expectedCurrencyScaled)) {
                    throw new InvalidArgumentException("Line [{$line->id}] component [{$col}] stored [{$storedCurrency}] != expected [{$expectedCurrencyScaled}].");
                }
                $totalsCurrency[$field] = $totalsCurrency[$field]->plus($expectedCurrencyScaled);

                // 2. Base component allocation
                $colBase = $col.'_base';
                $priorBase = BigDecimal::zero();
                foreach ($prior as $prev) {
                    $priorBase = $priorBase->plus((string) $prev->getAttribute($colBase));
                }
                $origBase = BigDecimal::of((string) $original->getAttribute($colBase));
                $remainingBase = $origBase->minus($priorBase);

                if ($lineQty->isEqualTo($remainingQty)) {
                    $expectedBase = $remainingBase;
                } else {
                    $expectedBase = $remainingBase->multipliedBy($lineQty)
                        ->dividedBy($remainingQty, 6, RoundingMode::HALF_UP);
                }
                $expectedBaseScaled = $expectedBase->toScale(6);

                $storedBase = BigDecimal::of((string) $line->getAttribute($colBase));
                if (! $storedBase->isEqualTo($expectedBaseScaled)) {
                    throw new InvalidArgumentException("Line [{$line->id}] component [{$colBase}] stored [{$storedBase}] != expected [{$expectedBaseScaled}].");
                }
                $totalsBase[$field] = $totalsBase[$field]->plus($expectedBaseScaled);
            }
        }

        // Check header totals match sum of lines
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
                throw new InvalidArgumentException("Header [{$header}_currency] stored [{$storedCur}] != expected [{$expectedCur}].");
            }

            $expectedB = $totalsBase[$field]->toScale(6);
            $storedB = BigDecimal::of((string) $return->getAttribute($header.'_base'));
            if (! $storedB->isEqualTo($expectedB)) {
                throw new InvalidArgumentException("Header [{$header}_base] stored [{$storedB}] != expected [{$expectedB}].");
            }
        }
    }
}
