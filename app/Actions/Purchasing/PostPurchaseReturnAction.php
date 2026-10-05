<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\DocumentSequence;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Audit\AuditService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Purchasing\HistoricalPurchaseReceiptValue;
use App\Services\Purchasing\PurchaseIdentitySnapshot;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Services\Purchasing\PurchaseReturnAmounts;
use App\Services\Purchasing\PurchaseReturnIssueCapability;
use App\Services\Purchasing\PurchaseReturnPostingCommandBuilder;
use App\Services\Purchasing\PurchaseReturnPostingScope;
use App\Services\Purchasing\PurchaseReturnValidationException;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\SalesActorGuard;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostPurchaseReturnAction
{
    private function __clone() {}

    private ?PurchaseReturnPostingScope $activePostingScope = null;

    /** Runtime ownership proof; there is deliberately no public activation setter. */
    public function ownsPostingScope(PurchaseReturnPostingScope $scope): bool
    {
        return $this->activePostingScope === $scope;
    }

    public function execute(PurchaseReturn $return, User $actor): PurchaseReturn
    {
        if ($this->activePostingScope !== null) {
            throw new InvalidInventoryMovementException('Canonical Purchase Return posting cannot be reentered.');
        }

        return DB::transaction(function () use ($return, $actor): PurchaseReturn {
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize((int) $return->company_id, $actor, 'purchasing.return.manage');
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');

            /** @var PurchaseReturn $lockedReturn */
            $lockedReturn = PurchaseReturn::where('company_id', $company->id)
                ->lockForUpdate()
                ->findOrFail($return->id);

            $builder = app(PurchaseReturnPostingCommandBuilder::class);
            if ($lockedReturn->status === PurchaseReturn::STATUS_POSTED) {
                $builder->validatePosted($lockedReturn);

                return $lockedReturn;
            }

            /** @var Purchase $purchase */
            $purchase = Purchase::where('company_id', $company->id)
                ->lockForUpdate()
                ->findOrFail($lockedReturn->purchase_id);

            app(PurchasePostingCommandBuilder::class)->validatePosted($purchase);

            /** @var Vendor $vendor */
            $vendor = Vendor::withTrashed()
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->findOrFail($lockedReturn->vendor_id);

            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::where('company_id', $company->id)
                ->lockForUpdate()
                ->findOrFail($lockedReturn->warehouse_id);

            if (! $warehouse->active) {
                throw new PurchaseReturnValidationException('purchasing.inactive_warehouse', 'Original warehouse is inactive.');
            }

            if (! CompanyCurrency::where('company_id', $company->id)
                ->where('currency_code', $lockedReturn->currency_code)
                ->where('enabled', true)
                ->exists()) {
                throw new PurchaseReturnValidationException('purchasing.disabled_currency', 'Original transaction currency is disabled in the company.');
            }

            if ($lockedReturn->return_date->lt($purchase->purchase_date)) {
                throw new PurchaseReturnValidationException('purchasing.return_date_before_purchase', 'Return date cannot be earlier than original purchase date.');
            }

            $lockedReturn->setRelation(
                'lines',
                $lockedReturn->lines()->withoutGlobalScopes()->lockForUpdate()->get()
            );

            if ($lockedReturn->lines->isEmpty()) {
                throw new InvalidArgumentException('Purchase return must contain at least one line.');
            }

            foreach ($lockedReturn->lines as $line) {
                $line->setRelation(
                    'allocations',
                    $line->allocations()->withoutGlobalScopes()->lockForUpdate()->get()
                );
                if ($line->allocations->isEmpty()) {
                    throw new InvalidArgumentException('Purchase return lines must have allocations.');
                }

                if ($line->purchase_tax_account_id !== null) {
                    $taxAccount = LedgerAccount::where('company_id', $company->id)->find($line->purchase_tax_account_id);
                    if ($taxAccount === null || ! $taxAccount->active) {
                        throw new PurchaseReturnValidationException('purchasing.inactive_tax_account', 'Historical purchase tax account is missing or inactive.');
                    }
                }
            }

            // Pure read-only verification of complete persisted draft economics, provenance and limits
            app(PurchaseReturnAmounts::class)->validateDraftIntegrity($lockedReturn, $purchase);

            // Refresh mutable party/company snapshots inside the outer transaction before effects
            $identity = app(PurchaseIdentitySnapshot::class);
            $lockedReturn->vendor_snapshot = $identity->vendor($vendor);
            $lockedReturn->company_snapshot = $identity->company($company);
            $lockedReturn->save();

            $scope = app(PurchaseReturnPostingScope::class);
            $this->activePostingScope = $scope;

            try {
                return $scope->withinCanonicalReturnPosting(
                    $this,
                    $lockedReturn,
                    $actor,
                    function (PurchaseReturnIssueCapability $capability) use ($company, $lockedReturn, $actor, $builder, $scope): PurchaseReturn {
                        $number = app(DocumentSequenceService::class)->generateNextNumber(
                            (int) $company->id,
                            DocumentSequence::TYPE_PURCHASE_RETURN,
                            (int) $lockedReturn->return_date->format('Y')
                        );
                        $scope->bindAllocatedNumber($number);

                        $movementService = app(InventoryMovementService::class);
                        $receiptValuation = app(HistoricalPurchaseReceiptValue::class);

                        foreach ($lockedReturn->lines as $line) {
                            $product = Product::where('company_id', $company->id)->findOrFail($line->product_id);
                            $baseUnitId = $product->base_unit_id;

                            $parts = [];
                            foreach ($line->allocations as $alloc) {
                                $parts[] = new StockMovementLineCommand(
                                    productId: (int) $line->product_id,
                                    warehouseId: (int) $lockedReturn->warehouse_id,
                                    quantity: Quantity::of($alloc->quantity_base),
                                    unitId: (int) $baseUnitId,
                                    lotId: $alloc->inventory_lot_id,
                                    originalMovementId: (int) $alloc->original_stock_movement_id
                                );
                            }

                            $command = new StockMovementCommand(
                                companyId: (int) $company->id,
                                movementType: StockMovement::TYPE_PURCHASE_RETURN,
                                movementDate: $lockedReturn->return_date->format('Y-m-d'),
                                lines: $parts,
                                sourceType: 'purchase_return',
                                sourceId: (int) $lockedReturn->id,
                                idempotencyKey: 'purchase_return_'.$lockedReturn->id.'_line_'.$line->id.'_stock',
                                createdBy: (int) $actor->id,
                                sourceLineId: (int) $line->id
                            );

                            $movements = $movementService->recordPurchaseReturnIssue($command, $capability);

                            $lineHistoricalBase = BigDecimal::zero();
                            $lineActualBase = BigDecimal::zero();

                            foreach ($line->allocations as $idx => $alloc) {
                                $mov = $movements[$idx];
                                $allocActual = BigDecimal::of((string) $mov->value_delta_base)->abs();
                                $allocHistorical = $receiptValuation->target(
                                    (int) $company->id,
                                    (int) $alloc->original_stock_movement_id,
                                    BigDecimal::of((string) $alloc->quantity_base),
                                    null,
                                    (int) $lockedReturn->purchase_id,
                                    (int) $line->purchase_line_id
                                );

                                $alloc->completeCanonicalAllocation(
                                    $mov,
                                    (string) $allocHistorical,
                                    (string) $allocActual,
                                    $actor,
                                    $capability
                                );

                                $lineHistoricalBase = $lineHistoricalBase->plus($allocHistorical);
                                $lineActualBase = $lineActualBase->plus($allocActual);
                            }

                            $commercialH = BigDecimal::of((string) $line->line_total_base)
                                ->minus($line->purchase_tax_account_id !== null ? (string) $line->line_tax_base : '0');
                            $lineAdjustment = $lineActualBase->minus($commercialH);

                            $line->completeCanonicalReturn(
                                $movements[0],
                                (string) $lineHistoricalBase,
                                (string) $lineActualBase,
                                (string) $lineAdjustment,
                                $actor,
                                $capability
                            );
                        }

                        $postingCommand = $builder->build($company, $lockedReturn, $number, $actor, false);
                        $batch = $postingCommand !== null
                            ? app(AccountingPostingService::class)->post($postingCommand)
                            : null;

                        $lockedReturn->completeCanonicalPost($batch, $number, $actor, $capability);

                        app(AuditService::class)->log(
                            (int) $company->id,
                            'purchase_return.posted',
                            'Purchase return posted',
                            $actor->id,
                            $lockedReturn,
                            ['status' => PurchaseReturn::STATUS_DRAFT],
                            [
                                'status' => PurchaseReturn::STATUS_POSTED,
                                'return_number' => $number,
                                'posting_batch_id' => $batch?->id,
                                'purchase_id' => $lockedReturn->purchase_id,
                            ]
                        );

                        return $lockedReturn->fresh(['lines.allocations']);
                    }
                );
            } finally {
                $this->activePostingScope = null;
            }
        }, attempts: 3);
    }
}
