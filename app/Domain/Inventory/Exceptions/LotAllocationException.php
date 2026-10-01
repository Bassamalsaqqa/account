<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

final class LotAllocationException extends RuntimeException
{
    public static function lotRequiredForExpiryTracking(int $productId): self
    {
        return new self("Product [{$productId}] tracks expiry and requires lot allocation for stock movements.");
    }

    public static function lotNotAllowedForNonExpiryTracking(int $productId): self
    {
        return new self("Product [{$productId}] does not track expiry; lot assignment is not permitted.");
    }

    public static function expiredLotCannotBeAllocated(int $lotId, string $expiryDate): self
    {
        return new self("Lot [{$lotId}] expired on [{$expiryDate}] and cannot be allocated for standard issue/sale.");
    }

    public static function mismatchedProductLot(int $productId, int $lotId): self
    {
        return new self("Lot [{$lotId}] does not belong to product [{$productId}].");
    }
}
