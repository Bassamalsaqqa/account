<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Domain\Money\Exceptions\UnresolvedExchangeRateException;
use App\Domain\Money\ValueObjects\ExchangeRate as ExchangeRateValueObject;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use DateTimeInterface;
use InvalidArgumentException;

class ExchangeRateService
{
    /**
     * Record a manual suggested exchange rate for a foreign currency relative to company base.
     */
    public function recordRate(
        Company $company,
        string $currencyCode,
        string $rate,
        ?DateTimeInterface $effectiveAt = null,
        ?User $createdBy = null,
        string $source = 'manual'
    ): ExchangeRate {
        $context = app(CompanyContext::class);

        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('Cannot record exchange rate without an active company context.');
        }

        if ($context->companyId() !== $company->id) {
            throw new CompanyReassignmentException("Cannot record exchange rate for company [{$company->id}] when active company is [{$context->companyId()}].");
        }

        $actingUser = $createdBy ?? $context->user();

        $currencyCode = strtoupper(trim($currencyCode));
        $baseCurrency = strtoupper($company->base_currency_code);

        if ($currencyCode === $baseCurrency) {
            throw new InvalidArgumentException("Cannot record exchange rate for company base currency [{$currencyCode}]. Base rate is always 1.");
        }

        // Validate rate is a strictly positive decimal
        $rateVo = ExchangeRateValueObject::from($rate);

        // Verify currency is enabled for this company
        $isEnabled = CompanyCurrency::where('company_id', $company->id)
            ->where('currency_code', $currencyCode)
            ->where('enabled', true)
            ->exists();

        if (! $isEnabled) {
            throw new InvalidArgumentException("Currency [{$currencyCode}] is not enabled for company [{$company->id}].");
        }

        return ExchangeRate::create([
            'company_id' => $company->id,
            'base_currency_code' => $baseCurrency,
            'currency_code' => $currencyCode,
            'rate' => $rateVo->toDecimalString(),
            'effective_at' => $effectiveAt ?? now(),
            'source' => $source,
            'created_by' => $actingUser?->id,
        ]);
    }

    /**
     * Resolve the historical exchange rate effective at a given timestamp.
     * If currency is base currency, returns exactly 1.
     */
    public function resolveRate(Company $company, string $currencyCode, ?DateTimeInterface $timestamp = null): ExchangeRateValueObject
    {
        $context = app(CompanyContext::class);

        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('Cannot resolve exchange rate without an active company context.');
        }

        if ($context->companyId() !== $company->id) {
            throw new CompanyReassignmentException("Cannot resolve exchange rate for company [{$company->id}] when active company is [{$context->companyId()}].");
        }

        $currencyCode = strtoupper(trim($currencyCode));
        $baseCurrency = strtoupper($company->base_currency_code);

        if ($currencyCode === $baseCurrency) {
            return ExchangeRateValueObject::one();
        }

        $query = ExchangeRate::where('company_id', $company->id)
            ->where('currency_code', $currencyCode)
            ->where('base_currency_code', $baseCurrency);

        if ($timestamp !== null) {
            $query->where('effective_at', '<=', $timestamp);
        }

        /** @var ExchangeRate|null $latest */
        $latest = $query->orderBy('effective_at', 'desc')->orderBy('id', 'desc')->first();

        if ($latest === null) {
            throw UnresolvedExchangeRateException::forCurrency($currencyCode, $baseCurrency, $timestamp);
        }

        return $latest->toValueObject();
    }
}
