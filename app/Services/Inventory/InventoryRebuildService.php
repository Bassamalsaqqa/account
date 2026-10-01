<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryLotBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryRebuildService
{
    /**
     * Rebuild all inventory caches (balances, cost states, lot balances) from immutable movements.
     *
     * CONTEXT MODES:
     * - Normal (fromCli = false): Must be called within an existing ambient matching company context.
     *   Used for admin UI actions. No scope bypass allowed.
     * - System/CLI (fromCli = true): Context-free bounded mode for CLI commands only.
     *   Rejects call if an ambient context exists for a different company.
     *   Never rewrites movement history.
     *
     * Phantom cache correction: any warehouse/lot balance rows not derivable from movements
     * are zeroed out (not deleted, to preserve audit trail), ensuring caches reflect reality.
     *
     * @return array{rebuilt_balances: int, rebuilt_cost_states: int, rebuilt_lot_balances: int, zeroed_phantom_balances: int, zeroed_phantom_lot_balances: int}
     */
    public function rebuildForCompany(int|Company $company, bool $fromCli = false): array
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        // Context mode enforcement
        if ($fromCli) {
            // System mode: reject if any ambient company context exists
            $context = app(CompanyContext::class);
            if ($context->hasCompany()) {
                throw new RuntimeException(
                    "System rebuild mode rejected: ambient company context [{$context->companyId()}] is present. Clear context before running system operations."
                );
            }
        } else {
            // Normal mode: require matching ambient context
            $context = app(CompanyContext::class);
            if (! $context->hasCompany()) {
                throw new NoActiveCompanyException('Rebuild requires an active company context in normal mode.');
            }
            if ($context->companyId() !== $companyId) {
                throw new RuntimeException(
                    "Cannot rebuild company [{$companyId}] when active context is company [{$context->companyId()}]."
                );
            }
        }

        $rebuildFn = function () use ($companyId, $fromCli): array {
            return DB::transaction(function () use ($companyId, $fromCli): array {
                /** @var Company $companyModel */
                $companyModel = Company::where('id', $companyId)->lockForUpdate()->firstOrFail();

                // Validate movement history integrity before rebuilding. Refuse corrupt history.
                $reconciler = app(InventoryReconciliationService::class);
                $report = $reconciler->auditCompany($companyModel, $fromCli);

                if ($report->hasHistoryCorruption()) {
                    throw new RuntimeException("Cannot rebuild inventory: history corruption detected:\n".implode("\n", $report->historyCorruptions));
                }

                // 1. Fetch all movements in exact sequence
                $movements = StockMovement::where('company_id', $companyModel->id)
                    ->orderBy('id')
                    ->get();

                // 2. Accumulate warehouse balances & lot balances from movements (source of truth)
                $derivedWarehouseBalances = [];
                $derivedLotBalances = [];
                $productMovements = [];

                foreach ($movements as $m) {
                    // Transfer in/out pairs do NOT change company cost state — only warehouse location changes
                    $whKey = "{$m->warehouse_id}:{$m->product_id}";
                    $currentWh = $derivedWarehouseBalances[$whKey] ?? BigDecimal::zero();
                    $derivedWarehouseBalances[$whKey] = $currentWh->plus(BigDecimal::of((string) $m->quantity_delta_base));

                    if ($m->lot_id !== null) {
                        $lotKey = "{$m->warehouse_id}:{$m->product_id}:{$m->lot_id}";
                        $currentLot = $derivedLotBalances[$lotKey] ?? BigDecimal::zero();
                        $derivedLotBalances[$lotKey] = $currentLot->plus(BigDecimal::of((string) $m->quantity_delta_base));
                    }

                    // Accumulate cost replay — skip transfer pairs (they don't change company cost state)
                    if (! in_array($m->movement_type, [StockMovement::TYPE_TRANSFER_IN, StockMovement::TYPE_TRANSFER_OUT], true)) {
                        $productMovements[$m->product_id][] = $m;
                    }
                }

                // 3. Sync InventoryBalance table — upsert derived keys, zero phantom rows
                $rebuiltBalances = 0;
                $zeroedPhantomBalances = 0;

                // Get all existing balance keys for this company
                $existingBalances = InventoryBalance::where('company_id', $companyModel->id)->get();
                $existingBalanceKeys = [];
                foreach ($existingBalances as $eb) {
                    $existingBalanceKeys["{$eb->warehouse_id}:{$eb->product_id}"] = $eb;
                }

                foreach ($derivedWarehouseBalances as $key => $qty) {
                    [$whId, $prodId] = explode(':', $key);
                    InventoryBalance::updateOrCreate(
                        [
                            'company_id' => $companyModel->id,
                            'product_id' => (int) $prodId,
                            'warehouse_id' => (int) $whId,
                        ],
                        [
                            'quantity_base' => (string) $qty->toScale(6, RoundingMode::HALF_UP),
                        ]
                    );
                    $rebuiltBalances++;
                    unset($existingBalanceKeys[$key]);
                }

                // Zero phantom cache rows (existing balances with no movement history)
                foreach ($existingBalanceKeys as $phantomBalance) {
                    if (! BigDecimal::of((string) $phantomBalance->quantity_base)->isZero()) {
                        $phantomBalance->update(['quantity_base' => '0.000000']);
                        $zeroedPhantomBalances++;
                    }
                }

                // 4. Sync InventoryLotBalance table — upsert derived, zero phantoms
                $rebuiltLotBalances = 0;
                $zeroedPhantomLotBalances = 0;

                $existingLotBalances = InventoryLotBalance::where('company_id', $companyModel->id)->get();
                $existingLotBalanceKeys = [];
                foreach ($existingLotBalances as $elb) {
                    $existingLotBalanceKeys["{$elb->warehouse_id}:{$elb->product_id}:{$elb->lot_id}"] = $elb;
                }

                foreach ($derivedLotBalances as $key => $qty) {
                    [$whId, $prodId, $lotId] = explode(':', $key);
                    InventoryLotBalance::updateOrCreate(
                        [
                            'company_id' => $companyModel->id,
                            'product_id' => (int) $prodId,
                            'warehouse_id' => (int) $whId,
                            'lot_id' => (int) $lotId,
                        ],
                        [
                            'quantity_base' => (string) $qty->toScale(6, RoundingMode::HALF_UP),
                        ]
                    );
                    $rebuiltLotBalances++;
                    unset($existingLotBalanceKeys[$key]);
                }

                // Zero phantom lot cache rows
                foreach ($existingLotBalanceKeys as $phantomLotBalance) {
                    if (! BigDecimal::of((string) $phantomLotBalance->quantity_base)->isZero()) {
                        $phantomLotBalance->update(['quantity_base' => '0.000000']);
                        $zeroedPhantomLotBalances++;
                    }
                }

                // 5. Replay and sync InventoryCostState using immutable movement snapshots
                // Transfer movements are excluded — they do not change company-wide cost/qty/value.
                // Full depletion policy: when qty reaches 0, value and avg reset to 0 (same as live engine).
                $rebuiltCostStates = 0;
                $allProducts = Product::where('company_id', $companyModel->id)->get();

                foreach ($allProducts as $product) {
                    $pMovements = $productMovements[$product->id] ?? [];
                    $runningQty = BigDecimal::zero();
                    $runningVal = BigDecimal::zero();
                    $runningAvg = BigDecimal::zero();

                    foreach ($pMovements as $m) {
                        $delta = BigDecimal::of((string) $m->quantity_delta_base);
                        if ($delta->isPositive()) {
                            // Inbound: use snapshotted unit_cost_base (not recomputed)
                            $inUnitCost = BigDecimal::of((string) $m->unit_cost_base);
                            $lineVal = $delta->multipliedBy($inUnitCost)->toScale(6, RoundingMode::HALF_UP);
                            $runningQty = $runningQty->plus($delta);
                            $runningVal = $runningVal->plus($lineVal);
                            $runningAvg = $runningQty->isZero() ? BigDecimal::zero() : $runningVal->dividedBy($runningQty, 6, RoundingMode::HALF_UP);
                        } elseif ($delta->isNegative()) {
                            $absDelta = $delta->abs();
                            $runningQty = $runningQty->minus($absDelta);
                            if ($runningQty->isZero()) {
                                // Full depletion: reset value and average to 0 (GL residual elimination policy)
                                $runningVal = BigDecimal::zero();
                                $runningAvg = BigDecimal::zero();
                            } else {
                                // Use snapshotted value_delta_base (not recomputed avg * qty)
                                // This ensures replay exactly matches the live engine including residual elimination
                                $lineVal = BigDecimal::of((string) $m->value_delta_base);
                                $runningVal = $runningVal->plus($lineVal);
                            }
                        }
                    }

                    InventoryCostState::updateOrCreate(
                        [
                            'company_id' => $companyModel->id,
                            'product_id' => $product->id,
                        ],
                        [
                            'quantity_base' => (string) $runningQty->toScale(6, RoundingMode::HALF_UP),
                            'average_cost_base' => (string) $runningAvg->toScale(6, RoundingMode::HALF_UP),
                            'inventory_value_base' => (string) $runningVal->toScale(6, RoundingMode::HALF_UP),
                        ]
                    );
                    $rebuiltCostStates++;
                }

                return [
                    'rebuilt_balances' => $rebuiltBalances,
                    'rebuilt_cost_states' => $rebuiltCostStates,
                    'rebuilt_lot_balances' => $rebuiltLotBalances,
                    'zeroed_phantom_balances' => $zeroedPhantomBalances,
                    'zeroed_phantom_lot_balances' => $zeroedPhantomLotBalances,
                ];
            });
        };

        if ($fromCli) {
            return CompanyScope::executeWithoutScope($rebuildFn);
        }

        return $rebuildFn();
    }
}
