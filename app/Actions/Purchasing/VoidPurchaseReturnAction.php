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
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Posting\AccountingReversalService;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Purchasing\PurchaseReturnVoidCapability;
use App\Services\Purchasing\PurchaseReturnVoidScope;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class VoidPurchaseReturnAction
{
    private ?PurchaseReturnVoidScope $activeReturnVoidScope = null;

    public function ownsReturnVoidScope(PurchaseReturnVoidScope $scope): bool
    {
        return $this->activeReturnVoidScope === $scope;
    }

    /**
     * Inspect all blockers preventing the purchase return from being voided.
     * Returns structured, localizable blocker details without throwing.
     *
     * @return list<array{code: string, message: string, translation_key: string, translation_params: array<string, mixed>, details: array<string, mixed>}>
     */
    public function inspectBlockers(PurchaseReturn $return): array
    {
        $context = app(CompanyContext::class);
        $actor = auth()->user();
        if (! $actor instanceof User || ! $context->hasCompany() || (int) $context->companyId() !== (int) $return->company_id) {
            throw new AuthorizationException('Authenticated active company actor required.');
        }

        return DB::transaction(function () use ($return, $actor, $context): array {
            $companyId = (int) $context->companyId();
            app(SalesActorGuard::class)->lockAndAuthorize($companyId, $actor, 'purchasing.return.void');
            app(SalesActorGuard::class)->lockAndAuthorize($companyId, $actor, 'purchasing.cost.view');
            $return = PurchaseReturn::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($return->id);

            return $this->inspectAuthorized($return);
        });
    }

    /** @return list<array{code: string, message: string, translation_key: string, translation_params: array<string, mixed>, details: array<string, mixed>}> */
    private function inspectAuthorized(PurchaseReturn $return): array
    {
        $companyId = (int) $return->company_id;
        $blockers = [];

        // 1. Parent purchase status check
        /** @var Purchase|null $purchase */
        $purchase = Purchase::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($return->purchase_id);

        if ($purchase === null || ! $purchase->isPosted()) {
            $blockers[] = [
                'code' => $purchase?->isVoid() ? 'parent_purchase_voided' : 'parent_purchase_not_posted',
                'message' => $purchase?->isVoid()
                    ? 'Cannot void purchase return because original purchase is voided.'
                    : 'Cannot void purchase return because original purchase is not in posted status.',
                'translation_key' => $purchase?->isVoid()
                    ? 'purchasing.void_return_blocked_parent_voided'
                    : 'purchasing.void_return_blocked_parent_not_posted',
                'translation_params' => ['purchase_id' => $return->purchase_id],
                'details' => ['purchase_id' => $return->purchase_id, 'status' => $purchase?->status],
            ];
        }

        // 2. Inactive warehouse check
        $warehouse = Warehouse::where('company_id', $companyId)->find($return->warehouse_id);
        if ($warehouse === null || ! $warehouse->active) {
            $blockers[] = [
                'code' => 'inactive_warehouse',
                'message' => 'Original receiving warehouse is inactive.',
                'translation_key' => 'purchasing.inactive_warehouse',
                'translation_params' => ['warehouse_id' => $return->warehouse_id],
                'details' => ['warehouse_id' => $return->warehouse_id],
            ];
        }

        // 3. Inactive products check
        $lines = $return->lines()->withoutGlobalScopes()->where('company_id', $companyId)->get();
        $checkedProducts = [];
        foreach ($lines as $line) {
            $productId = (int) $line->product_id;
            if (isset($checkedProducts[$productId])) {
                continue;
            }
            $checkedProducts[$productId] = true;
            $product = Product::where('company_id', $companyId)->find($productId);
            if ($product === null || ! $product->active) {
                $blockers[] = [
                    'code' => 'inactive_product',
                    'message' => "Product [{$productId}] is inactive.",
                    'translation_key' => 'purchasing.inactive_product',
                    'translation_params' => ['product_id' => $productId],
                    'details' => ['product_id' => $productId],
                ];
            }
        }

        return $blockers;
    }

    /**
     * Canonically void a posted purchase return.
     * Restores inventory and reverses accounting effects within atomic company-serialized boundary.
     */
    public function execute(PurchaseReturn $return, User $actor, string $reason, ?string $voidDate = null): PurchaseReturn
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $return->company_id) {
            throw new NoActiveCompanyException("Active company context does not match purchase return company [{$return->company_id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor must be authenticated and match user.');
        }

        if (! $actor->belongsToCompany($return->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$return->company_id}].");
        }

        setPermissionsTeamId($return->company_id);

        if (! $actor->hasPermissionTo('purchasing.return.void')) {
            throw new AuthorizationException('User does not have permission to void purchase returns.');
        }

        if (! $actor->hasPermissionTo('purchasing.cost.view')) {
            throw new AuthorizationException('User does not have permission to view purchasing costs.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Void reason is required.');
        }

        return app(TenantWriteAccess::class)->transaction((int) $return->company_id, function () use ($return, $actor, $reason, $voidDate): PurchaseReturn {
            /** @var Company $company */
            $company = Company::where('id', $return->company_id)->lockForUpdate()->firstOrFail();

            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'purchasing.return.void');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');

            /** @var PurchaseReturn $locked */
            $locked = PurchaseReturn::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('id', $return->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent retry check
            if ($locked->isVoid()) {
                if (trim((string) $locked->void_reason) !== trim($reason)) {
                    throw new IdempotencyConflictException('Cannot void purchase return: reason mismatch on retry.');
                }
                if ($voidDate !== null) {
                    $effectiveDate = $locked->void_business_date?->format('Y-m-d');
                    if ($effectiveDate !== null && $effectiveDate !== $voidDate) {
                        throw new IdempotencyConflictException('Cannot void purchase return: date mismatch on retry.');
                    }
                }

                return $locked->fresh(['lines.allocations', 'postingBatch', 'voidPostingBatch']);
            }

            if (! $locked->isPosted()) {
                throw new InvalidArgumentException("Cannot void purchase return in [{$locked->status}] status. Only posted returns can be voided.");
            }

            $locked->setRelation(
                'lines',
                $locked->lines()->withoutGlobalScopes()->lockForUpdate()->get()
            );

            foreach ($locked->lines as $line) {
                $line->setRelation(
                    'allocations',
                    $line->allocations()->withoutGlobalScopes()->lockForUpdate()->get()
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
                if ($voidDate < $locked->return_date->format('Y-m-d')) {
                    throw new InvalidArgumentException('Void date cannot precede return date.');
                }
            } else {
                $voidDate = now($company->timezone)->toDateString();
                if ($voidDate < $locked->return_date->format('Y-m-d')) {
                    $voidDate = $locked->return_date->format('Y-m-d');
                }
            }

            $scope = app(PurchaseReturnVoidScope::class);
            $this->activeReturnVoidScope = $scope;

            try {
                return $scope->withinCanonicalReturnVoid($this, $locked, $actor, function (PurchaseReturnVoidCapability $capability) use ($company, $locked, $actor, $reason, $voidDate, $scope): PurchaseReturn {
                    // Compensating inbound stock movements
                    $movementService = app(InventoryMovementService::class);

                    foreach ($locked->lines as $line) {
                        $parts = [];
                        foreach ($line->allocations as $alloc) {
                            /** @var StockMovement $origMovement */
                            $origMovement = StockMovement::withoutGlobalScopes()
                                ->where('company_id', $company->id)
                                ->findOrFail($alloc->stock_movement_id);

                            $parts[] = new StockMovementLineCommand(
                                productId: (int) $line->product_id,
                                warehouseId: (int) $locked->warehouse_id,
                                quantity: Quantity::of($origMovement->source_quantity),
                                unitId: (int) $origMovement->unit_id,
                                unitCostBase: (string) $origMovement->unit_cost_base,
                                lotId: $alloc->inventory_lot_id,
                                valueDeltaBase: (string) BigDecimal::of($origMovement->value_delta_base)->abs(),
                                originalMovementId: (int) $origMovement->id
                            );
                        }

                        $command = new StockMovementCommand(
                            companyId: (int) $company->id,
                            movementType: StockMovement::TYPE_PURCHASE,
                            movementDate: $voidDate,
                            lines: $parts,
                            sourceType: 'purchase_return_void',
                            sourceId: (int) $locked->id,
                            idempotencyKey: 'purchase_return_'.$locked->id.'_line_'.$line->id.'_void_stock',
                            createdBy: (int) $actor->id,
                            sourceLineId: (int) $line->id,
                            reason: "Void Purchase Return {$locked->return_number}"
                        );

                        $movementService->recordPurchaseReturnVoid($command, $capability);
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
                        'purchase_return.voided',
                        'Purchase return voided',
                        $actor->id,
                        $locked,
                        ['status' => PurchaseReturn::STATUS_POSTED],
                        $locked->only(['status', 'voided_at', 'voided_by', 'void_reason', 'void_posting_batch_id'])
                    );

                    return $locked->fresh(['lines.allocations', 'postingBatch', 'voidPostingBatch']);
                });
            } finally {
                $this->activeReturnVoidScope = null;
            }
        }, attempts: 3);
    }
}
