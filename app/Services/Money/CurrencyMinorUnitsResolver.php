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
 * Sources scale directly from master Currency table with optional company enablement qualification.
 * Supported domain precision is 0..6 decimals matching database storage schema decimal(20, 6).
 */
final class CurrencyMinorUnitsResolver
{
    public const int MIN_SUPPORTED_MINOR_UNITS = 0;

    public const int MAX_SUPPORTED_MINOR_UNITS = 6;

    /**
     * Resolve authoritative minor units for a currency code.
     *
     * @param  string  $currencyCode  ISO 4217 currency code
     * @param  Company|int|null  $company  Optional company to qualify enabled tenancy status
     * @param  bool  $requireActiveMaster  Whether master currency must be active
     * @return int Supported minor units (0..6)
     *
     * @throws InvalidArgumentException
     */
    public function resolve(string $currencyCode, Company|int|null $company = null, bool $requireActiveMaster = true): int
    {
        $code = strtoupper(trim($currencyCode));
        if ($code === '') {
            throw new InvalidArgumentException('Currency code is required.');
        }

        /** @var Currency|null $currency */
        $currency = Currency::where('code', $code)->first();
        if ($currency === null) {
            throw new InvalidArgumentException("Unknown currency [{$code}].");
        }

        if ($requireActiveMaster && ! $currency->active) {
            throw new InvalidArgumentException("Currency [{$code}] is inactive.");
        }

        $rawUnits = $currency->getAttribute('minor_units');
        if (! is_int($rawUnits) && ! is_numeric($rawUnits)) {
            throw new InvalidArgumentException("Currency [{$code}] has invalid minor units.");
        }
        $minorUnits = (int) $rawUnits;
        if ($minorUnits < self::MIN_SUPPORTED_MINOR_UNITS || $minorUnits > self::MAX_SUPPORTED_MINOR_UNITS) {
            throw new InvalidArgumentException("Currency [{$code}] minor units [{$minorUnits}] is invalid. Supported range is 0 to 6.");
        }

        if ($company !== null) {
            $companyId = $company instanceof Company ? (int) $company->id : (int) $company;
            $enabled = CompanyCurrency::where('company_id', $companyId)
                ->where('currency_code', $code)
                ->where('enabled', true)
                ->exists();

            if (! $enabled) {
                throw new InvalidArgumentException("Currency [{$code}] is not enabled for company [{$companyId}].");
            }
        }

        return $minorUnits;
    }

    /**
     * Resolve authoritative minor units and require that the company has enabled this currency.
     *
     * @param  Company|int  $company  Company model or ID
     * @param  string  $currencyCode  ISO 4217 currency code
     * @return int Supported minor units (0..6)
     *
     * @throws InvalidArgumentException
     */
    public function resolveForCompany(Company|int $company, string $currencyCode): int
    {
        return $this->resolve($currencyCode, $company, requireActiveMaster: true);
    }
}
