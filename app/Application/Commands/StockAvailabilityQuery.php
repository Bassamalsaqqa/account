<?php

declare(strict_types=1);

namespace App\Application\Commands;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\FefoAllocationService;
use Brick\Math\BigDecimal;

class StockAvailabilityQuery
{
    public function __construct(
        protected FefoAllocationService $fefoAllocationService,
    ) {}

    /**
     * Check stock availability and FEFO lot allocations for a list of line requirements.
     * Repeated product lines are aggregated before availability and FEFO checks.
     * Costs are strictly omitted. Stock is never reserved.
     *
     * @param  list<array{product: Product, quantity_base: BigDecimal}>  $requirements
     * @return array{
     *     disclaimer: string,
     *     products: list<array{
     *         product_id: int,
     *         product_name: string,
     *         product_sku: string|null,
     *         track_stock: bool,
     *         track_expiry: bool,
     *         quantity_needed_base: string,
     *         quantity_available_base: string,
     *         is_sufficient: bool,
     *         fefo_allocations: list<array{
     *             lot_id: int|null,
     *             lot_number: string|null,
     *             expiry_date: string|null,
     *             quantity: string,
     *         }>,
     *     }>,
     *     blockers: list<array{code: string, message: string, product_id?: int}>,
     * }
     */
    public function check(Company $company, ?Warehouse $warehouse, array $requirements, string $issueDate): array
    {
        $disclaimer = 'Stock availability and FEFO lot allocations are projected at preview time and are not reserved.';
        $blockers = [];
        $productsList = [];

        // 1. Aggregate repeated product lines before checking availability and FEFO
        /** @var array<int, array{product: Product, quantity_needed_base: BigDecimal}> $aggregated */
        $aggregated = [];
        foreach ($requirements as $req) {
            $product = $req['product'];
            $qtyBase = $req['quantity_base'];
            $pId = (int) $product->id;

            if (! isset($aggregated[$pId])) {
                $aggregated[$pId] = [
                    'product' => $product,
                    'quantity_needed_base' => BigDecimal::zero(),
                ];
            }
            $aggregated[$pId]['quantity_needed_base'] = $aggregated[$pId]['quantity_needed_base']->plus($qtyBase);
        }

        // 2. Evaluate availability for each aggregated product
        foreach ($aggregated as $pId => $item) {
            /** @var Product $product */
            $product = $item['product'];
            $neededBase = $item['quantity_needed_base'];

            if (! $product->track_stock) {
                // Non-stock or service item
                $productsList[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name_en ?? $product->name_ar,
                    'product_sku' => $product->sku,
                    'track_stock' => false,
                    'track_expiry' => false,
                    'quantity_needed_base' => (string) $neededBase->toScale(6),
                    'quantity_available_base' => 'unlimited',
                    'is_sufficient' => true,
                    'fefo_allocations' => [],
                ];

                continue;
            }

            // Product tracks stock: warehouse is mandatory
            if ($warehouse === null) {
                $blockers[] = [
                    'code' => 'warehouse_required',
                    'message' => "Warehouse is required for stock-bearing product [{$product->id}].",
                    'product_id' => $product->id,
                ];
                $productsList[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name_en ?? $product->name_ar,
                    'product_sku' => $product->sku,
                    'track_stock' => true,
                    'track_expiry' => (bool) $product->track_expiry,
                    'quantity_needed_base' => (string) $neededBase->toScale(6),
                    'quantity_available_base' => '0.000000',
                    'is_sufficient' => false,
                    'fefo_allocations' => [],
                ];

                continue;
            }

            if (! $warehouse->active) {
                $blockers[] = [
                    'code' => 'warehouse_inactive',
                    'message' => "Warehouse [{$warehouse->id}] is inactive.",
                    'product_id' => $product->id,
                ];
                $productsList[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name_en ?? $product->name_ar,
                    'product_sku' => $product->sku,
                    'track_stock' => true,
                    'track_expiry' => (bool) $product->track_expiry,
                    'quantity_needed_base' => (string) $neededBase->toScale(6),
                    'quantity_available_base' => '0.000000',
                    'is_sufficient' => false,
                    'fefo_allocations' => [],
                ];

                continue;
            }

            // Query inventory balance
            $balance = InventoryBalance::where('company_id', $company->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->value('quantity_base') ?? '0.000000';

            $availableBase = BigDecimal::of((string) $balance);
            $isSufficient = $availableBase->isGreaterThanOrEqualTo($neededBase);

            if (! $isSufficient) {
                $blockers[] = [
                    'code' => 'insufficient_stock',
                    'message' => "Insufficient stock for product [{$product->id}]. Needed: {$neededBase->toScale(6)}, available: {$availableBase->toScale(6)}.",
                    'product_id' => $product->id,
                ];
            }

            // FEFO lot allocations for expiry-tracked products (read-only, no costs)
            $fefoAllocations = [];
            if ($product->track_expiry) {
                try {
                    $allocs = $this->fefoAllocationService->allocate(
                        $product,
                        $warehouse,
                        (string) $neededBase,
                        excludeExpired: true,
                        asOfDate: $issueDate
                    );

                    foreach ($allocs as $alloc) {
                        $expiry = $alloc['lot']?->expiry_date?->format('Y-m-d');

                        $fefoAllocations[] = [
                            'lot_id' => $alloc['lot_id'],
                            'lot_number' => $alloc['lot']?->lot_number,
                            'expiry_date' => $expiry,
                            'quantity' => (string) $alloc['quantity']->toScale(),
                        ];
                    }
                } catch (InsufficientStockException $e) {
                    if ($isSufficient) {
                        $blockers[] = [
                            'code' => 'insufficient_fefo_stock',
                            'message' => $e->getMessage(),
                            'product_id' => $product->id,
                        ];
                        $isSufficient = false;
                    }
                }
            }

            $productsList[] = [
                'product_id' => $product->id,
                'product_name' => $product->name_en ?? $product->name_ar,
                'product_sku' => $product->sku,
                'track_stock' => true,
                'track_expiry' => (bool) $product->track_expiry,
                'quantity_needed_base' => (string) $neededBase->toScale(6),
                'quantity_available_base' => (string) $availableBase->toScale(6),
                'is_sufficient' => $isSufficient,
                'fefo_allocations' => $fefoAllocations,
            ];
        }

        return [
            'disclaimer' => $disclaimer,
            'products' => $productsList,
            'blockers' => $blockers,
        ];
    }
}
