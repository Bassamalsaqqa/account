<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyMobileSetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\MoneyAccount;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Mobile\CatalogSnapshot;
use App\Support\Mobile\WireResponse;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BootstrapController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/bootstrap
     */
    public function show(Request $request, string $companyId): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        return CatalogSnapshot::withConsistentRead(function () use ($company, $user): JsonResponse {
            // Determine configured company mobile settings
            /** @var CompanyMobileSetting|null $setting */
            $setting = CompanyMobileSetting::where('company_id', $company->id)->first();
            if ($setting === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
                    message: 'Company mobile settings are not configured.',
                    status: 409
                );
            }

            // 1. Walk-in customer: must be configured, exist in same company, and active
            if ($setting->walk_in_customer_id === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
                    message: 'Walk-in customer is not configured.',
                    status: 409
                );
            }
            /** @var Customer|null $walkInCustomer */
            $walkInCustomer = Customer::where('id', $setting->walk_in_customer_id)
                ->where('company_id', $company->id)
                ->first();
            if ($walkInCustomer === null || $walkInCustomer->status !== 'active') {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
                    message: 'Configured walk-in customer is missing, inactive, or belongs to another company.',
                    status: 409
                );
            }

            // 2. Approved cash account: must be configured, exist in same company, active, TYPE_CASH, and matching base currency
            if ($setting->approved_cash_account_id === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
                    message: 'Approved cash account is not configured.',
                    status: 409
                );
            }
            /** @var MoneyAccount|null $cashAccount */
            $cashAccount = MoneyAccount::where('id', $setting->approved_cash_account_id)
                ->where('company_id', $company->id)
                ->with('ledgerAccount')
                ->first();

            if ($cashAccount === null || ! $cashAccount->is_active || $cashAccount->account_type !== MoneyAccount::TYPE_CASH || $cashAccount->currency_code !== $company->base_currency_code) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
                    message: 'Configured cash account is missing, inactive, non-cash, currency-mismatched, or belongs to another company.',
                    status: 409
                );
            }

            // Ledger account: must exist, belong to same company, be active, and have valid non-empty code
            $ledger = $cashAccount->ledgerAccount;
            if ($ledger === null || (int) $ledger->company_id !== (int) $company->id || ! $ledger->active || empty(trim((string) $ledger->code))) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
                    message: 'Approved cash account must have an active owned eligible ledger account.',
                    status: 409
                );
            }
            $cashCode = trim((string) $ledger->code);

            // 3. Default warehouse: must be configured, exist in same company, and active
            if ($setting->default_warehouse_id === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
                    message: 'Default warehouse is not configured.',
                    status: 409
                );
            }
            /** @var Warehouse|null $warehouse */
            $warehouse = Warehouse::where('id', $setting->default_warehouse_id)
                ->where('company_id', $company->id)
                ->first();
            if ($warehouse === null || ! $warehouse->active) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
                    message: 'Configured default warehouse is missing, inactive, or belongs to another company.',
                    status: 409
                );
            }

            // 4. Base currency validation: exact format, active global currency, and enabled company currency
            $baseCurrencyCode = (string) $company->base_currency_code;
            if (! preg_match('/^[A-Z]{3}$/', $baseCurrencyCode)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
                    message: 'Company base currency code format is invalid.',
                    status: 409
                );
            }
            $isGlobalActive = Currency::where('code', $baseCurrencyCode)->where('active', true)->exists();
            $isCompanyDisabled = CompanyCurrency::where('company_id', $company->id)
                ->where('currency_code', $baseCurrencyCode)
                ->where('enabled', false)
                ->exists();
            $hasCompanyCurrencies = CompanyCurrency::where('company_id', $company->id)->exists();
            $isCompanyEnabled = ! $hasCompanyCurrencies || CompanyCurrency::where('company_id', $company->id)
                ->where('currency_code', $baseCurrencyCode)
                ->where('enabled', true)
                ->exists();
            if (! $isGlobalActive || $isCompanyDisabled || ! $isCompanyEnabled) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
                    message: 'Company base currency is not an active global currency and enabled company currency.',
                    status: 409
                );
            }

            // 5. Staff role and permissions
            $roleName = $user->roles()->where('roles.company_id', $company->id)->value('name');
            $role = match (strtolower((string) $roleName)) {
                'owner', 'admin', 'administrator' => 'admin',
                'manager' => 'manager',
                default => 'cashier',
            };
            $permissions = $user->getAllPermissions()->pluck('name')->values()->all();

            // 6. Units: active units in same company (empty allowed, no truncation or clamping)
            $units = Unit::where('company_id', $company->id)
                ->where('active', true)
                ->orderBy('id')
                ->get()
                ->map(function (Unit $u): array {
                    $symbol = $u->symbol_en ?? $u->symbol_ar;

                    return [
                        'id' => $u->public_id,
                        'code' => (string) $u->code,
                        'name' => (string) $u->displayName(),
                        'symbol' => $symbol !== null && trim((string) $symbol) !== '' ? trim((string) $symbol) : null,
                        'allows_fraction' => (bool) $u->allows_fraction,
                        'decimal_places' => (int) $u->decimal_places,
                    ];
                })
                ->values()
                ->all();

            // 7. Taxes: configured default tax rate must be valid same-company active if specified
            if ($setting->default_tax_rate_id !== null) {
                $defaultTaxRate = TaxRate::where('id', $setting->default_tax_rate_id)
                    ->where('company_id', $company->id)
                    ->first();
                if ($defaultTaxRate === null || ! $defaultTaxRate->active) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
                        message: 'Configured default tax rate is missing, inactive, or belongs to another company.',
                        status: 409
                    );
                }
            }

            $taxes = TaxRate::where('company_id', $company->id)
                ->where('active', true)
                ->orderBy('id')
                ->get()
                ->map(function (TaxRate $t) use ($setting): array {
                    $isDefault = $setting->default_tax_rate_id !== null && (int) $setting->default_tax_rate_id === (int) $t->id;

                    return [
                        'id' => $t->public_id,
                        'code' => (string) $t->code,
                        'name' => (string) $t->displayName(),
                        'rate' => (string) BigDecimal::of((string) $t->rate)->toScale(6, RoundingMode::UNNECESSARY),
                        'calculation' => $t->calculation === TaxRate::CALC_INCLUSIVE ? 'inclusive' : 'exclusive',
                        'is_default' => $isDefault,
                    ];
                })
                ->values()
                ->all();

            $response = response()->json([
                'company_id' => $company->public_id,
                'company_name' => (string) $company->displayName(),
                'base_currency' => $company->base_currency_code,
                'walk_in_customer_id' => $walkInCustomer->public_id,
                'staff' => [
                    'id' => $user->public_id,
                    'name' => (string) $user->name,
                    'role' => $role,
                    'permissions' => $permissions,
                ],
                'device_lease' => null,
                'default_warehouse' => [
                    'id' => $warehouse->public_id,
                    'code' => (string) $warehouse->code,
                    'name' => (string) $warehouse->displayName(),
                ],
                'approved_cash_account' => [
                    'id' => $cashAccount->public_id,
                    'code' => (string) $cashCode,
                    'name' => (string) $cashAccount->displayName(),
                    'currency' => $cashAccount->currency_code,
                ],
                'units' => $units,
                'taxes' => $taxes,
                'offline_policy' => [
                    'enabled' => false,
                    'availability' => 'unavailable',
                ],
                'catalog_snapshot_version' => CatalogSnapshot::computeVersion($company),
            ], 200, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Pragma' => 'no-cache',
            ]);

            if (! WireResponse::accepts(json_decode((string) $response->getContent()), 'bootstrap-response')) {
                throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                    code: 'CONTRACT_INCOMPATIBLE',
                    message: 'Existing data cannot be represented by Mobile V1.',
                    status: 409
                ));
            }

            return $response;
        });
    }
}
