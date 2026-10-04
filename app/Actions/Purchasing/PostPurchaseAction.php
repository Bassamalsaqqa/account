<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\DocumentSequence;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Purchasing\PurchaseAcquisitionValue;
use App\Services\Purchasing\PurchaseDraftIntegrity;
use App\Services\Purchasing\PurchaseIdentitySnapshot;
use App\Services\Purchasing\PurchaseInputTaxAccount;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Services\Purchasing\PurchasePostingScope;
use App\Services\Purchasing\PurchaseReceiptCapability;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\SalesActorGuard;
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
                    $valuation = app(PurchaseAcquisitionValue::class);
                    foreach ($locked->lines as $line) {
                        $value = $valuation->line($line, $taxAccounts[$line->id]);
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
                        $line->completeCanonicalReceipt($movements[0], $taxAccounts[$line->id], $actor);
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
