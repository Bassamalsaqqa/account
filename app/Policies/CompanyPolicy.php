<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;

class CompanyPolicy
{
    /**
     * Determine whether the user can view the company settings.
     */
    public function view(User $user, Company $company): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || $context->companyId() !== $company->id) {
            return false;
        }

        $isMember = $user->memberships()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            return false;
        }

        return $user->hasAnyPermission(['settings.company.view', 'settings.company.manage']);
    }

    /**
     * Determine whether the user can update the company settings.
     */
    public function update(User $user, Company $company): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || $context->companyId() !== $company->id) {
            return false;
        }

        $isMember = $user->memberships()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            return false;
        }

        return $user->hasPermissionTo('settings.company.manage');
    }
}
