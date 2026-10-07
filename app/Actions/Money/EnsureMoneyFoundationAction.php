<?php

declare(strict_types=1);

namespace App\Actions\Money;

use App\Models\Company;
use App\Models\DocumentSequence;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EnsureMoneyFoundationAction
{
    public function execute(Company $company): void
    {
        if (app(CompanyContext::class)->hasCompany()) {
            throw new InvalidArgumentException('System Money provisioning requires no ambient Company.');
        }
        CompanyScope::executeWithoutScope(function () use ($company): void {
            DB::transaction(function () use ($company): void {
                $company = Company::lockForUpdate()->findOrFail($company->id);
                app(CompanyRoleService::class)->upgradeSalesCatalog($company);
                if (! DocumentSequence::where('company_id', $company->id)->where('document_type', DocumentSequence::TYPE_MONEY_TRANSFER)->exists()) {
                    DocumentSequence::create(['company_id' => $company->id, 'document_type' => DocumentSequence::TYPE_MONEY_TRANSFER,
                        'prefix' => 'TRF', 'year' => Carbon::now($company->timezone)->year, 'padding' => 4, 'next_number' => 1, 'reset_policy' => 'yearly']);
                }
            });
        });
    }
}
