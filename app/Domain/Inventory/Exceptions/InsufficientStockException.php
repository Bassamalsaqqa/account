<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

final class InsufficientStockException extends RuntimeException
{
    public static function forWarehouse(int $productId, int $warehouseId, string $requested, string $available): self
    {
        return new self("Insufficient stock for product [{$productId}] in warehouse [{$warehouseId}]. Requested [{$requested}], available [{$available}]. Negative warehouse balances are prohibited.");
    }

    public static function forLot(int $productId, int $warehouseId, int $lotId, string $requested, string $available): self
    {
        return new self("Insufficient stock for product [{$productId}] in warehouse [{$warehouseId}], lot [{$lotId}]. Requested [{$requested}], available [{$available}]. Negative lot balances are prohibited.");
    }

    public static function forCompany(int $productId, string $requested, string $available): self
    {
        return new self("Insufficient company-wide stock for product [{$productId}]. Requested [{$requested}], available [{$available}].");
    }
}
