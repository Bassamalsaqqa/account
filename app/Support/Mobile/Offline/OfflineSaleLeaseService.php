<?php

declare(strict_types=1);

namespace App\Support\Mobile\Offline;

use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Support\Mobile\CatalogSnapshot;
use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Receipt\Exceptions\FinancialExecutionDeniedException;
use App\Support\Mobile\Receipt\NativeWriteContextResolver;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class OfflineSaleLeaseService
{
    public function __construct(
        private readonly NativeWriteContextResolver $contextResolver,
        private readonly CurrencyMinorUnitsResolver $currencyResolver,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('mobile.v2_offline_sales_enabled', false)
            && (bool) config('mobile.v2_cash_slice_enabled', false)
            && (bool) config('mobile.v2_enabled', false)
            && (bool) config('mobile.merchant_api_enabled', false)
            && (bool) config('mobile.enabled', false);
    }

    /** Called by configuration writers while holding the company and mobile settings locks. */
    public static function retireCompanyLeases(CompanyMobileSetting $setting): void
    {
        DB::table('mobile_offline_sale_leases')
            ->where('company_id', $setting->company_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => DB::raw('UTC_TIMESTAMP()')]);
    }

    /**
     * Invoked by the receipt manager through its private authority scope.
     *
     * @return array<string, mixed>
     *
     * @phpstan-ignore method.unused (called through Closure::bind)
     */
    private function issue(Company $company, User $actor, MobileDevice $device, MobileAccessToken $token, string $snapshot): array
    {
        $policy = $this->lockedPolicy($company);
        $authority = $this->authorityFingerprint($token);
        $policyFingerprint = hash('sha256', CanonicalJsonSerializer::serialize($policy));

        $now = now('UTC')->startOfSecond();
        $scope = $this->scope($company, $actor, $device);
        $lease = $scope->whereNull('revoked_at')
            ->where('authority_fingerprint', $authority)
            ->where('expires_at', '>', $now->toDateTimeString())->lockForUpdate()->first();
        if ($lease !== null) {
            if ($lease->catalog_snapshot_version !== $snapshot
                || $lease->policy_fingerprint !== $policyFingerprint) {
                throw new OfflineSaleConflict('LEASE_ALREADY_ACTIVE', 'Resolve the existing offline lease before requesting another.');
            }
        } else {
            if (! hash_equals(CatalogSnapshot::computeVersion($company), $snapshot)) {
                throw new OfflineSaleConflict('STALE_CATALOG', 'Refresh the catalog before requesting an offline lease.');
            }
            $expires = $now->copy()->addHours($policy['max_offline_hours']);
            if ($expires->year > 9999) {
                throw new OfflineSaleConflict('OFFLINE_POLICY_UNCONFIGURED', 'Offline lease duration exceeds the supported calendar range.');
            }
            $lease = (object) [
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'mobile_device_id' => $device->id,
                'authority_fingerprint' => $authority,
                'policy_fingerprint' => $policyFingerprint,
                'catalog_snapshot_version' => $snapshot,
                'issued_at' => $now->toDateTimeString(),
                'expires_at' => $expires->toDateTimeString(),
                'posted_sales_count' => 0,
                'posted_total_amount' => '0.000000',
            ];
            DB::table('mobile_offline_sale_leases')->insert((array) $lease);
        }

        return [
            'lease_id' => $lease->id,
            'company_id' => $company->public_id,
            'actor_id' => $actor->public_id,
            'device_id' => $device->device_id,
            'catalog_snapshot_version' => $snapshot,
            'issued_at' => Carbon::parse($lease->issued_at, 'UTC')->toIso8601ZuluString(),
            'expires_at' => Carbon::parse($lease->expires_at, 'UTC')->toIso8601ZuluString(),
            'posted_sales_count' => (int) $lease->posted_sales_count,
            'posted_total_amount' => (string) $lease->posted_total_amount,
            ...$policy,
        ];
    }

    /**
     * Charge the lease inside the same receipt transaction as all canonical posting effects.
     * A posting error rolls this charge back.
     *
     * @phpstan-ignore method.unused (called through Closure::bind)
     */
    private function admit(Company $company, User $actor, MobileDevice $device, MobileAccessToken $token, OfflineSaleEvent $sale): int
    {
        $event = $sale->event;
        $policy = $this->lockedPolicy($company);
        $scope = $this->scope($company, $actor, $device)->where('id', $event['device_lease_id']);
        $lease = (clone $scope)->lockForUpdate()->first();
        if ($lease === null || $lease->id !== $event['device_lease_id']
            || $event['company_id'] !== $company->public_id || $event['actor_id'] !== $actor->public_id
            || $event['device_id'] !== $device->device_id
            || $lease->authority_fingerprint !== $this->authorityFingerprint($token)) {
            throw new OfflineSaleConflict('LEASE_INVALID', 'The saved sale has no valid offline lease for this staff member and device.');
        }
        if ($lease->revoked_at !== null) {
            throw new OfflineSaleConflict('LEASE_POLICY_CHANGED', 'The offline sale policy or cash setup has changed.');
        }

        $now = now('UTC');
        $issued = Carbon::parse($lease->issued_at, 'UTC');
        $expires = Carbon::parse($lease->expires_at, 'UTC');
        $captured = OfflineSaleEvent::captureTime($event['captured_at']);
        if ($now >= $expires || $captured < $issued || $captured > $now) {
            throw new OfflineSaleConflict('LEASE_WINDOW_MISMATCH', 'The sale must be captured and first submitted within its lease window.');
        }

        if ($lease->catalog_snapshot_version !== $event['catalog_snapshot_version']) {
            throw new OfflineSaleConflict('STALE_CATALOG', 'The saved sale does not match its leased catalog.');
        }
        if ($lease->policy_fingerprint !== hash('sha256', CanonicalJsonSerializer::serialize($policy))
            || $event['currency'] !== $policy['currency']
            || $event['cash_account_id'] !== $policy['cash_account_id']
            || $event['warehouse_id'] !== $policy['warehouse_id']) {
            throw new OfflineSaleConflict('LEASE_POLICY_CHANGED', 'The offline sale policy or cash setup has changed.');
        }

        foreach ($event['lines'] as $line) {
            if ($line['tax_rate_id'] !== $policy['tax_rate_id']) {
                throw new OfflineSaleConflict('STALE_TAX', 'Offline sales must use the tax choice configured for the lease.');
            }
        }

        $sale->cashProfile($policy['currency_minor_units']);
        $count = (int) $lease->posted_sales_count;
        $total = BigDecimal::of((string) $lease->posted_total_amount)->plus($event['payment']['amount_settled']);
        if ($count >= $policy['max_offline_sales_count'] || $total->isGreaterThan($policy['max_offline_total_amount'])) {
            throw new OfflineSaleConflict('LEASE_LIMIT_REACHED', 'The offline sales count or value limit would be exceeded.');
        }

        $scope->update(['posted_sales_count' => $count + 1, 'posted_total_amount' => (string) $total]);

        return $policy['currency_minor_units'];
    }

    /**
     * @return array{currency: string, currency_minor_units: int, warehouse_id: string, cash_account_id: string, walk_in_customer_id: string, tax_rate_id: string|null, tax_rate: string|null, tax_calculation: string|null, max_offline_hours: int, max_offline_sales_count: int, max_offline_total_amount: string}
     */
    private function lockedPolicy(Company $company): array
    {
        if (DB::transactionLevel() !== 1) {
            throw new LogicException('Offline leases require the root receipt authority transaction.');
        }
        if (! self::enabled()) {
            throw new FinancialExecutionDeniedException;
        }

        $setting = CompanyMobileSetting::query()->whereKey($company->id)->lockForUpdate()->first();
        if ($setting === null || ! $setting->offline_policy_enabled || ! $setting->offline_cash_only) {
            throw new FinancialExecutionDeniedException;
        }

        $hours = $setting->max_offline_hours;
        $count = $setting->max_offline_sales_count;
        $amount = $setting->max_offline_total_amount;
        if ($hours === null || $hours <= 0 || $count === null || $count <= 0
            || $amount === null || ! BigDecimal::of($amount)->isPositive()) {
            throw new OfflineSaleConflict('OFFLINE_POLICY_UNCONFIGURED', 'Offline sales require explicit duration, count, and total limits.');
        }

        $currency = (string) $company->base_currency_code;
        try {
            $minorUnits = $this->currencyResolver->resolveFreshForCompany($company, $currency);
            $cash = $this->contextResolver->resolveCashAccount($company);
            $warehouse = $this->contextResolver->resolveWarehouse($company);
        } catch (InvalidArgumentException) {
            throw new OfflineSaleConflict('OFFLINE_POLICY_UNCONFIGURED', 'Offline sales require an active currency, approved cash account, and warehouse.');
        }

        $customer = Customer::query()->where('company_id', $company->id)->whereKey($setting->walk_in_customer_id)->lockForUpdate()->first();
        $tax = TaxRate::query()->where('company_id', $company->id)->whereKey($setting->default_tax_rate_id)->lockForUpdate()->first();
        if ($customer === null || ! $customer->active
            || ($setting->default_tax_rate_id !== null && ($tax === null || ! $tax->active))) {
            throw new OfflineSaleConflict('OFFLINE_POLICY_UNCONFIGURED', 'Offline sales require an active walk-in customer and a valid tax setup.');
        }

        return [
            'currency' => $currency,
            'currency_minor_units' => $minorUnits,
            'warehouse_id' => (string) $warehouse->public_id,
            'cash_account_id' => (string) $cash->public_id,
            'walk_in_customer_id' => (string) $customer->public_id,
            'tax_rate_id' => $tax?->public_id,
            'tax_rate' => $tax?->rate,
            'tax_calculation' => $tax?->calculation,
            'max_offline_hours' => $hours,
            'max_offline_sales_count' => $count,
            'max_offline_total_amount' => $amount,
        ];
    }

    private function authorityFingerprint(MobileAccessToken $token): string
    {
        return hash('sha256', $token->password_epoch);
    }

    private function scope(Company $company, User $actor, MobileDevice $device): Builder
    {
        return DB::table('mobile_offline_sale_leases')
            ->where('company_id', $company->id)
            ->where('actor_id', $actor->id)
            ->where('mobile_device_id', $device->id);
    }
}
