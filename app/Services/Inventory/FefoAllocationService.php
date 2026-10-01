<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InventorySecurityException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FefoAllocationService
{
    /**
     * Determine FEFO lot allocation for DISPLAY/PREVIEW purposes only.
     * Does NOT acquire row locks and does NOT require a transaction.
     * Must not be used for actual stock consumption.
     *
     * @return list<array{lot: ?InventoryLot, lot_id: ?int, quantity: Quantity}>
     */
    public function allocate(
        Product $product,
        Warehouse $warehouse,
        Quantity|string $requestedQuantity,
        bool $excludeExpired = true,
        ?string $asOfDate = null,
    ): array {
        return $this->doAllocate($product, $warehouse, $requestedQuantity, $excludeExpired, false, $asOfDate);
    }

    /**
     * Determine FEFO lot allocation WITH row-level locks.
     * MUST be called inside an active DB::transaction().
     * Enforces global lock order (Company -> Product -> Warehouse -> Lots).
     *
     * @return list<array{lot: ?InventoryLot, lot_id: ?int, quantity: Quantity}>
     */
    public function allocateWithLock(
        Product $product,
        Warehouse $warehouse,
        Quantity|string $requestedQuantity,
        bool $excludeExpired = true,
        ?string $asOfDate = null,
    ): array {
        if (DB::transactionLevel() <= 0) {
            throw new RuntimeException('allocateWithLock requires an active database transaction.');
        }

        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $product->company_id) {
            throw new InventorySecurityException("FEFO allocation requires active matching company context for company [{$product->company_id}].");
        }

        if ((int) $product->company_id !== (int) $warehouse->company_id) {
            throw new InvalidInventoryMovementException("Product company [{$product->company_id}] does not match warehouse company [{$warehouse->company_id}].");
        }

        // Global lock order: Company -> Product -> Warehouse
        Company::where('id', $product->company_id)->lockForUpdate()->firstOrFail();
        Product::where('id', $product->id)->lockForUpdate()->firstOrFail();
        Warehouse::where('id', $warehouse->id)->lockForUpdate()->firstOrFail();

        return $this->doAllocate($product, $warehouse, $requestedQuantity, $excludeExpired, true, $asOfDate);
    }

    /**
     * @return list<array{lot: ?InventoryLot, lot_id: ?int, quantity: Quantity}>
     */
    private function doAllocate(
        Product $product,
        Warehouse $warehouse,
        Quantity|string $requestedQuantity,
        bool $excludeExpired,
        bool $lockForUpdate,
        ?string $asOfDate,
    ): array {
        $needed = $requestedQuantity instanceof Quantity ? $requestedQuantity : Quantity::of($requestedQuantity);

        if ($needed->isLessThanOrEqualTo(0)) {
            return [];
        }

        // Non-expiry products do not incur lot allocations
        if (! $product->track_expiry) {
            return [
                [
                    'lot' => null,
                    'lot_id' => null,
                    'quantity' => $needed,
                ],
            ];
        }

        // Query eligible lot balances ordered by FEFO
        $query = InventoryLotBalance::query()
            ->with('lot')
            ->join('inventory_lots', 'inventory_lot_balances.lot_id', '=', 'inventory_lots.id')
            ->where('inventory_lot_balances.company_id', $product->company_id)
            ->where('inventory_lot_balances.product_id', $product->id)
            ->where('inventory_lot_balances.warehouse_id', $warehouse->id)
            ->where('inventory_lot_balances.quantity_base', '>', 0)
            ->select('inventory_lot_balances.*');

        $effectiveDate = $asOfDate ?? Carbon::today()->toDateString();

        if ($excludeExpired) {
            $query->where(function ($q) use ($effectiveDate) {
                $q->whereNull('inventory_lots.expiry_date')
                    ->orWhere('inventory_lots.expiry_date', '>=', $effectiveDate);
            });
        }

        // FEFO Ordering: Expiry ASC (nulls last), received_date ASC, id ASC
        $query->orderByRaw('CASE WHEN inventory_lots.expiry_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('inventory_lots.expiry_date', 'asc')
            ->orderBy('inventory_lots.received_date', 'asc')
            ->orderBy('inventory_lots.id', 'asc');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        /** @var Collection<int, InventoryLotBalance> $candidateBalances */
        $candidateBalances = $query->get();

        $allocations = [];
        $remaining = $needed;

        foreach ($candidateBalances as $balance) {
            if ($remaining->isZero()) {
                break;
            }

            $avail = Quantity::of((string) $balance->quantity_base);
            if ($avail->isLessThanOrEqualTo(0)) {
                continue;
            }

            $take = $avail->isGreaterThanOrEqualTo($remaining) ? $remaining : $avail;

            /** @var InventoryLot $lot */
            $lot = $balance->lot;

            $allocations[] = [
                'lot' => $lot,
                'lot_id' => $lot->id,
                'quantity' => $take,
            ];

            $remaining = $remaining->subtract($take);
        }

        if ($remaining->isPositive()) {
            $allocatedTotal = $needed->subtract($remaining);
            throw InsufficientStockException::forWarehouse(
                $product->id,
                $warehouse->id,
                $needed->toScale(),
                $allocatedTotal->toScale()
            );
        }

        return $allocations;
    }
}
