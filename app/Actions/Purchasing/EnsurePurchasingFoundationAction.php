<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Company;
use App\Models\CompanyPurchaseSetting;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EnsurePurchasingFoundationAction
{
    /** Explicit system provisioning: no ambient tenant, business masters or posting effects. */
    public function execute(Company $company): void
    {
        if (app(CompanyContext::class)->hasCompany()) {
            throw new InvalidArgumentException('System purchasing provisioning requires no active company context.');
        }
        CompanyScope::executeWithoutScope(function () use ($company): void {
            DB::transaction(function () use ($company): void {
                $company = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
                CompanyPurchaseSetting::firstOrCreate(['company_id' => $company->id]);
                app(DocumentSequenceService::class)->ensurePurchaseSequences($company->id);
                // Accepted catalog upgrade grants Owner only; never resync customized non-owner roles.
                app(CompanyRoleService::class)->upgradeSalesCatalog($company);
            });
        });
    }
}
