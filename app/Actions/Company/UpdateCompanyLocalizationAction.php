<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\CompanyDocumentSettings;
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

    public function execute(
        Company $company,
        string $defaultLocale,
        string $timezone,
        bool $englishEnabled,
        User $actor
    ): Company {
        if (! in_array($defaultLocale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException("Default locale must be 'ar' or 'en'.");
        }

        return DB::transaction(function () use ($company, $defaultLocale, $timezone, $englishEnabled, $actor) {
            $before = [
                'default_locale' => $company->default_locale,
                'timezone' => $company->timezone,
                'english_enabled' => $company->isLanguageEnabled('en'),
            ];

            // Invariant: disabling English normalizes default locale to Arabic
            $effectiveDefaultLocale = (! $englishEnabled && $defaultLocale === 'en') ? 'ar' : $defaultLocale;

            $company->update([
                'default_locale' => $effectiveDefaultLocale,
                'timezone' => $timezone,
            ]);

            // Invariant: Arabic is always enabled (ar.enabled = true)
            CompanyLanguage::updateOrCreate(
                ['company_id' => $company->id, 'locale' => 'ar'],
                [
                    'enabled' => true,
                    'is_default' => ($effectiveDefaultLocale === 'ar'),
                ]
            );

            // Invariant: en.enabled reflects selection; exactly one enabled default
            CompanyLanguage::updateOrCreate(
                ['company_id' => $company->id, 'locale' => 'en'],
                [
                    'enabled' => $englishEnabled,
                    'is_default' => ($effectiveDefaultLocale === 'en'),
                ]
            );

            // Invariant: if disabling English, normalize document settings default locale to Arabic
            if (! $englishEnabled) {
                $docSettings = CompanyDocumentSettings::where('company_id', $company->id)->first();
                if ($docSettings && $docSettings->default_document_locale === 'en') {
                    $docSettings->update(['default_document_locale' => 'ar']);
                }
            }

            $this->audit->log(
                companyId: $company->id,
                eventKey: 'company.localization_updated',
                summary: "Localization settings updated by {$actor->name}",
                actorUserId: $actor->id,
                subject: $company,
                before: $before,
                after: [
                    'default_locale' => $effectiveDefaultLocale,
                    'timezone' => $timezone,
                    'english_enabled' => $englishEnabled,
                ]
            );

            return $company;
        });
    }
}
