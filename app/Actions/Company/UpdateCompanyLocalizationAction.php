<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\CompanyLanguage;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateCompanyLocalizationAction
{
    public function __construct(
        protected AuditService $audit
    ) {}

    public function execute(Company $company, string $defaultLocale, string $timezone, User $actor): Company
    {
        if (! in_array($defaultLocale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException("Default locale must be 'ar' or 'en'.");
        }

        return DB::transaction(function () use ($company, $defaultLocale, $timezone, $actor) {
            $before = $company->only(['default_locale', 'timezone']);

            $company->update([
                'default_locale' => $defaultLocale,
                'timezone' => $timezone,
            ]);

            // Ensure exactly one default language matching the company default locale
            CompanyLanguage::where('company_id', $company->id)->update(['is_default' => false]);
            CompanyLanguage::updateOrCreate(
                ['company_id' => $company->id, 'locale' => $defaultLocale],
                ['is_default' => true, 'enabled' => true]
            );

            $this->audit->log(
                companyId: $company->id,
                eventKey: 'company.localization_updated',
                summary: "Localization settings updated by {$actor->name}",
                actorUserId: $actor->id,
                subject: $company,
                before: $before,
                after: [
                    'default_locale' => $defaultLocale,
                    'timezone' => $timezone,
                ]
            );

            return $company;
        });
    }
}
