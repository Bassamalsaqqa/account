<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

final class HistoricalConversionLockedException extends RuntimeException
{
    public static function cannotMutateConversion(int $productId, int $unitId): self
    {
        return new self("Cannot mutate conversion ratio for unit [{$unitId}] on product [{$productId}]. Existing stock movement history references this product.");
    }
}
