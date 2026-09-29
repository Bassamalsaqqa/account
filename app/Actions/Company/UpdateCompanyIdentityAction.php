<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

class UpdateCompanyIdentityAction
{
    public function __construct(
        protected AuditService $audit
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Company $company, array $validated, User $actor): Company
    {
        return DB::transaction(function () use ($company, $validated, $actor) {
            $before = $company->only(array_keys($validated));
            $company->update($validated);

            $this->audit->log(
                companyId: $company->id,
                eventKey: 'company.identity_updated',
                summary: "Company identity updated by {$actor->name}",
                actorUserId: $actor->id,
                subject: $company,
                before: $before,
                after: $validated
            );

            return $company;
        });
    }
}
