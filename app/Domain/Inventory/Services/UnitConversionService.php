<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\HistoricalConversionLockedException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class UnitConversionService
{
    /**
     * Convert quantity in specified unit to base quantity.
     */
    public function toBase(Quantity|string $quantity, ProductUnit $productUnit): Quantity
    {
        $qty = $quantity instanceof Quantity ? $quantity : Quantity::of($quantity);

        if (! $productUnit->active) {
            throw new InvalidUnitConversionException("Product unit [{$productUnit->id}] is inactive.");
        }

        $unit = $productUnit->unit;
        if ($unit !== null) {
            if (! $unit->active) {
                throw new InvalidUnitConversionException("Unit [{$unit->id}] is inactive.");
            }
            $qty->validateUnitConstraints($unit);
        }

        $conversion = BigDecimal::of((string) $productUnit->conversion_to_base);
        if ($conversion->isLessThanOrEqualTo(0)) {
            throw InvalidUnitConversionException::zeroOrNegative((string) $conversion);
        }

        if ($productUnit->is_base && ! $conversion->isEqualTo(BigDecimal::one())) {
            throw new InvalidUnitConversionException("Base product unit must have conversion factor of exactly 1.000000. Given [{$conversion}].");
        }

        $this->validateProductBaseInvariants($productUnit);

        return $qty->multiply($conversion);
    }

    /**
     * Convert base quantity to quantity in specified unit.
     */
    public function fromBase(Quantity|string $baseQuantity, ProductUnit $productUnit): Quantity
    {
        $qty = $baseQuantity instanceof Quantity ? $baseQuantity : Quantity::of($baseQuantity);

        if (! $productUnit->active) {
            throw new InvalidUnitConversionException("Product unit [{$productUnit->id}] is inactive.");
        }

        $unit = $productUnit->unit;
        if ($unit !== null && ! $unit->active) {
            throw new InvalidUnitConversionException("Unit [{$unit->id}] is inactive.");
        }

        $conversion = BigDecimal::of((string) $productUnit->conversion_to_base);
        if ($conversion->isLessThanOrEqualTo(0)) {
            throw InvalidUnitConversionException::zeroOrNegative((string) $conversion);
        }

        if ($productUnit->is_base && ! $conversion->isEqualTo(BigDecimal::one())) {
            throw new InvalidUnitConversionException("Base product unit must have conversion factor of exactly 1.000000. Given [{$conversion}].");
        }

        $this->validateProductBaseInvariants($productUnit);

        $converted = $qty->divide($conversion, Quantity::DEFAULT_SCALE, RoundingMode::HALF_UP);

        if ($unit !== null) {
            $converted->validateUnitConstraints($unit);
        }

        return $converted;
    }

    /**
     * Validate product declared base unit correspondence and exactly-one base row invariant.
     */
    private function validateProductBaseInvariants(ProductUnit $productUnit): void
    {
        $product = $productUnit->product ?? Product::find($productUnit->product_id);
        if ($product === null) {
            return;
        }

        // 1. Company tenant isolation
        if ((int) $product->company_id !== (int) $productUnit->company_id) {
            throw new InvalidUnitConversionException("ProductUnit company [{$productUnit->company_id}] does not match Product company [{$product->company_id}].");
        }

        // 2. Base unit correspondence
        $isDeclaredBase = (int) $product->base_unit_id === (int) $productUnit->unit_id;

        if ($isDeclaredBase) {
            if (! $productUnit->is_base) {
                throw new InvalidUnitConversionException("Product [{$product->id}] declared base unit [{$productUnit->unit_id}] must have is_base=true.");
            }
            $conv = BigDecimal::of((string) $productUnit->conversion_to_base);
            if (! $conv->isEqualTo(BigDecimal::one())) {
                throw new InvalidUnitConversionException("Product [{$product->id}] declared base unit must have conversion factor of exactly 1.000000. Given [{$conv}].");
            }
        } elseif ($productUnit->is_base) {
            throw new InvalidUnitConversionException("ProductUnit [{$productUnit->id}] has is_base=true but unit [{$productUnit->unit_id}] does not match declared product base unit [{$product->base_unit_id}].");
        }

        // 3. Exactly one base row invariant per product: never allow multiple base unit rows
        $baseCount = ProductUnit::where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('is_base', true)
            ->count();

        if ($baseCount > 1) {
            throw new InvalidUnitConversionException("Product [{$product->id}] has multiple [{$baseCount}] base unit rows.");
        }
    }

    /**
     * Assert that unit conversion can be mutated for product.
     * Locked if any stock movements exist for this product.
     */
    public function assertCanMutateConversion(Product $product, int $unitId): void
    {
        $hasMovements = StockMovement::where('product_id', $product->id)->exists();
        if ($hasMovements) {
            throw HistoricalConversionLockedException::cannotMutateConversion($product->id, $unitId);
        }
    }
}
