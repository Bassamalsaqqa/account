<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Services\Money\CurrencyMinorUnitsResolver;
use Brick\Math\BigDecimal;
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
        $totalBasisUnits = 0;
        $totalBasisDecimal = BigDecimal::zero();

        foreach ($lines as $line) {
            if ($line->unitCost->isGreaterThan(0) && ! $line->taxInclusive) {
                $bInt = $line->subtotal->multipliedBy($scaleFactor)->toBigInteger()->toInt();
                $lineBases[$line->lineNumber] = $bInt;
                $totalBasisUnits += $bInt;
                $totalBasisDecimal = $totalBasisDecimal->plus($line->subtotal);
            } else {
                $lineBases[$line->lineNumber] = 0;
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
            $dUnits = $dCurrency->multipliedBy($scaleFactor)->toBigInteger()->toInt();
        } elseif ($intent->discountType === DocumentDiscountIntent::TYPE_FIXED) {
            $fixed = $intent->discountValue;
            if ($fixed->isNegative()) {
                throw new InvalidArgumentException(__('purchasing.negative_discount_forbidden'));
            }
            $dCurrency = $fixed->toScale($cmu, RoundingMode::HALF_UP);
            $dUnits = $dCurrency->multipliedBy($scaleFactor)->toBigInteger()->toInt();
        } else {
            $dUnits = 0;
        }

        // Pre-condition validations
        if ($totalBasisUnits === 0) {
            if ($dUnits > 0) {
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

        if ($dUnits > $totalBasisUnits) {
            throw new InvalidArgumentException(__('purchasing.discount_exceeds_subtotal'));
        }

        if ($dUnits < 0) {
            throw new InvalidArgumentException(__('purchasing.negative_discount_forbidden'));
        }

        // Hare-Niemeyer (Largest Remainder) Formulation
        $floorShares = [];
        $remainders = [];
        $sumFloors = 0;

        foreach ($lines as $line) {
            $b_i = $lineBases[$line->lineNumber];
            if ($b_i === 0) {
                $floorShares[$line->lineNumber] = 0;
                $remainders[$line->lineNumber] = 0;

                continue;
            }
            $m_i = $dUnits * $b_i;
            $f_i = intdiv($m_i, $totalBasisUnits);
            $r_i = $m_i % $totalBasisUnits;

            $floorShares[$line->lineNumber] = $f_i;
            $remainders[$line->lineNumber] = $r_i;
            $sumFloors += $f_i;
        }

        $residual = $dUnits - $sumFloors;

        // Rank eligible lines: comparator (-r_i, line_number_i)
        // High remainder first; ties broken strictly by ascending persistent line_number
        $rankedLines = [];
        foreach ($lines as $line) {
            if ($lineBases[$line->lineNumber] > 0) {
                $rankedLines[] = [
                    'line_number' => $line->lineNumber,
                    'remainder' => $remainders[$line->lineNumber],
                ];
            }
        }

        usort($rankedLines, function (array $a, array $b): int {
            if ($a['remainder'] !== $b['remainder']) {
                return $b['remainder'] <=> $a['remainder'];
            }

            return $a['line_number'] <=> $b['line_number'];
        });

        $extraUnits = [];
        for ($k = 0; $k < $residual; $k++) {
            $extraUnits[$rankedLines[$k]['line_number']] = 1;
        }

        $allocations = [];
        $totalAllocatedUnits = 0;

        foreach ($lines as $line) {
            $ln = $line->lineNumber;
            $allocatedUnits = ($floorShares[$ln] ?? 0) + ($extraUnits[$ln] ?? 0);
            $totalAllocatedUnits += $allocatedUnits;

            $allocations[$ln] = BigDecimal::of($allocatedUnits)
                ->dividedBy($scaleFactor, self::STORAGE_SCALE, RoundingMode::UNNECESSARY);
        }

        if ($totalAllocatedUnits !== $dUnits) {
            throw new LogicException("Hare-Niemeyer minor unit conservation violated: sum={$totalAllocatedUnits}, target={$dUnits}");
        }

        $totalAllocatedDecimal = BigDecimal::of($dUnits)
            ->dividedBy($scaleFactor, self::STORAGE_SCALE, RoundingMode::UNNECESSARY);

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

        return strtoupper(trim($currencyCode)) === 'JOD' ? 3 : 2;
    }
}
