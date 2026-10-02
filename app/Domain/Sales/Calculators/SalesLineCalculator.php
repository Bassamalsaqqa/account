<?php

declare(strict_types=1);

namespace App\Domain\Sales\Calculators;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class SalesLineCalculator
{
    public const int INTERNAL_DECIMAL_SCALE = 6;

    /**
     * Calculate line money values deterministically using BigDecimal.
     */
    public function calculate(SalesLineCalculationInput $input): SalesLineCalculationResult
    {
        $minorUnits = $input->currencyMinorUnits;
        $baseMinorUnits = $input->baseCurrencyMinorUnits;

        // 1. Line subtotal = quantity * unit_price
        $rawSubtotal = $input->quantity->multipliedBy($input->unitPrice);
        $subtotal = $rawSubtotal->toScale($minorUnits, RoundingMode::HALF_UP);

        // 2. Line discount
        $discount = BigDecimal::zero();
        if ($input->discountType === 'percent') {
            if ($input->discountValue->isPositive()) {
                // percent / 100
                $percentFactor = $input->discountValue->dividedBy(BigDecimal::of(100), 8, RoundingMode::HALF_UP);
                $discount = $subtotal->multipliedBy($percentFactor)->toScale($minorUnits, RoundingMode::HALF_UP);
            }
        } elseif ($input->discountType === 'fixed') {
            if ($input->discountValue->isPositive()) {
                $discount = $input->discountValue->toScale($minorUnits, RoundingMode::HALF_UP);
            }
        }

        // Cap discount at subtotal, cannot be negative
        if ($discount->isGreaterThan($subtotal)) {
            $discount = $subtotal;
        }
        if ($discount->isNegative()) {
            $discount = BigDecimal::zero();
        }

        $netBeforeTax = $subtotal->minus($discount);

        // 3. Tax calculation
        $tax = BigDecimal::zero();
        $total = $netBeforeTax;

        if ($input->taxRate !== null && $input->taxRate->isPositive()) {
            $rate = $input->taxRate->dividedBy(100, 8);

            if ($input->taxInclusive) {
                // Inclusive: gross = netBeforeTax (discounted amount contains tax)
                // net = gross / (1 + rate)
                $onePlusRate = BigDecimal::one()->plus($rate);
                $actualNet = $netBeforeTax->dividedBy($onePlusRate, $minorUnits, RoundingMode::HALF_UP);
                $tax = $netBeforeTax->minus($actualNet);
                $netBeforeTax = $actualNet;
                // In inclusive tax, the line total charged to customer is the original discounted amount
                $total = $actualNet->plus($tax);
            } else {
                // Exclusive: tax = netBeforeTax * rate
                $tax = $netBeforeTax->multipliedBy($rate)->toScale($minorUnits, RoundingMode::HALF_UP);
                $total = $netBeforeTax->plus($tax);
            }
        }

        // 4. Base currency conversion
        $fx = $input->exchangeRate;
        $subtotalBase = $subtotal->multipliedBy($fx)->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::HALF_UP);
        $discountBase = $discount->multipliedBy($fx)->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::HALF_UP);
        $taxBase = $tax->multipliedBy($fx)->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::HALF_UP);
        $totalBase = $total->multipliedBy($fx)->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::HALF_UP);

        // 5. Normalize all monetary results to 6 decimal scale for database storage
        return new SalesLineCalculationResult(
            subtotal: $subtotal->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            discount: $discount->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            netBeforeTax: $netBeforeTax->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            tax: $tax->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            total: $total->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            subtotalBase: $subtotalBase->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            discountBase: $discountBase->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            taxBase: $taxBase->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            totalBase: $totalBase->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
        );
    }
}
