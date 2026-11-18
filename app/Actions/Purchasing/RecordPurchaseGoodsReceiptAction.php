<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

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
use App\Services\Sales\SalesActorGuard;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
            throw new InvalidArgumentException('Goods receipt action cannot be reentered.');
        }

        return DB::transaction(function () use ($company, $purchase, $actor, $command, $mobileToken): GoodsReceiptNote {
            // 1. Fresh native token/device/password/membership/permission revalidation INSIDE the same transaction
            if ($mobileToken !== null) {
                /** @var MobileAccessToken|null $lockedToken */
                $lockedToken = MobileAccessToken::where('id', $mobileToken->id)->lockForUpdate()->first();
                if ($lockedToken === null || ! $lockedToken->isValid()) {
                    throw new AuthenticationException('The Bearer token has been revoked or expired.');
                }

                /** @var MobileDevice|null $lockedDevice */
                $lockedDevice = MobileDevice::where('device_id', $lockedToken->device_id)->lockForUpdate()->first();
                if ($lockedDevice === null || $lockedDevice->isRevoked() || (int) $lockedDevice->user_id !== (int) $lockedToken->user_id) {
                    throw new AuthenticationException('The mobile device has been revoked or unlinked.');
                }

                /** @var User|null $lockedUser */
                $lockedUser = User::where('id', $lockedToken->user_id)->lockForUpdate()->first();
                if ($lockedUser === null) {
                    throw new AuthenticationException('Authenticated user not found.');
                }

                $currentEpoch = 'epoch-'.substr(hash('sha256', (string) $lockedUser->password), 0, 16);
                if (! hash_equals($currentEpoch, (string) $lockedToken->password_epoch)) {
                    $lockedToken->update(['revoked_at' => now()]);
                    throw new AuthenticationException('Password was revised; token expired.');
                }

                if (! $lockedUser->activeCompanies()->where('companies.id', $company->id)->exists()) {
                    throw new AuthorizationException('User does not possess active authorized membership in the requested company.');
                }

                if ($lockedToken->company_id !== null && (int) $lockedToken->company_id !== (int) $company->id) {
                    throw new AuthorizationException('Token is bound to another company.');
                }

                if (! $lockedUser->hasPermissionTo('inventory.stock.transfer') || ! $lockedUser->hasPermissionTo('purchasing.purchase.view')) {
                    throw new AuthorizationException('Missing required permissions for goods receipt.');
                }
            }

            // 2. Pessimistic lock Company and authorize Actor
            $guard = app(SalesActorGuard::class);
            $guard->lockAndAuthorize((int) $company->id, $actor, 'inventory.stock.transfer');
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.purchase.view');

            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();

            // 3. Pessimistic lock Purchase
            /** @var Purchase $lockedPurchase */
            $lockedPurchase = Purchase::where('company_id', $lockedCompany->id)
                ->where('id', $purchase->id)
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

            // 4. Idempotency Check
            $requestHash = hash('sha256', json_encode($command->toPayloadArray(), JSON_THROW_ON_ERROR));
            /** @var GoodsReceiptNote|null $existingGrn */
            $existingGrn = GoodsReceiptNote::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $command->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingGrn !== null) {
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

            // 7. Validate Quantities and Over-Receipt Protection
            $unitConversionService = app(UnitConversionService::class);
            $stockTransferLines = [];
            $lineExecutionPlan = [];

            foreach ($command->lines as $lineCmd) {
                /** @var PurchaseLine|null $purchaseLine */
                $purchaseLine = $purchaseLines->get($lineCmd->purchaseLineId);
                if ($purchaseLine === null) {
                    throw new InvalidArgumentException("Purchase line [{$lineCmd->purchaseLineId}] does not belong to purchase bill [{$lockedPurchase->id}].");
                }

                // Ordered base quantity
                $orderedBase = BigDecimal::of((string) $purchaseLine->quantity_base);

                // Cumulative previously received base quantity from immutable GRN lines
                $previouslyReceivedRaw = GoodsReceiptNoteLine::where('company_id', $lockedCompany->id)
                    ->where('purchase_line_id', $purchaseLine->id)
                    ->sum('quantity_base');
                $prevReceivedBase = BigDecimal::of((string) $previouslyReceivedRaw);

                // Pre-receipt returns from transit (returns where goods were NOT transferred back from physical)
                $preReceiptReturnedRaw = PurchaseReturnAllocation::where('company_id', $lockedCompany->id)
                    ->whereHas('purchaseReturnLine', fn ($q) => $q->where('purchase_line_id', $purchaseLine->id))
                    ->whereHas('purchaseReturn', fn ($q) => $q->where('warehouse_id', $sourceWarehouse->id)
                        ->where('status', PurchaseReturn::STATUS_POSTED)
                        ->where(fn ($wq) => $wq->whereNull('origin_warehouse_id')->orWhere('origin_warehouse_id', $sourceWarehouse->id))
                    )
                    ->sum('quantity_base');
                $preReceiptReturnedBase = BigDecimal::of((string) $preReceiptReturnedRaw);

                // Remaining receivable quantity without double-subtracting physical returns
                $remainingBase = $orderedBase->minus($prevReceivedBase)->minus($preReceiptReturnedBase);
                if ($remainingBase->isNegative()) {
                    $remainingBase = BigDecimal::zero();
                }

                // Convert requested quantity to base units
                $productUnit = $purchaseLine->productUnit;
                $quantityObj = Quantity::of((string) $lineCmd->quantity);
                $quantityBaseObj = $unitConversionService->toBase($quantityObj, $productUnit);
                $quantityBase = $quantityBaseObj->toBigDecimal();

                if ($quantityBase->isGreaterThan($remainingBase)) {
                    throw OverReceiptLimitException::forLine(
                        $purchaseLine->public_id,
                        (string) $quantityBase->toScale(6, RoundingMode::HALF_UP),
                        (string) $remainingBase->toScale(6, RoundingMode::HALF_UP)
                    );
                }

                // Expiry and lot resolution
                $lotId = null;
                if ($purchaseLine->product->track_expiry) {
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

                $stockTransferLines[] = new StockTransferLineCommand(
                    productId: (int) $purchaseLine->product_id,
                    quantity: $quantityObj,
                    unitId: (int) $purchaseLine->productUnit->unit_id,
                    lotId: $lotId
                );

                $lineExecutionPlan[] = [
                    'lineCmd' => $lineCmd,
                    'purchaseLine' => $purchaseLine,
                    'quantityBase' => $quantityBase,
                    'prevReceivedBase' => $prevReceivedBase,
                    'preReceiptReturnedBase' => $preReceiptReturnedBase,
                    'orderedBase' => $orderedBase,
                    'lotId' => $lotId,
                ];
            }

            // 8. Execute Paired Stock Transfer within GoodsReceiptScope
            $scope = app(GoodsReceiptScope::class);
            $this->activeGoodsReceiptScope = $scope;

            try {
                return $scope->withinGoodsReceipt(
                    $this,
                    $lockedPurchase,
                    $sourceWarehouse,
                    $destWarehouse,
                    $actor,
                    function (GoodsReceiptCapability $capability) use (
                        $lockedCompany,
                        $lockedPurchase,
                        $sourceWarehouse,
                        $destWarehouse,
                        $actor,
                        $command,
                        $requestHash,
                        $stockTransferLines,
                        $lineExecutionPlan,
                        $purchaseLines,
                        $scope
                    ): GoodsReceiptNote {
                        $year = (int) substr($command->receiptDate, 0, 4);
                        $number = app(DocumentSequenceService::class)->generateNextNumber(
                            (int) $lockedCompany->id,
                            DocumentSequence::TYPE_GOODS_RECEIPT,
                            $year
                        );
                        $scope->recordAllocatedNumber($this, $number);

                        $transferCmd = new StockTransferCommand(
                            companyId: (int) $lockedCompany->id,
                            sourceWarehouseId: (int) $sourceWarehouse->id,
                            destinationWarehouseId: (int) $destWarehouse->id,
                            movementDate: $command->receiptDate,
                            lines: $stockTransferLines,
                            idempotencyKey: 'grn_transfer_'.$command->idempotencyKey,
                            createdBy: (int) $actor->id,
                            sourceType: 'goods_receipt',
                            sourceId: 1, // temporary until GRN created
                            reason: "Goods Receipt Note {$number}",
                            capability: $capability
                        );

                        $movements = app(InventoryMovementService::class)->transfer($transferCmd);
                        $operationId = (int) $movements[0]->inventory_operation_id;

                        // Create GRN document header
                        $grn = GoodsReceiptNote::create([
                            'public_id' => (string) Str::ulid(),
                            'company_id' => $lockedCompany->id,
                            'purchase_id' => $lockedPurchase->id,
                            'grn_number' => $number,
                            'warehouse_id' => $destWarehouse->id,
                            'receipt_date' => $command->receiptDate,
                            'status' => GoodsReceiptNote::STATUS_POSTED,
                            'inventory_operation_id' => $operationId,
                            'notes' => $command->notes,
                            'idempotency_key' => $command->idempotencyKey,
                            'request_hash' => $requestHash,
                            'response_snapshot' => [],
                            'created_by' => $actor->id,
                        ]);

                        // Update transfer movements source_id to GRN ID via DB builder (StockMovement model is immutable)
                        DB::table('stock_movements')
                            ->whereIn('id', collect($movements)->pluck('id'))
                            ->update(['source_id' => $grn->id]);

                        $responseLines = [];
                        foreach ($lineExecutionPlan as $idx => $plan) {
                            $lineCmd = $plan['lineCmd'];
                            /** @var PurchaseLine $purchaseLine */
                            $purchaseLine = $plan['purchaseLine'];
                            $quantityBase = $plan['quantityBase'];
                            $prevReceivedBase = $plan['prevReceivedBase'];
                            $preReceiptReturnedBase = $plan['preReceiptReturnedBase'];
                            $orderedBase = $plan['orderedBase'];

                            $outMov = $movements[$idx * 2];
                            $inMov = $movements[$idx * 2 + 1];

                            $grnLine = GoodsReceiptNoteLine::create([
                                'public_id' => (string) Str::ulid(),
                                'company_id' => $lockedCompany->id,
                                'goods_receipt_note_id' => $grn->id,
                                'purchase_line_id' => $purchaseLine->id,
                                'product_id' => $purchaseLine->product_id,
                                'product_unit_id' => $purchaseLine->product_unit_id,
                                'lot_id' => $plan['lotId'],
                                'quantity' => (string) $lineCmd->quantity->toScale(6, RoundingMode::HALF_UP),
                                'quantity_base' => (string) $quantityBase->toScale(6, RoundingMode::HALF_UP),
                                'transfer_out_movement_id' => $outMov->id,
                                'transfer_in_movement_id' => $inMov->id,
                            ]);

                            DB::table('stock_movements')
                                ->where('id', $outMov->id)
                                ->update(['source_line_id' => $grnLine->id]);
                            DB::table('stock_movements')
                                ->where('id', $inMov->id)
                                ->update(['source_line_id' => $grnLine->id]);

                            // New cumulative received after this GRN
                            $newCumulativeReceived = $prevReceivedBase->plus($quantityBase);
                            $newRemaining = $orderedBase->minus($newCumulativeReceived)->minus($preReceiptReturnedBase);
                            if ($newRemaining->isNegative()) {
                                $newRemaining = BigDecimal::zero();
                            }

                            $isLineComplete = $newRemaining->isZero();

                            $responseLines[] = [
                                'line_id' => (string) $purchaseLine->public_id,
                                'product_id' => (string) $purchaseLine->product->public_id,
                                'product_name' => (string) ($purchaseLine->product_name_ar ?? $purchaseLine->product->displayName()),
                                'ordered_quantity' => (string) $orderedBase->toScale(6, RoundingMode::HALF_UP),
                                'previously_received_quantity' => (string) $prevReceivedBase->toScale(6, RoundingMode::HALF_UP),
                                'received_now' => (string) $quantityBase->toScale(6, RoundingMode::HALF_UP),
                                'remaining_quantity' => (string) $newRemaining->toScale(6, RoundingMode::HALF_UP),
                                'is_line_complete' => $isLineComplete,
                                'unit_name' => (string) ($purchaseLine->productUnit?->unit?->displayName() ?? 'وحدة'),
                            ];
                        }

                        // Determine overall receiving status across all lines of the purchase bill
                        $allComplete = true;
                        foreach ($purchaseLines as $pl) {
                            $totReceived = GoodsReceiptNoteLine::where('company_id', $lockedCompany->id)
                                ->where('purchase_line_id', $pl->id)
                                ->sum('quantity_base');
                            $totPreReturn = PurchaseReturnAllocation::where('company_id', $lockedCompany->id)
                                ->whereHas('purchaseReturnLine', fn ($q) => $q->where('purchase_line_id', $pl->id))
                                ->whereHas('purchaseReturn', fn ($q) => $q->where('warehouse_id', $sourceWarehouse->id)
                                    ->where('status', PurchaseReturn::STATUS_POSTED)
                                    ->where(fn ($wq) => $wq->whereNull('origin_warehouse_id')->orWhere('origin_warehouse_id', $sourceWarehouse->id))
                                )
                                ->sum('quantity_base');

                            $plRemaining = BigDecimal::of((string) $pl->quantity_base)
                                ->minus(BigDecimal::of((string) $totReceived))
                                ->minus(BigDecimal::of((string) $totPreReturn));

                            if ($plRemaining->isPositive()) {
                                $allComplete = false;
                                break;
                            }
                        }

                        $overallReceivingStatus = $allComplete ? 'received' : 'partial';

                        $snapshot = [
                            'id' => (string) $grn->public_id,
                            'grn_number' => (string) $grn->grn_number,
                            'purchase_id' => (string) $lockedPurchase->public_id,
                            'bill_number' => (string) ($lockedPurchase->vendor_invoice_number ?? $lockedPurchase->purchase_number ?? $lockedPurchase->public_id),
                            'supplier_name' => (string) ($lockedPurchase->vendor ? $lockedPurchase->vendor->displayName() : 'مورد عام'),
                            'receipt_date' => (string) $grn->receipt_date->format('Y-m-d'),
                            'destination_warehouse' => (string) $destWarehouse->displayName(),
                            'overall_receiving_status' => $overallReceivingStatus,
                            'lines' => $responseLines,
                            'posted_at' => now()->format('Y-m-d\TH:i:s\Z'),
                        ];

                        $grn->response_snapshot = $snapshot;
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
