<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteLine;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\NativeCommandReceipt;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Purchasing\GoodsReceiptCapability;
use App\Services\Purchasing\GoodsReceiptScope;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Mobile\Receipt\NativeWriteContextResolver;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Canonical action for executing physical purchase returns coordinating stock back to transit.
 */
final class ExecutePhysicalPurchaseReturnAction
{
    private function __clone() {}

    private ?GoodsReceiptScope $activeGoodsReceiptScope = null;

    /**
     * Runtime ownership proof; there is deliberately no public activation setter.
     */
    public function ownsGoodsReceiptScope(GoodsReceiptScope $scope): bool
    {
        return $this->activeGoodsReceiptScope === $scope;
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array{outcome: string, status: int, snapshot: array<string, mixed>, receipt: NativeCommandReceipt}
     */
    public function execute(
        Company $company,
        User $actor,
        Purchase $purchase,
        array $lines,
        string $idempotencyKey,
        ?Warehouse $reqWarehouse = null,
        ?string $specifiedGrnPublicId = null,
        ?MobileAccessToken $token = null,
        ?MobileDevice $device = null,
        ?string $reason = null
    ): array {
        if ($this->activeGoodsReceiptScope !== null) {
            throw new LogicException('Canonical purchase return orchestration cannot be reentered.');
        }

        if ($lines === [] || ! array_is_list($lines)) {
            throw new InvalidArgumentException('Purchase return requires at least one line item.');
        }

        // Canonical payload construction for deterministic request hashing
        $normalizedLines = [];
        foreach ($lines as $idx => $lineData) {
            if (! is_array($lineData)) {
                throw new InvalidArgumentException("Line [{$idx}] must be an array.");
            }
            $pLineId = $lineData['purchase_line_id'] ?? null;
            $prodId = $lineData['product_id'] ?? null;
            if ($pLineId === null && $prodId === null) {
                throw new InvalidArgumentException("Line [{$idx}] must specify purchase_line_id or product_id.");
            }
            foreach ([$pLineId, $prodId] as $publicId) {
                if ($publicId !== null && (! is_string($publicId) || ! Str::isUlid($publicId))) {
                    throw new InvalidArgumentException("Line [{$idx}] contains an invalid identifier.");
                }
            }
            $rawQty = $lineData['quantity'] ?? null;
            $qtyStr = NativeWriteContextResolver::validateDecimalString(
                $rawQty,
                "line [{$idx}] quantity",
                6,
                true
            );
            $normalizedLines[] = [
                'purchase_line_id' => is_string($pLineId) ? trim($pLineId) : null,
                'product_id' => is_string($prodId) ? trim($prodId) : null,
                'quantity' => is_string($rawQty) ? trim($rawQty) : $qtyStr,
            ];
        }

        // Sort lines by purchase_line_id / product_id for order invariance
        usort($normalizedLines, function (array $a, array $b): int {
            $cmp = strcmp((string) ($a['purchase_line_id'] ?? $a['product_id']), (string) ($b['purchase_line_id'] ?? $b['product_id']));
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['quantity'], (string) $b['quantity']);
        });

        $canonicalPayload = [
            'purchase_id' => (string) $purchase->public_id,
            'warehouse_id' => $reqWarehouse ? (string) $reqWarehouse->public_id : null,
            'goods_receipt_note_id' => $specifiedGrnPublicId !== null ? trim($specifiedGrnPublicId) : null,
            'reason' => $reason,
            'lines' => $normalizedLines,
        ];

        return app(NativeCommandReceiptManager::class)->execute(
            (int) $company->id, $actor, $token, $device, 'purchase_return', $idempotencyKey, $canonicalPayload,
            ['purchasing.return.post', 'purchasing.return.manage', 'purchasing.cost.view'],
            function (Company $lockedCompany, User $lockedActor) use ($purchase, $reqWarehouse, $specifiedGrnPublicId, $normalizedLines, $reason, $idempotencyKey): array {
                app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);
                /** @var Purchase $lockedPurchase */
                $lockedPurchase = Purchase::where('company_id', $lockedCompany->id)
                    ->where('id', $purchase->id)
                    ->with(['lines.product', 'lines.lots', 'warehouse'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedPurchase->isPosted()) {
                    throw new InvalidArgumentException('Purchase bill must be posted before returns can be created.');
                }

                if ($lockedPurchase->status === Purchase::STATUS_VOID) {
                    throw new InvalidArgumentException('Cannot return against a voided purchase bill.');
                }

                if ($reqWarehouse !== null) {
                    $reqWarehouse = Warehouse::where('company_id', $lockedCompany->id)->whereKey($reqWarehouse->id)->lockForUpdate()->firstOrFail();
                    if ((! $lockedPurchase->is_staged || $reqWarehouse->is_transit) && (int) $reqWarehouse->id !== (int) $lockedPurchase->warehouse_id) {
                        throw new InvalidArgumentException('Requested warehouse does not match purchase receiving warehouse.');
                    }
                }
                $isPhysicalReturn = $lockedPurchase->is_staged && ($specifiedGrnPublicId !== null || ($reqWarehouse !== null
                    ? ! $reqWarehouse->is_transit
                    : GoodsReceiptNote::where('company_id', $lockedCompany->id)->where('purchase_id', $lockedPurchase->id)
                        ->where('status', GoodsReceiptNote::STATUS_POSTED)->exists()));

                // Validate specified GRN if provided
                /** @var GoodsReceiptNote|null $specifiedGrn */
                $specifiedGrn = null;
                if ($specifiedGrnPublicId !== null) {
                    $specifiedGrn = GoodsReceiptNote::where('company_id', $lockedCompany->id)
                        ->where('purchase_id', $lockedPurchase->id)
                        ->where('status', GoodsReceiptNote::STATUS_POSTED)
                        ->where('public_id', $specifiedGrnPublicId)
                        ->with('warehouse')
                        ->first();

                    if ($specifiedGrn === null) {
                        throw new InvalidArgumentException("Goods Receipt Note [{$specifiedGrnPublicId}] not found for this purchase bill.");
                    }

                    if ($reqWarehouse !== null && (int) $reqWarehouse->id !== (int) $specifiedGrn->warehouse_id) {
                        throw new InvalidArgumentException("Requested warehouse does not match Goods Receipt Note warehouse [{$specifiedGrn->warehouse->displayName()}].");
                    }
                }

                $draftLines = [];
                $transferBackPlan = [];
                /** @var Warehouse|null $resolvedOriginWarehouse */
                $resolvedOriginWarehouse = null;
                /** @var GoodsReceiptNote|null $resolvedOriginGrn */
                $resolvedOriginGrn = null;

                foreach ($normalizedLines as $lineData) {
                    $pLineId = $lineData['purchase_line_id'];
                    $prodId = $lineData['product_id'];

                    if ($pLineId !== null) {
                        /** @var PurchaseLine|null $origLine */
                        $origLine = $lockedPurchase->lines->firstWhere('public_id', $pLineId);
                        if ($origLine === null) {
                            throw new InvalidArgumentException("Purchase line [{$pLineId}] does not belong to this purchase bill.");
                        }
                        if ($prodId !== null && ($origLine->product === null || (string) $origLine->product->public_id !== $prodId)) {
                            throw new InvalidArgumentException("Supplied product [{$prodId}] does not match purchase line [{$pLineId}].");
                        }
                    } else {
                        $matches = $lockedPurchase->lines->filter(fn (PurchaseLine $line): bool => $line->product !== null && (string) $line->product->public_id === $prodId);
                        if ($matches->isEmpty()) {
                            throw new InvalidArgumentException("Product [{$prodId}] does not belong to this purchase bill.");
                        }
                        if ($matches->count() > 1) {
                            throw new InvalidArgumentException("Ambiguous return line for product [{$prodId}]. Multiple purchase lines exist; specify purchase_line_id.");
                        }
                        $origLine = $matches->first();
                    }

                    if (isset($draftLines[$origLine->id])) {
                        throw new InvalidArgumentException('Repeated original purchase line.');
                    }
                    $qty = BigDecimal::of($lineData['quantity']);
                    $ratio = BigDecimal::of((string) $origLine->unit_conversion_ratio);
                    $qtyBase = $qty->multipliedBy($ratio)->toScale(6);

                    // Verify returnable quantity on original purchase line across posted returns
                    $previouslyReturnedBase = PurchaseReturnLine::where('purchase_line_id', $origLine->id)
                        ->whereHas('purchaseReturn', fn ($q) => $q->where('company_id', $lockedCompany->id)->where('status', 'posted'))
                        ->sum('quantity_base');

                    $remReturnableBase = BigDecimal::of((string) $origLine->quantity_base)->minus(BigDecimal::of((string) $previouslyReturnedBase));
                    if ($qtyBase->isGreaterThan($remReturnableBase)) {
                        throw new InvalidArgumentException("Return quantity [{$qty}] exceeds remaining returnable quantity [{$remReturnableBase}] for purchase line [{$origLine->public_id}].");
                    }

                    if ($lockedPurchase->is_staged && ! $isPhysicalReturn) {
                        $received = GoodsReceiptNoteLine::where('company_id', $lockedCompany->id)
                            ->where('purchase_line_id', $origLine->id)
                            ->whereHas('goodsReceiptNote', fn ($q) => $q->where('status', GoodsReceiptNote::STATUS_POSTED))->sum('quantity_base');
                        $directReturns = PurchaseReturnLine::where('purchase_line_id', $origLine->id)
                            ->whereHas('purchaseReturn', fn ($q) => $q->where('company_id', $lockedCompany->id)
                                ->where('status', PurchaseReturn::STATUS_POSTED)->whereNull('origin_goods_receipt_note_id'))->sum('quantity_base');
                        if ($qtyBase->isGreaterThan(BigDecimal::of($origLine->quantity_base)->minus((string) $received)->minus((string) $directReturns))) {
                            throw new InvalidArgumentException('Return exceeds the remaining purchase quantity in transit.');
                        }
                    }

                    $draftLines[$origLine->id] = [
                        'purchase_line_id' => (int) $origLine->id,
                        'quantity' => (string) $qty,
                    ];

                    if ($isPhysicalReturn) {
                        // Find GRN line for this purchase line
                        $grnLineQuery = GoodsReceiptNoteLine::where('company_id', $lockedCompany->id)
                            ->where('purchase_line_id', $origLine->id)
                            ->whereHas('goodsReceiptNote', fn ($q) => $q->where('purchase_id', $lockedPurchase->id)->where('status', GoodsReceiptNote::STATUS_POSTED))
                            ->with(['goodsReceiptNote.warehouse']);

                        if ($specifiedGrn !== null) {
                            $grnLineQuery->where('goods_receipt_note_id', $specifiedGrn->id);
                        } elseif ($reqWarehouse !== null) {
                            $grnLineQuery->whereHas('goodsReceiptNote', fn ($q) => $q->where('warehouse_id', $reqWarehouse->id));
                        }

                        $candidates = $grnLineQuery->limit(2)->get();
                        if ($candidates->count() > 1) {
                            throw new InvalidArgumentException('Ambiguous receipt or lot for purchase line. Select a single receipt with an identifiable lot.');
                        }
                        $grnLine = $candidates->first();

                        if ($grnLine === null) {
                            throw new InvalidArgumentException("No valid physical goods receipt note found for purchase line [{$origLine->public_id}].");
                        }

                        /** @var GoodsReceiptNote $currentGrn */
                        $currentGrn = $grnLine->goodsReceiptNote;
                        /** @var Warehouse $actualWh */
                        $actualWh = $currentGrn->warehouse;

                        if ($actualWh->is_transit) {
                            throw new InvalidArgumentException("Goods Receipt Note warehouse [{$actualWh->code}] is an in-transit warehouse; physical return requires store warehouse.");
                        }

                        // Enforce that reqWarehouse if provided matches the actual GRN warehouse
                        if ($reqWarehouse !== null && (int) $reqWarehouse->id !== (int) $actualWh->id) {
                            throw new InvalidArgumentException("Requested warehouse does not match actual Goods Receipt Note warehouse [{$actualWh->displayName()}].");
                        }

                        // Validate remaining received quantity in this GRN line
                        $grnReceivedBase = BigDecimal::of((string) $grnLine->quantity_base);
                        $prevReturnedFromGrnBase = PurchaseReturnLine::where('purchase_line_id', $origLine->id)
                            ->whereHas('purchaseReturn', fn ($q) => $q->where('company_id', $lockedCompany->id)
                                ->where('status', 'posted')
                                ->where('origin_goods_receipt_note_id', $currentGrn->id))
                            ->sum('quantity_base');

                        $remGrnBase = $grnReceivedBase->minus(BigDecimal::of((string) $prevReturnedFromGrnBase));
                        if ($qtyBase->isGreaterThan($remGrnBase)) {
                            throw new InvalidArgumentException("Return quantity [{$qty}] exceeds remaining physical receipt quantity [{$remGrnBase}] in GRN [{$currentGrn->grn_number}].");
                        }

                        // Enforce single GRN and single warehouse origin for all lines in return event
                        if ($resolvedOriginWarehouse === null) {
                            $resolvedOriginWarehouse = $actualWh;
                            $resolvedOriginGrn = $currentGrn;
                        } else {
                            if ((int) $resolvedOriginWarehouse->id !== (int) $actualWh->id) {
                                throw new InvalidArgumentException('Purchase return across mixed physical warehouses is not supported in a single return event.');
                            }
                            if ((int) $resolvedOriginGrn->id !== (int) $currentGrn->id) {
                                throw new InvalidArgumentException('Purchase return across mixed Goods Receipt Notes is not supported in a single return event.');
                            }
                        }

                        if ($origLine->product->track_expiry) {
                            $lot = $origLine->lots->firstWhere('created_inventory_lot_id', $grnLine->lot_id);
                            if ($grnLine->lot_id === null || $lot === null || $lot->stock_movement_id === null) {
                                throw new InvalidArgumentException('Received lot has no matching original purchase receipt movement.');
                            }
                            $draftLines[$origLine->id]['allocations'] = [[
                                'purchase_line_lot_id' => $lot->id,
                                'original_stock_movement_id' => $lot->stock_movement_id,
                                'quantity' => (string) $qty,
                            ]];
                        }
                        $transferBackPlan[] = new StockTransferLineCommand(
                            productId: (int) $origLine->product_id,
                            quantity: Quantity::of($qtyBase),
                            unitId: (int) $origLine->product->base_unit_id,
                            lotId: $grnLine->lot_id
                        );
                    }
                }

                $originWarehouseId = $resolvedOriginWarehouse?->id;
                $originGrnId = $resolvedOriginGrn?->id;

                if (! empty($transferBackPlan) && $resolvedOriginWarehouse !== null) {
                    $transitWh = $lockedPurchase->warehouse;
                    if ($transitWh === null || ! $transitWh->is_transit) {
                        throw new InvalidArgumentException('Staged purchase receiving warehouse is not an active in-transit warehouse.');
                    }

                    $sourcePhysicalWh = $resolvedOriginWarehouse;

                    $returnScope = app(GoodsReceiptScope::class);
                    $this->activeGoodsReceiptScope = $returnScope;

                    try {
                        $returnScope->withinReturnTransfer(
                            $this,
                            $lockedPurchase,
                            $sourcePhysicalWh,
                            $transitWh,
                            $lockedActor,
                            function (GoodsReceiptCapability $cap) use ($lockedCompany, $sourcePhysicalWh, $transitWh, $transferBackPlan, $idempotencyKey, $lockedActor, $originGrnId, $lockedPurchase): void {
                                $realSourceId = $originGrnId ?? (int) $lockedPurchase->id;
                                $cmd = new StockTransferCommand(
                                    companyId: (int) $lockedCompany->id,
                                    sourceWarehouseId: (int) $sourcePhysicalWh->id,
                                    destinationWarehouseId: (int) $transitWh->id,
                                    movementDate: now()->format('Y-m-d'),
                                    lines: $transferBackPlan,
                                    idempotencyKey: 'return_transfer_'.hash('sha256', trim($idempotencyKey)),
                                    createdBy: (int) $lockedActor->id,
                                    sourceType: 'goods_receipt',
                                    sourceId: $realSourceId,
                                    reason: 'Physical stock return transfer to transit',
                                    capability: $cap
                                );
                                app(InventoryMovementService::class)->transfer($cmd);
                            }
                        );
                    } finally {
                        $this->activeGoodsReceiptScope = null;
                    }
                }

                $draftData = [
                    'purchase_id' => (int) $lockedPurchase->id,
                    'return_date' => now()->format('Y-m-d'),
                    'reason' => $reason ?? 'مرتجع مشتريات عبر تطبيق الجوال',
                    'lines' => array_values($draftLines),
                ];

                $createReturnDraftAction = app(CreatePurchaseReturnDraftAction::class);
                $draft = $createReturnDraftAction->execute($lockedCompany, $lockedActor, $draftData);

                if ($originWarehouseId !== null) {
                    $draft->origin_warehouse_id = $originWarehouseId;
                    $draft->origin_goods_receipt_note_id = $originGrnId;
                    $draft->save();
                }

                $postReturnAction = app(PostPurchaseReturnAction::class);
                $posted = $postReturnAction->execute($draft, $lockedActor);

                $snapshot = [
                    'id' => (string) $posted->public_id,
                    'status' => (string) $posted->status,
                    'total_amount' => (string) BigDecimal::of((string) $posted->grand_total_currency)->toScale(2, RoundingMode::HALF_UP),
                    'currency' => (string) $posted->currency_code,
                    'posted_at' => $posted->posted_at ? $posted->posted_at->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
                ];

                return [
                    'snapshot' => $snapshot,
                    'entity_type' => 'purchase_return',
                    'entity_id' => $posted->id,
                    'entity_public_id' => $posted->public_id,
                ];
            });
    }
}
