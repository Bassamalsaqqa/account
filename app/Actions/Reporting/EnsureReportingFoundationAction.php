<?php

declare(strict_types=1);

namespace App\Actions\Reporting;

use App\Exceptions\CompanyReassignmentException;
use App\Models\Company;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;

final class EnsureReportingFoundationAction
{
    /**
     * Idempotently provision Phase 8 reporting foundation:
     * - Owner catalog upgrade (preserves customized non-owner roles)
     * - Does NOT mutate sequences or create business records
     */
    public function execute(Company $company): void
    {
        $context = app(CompanyContext::class);
        $isSystem = ! $context->hasCompany();

        if (! $isSystem && $context->companyId() !== $company->id) {
            throw new CompanyReassignmentException("Cannot provision reporting foundation for company [{$company->id}] when active company is [{$context->companyId()}].");
        }

        CompanyScope::executeWithoutScope(function () use ($company): void {
            DB::transaction(function () use ($company): void {
                $lockedCompany = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();

                // Upgrade catalog permissions for Owner only (preserves customized non-owner roles)
                app(CompanyRoleService::class)->upgradeReportingCatalog($lockedCompany);
            });
        });
    }
}
