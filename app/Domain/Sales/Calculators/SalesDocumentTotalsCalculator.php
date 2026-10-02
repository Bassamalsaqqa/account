<?php

declare(strict_types=1);

namespace App\Domain\Sales\Calculators;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class SalesDocumentTotalsCalculator
{
    public const int INTERNAL_DECIMAL_SCALE = 6;

    /**
     * @param  list<SalesLineCalculationResult>  $lineResults
     */
    public function calculate(array $lineResults, ?BigDecimal $exchangeRate = null): SalesDocumentTotalsCalculationResult
    {
        $subtotalCurrency = BigDecimal::zero();
        $discountTotalCurrency = BigDecimal::zero();
        $taxTotalCurrency = BigDecimal::zero();
        $grandTotalCurrency = BigDecimal::zero();

        $subtotalBase = BigDecimal::zero();
        $discountTotalBase = BigDecimal::zero();
        $taxTotalBase = BigDecimal::zero();
        $grandTotalBase = BigDecimal::zero();

        foreach ($lineResults as $line) {
            $subtotalCurrency = $subtotalCurrency->plus($line->subtotal);
            $discountTotalCurrency = $discountTotalCurrency->plus($line->discount);
            $taxTotalCurrency = $taxTotalCurrency->plus($line->tax);
            $grandTotalCurrency = $grandTotalCurrency->plus($line->total);

            $subtotalBase = $subtotalBase->plus($line->subtotalBase);
            $discountTotalBase = $discountTotalBase->plus($line->discountBase);
            $taxTotalBase = $taxTotalBase->plus($line->taxBase);
            $grandTotalBase = $grandTotalBase->plus($line->totalBase);
        }

        if ($exchangeRate !== null) {
            $subtotalBase = $subtotalCurrency->multipliedBy($exchangeRate)->toScale(6, RoundingMode::HALF_UP);
            $discountTotalBase = $discountTotalCurrency->multipliedBy($exchangeRate)->toScale(6, RoundingMode::HALF_UP);
            $taxTotalBase = $taxTotalCurrency->multipliedBy($exchangeRate)->toScale(6, RoundingMode::HALF_UP);
            $grandTotalBase = $grandTotalCurrency->multipliedBy($exchangeRate)->toScale(6, RoundingMode::HALF_UP);
        }

        return new SalesDocumentTotalsCalculationResult(
            subtotalCurrency: $subtotalCurrency->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            discountTotalCurrency: $discountTotalCurrency->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            taxTotalCurrency: $taxTotalCurrency->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            grandTotalCurrency: $grandTotalCurrency->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            subtotalBase: $subtotalBase->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            discountTotalBase: $discountTotalBase->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            taxTotalBase: $taxTotalBase->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
            grandTotalBase: $grandTotalBase->toScale(self::INTERNAL_DECIMAL_SCALE, RoundingMode::UNNECESSARY),
        );
    }
}
