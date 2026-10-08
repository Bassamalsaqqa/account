<?php

declare(strict_types=1);

namespace App\Services\Phase7;

use App\Models\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class Phase7Amounts
{
    public static function nonNegative(mixed $value, string $currency): BigDecimal
    {
        if (! is_string($value) && ! $value instanceof BigDecimal) {
            throw new InvalidArgumentException('Exact decimal strings required.');
        }
        $units = Currency::where('code', $currency)->value('minor_units');
        if ($units === null) {
            throw new InvalidArgumentException('Unknown currency.');
        }
        $number = BigDecimal::of($value)->toScale((int) $units, RoundingMode::UNNECESSARY);
        if ($number->isNegative()) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        return $number->toScale(6);
    }
}
