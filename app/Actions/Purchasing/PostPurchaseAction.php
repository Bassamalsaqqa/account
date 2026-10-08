<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\DocumentSequence;
use App\Models\Expense;
use App\Models\LandedCostAllocation;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Purchasing\LandedCostIntegrity;
use App\Services\Purchasing\PurchaseAcquisitionValue;
use App\Services\Purchasing\PurchaseDraftIntegrity;
use App\Services\Purchasing\PurchaseIdentitySnapshot;
use App\Services\Purchasing\PurchaseInputTaxAccount;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Services\Purchasing\PurchasePostingScope;
use App\Services\Purchasing\PurchaseReceiptCapability;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\SalesActorGuard;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class PostPurchaseAction
{
    private function __clone() {}

    private ?PurchasePostingScope $activePostingScope = null;

    /** Runtime ownership proof; there is deliberately no public activation setter. */
    public function ownsPostingScope(PurchasePostingScope $scope): bool
    {
        return $this->activePostingScope === $scope;
    }

    public function execute(Purchase $purchase, User $actor): Purchase
    {
        if ($this->activePostingScope !== null) {
            throw new InvalidInventoryMovementException('Canonical Purchase posting cannot be reentered.');
        }

        return DB::transaction(function () use ($purchase, $actor): Purchase {
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize((int) $purchase->company_id, $actor, 'purchasing.purchase.post');
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');
            $locked = Purchase::where('company_id', $company->id)->lockForUpdate()->findOrFail($purchase->id);
            $builder = app(PurchasePostingCommandBuilder::class);
            if ($locked->status === Purchase::STATUS_POSTED) {
                $builder->validatePosted($locked);

                return $locked;
            }
            $vendor = $locked->vendor()->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $locked->warehouse()->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('lines', $locked->lines()->withoutGlobalScopes()->lockForUpdate()->get());
            foreach ($locked->lines as $line) {
                $line->setRelation('lots', $line->lots()->withoutGlobalScopes()->lockForUpdate()->get());
            }
            app(PurchaseDraftIntegrity::class)->validate($company, $locked);
            $taxAccounts = [];
            foreach ($locked->lines as $line) {
                $tax = $line->tax_rate_id === null ? null : TaxRate::where('company_id', $company->id)->where('active', true)->lockForUpdate()->findOrFail($line->tax_rate_id);
                $taxAccounts[$line->id] = app(PurchaseInputTaxAccount::class)->resolve((int) $company->id, $tax?->purchase_tax_account_id)?->id;
            }
            $identity = app(PurchaseIdentitySnapshot::class);
            $locked->vendor_snapshot = $identity->vendor($vendor);
            $locked->company_snapshot = $identity->company($company);
            $locked->save();
            $scope = app(PurchasePostingScope::class);
            $this->activePostingScope = $scope;
            try {
                return $scope->withinCanonicalPosting($this, $locked, $actor, function (PurchaseReceiptCapability $capability) use ($company, $locked, $actor, $taxAccounts, $builder): Purchase {
                    $number = app(DocumentSequenceService::class)->generateNextNumber((int) $company->id, DocumentSequence::TYPE_PURCHASE, (int) $locked->purchase_date->format('Y'));

                    $draftAllocations = LandedCostAllocation::where('company_id', $company->id)
                        ->where('purchase_id', $locked->id)
                        ->where('status', LandedCostAllocation::STATUS_DRAFT)
                        ->lockForUpdate()
                        ->get();

                    app(LandedCostIntegrity::class)->validate($locked, false);
                    $lineLandedMap = [];
                    if ($draftAllocations->isNotEmpty()) {
                        $expenseIds = $draftAllocations->pluck('expense_id')->unique();
                        $expenses = Expense::where('company_id', $company->id)
                            ->whereIn('id', $expenseIds)
                            ->lockForUpdate()
                            ->get()
                            ->keyBy('id');

                        foreach ($expenseIds as $expenseId) {
                            $expense = $expenses->get($expenseId);
                            if ($expense === null || $expense->status !== Expense::STATUS_POSTED || $expense->reversed_at !== null || $expense->classification !== Expense::CLASSIFICATION_LANDED_COST) {
                                throw new \InvalidArgumentException(__('purchasing.post_integrity_failed'));
                            }
                            if ($locked->purchase_date->toDateString() < $expense->expense_date->toDateString()) {
                                throw new \InvalidArgumentException('The purchase business date cannot precede the attached landed cost expense date.');
                            }

                            $expAllocations = $draftAllocations->where('expense_id', $expenseId);
                            $allocSum = BigDecimal::zero();
                            foreach ($expAllocations as $alloc) {
                                $allocSum = $allocSum->plus($alloc->allocated_base);
                            }
                            if (! $allocSum->isEqualTo($expense->base_amount)) {
                                throw new \InvalidArgumentException(__('purchasing.post_integrity_failed'));
                            }
                        }

                        foreach ($draftAllocations as $alloc) {
                            $lineId = (int) $alloc->purchase_line_id;
                            $lineLandedMap[$lineId] = ($lineLandedMap[$lineId] ?? BigDecimal::zero())->plus($alloc->allocated_base);
                        }
                    }

                    $valuation = app(PurchaseAcquisitionValue::class);
                    foreach ($locked->lines as $line) {
                        $lineLandedBase = isset($lineLandedMap[$line->id]) ? (string) $lineLandedMap[$line->id]->toScale(6) : '0.000000';
                        $commercialValue = $valuation->commercial($line, $taxAccounts[$line->id]);
                        $value = $commercialValue->plus($lineLandedBase)->toScale(6);
                        $cost = $valuation->unitCost($line, $value);
                        $parts = [];
                        if ($line->lots->isNotEmpty()) {
                            $values = $valuation->lots($line, $value);
                            foreach ($line->lots as $index => $lot) {
                                $parts[] = new StockMovementLineCommand((int) $line->product_id, (int) $locked->warehouse_id,
                                    Quantity::of($lot->quantity), (int) $line->productUnit->unit_id, $cost,
                                    lotNumber: $lot->lot_number, expiryDate: $lot->expiry_date?->format('Y-m-d'), valueDeltaBase: (string) $values[$index]);
                            }
                        } else {
                            $parts[] = new StockMovementLineCommand((int) $line->product_id, (int) $locked->warehouse_id,
                                Quantity::of($line->quantity), (int) $line->productUnit->unit_id, $cost, valueDeltaBase: (string) $value);
                        }
                        $movements = app(InventoryMovementService::class)->recordPurchaseReceipt(new StockMovementCommand((int) $company->id,
                            StockMovement::TYPE_PURCHASE, $locked->purchase_date->format('Y-m-d'), $parts, 'purchase', (int) $locked->id,
                            'purchase_'.$locked->id.'_line_'.$line->id.'_stock', (int) $actor->id, (int) $line->id), $capability);
                        foreach ($line->lots as $index => $lot) {
                            $lot->completeCanonicalReceipt($movements[$index], $actor);
                        }
                        $line->completeCanonicalReceipt($movements[0], $taxAccounts[$line->id], $actor, $lineLandedBase);
                    }

                    foreach ($draftAllocations as $allocation) {
                        $allocation->completeCanonicalAllocation($capability);
                    }

                    $batch = app(AccountingPostingService::class)->post($builder->build($company, $locked, $number, $actor));
                    $locked->completeCanonicalPost($batch, $number, $actor);
                    app(AuditService::class)->log((int) $company->id, 'purchase.posted', 'Purchase posted', $actor->id, $locked,
                        ['status' => Purchase::STATUS_DRAFT], $locked->only(['status', 'purchase_number', 'posting_batch_id']));

                    return $locked->fresh(['lines.lots']);
                });
            } finally {
                $this->activePostingScope = null;
            }
        }, attempts: 3);
    }
}
