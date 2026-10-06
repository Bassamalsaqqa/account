<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing\Concerns;

use App\Models\Company;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

trait AuthorizesPurchasingPages
{
    #[Locked]
    public int $pageCompanyId;

    protected function authorizePurchasing(string $permission): Company
    {
        abort_unless(auth()->check(), 403);
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== $this->pageCompanyId) {
            abort(403);
        }
        try {
            return DB::transaction(fn () => app(SalesActorGuard::class)->lockAndAuthorize(
                $this->pageCompanyId, auth()->user(), $permission
            ));
        } catch (AuthorizationException $exception) {
            abort(403);
        }
    }

    protected function authorizePaymentFinancialRead(): Company
    {
        abort_unless($this->canReadVendorFinancials(), 403);

        return app(CompanyContext::class)->company();
    }

    protected function canReadVendorFinancials(): bool
    {
        $context = app(CompanyContext::class);
        $user = auth()->user();
        if ($user === null || ! $context->hasCompany()
            || (int) $context->companyId() !== $this->pageCompanyId
            || ! $user->fresh()?->belongsToCompany($this->pageCompanyId)) {
            return false;
        }

        setPermissionsTeamId($this->pageCompanyId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user->hasPermissionTo('purchasing.cost.view')
            && ($user->hasPermissionTo('money.vendor_payment.create')
                || $user->hasPermissionTo('money.vendor_payment.allocate')
                || $user->hasPermissionTo('money.vendor_payment.reverse')
                || $user->hasPermissionTo('vendors.statement.view'));
    }
}
