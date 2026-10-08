<?php

declare(strict_types=1);

namespace App\Application\Reporting\Security;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;

final class ReportingGuard
{
    /** @param list<string>|string $requiredPermissions */
    public function authorize(Company $company, ?User $actor = null, array|string $requiredPermissions = []): User
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
            || ! Company::whereKey($company->id)->where('status', 'active')->exists()
            || ! CompanyUser::where('company_id', $company->id)->where('user_id', $authenticated->id)->where('status', 'active')->exists()) {
            throw new AuthorizationException('Active matching company membership is required for reports.');
        }

        setPermissionsTeamId($company->id);
        $authenticated->unsetRelation('roles')->unsetRelation('permissions');
        foreach (is_array($requiredPermissions) ? $requiredPermissions : [$requiredPermissions] as $permission) {
            if (! $authenticated->hasPermissionTo($permission)) {
                throw new AuthorizationException('The required report/source permission is missing.');
            }
        }

        return $authenticated;
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
