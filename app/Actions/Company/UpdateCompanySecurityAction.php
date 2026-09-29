<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\CompanySecuritySettings;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

class UpdateCompanySecurityAction
{
    public function __construct(
        protected AuditService $audit
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Company $company, array $validated, User $actor): CompanySecuritySettings
    {
        return DB::transaction(function () use ($company, $validated, $actor) {
            $security = $company->securitySettings;
            $before = $security ? $security->only(array_keys($validated)) : null;

            /** @var CompanySecuritySettings $updated */
            $updated = CompanySecuritySettings::updateOrCreate(
                ['company_id' => $company->id],
                $validated
            );

            $this->audit->log(
                companyId: $company->id,
                eventKey: 'company.security_updated',
                summary: "Company security policies updated by {$actor->name}",
                actorUserId: $actor->id,
                subject: $company,
                before: $before,
                after: $validated
            );

            return $updated;
        });
    }
}
