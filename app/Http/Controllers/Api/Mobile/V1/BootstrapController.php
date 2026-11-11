<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\CompanyMobileSetting;
use App\Models\Customer;
use App\Models\MoneyAccount;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
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
        $company = $request->attributes->get('mobile_company') ?? app(CompanyContext::class)->company();
        /** @var User $user */
        $user = $request->attributes->get('mobile_user') ?? auth()->user();

        /** @var CompanyMobileSetting|null $mobileSetting */
        $mobileSetting = CompanyMobileSetting::where('company_id', $company->id)->first();

        // 1. Resolve Walk-In Customer
        $walkInCustomer = null;
        if ($mobileSetting?->walk_in_customer_id) {
            $walkInCustomer = Customer::where('company_id', $company->id)
                ->where('id', $mobileSetting->walk_in_customer_id)
                ->where('status', 'active')
                ->first();
        }
        if ($walkInCustomer === null) {
            $walkInCustomer = Customer::where('company_id', $company->id)
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->where('code', 'CUST-WALK-IN')
                        ->orWhere('code', 'WALK-IN');
                })
                ->first();
        }

        if ($walkInCustomer === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'BOOTSTRAP_CONFIG_INCOMPLETE',
                message: 'Company walk-in customer is not configured or active.',
                status: 409,
                details: ['missing' => 'walk_in_customer']
            );
        }

        // 2. Resolve Default Warehouse
        $warehouse = null;
        if ($mobileSetting?->default_warehouse_id) {
            $warehouse = Warehouse::where('company_id', $company->id)
                ->where('id', $mobileSetting->default_warehouse_id)
                ->where('active', true)
                ->first();
        }
        if ($warehouse === null) {
            $invSettings = CompanyInventorySettings::where('company_id', $company->id)->first();
            if ($invSettings?->default_warehouse_id) {
                $warehouse = Warehouse::where('company_id', $company->id)
                    ->where('id', $invSettings->default_warehouse_id)
                    ->where('active', true)
                    ->first();
            }
        }
        if ($warehouse === null) {
            $warehouse = Warehouse::where('company_id', $company->id)
                ->where('active', true)
                ->where('is_default', true)
                ->first()
                ?? Warehouse::where('company_id', $company->id)->where('active', true)->first();
        }

        if ($warehouse === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'BOOTSTRAP_CONFIG_INCOMPLETE',
                message: 'Company default warehouse is not configured or active.',
                status: 409,
                details: ['missing' => 'default_warehouse']
            );
        }

        // 3. Resolve Approved Cash Account
        $cashAccount = null;
        if ($mobileSetting?->approved_cash_account_id) {
            $cashAccount = MoneyAccount::where('company_id', $company->id)
                ->where('id', $mobileSetting->approved_cash_account_id)
                ->where('account_type', MoneyAccount::TYPE_CASH)
                ->where('is_active', true)
                ->first();
        }
        if ($cashAccount === null) {
            $cashAccount = MoneyAccount::where('company_id', $company->id)
                ->where('account_type', MoneyAccount::TYPE_CASH)
                ->where('currency_code', $company->base_currency_code)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();
        }

        if ($cashAccount === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'BOOTSTRAP_CONFIG_INCOMPLETE',
                message: 'Company approved cash account in base currency is not configured or active.',
                status: 409,
                details: ['missing' => 'approved_cash_account']
            );
        }

        // 4. Resolve Units
        $units = Unit::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('code')
            ->get();

        if ($units->isEmpty()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'BOOTSTRAP_CONFIG_INCOMPLETE',
                message: 'Company units catalogue is empty.',
                status: 409,
                details: ['missing' => 'units']
            );
        }

        $unitsPayload = $units->map(fn (Unit $u) => [
            'id' => $u->public_id,
            'code' => $u->code,
            'name' => $u->name(),
            'symbol' => $u->symbol(),
            'allows_fraction' => (bool) $u->allows_fraction,
            'decimal_places' => (int) $u->decimal_places,
        ])->values()->all();

        // 5. Resolve Taxes
        $taxes = TaxRate::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('rate', 'desc')
            ->get();

        if ($taxes->isEmpty()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'BOOTSTRAP_CONFIG_INCOMPLETE',
                message: 'Company authoritative tax rates are empty.',
                status: 409,
                details: ['missing' => 'taxes']
            );
        }

        $defaultTaxRateId = $mobileSetting !== null && $mobileSetting->default_tax_rate_id !== null
            ? $mobileSetting->default_tax_rate_id
            : $taxes->first()->id;

        $taxesPayload = $taxes->map(fn (TaxRate $t) => [
            'id' => $t->public_id,
            'code' => $t->code,
            'name' => $t->displayName(),
            'rate' => sprintf('%.6f', (float) $t->rate),
            'calculation' => $t->calculation === TaxRate::CALC_INCLUSIVE ? 'inclusive' : 'exclusive',
            'is_default' => (int) $t->id === (int) $defaultTaxRateId,
        ])->values()->all();

        // Ensure at least one tax rate is default
        if (! collect($taxesPayload)->contains('is_default', true) && count($taxesPayload) > 0) {
            $taxesPayload[0]['is_default'] = true;
        }

        // 6. Map Staff Profile & Permissions
        setPermissionsTeamId($company->id);
        $roleName = $user->roles()->where('roles.company_id', $company->id)->value('name');
        $role = match (strtolower((string) $roleName)) {
            'owner', 'admin' => 'admin',
            'manager' => 'manager',
            default => 'cashier',
        };

        $permissions = $user->getAllPermissions()->pluck('name')->values()->all();

        // 7. Offline Policy
        $offlinePolicy = [
            'enabled' => $mobileSetting !== null ? (bool) $mobileSetting->offline_policy_enabled : false,
            'cash_only' => $mobileSetting !== null ? (bool) $mobileSetting->offline_cash_only : true,
            'max_offline_hours' => $mobileSetting !== null ? (int) $mobileSetting->max_offline_hours : 12,
            'max_offline_sales_count' => $mobileSetting !== null ? (int) $mobileSetting->max_offline_sales_count : 50,
            'max_offline_total_amount' => $mobileSetting !== null ? sprintf('%.6f', (float) $mobileSetting->max_offline_total_amount) : '5000.000000',
        ];

        // 8. Catalog Snapshot Version
        $catalogSnapshotVersion = $mobileSetting !== null && ! empty($mobileSetting->catalog_snapshot_version)
            ? $mobileSetting->catalog_snapshot_version
            : 'snap-20261110-v1';

        // 9. Approved Cash Account Payload
        $cashCode = $cashAccount->ledgerAccount?->code;
        if (empty($cashCode)) {
            $cashCode = 'CASH-'.substr($cashAccount->public_id, -6);
        }

        return response()->json([
            'company_id' => $company->public_id,
            'company_name' => $company->displayName(),
            'base_currency' => $company->base_currency_code,
            'walk_in_customer_id' => $walkInCustomer->public_id,
            'staff' => [
                'id' => $user->public_id,
                'name' => $user->name,
                'role' => $role,
                'permissions' => $permissions,
            ],
            'device_lease' => null, // Offline policy disabled, so device_lease is strictly null
            'default_warehouse' => [
                'id' => $warehouse->public_id,
                'code' => $warehouse->code,
                'name' => $warehouse->displayName(),
            ],
            'approved_cash_account' => [
                'id' => $cashAccount->public_id,
                'code' => $cashCode,
                'name' => $cashAccount->displayName(),
                'currency' => $cashAccount->currency_code,
            ],
            'units' => $unitsPayload,
            'taxes' => $taxesPayload,
            'offline_policy' => $offlinePolicy,
            'catalog_snapshot_version' => $catalogSnapshotVersion,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
