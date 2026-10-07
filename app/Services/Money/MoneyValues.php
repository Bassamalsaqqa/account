<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\Currency;
use App\Services\Sales\ReceiptRequestValues;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class MoneyValues
{
    public static function amount(mixed $value, string $currency): BigDecimal
    {
        $minorUnits = Currency::where('code', $currency)->value('minor_units');
        if ($minorUnits === null) {
            throw new InvalidArgumentException('Unknown currency.');
        }

        try {
            return BigDecimal::of(ReceiptRequestValues::decimal($value, (int) $minorUnits))->toScale(6);
        } catch (RoundingNecessaryException $exception) {
            throw new InvalidArgumentException('Amount must match the currency minor-unit precision exactly.', previous: $exception);
        }
    }

    public static function rate(mixed $value, string $currency, string $baseCurrency): BigDecimal
    {
        $rate = BigDecimal::of(ReceiptRequestValues::decimal($value, 10));
        if ($currency === $baseCurrency && ! $rate->isEqualTo(1)) {
            throw new InvalidArgumentException('Base currency rate must be exactly one.');
        }

        return $rate;
    }

    public static function base(BigDecimal $amount, BigDecimal $rate): BigDecimal
    {
        $base = $amount->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP);
        if (! $base->isPositive()) {
            throw new InvalidArgumentException('Positive money must have a representable positive base value.');
        }

        return $base;
    }

    public static function text(mixed $text, int $maximum = 2000): ?string
    {
        if ($text === null) {
            return null;
        }
        if (! is_string($text) || mb_strlen($text) > $maximum) {
            throw new InvalidArgumentException('Invalid money event text.');
        }
        $trimmed = trim($text);

        return $trimmed === '' ? null : $trimmed;
    }
}
