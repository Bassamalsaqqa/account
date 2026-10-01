<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

class InvalidInventoryMovementException extends RuntimeException
{
    public static function inactiveProduct(int $productId): self
    {
        return new self("Cannot perform stock movement on inactive product [{$productId}].");
    }

    public static function nonStockProduct(int $productId): self
    {
        return new self("Cannot perform stock movement on non-stock/service product [{$productId}].");
    }

    public static function inactiveWarehouse(int $warehouseId): self
    {
        return new self("Cannot perform stock movement on inactive warehouse [{$warehouseId}].");
    }

    public static function sameWarehouseTransfer(int $warehouseId): self
    {
        return new self("Transfer source and destination warehouse must be different. Given [{$warehouseId}].");
    }

    public static function crossCompanyEntity(string $entity, int $id): self
    {
        return new self("Cross-company entity reference rejected: {$entity} [{$id}] does not belong to active company.");
    }

    public static function missingCostForInbound(int $productId): self
    {
        return new self("Inbound stock movement for product [{$productId}] requires an explicit unit cost.");
    }
}
