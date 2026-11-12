<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Currency;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Authoritative resolver for currency minor units.
 *
 * Sources scale directly from master Currency table with tenancy qualification.
 * Supported domain precision is 0..6 decimals matching database storage schema decimal(20, 6).
 */
final class CurrencyMinorUnitsResolver
{
    public const int MIN_SUPPORTED_MINOR_UNITS = 0;

    public const int MAX_SUPPORTED_MINOR_UNITS = 6;

    /** @var array<string, array{minor_units: int, active: bool}> */
    private array $currencyCache = [];

    /** @var array<string, bool> */
    private array $companyEnabledCache = [];

    /**
     * Flush in-memory resolver caches.
     */
    public function flush(): void
    {
        $this->currencyCache = [];
        $this->companyEnabledCache = [];
    }

    /**
     * Resolve authoritative minor units for a currency code.
     *
     * In read-only/historical mode (default), returns the scale without requiring
     * master active status or company enablement, preserving historical reads,
     * audits, returns, and reversals of retired currencies.
     *
     * @param  string  $currencyCode  ISO 4217 currency code
     * @param  Company|int|null  $company  Optional company to qualify enabled tenancy status
     * @param  bool  $requireActiveMaster  Whether master currency must be active
     * @param  bool  $requireCompanyEnabled  Whether currency must be enabled for the company
     * @return int Supported minor units (0..6)
     *
     * @throws InvalidArgumentException
     */
    public function resolve(
        string $currencyCode,
        Company|int|null $company = null,
        bool $requireActiveMaster = false,
        bool $requireCompanyEnabled = false
    ): int {
        $code = strtoupper(trim($currencyCode));
        if ($code === '') {
            throw new InvalidArgumentException('Currency code is required.');
        }

        $record = $this->resolveCurrencyRecord($code);

        if ($requireActiveMaster && ! $record['active']) {
            throw new InvalidArgumentException("Currency [{$code}] is inactive.");
        }

        if ($requireCompanyEnabled && $company !== null) {
            $companyId = $company instanceof Company ? (int) $company->id : (int) $company;
            if (! $this->isCompanyEnabled($companyId, $code)) {
                throw new InvalidArgumentException("Currency [{$code}] is not enabled for company [{$companyId}].");
            }
        }

        return $record['minor_units'];
    }

    /**
     * Resolve authoritative minor units and require that master is active and the company has enabled this currency.
     * Used strictly for NEW transactions (drafts, quotations, pricing, payment posting).
     *
     * @param  Company|int  $company  Company model or ID
     * @param  string  $currencyCode  ISO 4217 currency code
     * @return int Supported minor units (0..6)
     *
     * @throws InvalidArgumentException
     */
    public function resolveForCompany(Company|int $company, string $currencyCode): int
    {
        return $this->resolve($currencyCode, $company, requireActiveMaster: true, requireCompanyEnabled: true);
    }

    /**
     * Authoritative fresh resolution and row-level locking for company write operations.
     *
     * Locks records in deterministic order:
     * 1. Global Currency record SHARED LOCK (allows concurrent company writes, prevents metadata mutation)
     * 2. Tenant CompanyCurrency record FOR UPDATE (exclusive lock scoped to company)
     *
     * Validates master active status, company enablement, and minor units (0..6).
     * Refreshes the in-memory resolver cache so subsequent reads within the same request/transaction
     * reuse the locked authoritative metadata without re-querying, and returns the authoritative scale.
     *
     * @param  Company|int  $company  Company model or ID
     * @param  string  $currencyCode  ISO 4217 currency code
     * @param  bool  $lockForUpdate  Whether to acquire row locks (default true for write transactions)
     * @return int Supported minor units (0..6)
     *
     * @throws InvalidArgumentException
     * @throws \LogicException If lockForUpdate is true outside an active database transaction
     */
    public function resolveFreshForCompany(Company|int $company, string $currencyCode, bool $lockForUpdate = true): int
    {
        $code = strtoupper(trim($currencyCode));
        $map = $this->resolveCurrenciesForCompanyWrite($company, [$code], $lockForUpdate);

        return $map[$code];
    }

