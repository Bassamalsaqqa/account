<?php

declare(strict_types=1);

namespace App\Domain\Sales\Calculators;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class TaxPercentage
{
    public static function parse(mixed $value): BigDecimal
    {
        if (! is_string($value) && ! $value instanceof BigDecimal) {
            throw new InvalidArgumentException('Tax percentage requires an exact decimal string.');
        }
        $text = (string) $value;
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/D', $text)) {
            throw new InvalidArgumentException('Tax percentage must have at most six decimal places.');
        }
        $rate = BigDecimal::of($text);
        if ($rate->isGreaterThan(100)) {
            throw new InvalidArgumentException('Tax percentage must be between zero and 100.');
        }

        return $rate->toScale(6);
    }
}
