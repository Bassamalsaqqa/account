<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Models\InventoryLotBalance;
use Illuminate\Database\Eloquent\Collection;

final class AvailableLotQuery
{
    /** @return Collection<int, InventoryLotBalance> */
    public function get(int $companyId, int $productId, int $warehouseId): Collection
    {
        return InventoryLotBalance::query()
            ->where('inventory_lot_balances.company_id', $companyId)
            ->where('inventory_lot_balances.product_id', $productId)
            ->where('inventory_lot_balances.warehouse_id', $warehouseId)
            ->where('quantity_base', '>', '0')
            ->whereHas('lot', fn ($query) => $query->where('company_id', $companyId)->where('product_id', $productId))
            ->join('inventory_lots', 'inventory_lots.id', '=', 'inventory_lot_balances.lot_id')
            ->select('inventory_lot_balances.*')
            ->with('lot')
            ->orderByRaw('inventory_lots.expiry_date IS NULL')
            ->orderBy('inventory_lots.expiry_date')
            ->orderBy('inventory_lots.received_date')
            ->orderBy('inventory_lots.id')
            ->get();
    }
}
