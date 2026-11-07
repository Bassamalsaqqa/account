<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Purchasing\Exceptions\PurchasingReversalBlockedException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryLotBalance;
use App\Models\LandedCostAllocation;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VendorPaymentAllocation;
use App\Services\Audit\AuditService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Posting\AccountingReversalService;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Purchasing\PurchaseVoidCapability;
use App\Services\Purchasing\PurchaseVoidScope;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class VoidPurchaseAction
{
    private ?PurchaseVoidScope $activeVoidScope = null;

    public function ownsVoidScope(PurchaseVoidScope $scope): bool
    {
        return $this->activeVoidScope === $scope;
    }

    /**
     * Inspect all blockers preventing the purchase from being voided.
     * Returns structured, localizable blocker details without throwing.
     *
     * @return list<array{code: string, message: string, translation_key: string, translation_params: array<string, mixed>, details: array<string, mixed>}>
     */
    public function inspectBlockers(Purchase $purchase): array
    {
        $context = app(CompanyContext::class);
        $actor = auth()->user();
        if (! $actor instanceof User || ! $context->hasCompany() || (int) $context->companyId() !== (int) $purchase->company_id) {
            throw new AuthorizationException('Authenticated active company actor required.');
        }

        return DB::transaction(function () use ($purchase, $actor, $context): array {
            $companyId = (int) $context->companyId();
            app(SalesActorGuard::class)->lockAndAuthorize($companyId, $actor, 'purchasing.purchase.void');
            app(SalesActorGuard::class)->lockAndAuthorize($companyId, $actor, 'purchasing.cost.view');
            $purchase = Purchase::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($purchase->id);

            return $this->inspectAuthorized($purchase);
        });
    }

    /** @return list<array{code: string, message: string, translation_key: string, translation_params: array<string, mixed>, details: array<string, mixed>}> */
    private function inspectAuthorized(Purchase $purchase): array
    {
        $companyId = (int) $purchase->company_id;
        $blockers = [];

        // 1. Active vendor payment allocations
        $activePayments = VendorPaymentAllocation::query()
            ->where('purchase_id', $purchase->id)
            ->where('company_id', $companyId)
            ->whereHas('vendorPayment', fn ($q) => $q->where('is_reversed', false))
            ->get();

        if ($activePayments->isNotEmpty()) {
            $blockers[] = [
                'code' => 'active_vendor_payments',
                'message' => 'Purchase has active vendor payment allocations. Reverse payments first.',
                'translation_key' => 'purchasing.void_blocked_active_payments',
                'translation_params' => ['count' => $activePayments->count()],
                'details' => ['allocation_ids' => $activePayments->pluck('id')->all()],
            ];
        }

        // 2. Linked non-void purchase returns
        $returns = PurchaseReturn::query()
            ->where('purchase_id', $purchase->id)
            ->where('company_id', $companyId)
            ->where('status', '!=', PurchaseReturn::STATUS_VOID)
            ->get();

        if ($returns->isNotEmpty()) {
            $blockers[] = [
                'code' => 'linked_purchase_returns',
                'message' => 'Purchase has linked purchase returns that are not voided. Void returns first.',
                'translation_key' => 'purchasing.void_blocked_linked_returns',
                'translation_params' => ['count' => $returns->count()],
                'details' => ['return_ids' => $returns->pluck('id')->all()],
            ];
        }

        // 3. Locked landed cost allocations
        $landedCosts = LandedCostAllocation::query()
            ->where('purchase_id', $purchase->id)
            ->where('company_id', $companyId)
            ->where('status', LandedCostAllocation::STATUS_LOCKED)
            ->get();

        if ($landedCosts->isNotEmpty()) {
            $blockers[] = [
                'code' => 'locked_landed_costs',
                'message' => 'Purchase has locked landed cost allocations.',
                'translation_key' => 'purchasing.void_blocked_locked_landed_costs',
                'translation_params' => ['count' => $landedCosts->count()],
                'details' => ['allocation_ids' => $landedCosts->pluck('id')->all()],
            ];
        }

        // 4. Downstream consumed stock and valuation checks
        $lines = $purchase->lines()->withoutGlobalScopes()->where('company_id', $companyId)->with('lots')->get();

        /** @var array<int, BigDecimal> $requiredQtyByProduct */
        $requiredQtyByProduct = [];
        /** @var array<int, BigDecimal> $requiredValByProduct */
        $requiredValByProduct = [];
        /** @var array<int, array{qty: BigDecimal, lot_number: string, product_id: int}> $requiredLotInfo */
        $requiredLotInfo = [];

        foreach ($lines as $line) {
            $productId = (int) $line->product_id;
            $lineQty = BigDecimal::of((string) $line->quantity_base);
            $requiredQtyByProduct[$productId] = ($requiredQtyByProduct[$productId] ?? BigDecimal::zero())->plus($lineQty);

            // Calculate receipt value for this line: sum of original movement value_delta_base
            $lineReceiptVal = BigDecimal::zero();
            if ($line->lots->isNotEmpty()) {
                foreach ($line->lots as $lot) {
                    $mov = StockMovement::withoutGlobalScopes()->where('company_id', $companyId)->find($lot->stock_movement_id);
                    if ($mov) {
                        $lineReceiptVal = $lineReceiptVal->plus($mov->value_delta_base);
                    }

                    if ($lot->created_inventory_lot_id !== null) {
                        $lotId = (int) $lot->created_inventory_lot_id;
                        $lotQty = BigDecimal::of((string) $lot->quantity_base);
                        if (! isset($requiredLotInfo[$lotId])) {
                            $requiredLotInfo[$lotId] = [
                                'qty' => $lotQty,
                                'lot_number' => (string) $lot->lot_number,
                                'product_id' => $productId,
                            ];
                        } else {
                            $requiredLotInfo[$lotId]['qty'] = $requiredLotInfo[$lotId]['qty']->plus($lotQty);
                        }
                    }
                }
            } else {
                $mov = StockMovement::withoutGlobalScopes()->where('company_id', $companyId)->find($line->stock_movement_id);
                if ($mov) {
                    $lineReceiptVal = $lineReceiptVal->plus($mov->value_delta_base);
                }
            }

            $requiredValByProduct[$productId] = ($requiredValByProduct[$productId] ?? BigDecimal::zero())->plus($lineReceiptVal);
        }

        foreach ($requiredQtyByProduct as $productId => $requiredProductQty) {
            // Warehouse stock check
            $whBalance = InventoryBalance::where('company_id', $companyId)
                ->where('warehouse_id', $purchase->warehouse_id)
                ->where('product_id', $productId)
                ->first();

            $whQty = $whBalance ? BigDecimal::of((string) $whBalance->quantity_base) : BigDecimal::zero();
            if ($whQty->isLessThan($requiredProductQty)) {
                $blockers[] = [
                    'code' => 'insufficient_warehouse_stock',
                    'message' => "Warehouse [{$purchase->warehouse_id}] has insufficient stock for product [{$productId}].",
                    'translation_key' => 'purchasing.void_blocked_insufficient_stock',
                    'translation_params' => [
                        'product_id' => $productId,
                        'available' => (string) $whQty,
                        'required' => (string) $requiredProductQty,
                    ],
                    'details' => [
                        'product_id' => $productId,
                        'warehouse_id' => $purchase->warehouse_id,
                        'available' => (string) $whQty,
                        'required' => (string) $requiredProductQty,
                    ],
                ];
            }

            // Company cost state stock & valuation check
            $costState = InventoryCostState::where('company_id', $companyId)
                ->where('product_id', $productId)
                ->first();

            $companyQty = $costState ? BigDecimal::of((string) $costState->quantity_base) : BigDecimal::zero();
            $companyVal = $costState ? BigDecimal::of((string) $costState->inventory_value_base) : BigDecimal::zero();

            if ($companyQty->isLessThan($requiredProductQty)) {
                $blockers[] = [
                    'code' => 'insufficient_company_stock',
                    'message' => "Company has insufficient stock for product [{$productId}].",
                    'translation_key' => 'purchasing.void_blocked_insufficient_company_stock',
                    'translation_params' => [
                        'product_id' => $productId,
                        'available' => (string) $companyQty,
                        'required' => (string) $requiredProductQty,
                    ],
                    'details' => [
                        'product_id' => $productId,
                        'available' => (string) $companyQty,
                        'required' => (string) $requiredProductQty,
                    ],
                ];
            }

            $productReceiptVal = $requiredValByProduct[$productId] ?? BigDecimal::zero();
            if ($companyVal->isLessThan($productReceiptVal)) {
                $blockers[] = [
                    'code' => 'carrying_value_mismatch',
                    'message' => "Company inventory carrying value [{$companyVal}] is less than original receipt value [{$productReceiptVal}] for product [{$productId}].",
                    'translation_key' => 'purchasing.void_blocked_carrying_value_mismatch',
                    'translation_params' => [
                        'product_id' => $productId,
                        'current_value' => (string) $companyVal,
                        'receipt_value' => (string) $productReceiptVal,
                    ],
                    'details' => [
                        'product_id' => $productId,
                        'current_value' => (string) $companyVal,
                        'receipt_value' => (string) $productReceiptVal,
                    ],
                ];
            }
        }

        // Expiry lot stock check
        foreach ($requiredLotInfo as $lotId => $lotData) {
            $lotBalance = InventoryLotBalance::where('company_id', $companyId)
                ->where('lot_id', $lotId)
                ->where('warehouse_id', $purchase->warehouse_id)
                ->first();

            $lotQty = $lotBalance ? BigDecimal::of((string) $lotBalance->quantity_base) : BigDecimal::zero();
            if ($lotQty->isLessThan($lotData['qty'])) {
                $blockers[] = [
                    'code' => 'insufficient_lot_stock',
                    'message' => "Lot [{$lotData['lot_number']}] has insufficient stock for product [{$lotData['product_id']}].",
                    'translation_key' => 'purchasing.void_blocked_insufficient_lot_stock',
                    'translation_params' => [
                        'lot_id' => $lotId,
                        'available' => (string) $lotQty,
                        'required' => (string) $lotData['qty'],
                    ],
                    'details' => [
                        'lot_id' => $lotId,
                        'lot_number' => $lotData['lot_number'],
                        'available' => (string) $lotQty,
                        'required' => (string) $lotData['qty'],
                    ],
                ];
            }
        }

        return $blockers;
    }

    /**
     * Canonically void a posted purchase.
     * Reverses inventory and accounting effects within atomic company-serialized boundary.
     */
    public function execute(Purchase $purchase, User $actor, string $reason, ?string $voidDate = null): Purchase
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $purchase->company_id) {
            throw new NoActiveCompanyException("Active company context does not match purchase company [{$purchase->company_id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor must be authenticated and match user.');
        }

        if (! $actor->belongsToCompany($purchase->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$purchase->company_id}].");
        }

        setPermissionsTeamId($purchase->company_id);

        if (! $actor->hasPermissionTo('purchasing.purchase.void')) {
            throw new AuthorizationException('User does not have permission to void purchases.');
        }

        if (! $actor->hasPermissionTo('purchasing.cost.view')) {
            throw new AuthorizationException('User does not have permission to view purchasing costs.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Void reason is required.');
        }

        return app(TenantWriteAccess::class)->transaction((int) $purchase->company_id, function () use ($purchase, $actor, $reason, $voidDate): Purchase {
            /** @var Company $company */
            $company = Company::where('id', $purchase->company_id)->lockForUpdate()->firstOrFail();

            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'purchasing.purchase.void');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');

            /** @var Purchase $locked */
            $locked = Purchase::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('id', $purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent retry check
            if ($locked->isVoid()) {
                if (trim((string) $locked->void_reason) !== trim($reason)) {
                    throw new IdempotencyConflictException('Cannot void purchase: reason mismatch on retry.');
                }
                if ($voidDate !== null) {
                    $effectiveDate = $locked->void_business_date?->format('Y-m-d');
                    if ($effectiveDate !== null && $effectiveDate !== $voidDate) {
                        throw new IdempotencyConflictException('Cannot void purchase: date mismatch on retry.');
                    }
                }

                return $locked->fresh(['lines.lots', 'postingBatch', 'voidPostingBatch']);
            }

            if (! $locked->isPosted()) {
                throw new InvalidArgumentException("Cannot void purchase in [{$locked->status}] status. Only posted purchases can be voided.");
            }

            $locked->setRelation(
                'lines',
                $locked->lines()->withoutGlobalScopes()->lockForUpdate()->get()
            );

            foreach ($locked->lines as $line) {
                $line->setRelation(
                    'lots',
                    $line->lots()->withoutGlobalScopes()->lockForUpdate()->get()
                );
            }

            // Inspect blockers
            $blockers = $this->inspectAuthorized($locked);
            if (! empty($blockers)) {
                throw new PurchasingReversalBlockedException($blockers);
            }

            // Date resolution
            if ($voidDate !== null) {
                app(PurchaseDocumentRules::class)->date($voidDate);
                if ($voidDate < $locked->purchase_date->format('Y-m-d')) {
                    throw new InvalidArgumentException('Void date cannot precede purchase date.');
                }
            } else {
                $voidDate = now($company->timezone)->toDateString();
                if ($voidDate < $locked->purchase_date->format('Y-m-d')) {
                    $voidDate = $locked->purchase_date->format('Y-m-d');
                }
            }

            $scope = app(PurchaseVoidScope::class);
            $this->activeVoidScope = $scope;

            try {
                return $scope->withinCanonicalVoid($this, $locked, $actor, function (PurchaseVoidCapability $capability) use ($company, $locked, $actor, $reason, $voidDate, $scope): Purchase {
                    // Compensating outbound stock movements
                    $movementService = app(InventoryMovementService::class);

                    foreach ($locked->lines as $line) {
                        $parts = [];
                        if ($line->lots->isNotEmpty()) {
                            foreach ($line->lots as $lot) {
                                /** @var StockMovement $origMovement */
                                $origMovement = StockMovement::withoutGlobalScopes()
                                    ->where('company_id', $company->id)
                                    ->findOrFail($lot->stock_movement_id);

                                $parts[] = new StockMovementLineCommand(
                                    productId: (int) $line->product_id,
                                    warehouseId: (int) $locked->warehouse_id,
                                    quantity: Quantity::of($origMovement->source_quantity),
                                    unitId: (int) $origMovement->unit_id,
                                    unitCostBase: (string) $origMovement->unit_cost_base,
                                    lotId: $lot->created_inventory_lot_id,
                                    valueDeltaBase: (string) $origMovement->value_delta_base,
                                    originalMovementId: (int) $origMovement->id
                                );
                            }
                        } else {
                            /** @var StockMovement $origMovement */
                            $origMovement = StockMovement::withoutGlobalScopes()
                                ->where('company_id', $company->id)
                                ->findOrFail($line->stock_movement_id);

                            $parts[] = new StockMovementLineCommand(
                                productId: (int) $line->product_id,
                                warehouseId: (int) $locked->warehouse_id,
                                quantity: Quantity::of($origMovement->source_quantity),
                                unitId: (int) $origMovement->unit_id,
                                unitCostBase: (string) $origMovement->unit_cost_base,
                                valueDeltaBase: (string) $origMovement->value_delta_base,
                                originalMovementId: (int) $origMovement->id
                            );
                        }

                        $command = new StockMovementCommand(
                            companyId: (int) $company->id,
                            movementType: StockMovement::TYPE_PURCHASE_RETURN,
                            movementDate: $voidDate,
                            lines: $parts,
                            sourceType: 'purchase_void',
                            sourceId: (int) $locked->id,
                            idempotencyKey: 'purchase_'.$locked->id.'_line_'.$line->id.'_void_stock',
                            createdBy: (int) $actor->id,
                            sourceLineId: (int) $line->id,
                            reason: "Void Purchase {$locked->purchase_number}"
                        );

                        $movementService->recordPurchaseVoid($command, $capability);
                    }

                    // Accounting reversal
                    $reversalBatch = null;
                    if ($locked->posting_batch_id !== null) {
                        /** @var PostingBatch $originalBatch */
                        $originalBatch = PostingBatch::withoutGlobalScopes()
                            ->where('company_id', $company->id)
                            ->findOrFail($locked->posting_batch_id);

                        $reversalBatch = app(AccountingReversalService::class)->reverse(
                            $originalBatch,
                            $actor,
                            $reason,
                            $voidDate
                        );
                    }

                    // Complete canonical void
                    $locked->completeCanonicalVoid($reversalBatch, $reason, $actor, $capability, $voidDate);

                    $scope->assertPersistedVoid($this, $locked);

                    app(AuditService::class)->log(
                        (int) $company->id,
                        'purchase.voided',
                        'Purchase voided',
                        $actor->id,
                        $locked,
                        ['status' => Purchase::STATUS_POSTED],
                        $locked->only(['status', 'voided_at', 'voided_by', 'void_reason', 'void_posting_batch_id'])
                    );

                    return $locked->fresh(['lines.lots', 'postingBatch', 'voidPostingBatch']);
                });
            } finally {
                $this->activeVoidScope = null;
            }
        }, attempts: 3);
    }
}
