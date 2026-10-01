<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\Inventory\DTO\InventoryReconciliationReport;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryOperation;
use App\Models\LedgerAccount;
use App\Models\PostingLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

class InventoryReconciliationService
{
    /**
     * Audit company inventory integrity. Strictly read-only, never mutates state.
     *
     * CONTEXT MODES:
     * - Normal (fromCli = false): Must have matching ambient company context.
     * - System/CLI (fromCli = true): Context-free bounded mode. Rejects if ambient
     *   context is for a different company.
     *
     * Checks performed:
     * A. Foreign key / company coherence on movements.
     * B. Derived warehouse quantities vs cached InventoryBalance (UNION of movement-derived + cache keys).
     *    Phantom cache rows (cached but no movement) are flagged as discrepancies.
     * C. Derived lot quantities vs cached InventoryLotBalance (same UNION approach).
     * D. Expiry-tracked product lot sums per warehouse must equal warehouse balance.
     * E. Non-expiry-tracked products must not have positive lot balances.
     * F. Replay moving weighted average (excluding transfer pairs) vs InventoryCostState.
     * G. Transfer pair symmetry: each transfer operation must have balanced out/in per product/lot/value.
     */
    public function auditCompany(int|Company $company, bool $fromCli = false): InventoryReconciliationReport
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        // Context mode enforcement
        if ($fromCli) {
            $context = app(CompanyContext::class);
            if ($context->hasCompany()) {
                throw new RuntimeException(
                    "System reconciliation mode rejected: ambient company context [{$context->companyId()}] is present. Clear context before running system operations."
                );
            }
        } else {
            $context = app(CompanyContext::class);
            if (! $context->hasCompany()) {
                throw new NoActiveCompanyException('Reconciliation requires an active company context in normal mode.');
            }
            if ($context->companyId() !== $companyId) {
                throw new RuntimeException(
                    "Cannot audit company [{$companyId}] when active context is company [{$context->companyId()}]."
                );
            }
        }

