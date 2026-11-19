<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Purchasing\DTO\RecordGoodsReceiptCommand;
use App\Domain\Purchasing\Exceptions\OverReceiptLimitException;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteLine;
use App\Models\InventoryLot;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Purchasing\GoodsReceiptCapability;
use App\Services\Purchasing\GoodsReceiptScope;
use App\Services\Sales\DocumentSequenceService;
use App\Support\Mobile\NativeAdmissionGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class RecordPurchaseGoodsReceiptAction
{
    private function __clone() {}

    private ?GoodsReceiptScope $activeGoodsReceiptScope = null;

    public function ownsGoodsReceiptScope(GoodsReceiptScope $scope): bool
    {
        return $this->activeGoodsReceiptScope === $scope;
    }

    /**
     * Atomically validate and execute canonical Goods Receipt Note (GRN) with paired internal transfer.
     */
    public function execute(
        Company $company,
        Purchase $purchase,
        User $actor,
        RecordGoodsReceiptCommand $command,
        ?MobileAccessToken $mobileToken = null,
        ?MobileDevice $mobileDevice = null
    ): GoodsReceiptNote {
        if ($this->activeGoodsReceiptScope !== null) {
            throw new LogicException('Goods receipt action cannot be reentered.');
        }

        return app(TenantWriteAccess::class)->transaction((int) $company->id, function () use (
            $company,
            $purchase,
            $actor,
            $command,
            $mobileToken,
            $mobileDevice
        ): GoodsReceiptNote {
            // 1. Enforce strict lock order and native admission: Company -> Membership -> User -> Device -> Token
            $admission = app(NativeAdmissionGuard::class)->lockAndAuthorize(
                (int) $company->id,
                $actor,
                $mobileToken,
                $mobileDevice,
                ['inventory.stock.transfer', 'purchasing.purchase.view']
            );

            $lockedCompany = $admission['company'];
            $lockedActor = $admission['user'];
            $lockedDevice = $admission['device'];

            app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);

            // 2. Pessimistic lock Purchase FOR UPDATE
            /** @var Purchase $lockedPurchase */
            $lockedPurchase = Purchase::where('company_id', $lockedCompany->id)
                ->where('id', $purchase->id)
                ->with(['vendor'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedPurchase->isPosted()) {
                throw new InvalidArgumentException('Purchase bill must be posted before goods can be received.');
            }

            if ($lockedPurchase->status === Purchase::STATUS_VOID) {
                throw new InvalidArgumentException('Cannot receive goods against a voided purchase bill.');
            }

            if (! $lockedPurchase->is_staged) {
                throw new InvalidArgumentException('Cannot create a Goods Receipt Note for a direct-receipt purchase bill.');
            }

            // 3. Strict canonical date validation
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $command->receiptDate) !== 1) {
                throw new InvalidArgumentException("Receipt date [{$command->receiptDate}] must be in canonical YYYY-MM-DD format.");
            }
            try {
                Carbon::createFromFormat('!Y-m-d', $command->receiptDate);
            } catch (\Throwable) {
                throw new InvalidArgumentException("Receipt date [{$command->receiptDate}] is not a valid calendar date.");
            }

            // 4. Request Hash & Strict Scoped Replay Lookup
            $normalizedPayload = [
                'source_purchase_id' => (string) $lockedPurchase->public_id,
                'actor_id' => (int) $lockedActor->id,
                'device_id' => (string) $lockedDevice->device_id,
                'command' => $command->toPayloadArray(),
            ];
            $requestHash = hash('sha256', json_encode($normalizedPayload, JSON_THROW_ON_ERROR));

            /** @var GoodsReceiptNote|null $existingGrn */
            $existingGrn = GoodsReceiptNote::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $command->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingGrn !== null) {
                // Same key, different purchase => 409 Conflict
                if ((int) $existingGrn->purchase_id !== (int) $lockedPurchase->id) {
                    throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously committed for a different purchase bill.");
                }

                // Cross-actor replay => 403 Forbidden
                if ((int) $existingGrn->created_by !== (int) $lockedActor->id) {
                    throw new AuthorizationException('Idempotency key replay across different actors is forbidden.');
                }

                // Replay check
                if (hash_equals($existingGrn->request_hash, $requestHash)) {
                    return $existingGrn;
                }

                throw new IdempotencyConflictException("Idempotency key [{$command->idempotencyKey}] was previously committed with different receipt parameters.");
            }

            // 5. Lock Purchase Lines in ID order
            $purchaseLines = PurchaseLine::where('company_id', $lockedCompany->id)
                ->where('purchase_id', $lockedPurchase->id)
                ->with(['lots', 'product', 'productUnit'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // 6. Lock Warehouses in ID order
            $sourceWhId = (int) $lockedPurchase->warehouse_id;
            $destWhId = (int) $command->destinationWarehouseId;

            if ($sourceWhId === $destWhId) {
                throw new InvalidArgumentException('Source in-transit warehouse and destination physical warehouse must be different.');
            }

            $whIds = collect([$sourceWhId, $destWhId])->sort()->values()->all();
            $warehouses = Warehouse::where('company_id', $lockedCompany->id)
                ->whereIn('id', $whIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Warehouse|null $sourceWarehouse */
            $sourceWarehouse = $warehouses->get($sourceWhId);
            /** @var Warehouse|null $destWarehouse */
            $destWarehouse = $warehouses->get($destWhId);

            if ($sourceWarehouse === null || ! $sourceWarehouse->is_transit) {
                throw new InvalidArgumentException('Purchase receiving warehouse is not an active in-transit warehouse.');
            }

            if ($destWarehouse === null || $destWarehouse->is_transit || ! $destWarehouse->active) {
                throw new InvalidArgumentException('Destination warehouse must be an active non-transit warehouse.');
            }

            // 7. Aggregate requested base quantities per line and validate over-receipt
            $unitConversionService = app(UnitConversionService::class);
            $requestedBasePerLine = [];
            $parsedLines = [];

            foreach ($command->lines as $lineCmd) {
                /** @var PurchaseLine|null $purchaseLine */
                $purchaseLine = $purchaseLines->get($lineCmd->purchaseLineId);
                if ($purchaseLine === null) {
                    throw new InvalidArgumentException("Purchase line [{$lineCmd->purchaseLineId}] does not belong to purchase bill [{$lockedPurchase->id}].");
                }

                $quantityObj = Quantity::of((string) $lineCmd->quantity);
                if (! $quantityObj->isPositive()) {
                    throw new InvalidArgumentException("Receipt quantity for line [{$purchaseLine->public_id}] must be strictly positive.");
                }

                $quantityBaseObj = $unitConversionService->toBase($quantityObj, $purchaseLine->productUnit);
                $quantityBase = $quantityBaseObj->toBigDecimal();

                $lineId = (int) $purchaseLine->id;
                if (! isset($requestedBasePerLine[$lineId])) {
                    $requestedBasePerLine[$lineId] = BigDecimal::zero();
                }
                $requestedBasePerLine[$lineId] = $requestedBasePerLine[$lineId]->plus($quantityBase);

                // Lot and expiry resolution per entry
                $lotId = null;
                if ($purchaseLine->product->track_expiry) {
                    if ($lineCmd->expiryDate !== null && trim($lineCmd->expiryDate) !== '') {
                        $expCandidate = trim($lineCmd->expiryDate);
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expCandidate) !== 1) {
                            throw new InvalidArgumentException("Expiry date [{$expCandidate}] must be in canonical YYYY-MM-DD format.");
                        }
                    }

                    $matchedLotId = null;
                    if ($lineCmd->lotNumber !== null && trim($lineCmd->lotNumber) !== '') {
                        $needle = trim($lineCmd->lotNumber);
                        $matched = $purchaseLine->lots->first(fn ($l) => $l->lot_number === $needle);
                        $matchedLotId = $matched?->created_inventory_lot_id;
                    }

                    if ($matchedLotId === null) {
                        $matched = $purchaseLine->lots->first();
                        $matchedLotId = $matched?->created_inventory_lot_id;
                    }

                    if ($matchedLotId === null) {
                        /** @var InventoryLot|null $invLot */
                        $invLot = InventoryLot::where('company_id', $lockedCompany->id)
                            ->where('product_id', $purchaseLine->product_id)
                            ->where('source_type', 'purchase')
                            ->where('source_id', $lockedPurchase->id)
                            ->first();
                        $matchedLotId = $invLot?->id;
                    }

                    if ($matchedLotId === null) {
                        throw new InvalidArgumentException("Lot number/identity is required for expiry-tracked product [{$purchaseLine->product_id}].");
                    }
                    $lotId = (int) $matchedLotId;
                }

                $parsedLines[] = [
                    'lineCmd' => $lineCmd,
                    'purchaseLine' => $purchaseLine,
                    'quantityObj' => $quantityObj,
                    'quantityBase' => $quantityBase,
                    'lotId' => $lotId,
                ];
            }

            // Compute remaining receivable per line and validate aggregate requested quantity
            $lineRemainingMap = [];
            foreach ($purchaseLines as $pl) {
                $orderedBase = BigDecimal::of((string) $pl->quantity_base);
                $previouslyReceivedRaw = GoodsReceiptNoteLine::where('company_id', $lockedCompany->id)
                    ->where('purchase_line_id', $pl->id)
                    ->whereHas('goodsReceiptNote', fn ($q) => $q->where('status', GoodsReceiptNote::STATUS_POSTED))
                    ->sum('quantity_base');
                $prevReceivedBase = BigDecimal::of((string) $previouslyReceivedRaw);

                $preReceiptReturnedRaw = PurchaseReturnAllocation::where('company_id', $lockedCompany->id)
                    ->whereHas('purchaseReturnLine', fn ($q) => $q->where('purchase_line_id', $pl->id))
                    ->whereHas('purchaseReturn', fn ($q) => $q->where('warehouse_id', $sourceWarehouse->id)
                        ->where('status', PurchaseReturn::STATUS_POSTED)
                        ->where(fn ($wq) => $wq->whereNull('origin_warehouse_id')->orWhere('origin_warehouse_id', $sourceWarehouse->id))
                    )
                    ->sum('quantity_base');
                $preReceiptReturnedBase = BigDecimal::of((string) $preReceiptReturnedRaw);

                $remainingBase = $orderedBase->minus($prevReceivedBase)->minus($preReceiptReturnedBase);
                if ($remainingBase->isNegative()) {
                    $remainingBase = BigDecimal::zero();
                }

                $lineRemainingMap[$pl->id] = [
                    'orderedBase' => $orderedBase,
                    'prevReceivedBase' => $prevReceivedBase,
                    'preReceiptReturnedBase' => $preReceiptReturnedBase,
                    'remainingBase' => $remainingBase,
                ];

                if (isset($requestedBasePerLine[$pl->id])) {
                    $totalRequestedForLine = $requestedBasePerLine[$pl->id];
                    if ($totalRequestedForLine->isGreaterThan($remainingBase)) {
                        throw OverReceiptLimitException::forLine(
                            $pl->public_id,
                            (string) $totalRequestedForLine->toScale(6, RoundingMode::HALF_UP),
                            (string) $remainingBase->toScale(6, RoundingMode::HALF_UP)
                        );
                    }
                }
            }

            // 8. Persist Canonical Draft Header and Lines before Transfer
            $scope = app(GoodsReceiptScope::class);
            $this->activeGoodsReceiptScope = $scope;

            try {
                return $scope->withinGoodsReceipt(
                    $this,
                    $lockedPurchase,
                    $sourceWarehouse,
                    $destWarehouse,
                    $lockedActor,
                    function (GoodsReceiptCapability $capability) use (
                        $lockedCompany,
                        $lockedPurchase,
                        $sourceWarehouse,
                        $destWarehouse,
                        $lockedActor,
                        $command,
                        $requestHash,
                        $parsedLines,
                        $purchaseLines,
                        $lineRemainingMap,
                        $requestedBasePerLine,
                        $scope
                    ): GoodsReceiptNote {
                        $year = (int) substr($command->receiptDate, 0, 4);
                        $number = app(DocumentSequenceService::class)->generateNextNumber(
                            (int) $lockedCompany->id,
                            DocumentSequence::TYPE_GOODS_RECEIPT,
                            $year
                        );
                        $scope->recordAllocatedNumber($this, $number);

                        // A. Persist real canonical GRN header in draft status
                        $grn = GoodsReceiptNote::create([
                            'public_id' => (string) Str::ulid(),
                            'company_id' => $lockedCompany->id,
                            'purchase_id' => $lockedPurchase->id,
                            'grn_number' => $number,
                            'warehouse_id' => $destWarehouse->id,
                            'receipt_date' => $command->receiptDate,
                            'status' => GoodsReceiptNote::STATUS_DRAFT,
                            'inventory_operation_id' => null,
                            'notes' => $command->notes,
                            'idempotency_key' => $command->idempotencyKey,
                            'request_hash' => $requestHash,
                            'response_snapshot' => null,
                            'created_by' => $lockedActor->id,
                        ]);

                        // B. Persist real canonical GRN lines in draft status
                        $draftGrnLines = [];
                        $stockTransferLines = [];

                        foreach ($parsedLines as $item) {
                            $lineCmd = $item['lineCmd'];
                            /** @var PurchaseLine $purchaseLine */
                            $purchaseLine = $item['purchaseLine'];
                            $quantityObj = $item['quantityObj'];
                            $quantityBase = $item['quantityBase'];
                            $lotId = $item['lotId'];

                            $grnLine = GoodsReceiptNoteLine::create([
                                'public_id' => (string) Str::ulid(),
                                'company_id' => $lockedCompany->id,
                                'goods_receipt_note_id' => $grn->id,
                                'purchase_line_id' => $purchaseLine->id,
                                'product_id' => $purchaseLine->product_id,
                                'product_unit_id' => $purchaseLine->product_unit_id,
                                'lot_id' => $lotId,
                                'quantity' => (string) $lineCmd->quantity->toScale(6, RoundingMode::HALF_UP),
                                'quantity_base' => (string) $quantityBase->toScale(6, RoundingMode::HALF_UP),
                                'transfer_out_movement_id' => null,
                                'transfer_in_movement_id' => null,
                            ]);

                            $draftGrnLines[] = [
                                'grnLine' => $grnLine,
                                'purchaseLine' => $purchaseLine,
                                'quantityBase' => $quantityBase,
                            ];

                            $stockTransferLines[] = new StockTransferLineCommand(
                                productId: (int) $purchaseLine->product_id,
                                quantity: $quantityObj,
                                unitId: (int) $purchaseLine->productUnit->unit_id,
                                lotId: $lotId,
                                sourceLineId: (int) $grnLine->id
                            );
                        }

                        // C. Execute Paired Stock Transfer with REAL GRN & line provenance
                        $transferCmd = new StockTransferCommand(
                            companyId: (int) $lockedCompany->id,
                            sourceWarehouseId: (int) $sourceWarehouse->id,
                            destinationWarehouseId: (int) $destWarehouse->id,
                            movementDate: $command->receiptDate,
                            lines: $stockTransferLines,
                            idempotencyKey: 'grn_transfer_'.$command->idempotencyKey,
                            createdBy: (int) $lockedActor->id,
                            sourceType: 'goods_receipt',
                            sourceId: (int) $grn->id,
                            reason: "Goods Receipt Note {$number}",
                            capability: $capability
                        );

                        $movements = app(InventoryMovementService::class)->transfer($transferCmd);
                        $operationId = (int) $movements[0]->inventory_operation_id;

                        // D. Link movements to draft lines without raw SQL bypasses
                        $responseLines = [];
                        foreach ($draftGrnLines as $idx => $draftItem) {
                            /** @var GoodsReceiptNoteLine $grnLine */
                            $grnLine = $draftItem['grnLine'];
                            /** @var PurchaseLine $purchaseLine */
                            $purchaseLine = $draftItem['purchaseLine'];
                            $quantityBase = $draftItem['quantityBase'];

                            $outMov = $movements[$idx * 2];
                            $inMov = $movements[$idx * 2 + 1];

                            $grnLine->transfer_out_movement_id = $outMov->id;
                            $grnLine->transfer_in_movement_id = $inMov->id;
                            $grnLine->save();

                            $remData = $lineRemainingMap[$purchaseLine->id];
                            $newCumulativeReceived = $remData['prevReceivedBase']->plus($requestedBasePerLine[$purchaseLine->id]);
                            $newRemaining = $remData['orderedBase']->minus($newCumulativeReceived)->minus($remData['preReceiptReturnedBase']);
                            if ($newRemaining->isNegative()) {
                                $newRemaining = BigDecimal::zero();
                            }

                            $responseLines[] = [
                                'line_id' => (string) $purchaseLine->public_id,
                                'product_id' => (string) $purchaseLine->product->public_id,
                                'product_name' => (string) ($purchaseLine->product_name_ar ?? $purchaseLine->product->displayName()),
                                'ordered_quantity' => (string) $remData['orderedBase']->toScale(6, RoundingMode::HALF_UP),
                                'previously_received_quantity' => (string) $remData['prevReceivedBase']->toScale(6, RoundingMode::HALF_UP),
                                'received_now' => (string) $quantityBase->toScale(6, RoundingMode::HALF_UP),
                                'remaining_quantity' => (string) $newRemaining->toScale(6, RoundingMode::HALF_UP),
                                'is_line_complete' => $newRemaining->isZero(),
                                'unit_name' => (string) ($purchaseLine->productUnit?->unit?->displayName() ?? 'وحدة'),
                            ];
                        }

                        // E. Determine overall receiving status across all bill lines
                        $allComplete = true;
                        foreach ($purchaseLines as $pl) {
                            $remData = $lineRemainingMap[$pl->id];
                            $reqForLine = $requestedBasePerLine[$pl->id] ?? BigDecimal::zero();
                            $plRemaining = $remData['remainingBase']->minus($reqForLine);
                            if ($plRemaining->isPositive()) {
                                $allComplete = false;
                                break;
                            }
                        }

                        $overallStatus = $allComplete ? 'received' : 'partial';

                        $responseSnapshot = [
                            'id' => (string) $grn->public_id,
                            'grn_number' => (string) $number,
                            'purchase_id' => (string) $lockedPurchase->public_id,
                            'bill_number' => (string) ($lockedPurchase->vendor_invoice_number ?? $lockedPurchase->public_id),
                            'supplier_name' => (string) ($lockedPurchase->vendor?->displayName() ?? 'مورد عام'),
                            'receipt_date' => $command->receiptDate,
                            'destination_warehouse' => (string) $destWarehouse->displayName(),
                            'overall_receiving_status' => $overallStatus,
                            'lines' => $responseLines,
                            'posted_at' => now()->format('Y-m-d\TH:i:s\Z'),
                        ];

                        // F. Transition GRN to posted and freeze final metadata
                        $grn->inventory_operation_id = $operationId;
                        $grn->status = GoodsReceiptNote::STATUS_POSTED;
                        $grn->response_snapshot = $responseSnapshot;
                        $grn->save();

                        return $grn;
                    }
                );
            } finally {
                $this->activeGoodsReceiptScope = null;
            }
        });
    }
}
