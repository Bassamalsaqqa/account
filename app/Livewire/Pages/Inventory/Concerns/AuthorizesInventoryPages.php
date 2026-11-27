<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Inventory\Concerns;

use App\Models\Company;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

trait AuthorizesInventoryPages
{
    #[Locked]
    public int $pageCompanyId;

    protected function authorizeInventory(string $permission): Company
    {
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany() && $context->companyId() === $this->pageCompanyId, 403);

        try {
            return DB::transaction(fn () => app(SalesActorGuard::class)->lockAndAuthorize(
                $this->pageCompanyId, auth()->user(), $permission
            ));
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    /**
     * Read-only authorization for boot/render paths without database row locks.
     *
     * @param  string|list<string>  $permissions
     */
    protected function authorizeInventoryReadOnly(string|array $permissions): Company
    {
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany() && $context->companyId() === $this->pageCompanyId, 403);

        try {
            return app(SalesActorGuard::class)->authorizeReadOnly(
                $this->pageCompanyId, auth()->user(), $permissions
            );
        } catch (AuthorizationException) {
            abort(403);
        }
    }
}