        $auditFn = function () use ($companyId): InventoryReconciliationReport {
            $historyCorruptions = [];
            $cacheDiscrepancies = [];

            // 1. Verify Company exists
            /** @var Company|null $companyModel */
            $companyModel = Company::find($companyId);
            if ($companyModel === null) {
                return new InventoryReconciliationReport(
                    companyId: $companyId,
                    isHealthy: false,
                    discrepancies: ["Company [{$companyId}] does not exist."],
                    checkedProducts: 0,
                    checkedWarehouses: 0,
                    checkedMovements: 0,
                    totalValuationBase: '0.000000',
                    historyCorruptions: ["Company [{$companyId}] does not exist."],
                    cacheDiscrepancies: [],
                );
            }

            // 2. Load entities
            $products = Product::where('company_id', $companyId)->get()->keyBy('id');
            $warehouses = Warehouse::where('company_id', $companyId)->get()->keyBy('id');
            $movements = StockMovement::where('company_id', $companyId)->orderBy('id')->get();
            $balances = InventoryBalance::where('company_id', $companyId)->get();
            $costStates = InventoryCostState::where('company_id', $companyId)->get()->keyBy('product_id');
            $lotBalances = InventoryLotBalance::where('company_id', $companyId)->get();
            $lots = InventoryLot::where('company_id', $companyId)->get()->keyBy('id');

            // Check A: Foreign key and company coherence on movements (History Corruptions)
            foreach ($movements as $m) {
                if (! $products->has($m->product_id)) {
                    $historyCorruptions[] = "Movement [{$m->id}] references product [{$m->product_id}] not belonging to company [{$companyId}].";
                }
                if (! $warehouses->has($m->warehouse_id)) {
                    $historyCorruptions[] = "Movement [{$m->id}] references warehouse [{$m->warehouse_id}] not belonging to company [{$companyId}].";
                }
                if ($m->lot_id !== null && ! $lots->has($m->lot_id)) {
                    $historyCorruptions[] = "Movement [{$m->id}] references lot [{$m->lot_id}] not belonging to company [{$companyId}].";
                }
                // Lot must belong to same product
                if ($m->lot_id !== null && $lots->has($m->lot_id)) {
                    /** @var InventoryLot $lot */
                    $lot = $lots->get($m->lot_id);
                    if ($lot->product_id !== $m->product_id) {
                        $historyCorruptions[] = "Movement [{$m->id}] lot [{$m->lot_id}] belongs to product [{$lot->product_id}] but movement is for product [{$m->product_id}].";
                    }
                }
                // Movement sign must match inbound/outbound convention
                $delta = BigDecimal::of((string) $m->quantity_delta_base);
                $inboundTypes = [StockMovement::TYPE_OPENING_BALANCE, StockMovement::TYPE_TRANSFER_IN, StockMovement::TYPE_ADJUSTMENT_INCREASE];
                $outboundTypes = [StockMovement::TYPE_TRANSFER_OUT, StockMovement::TYPE_ADJUSTMENT_DECREASE, StockMovement::TYPE_DAMAGE_OR_LOSS, StockMovement::TYPE_EXPIRY_DISPOSAL];
                if (in_array($m->movement_type, $inboundTypes, true) && $delta->isNegative()) {
                    $historyCorruptions[] = "Movement [{$m->id}] type [{$m->movement_type}] should have positive quantity but has [{$m->quantity_delta_base}].";
                }
                if (in_array($m->movement_type, $outboundTypes, true) && $delta->isPositive()) {
                    $historyCorruptions[] = "Movement [{$m->id}] type [{$m->movement_type}] should have negative quantity but has [{$m->quantity_delta_base}].";
                }

                // Check operation provenance
                if ($m->getAttribute('inventory_operation_id') === null) {
                    $historyCorruptions[] = "Movement [{$m->id}] has no associated inventory operation.";
                } else {
                    /** @var InventoryOperation|null $op */
                    $op = InventoryOperation::withoutGlobalScopes()->find($m->inventory_operation_id);
                    if ($op === null) {
                        $historyCorruptions[] = "Movement [{$m->id}] references non-existent operation [{$m->inventory_operation_id}].";
                    } elseif ((int) $op->company_id !== (int) $companyId) {
                        $historyCorruptions[] = "Movement [{$m->id}] references operation [{$op->id}] belonging to another company [{$op->company_id}].";
                    } else {
                        if ((int) $m->created_by !== (int) $op->created_by) {
                            $historyCorruptions[] = "Movement [{$m->id}] actor [{$m->created_by}] does not match operation [{$op->id}] creator [{$op->created_by}].";
                        }
                        if (! in_array($op->operation_type, [InventoryOperation::TYPE_MOVEMENT, InventoryOperation::TYPE_TRANSFER], true)) {
                            $historyCorruptions[] = "Movement [{$m->id}] references operation [{$op->id}] with invalid operation_type [{$op->operation_type}].";
                        } elseif ($op->operation_type === InventoryOperation::TYPE_TRANSFER && ! in_array($m->movement_type, [StockMovement::TYPE_TRANSFER_IN, StockMovement::TYPE_TRANSFER_OUT], true)) {
                            $historyCorruptions[] = "Movement [{$m->id}] type [{$m->movement_type}] does not match transfer operation [{$op->id}].";
                        } elseif ($op->operation_type === InventoryOperation::TYPE_MOVEMENT && in_array($m->movement_type, [StockMovement::TYPE_TRANSFER_IN, StockMovement::TYPE_TRANSFER_OUT], true)) {
                            $historyCorruptions[] = "Movement [{$m->id}] transfer type [{$m->movement_type}] does not match movement operation [{$op->id}].";
                        }
                    }
                }
            }

            // Check operation line count and orphan operations
            $movementsByOp = $movements->groupBy('inventory_operation_id');
            $operations = InventoryOperation::where('company_id', $companyId)->get();
            foreach ($operations as $op) {
                if (! in_array($op->operation_type, [InventoryOperation::TYPE_MOVEMENT, InventoryOperation::TYPE_TRANSFER], true)) {
                    $historyCorruptions[] = "Operation [{$op->id}] has invalid operation_type [{$op->operation_type}]. Valid types are [movement, transfer].";

                    continue;
                }

                $opMovements = $movementsByOp->get($op->id, collect());
                $movementCount = $opMovements->count();

                if ($movementCount === 0) {
                    $historyCorruptions[] = "Operation [{$op->id}] has no associated stock movements (orphan operation).";

                    continue;
                }

                if ($op->operation_type === InventoryOperation::TYPE_MOVEMENT) {
                    if ($movementCount !== (int) $op->line_count) {
                        $historyCorruptions[] = "Operation [{$op->id}] line_count [{$op->line_count}] does not match associated movement count [{$movementCount}].";
                    }
                } elseif ($op->operation_type === InventoryOperation::TYPE_TRANSFER) {
                    $expectedCount = (int) $op->line_count * 2;
                    if ($movementCount !== $expectedCount) {
                        $historyCorruptions[] = "Transfer operation [{$op->id}] line_count [{$op->line_count}] expected [{$expectedCount}] movements but found [{$movementCount}].";
                    }
                }
            }

            // Check B: Replay movements to calculate derived warehouse quantities (UNION of derived + cache keys)
            // Also tracks running balance to detect negative running balances in history
            $derivedWarehouseQty = [];
            $derivedLotQty = [];

            foreach ($movements as $m) {
                $whKey = "{$m->warehouse_id}:{$m->product_id}";
                $currentWh = $derivedWarehouseQty[$whKey] ?? BigDecimal::zero();
                $newWh = $currentWh->plus(BigDecimal::of((string) $m->quantity_delta_base));
                if ($newWh->isNegative()) {
                    $historyCorruptions[] = "Movement [{$m->id}] causes negative running balance [{$newWh}] for product [{$m->product_id}] in warehouse [{$m->warehouse_id}].";
                }
                $derivedWarehouseQty[$whKey] = $newWh;

                if ($m->lot_id !== null) {
                    $lotKey = "{$m->warehouse_id}:{$m->product_id}:{$m->lot_id}";
                    $currentLot = $derivedLotQty[$lotKey] ?? BigDecimal::zero();
                    $newLot = $currentLot->plus(BigDecimal::of((string) $m->quantity_delta_base));
                    if ($newLot->isNegative()) {
                        $historyCorruptions[] = "Movement [{$m->id}] causes negative running lot balance [{$newLot}] for lot [{$m->lot_id}] in warehouse [{$m->warehouse_id}].";
                    }
                    $derivedLotQty[$lotKey] = $newLot;
                }
            }

            // Build cache lookup
            $balanceLookup = [];
            foreach ($balances as $b) {
                $key = "{$b->warehouse_id}:{$b->product_id}";
                $balanceLookup[$key] = BigDecimal::of((string) $b->quantity_base);

                // No negative balance allowed in cache
                if ($balanceLookup[$key]->isNegative()) {
                    $cacheDiscrepancies[] = "Negative inventory balance for product [{$b->product_id}] in warehouse [{$b->warehouse_id}]: [{$b->quantity_base}].";
                }
            }

            // Compare from derived (movement) keys
            foreach ($derivedWarehouseQty as $key => $derivedQty) {
                $cachedQty = $balanceLookup[$key] ?? BigDecimal::zero();
                if (! $derivedQty->isEqualTo($cachedQty)) {
                    [$whId, $prodId] = explode(':', $key);
                    $cacheDiscrepancies[] = "Warehouse balance mismatch for product [{$prodId}] in warehouse [{$whId}]: movement derived [{$derivedQty}] vs cached [{$cachedQty}].";
                }
            }

            // Check phantom cache rows: cached rows with no movement derivation
            foreach ($balanceLookup as $key => $cachedQty) {
                if (! $cachedQty->isZero() && ! isset($derivedWarehouseQty[$key])) {
                    [$whId, $prodId] = explode(':', $key);
                    $cacheDiscrepancies[] = "Phantom balance cache row for product [{$prodId}] in warehouse [{$whId}]: cached [{$cachedQty}] but no movements exist.";
                }
            }

            // Check C: Compare derived lot quantities against InventoryLotBalance table (UNION approach)
            $lotBalanceLookup = [];
            foreach ($lotBalances as $lb) {
                $key = "{$lb->warehouse_id}:{$lb->product_id}:{$lb->lot_id}";
                $lotBalanceLookup[$key] = BigDecimal::of((string) $lb->quantity_base);

                // No negative lot balance allowed in cache
                if ($lotBalanceLookup[$key]->isNegative()) {
                    $cacheDiscrepancies[] = "Negative lot balance for lot [{$lb->lot_id}] in warehouse [{$lb->warehouse_id}]: [{$lb->quantity_base}].";
                }
            }

            foreach ($derivedLotQty as $key => $derivedQty) {
                $cachedQty = $lotBalanceLookup[$key] ?? BigDecimal::zero();
                if (! $derivedQty->isEqualTo($cachedQty)) {
                    [$whId, $prodId, $lotId] = explode(':', $key);
                    $cacheDiscrepancies[] = "Lot balance mismatch for lot [{$lotId}], product [{$prodId}] in warehouse [{$whId}]: movement derived [{$derivedQty}] vs cached [{$cachedQty}].";
                }
            }

            // Check phantom lot cache rows
            foreach ($lotBalanceLookup as $key => $cachedQty) {
                if (! $cachedQty->isZero() && ! isset($derivedLotQty[$key])) {
                    [$whId, $prodId, $lotId] = explode(':', $key);
                    $cacheDiscrepancies[] = "Phantom lot balance cache row for lot [{$lotId}], product [{$prodId}] in warehouse [{$whId}]: cached [{$cachedQty}] but no movements exist.";
                }
            }

            // Check D & E: Expiry and non-expiry lot integrity
            foreach ($products as $product) {
                if ($product->track_expiry) {
                    foreach ($warehouses as $wh) {
                        $whKey = "{$wh->id}:{$product->id}";
                        $whQty = $balanceLookup[$whKey] ?? BigDecimal::zero();

                        $lotSum = BigDecimal::zero();
                        foreach ($lotBalances->where('product_id', $product->id)->where('warehouse_id', $wh->id) as $lb) {
                            $lotSum = $lotSum->plus(BigDecimal::of((string) $lb->quantity_base));
                        }

                        if (! $whQty->isEqualTo($lotSum)) {
                            $cacheDiscrepancies[] = "Product [{$product->id}] lot balance sum [{$lotSum}] does not match warehouse [{$wh->id}] balance [{$whQty}].";
                        }
                    }
                } else {
                    // Non-expiry tracked products must not have lot balances > 0
                    $unexpectedLots = $lotBalances->where('product_id', $product->id)->filter(fn ($lb) => ! BigDecimal::of((string) $lb->quantity_base)->isZero());
                    if ($unexpectedLots->isNotEmpty()) {
                        $cacheDiscrepancies[] = "Product [{$product->id}] does not track expiry but has active lot balances.";
                    }
                }
            }

            // Check F: Replay moving weighted average cost & valuation (excluding transfer pairs)
            $totalValuation = BigDecimal::zero();
            foreach ($products as $product) {
                $prodMovements = $movements->where('product_id', $product->id)
                    ->sortBy('id');

                $runningQty = BigDecimal::zero();
                $runningVal = BigDecimal::zero();
                $runningAvg = BigDecimal::zero();

                foreach ($prodMovements as $m) {
                    $delta = BigDecimal::of((string) $m->quantity_delta_base);
                    $valDeltaStored = BigDecimal::of((string) $m->value_delta_base);
                    $storedAvgAfter = BigDecimal::of((string) $m->average_cost_after);
                    $storedQtyAfter = BigDecimal::of((string) $m->quantity_after_product_company);

                    if ($delta->isZero()) {
                        $historyCorruptions[] = "Movement [{$m->id}] for product [{$product->id}] has zero quantity delta.";

                        continue;
                    }

                    if (in_array($m->movement_type, [StockMovement::TYPE_TRANSFER_IN, StockMovement::TYPE_TRANSFER_OUT], true)) {
                        // Transfer movements do not change company-wide cost state, but must snapshot current company cost state
                        $transUnitCost = BigDecimal::of((string) $m->unit_cost_base);
                        if (! $transUnitCost->isEqualTo($runningAvg)) {
                            $historyCorruptions[] = "Movement [{$m->id}] for product [{$product->id}] transfer unit cost snapshot corrupt: stored [{$transUnitCost}] vs expected [{$runningAvg}].";
                        }
                        if (! $storedAvgAfter->isEqualTo($runningAvg)) {
                            $historyCorruptions[] = "Movement [{$m->id}] for product [{$product->id}] transfer average_cost_after corrupt: stored [{$storedAvgAfter}] vs expected [{$runningAvg}].";
                        }
                        if (! $storedQtyAfter->isEqualTo($runningQty)) {
                            $historyCorruptions[] = "Movement [{$m->id}] for product [{$product->id}] transfer quantity_after_product_company corrupt: stored [{$storedQtyAfter}] vs expected [{$runningQty}].";
                        }
                        $expectedTransVal = $delta->abs()->multipliedBy($runningAvg)->toScale(6, RoundingMode::HALF_UP);
                        $expectedTransValDelta = $m->movement_type === StockMovement::TYPE_TRANSFER_OUT
                            ? $expectedTransVal->negated()
                            : $expectedTransVal;
                        if (! $valDeltaStored->isEqualTo($expectedTransValDelta)) {
                            $historyCorruptions[] = "Movement [{$m->id}] for product [{$product->id}] transfer value delta corrupt: stored [{$valDeltaStored}] vs expected [{$expectedTransValDelta}].";
                        }

                        continue;
                    }

                    if ($delta->isPositive()) {
                        // Inbound: use snapshotted unit_cost_base
                        $inUnitCost = BigDecimal::of((string) $m->unit_cost_base);
                        $expectedValDelta = $delta->multipliedBy($inUnitCost)->toScale(6, RoundingMode::HALF_UP);

                        if (! $valDeltaStored->isEqualTo($expectedValDelta)) {
                            $historyCorruptions[] = "Movement [{$m->id}] for product [{$product->id}] inbound value delta corrupt: stored [{$valDeltaStored}] vs expected [{$expectedValDelta}].";
                        }

                        $runningQty = $runningQty->plus($delta);
                        $runningVal = $runningVal->plus($expectedValDelta);
                        $runningAvg = $runningQty->isZero() ? BigDecimal::zero() : $runningVal->dividedBy($runningQty, 6, RoundingMode::HALF_UP);
                    } else {
                        // Outbound: unit_cost_base must snapshot pre-movement running average cost
                        $outUnitCost = BigDecimal::of((string) $m->unit_cost_base);
                        if (! $outUnitCost->isEqualTo($runningAvg)) {
                            $historyCorruptions[] = "Movement [{$m->id}] for product [{$product->id}] outbound unit cost snapshot corrupt: stored [{$outUnitCost}] vs expected [{$runningAvg}].";
                        }

                        $absDelta = $delta->abs();
                        if ($runningQty->isLessThan($absDelta)) {
                            $historyCorruptions[] = "Movement [{$m->id}] for product [{$product->id}] exceeds available company stock: requested [{$absDelta}] vs running [{$runningQty}].";
                        }

                        if ($runningQty->isEqualTo($absDelta)) {
                            // Full depletion: value delta MUST exactly negate previous running value so remaining value is exactly zero
                            $expectedValDelta = $runningVal->negated()->toScale(6, RoundingMode::HALF_UP);
                            if (! $valDeltaStored->isEqualTo($expectedValDelta)) {
                                $historyCorruptions[] = "Movement [{$m->id}] for product [{$product->id}] full depletion value delta corrupt: stored [{$valDeltaStored}] vs expected [{$expectedValDelta}].";
                            }
                            $runningQty = BigDecimal::zero();
                            $runningVal = BigDecimal::zero();
                            $runningAvg = BigDecimal::zero();
                        } elseif ($runningQty->isGreaterThan($absDelta)) {
                            // Partial depletion: preserve moving average cost snapshot until zero quantity
                            $outVal = $absDelta->multipliedBy($runningAvg)->toScale(6, RoundingMode::HALF_UP);
                            if ($outVal->isGreaterThan($runningVal)) {
                                $outVal = $runningVal;
                            }
                            $expectedValDelta = $outVal->negated();

                            if (! $valDeltaStored->isEqualTo($expectedValDelta)) {
                                $historyCorruptions[] = "Movement [{$m->id}] for product [{$product->id}] partial depletion value delta corrupt: stored [{$valDeltaStored}] vs expected [{$expectedValDelta}].";
                            }

                            $runningQty = $runningQty->minus($absDelta);
                            $runningVal = $runningVal->plus($expectedValDelta);
                        } else {
                            // Outbound exceeds runningQty (already flagged as corruption above)
                            $runningQty = BigDecimal::zero();
                            $runningVal = BigDecimal::zero();
                            $runningAvg = BigDecimal::zero();
                        }
                    }

                    // Validate after-state snapshots
                    if (! $storedQtyAfter->isEqualTo($runningQty)) {
                        $historyCorruptions[] = "Movement [{$m->id}] quantity_after_product_company corrupt: stored [{$storedQtyAfter}] vs replayed [{$runningQty}].";
                    }
                    if (! $storedAvgAfter->isEqualTo($runningAvg)) {
                        $historyCorruptions[] = "Movement [{$m->id}] average_cost_after corrupt: stored [{$storedAvgAfter}] vs replayed [{$runningAvg}].";
                    }
                }

                /** @var InventoryCostState|null $costState */
                $costState = $costStates->get($product->id);
                $cachedQty = $costState !== null ? BigDecimal::of((string) $costState->quantity_base) : BigDecimal::zero();
                $cachedVal = $costState !== null ? BigDecimal::of((string) $costState->inventory_value_base) : BigDecimal::zero();
                $cachedAvg = $costState !== null ? BigDecimal::of((string) $costState->average_cost_base) : BigDecimal::zero();

                if (! $runningQty->isEqualTo($cachedQty)) {
                    $cacheDiscrepancies[] = "Product [{$product->id}] company quantity mismatch: movement derived [{$runningQty}] vs cost state [{$cachedQty}].";
                }
                if (! $runningVal->isEqualTo($cachedVal)) {
                    $cacheDiscrepancies[] = "Product [{$product->id}] inventory value mismatch: movement derived [{$runningVal}] vs cost state [{$cachedVal}].";
                }
                if (! $runningAvg->isEqualTo($cachedAvg)) {
                    $cacheDiscrepancies[] = "Product [{$product->id}] average cost mismatch: movement derived [{$runningAvg}] vs cost state [{$cachedAvg}].";
                }

                $totalValuation = $totalValuation->plus($cachedVal);
            }

            // Check G: Transfer pair symmetry — per idempotency_key, each OUT must have matching IN
            $transferOuts = $movements->where('movement_type', StockMovement::TYPE_TRANSFER_OUT)->values();
            $transferIns = $movements->where('movement_type', StockMovement::TYPE_TRANSFER_IN)->values();

            if ($transferOuts->count() !== $transferIns->count()) {
                $historyCorruptions[] = "Transfer count mismatch: [{$transferOuts->count()}] transfer_out movements vs [{$transferIns->count()}] transfer_in movements.";
            }

            // Pair by normalized transfer key — each transfer operation produces matched OUT and IN movements
            $outsByKey = [];
            foreach ($transferOuts as $out) {
                $opKey = (string) preg_replace('/:(out|in)(?=:\d+|$)/', ':pair', (string) $out->idempotency_key);
                $outsByKey[$opKey][] = $out;
            }
            $insByKey = [];
            foreach ($transferIns as $in) {
                $opKey = (string) preg_replace('/:(out|in)(?=:\d+|$)/', ':pair', (string) $in->idempotency_key);
                $insByKey[$opKey][] = $in;
            }

            $allTransferKeys = array_unique(array_merge(array_keys($outsByKey), array_keys($insByKey)));

            foreach ($allTransferKeys as $key) {
                $outs = $outsByKey[$key] ?? [];
                $ins = $insByKey[$key] ?? [];
                if (count($outs) !== count($ins)) {
                    $historyCorruptions[] = "Transfer operation [{$key}] has [".count($outs).'] out movements vs ['.count($ins).'] in movements.';

                    continue;
                }
                foreach ($outs as $idx => $out) {
                    $in = $ins[$idx] ?? null;
                    if ($in === null) {
                        continue;
                    }
                    if ($out->company_id !== $in->company_id) {
                        $historyCorruptions[] = "Transfer operation [{$key}] out/in company mismatch.";
                    }
                    if ($out->warehouse_id === $in->warehouse_id) {
                        $historyCorruptions[] = "Transfer operation [{$key}] has identical source and destination warehouse [{$out->warehouse_id}].";
                    }
                    $outDate = substr((string) $out->getRawOriginal('movement_date', (string) $out->movement_date), 0, 10);
                    $inDate = substr((string) $in->getRawOriginal('movement_date', (string) $in->movement_date), 0, 10);
                    if ($outDate !== $inDate) {
                        $historyCorruptions[] = "Transfer operation [{$key}] out/in date mismatch: [{$outDate}] vs [{$inDate}].";
                    }
                    if ($out->created_by !== $in->created_by) {
                        $historyCorruptions[] = "Transfer operation [{$key}] out/in creator mismatch: [{$out->created_by}] vs [{$in->created_by}].";
                    }
                    if ($out->product_id !== $in->product_id) {
                        $historyCorruptions[] = "Transfer operation [{$key}] out/in product mismatch: [{$out->product_id}] vs [{$in->product_id}].";
                    }
                    if ($out->lot_id !== $in->lot_id) {
                        $historyCorruptions[] = "Transfer operation [{$key}] out/in lot mismatch: [{$out->lot_id}] vs [{$in->lot_id}].";
                    }
                    $outQty = BigDecimal::of((string) $out->quantity_delta_base);
                    $inQty = BigDecimal::of((string) $in->quantity_delta_base);
                    if (! $outQty->negated()->isEqualTo($inQty)) {
                        $historyCorruptions[] = "Transfer operation [{$key}] out qty [{$outQty}] is not exact inverse of in qty [{$inQty}].";
                    }
                    $outVal = BigDecimal::of((string) $out->value_delta_base);
                    $inVal = BigDecimal::of((string) $in->value_delta_base);
                    if (! $outVal->negated()->isEqualTo($inVal)) {
                        $historyCorruptions[] = "Transfer operation [{$key}] out value [{$outVal}] is not exact inverse of in value [{$inVal}].";
                    }
                    $outCost = BigDecimal::of((string) $out->unit_cost_base)->toScale(6, RoundingMode::HALF_UP);
                    $inCost = BigDecimal::of((string) $in->unit_cost_base)->toScale(6, RoundingMode::HALF_UP);
                    if (! $outCost->isEqualTo($inCost)) {
                        $historyCorruptions[] = "Transfer operation [{$key}] out cost [{$outCost}] != in cost [{$inCost}].";
                    }
                }
            }

            // Check H: Inventory GL control account reconciliation
            $inventoryAccount = LedgerAccount::where('company_id', $companyId)
                ->where('system_key', 'inventory')
                ->first();

            $glRelevantMovements = $movements->filter(fn ($m) => in_array($m->source_type, ['opening_stock', 'stock_adjustment', 'stock_disposal'], true));

            if ($inventoryAccount !== null) {
                $hasPostings = PostingLine::where('company_id', $companyId)
                    ->where('ledger_account_id', $inventoryAccount->id)
                    ->exists();

                if ($hasPostings || $glRelevantMovements->isNotEmpty()) {
                    $glLines = PostingLine::where('company_id', $companyId)
                        ->where('ledger_account_id', $inventoryAccount->id)
                        ->get();

                    $glBalance = BigDecimal::zero();
                    foreach ($glLines as $line) {
                        $glBalance = $glBalance
                            ->plus(BigDecimal::of((string) $line->debit_base))
                            ->minus(BigDecimal::of((string) $line->credit_base));
                    }
                    $glBalanceScaled = $glBalance->toScale(6, RoundingMode::HALF_UP);
                    $expectedValuation = $totalValuation->toScale(6, RoundingMode::HALF_UP);

                    if (! $glBalanceScaled->isEqualTo($expectedValuation)) {
                        $cacheDiscrepancies[] = "Inventory GL control account [{$inventoryAccount->code}] balance [{$glBalanceScaled}] does not match inventory valuation [{$expectedValuation}].";
                    }
                }
            } elseif (! $totalValuation->isZero() && $glRelevantMovements->isNotEmpty()) {
                $cacheDiscrepancies[] = "Inventory has positive valuation [{$totalValuation}] but no inventory control account exists.";
            }

            // Check I: Master-data configuration and lifecycle consistency (Cache / Config Discrepancies)
            $units = Unit::where('company_id', $companyId)->get()->keyBy('id');
            $productUnits = ProductUnit::where('company_id', $companyId)->get();
            $productUnitsByProd = $productUnits->groupBy('product_id');

            // 1. Declared base unit existence and exact base-row invariant per product
            foreach ($products as $prod) {
                if ($prod->getAttribute('base_unit_id') === null) {
                    $cacheDiscrepancies[] = "Product [{$prod->id}] has no declared base unit.";
                } else {
                    $baseUnit = $units->get($prod->base_unit_id);
                    if ($baseUnit === null) {
                        $cacheDiscrepancies[] = "Product [{$prod->id}] base unit [{$prod->base_unit_id}] does not exist or does not belong to company [{$companyId}].";
                    } elseif (! $baseUnit->active) {
                        $cacheDiscrepancies[] = "Product [{$prod->id}] base unit [{$baseUnit->id}] is inactive.";
                    }
                }

                $prodUList = $productUnitsByProd->get($prod->id, collect());
                $baseRows = $prodUList->where('is_base', true);
                if ($baseRows->count() === 0) {
                    $cacheDiscrepancies[] = "Product [{$prod->id}] has no base ProductUnit configured.";
                } elseif ($baseRows->count() > 1) {
                    $cacheDiscrepancies[] = "Product [{$prod->id}] has multiple [{$baseRows->count()}] base ProductUnit rows.";
                } else {
                    /** @var ProductUnit $baseRow */
                    $baseRow = $baseRows->first();
                    if ($prod->getAttribute('base_unit_id') !== null && (int) $baseRow->unit_id !== (int) $prod->base_unit_id) {
                        $cacheDiscrepancies[] = "Product [{$prod->id}] base ProductUnit unit_id [{$baseRow->unit_id}] does not match products.base_unit_id [{$prod->base_unit_id}].";
                    }
                    $factor = BigDecimal::of((string) $baseRow->conversion_to_base);
                    if (! $factor->isEqualTo(BigDecimal::one())) {
                        $cacheDiscrepancies[] = "Product [{$prod->id}] base ProductUnit conversion factor is [{$factor}], expected 1.000000.";
                    }
                    if (! $baseRow->active) {
                        $cacheDiscrepancies[] = "Product [{$prod->id}] base ProductUnit is inactive.";
                    }
                }

                // Check default sale and default purchase singleton invariants
                $activeUnits = $prodUList->where('active', true);
                $activeSaleDefaults = $activeUnits->where('is_default_sale', true);
                $activePurchaseDefaults = $activeUnits->where('is_default_purchase', true);

                if ($activeSaleDefaults->count() === 0) {
                    $cacheDiscrepancies[] = "Product [{$prod->id}] has no active default sale unit configured.";
                } elseif ($activeSaleDefaults->count() > 1) {
                    $cacheDiscrepancies[] = "Product [{$prod->id}] has multiple [{$activeSaleDefaults->count()}] active default sale units configured.";
                }

                if ($activePurchaseDefaults->count() === 0) {
                    $cacheDiscrepancies[] = "Product [{$prod->id}] has no active default purchase unit configured.";
                } elseif ($activePurchaseDefaults->count() > 1) {
                    $cacheDiscrepancies[] = "Product [{$prod->id}] has multiple [{$activePurchaseDefaults->count()}] active default purchase units configured.";
                }

                // Positive-stock product must be active
                $costState = $costStates->get($prod->id);
                $prodCompanyQty = $costState !== null ? BigDecimal::of((string) $costState->quantity_base) : BigDecimal::zero();
                if ($prodCompanyQty->isPositive() && ! $prod->active) {
                    $cacheDiscrepancies[] = "Product [{$prod->id}] has positive stock [{$prodCompanyQty}] but is marked inactive.";
                }
            }

            // 2. ProductUnit positive factor and company coherence
            foreach ($productUnits as $pu) {
                if (! $products->has($pu->product_id)) {
                    $cacheDiscrepancies[] = "ProductUnit [{$pu->id}] references non-existent or foreign product [{$pu->product_id}].";
                }
                if (! $units->has($pu->unit_id)) {
                    $cacheDiscrepancies[] = "ProductUnit [{$pu->id}] references non-existent or foreign unit [{$pu->unit_id}].";
                } else {
                    /** @var Unit $referencedUnit */
                    $referencedUnit = $units->get($pu->unit_id);
                    if ($pu->active && ! $referencedUnit->active) {
                        $cacheDiscrepancies[] = "ProductUnit [{$pu->id}] for product [{$pu->product_id}] is active but references inactive unit [{$pu->unit_id}].";
                    }
                }
                $puFactor = BigDecimal::of((string) $pu->conversion_to_base);
                if ($puFactor->isLessThanOrEqualTo(0)) {
                    $cacheDiscrepancies[] = "ProductUnit [{$pu->id}] has non-positive conversion factor [{$puFactor}].";
                }
            }

            // 3. Inactive warehouse with positive stock
            foreach ($warehouses as $wh) {
                if (! $wh->active) {
                    $whBalances = $balances->where('warehouse_id', $wh->id);
                    foreach ($whBalances as $b) {
                        $bQty = BigDecimal::of((string) $b->quantity_base);
                        if ($bQty->isPositive()) {
                            $cacheDiscrepancies[] = "Inactive warehouse [{$wh->id}] has positive balance [{$bQty}] for product [{$b->product_id}].";
                        }
                    }
                }
            }

            $allDiscrepancies = array_values(array_unique(array_merge($historyCorruptions, $cacheDiscrepancies)));

            return new InventoryReconciliationReport(
                companyId: $companyId,
                isHealthy: empty($allDiscrepancies),
                discrepancies: $allDiscrepancies,
                checkedProducts: $products->count(),
                checkedWarehouses: $warehouses->count(),
                checkedMovements: $movements->count(),
                totalValuationBase: (string) $totalValuation->toScale(6, RoundingMode::HALF_UP),
                historyCorruptions: array_values(array_unique($historyCorruptions)),
                cacheDiscrepancies: array_values(array_unique($cacheDiscrepancies)),
            );
        };

        if ($fromCli) {
            return CompanyScope::executeWithoutScope($auditFn);
        }

        return $auditFn();
    }
}
