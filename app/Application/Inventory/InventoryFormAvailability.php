<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Inventory\Services\StockQuantityBreakdown;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Warehouse;

/** Read-only warehouse quantity for an already authorized inventory form. */
final class InventoryFormAvailability
{
    /** @return array{quantity: string, unit: string, packages: list<array{quantity: string, name: string}>}|null */
    public function forProduct(int $companyId, ?Product $product, ?int $warehouseId): ?array
    {
        if ($product === null || (int) $product->company_id !== $companyId || $warehouseId === null
            || ! Warehouse::where('company_id', $companyId)->where('active', true)->whereKey($warehouseId)->exists()) {
            return null;
        }

        $quantity = (string) (InventoryBalance::where('company_id', $companyId)
            ->where('product_id', $product->id)->where('warehouse_id', $warehouseId)->value('quantity_base') ?? '0');

        return [
            'quantity' => $quantity,
            'unit' => $product->baseUnit?->name() ?? '',
            'packages' => app(StockQuantityBreakdown::class)->forProduct($product, $quantity),
        ];
    }
}
