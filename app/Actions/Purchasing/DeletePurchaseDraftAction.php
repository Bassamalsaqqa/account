<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Application\Platform\TenantWriteAccess;
use App\Models\AiPurchaseDraftReceipt;
use App\Models\AssistantRequest;
use App\Models\LandedCostAllocation;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\PurchaseConfirmationReceipt;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VendorPaymentAllocation;
use App\Services\Audit\AuditService;
use App\Services\Corrections\PurchaseCorrection;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class DeletePurchaseDraftAction
{
    public function execute(Purchase $purchase, User $actor): void
    {
        app(TenantWriteAccess::class)->transaction((int) $purchase->company_id, function () use ($purchase, $actor): void {
            $guard = app(SalesActorGuard::class);
            $guard->lockAndAuthorize((int) $purchase->company_id, $actor, 'purchasing.purchase.edit_draft');
            $guard->lockAndAuthorize((int) $purchase->company_id, $actor, 'purchasing.cost.view');
            if (! $actor->hasRole('Owner')) {
                throw new AuthorizationException;
            }
            $locked = Purchase::where('company_id', $purchase->company_id)->lockForUpdate()->findOrFail($purchase->id);
            $blocked = ! $locked->isDraft();
            foreach (Purchase::RESERVED_FIELDS as $field) {
                $blocked = $blocked || $locked->getAttribute($field) !== null;
            }
            // Preserve retry receipts, correction provenance and every dependent business event.
            $companyId = (int) $locked->company_id;
            foreach ([
                PurchaseReturn::class,
                VendorPaymentAllocation::class,
                LandedCostAllocation::class,
                AiPurchaseDraftReceipt::class,
                PurchaseConfirmationReceipt::class,
                AssistantRequest::class,
            ] as $modelClass) {
                $blocked = $blocked || $modelClass::where('company_id', $companyId)->where('purchase_id', $locked->id)->exists();
            }
            $blocked = $blocked || app(PurchaseCorrection::class)->hasReceiptForPurchase($companyId, (int) $locked->id);
            foreach ([PostingBatch::class, StockMovement::class] as $modelClass) {
                $blocked = $blocked || $modelClass::where('company_id', $companyId)
                    ->where('source_type', 'purchase')
                    ->where('source_id', $locked->id)
                    ->exists();
            }
            $blocked = $blocked || $locked->lines()->where('company_id', '!=', $locked->company_id)->exists();
            $blocked = $blocked || $locked->lines()->where(function ($query): void {
                $query->whereNotNull('stock_movement_id')->orWhereNotNull('inventory_unit_cost_base')->orWhereNotNull('purchase_tax_account_id');
            })->exists();
            foreach ($locked->lines()->get() as $line) {
                $blocked = $blocked || $line->lots()->where('company_id', '!=', $locked->company_id)->exists();
                $blocked = $blocked || $line->lots()->where(function ($query): void {
                    $query->whereNotNull('stock_movement_id')->orWhereNotNull('created_inventory_lot_id');
                })->exists();
            }
            if ($blocked) {
                throw ValidationException::withMessages(['deleteDraft' => __('purchase_draft_delete.blocked')]);
            }
            app(AuditService::class)->log((int) $locked->company_id, 'purchase.draft.deleted', 'Owner discarded an unposted purchase draft', (int) $actor->id, $locked,
                before: ['public_id' => $locked->public_id, 'vendor_id' => $locked->vendor_id, 'line_count' => $locked->lines()->count()]);
            foreach ($locked->lines()->get() as $line) {
                foreach ($line->lots()->get() as $lot) {
                    $lot->delete();
                }
                $line->delete();
            }
            $locked->delete();
        });
    }
}
