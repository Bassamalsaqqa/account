<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\GoodsReceiptNoteLine;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Purchasing\GoodsReceiptCapability;
use App\Services\Purchasing\GoodsReceiptScope;
use App\Support\Mobile\NativeAdmissionGuard;
use App\Support\Tenancy\CompanyContext;
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
     * @param  list<array{product_id?: string|null, purchase_line_id?: string|null, quantity?: string|int|float}>  $lines
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
    ): PurchaseReturn {
        if ($this->activeGoodsReceiptScope !== null) {
            throw new LogicException('Canonical purchase return orchestration cannot be reentered.');
        }

        return app(TenantWriteAccess::class)->transaction((int) $company->id, function () use (
            $company,
            $actor,
            $purchase,
            $lines,
            $idempotencyKey,
            $reqWarehouse,
            $specifiedGrnPublicId,
            $token,
            $device,
            $reason
        ): PurchaseReturn {
            // 1. Enforce strict lock order and native admission: Company -> Membership -> User -> Device -> Token
            $admission = app(NativeAdmissionGuard::class)->lockAndAuthorize(
                (int) $company->id,
                $actor,
                $token,
                $device,
                ['purchasing.return.manage']
            );

            $lockedCompany = $admission['company'];
            $lockedActor = $admission['user'];

            app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);

            // 2. Lock Purchase row FOR UPDATE
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

            $isPhysicalReturn = false;
            if ($lockedPurchase->is_staged) {
                if ($specifiedGrnPublicId !== null || ($reqWarehouse !== null && ! $reqWarehouse->is_transit)) {
                    $isPhysicalReturn = true;
                }
            }

            // 3. Match lines
            $draftLines = [];
            $transferBackPlan = [];

            foreach ($lines as $lineData) {
                $productId = $lineData['product_id'] ?? null;
                $purchaseLinePublicId = $lineData['purchase_line_id'] ?? null;
                /** @var PurchaseLine|null $origLine */
                $origLine = $lockedPurchase->lines->first(function (PurchaseLine $l) use ($productId, $purchaseLinePublicId): bool {
                    if ($purchaseLinePublicId !== null && (string) $l->public_id === (string) $purchaseLinePublicId) {
                        return true;
                    }

                    return $productId !== null && $l->product !== null && (string) $l->product->public_id === (string) $productId;
                });

                if ($origLine !== null) {
                    $qty = (string) ($lineData['quantity'] ?? '1');
                    $draftLines[] = [
                        'purchase_line_id' => (int) $origLine->id,
                        'quantity' => $qty,
                    ];

                    if ($isPhysicalReturn) {
                        $grnLineQuery = GoodsReceiptNoteLine::where('company_id', $lockedCompany->id)
                            ->where('purchase_line_id', $origLine->id)
                            ->with(['goodsReceiptNote.warehouse']);
                        if ($specifiedGrnPublicId !== null) {
                            $grnLineQuery->whereHas('goodsReceiptNote', fn ($q) => $q->where('public_id', $specifiedGrnPublicId));
                        }
                        /** @var GoodsReceiptNoteLine|null $grnLine */
                        $grnLine = $grnLineQuery->orderByDesc('id')->first();

                        $physicalWh = $reqWarehouse ?? $grnLine?->goodsReceiptNote?->warehouse;
                        if ($physicalWh !== null && ! $physicalWh->is_transit) {
                            $transferBackPlan[] = [
                                'origLine' => $origLine,
                                'quantity' => $qty,
                                'physicalWarehouse' => $physicalWh,
                                'lotId' => $grnLine?->lot_id,
                                'grnId' => $grnLine?->goods_receipt_note_id,
                            ];
                        }
                    }
                }
            }

            if (empty($draftLines)) {
                throw new InvalidArgumentException('No valid purchase lines matched for return.');
            }

            $originWarehouseId = null;
            $originGrnId = null;

            // 4. If staged bill and physical receipt occurred, execute coordinated transfer-back to transit
            if (! empty($transferBackPlan)) {
                $transitWh = $lockedPurchase->warehouse;
                if ($transitWh === null || ! $transitWh->is_transit) {
                    throw new InvalidArgumentException('Staged purchase receiving warehouse is not an active in-transit warehouse.');
                }

                $firstPlan = $transferBackPlan[0];
                /** @var Warehouse $sourcePhysicalWh */
                $sourcePhysicalWh = $firstPlan['physicalWarehouse'];
                $originWarehouseId = $sourcePhysicalWh->id;
                $originGrnId = $firstPlan['grnId'];

                $transferLines = [];
                foreach ($transferBackPlan as $plan) {
                    $transferLines[] = new StockTransferLineCommand(
                        productId: (int) $plan['origLine']->product_id,
                        quantity: Quantity::of($plan['quantity']),
                        unitId: (int) $plan['origLine']->productUnit->unit_id,
                        lotId: $plan['lotId']
                    );
                }

                $returnScope = app(GoodsReceiptScope::class);
                $this->activeGoodsReceiptScope = $returnScope;

                try {
                    $returnScope->withinReturnTransfer(
                        $this,
                        $lockedPurchase,
                        $sourcePhysicalWh,
                        $transitWh,
                        $lockedActor,
                        function (GoodsReceiptCapability $cap) use ($lockedCompany, $sourcePhysicalWh, $transitWh, $transferLines, $idempotencyKey, $lockedActor, $originGrnId, $lockedPurchase): void {
                            $realSourceId = $originGrnId ?? (int) $lockedPurchase->id;
                            $cmd = new StockTransferCommand(
                                companyId: (int) $lockedCompany->id,
                                sourceWarehouseId: (int) $sourcePhysicalWh->id,
                                destinationWarehouseId: (int) $transitWh->id,
                                movementDate: now()->format('Y-m-d'),
                                lines: $transferLines,
                                idempotencyKey: 'return_transfer_'.$idempotencyKey,
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

            // 5. Create Draft Return and Post it
            $draftData = [
                'purchase_id' => (int) $lockedPurchase->id,
                'return_date' => now()->format('Y-m-d'),
                'reason' => $reason ?? 'مرتجع مشتريات عبر تطبيق الجوال',
                'lines' => $draftLines,
            ];

            $createReturnDraftAction = app(CreatePurchaseReturnDraftAction::class);
            $draft = $createReturnDraftAction->execute($lockedCompany, $lockedActor, $draftData);

            if ($originWarehouseId !== null) {
                $draft->origin_warehouse_id = $originWarehouseId;
                $draft->origin_goods_receipt_note_id = $originGrnId;
                $draft->save();
            }

            $postReturnAction = app(PostPurchaseReturnAction::class);

            return $postReturnAction->execute($draft, $lockedActor);
        });
    }
}
