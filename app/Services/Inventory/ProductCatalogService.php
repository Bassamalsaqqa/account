<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\Inventory\Exceptions\HistoricalConversionLockedException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Inventory\Services\UnitConversionService;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductCatalogService
{
    public function __construct(
        private readonly UnitConversionService $conversionService
    ) {}

    /**
     * Create product with mandatory base ProductUnit row under transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createProduct(Company $company, array $data, int $actorId): Product
    {
        return DB::transaction(function () use ($company, $data, $actorId): Product {
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            if ($lockedCompany->status !== 'active') {
                throw new InvalidInventoryMovementException("Cannot create products on inactive company [{$lockedCompany->id}].");
            }

            $data['company_id'] = $lockedCompany->id;
            $data['created_by'] = $actorId;

            /** @var Product $product */
            $product = Product::create($data);

            // Provision required base ProductUnit row
            ProductUnit::create([
                'company_id' => $lockedCompany->id,
                'product_id' => $product->id,
                'unit_id' => $product->base_unit_id,
                'conversion_to_base' => '1.000000',
                'is_base' => true,
                'is_default_sale' => true,
                'is_default_purchase' => true,
                'active' => true,
            ]);

            return $product;
        });
    }

    /**
     * Update product under transaction locks.
     * Reconciles base unit row if base_unit_id changed (only allowed when no movements exist).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProduct(Product $product, array $data, int $actorId): Product
    {
        return DB::transaction(function () use ($product, $data, $actorId): Product {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            $hasMovements = StockMovement::where('company_id', $lockedProduct->company_id)
                ->where('product_id', $lockedProduct->id)
                ->exists();

            // Track expiry cannot be disabled if lots exist (even if no movements yet)
            if ($lockedProduct->track_expiry && isset($data['track_expiry']) && ! (bool) $data['track_expiry']) {
                $hasLots = InventoryLot::where('company_id', $lockedProduct->company_id)->where('product_id', $lockedProduct->id)->exists();
                if ($hasLots) {
                    throw new InvalidArgumentException(__('inventory.cannot_disable_expiry_tracking_with_history'));
                }
            }

            if ($hasMovements) {
                if (isset($data['track_stock']) && ! (bool) $data['track_stock'] && $lockedProduct->track_stock) {
                    throw new InvalidArgumentException(__('inventory.cannot_disable_stock_tracking_with_movements'));
                }

                if (isset($data['product_type']) && $data['product_type'] !== $lockedProduct->product_type) {
                    throw new InvalidArgumentException(__('inventory.cannot_change_product_type_with_movements'));
                }

                if (! $lockedProduct->track_expiry && isset($data['track_expiry']) && (bool) $data['track_expiry']) {
                    throw new InvalidArgumentException(__('inventory.cannot_enable_expiry_tracking_with_history'));
                }

                if (isset($data['base_unit_id']) && (int) $data['base_unit_id'] !== (int) $lockedProduct->base_unit_id) {
                    throw HistoricalConversionLockedException::cannotMutateConversion(
                        $lockedProduct->id,
                        (int) $lockedProduct->base_unit_id
                    );
                }
            }

            // If base_unit_id changed prior to any movements:
            if (isset($data['base_unit_id']) && (int) $data['base_unit_id'] !== (int) $lockedProduct->base_unit_id) {
                $newBaseUnitId = (int) $data['base_unit_id'];

                // 1. Reconcile or create the target base unit row
                $targetUnit = ProductUnit::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->where('unit_id', $newBaseUnitId)
                    ->lockForUpdate()
                    ->first();

                if ($targetUnit !== null) {
                    $targetUnit->update([
                        'conversion_to_base' => '1.000000',
                        'is_base' => true,
                        'active' => true,
                    ]);
                } else {
                    ProductUnit::create([
                        'company_id' => $lockedProduct->company_id,
                        'product_id' => $lockedProduct->id,
                        'unit_id' => $newBaseUnitId,
                        'conversion_to_base' => '1.000000',
                        'is_base' => true,
                        'is_default_sale' => true,
                        'is_default_purchase' => true,
                        'active' => true,
                    ]);
                }

                // 2. Remove is_base flag from any other units so exactly one base unit row exists
                ProductUnit::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->where('unit_id', '!=', $newBaseUnitId)
                    ->where('is_base', true)
                    ->delete();
            }

            $data['updated_by'] = $actorId;
            $lockedProduct->update($data);

            return $lockedProduct;
        });
    }

    /**
     * Add or update alternate unit under transaction locks.
     * Existing units with movement history cannot have conversion factor mutated.
     * Adding NEW units is allowed even after movement history.
     */
    public function addOrUpdateAlternateUnit(
        Product $product,
        int $unitId,
        string $conversionToBase,
        bool $isDefaultSale,
        bool $isDefaultPurchase
    ): ProductUnit {
        return DB::transaction(function () use ($product, $unitId, $conversionToBase, $isDefaultSale, $isDefaultPurchase): ProductUnit {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            if ($unitId === (int) $lockedProduct->base_unit_id) {
                throw new InvalidArgumentException(__('inventory.cannot_add_base_as_alternate'));
            }

            $convBd = BigDecimal::of($conversionToBase);
            if ($convBd->isLessThanOrEqualTo(0)) {
                throw InvalidUnitConversionException::zeroOrNegative($conversionToBase);
            }

            /** @var ProductUnit|null $existingUnit */
            $existingUnit = ProductUnit::where('company_id', $lockedProduct->company_id)
                ->where('product_id', $lockedProduct->id)
                ->where('unit_id', $unitId)
                ->lockForUpdate()
                ->first();

            if ($existingUnit !== null) {
                // If mutating conversion factor of an existing unit, verify movement locks
                $currentConv = BigDecimal::of((string) $existingUnit->conversion_to_base);
                if (! $convBd->isEqualTo($currentConv)) {
                    $this->conversionService->assertCanMutateConversion($lockedProduct, $unitId);
                }

                $existingUnit->update([
                    'conversion_to_base' => (string) $convBd->toScale(6),
                    'is_base' => false,
                    'is_default_sale' => $isDefaultSale,
                    'is_default_purchase' => $isDefaultPurchase,
                    'active' => true,
                ]);

                return $existingUnit;
            }

            // Adding new alternate unit — allowed even if product has movement history
            return ProductUnit::create([
                'company_id' => $lockedProduct->company_id,
                'product_id' => $lockedProduct->id,
                'unit_id' => $unitId,
                'conversion_to_base' => (string) $convBd->toScale(6),
                'is_base' => false,
                'is_default_sale' => $isDefaultSale,
                'is_default_purchase' => $isDefaultPurchase,
                'active' => true,
            ]);
        });
    }

    /**
     * Remove alternate unit under transaction locks.
     * Removal of base unit is strictly forbidden.
     * Removal of unit referenced in stock movements is forbidden.
     */
    public function removeAlternateUnit(Product $product, int $productUnitId): void
    {
        DB::transaction(function () use ($product, $productUnitId): void {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            /** @var ProductUnit|null $targetUnit */
            $targetUnit = ProductUnit::where('id', $productUnitId)
                ->where('company_id', $lockedProduct->company_id)
                ->where('product_id', $lockedProduct->id)
                ->lockForUpdate()
                ->first();

            if ($targetUnit === null) {
                return;
            }

            if ($targetUnit->is_base || (int) $targetUnit->unit_id === (int) $lockedProduct->base_unit_id) {
                throw new InvalidArgumentException(__('inventory.cannot_remove_base_unit'));
            }

            $hasMovements = StockMovement::where('company_id', $lockedProduct->company_id)
                ->where('product_id', $lockedProduct->id)
                ->where('unit_id', $targetUnit->unit_id)
                ->exists();

            if ($hasMovements) {
                throw new HistoricalConversionLockedException(__('inventory.cannot_remove_unit_with_movement_history'));
            }

            $targetUnit->delete();
        });
    }

    /**
     * Add barcode under transaction locks.
     */
    public function addBarcode(Product $product, string $barcode, ?int $unitId, bool $isPrimary): ProductBarcode
    {
        return DB::transaction(function () use ($product, $barcode, $unitId, $isPrimary): ProductBarcode {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            if ($unitId !== null) {
                $pu = ProductUnit::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->where('unit_id', $unitId)
                    ->first();

                if ($pu === null) {
                    throw new InvalidArgumentException("Unit [{$unitId}] is not configured for product [{$lockedProduct->id}].");
                }
            }

            if ($isPrimary) {
                ProductBarcode::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->update(['is_primary' => false]);
            }

            return ProductBarcode::create([
                'company_id' => $lockedProduct->company_id,
                'product_id' => $lockedProduct->id,
                'unit_id' => $unitId,
                'barcode' => trim($barcode),
                'type' => 'CODE128',
                'is_primary' => $isPrimary,
            ]);
        });
    }

    /**
     * Remove barcode under transaction locks.
     */
    public function removeBarcode(Product $product, int $barcodeId): void
    {
        DB::transaction(function () use ($product, $barcodeId): void {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            ProductBarcode::where('id', $barcodeId)
                ->where('company_id', $lockedProduct->company_id)
                ->where('product_id', $lockedProduct->id)
                ->delete();
        });
    }
}