    /**
     * Authoritative fresh multi-currency resolution and row-level locking in deterministic alphabetical order.
     *
     * 1. All master Currency records in alphabetical order with SHARED LOCK
     * 2. All tenant CompanyCurrency records in alphabetical order with FOR UPDATE (company-scoped)
     *
     * @param  Company|int  $company  Company model or ID
     * @param  list<string>  $currencyCodes  List of currency codes
     * @param  bool  $lockForUpdate  Whether to acquire row locks (default true for write transactions)
     * @return array<string, int> Map of currency code => minor units
     *
     * @throws InvalidArgumentException
     * @throws \LogicException If lockForUpdate is true outside an active database transaction
     */
    public function resolveCurrenciesForCompanyWrite(Company|int $company, array $currencyCodes, bool $lockForUpdate = true): array
    {
        $companyId = $company instanceof Company ? (int) $company->id : (int) $company;
        if ($companyId <= 0) {
            throw new InvalidArgumentException("Invalid company ID [{$companyId}].");
        }

        if ($lockForUpdate && DB::transactionLevel() <= 0) {
            throw new \LogicException('Currency write resolution with locking requires an active database transaction.');
        }

        $codes = [];
        foreach ($currencyCodes as $rawCode) {
            $code = strtoupper(trim((string) $rawCode));
            if ($code === '') {
                throw new InvalidArgumentException('Currency code is required.');
            }
            $codes[$code] = $code;
        }

        if (empty($codes)) {
            return [];
        }

        // Deterministic lock order: alphabetical by currency code to avoid cross-tenant/cross-currency deadlocks
        $sortedCodes = array_values($codes);
        sort($sortedCodes, SORT_STRING);

        // 1. Lock master Currency records in deterministic alphabetical order with sharedLock
        // sharedLock allows concurrent reads across independent companies while preventing concurrent metadata mutations
        $masterRecords = [];
        foreach ($sortedCodes as $code) {
            $currencyQuery = Currency::where('code', $code);
            if ($lockForUpdate) {
                $currencyQuery = $currencyQuery->sharedLock();
            }
            /** @var Currency|null $currency */
            $currency = $currencyQuery->first();
            if ($currency === null) {
                throw new InvalidArgumentException("Unknown currency [{$code}].");
            }

            if (! (bool) $currency->active) {
                $this->currencyCache[$code] = [
                    'minor_units' => (int) $currency->minor_units,
                    'active' => false,
                ];
                throw new InvalidArgumentException("Currency [{$code}] is inactive.");
            }

            $rawOriginal = $currency->getRawOriginal('minor_units');
            $rawUnits = $rawOriginal ?? $currency->getAttribute('minor_units');
            if (! is_int($rawUnits) && (! is_string($rawUnits) || ! ctype_digit($rawUnits))) {
                throw new InvalidArgumentException("Currency [{$code}] has invalid minor units.");
            }

            $minorUnits = (int) $rawUnits;
            if ($minorUnits < self::MIN_SUPPORTED_MINOR_UNITS || $minorUnits > self::MAX_SUPPORTED_MINOR_UNITS) {
                throw new InvalidArgumentException("Currency [{$code}] minor units [{$minorUnits}] is invalid. Supported range is 0 to 6.");
            }

            $masterRecords[$code] = [
                'minor_units' => $minorUnits,
                'active' => true,
            ];
        }

        // 2. Lock tenant CompanyCurrency records in deterministic alphabetical order with lockForUpdate
        // Exclusive locks are scoped strictly to the tenant company, avoiding cross-company serialization
        $result = [];
        foreach ($sortedCodes as $code) {
            $ccQuery = CompanyCurrency::where('company_id', $companyId)->where('currency_code', $code);
            if ($lockForUpdate) {
                $ccQuery = $ccQuery->lockForUpdate();
            }
            /** @var CompanyCurrency|null $companyCurrency */
            $companyCurrency = $ccQuery->first();
            if ($companyCurrency === null || ! (bool) $companyCurrency->enabled) {
                $this->companyEnabledCache["{$companyId}:{$code}"] = false;
                throw new InvalidArgumentException("Currency [{$code}] is not enabled for company [{$companyId}].");
            }

            // Refresh in-memory resolver caches with fresh locked authoritative state
            $this->currencyCache[$code] = $masterRecords[$code];
            $this->companyEnabledCache["{$companyId}:{$code}"] = true;

            $result[$code] = $masterRecords[$code]['minor_units'];
        }

        return $result;
    }

    /**
     * Resolve authoritative minor units for historical transaction inspection, reversal, or return.
     * Does not require master currency to be active or company enabled.
     *
     * @param  string  $currencyCode  ISO 4217 currency code
     * @return int Supported minor units (0..6)
     *
     * @throws InvalidArgumentException
     */
    public function resolveHistorical(string $currencyCode): int
    {
        return $this->resolve($currencyCode, requireActiveMaster: false, requireCompanyEnabled: false);
    }

    /**
     * @return array{minor_units: int, active: bool}
     */
    private function resolveCurrencyRecord(string $code): array
    {
        if (isset($this->currencyCache[$code])) {
            return $this->currencyCache[$code];
        }

        /** @var Currency|null $currency */
        $currency = Currency::where('code', $code)->first();
        if ($currency === null) {
            throw new InvalidArgumentException("Unknown currency [{$code}].");
        }

        $rawOriginal = $currency->getRawOriginal('minor_units');
        $rawUnits = $rawOriginal ?? $currency->getAttribute('minor_units');
        if (! is_int($rawUnits) && (! is_string($rawUnits) || ! ctype_digit($rawUnits))) {
            throw new InvalidArgumentException("Currency [{$code}] has invalid minor units.");
        }

        $minorUnits = (int) $rawUnits;
        if ($minorUnits < self::MIN_SUPPORTED_MINOR_UNITS || $minorUnits > self::MAX_SUPPORTED_MINOR_UNITS) {
            throw new InvalidArgumentException("Currency [{$code}] minor units [{$minorUnits}] is invalid. Supported range is 0 to 6.");
        }

        $record = [
            'minor_units' => $minorUnits,
            'active' => (bool) $currency->active,
        ];

        $this->currencyCache[$code] = $record;

        return $record;
    }

    private function isCompanyEnabled(int $companyId, string $code): bool
    {
        $cacheKey = "{$companyId}:{$code}";
        if (isset($this->companyEnabledCache[$cacheKey])) {
            return $this->companyEnabledCache[$cacheKey];
        }

        $enabled = CompanyCurrency::where('company_id', $companyId)
            ->where('currency_code', $code)
            ->where('enabled', true)
            ->exists();

        $this->companyEnabledCache[$cacheKey] = $enabled;

        return $enabled;
    }
}
