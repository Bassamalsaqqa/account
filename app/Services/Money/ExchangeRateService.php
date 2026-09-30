<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Domain\Money\Exceptions\UnresolvedExchangeRateException;
use App\Domain\Money\ValueObjects\ExchangeRate as ExchangeRateValueObject;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
        if (! preg_match('/\A[A-Z]{3}\z/', $currencyCode)) {
            throw new InvalidArgumentException("Invalid currency code [{$currencyCode}]. Must be exactly 3 uppercase letters.");
        }

        if ($source === '' || trim($source) !== $source || mb_strlen($source) > 32 || ! preg_match('/\A[a-z0-9_-]+\z/', $source)) {
            throw new InvalidArgumentException("Invalid exchange rate source [{$source}]. Must be 1-32 lowercase alphanumeric characters, dash, or underscore.");
        }

        // Validate rate VO early to fail fast on invalid decimal strings
        $rateVo = ExchangeRateValueObject::from($rate);

        return DB::transaction(function () use ($company, $currencyCode, $rateVo, $effectiveAt, $createdBy, $source): ExchangeRate {
            $context = app(CompanyContext::class);

            if (! $context->hasCompany()) {
                throw new NoActiveCompanyException('Cannot record exchange rate without an active company context.');
            }

            if ($context->companyId() !== $company->id) {
                throw new CompanyReassignmentException("Cannot record exchange rate for company [{$company->id}] when active company is [{$context->companyId()}].");
            }

            $authUser = $context->user();
            if ($authUser !== null && $createdBy !== null && $createdBy->id !== $authUser->id) {
                throw new InvalidArgumentException("Explicit createdBy user [{$createdBy->id}] conflicts with authenticated user [{$authUser->id}].");
            }

            $actingUser = $createdBy ?? $authUser;
            if ($actingUser === null) {
                throw new InvalidArgumentException('Authenticated user is required to record exchange rates.');
            }

            // 1. Lock company FOR UPDATE first
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $company->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCompany->status !== 'active') {
                throw new InvalidArgumentException("Cannot record exchange rate for inactive company [{$lockedCompany->id}].");
            }

            // 2. Lock active actor membership
            /** @var CompanyUser|null $member */
            $member = CompanyUser::where('company_id', $lockedCompany->id)
                ->where('user_id', $actingUser->id)
                ->lockForUpdate()
                ->first();

            if ($member === null || $member->status !== 'active') {
                throw new InvalidArgumentException("User [{$actingUser->id}] is not an active member of company [{$lockedCompany->id}].");
            }

            // 3. Base currency from locked DB company, never stale passed model
            $baseCurrency = strtoupper($lockedCompany->base_currency_code);
            if ($currencyCode === $baseCurrency) {
                throw new InvalidArgumentException("Cannot record exchange rate for company base currency [{$currencyCode}]. Base rate is always 1.");
            }

            // 4. Lock ALL company currency rows and validate base currency and standard rows
            /** @var Collection<string, CompanyCurrency> $lockedCurrencies */
            $lockedCurrencies = CompanyCurrency::where('company_id', $lockedCompany->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('currency_code');

            $standardCurrencies = ['ILS', 'USD', 'JOD'];
            foreach ($standardCurrencies as $sc) {
                if (! isset($lockedCurrencies[$sc])) {
                    throw new InvalidArgumentException("Standard currency [{$sc}] is missing from company [{$lockedCompany->id}] configuration.");
                }
            }

            $baseCurrencies = $lockedCurrencies->filter(fn (CompanyCurrency $c) => (bool) $c->is_base);
            if ($baseCurrencies->count() !== 1) {
                throw new InvalidArgumentException("Company currency configuration is invalid: exactly one base currency must be configured for company [{$lockedCompany->id}], found {$baseCurrencies->count()}.");
            }

            $baseRow = $lockedCurrencies->get($baseCurrency);
            if ($baseRow === null || ! $baseRow->enabled || ! (bool) $baseRow->is_base) {
                throw new InvalidArgumentException("Base currency [{$baseCurrency}] configuration is invalid for company [{$lockedCompany->id}].");
            }

            $foreignRow = $lockedCurrencies->get($currencyCode);
            if ($foreignRow === null || ! $foreignRow->enabled) {
                throw new InvalidArgumentException("Currency [{$currencyCode}] is not enabled for company [{$lockedCompany->id}].");
            }

            return ExchangeRate::create([
                'company_id' => $lockedCompany->id,
                'base_currency_code' => $baseCurrency,
                'currency_code' => $currencyCode,
                'rate' => $rateVo->toDecimalString(),
                'effective_at' => $effectiveAt ?? now(),
                'source' => $source,
                'created_by' => $actingUser->id,
            ]);
        });
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

        // Reload persisted company to use current base currency
        /** @var Company $persistedCompany */
        $persistedCompany = Company::where('id', $company->id)->firstOrFail();

        if (! preg_match('/\A[A-Z]{3}\z/', $currencyCode)) {
            throw new InvalidArgumentException("Invalid currency code [{$currencyCode}]. Must be exactly 3 uppercase letters.");
        }

        $baseCurrency = strtoupper($persistedCompany->base_currency_code);

        if ($currencyCode === $baseCurrency) {
            return ExchangeRateValueObject::one();
        }

        $effectiveCutoff = $timestamp ?? now();

        /** @var ExchangeRate|null $latest */
        $latest = ExchangeRate::where('company_id', $persistedCompany->id)
            ->where('currency_code', $currencyCode)
            ->where('base_currency_code', $baseCurrency)
            ->where('effective_at', '<=', $effectiveCutoff)
            ->orderBy('effective_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($latest === null) {
            throw UnresolvedExchangeRateException::forCurrency($currencyCode, $baseCurrency, $timestamp);
        }

        return $latest->toValueObject();
    }
}
