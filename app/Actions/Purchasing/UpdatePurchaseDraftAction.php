<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Purchase;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Purchasing\PurchaseDraftBuilder;
use App\Services\Purchasing\PurchaseDraftWriter;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;

final class UpdatePurchaseDraftAction
{
    /** @param array<string, mixed> $data */
    public function execute(Purchase $purchase, User $actor, array $data): Purchase
    {
        return DB::transaction(function () use ($purchase, $actor, $data): Purchase {
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize((int) $purchase->company_id, $actor, 'purchasing.purchase.edit_draft');
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');
            $locked = Purchase::where('company_id', $company->id)->lockForUpdate()->findOrFail($purchase->id);
            $locked->assertMutableDraft();
            $before = $locked->only(['vendor_id', 'warehouse_id', 'currency_code']);
            $draft = app(PurchaseDraftBuilder::class)->prepare($company, $data, $locked);
            $locked->fill($draft->header)->fill(['updated_by' => $actor->id])->save();
            app(PurchaseDraftWriter::class)->replaceLines($locked, $draft);
            app(AuditService::class)->log($company->id, 'purchase.draft.updated', 'Purchase draft updated', $actor->id, $locked, $before, $locked->only(array_keys($before)));

            return $locked->fresh(['lines.lots']);
        });
    }
}
