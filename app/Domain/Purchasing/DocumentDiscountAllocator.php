<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Services\Money\CurrencyMinorUnitsResolver;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use LogicException;

final class DocumentDiscountAllocator
{
    public const int STORAGE_SCALE = 6;

    /**
     * Allocate document discount across lines using integer Hare-Niemeyer (Largest Remainder).
     *
     * @param  list<DocumentDiscountLineCandidate>  $lines
     */
    public function allocate(
        DocumentDiscountIntent $intent,
        array $lines,
        string $currencyCode,
        ?int $customMinorUnits = null,
    ): DocumentDiscountAllocationResult {
        $cmu = $customMinorUnits ?? $this->resolveMinorUnits($currencyCode);
        $scaleFactorInt = 10 ** $cmu;
        $scaleFactor = BigDecimal::of($scaleFactorInt);

        // Verify tax-inclusive restriction: if any line is tax-inclusive and discount intent is present
        if (! $intent->isNone()) {
            foreach ($lines as $line) {
                if ($line->taxInclusive) {
                    throw new InvalidArgumentException(__('purchasing.tax_inclusive_global_discount_forbidden'));
                }
            }
        }

        // Calculate pre-tax basis for each line in integer minor units
        // B_i = int(S_i * 10^cmu)
        // Lines with unit_cost <= 0 have B_i = 0
        $lineBases = [];
        $totalBasisUnits = BigInteger::zero();
        $totalBasisDecimal = BigDecimal::zero();

        foreach ($lines as $line) {
            if ($line->unitCost->isGreaterThan(0) && ! $line->taxInclusive) {
                $bInt = $line->subtotal->toScale($cmu, RoundingMode::HALF_UP)->getUnscaledValue();
                $lineBases[$line->lineNumber] = $bInt;
                $totalBasisUnits = $totalBasisUnits->plus($bInt);
                $totalBasisDecimal = $totalBasisDecimal->plus($line->subtotal);
            } else {
                $lineBases[$line->lineNumber] = BigInteger::zero();
            }
        }

        // Determine total discount to allocate in minor units (D_units)
        if ($intent->isNone()) {
            $allocations = [];
            foreach ($lines as $line) {
                $allocations[$line->lineNumber] = BigDecimal::zero()->toScale(self::STORAGE_SCALE);
            }

            return new DocumentDiscountAllocationResult(
                $allocations,
                BigDecimal::zero()->toScale(self::STORAGE_SCALE),
                $totalBasisDecimal->toScale(self::STORAGE_SCALE)
            );
        }

        if ($intent->discountType === DocumentDiscountIntent::TYPE_PERCENT) {
            $percent = $intent->discountValue;
            if ($percent->isNegative() || $percent->isGreaterThan(100)) {
                throw new InvalidArgumentException(__('purchasing.invalid_discount_percentage'));
            }
            $percentFactor = $percent->dividedBy(BigDecimal::of(100), 8, RoundingMode::HALF_UP);
            $dCurrency = $totalBasisDecimal->multipliedBy($percentFactor)->toScale($cmu, RoundingMode::HALF_UP);
            $dUnits = $dCurrency->toScale($cmu)->getUnscaledValue();
        } elseif ($intent->discountType === DocumentDiscountIntent::TYPE_FIXED) {
            $fixed = $intent->discountValue;
            if ($fixed->isNegative()) {
                throw new InvalidArgumentException(__('purchasing.negative_discount_forbidden'));
            }
            if ($fixed->stripTrailingZeros()->getScale() > $cmu) {
                throw new InvalidArgumentException(__('purchasing.invalid_discount_value'));
            }
            $dCurrency = $fixed->toScale($cmu, RoundingMode::UNNECESSARY);
            $dUnits = $dCurrency->toScale($cmu)->getUnscaledValue();
        } else {
            $dUnits = BigInteger::zero();
        }

        // Pre-condition validations
        if ($totalBasisUnits->isZero()) {
            if ($dUnits->isPositive()) {
                throw new InvalidArgumentException(__('purchasing.zero_subtotal_discount_forbidden'));
            }
            $allocations = [];
            foreach ($lines as $line) {
                $allocations[$line->lineNumber] = BigDecimal::zero()->toScale(self::STORAGE_SCALE);
            }

            return new DocumentDiscountAllocationResult(
                $allocations,
                BigDecimal::zero()->toScale(self::STORAGE_SCALE),
                BigDecimal::zero()->toScale(self::STORAGE_SCALE)
            );
        }

        if ($dUnits->isGreaterThan($totalBasisUnits)) {
            throw new InvalidArgumentException(__('purchasing.discount_exceeds_subtotal'));
        }

        if ($dUnits->isNegative()) {
            throw new InvalidArgumentException(__('purchasing.negative_discount_forbidden'));
        }

        // Hare-Niemeyer (Largest Remainder) Formulation
        $floorShares = [];
        $remainders = [];
        $sumFloors = BigInteger::zero();

        foreach ($lines as $line) {
            $b_i = $lineBases[$line->lineNumber];
            if ($b_i->isZero()) {
                $floorShares[$line->lineNumber] = BigInteger::zero();
                $remainders[$line->lineNumber] = BigInteger::zero();

                continue;
            }
            $m_i = $dUnits->multipliedBy($b_i);
            [$f_i, $r_i] = $m_i->quotientAndRemainder($totalBasisUnits);

            $floorShares[$line->lineNumber] = $f_i;
            $remainders[$line->lineNumber] = $r_i;
            $sumFloors = $sumFloors->plus($f_i);
        }

        $residual = $dUnits->minus($sumFloors)->toInt();

        // Rank eligible lines: comparator (-r_i, line_number_i)
        // High remainder first; ties broken strictly by ascending persistent line_number
        $rankedLines = [];
        foreach ($lines as $line) {
            if ($lineBases[$line->lineNumber]->isPositive()) {
                $rankedLines[] = [
                    'line_number' => $line->lineNumber,
                    'remainder' => $remainders[$line->lineNumber],
                ];
            }
        }

        usort($rankedLines, function (array $a, array $b): int {
            /** @var BigInteger $rA */
            $rA = $a['remainder'];
            /** @var BigInteger $rB */
            $rB = $b['remainder'];
            $cmp = $rB->compareTo($rA);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $a['line_number'] <=> $b['line_number'];
        });

        $extraUnits = [];
        for ($k = 0; $k < $residual; $k++) {
            $extraUnits[$rankedLines[$k]['line_number']] = 1;
        }

        $allocations = [];
        $totalAllocatedUnits = BigInteger::zero();

        foreach ($lines as $line) {
            $ln = $line->lineNumber;
            $bonus = isset($extraUnits[$ln]) ? BigInteger::of(1) : BigInteger::zero();
            $allocatedUnits = ($floorShares[$ln] ?? BigInteger::zero())->plus($bonus);
            $totalAllocatedUnits = $totalAllocatedUnits->plus($allocatedUnits);

            $allocations[$ln] = BigDecimal::ofUnscaledValue($allocatedUnits, $cmu)->toScale(self::STORAGE_SCALE);
        }

        if (! $totalAllocatedUnits->isEqualTo($dUnits)) {
            throw new LogicException("Hare-Niemeyer minor unit conservation violated: sum={$totalAllocatedUnits}, target={$dUnits}");
        }

        $totalAllocatedDecimal = BigDecimal::ofUnscaledValue($dUnits, $cmu)->toScale(self::STORAGE_SCALE);

        return new DocumentDiscountAllocationResult(
            $allocations,
            $totalAllocatedDecimal,
            $totalBasisDecimal->toScale(self::STORAGE_SCALE)
        );
    }

    private function resolveMinorUnits(string $currencyCode): int
    {
        try {
            if (app()->bound(CurrencyMinorUnitsResolver::class)) {
                return app(CurrencyMinorUnitsResolver::class)->resolve($currencyCode);
            }
        } catch (\Throwable) {
            // fallback for standalone unit contexts
        }

        $upper = strtoupper(trim($currencyCode));

        return in_array($upper, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;
    }
}
