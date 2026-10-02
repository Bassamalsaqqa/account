<?php

declare(strict_types=1);

namespace App\Domain\Sales\Formatters;

use App\Models\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class SalesMoneyFormatter
{
    /**
     * @var array<string, int>
     */
    private static array $minorUnitsMap = [
        'ILS' => 2,
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'JOD' => 3,
        'KWD' => 3,
        'BHD' => 3,
        'OMR' => 3,
    ];

    /**
     * Format a monetary amount to its exact string representation with currency-aware minor units.
     */
    public static function format(string|int|BigDecimal|null $amount, ?string $currencyCode = null, ?int $scale = null): string
    {
        if ($amount === null || $amount === '') {
            $amount = '0';
        }

        $minorUnits = $scale;
        if ($minorUnits === null && $currencyCode !== null) {
            $upperCode = strtoupper($currencyCode);
            if (isset(self::$minorUnitsMap[$upperCode])) {
                $minorUnits = self::$minorUnitsMap[$upperCode];
            } else {
                try {
                    $curr = Currency::find($upperCode);
                    $minorUnits = $curr ? (int) $curr->minor_units : 2;
                    self::$minorUnitsMap[$upperCode] = $minorUnits;
                } catch (\Throwable) {
                    $minorUnits = 2;
                }
            }
        }

        $minorUnits ??= 2;

        $decimal = BigDecimal::of((string) $amount)->toScale($minorUnits, RoundingMode::HALF_UP);
        $raw = (string) $decimal;

        $parts = explode('.', $raw);
        $intStr = $parts[0];
        $isNegative = str_starts_with($intStr, '-');
        $unsignedInt = $isNegative ? substr($intStr, 1) : $intStr;

        // Add thousands separator to integer portion purely via string operations
        $reversed = strrev($unsignedInt);
        $chunks = str_split($reversed, 3);
        $formattedInt = strrev(implode(',', $chunks));
        if ($isNegative && ! $decimal->isZero()) {
            $formattedInt = '-'.$formattedInt;
        }

        return isset($parts[1]) ? "{$formattedInt}.{$parts[1]}" : $formattedInt;
    }

    /**
     * Format a monetary amount with currency.
     */
    public static function formatCurrency(string|int|BigDecimal|null $amount, ?string $currencyCode = null): string
    {
        return self::format($amount, $currencyCode);
    }

    /**
     * Format a quantity without losing precision.
     */
    public static function formatQuantity(string|int|BigDecimal|null $quantity, int $maxScale = 6): string
    {
        if ($quantity === null || $quantity === '') {
            return '0';
        }

        $decimal = BigDecimal::of((string) $quantity);
        $str = (string) $decimal;

        if (str_contains($str, '.')) {
            $str = rtrim(rtrim($str, '0'), '.');
        }

        return $str === '' ? '0' : $str;
    }

    public static function isPositive(string|int|BigDecimal|null $amount): bool
    {
        if ($amount === null || $amount === '') {
            return false;
        }

        return BigDecimal::of((string) $amount)->isPositive();
    }

    public static function isNegative(string|int|BigDecimal|null $amount): bool
    {
        if ($amount === null || $amount === '') {
            return false;
        }

        return BigDecimal::of((string) $amount)->isNegative();
    }

    public static function isZero(string|int|BigDecimal|null $amount): bool
    {
        if ($amount === null || $amount === '') {
            return true;
        }

        return BigDecimal::of((string) $amount)->isZero();
    }
}
