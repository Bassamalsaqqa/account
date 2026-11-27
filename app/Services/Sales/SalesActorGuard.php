<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class SalesActorGuard
{
    /** Company is always the first lock; fresh team permissions cannot reuse another team's cached relations. */
    public function lockAndAuthorize(int $companyId, User $actor, string $permission): Company
    {
        $context = app(CompanyContext::class);
        if (DB::transactionLevel() === 0 || ! $context->hasCompany() || (int) $context->companyId() !== $companyId
            || ! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('An authenticated matching company actor and transaction are required.');
        }
        $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
        $membership = CompanyUser::where('company_id', $companyId)->where('user_id', $actor->id)->lockForUpdate()->first();
        setPermissionsTeamId($companyId);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        if ($company->status !== 'active' || $membership?->status !== 'active' || ! $this->hasPermissionSafely($actor, $permission)) {
            throw new AuthorizationException('Active membership and the requested company permission are required.');
        }

        return $company;
    }

    /**
     * Read-only authorization for component hydration/rendering/selector queries without write row locks.
     * Re-evaluates active company, active membership, and fresh team permissions on every call.
     *
     * @param  string|list<string>  $permissions
     */
    public function authorizeReadOnly(int $companyId, User $actor, string|array $permissions): Company
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== $companyId
            || ! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('An authenticated matching company actor is required.');
        }
        $company = Company::whereKey($companyId)->firstOrFail();
        $membership = CompanyUser::where('company_id', $companyId)->where('user_id', $actor->id)->first();
        setPermissionsTeamId($companyId);
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        if ($company->status !== 'active' || $membership?->status !== 'active') {
            throw new AuthorizationException('Active membership and an active company are required.');
        }

        foreach ((array) $permissions as $permission) {
            if (! $this->hasPermissionSafely($actor, $permission)) {
                throw new AuthorizationException('Active membership and the requested company permission are required.');
            }
        }

        return $company;
    }

    private function hasPermissionSafely(User $actor, string $permission): bool
    {
        try {
            return $actor->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
