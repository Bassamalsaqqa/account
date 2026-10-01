<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

final class InvalidQuantityException extends RuntimeException
{
    public static function nonNumeric(string $value): self
    {
        return new self("Quantity value [{$value}] is not a valid numeric decimal string.");
    }

    public static function negativeDisallowed(string $value): self
    {
        return new self("Negative quantity [{$value}] is not allowed in this context.");
    }

    public static function zeroOrNegativeDisallowed(string $value): self
    {
        return new self("Quantity [{$value}] must be strictly positive (> 0).");
    }

    public static function fractionDisallowed(string $unitName, string $value): self
    {
        return new self("Unit [{$unitName}] does not allow fractional quantities. Given [{$value}].");
    }

    public static function excessiveScale(string $value, int $maxScale): self
    {
        return new self("Quantity [{$value}] exceeds maximum precision of {$maxScale} decimal places.");
    }
}
