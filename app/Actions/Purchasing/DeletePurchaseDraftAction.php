<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Application\Platform\TenantWriteAccess;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
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
            foreach (['purchase_returns', 'vendor_payment_allocations', 'landed_cost_allocations',
                'ai_purchase_draft_receipts', 'purchase_confirmation_receipts', 'purchase_correction_receipts'] as $table) {
                $blocked = $blocked || DB::table($table)->where('purchase_id', $locked->id)->exists();
            }
            $blocked = $blocked || DB::table('purchase_correction_receipts')->where('replacement_id', $locked->id)->exists()
                || DB::table('assistant_requests')->where('purchase_id', $locked->id)->exists();
            foreach (['posting_batches', 'stock_movements'] as $table) {
                $blocked = $blocked || DB::table($table)->where('source_type', 'purchase')->where('source_id', $locked->id)->exists();
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
