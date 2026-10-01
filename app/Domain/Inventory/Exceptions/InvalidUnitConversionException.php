<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

final class InvalidUnitConversionException extends RuntimeException
{
    public static function zeroOrNegative(string $conversion): self
    {
        return new self("Unit conversion factor must be strictly positive (> 0). Given [{$conversion}].");
    }

    public static function baseMustBeOne(string $conversion): self
    {
        return new self("Base unit conversion factor must be exactly 1. Given [{$conversion}].");
    }

    public static function unitNotFound(int $productId, int $unitId): self
    {
        return new self("Unit [{$unitId}] is not configured for product [{$productId}].");
    }
}
