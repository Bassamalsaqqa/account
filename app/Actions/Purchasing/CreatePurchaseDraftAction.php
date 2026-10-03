<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Company;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Purchasing\PurchaseDraftBuilder;
use App\Services\Purchasing\PurchaseDraftWriter;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;

final class CreatePurchaseDraftAction
{
    /** @param array<string, mixed> $data */
    public function execute(Company $company, User $actor, array $data): Purchase
    {
        return DB::transaction(function () use ($company, $actor, $data): Purchase {
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.purchase.create');
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');
            $draft = app(PurchaseDraftBuilder::class)->prepare($company, $data);
            $purchase = Purchase::create($draft->header + ['company_id' => $company->id, 'created_by' => $actor->id]);
            app(PurchaseDraftWriter::class)->replaceLines($purchase, $draft);
            app(AuditService::class)->log($company->id, 'purchase.draft.created', 'Purchase draft created', $actor->id, $purchase, null, $purchase->only(['vendor_id', 'warehouse_id', 'currency_code']));

            return $purchase->load('lines.lots');
        });
    }
}
