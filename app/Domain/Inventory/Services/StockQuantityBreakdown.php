<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Models\Product;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Read-only display of whole packages and an exact remainder in the base unit. */
final class StockQuantityBreakdown
{
    /** @return list<array{quantity:string, name:string}> */
    public function forProduct(Product $product, string $quantityBase): array
    {
        if (! $product->relationLoaded('productUnits') || ! $product->relationLoaded('baseUnit')) {
            throw new \LogicException('Stock display requires eager-loaded units.');
        }
        $packages = [];
        foreach ($product->productUnits as $productUnit) {
            $unit = $productUnit->unit;
            if ($productUnit->active && ! $productUnit->is_base && $unit?->active
                && (int) $productUnit->company_id === (int) $product->company_id
                && (int) $productUnit->product_id === (int) $product->id
                && (int) $unit->company_id === (int) $product->company_id) {
                $packages[] = ['name' => $unit->name(), 'conversion' => (string) $productUnit->conversion_to_base];
            }
        }

        $baseName = $product->baseUnit?->name() ?? '';
        $parts = $this->parts($quantityBase, $baseName, $packages);

        return count($parts) === 1 && $parts[0]['name'] === $baseName ? [] : $parts;
    }

    /**
     * @param  list<array{name:string, conversion:string}>  $packages  Active same-product units, larger than the base unit.
     * @return list<array{quantity:string, name:string}>
     */
    public function parts(string $quantityBase, string $baseName, array $packages): array
    {
        $remaining = BigDecimal::of($quantityBase);
        // Negative positions are clearer in the base unit; never suggest negative packages.
        if (! $remaining->isPositive()) {
            return [['quantity' => (string) $remaining->strippedOfTrailingZeros(), 'name' => $baseName]];
        }

        $packages = array_values(array_filter($packages, fn (array $unit): bool => BigDecimal::of($unit['conversion'])->isGreaterThan('1')));
        usort($packages, fn (array $a, array $b): int => BigDecimal::of($b['conversion'])->compareTo($a['conversion']) ?: strcmp($a['name'], $b['name']));
        $parts = [];
        foreach ($packages as $package) {
            $factor = BigDecimal::of($package['conversion']);
            $count = $remaining->dividedBy($factor, 0, RoundingMode::DOWN);
            if ($count->isPositive()) {
                $parts[] = ['quantity' => (string) $count, 'name' => $package['name']];
                $remaining = $remaining->minus($count->multipliedBy($factor));
            }
        }
        if ($remaining->isPositive() || $parts === []) {
            $parts[] = ['quantity' => (string) $remaining->strippedOfTrailingZeros(), 'name' => $baseName];
        }

        return $parts;
    }
}
