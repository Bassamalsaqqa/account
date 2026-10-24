<?php

declare(strict_types=1);

namespace App\Application\Reporting\Security;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

final class ReportingGuard
{
    /** @param list<string>|string $requiredPermissions */
    public function authorize(Company $company, ?User $actor = null, array|string $requiredPermissions = []): User
    {
        $this->readPermissions($company, $actor, $requiredPermissions);

        return auth()->user();
    }

    /** One fresh authority evaluation plus its finite permission names; no cached grant.
     * @param  list<string>|string  $requiredPermissions
     * @return list<string>
     */
    public function readPermissions(Company $company, ?User $actor = null, array|string $requiredPermissions = []): array
    {
        $context = app(CompanyContext::class);
        $authenticated = auth()->user();
        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('An active company context is required for reports.');
        }
        if (! $authenticated instanceof User
            || (int) $context->companyId() !== (int) $company->id
            || (int) $context->user()?->id !== (int) $authenticated->id
            || ($actor !== null && (int) $actor->id !== (int) $authenticated->id)
            || ! CompanyUser::where('company_id', $company->id)->where('user_id', $authenticated->id)->where('status', 'active')
                ->whereHas('company', fn ($query) => $query->where('status', 'active'))->exists()) {
            throw new AuthorizationException('Active matching company membership is required for reports.');
        }

        setPermissionsTeamId($company->id);
        $authenticated->unsetRelation('roles')->unsetRelation('permissions');
        $permissions = $this->permissionNames($authenticated);
        foreach (is_array($requiredPermissions) ? $requiredPermissions : [$requiredPermissions] as $permission) {
            if (! in_array($permission, $permissions, true)) {
                throw new AuthorizationException('The required report/source permission is missing.');
            }
        }

        return $permissions;
    }

    /** Fresh team-aware direct and role permissions in one SQL union, never a cached grant.
     * @return list<string>
     */
    public function permissionNames(User $actor): array
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || ! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Matching company actor required.');
        }
        setPermissionsTeamId($context->companyId());
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        // Use Spatie's relationships themselves: morph type, team pivots and
        // global/current-team role semantics remain owned by the installed package.
        $direct = $actor->permissions()->where('guard_name', 'web')->reorder()->select('permissions.name');
        $roleIds = $actor->roles()->reorder()->select('roles.id');
        $viaRoles = Permission::query()->where('guard_name', 'web')->whereIn('id',
            DB::table('role_has_permissions')->whereIn('role_id', $roleIds->getQuery())->select('permission_id'))
            ->select('permissions.name');

        $names = $direct->union($viaRoles)->pluck('name')->all();
        // Authority snapshots compare effective permission names deterministically.
        sort($names, SORT_STRING);

        return $names;
    }

    /** Resolve configuration from persisted company identity, never caller attributes. */
    public function company(Company $company, ?User $actor = null): Company
    {
        $this->authorize($company, $actor);

        return Company::whereKey($company->id)->where('status', 'active')->firstOrFail();
    }

    /** @param list<string>|string $permissions */
    public function allows(Company $company, array|string $permissions, ?User $actor = null): bool
    {
        try {
            $this->authorize($company, $actor, $permissions);

            return true;
        } catch (AuthorizationException|NoActiveCompanyException) {
            return false;
        }
    }

    public function authorizeProfit(Company $company, ?User $actor = null): User
    {
        return $this->authorize($company, $actor, ReportPermissionCatalog::PROFIT);
    }

    public function canViewCost(User $user): bool
    {
        return $this->capability($user, [ReportPermissionCatalog::REPORTS_COST_VIEW]);
    }

    public function canViewInventoryCost(User $user): bool
    {
        return $this->capability($user, [ReportPermissionCatalog::INVENTORY_COST_VIEW, ReportPermissionCatalog::REPORTS_COST_VIEW]);
    }

    public function canViewPurchasingCost(User $user): bool
    {
        return $this->capability($user, [ReportPermissionCatalog::PURCHASING_COST_VIEW]);
    }

    public function canViewPayroll(User $user): bool
    {
        return $this->capability($user, [ReportPermissionCatalog::PAYROLL_SALARY_VIEW]);
    }

    /** @param list<string> $permissions */
    private function capability(User $user, array $permissions): bool
    {
        $context = app(CompanyContext::class);

        return $context->hasCompany() && $this->allows($context->company(), $permissions, $user);
    }
}
