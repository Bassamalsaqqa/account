<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Currency;
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
