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
}
