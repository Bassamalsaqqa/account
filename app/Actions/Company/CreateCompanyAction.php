<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyDocumentSettings;
use App\Models\CompanyInventorySettings;
use App\Models\CompanyLanguage;
use App\Models\CompanySecuritySettings;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CreateCompanyAction
{
    public function __construct(
        protected CompanyRoleService $roleService,
        protected AuditService $auditService
    ) {}

    /**
     * Create a new company atomically with all Phase 1 foundations.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $owner, array $data): Company
    {
        $baseCurrency = strtoupper((string) ($data['base_currency_code'] ?? 'ILS'));
        if (! in_array($baseCurrency, ['ILS', 'USD', 'JOD'], true)) {
            throw new InvalidArgumentException("Base currency must be one of ILS, USD, JOD. '{$baseCurrency}' given.");
        }

        $defaultLocale = (string) ($data['default_locale'] ?? 'ar');
        if (! in_array($defaultLocale, ['ar', 'en'], true)) {
            $defaultLocale = 'ar';
        }

        return DB::transaction(function () use ($owner, $data, $baseCurrency, $defaultLocale) {
            return CompanyScope::executeWithoutScope(function () use ($owner, $data, $baseCurrency, $defaultLocale) {
                // 1. Create company record
                $company = Company::create([
                    'public_id' => (string) Str::ulid(),
                    'name_ar' => $data['name_ar'],
                    'name_en' => $data['name_en'] ?? null,
                    'legal_name_ar' => $data['legal_name_ar'] ?? null,
                    'legal_name_en' => $data['legal_name_en'] ?? null,
                    'base_currency_code' => $baseCurrency,
                    'default_locale' => $defaultLocale,
                    'timezone' => $data['timezone'] ?? 'Asia/Hebron',
                    'phone' => $data['phone'] ?? null,
                    'whatsapp' => $data['whatsapp'] ?? null,
                    'email' => $data['email'] ?? null,
                    'website' => $data['website'] ?? null,
                    'address_ar' => $data['address_ar'] ?? null,
                    'address_en' => $data['address_en'] ?? null,
                    'registration_number' => $data['registration_number'] ?? null,
                    'tax_number' => $data['tax_number'] ?? null,
                    'logo_path' => $data['logo_path'] ?? null,
                    'stamp_path' => $data['stamp_path'] ?? null,
                    'status' => 'active',
                ]);

                // 2. Owner membership
                CompanyUser::create([
                    'company_id' => $company->id,
                    'user_id' => $owner->id,
                    'status' => 'active',
                    'is_owner' => true,
                    'joined_at' => now(),
                    'last_accessed_at' => now(),
                ]);

                if (empty($owner->last_active_company_id)) {
                    $owner->update(['last_active_company_id' => $company->id]);
                }

                // 3. Languages (Arabic & English)
                CompanyLanguage::create([
                    'company_id' => $company->id,
                    'locale' => 'ar',
                    'enabled' => true,
                    'is_default' => ($defaultLocale === 'ar'),
                ]);

                CompanyLanguage::create([
                    'company_id' => $company->id,
                    'locale' => 'en',
                    'enabled' => true,
                    'is_default' => ($defaultLocale === 'en'),
                ]);

                // 4. Currencies (ILS, USD, JOD) with exactly one base
                $currencies = ['ILS', 'USD', 'JOD'];
                $order = 1;
                foreach ($currencies as $curr) {
                    $isBase = ($curr === $baseCurrency);
                    CompanyCurrency::create([
                        'company_id' => $company->id,
                        'currency_code' => $curr,
                        'enabled' => true,
                        'is_base' => $isBase,
                        'display_order' => $isBase ? 1 : ++$order,
                    ]);
                }

                // 5. Settings tables
                CompanyInventorySettings::create([
                    'company_id' => $company->id,
                    'allow_negative_stock' => false,
                    'default_cost_method' => 'moving_average',
                    'default_expiry_warning_days' => 30,
                ]);

                CompanyDocumentSettings::create([
                    'company_id' => $company->id,
                    'default_document_locale' => $defaultLocale,
                    'show_logo' => true,
                    'show_qr_by_default' => false,
                    'show_product_images_on_quotes' => false,
                    'invoice_footer_ar' => null,
                    'invoice_footer_en' => null,
                    'quotation_terms_ar' => null,
                    'quotation_terms_en' => null,
                ]);

                CompanySecuritySettings::create([
                    'company_id' => $company->id,
                    'require_2fa_for_owner' => true,
                    'require_2fa_for_admin' => false,
                    'public_share_default_expiry_days' => 30,
                ]);

                // 6. Seed company roles and permissions
                $this->roleService->seedCompanyRoles($company);

                // 7. Assign Owner role
                setPermissionsTeamId($company->id);
                app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
                $ownerRole = Role::where('company_id', $company->id)->where('name', 'Owner')->firstOrFail();
                $owner->assignRole($ownerRole);

                // 8. Log initial audit event
                $this->auditService->log(
                    companyId: $company->id,
                    eventKey: 'company.created',
                    summary: "Company '{$company->displayName()}' created by {$owner->name}",
                    actorUserId: $owner->id,
                    subject: $company,
                    after: [
                        'public_id' => $company->public_id,
                        'name_ar' => $company->name_ar,
                        'name_en' => $company->name_en,
                        'base_currency_code' => $company->base_currency_code,
                        'default_locale' => $company->default_locale,
                        'timezone' => $company->timezone,
                    ]
                );

                return $company;
            });
        });
    }
}
