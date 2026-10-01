<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\Inventory\Exceptions\HistoricalConversionLockedException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Inventory\Services\UnitConversionService;
use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
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
     * Reconciles base unit row if base_unit_id changed (only allowed for healthy pristine products).
     * Guards deactivation of stock-tracked products with positive quantity.
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
            }

            // Section 7: Base unit change allowed only for healthy pristine Product
            if (isset($data['base_unit_id']) && (int) $data['base_unit_id'] !== (int) $lockedProduct->base_unit_id) {
                $newBaseUnitId = (int) $data['base_unit_id'];

                if ($hasMovements) {
                    throw HistoricalConversionLockedException::cannotMutateConversion(
                        $lockedProduct->id,
                        (int) $lockedProduct->base_unit_id
                    );
                }

                // Check for alternate ProductUnit configuration
                $hasAlternateUnits = ProductUnit::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->where('is_base', false)
                    ->exists();
                if ($hasAlternateUnits) {
                    throw new HistoricalConversionLockedException("Cannot change base unit: product [{$lockedProduct->id}] has alternate unit configurations. Alternate units must be removed first.");
                }

                // Check for unit-linked barcode
                $hasUnitBarcodes = ProductBarcode::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->whereNotNull('unit_id')
                    ->exists();
                if ($hasUnitBarcodes) {
                    throw new HistoricalConversionLockedException("Cannot change base unit: product [{$lockedProduct->id}] has unit-linked barcodes.");
                }

                // Check for lot / balance / cost history dependency
                $hasLots = InventoryLot::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->exists();
                $hasBalances = InventoryBalance::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->where('quantity_base', '!=', '0.000000')
                    ->exists();
                $hasCostHistory = InventoryCostState::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->where('quantity_base', '!=', '0.000000')
                    ->exists();

                if ($hasLots || $hasBalances || $hasCostHistory) {
                    throw new HistoricalConversionLockedException("Cannot change base unit: product [{$lockedProduct->id}] has inventory lots or active balance/cost history.");
                }

                // Validate current declared Unit and ProductUnit configuration before allowing base switch
                /** @var Unit|null $currentDeclaredUnit */
                $currentDeclaredUnit = Unit::where('company_id', $lockedProduct->company_id)
                    ->where('id', $lockedProduct->base_unit_id)
                    ->first();
                if ($currentDeclaredUnit === null || ! $currentDeclaredUnit->active) {
                    throw new InvalidUnitConversionException("Cannot change base unit: product [{$lockedProduct->id}] has invalid or inactive current declared base unit [{$lockedProduct->base_unit_id}].");
                }

                $baseUnits = ProductUnit::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->where('is_base', true)
                    ->lockForUpdate()
                    ->get();

                if ($baseUnits->count() !== 1) {
                    throw new InvalidUnitConversionException("Cannot change base unit: product [{$lockedProduct->id}] has invalid base unit configuration (expected exactly 1 base ProductUnit, found {$baseUnits->count()}).");
                }

                /** @var ProductUnit $currentBase */
                $currentBase = $baseUnits->first();
                if ((int) $currentBase->unit_id !== (int) $lockedProduct->base_unit_id) {
                    throw new InvalidUnitConversionException("Cannot change base unit: product [{$lockedProduct->id}] base ProductUnit unit_id [{$currentBase->unit_id}] does not match product base_unit_id [{$lockedProduct->base_unit_id}].");
                }

                if (! $currentBase->active) {
                    throw new InvalidUnitConversionException("Cannot change base unit: product [{$lockedProduct->id}] base ProductUnit is inactive.");
                }

                if (BigDecimal::of((string) $currentBase->conversion_to_base)->compareTo(BigDecimal::one()) !== 0) {
                    throw new InvalidUnitConversionException("Cannot change base unit: product [{$lockedProduct->id}] base ProductUnit has invalid conversion_to_base [{$currentBase->conversion_to_base}].");
                }

                // Target unit must exist in same company and be active
                /** @var Unit|null $targetUnit */
                $targetUnit = Unit::where('company_id', $lockedProduct->company_id)
                    ->where('id', $newBaseUnitId)
                    ->first();
                if ($targetUnit === null || ! $targetUnit->active) {
                    throw new InvalidUnitConversionException("Target base unit [{$newBaseUnitId}] does not exist or is inactive in company [{$lockedProduct->company_id}].");
                }

                // Pristine product: safely replace sole base row under locks
                ProductUnit::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->delete();

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

            // Section 8: Deactivation guard for stock-tracked product with positive stock
            if (isset($data['active']) && ! $data['active'] && $lockedProduct->active) {
                if ($lockedProduct->track_stock) {
                    /** @var InventoryCostState|null $costState */
                    $costState = InventoryCostState::where('company_id', $lockedProduct->company_id)
                        ->where('product_id', $lockedProduct->id)
                        ->lockForUpdate()
                        ->first();
                    $currentQty = $costState !== null ? BigDecimal::of((string) $costState->quantity_base) : BigDecimal::zero();
                    if ($currentQty->isPositive()) {
                        throw new InvalidInventoryMovementException("Cannot deactivate product [{$lockedProduct->id}] with positive stock [{$currentQty}]. Stock must be zero before deactivation.");
                    }
                }
            }

            $data['updated_by'] = $actorId;
            $lockedProduct->update($data);

            return $lockedProduct;
        });
    }

    /**
     * Update warehouse under company locks with domain deactivation guards.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateWarehouse(Company $company, Warehouse|int $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($company, $warehouse, $data): Warehouse {
            Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            $warehouseId = $warehouse instanceof Warehouse ? $warehouse->id : $warehouse;
            /** @var Warehouse $lockedWarehouse */
            $lockedWarehouse = Warehouse::where('company_id', $company->id)->where('id', $warehouseId)->lockForUpdate()->firstOrFail();

            $resultingDefault = array_key_exists('is_default', $data) ? (bool) $data['is_default'] : (bool) $lockedWarehouse->is_default;
            $resultingActive = array_key_exists('active', $data) ? (bool) $data['active'] : (bool) $lockedWarehouse->active;

            // 1. Inactive warehouse can NEVER be or become a default warehouse
            if ($resultingDefault && ! $resultingActive) {
                throw new InvalidInventoryMovementException(__('inventory.cannot_deactivate_default_warehouse'));
            }

            // 2. Cannot remove default status from sole active default warehouse
            if (! $resultingDefault && $lockedWarehouse->is_default) {
                $otherActiveDefault = Warehouse::where('company_id', $company->id)
                    ->where('is_default', true)
                    ->where('active', true)
                    ->where('id', '!=', $lockedWarehouse->id)
                    ->exists();
                if (! $otherActiveDefault) {
                    throw new InvalidInventoryMovementException(__('inventory.cannot_remove_default_from_sole_warehouse'));
                }
            }

            // 3. Deactivation guards: cannot deactivate warehouse with positive stock
            if (! $resultingActive && $lockedWarehouse->active) {
                $hasPositiveStock = InventoryBalance::where('company_id', $company->id)
                    ->where('warehouse_id', $lockedWarehouse->id)
                    ->where('quantity_base', '>', 0)
                    ->exists();

                if ($hasPositiveStock) {
                    throw new InvalidInventoryMovementException("Cannot deactivate warehouse [{$lockedWarehouse->id}] with positive inventory balance. Adjust or transfer stock to zero first.");
                }
            }

            if ($resultingDefault && ! $lockedWarehouse->is_default) {
                Warehouse::where('company_id', $company->id)->where('id', '!=', $lockedWarehouse->id)->update(['is_default' => false]);
            }

            $lockedWarehouse->update($data);

            if ($resultingDefault) {
                CompanyInventorySettings::where('company_id', $company->id)->update([
                    'default_warehouse_id' => $lockedWarehouse->id,
                ]);
            }

            return $lockedWarehouse;
        });
    }

    /**
     * Deactivate warehouse under company locks with domain guards.
     */
    public function deactivateWarehouse(Company $company, Warehouse|int $warehouse): Warehouse
    {
        return $this->updateWarehouse($company, $warehouse, ['active' => false]);
    }

    /**
     * Update unit under company locks with domain deactivation guards.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateUnit(Company $company, Unit|int $unit, array $data): Unit
    {
        return DB::transaction(function () use ($company, $unit, $data): Unit {
            Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            $unitId = $unit instanceof Unit ? $unit->id : $unit;
            /** @var Unit $lockedUnit */
            $lockedUnit = Unit::where('company_id', $company->id)->where('id', $unitId)->lockForUpdate()->firstOrFail();

            $isActive = isset($data['active']) ? (bool) $data['active'] : (bool) $lockedUnit->active;

            // Invariant: Unit used as base by an active Product cannot deactivate
            if (! $isActive && $lockedUnit->active) {
                $isUsedAsBaseByActiveProduct = Product::where('company_id', $company->id)
                    ->where('base_unit_id', $lockedUnit->id)
                    ->where('active', true)
                    ->exists();

                if ($isUsedAsBaseByActiveProduct) {
                    throw new InvalidInventoryMovementException("Cannot deactivate unit [{$lockedUnit->id}]: it is currently used as the base unit for one or more active products.");
                }

                $isReferencedByActiveProductUnit = ProductUnit::where('company_id', $company->id)
                    ->where('unit_id', $lockedUnit->id)
                    ->where('active', true)
                    ->exists();

                if ($isReferencedByActiveProductUnit) {
                    throw new InvalidInventoryMovementException(__('inventory.cannot_deactivate_unit_in_use'));
                }
            }

            $lockedUnit->update($data);

            return $lockedUnit;
        });
    }

    /**
     * Deactivate unit under company locks with domain guards.
     */
    public function deactivateUnit(Company $company, Unit|int $unit): Unit
    {
        return $this->updateUnit($company, $unit, ['active' => false]);
    }

    /**
     * Add or update alternate unit under transaction locks.
     * Existing units with movement history cannot have conversion factor mutated.
     * Adding NEW units is allowed even after movement history.
     * Enforces singleton active default sale and purchase invariants per product.
     */
    public function addOrUpdateAlternateUnit(
        Product $product,
        int $unitId,
        string $conversionToBase,
        bool $isDefaultSale,
        bool $isDefaultPurchase
    ): ProductUnit {
        return DB::transaction(function () use (
            $product,
            $unitId,
            $conversionToBase,
            $isDefaultSale,
            $isDefaultPurchase
        ): ProductUnit {
            Company::where('id', $product->company_id)->lockForUpdate()->firstOrFail();
            /** @var Product $lockedProduct */
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            if ($unitId === (int) $lockedProduct->base_unit_id) {
                throw new InvalidArgumentException(__('inventory.cannot_add_base_as_alternate'));
            }

            /** @var Unit|null $targetUnit */
            $targetUnit = Unit::where('company_id', $lockedProduct->company_id)
                ->where('id', $unitId)
                ->lockForUpdate()
                ->first();

            if ($targetUnit === null) {
                throw new InvalidArgumentException("Target unit [{$unitId}] does not exist in company [{$lockedProduct->company_id}].");
            }

            if (! $targetUnit->active) {
                throw new InvalidInventoryMovementException(__('inventory.cannot_use_inactive_unit'));
            }

            $convBd = BigDecimal::of($conversionToBase);
            if ($convBd->isLessThanOrEqualTo(0)) {
                throw InvalidUnitConversionException::zeroOrNegative((string) $convBd);
            }

            // Lock all ProductUnits for this product
            ProductUnit::where('company_id', $lockedProduct->company_id)
                ->where('product_id', $lockedProduct->id)
                ->lockForUpdate()
                ->get();

            /** @var ProductUnit|null $existingUnit */
            $existingUnit = ProductUnit::where('company_id', $lockedProduct->company_id)
                ->where('product_id', $lockedProduct->id)
                ->where('unit_id', $unitId)
                ->first();

            if ($existingUnit !== null) {
                $existingConv = BigDecimal::of((string) $existingUnit->conversion_to_base);
                if (! $existingConv->isEqualTo($convBd)) {
                    $this->conversionService->assertCanMutateConversion($lockedProduct, $unitId);
                }

                // Check if unsetting default sale or purchase requires base fallback
                $needsSaleFallback = false;
                if (! $isDefaultSale) {
                    $hasOtherSaleDefault = ProductUnit::where('company_id', $lockedProduct->company_id)
                        ->where('product_id', $lockedProduct->id)
                        ->where('active', true)
                        ->where('is_default_sale', true)
                        ->where('id', '!=', $existingUnit->id)
                        ->exists();

                    if (! $hasOtherSaleDefault) {
                        $needsSaleFallback = true;
                    }
                }

                $needsPurchaseFallback = false;
                if (! $isDefaultPurchase) {
                    $hasOtherPurchaseDefault = ProductUnit::where('company_id', $lockedProduct->company_id)
                        ->where('product_id', $lockedProduct->id)
                        ->where('active', true)
                        ->where('is_default_purchase', true)
                        ->where('id', '!=', $existingUnit->id)
                        ->exists();

                    if (! $hasOtherPurchaseDefault) {
                        $needsPurchaseFallback = true;
                    }
                }

                // If fallback to base is needed for either dimension, validate healthy base BEFORE any mutation
                $baseUnitToPromote = null;
                if ($needsSaleFallback || $needsPurchaseFallback) {
                    $baseUnitToPromote = $this->conversionService->getHealthyBaseUnit($lockedProduct);
                }

                if ($isDefaultSale) {
                    ProductUnit::where('company_id', $lockedProduct->company_id)
                        ->where('product_id', $lockedProduct->id)
                        ->where('id', '!=', $existingUnit->id)
                        ->update(['is_default_sale' => false]);
                }

                if ($isDefaultPurchase) {
                    ProductUnit::where('company_id', $lockedProduct->company_id)
                        ->where('product_id', $lockedProduct->id)
                        ->where('id', '!=', $existingUnit->id)
                        ->update(['is_default_purchase' => false]);
                }

                $existingUnit->update([
                    'conversion_to_base' => (string) $convBd->toScale(6),
                    'is_default_sale' => $isDefaultSale,
                    'is_default_purchase' => $isDefaultPurchase,
                    'active' => true,
                ]);

                if ($baseUnitToPromote !== null) {
                    $baseUpdates = [];
                    if ($needsSaleFallback) {
                        $baseUpdates['is_default_sale'] = true;
                    }
                    if ($needsPurchaseFallback) {
                        $baseUpdates['is_default_purchase'] = true;
                    }
                    $baseUnitToPromote->update($baseUpdates);
                }

                return $existingUnit->fresh() ?? $existingUnit;
            }

            // Check if adding new unit with false defaults requires base fallback
            $needsSaleFallback = false;
            if (! $isDefaultSale) {
                $hasSaleDefault = ProductUnit::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->where('active', true)
                    ->where('is_default_sale', true)
                    ->exists();

                if (! $hasSaleDefault) {
                    $needsSaleFallback = true;
                }
            }

            $needsPurchaseFallback = false;
            if (! $isDefaultPurchase) {
                $hasPurchaseDefault = ProductUnit::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->where('active', true)
                    ->where('is_default_purchase', true)
                    ->exists();

                if (! $hasPurchaseDefault) {
                    $needsPurchaseFallback = true;
                }
            }

            // Validate healthy base BEFORE creating unit or mutating if fallback is needed
            $baseUnitToPromote = null;
            if ($needsSaleFallback || $needsPurchaseFallback) {
                $baseUnitToPromote = $this->conversionService->getHealthyBaseUnit($lockedProduct);
            }

            // Adding new alternate unit — demote others if setting default
            if ($isDefaultSale) {
                ProductUnit::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->update(['is_default_sale' => false]);
            }

            if ($isDefaultPurchase) {
                ProductUnit::where('company_id', $lockedProduct->company_id)
                    ->where('product_id', $lockedProduct->id)
                    ->update(['is_default_purchase' => false]);
            }

            $newUnit = ProductUnit::create([
                'company_id' => $lockedProduct->company_id,
                'product_id' => $lockedProduct->id,
                'unit_id' => $unitId,
                'conversion_to_base' => (string) $convBd->toScale(6),
                'is_base' => false,
                'is_default_sale' => $isDefaultSale,
                'is_default_purchase' => $isDefaultPurchase,
                'active' => true,
            ]);

            if ($baseUnitToPromote !== null) {
                $baseUpdates = [];
                if ($needsSaleFallback) {
                    $baseUpdates['is_default_sale'] = true;
                }
                if ($needsPurchaseFallback) {
                    $baseUpdates['is_default_purchase'] = true;
                }
                $baseUnitToPromote->update($baseUpdates);
            }

            return $newUnit;
        });
    }

    /**
     * Remove alternate unit under transaction locks.
     * Removal of base unit is strictly forbidden.
     * Removal of unit referenced in stock movements or barcodes is forbidden.
     * Promotes healthy base unit for any dimension for which target was default.
     */
    public function removeAlternateUnit(Product $product, int $productUnitId): void
    {
        DB::transaction(function () use ($product, $productUnitId): void {
            Company::where('id', $product->company_id)->lockForUpdate()->firstOrFail();
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

            // Reject if referenced by product barcodes
            $hasLinkedBarcodes = ProductBarcode::where('company_id', $lockedProduct->company_id)
                ->where('product_id', $lockedProduct->id)
                ->where('unit_id', $targetUnit->unit_id)
                ->exists();

            if ($hasLinkedBarcodes) {
                throw new InvalidArgumentException(__('inventory.cannot_remove_unit_with_barcodes'));
            }

            $hasMovements = StockMovement::where('company_id', $lockedProduct->company_id)
                ->where('product_id', $lockedProduct->id)
                ->where('unit_id', $targetUnit->unit_id)
                ->exists();

            if ($hasMovements) {
                throw new HistoricalConversionLockedException(__('inventory.cannot_remove_unit_with_movement_history'));
            }

            // When removable alternate is default sale and/or purchase, promote the existing healthy base ProductUnit
            $promoteSale = (bool) $targetUnit->is_default_sale;
            $promotePurchase = (bool) $targetUnit->is_default_purchase;

            if ($promoteSale || $promotePurchase) {
                $baseUnit = $this->conversionService->getHealthyBaseUnit($lockedProduct);

                $updates = [];
                if ($promoteSale) {
                    $updates['is_default_sale'] = true;
                }
                if ($promotePurchase) {
                    $updates['is_default_purchase'] = true;
                }
                $baseUnit->update($updates);
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
