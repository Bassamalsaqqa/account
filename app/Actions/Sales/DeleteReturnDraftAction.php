<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Application\Platform\TenantWriteAccess;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Ordinary unposted return discard. Correction provenance and economic history always survive. */
final class DeleteReturnDraftAction
{
    public function execute(SalesReturn|PurchaseReturn $source, User $actor): void
    {
        app(TenantWriteAccess::class)->transaction((int) $source->company_id, function () use ($source, $actor): void {
            $purchase = $source instanceof PurchaseReturn;
            $family = $purchase ? 'purchase_return' : 'sales_return';
            $permissions = $purchase ? ['purchasing.purchase.view', 'purchasing.return.manage', 'purchasing.cost.view'] : ['sales.return.view', 'sales.return.manage'];
            foreach ($permissions as $permission) {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $source->company_id, $actor, $permission);
            }
            $locked = $source->newQuery()->where('company_id', $source->company_id)->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $history = DB::table($family.'_correction_receipts')->where('company_id', $locked->company_id)
                ->where(fn ($q) => $q->where('source_id', $locked->id)->orWhere('replacement_id', $locked->id))->exists();
            if (! $locked->isDraft() || $locked->posting_batch_id !== null || $locked->posted_at !== null || $history
                || $locked->lines()->where('company_id', $locked->company_id)->whereNotNull('stock_movement_id')->exists()
                || DB::table($purchase ? 'purchase_return_allocations' : 'sales_return_lot_allocations')->where('company_id', $locked->company_id)->where($purchase ? 'purchase_return_id' : 'sales_return_id', $locked->id)->whereNotNull('stock_movement_id')->exists()
                || DB::table('posting_batches')->where('company_id', $locked->company_id)->where('source_type', $family)->where('source_id', $locked->id)->exists()
                || DB::table('stock_movements')->where('company_id', $locked->company_id)->where('source_type', $family)->where('source_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['correction' => __('corrections.draft_retained')]);
            }
            app(AuditService::class)->log((int) $locked->company_id, $family.'.draft_deleted', 'Unposted return draft discarded', (int) $actor->id, $locked);
            foreach ($locked->lines()->where('company_id', $locked->company_id)->get() as $line) {
                $line->delete();
            }
            $locked->delete();
        });
    }
}
