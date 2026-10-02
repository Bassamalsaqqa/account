<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class ReceiptRequestValues
{
    public static function id(mixed $value): int
    {
        if (! is_int($value) || $value <= 0) {
            throw new InvalidArgumentException('Receipt references must be positive integer IDs.');
        }

        return $value;
    }

    public static function decimal(mixed $value, int $scale): string
    {
        if (! is_string($value) && ! $value instanceof BigDecimal) {
            throw new InvalidArgumentException('Receipt amounts/rates require exact decimal strings.');
        }
        $number = BigDecimal::of($value);
        if (! $number->isPositive()) {
            throw new InvalidArgumentException('Receipt amounts/rates must be positive.');
        }

        return (string) $number->toScale($scale, RoundingMode::UNNECESSARY);
    }

    public static function key(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 128 || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\-]*$/D', $value)) {
            throw new InvalidArgumentException('Invalid receipt request key.');
        }

        return $value;
    }
}
