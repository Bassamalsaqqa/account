<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InventorySecurityException;
use App\Domain\Inventory\Exceptions\LotAllocationException;
use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryOperation;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseReceiptIntent;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InventoryMovementService
{
    public function __construct(
        protected UnitConversionService $unitConversionService = new UnitConversionService,
    ) {}

    /**
     * Record stock movement(s) atomically under consistent deterministic lock hierarchy.
     *
     * Requirements enforced:
     * - idempotencyKey is required (non-nullable, non-empty).
     * - createdBy is required (non-nullable, positive integer).
     * - createdBy must be a current active member of the company (revalidated under lock).
     * - company context must already match the command companyId; no auto-activation.
     * - Transfer pairs conserve company-wide quantity, value, and average.
     * - Full depletion zero policy: when outbound empties company stock exactly, value and average
     *   reset to 0 and the movement value_delta_base is adjusted to the exact residual to prevent
     *   GL float imbalance (see residual handling below).
     *
     * @return list<StockMovement>
     */
    public function record(StockMovementCommand $command): array
    {
        if ($command->movementType === StockMovement::TYPE_PURCHASE) {
            throw new InvalidInventoryMovementException('Purchase receipts must be recorded by canonical Purchase posting inside its outer transaction.');
        }

        return $this->recordMovement($command);
    }

    /**
     * Purchase posting only: the caller owns the stock + accounting transaction.
     *
     * @return list<StockMovement>
     */
    public function recordPurchaseReceipt(StockMovementCommand $command): array
    {
        if ($command->movementType !== StockMovement::TYPE_PURCHASE) {
            throw new InvalidInventoryMovementException('The Purchase receipt entrypoint accepts only Purchase movements.');
        }
        if (DB::transactionLevel() === 0) {
            throw new InvalidInventoryMovementException('Purchase receipts require an existing outer Purchase posting transaction.');
        }

        return $this->recordMovement($command);
    }

    /** @return list<StockMovement> */
    private function recordMovement(StockMovementCommand $command): array
    {
        // 0. Standalone transfer types rejected
        if (in_array($command->movementType, [StockMovement::TYPE_TRANSFER_IN, StockMovement::TYPE_TRANSFER_OUT], true)) {
            throw new InvalidInventoryMovementException('Transfer movements cannot be recorded via single-movement record(); use transfer() for paired transfers.');
        }

        // 1. Enforce active matching company context
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $command->companyId) {
            throw new InventorySecurityException("Inventory operation requires active matching company context for company [{$command->companyId}].");
        }

        // 2. Enforce authenticated actor equals createdBy
        if (! auth()->check()) {
            throw new InventorySecurityException('Inventory operation requires an authenticated user.');
        }
        $authUser = auth()->user();
        if ((int) $command->createdBy !== (int) $authUser->id) {
            throw new InventorySecurityException("Authenticated user [{$authUser->id}] cannot post inventory movements on behalf of [{$command->createdBy}].");
        }

        // 3. Permission authorization
        if ($command->movementType === StockMovement::TYPE_OPENING_BALANCE) {
            if (! $authUser->hasPermissionTo('inventory.stock.adjust') || ! $authUser->hasPermissionTo('inventory.cost.view')) {
                throw new AuthorizationException('User does not have permission to post opening stock.');
            }
        } elseif ($command->movementType === StockMovement::TYPE_PURCHASE) {
            DB::transaction(function () use ($command, $authUser): void {
                app(SalesActorGuard::class)->lockAndAuthorize($command->companyId, $authUser, 'purchasing.purchase.post');
                app(SalesActorGuard::class)->lockAndAuthorize($command->companyId, $authUser, 'purchasing.cost.view');
            });
        } elseif ($command->movementType === StockMovement::TYPE_SALE) {
            if (! $authUser->hasPermissionTo($command->sourceType === 'sales_return_void' ? 'sales.return.void' : 'sales.invoice.post')) {
                throw new AuthorizationException('User does not have permission to post sales invoice movements.');
            }
        } elseif ($command->movementType === StockMovement::TYPE_SALE_RETURN) {
            if (! $authUser->hasPermissionTo($command->sourceType === 'sales_invoice_void' ? 'sales.invoice.void' : 'sales.return.post')) {
                throw new AuthorizationException('User does not have permission to post sales return movements.');
            }
        } else {
            if (! $authUser->hasPermissionTo('inventory.stock.adjust')) {
                throw new AuthorizationException('User does not have permission to adjust inventory.');
            }
            if ($command->movementType === StockMovement::TYPE_ADJUSTMENT_INCREASE) {
                foreach ($command->lines as $line) {
                    if ($line->unitCostBase !== null && ! $authUser->hasPermissionTo('inventory.cost.view')) {
                        throw new AuthorizationException('User does not have permission to specify explicit unit cost.');
                    }
                }
            }
        }

        foreach ($command->lines as $line) {
            if ($command->movementType === StockMovement::TYPE_SALE_RETURN) {
                if ($line->originalMovementId === null || $line->valueDeltaBase === null) {
                    throw new InvalidInventoryMovementException('Sales compensation requires an original immutable sale movement and exact historical value.');
                }
            } elseif ($command->movementType === StockMovement::TYPE_PURCHASE) {
                if ($line->unitCostBase === null || $line->valueDeltaBase === null || $line->originalMovementId !== null
                    || $command->sourceType !== 'purchase' || $command->sourceLineId === null) {
                    throw new InvalidInventoryMovementException('Purchase receipts require explicit acquisition cost/value and Purchase line provenance.');
                }
            } elseif ($line->originalMovementId !== null || $line->valueDeltaBase !== null) {
                throw new InvalidInventoryMovementException('Ordinary inventory operations cannot override their calculated value or historical source.');
            }
        }

        return DB::transaction(function () use ($command): array {
            // 1. Lock Company row FOR UPDATE and verify active lifecycle
            /** @var Company $company */
            $company = Company::where('id', $command->companyId)->lockForUpdate()->firstOrFail();
            if ($company->status !== 'active') {
                throw new InvalidInventoryMovementException("Cannot execute stock movements on inactive company [{$company->id}].");
            }

            // 2. Require and revalidate actor membership under lock
            $companyUser = CompanyUser::where('company_id', $company->id)
                ->where('user_id', $command->createdBy)
                ->lockForUpdate()
                ->first();

            if (! $companyUser || $companyUser->status !== 'active') {
                throw new InvalidInventoryMovementException("Actor [{$command->createdBy}] is not an active member of company [{$company->id}].");
            }

            if ($command->movementType === StockMovement::TYPE_PURCHASE) {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, auth()->user(), 'purchasing.purchase.post');
                app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, auth()->user(), 'purchasing.cost.view');
            }

            // 3. Idempotency Check — authoritative operation identity & payload comparison
            $requestHash = $this->computeMovementRequestHash($command);
            $expectedKeys = $this->resolveExpectedMovementKeys($command);

            /** @var InventoryOperation|null $existingOp */
            $existingOp = InventoryOperation::where('company_id', $company->id)
                ->where('idempotency_key', $command->idempotencyKey)
                ->first();

            if ($existingOp !== null) {
                if ($existingOp->operation_type !== InventoryOperation::TYPE_MOVEMENT) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used for a {$existingOp->operation_type} operation.");
                }

                if ($existingOp->line_count !== count($command->lines)) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with a different line count ({$existingOp->line_count} vs ".count($command->lines).').');
                }

                if ($existingOp->request_hash !== null && $existingOp->request_hash !== $requestHash) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different request parameters.");
                }

                /** @var Collection<int, StockMovement> $existingMovements */
                $existingMovements = StockMovement::where('company_id', $company->id)
                    ->where('inventory_operation_id', $existingOp->id)
                    ->orderBy('id')
                    ->get();

                if ($existingMovements->count() !== count($command->lines)) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously partially executed or used with a different number of lines.");
                }

                $this->validateIdempotencyMatch($existingMovements->all(), $command);

                return $existingMovements->all();
            }

            // Fallback for pre-migration movements or legacy idempotency_key
            /** @var Collection<int, StockMovement> $legacyMovements */
            $legacyMovements = StockMovement::where('company_id', $company->id)
                ->whereIn('idempotency_key', $expectedKeys)
                ->orderBy('id')
                ->get();

            if ($legacyMovements->isNotEmpty()) {
                if ($legacyMovements->count() !== count($expectedKeys)) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously partially executed or used with a different number of lines.");
                }
                $this->validateIdempotencyMatch($legacyMovements->all(), $command);

                return $legacyMovements->all();
            }

            // Check if legacy movement was recorded with different shape under the same root key
            $legacyRoot = StockMovement::where('company_id', $company->id)
                ->where('idempotency_key', $command->idempotencyKey)
                ->first();
            if ($legacyRoot !== null && count($command->lines) !== 1) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with a different number of lines.");
            }
            $legacyChild0 = StockMovement::where('company_id', $company->id)
                ->where('idempotency_key', "{$command->idempotencyKey}:0")
                ->first();
            if ($legacyChild0 !== null && count($command->lines) === 1) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with a different number of lines.");
            }

            if ($command->movementType === StockMovement::TYPE_PURCHASE) {
                app(PurchaseReceiptIntent::class)->validate($command);
            }

            // 4. Gather and sort entity IDs for deadlock-free locking
            $productIds = collect($command->lines)->map(fn (StockMovementLineCommand $l) => $l->productId)->unique()->sort()->values()->all();
            $warehouseIds = collect($command->lines)->map(fn (StockMovementLineCommand $l) => $l->warehouseId)->unique()->sort()->values()->all();

            // 5. Lock Products in sorted ID order
            /** @var Collection<int, Product> $lockedProducts */
            $lockedProducts = Product::where('company_id', $company->id)
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedProducts->count() !== count($productIds)) {
                throw new InvalidInventoryMovementException('One or more referenced products not found in active company.');
            }

            foreach ($lockedProducts as $prod) {
                if (! $prod->active) {
                    throw InvalidInventoryMovementException::inactiveProduct($prod->id);
                }
                if (! $prod->track_stock || $prod->product_type !== Product::TYPE_STOCK) {
                    throw InvalidInventoryMovementException::nonStockProduct($prod->id);
                }
            }

            // 6. Lock Warehouses in sorted ID order
            /** @var Collection<int, Warehouse> $lockedWarehouses */
            $lockedWarehouses = Warehouse::where('company_id', $company->id)
                ->whereIn('id', $warehouseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedWarehouses->count() !== count($warehouseIds)) {
                throw new InvalidInventoryMovementException('One or more referenced warehouses not found in active company.');
            }

            foreach ($lockedWarehouses as $wh) {
                if (! $wh->active) {
                    throw InvalidInventoryMovementException::inactiveWarehouse($wh->id);
                }
            }

            // 7. Lock Warehouse Balances in sorted (warehouse_id, product_id) order
            $existingBalances = InventoryBalance::where('company_id', $company->id)
                ->whereIn('product_id', $productIds)
                ->whereIn('warehouse_id', $warehouseIds)
                ->orderBy('warehouse_id')
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            $balancesByKey = [];
            foreach ($existingBalances as $b) {
                $balancesByKey["{$b->warehouse_id}:{$b->product_id}"] = $b;
            }

            // 8. Lock Product Cost States in sorted product_id order
            $existingCostStates = InventoryCostState::where('company_id', $company->id)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // 9. Process lines and record movements
            $createdMovements = [];

            $operation = InventoryOperation::create([
                'company_id' => $company->id,
                'idempotency_key' => $command->idempotencyKey,
                'request_hash' => $requestHash,
                'operation_type' => InventoryOperation::TYPE_MOVEMENT,
                'line_count' => count($command->lines),
                'created_by' => $command->createdBy,
            ]);

            foreach ($command->lines as $idx => $line) {
                /** @var Product $product */
                $product = $lockedProducts->get($line->productId);
                /** @var Warehouse $warehouse */
                $warehouse = $lockedWarehouses->get($line->warehouseId);

                // Determine base quantity and conversion factor through canonical unit conversion layer
                $unitId = $line->unitId ?? $product->base_unit_id;
                /** @var ProductUnit|null $prodUnit */
                $prodUnit = ProductUnit::where('company_id', $company->id)
                    ->where('product_id', $product->id)
                    ->where('unit_id', $unitId)
                    ->with('unit')
                    ->lockForUpdate()
                    ->first();

                if ($prodUnit === null) {
                    throw new InvalidInventoryMovementException("Unit [{$unitId}] is not an active configured unit for product [{$product->id}].");
                }

                $sourceQty = $line->quantity;
                $quantityBase = $this->unitConversionService->toBase($sourceQty, $prodUnit);
                $conversionToBase = BigDecimal::of((string) $prodUnit->conversion_to_base);

                if ($quantityBase->isLessThanOrEqualTo(0)) {
                    throw new InvalidInventoryMovementException("Stock movement line quantity must be strictly positive (> 0). Given [{$quantityBase}].");
                }

                $isInbound = in_array($command->movementType, [
                    StockMovement::TYPE_OPENING_BALANCE,
                    StockMovement::TYPE_TRANSFER_IN,
                    StockMovement::TYPE_ADJUSTMENT_INCREASE,
                    StockMovement::TYPE_SALE_RETURN,
                    StockMovement::TYPE_PURCHASE,
                ], true);

                $signedDeltaBase = $isInbound ? $quantityBase->toBigDecimal() : $quantityBase->toBigDecimal()->negated();

                // Warehouse balance lock / update
                $balKey = "{$warehouse->id}:{$product->id}";
                if (! isset($balancesByKey[$balKey])) {
                    $balancesByKey[$balKey] = InventoryBalance::create([
                        'company_id' => $company->id,
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'quantity_base' => '0.000000',
                    ]);
                }

                /** @var InventoryBalance $whBalance */
                $whBalance = $balancesByKey[$balKey];
                $currentWhQty = BigDecimal::of((string) $whBalance->quantity_base);
                $newWhQty = $currentWhQty->plus($signedDeltaBase);

                if ($newWhQty->isNegative()) {
                    throw InsufficientStockException::forWarehouse(
                        $product->id,
                        $warehouse->id,
                        $quantityBase->toScale(),
                        (string) $currentWhQty->toScale(6, RoundingMode::HALF_UP)
                    );
                }

                $whBalance->update(['quantity_base' => (string) $newWhQty->toScale(6, RoundingMode::HALF_UP)]);

                // Lot Handling
                $lotId = null;
                if ($product->track_expiry) {
                    $lotId = $this->handleLotAllocation(
                        $company,
                        $product,
                        $warehouse,
                        $line,
                        $isInbound,
                        $quantityBase,
                        $signedDeltaBase,
                        $command
                    );
                } elseif ($line->lotId !== null || $line->lotNumber !== null) {
                    throw LotAllocationException::lotNotAllowedForNonExpiryTracking($product->id);
                }

                // Cost State & Valuation Updates (Company-wide per Product)
                if (! isset($existingCostStates[$product->id])) {
                    $existingCostStates[$product->id] = InventoryCostState::create([
                        'company_id' => $company->id,
                        'product_id' => $product->id,
                        'quantity_base' => '0.000000',
                        'average_cost_base' => '0.000000',
                        'inventory_value_base' => '0.000000',
                    ]);
                }

                /** @var InventoryCostState $costState */
                $costState = $existingCostStates[$product->id];
                $oldCompanyQty = BigDecimal::of((string) $costState->quantity_base);
                $oldCompanyVal = BigDecimal::of((string) $costState->inventory_value_base);
                $oldCompanyAvg = BigDecimal::of((string) $costState->average_cost_base);

                if ($isInbound) {
                    if ($line->unitCostBase === null) {
                        if ($command->movementType === StockMovement::TYPE_ADJUSTMENT_INCREASE) {
                            if ($oldCompanyQty->isLessThanOrEqualTo(0)) {
                                throw new InvalidInventoryMovementException(
                                    "Stock adjustment increase without explicit unit cost requires positive existing company stock for product [{$product->id}]. Please specify an explicit unit cost authorized by valuation permissions."
                                );
                            }
                            $inUnitCost = $oldCompanyAvg;
                        } else {
                            throw InvalidInventoryMovementException::missingCostForInbound($product->id);
                        }
                    } else {
                        if (! is_numeric($line->unitCostBase)) {
                            throw new InvalidInventoryMovementException("Inbound unit cost must be numeric. Given [{$line->unitCostBase}].");
                        }
                        $inUnitCost = BigDecimal::of($line->unitCostBase);
                        if ($inUnitCost->isNegative()) {
                            throw new InvalidInventoryMovementException("Inbound unit cost cannot be negative. Given [{$inUnitCost}].");
                        }
                        if ($inUnitCost->strippedOfTrailingZeros()->getScale() > 6) {
                            throw new InvalidInventoryMovementException("Inbound unit cost [{$line->unitCostBase}] exceeds maximum precision of 6 decimal places.");
                        }
                        $maxCost = BigDecimal::of('99999999999999.999999');
                        if ($inUnitCost->isGreaterThan($maxCost)) {
                            throw new InvalidInventoryMovementException("Inbound unit cost [{$line->unitCostBase}] exceeds DECIMAL(20,6) boundary.");
                        }
                    }

                    if ($line->originalMovementId !== null) {
                        $lineValDelta = app(HistoricalSaleCost::class)->value(
                            $company->id, $line->originalMovementId, $product->id, $warehouse->id, $lotId,
                            $command->sourceType, $command->sourceId, $quantityBase->toBigDecimal()
                        );
                        $original = StockMovement::where('id', $line->originalMovementId)->firstOrFail();
                        if (! BigDecimal::of((string) $original->unit_cost_base)->isEqualTo($inUnitCost)
                            || ! $lineValDelta->isEqualTo(BigDecimal::of((string) $line->valueDeltaBase))) {
                            throw new InvalidInventoryMovementException('Historical compensation cost/value does not match the original sale allocation.');
                        }
                    } elseif ($command->movementType === StockMovement::TYPE_PURCHASE) {
                        $lineValDelta = BigDecimal::of($line->valueDeltaBase)->toScale(6);
                    } else {
                        $lineValDelta = $quantityBase->toBigDecimal()->multipliedBy($inUnitCost)->toScale(6, RoundingMode::HALF_UP);
                    }
                    $newCompanyQty = $oldCompanyQty->plus($quantityBase->toBigDecimal());
                    $newCompanyVal = $oldCompanyVal->plus($lineValDelta);
                    $newCompanyAvg = $newCompanyQty->isZero()
                        ? BigDecimal::zero()
                        : $newCompanyVal->dividedBy($newCompanyQty, 6, RoundingMode::HALF_UP);

                    $movementUnitCost = $inUnitCost;
                    $movementValueDelta = $lineValDelta;
                } else {
                    // Outbound movement
                    if ($oldCompanyQty->isLessThan($quantityBase->toBigDecimal())) {
                        throw InsufficientStockException::forCompany(
                            $product->id,
                            $quantityBase->toScale(),
                            (string) $oldCompanyQty->toScale(6, RoundingMode::HALF_UP)
                        );
                    }

                    $movementUnitCost = $oldCompanyAvg;
                    $newCompanyQty = $oldCompanyQty->minus($quantityBase->toBigDecimal());

                    if ($newCompanyQty->isZero()) {
                        // Full depletion: movement value equals exact remaining value in cost state
                        // This prevents GL residual from rounding (qty * avg may differ from cached value)
                        $movementValueDelta = $oldCompanyVal->negated();
                        $newCompanyVal = BigDecimal::zero();
                        $newCompanyAvg = BigDecimal::zero();
                        $movementUnitCost = $oldCompanyAvg;
                    } else {
                        // Partial depletion: preserve moving average cost snapshot until zero quantity
                        // Never produce negative remaining value
                        $outVal = $quantityBase->toBigDecimal()->multipliedBy($oldCompanyAvg)->toScale(6, RoundingMode::HALF_UP);
                        if ($outVal->isGreaterThan($oldCompanyVal)) {
                            $outVal = $oldCompanyVal;
                        }
                        $movementValueDelta = $outVal->negated();
                        $newCompanyVal = $oldCompanyVal->minus($outVal);
                        $newCompanyAvg = $oldCompanyAvg;
                        $movementUnitCost = $oldCompanyAvg;
                    }
                }

                $costState->update([
                    'quantity_base' => (string) $newCompanyQty->toScale(6, RoundingMode::HALF_UP),
                    'average_cost_base' => (string) $newCompanyAvg->toScale(6, RoundingMode::HALF_UP),
                    'inventory_value_base' => (string) $newCompanyVal->toScale(6, RoundingMode::HALF_UP),
                ]);

                // Create immutable StockMovement — no created_by fallback
                $movement = StockMovement::create([
                    'company_id' => $company->id,
                    'inventory_operation_id' => $operation->id,
                    'reversal_of_id' => $line->originalMovementId,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'lot_id' => $lotId,
                    'movement_type' => $command->movementType,
                    'movement_date' => $command->movementDate,
                    'quantity_delta_base' => (string) $signedDeltaBase->toScale(6, RoundingMode::HALF_UP),
                    'unit_cost_base' => (string) $movementUnitCost->toScale(6, RoundingMode::HALF_UP),
                    'value_delta_base' => (string) $movementValueDelta->toScale(6, RoundingMode::HALF_UP),
                    'average_cost_after' => (string) $newCompanyAvg->toScale(6, RoundingMode::HALF_UP),
                    'quantity_after_product_company' => (string) $newCompanyQty->toScale(6, RoundingMode::HALF_UP),
                    'unit_id' => $line->unitId,
                    'source_quantity' => (string) $sourceQty->toScale(6, RoundingMode::HALF_UP),
                    'conversion_to_base' => (string) $conversionToBase->toScale(6, RoundingMode::HALF_UP),
                    'source_type' => $command->sourceType,
                    'source_id' => $command->sourceId,
                    'source_line_id' => $command->sourceLineId,
                    'idempotency_key' => count($command->lines) === 1 ? $command->idempotencyKey : "{$command->idempotencyKey}:{$idx}",
                    'reason' => $command->reason,
                    'created_by' => $command->createdBy,
                    'created_at' => now(),
                ]);

                $createdMovements[] = $movement;
            }

            return $createdMovements;
        });
    }

    /**
     * Handle lot creation or consumption for expiry-tracked products.
     * Validates that disposal lots are actually expired (movement_date > expiry_date).
     */
    private function handleLotAllocation(
        Company $company,
        Product $product,
        Warehouse $warehouse,
        StockMovementLineCommand $line,
        bool $isInbound,
        Quantity $quantityBase,
        BigDecimal $signedDeltaBase,
        StockMovementCommand $command
    ): int {
        if ($isInbound) {
            // Inbound: If existing lotId provided, lock and reuse it; otherwise create new lot
            if ($line->lotId !== null) {
                /** @var InventoryLot $lot */
                $lot = InventoryLot::where('company_id', $company->id)
                    ->where('product_id', $product->id)
                    ->where('id', $line->lotId)
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                $lot = InventoryLot::create([
                    'company_id' => $company->id,
                    'product_id' => $product->id,
                    'lot_number' => $line->lotNumber,
                    'expiry_date' => $line->expiryDate,
                    'received_date' => $command->movementDate,
                    'source_type' => $command->sourceType,
                    'source_id' => $command->sourceId,
                    'source_line_id' => $command->sourceLineId,
                ]);
            }
        } else {
            // Outbound: Must specify lotId
            if ($line->lotId === null) {
                throw LotAllocationException::lotRequiredForExpiryTracking($product->id);
            }

            /** @var InventoryLot|null $lot */
            $lot = InventoryLot::where('company_id', $company->id)
                ->where('product_id', $product->id)
                ->where('id', $line->lotId)
                ->lockForUpdate()
                ->first();

            if ($lot === null) {
                throw LotAllocationException::mismatchedProductLot($product->id, $line->lotId);
            }

            // Expiry date verification
            $movementDate = $command->movementDate;
            $lotExpiry = $lot->expiry_date ? substr((string) $lot->getRawOriginal('expiry_date', (string) $lot->expiry_date), 0, 10) : null;
            if ($command->movementType === StockMovement::TYPE_EXPIRY_DISPOSAL) {
                if ($lotExpiry === null || $lotExpiry >= $movementDate) {
                    throw new InvalidInventoryMovementException(
                        "Lot [{$lot->id}] is not expired as of movement date [{$movementDate}]. Expiry disposal requires an actually expired lot."
                    );
                }
            } else {
                // Normal outbound stock issue excludes expired lots
                if ($lotExpiry !== null && $lotExpiry < $movementDate) {
                    throw new InvalidInventoryMovementException(
                        "Lot [{$lot->id}] has expired as of [{$movementDate}] (expiry: [{$lotExpiry}]) and cannot be consumed for regular operations."
                    );
                }
            }
        }

        // Lock / Update lot balance
        /** @var InventoryLotBalance|null $lotBalance */
        $lotBalance = InventoryLotBalance::where('company_id', $company->id)
            ->where('lot_id', $lot->id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->first();

        if ($lotBalance === null) {
            $lotBalance = InventoryLotBalance::create([
                'company_id' => $company->id,
                'product_id' => $product->id,
                'lot_id' => $lot->id,
                'warehouse_id' => $warehouse->id,
                'quantity_base' => '0.000000',
            ]);
        }

        $currentLotQty = BigDecimal::of((string) $lotBalance->quantity_base);
        $newLotQty = $currentLotQty->plus($signedDeltaBase);

        if ($newLotQty->isNegative()) {
            throw InsufficientStockException::forLot(
                $product->id,
                $warehouse->id,
                $lot->id,
                $quantityBase->toScale(),
                (string) $currentLotQty->toScale(6, RoundingMode::HALF_UP)
            );
        }

        $lotBalance->update(['quantity_base' => (string) $newLotQty->toScale(6, RoundingMode::HALF_UP)]);

        return $lot->id;
    }

    /**
     * Validate that existing movements match incoming command payload on idempotent retry.
     * Full canonical payload comparison: count, product, warehouse, type, quantity, cost, lot, source, date.
     *
     * @param  list<StockMovement>  $existing
     */
    private function validateIdempotencyMatch(array $existing, StockMovementCommand $command): void
    {
        if (count($existing) !== count($command->lines)) {
            throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with a different number of lines.");
        }

        foreach ($existing as $idx => $m) {
            $cmdLine = $command->lines[$idx];

            if ((int) $m->created_by !== (int) $command->createdBy) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used by a different actor.");
            }

            if ($m->product_id !== $cmdLine->productId || $m->warehouse_id !== $cmdLine->warehouseId) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different product/warehouse.");
            }

            if ($m->movement_type !== $command->movementType) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different movement type.");
            }

            $rawDate = (string) $m->getRawOriginal('movement_date', (string) $m->movement_date);
            $mDate = substr($rawDate, 0, 10);
            if ($mDate !== $command->movementDate) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different movement date.");
            }

            if ($m->source_type !== $command->sourceType || (int) $m->source_id !== (int) $command->sourceId) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different source.");
            }

            if ((int) ($m->source_line_id ?? 0) !== (int) ($command->sourceLineId ?? 0)) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different source line ID.");
            }

            // Unit comparison
            if ($m->unit_id !== $cmdLine->unitId) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different unit.");
            }

            // Quantity comparison (compare against source_quantity if recorded, otherwise quantity_delta_base)
            $storedSourceQty = $m->source_quantity !== null
                ? BigDecimal::of((string) $m->source_quantity)->toScale(6, RoundingMode::HALF_UP)->abs()
                : BigDecimal::of((string) $m->quantity_delta_base)->toScale(6, RoundingMode::HALF_UP)->abs();
            $cmdQtyScaled = $cmdLine->quantity->toBigDecimal()->toScale(6, RoundingMode::HALF_UP)->abs();
            if (! $storedSourceQty->isEqualTo($cmdQtyScaled)) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different quantity.");
            }

            // Cost comparison: inbound compares unit cost if explicit, or allows null for adjustment increase; outbound forbids command unit cost
            $isInbound = in_array($command->movementType, [StockMovement::TYPE_OPENING_BALANCE, StockMovement::TYPE_ADJUSTMENT_INCREASE, StockMovement::TYPE_SALE_RETURN, StockMovement::TYPE_PURCHASE], true);
            if ($isInbound) {
                if ($cmdLine->unitCostBase !== null) {
                    $existingCost = BigDecimal::of((string) $m->unit_cost_base)->toScale(6, RoundingMode::HALF_UP);
                    $cmdCost = BigDecimal::of($cmdLine->unitCostBase)->toScale(6, RoundingMode::HALF_UP);
                    if (! $existingCost->isEqualTo($cmdCost)) {
                        throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different unit cost.");
                    }
                } else {
                    if ($command->movementType !== StockMovement::TYPE_ADJUSTMENT_INCREASE) {
                        throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with a unit cost.");
                    }
                }
            } else {
                if ($cmdLine->unitCostBase !== null) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used without a unit cost.");
                }
            }

            // Lot comparison: handle existing lot vs new lot created by original command (with or without lotNumber)
            if ($cmdLine->lotId !== null) {
                if ($m->lot_id !== $cmdLine->lotId) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different lot assignment.");
                }
            } else {
                if ($cmdLine->lotNumber !== null || $cmdLine->expiryDate !== null || ($command->movementType === StockMovement::TYPE_PURCHASE && $m->lot_id !== null)) {
                    if ($m->lot_id === null) {
                        throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used without a lot.");
                    }
                    $m->loadMissing('lot');
                    $lot = $m->lot;
                    if (! $lot) {
                        throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] references missing lot.");
                    }
                    if ($lot->lot_number !== $cmdLine->lotNumber) {
                        throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different lot number.");
                    }
                    $cmdExpiry = $cmdLine->expiryDate ? substr($cmdLine->expiryDate, 0, 10) : null;
                    $rawLotExpiry = $lot->expiry_date ? substr((string) $lot->getRawOriginal('expiry_date', (string) $lot->expiry_date), 0, 10) : null;
                    if ($cmdExpiry !== $rawLotExpiry) {
                        throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different expiry date.");
                    }
                } else {
                    if ($m->lot_id !== null) {
                        throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with a lot assignment.");
                    }
                }
            }

            // Reason comparison
            if ($cmdLine->valueDeltaBase !== null && ! BigDecimal::of($m->value_delta_base)->isEqualTo($cmdLine->valueDeltaBase)) {
                throw new IdempotencyConflictException('Exact acquisition value differs from the existing movement.');
            }
            if ($m->reason !== $command->reason) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different reason.");
            }
        }
    }

    /**
     * Record atomic warehouse-to-warehouse stock transfer.
     * Conserves company-wide total stock quantity, value, and average cost.
     * Transfer pairs never modify product cost state at the company level.
     *
     * @return list<StockMovement>
     */
    public function transfer(StockTransferCommand $command): array
    {
        // 1. Enforce active matching company context
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $command->companyId) {
            throw new InventorySecurityException("Inventory transfer requires active matching company context for company [{$command->companyId}].");
        }

        // 2. Enforce authenticated actor equals createdBy
        if (! auth()->check()) {
            throw new InventorySecurityException('Inventory transfer requires an authenticated user.');
        }
        $authUser = auth()->user();
        if ((int) $command->createdBy !== (int) $authUser->id) {
            throw new InventorySecurityException("Authenticated user [{$authUser->id}] cannot transfer stock on behalf of [{$command->createdBy}].");
        }

        // 3. Permission authorization
        if (! $authUser->hasPermissionTo('inventory.stock.transfer')) {
            throw new AuthorizationException('User does not have permission to transfer stock.');
        }

        return DB::transaction(function () use ($command): array {
            // 1. Lock Company row FOR UPDATE and verify active lifecycle
            /** @var Company $company */
            $company = Company::where('id', $command->companyId)->lockForUpdate()->firstOrFail();
            if ($company->status !== 'active') {
                throw new InvalidInventoryMovementException("Cannot execute stock movements on inactive company [{$company->id}].");
            }

            // 2. Require and revalidate actor membership under lock
            $companyUser = CompanyUser::where('company_id', $company->id)
                ->where('user_id', $command->createdBy)
                ->lockForUpdate()
                ->first();

            if (! $companyUser || $companyUser->status !== 'active') {
                throw new InvalidInventoryMovementException("Actor [{$command->createdBy}] is not an active member of company [{$company->id}].");
            }

            // 3. Validate source and destination differ
            if ($command->sourceWarehouseId === $command->destinationWarehouseId) {
                throw new InvalidInventoryMovementException('Source and destination warehouses cannot be the same.');
            }

            // 4. Idempotency Check — authoritative operation identity & transfer payload comparison
            $requestHash = $this->computeTransferRequestHash($command);
            $expectedKeys = $this->resolveExpectedTransferKeys($command);

            /** @var InventoryOperation|null $existingOp */
            $existingOp = InventoryOperation::where('company_id', $company->id)
                ->where('idempotency_key', $command->idempotencyKey)
                ->first();

            if ($existingOp !== null) {
                if ($existingOp->operation_type !== InventoryOperation::TYPE_TRANSFER) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used for a {$existingOp->operation_type} operation.");
                }

                if ($existingOp->line_count !== count($command->lines)) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with a different line count ({$existingOp->line_count} vs ".count($command->lines).').');
                }

                if ($existingOp->request_hash !== null && $existingOp->request_hash !== $requestHash) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different transfer parameters.");
                }

                /** @var Collection<int, StockMovement> $existingMovements */
                $existingMovements = StockMovement::where('company_id', $company->id)
                    ->where('inventory_operation_id', $existingOp->id)
                    ->orderBy('id')
                    ->get();

                if ($existingMovements->count() !== count($command->lines) * 2) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously partially executed or used with a different line count.");
                }

                $this->validateTransferIdempotencyMatch($existingMovements->all(), $command);

                return $existingMovements->all();
            }

            // Fallback for pre-migration movements or legacy idempotency_key
            /** @var Collection<int, StockMovement> $legacyMovements */
            $legacyMovements = StockMovement::where('company_id', $company->id)
                ->whereIn('idempotency_key', $expectedKeys)
                ->orderBy('id')
                ->get();

            if ($legacyMovements->isNotEmpty()) {
                if ($legacyMovements->count() !== count($expectedKeys)) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously partially executed or used with a different line count.");
                }
                $this->validateTransferIdempotencyMatch($legacyMovements->all(), $command);

                return $legacyMovements->all();
            }

            // Cross-reuse check: was the root key used for a non-transfer movement?
            $legacyRoot = StockMovement::where('company_id', $company->id)
                ->where('idempotency_key', $command->idempotencyKey)
                ->first();
            if ($legacyRoot !== null) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used for a different operation.");
            }

            // 5. Gather and sort product IDs
            $productIds = collect($command->lines)->map(fn (StockTransferLineCommand $l) => $l->productId)->unique()->sort()->values()->all();

            // 6. Lock Products in sorted ID order
            /** @var Collection<int, Product> $lockedProducts */
            $lockedProducts = Product::where('company_id', $company->id)
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedProducts->count() !== count($productIds)) {
                throw new InvalidInventoryMovementException('One or more referenced products not found in active company.');
            }

            foreach ($lockedProducts as $prod) {
                if (! $prod->active) {
                    throw InvalidInventoryMovementException::inactiveProduct($prod->id);
                }
                if (! $prod->track_stock || $prod->product_type !== Product::TYPE_STOCK) {
                    throw InvalidInventoryMovementException::nonStockProduct($prod->id);
                }
            }

            // 7. Lock Warehouses in sorted ID order
            $whIds = collect([$command->sourceWarehouseId, $command->destinationWarehouseId])->sort()->values()->all();
            /** @var Collection<int, Warehouse> $lockedWarehouses */
            $lockedWarehouses = Warehouse::where('company_id', $company->id)
                ->whereIn('id', $whIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedWarehouses->count() !== 2) {
                throw new InvalidInventoryMovementException('One or both transfer warehouses not found in active company.');
            }

            foreach ($lockedWarehouses as $wh) {
                if (! $wh->active) {
                    throw InvalidInventoryMovementException::inactiveWarehouse($wh->id);
                }
            }

            /** @var Warehouse $sourceWarehouse */
            $sourceWarehouse = $lockedWarehouses->get($command->sourceWarehouseId);
            /** @var Warehouse $destWarehouse */
            $destWarehouse = $lockedWarehouses->get($command->destinationWarehouseId);

            // 8. Lock Warehouse Balances in sorted (warehouse_id, product_id) order
            $existingBalances = InventoryBalance::where('company_id', $company->id)
                ->whereIn('product_id', $productIds)
                ->whereIn('warehouse_id', $whIds)
                ->orderBy('warehouse_id')
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            $balancesByKey = [];
            foreach ($existingBalances as $b) {
                $balancesByKey["{$b->warehouse_id}:{$b->product_id}"] = $b;
            }

            // 9. Lock Product Cost States in sorted product_id order — read-only snapshot for transfer
            $existingCostStates = InventoryCostState::where('company_id', $company->id)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $createdMovements = [];

            $operation = InventoryOperation::create([
                'company_id' => $company->id,
                'idempotency_key' => $command->idempotencyKey,
                'request_hash' => $requestHash,
                'operation_type' => InventoryOperation::TYPE_TRANSFER,
                'line_count' => count($command->lines),
                'created_by' => $command->createdBy,
            ]);

            foreach ($command->lines as $idx => $line) {
                /** @var Product $product */
                $product = $lockedProducts->get($line->productId);

                // Conversion to base via canonical unit conversion layer
                $unitId = $line->unitId ?? $product->base_unit_id;
                /** @var ProductUnit|null $prodUnit */
                $prodUnit = ProductUnit::where('company_id', $company->id)
                    ->where('product_id', $product->id)
                    ->where('unit_id', $unitId)
                    ->with('unit')
                    ->lockForUpdate()
                    ->first();

                if ($prodUnit === null) {
                    throw new InvalidInventoryMovementException("Unit [{$unitId}] is not an active configured unit for product [{$product->id}].");
                }

                $sourceQty = $line->quantity;
                $quantityBase = $this->unitConversionService->toBase($sourceQty, $prodUnit);
                $conversionToBase = BigDecimal::of((string) $prodUnit->conversion_to_base);

                if ($quantityBase->isLessThanOrEqualTo(0)) {
                    throw new InvalidInventoryMovementException('Stock transfer line quantity must be strictly positive (> 0).');
                }

                // Balance at source
                $srcKey = "{$sourceWarehouse->id}:{$product->id}";
                if (! isset($balancesByKey[$srcKey])) {
                    $balancesByKey[$srcKey] = InventoryBalance::create([
                        'company_id' => $company->id,
                        'product_id' => $product->id,
                        'warehouse_id' => $sourceWarehouse->id,
                        'quantity_base' => '0.000000',
                    ]);
                }
                /** @var InventoryBalance $srcBalance */
                $srcBalance = $balancesByKey[$srcKey];
                $currentSrcQty = BigDecimal::of((string) $srcBalance->quantity_base);
                $newSrcQty = $currentSrcQty->minus($quantityBase->toBigDecimal());

                if ($newSrcQty->isNegative()) {
                    throw InsufficientStockException::forWarehouse(
                        $product->id,
                        $sourceWarehouse->id,
                        $quantityBase->toScale(),
                        (string) $currentSrcQty->toScale(6, RoundingMode::HALF_UP)
                    );
                }
                $srcBalance->update(['quantity_base' => (string) $newSrcQty->toScale(6, RoundingMode::HALF_UP)]);

                // Balance at destination
                $dstKey = "{$destWarehouse->id}:{$product->id}";
                if (! isset($balancesByKey[$dstKey])) {
                    $balancesByKey[$dstKey] = InventoryBalance::create([
                        'company_id' => $company->id,
                        'product_id' => $product->id,
                        'warehouse_id' => $destWarehouse->id,
                        'quantity_base' => '0.000000',
                    ]);
                }
                /** @var InventoryBalance $dstBalance */
                $dstBalance = $balancesByKey[$dstKey];
                $currentDstQty = BigDecimal::of((string) $dstBalance->quantity_base);
                $newDstQty = $currentDstQty->plus($quantityBase->toBigDecimal());
                $dstBalance->update(['quantity_base' => (string) $newDstQty->toScale(6, RoundingMode::HALF_UP)]);

                // Lot handling
                $lotId = null;
                if ($product->track_expiry) {
                    if ($line->lotId === null) {
                        throw LotAllocationException::lotRequiredForExpiryTracking($product->id);
                    }

                    /** @var InventoryLot|null $lot */
                    $lot = InventoryLot::where('company_id', $company->id)
                        ->where('product_id', $product->id)
                        ->where('id', $line->lotId)
                        ->lockForUpdate()
                        ->first();

                    if ($lot === null) {
                        throw LotAllocationException::mismatchedProductLot($product->id, $line->lotId);
                    }
                    $lotId = $lot->id;

                    // Decrement lot balance at source
                    /** @var InventoryLotBalance|null $srcLotBalance */
                    $srcLotBalance = InventoryLotBalance::where('company_id', $company->id)
                        ->where('lot_id', $lot->id)
                        ->where('warehouse_id', $sourceWarehouse->id)
                        ->lockForUpdate()
                        ->first();

                    $currentSrcLotQty = $srcLotBalance !== null ? BigDecimal::of((string) $srcLotBalance->quantity_base) : BigDecimal::zero();
                    $newSrcLotQty = $currentSrcLotQty->minus($quantityBase->toBigDecimal());

                    if ($newSrcLotQty->isNegative()) {
                        throw InsufficientStockException::forLot(
                            $product->id,
                            $sourceWarehouse->id,
                            $lot->id,
                            $quantityBase->toScale(),
                            (string) $currentSrcLotQty->toScale(6, RoundingMode::HALF_UP)
                        );
                    }
                    if ($srcLotBalance !== null) {
                        $srcLotBalance->update(['quantity_base' => (string) $newSrcLotQty->toScale(6, RoundingMode::HALF_UP)]);
                    }

                    // Increment lot balance at destination
                    /** @var InventoryLotBalance|null $dstLotBalance */
                    $dstLotBalance = InventoryLotBalance::where('company_id', $company->id)
                        ->where('lot_id', $lot->id)
                        ->where('warehouse_id', $destWarehouse->id)
                        ->lockForUpdate()
                        ->first();

                    if ($dstLotBalance === null) {
                        $dstLotBalance = InventoryLotBalance::create([
                            'company_id' => $company->id,
                            'product_id' => $product->id,
                            'lot_id' => $lot->id,
                            'warehouse_id' => $destWarehouse->id,
                            'quantity_base' => '0.000000',
                        ]);
                    }
                    $currentDstLotQty = BigDecimal::of((string) $dstLotBalance->quantity_base);
                    $newDstLotQty = $currentDstLotQty->plus($quantityBase->toBigDecimal());
                    $dstLotBalance->update(['quantity_base' => (string) $newDstLotQty->toScale(6, RoundingMode::HALF_UP)]);
                } elseif ($line->lotId !== null) {
                    throw LotAllocationException::lotNotAllowedForNonExpiryTracking($product->id);
                }

                // Cost Snapshot (unchanged at company level — transfer never changes product cost/value/qty)
                /** @var InventoryCostState|null $costStateSnap */
                $costStateSnap = $existingCostStates->get($product->id);
                $currentAvg = $costStateSnap !== null
                    ? BigDecimal::of((string) $costStateSnap->average_cost_base)
                    : BigDecimal::zero();
                $companyQtySnap = $costStateSnap !== null ? (string) $costStateSnap->quantity_base : '0.000000';

                $transferredValue = $quantityBase->toBigDecimal()->multipliedBy($currentAvg)->toScale(6, RoundingMode::HALF_UP);

                $childKeyOut = count($command->lines) === 1
                    ? "{$command->idempotencyKey}:out"
                    : "{$command->idempotencyKey}:out:{$idx}";
                $childKeyIn = count($command->lines) === 1
                    ? "{$command->idempotencyKey}:in"
                    : "{$command->idempotencyKey}:in:{$idx}";

                // Create paired movements — transfer does NOT change InventoryCostState
                // 1. Transfer Out
                $movementOut = StockMovement::create([
                    'company_id' => $company->id,
                    'inventory_operation_id' => $operation->id,
                    'product_id' => $product->id,
                    'warehouse_id' => $sourceWarehouse->id,
                    'lot_id' => $lotId,
                    'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
                    'movement_date' => $command->movementDate,
                    'quantity_delta_base' => (string) $quantityBase->toBigDecimal()->negated()->toScale(6, RoundingMode::HALF_UP),
                    'unit_cost_base' => (string) $currentAvg->toScale(6, RoundingMode::HALF_UP),
                    'value_delta_base' => (string) $transferredValue->negated()->toScale(6, RoundingMode::HALF_UP),
                    'average_cost_after' => (string) $currentAvg->toScale(6, RoundingMode::HALF_UP),
                    'quantity_after_product_company' => $companyQtySnap,
                    'unit_id' => $line->unitId,
                    'source_quantity' => (string) $sourceQty->toScale(6, RoundingMode::HALF_UP),
                    'conversion_to_base' => (string) $conversionToBase->toScale(6, RoundingMode::HALF_UP),
                    'source_type' => $command->sourceType,
                    'source_id' => $command->sourceId,
                    'source_line_id' => null,
                    'idempotency_key' => $childKeyOut,
                    'reason' => $command->reason,
                    'created_by' => $command->createdBy,
                    'created_at' => now(),
                ]);

                // 2. Transfer In
                $movementIn = StockMovement::create([
                    'company_id' => $company->id,
                    'inventory_operation_id' => $operation->id,
                    'product_id' => $product->id,
                    'warehouse_id' => $destWarehouse->id,
                    'lot_id' => $lotId,
                    'movement_type' => StockMovement::TYPE_TRANSFER_IN,
                    'movement_date' => $command->movementDate,
                    'quantity_delta_base' => (string) $quantityBase->toBigDecimal()->toScale(6, RoundingMode::HALF_UP),
                    'unit_cost_base' => (string) $currentAvg->toScale(6, RoundingMode::HALF_UP),
                    'value_delta_base' => (string) $transferredValue->toScale(6, RoundingMode::HALF_UP),
                    'average_cost_after' => (string) $currentAvg->toScale(6, RoundingMode::HALF_UP),
                    'quantity_after_product_company' => $companyQtySnap,
                    'unit_id' => $line->unitId,
                    'source_quantity' => (string) $sourceQty->toScale(6, RoundingMode::HALF_UP),
                    'conversion_to_base' => (string) $conversionToBase->toScale(6, RoundingMode::HALF_UP),
                    'source_type' => $command->sourceType,
                    'source_id' => $command->sourceId,
                    'source_line_id' => null,
                    'idempotency_key' => $childKeyIn,
                    'reason' => $command->reason,
                    'created_by' => $command->createdBy,
                    'created_at' => now(),
                ]);

                $createdMovements[] = $movementOut;
                $createdMovements[] = $movementIn;
            }

            return $createdMovements;
        });
    }

    /**
     * Validate that existing transfer movements match incoming command payload on idempotent retry.
     * Checks paired count, product, warehouses, quantity, cost snapshot, lot, source, date.
     *
     * @param  list<StockMovement>  $existing
     */
    private function validateTransferIdempotencyMatch(array $existing, StockTransferCommand $command): void
    {
        $expectedCount = count($command->lines) * 2;
        if (count($existing) !== $expectedCount) {
            throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously used with different line count for transfer.");
        }

        // Check pairs: first half are OUT, second half are IN (by order)
        $outMovements = array_filter($existing, fn ($m) => $m->movement_type === StockMovement::TYPE_TRANSFER_OUT);
        $inMovements = array_filter($existing, fn ($m) => $m->movement_type === StockMovement::TYPE_TRANSFER_IN);

        if (count($outMovements) !== count($inMovements)) {
            throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] has unbalanced transfer pairs.");
        }

        foreach ($command->lines as $idx => $line) {
            $outArr = array_values($outMovements);
            $inArr = array_values($inMovements);
            $out = $outArr[$idx] ?? null;
            $in = $inArr[$idx] ?? null;

            if ($out === null || $in === null) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] transfer pair missing at index [{$idx}].");
            }

            // OUT must be from source warehouse
            if ($out->warehouse_id !== $command->sourceWarehouseId) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] transfer out warehouse mismatch.");
            }

            // IN must be at destination warehouse
            if ($in->warehouse_id !== $command->destinationWarehouseId) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] transfer in warehouse mismatch.");
            }

            // Product must match
            if ($out->product_id !== $line->productId || $in->product_id !== $line->productId) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] transfer product mismatch at index [{$idx}].");
            }

            // Quantity and unit comparison: compare original source quantity and unit
            $cmdSourceQty = $line->quantity->toBigDecimal()->toScale(6, RoundingMode::HALF_UP);
            $storedSourceQty = $out->source_quantity !== null
                ? BigDecimal::of((string) $out->source_quantity)->toScale(6, RoundingMode::HALF_UP)
                : BigDecimal::of((string) $out->quantity_delta_base)->abs()->toScale(6, RoundingMode::HALF_UP);

            if (! $cmdSourceQty->isEqualTo($storedSourceQty)) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] transfer quantity mismatch at index [{$idx}].");
            }

            if ($out->unit_id !== $line->unitId || $in->unit_id !== $line->unitId) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] transfer unit mismatch at index [{$idx}].");
            }

            // Actor comparison
            if ((int) $out->created_by !== (int) $command->createdBy || (int) $in->created_by !== (int) $command->createdBy) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] transfer actor mismatch at index [{$idx}].");
            }

            // Lot must match
            if ($out->lot_id !== $line->lotId || $in->lot_id !== $line->lotId) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] transfer lot mismatch at index [{$idx}].");
            }

            // Date must match
            $rawOutDate = (string) $out->getRawOriginal('movement_date', (string) $out->movement_date);
            $rawInDate = (string) $in->getRawOriginal('movement_date', (string) $in->movement_date);
            $outDate = substr($rawOutDate, 0, 10);
            $inDate = substr($rawInDate, 0, 10);
            if ($outDate !== $command->movementDate || $inDate !== $command->movementDate) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] transfer date mismatch.");
            }

            // Source must match
            if ($out->source_type !== $command->sourceType || $out->source_id !== $command->sourceId) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] transfer source mismatch.");
            }

            // Reason must match
            if ($out->reason !== $command->reason) {
                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] transfer reason mismatch.");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveExpectedMovementKeys(StockMovementCommand $command): array
    {
        $rawKey = $command->idempotencyKey;
        if (empty(trim($rawKey))) {
            throw new InvalidInventoryMovementException('Idempotency key cannot be empty.');
        }
        if (strlen($rawKey) > 128) {
            throw new InvalidInventoryMovementException('Idempotency key exceeds maximum length of 128 characters.');
        }
        if (preg_match('/[\r\n\t]/', $rawKey)) {
            throw new InvalidInventoryMovementException('Idempotency key contains invalid whitespace characters.');
        }
        if (preg_match('/:\d+$/', $rawKey) || preg_match('/:(in|out)(:\d+)?$/', $rawKey)) {
            throw new InvalidInventoryMovementException("Idempotency key format is reserved for child operation keys: [{$rawKey}].");
        }

        $expectedKeys = [];
        if (count($command->lines) === 1) {
            $expectedKeys = [$rawKey];
        } else {
            for ($idx = 0; $idx < count($command->lines); $idx++) {
                $expectedKeys[] = "{$rawKey}:{$idx}";
            }
        }

        foreach ($expectedKeys as $ek) {
            if (strlen($ek) > 191) {
                throw new InvalidInventoryMovementException('Derived idempotency key exceeds maximum length of 191 characters.');
            }
        }

        return $expectedKeys;
    }

    /**
     * @return list<string>
     */
    private function resolveExpectedTransferKeys(StockTransferCommand $command): array
    {
        $rawKey = $command->idempotencyKey;
        if (empty(trim($rawKey))) {
            throw new InvalidInventoryMovementException('Idempotency key cannot be empty.');
        }
        if (strlen($rawKey) > 128) {
            throw new InvalidInventoryMovementException('Idempotency key exceeds maximum length of 128 characters.');
        }
        if (preg_match('/[\r\n\t]/', $rawKey)) {
            throw new InvalidInventoryMovementException('Idempotency key contains invalid whitespace characters.');
        }
        if (preg_match('/:\d+$/', $rawKey) || preg_match('/:(in|out)(:\d+)?$/', $rawKey)) {
            throw new InvalidInventoryMovementException("Idempotency key format is reserved for child operation keys: [{$rawKey}].");
        }

        $expectedKeys = [];
        for ($idx = 0; $idx < count($command->lines); $idx++) {
            $expectedKeys[] = count($command->lines) === 1 ? "{$rawKey}:out" : "{$rawKey}:out:{$idx}";
            $expectedKeys[] = count($command->lines) === 1 ? "{$rawKey}:in" : "{$rawKey}:in:{$idx}";
        }

        foreach ($expectedKeys as $ek) {
            if (strlen($ek) > 191) {
                throw new InvalidInventoryMovementException('Derived idempotency key exceeds maximum length of 191 characters.');
            }
        }

        return $expectedKeys;
    }

    /**
     * Compute deterministic canonical request fingerprint (SHA-256) for stock movement commands.
     */
    public function computeMovementRequestHash(StockMovementCommand $command): string
    {
        $payload = [
            'op' => InventoryOperation::TYPE_MOVEMENT,
            'company_id' => (int) $command->companyId,
            'movement_type' => $command->movementType,
            'movement_date' => $command->movementDate,
            'source_type' => $command->sourceType,
            'source_id' => (int) $command->sourceId,
            'source_line_id' => $command->sourceLineId !== null ? (int) $command->sourceLineId : null,
            'reason' => $command->reason,
            'created_by' => (int) $command->createdBy,
            'lines' => array_map(function (StockMovementLineCommand $line): array {
                return [
                    'product_id' => (int) $line->productId,
                    'warehouse_id' => (int) $line->warehouseId,
                    'quantity' => (string) $line->quantity->toScale(6, RoundingMode::HALF_UP),
                    'unit_id' => $line->unitId !== null ? (int) $line->unitId : null,
                    'unit_cost_base' => $line->unitCostBase !== null
                        ? (string) BigDecimal::of($line->unitCostBase)->toScale(6, RoundingMode::HALF_UP)
                        : null,
                    'lot_id' => $line->lotId !== null ? (int) $line->lotId : null,
                    'lot_number' => $line->lotNumber,
                    'expiry_date' => $line->expiryDate !== null ? substr($line->expiryDate, 0, 10) : null,
                    'original_movement_id' => $line->originalMovementId,
                    'value_delta_base' => $line->valueDeltaBase !== null
                        ? (string) BigDecimal::of($line->valueDeltaBase)->toScale(6, RoundingMode::HALF_UP)
                        : null,
                ];
            }, $command->lines),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Compute deterministic canonical request fingerprint (SHA-256) for stock transfer commands.
     */
    public function computeTransferRequestHash(StockTransferCommand $command): string
    {
        $payload = [
            'op' => InventoryOperation::TYPE_TRANSFER,
            'company_id' => (int) $command->companyId,
            'source_warehouse_id' => (int) $command->sourceWarehouseId,
            'destination_warehouse_id' => (int) $command->destinationWarehouseId,
            'movement_date' => $command->movementDate,
            'source_type' => $command->sourceType,
            'source_id' => (int) $command->sourceId,
            'reason' => $command->reason,
            'created_by' => (int) $command->createdBy,
            'lines' => array_map(function (StockTransferLineCommand $line): array {
                return [
                    'product_id' => (int) $line->productId,
                    'quantity' => (string) $line->quantity->toScale(6, RoundingMode::HALF_UP),
                    'unit_id' => $line->unitId !== null ? (int) $line->unitId : null,
                    'lot_id' => $line->lotId !== null ? (int) $line->lotId : null,
                ];
            }, $command->lines),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
