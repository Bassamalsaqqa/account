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
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Money\CurrencyMinorUnitsResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BusinessSettingsController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/settings/business
     * Comprehensive store profile, authoritative server currency minor units,
     * and warehouse / cash drawer / walk-in mapping.
     */
    public function show(Request $request, string $companyId, CurrencyMinorUnitsResolver $currencyResolver): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        // Read-ready clients need authoritative precision before merchant rollout.
        // Only the merchant projection may load operational mappings and taxes.
        if (! config('mobile.merchant_api_enabled', false)) {
            if (! $user->hasPermissionTo('sales.invoice.create')) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'FORBIDDEN', message: 'Missing required sales.invoice.create permission.', status: 403
                );
            }

            return response()->json([
                'company_id' => (string) $company->public_id,
                'name_ar' => (string) $company->name_ar,
                'name_en' => (string) ($company->name_en ?? $company->name_ar),
                'city_ar' => (string) ($company->address_ar ?? ''),
                'city_en' => (string) ($company->address_en ?? ''),
                'phone' => (string) ($company->phone ?? ''),
                'tax_number' => (string) ($company->tax_number ?? ''),
                'default_currency' => (string) $company->base_currency_code,
                'default_currency_minor_units' => $currencyResolver->resolveForCompany($company, (string) $company->base_currency_code),
            ], 200, ['Cache-Control' => 'no-store, no-cache, must-revalidate, private']);
        }

        $setting = CompanyMobileSetting::where('company_id', $company->id)->first();

        // Warehouse mapping
        $warehouse = null;
        if ($setting?->default_warehouse_id) {
            $wh = Warehouse::where('id', $setting->default_warehouse_id)->where('company_id', $company->id)->first();
            if ($wh !== null) {
                $warehouse = [
                    'id' => (string) $wh->public_id,
                    'code' => (string) $wh->code,
                    'name' => (string) $wh->displayName(),
                ];
            }
        }
        if ($warehouse === null) {
            $wh = Warehouse::where('company_id', $company->id)->where('active', true)->first();
            if ($wh !== null) {
                $warehouse = [
                    'id' => (string) $wh->public_id,
                    'code' => (string) $wh->code,
                    'name' => (string) $wh->displayName(),
                ];
            }
        }

        // Cash account mapping
        $cashAccount = null;
        if ($setting?->approved_cash_account_id) {
            $ca = MoneyAccount::where('id', $setting->approved_cash_account_id)
                ->where('company_id', $company->id)
                ->with('ledgerAccount')
                ->first();
            if ($ca !== null) {
                $cashAccount = [
                    'id' => (string) $ca->public_id,
                    'code' => (string) ($ca->ledgerAccount->code ?? ''),
                    'name' => (string) $ca->displayName(),
                    'currency' => (string) $ca->currency_code,
                    'currency_minor_units' => $currencyResolver->resolve((string) $ca->currency_code, $company),
                ];
            }
        }

        // Walk-in customer mapping
        $walkInCustomer = null;
        if ($setting?->walk_in_customer_id) {
            $wc = Customer::where('id', $setting->walk_in_customer_id)->where('company_id', $company->id)->first();
            if ($wc !== null) {
                $walkInCustomer = [
                    'id' => (string) $wc->public_id,
                    'code' => $wc->code,
                    'name' => (string) $wc->displayName(),
                ];
            }
        }

        // Active company currencies with verified minor units
        $companyCurrencies = CompanyCurrency::where('company_id', $company->id)
            ->where('enabled', true)
            ->pluck('currency_code')
            ->all();

        if (empty($companyCurrencies)) {
            $companyCurrencies = [$company->base_currency_code];
        }

        $currencies = Currency::where('active', true)
            ->whereIn('code', $companyCurrencies)
            ->get()
            ->map(fn (Currency $c) => [
                'code' => (string) $c->code,
                'name' => (string) $c->name,
                'symbol' => (string) ($c->symbol ?? $c->code),
                'minor_units' => $currencyResolver->resolve((string) $c->code, $company),
                'is_base' => (string) $c->code === (string) $company->base_currency_code,
            ])
            ->values()
            ->all();

        // Taxes
        $defaultTaxPercent = null;
        if ($setting?->default_tax_rate_id) {
            $dt = TaxRate::where('id', $setting->default_tax_rate_id)->where('company_id', $company->id)->first();
            if ($dt !== null) {
                $defaultTaxPercent = (string) BigDecimal::of((string) $dt->rate)->toScale(6, RoundingMode::UNNECESSARY);
            }
        }

        $taxes = TaxRate::where('company_id', $company->id)
            ->where('active', true)
            ->get()
            ->map(fn (TaxRate $t) => [
                'id' => (string) $t->public_id,
                'code' => (string) $t->code,
                'name' => (string) $t->displayName(),
                'rate' => (string) BigDecimal::of((string) $t->rate)->toScale(6, RoundingMode::UNNECESSARY),
                'calculation' => $t->calculation === TaxRate::CALC_INCLUSIVE ? 'inclusive' : 'exclusive',
                'is_default' => $setting?->default_tax_rate_id !== null && (int) $setting->default_tax_rate_id === (int) $t->id,
            ])
            ->values()
            ->all();

        $baseCmu = $currencyResolver->resolveForCompany($company, (string) $company->base_currency_code);

        return response()->json([
            'company_id' => (string) $company->public_id,
            'name_ar' => (string) $company->name_ar,
            'name_en' => (string) ($company->name_en ?? $company->name_ar),
            'city_ar' => (string) ($company->address_ar ?? ''),
            'city_en' => (string) ($company->address_en ?? ''),
            'phone' => (string) ($company->phone ?? ''),
            'tax_number' => (string) ($company->tax_number ?? ''),
            'default_currency' => (string) $company->base_currency_code,
            'default_currency_minor_units' => $baseCmu,
            'timezone' => (string) ($company->timezone ?? config('app.timezone', 'UTC')),
            'default_tax_percent' => $defaultTaxPercent,
            'default_warehouse' => $warehouse,
            'approved_cash_account' => $cashAccount,
            'walk_in_customer' => $walkInCustomer,
            'currencies' => $currencies,
            'taxes' => $taxes,
            'laravel_sync_status' => 'جاهز للمزامنة مع نظام الحسابات (Laravel API v1/v2)',
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
