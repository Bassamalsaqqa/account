<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    /**
     * Determine whether the user can view roles.
     */
    public function viewAny(User $user): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            return false;
        }

        return $user->hasAnyPermission(['settings.roles.view', 'settings.roles.manage']);
    }

    /**
     * Determine whether the user can update role permissions.
     */
    public function update(User $user, Role $role): bool
    {
        $roleCompanyId = $role->getAttribute('company_id');
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $roleCompanyId !== $context->companyId()) {
            return false;
        }

        // Owner role permissions cannot be trimmed or edited
        if ($role->name === 'Owner') {
            return false;
        }

        return $user->hasPermissionTo('settings.roles.manage');
    }
}
