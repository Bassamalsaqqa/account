<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\HistoricalConversionLockedException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
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
    public function validateProductBaseInvariants(ProductUnit $productUnit): void
    {
        /** @var Product|null $product */
        $product = $productUnit->product ?? Product::find($productUnit->product_id);
        if ($product === null) {
            throw new InvalidUnitConversionException("ProductUnit [{$productUnit->id}] references non-existent product [{$productUnit->product_id}].");
        }

        // 1. Company tenant isolation between Product and ProductUnit
        if ((int) $product->company_id !== (int) $productUnit->company_id) {
            throw new InvalidUnitConversionException("ProductUnit company [{$productUnit->company_id}] does not match Product company [{$product->company_id}].");
        }

        // 2. Unit referenced by ProductUnit must exist, belong to same company, and be active
        /** @var Unit|null $unit */
        $unit = $productUnit->unit ?? Unit::find($productUnit->unit_id);
        if ($unit === null) {
            throw new InvalidUnitConversionException("ProductUnit [{$productUnit->id}] references non-existent unit [{$productUnit->unit_id}].");
        }
        if ((int) $unit->company_id !== (int) $product->company_id) {
            throw new InvalidUnitConversionException("Unit [{$unit->id}] company [{$unit->company_id}] does not match Product company [{$product->company_id}].");
        }
        if (! $unit->active) {
            throw new InvalidUnitConversionException("Unit [{$unit->id}] is inactive.");
        }

        // 3. Product declared base unit must exist, belong to same company, and be active
        /** @var Unit|null $declaredBaseUnit */
        $declaredBaseUnit = Unit::where('company_id', $product->company_id)->where('id', $product->base_unit_id)->first();
        if ($declaredBaseUnit === null) {
            throw new InvalidUnitConversionException("Product [{$product->id}] declared base unit [{$product->base_unit_id}] does not exist in company [{$product->company_id}].");
        }
        if (! $declaredBaseUnit->active) {
            throw new InvalidUnitConversionException("Product [{$product->id}] declared base unit [{$declaredBaseUnit->id}] is inactive.");
        }

        // 4. Exactly one base row invariant per product matching products.base_unit_id
        $baseRows = ProductUnit::where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('is_base', true)
            ->get();

        if ($baseRows->isEmpty()) {
            throw new InvalidUnitConversionException("Product [{$product->id}] has no base ProductUnit row.");
        }

        if ($baseRows->count() > 1) {
            throw new InvalidUnitConversionException("Product [{$product->id}] has multiple [{$baseRows->count()}] base unit rows.");
        }

        /** @var ProductUnit $baseRow */
        $baseRow = $baseRows->first();
        if ((int) $baseRow->unit_id !== (int) $product->base_unit_id) {
            throw new InvalidUnitConversionException("Base ProductUnit unit_id [{$baseRow->unit_id}] does not match product base_unit_id [{$product->base_unit_id}].");
        }
        if (! $baseRow->active) {
            throw new InvalidUnitConversionException("Base ProductUnit for product [{$product->id}] is inactive.");
        }
        $baseConv = BigDecimal::of((string) $baseRow->conversion_to_base);
        if (! $baseConv->isEqualTo(BigDecimal::one())) {
            throw new InvalidUnitConversionException("Base ProductUnit must have conversion factor of exactly 1.000000. Given [{$baseConv}].");
        }

        // 5. ProductUnit base flag vs product base_unit_id coherence
        $isDeclaredBase = (int) $product->base_unit_id === (int) $productUnit->unit_id;
        if ($isDeclaredBase && ! $productUnit->is_base) {
            throw new InvalidUnitConversionException("Product [{$product->id}] declared base unit [{$productUnit->unit_id}] must have is_base=true.");
        }
        if (! $isDeclaredBase && $productUnit->is_base) {
            throw new InvalidUnitConversionException("ProductUnit [{$productUnit->id}] has is_base=true but unit [{$productUnit->unit_id}] does not match declared product base unit [{$product->base_unit_id}].");
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
