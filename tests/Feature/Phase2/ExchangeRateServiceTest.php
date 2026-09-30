<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Money\Exceptions\UnresolvedExchangeRateException;
use App\Domain\Money\ValueObjects\ExchangeRate as ExchangeRateValueObject;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\Money\ExchangeRateService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $companyA;

    protected Company $companyB;

    protected ExchangeRateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->companyA = $creator->execute($this->user, [
            'name_ar' => 'شركة أ',
            'base_currency_code' => 'ILS',
        ]);

        $this->companyB = $creator->execute($this->user, [
            'name_ar' => 'شركة ب',
            'base_currency_code' => 'USD',
        ]);

        $this->service = app(ExchangeRateService::class);

        app(CompanyContext::class)->setCompany($this->companyA, $this->user);
    }

    public function test_can_record_manual_suggested_rate_for_enabled_currency(): void
    {
        $rate = $this->service->recordRate(
            company: $this->companyA,
            currencyCode: 'USD',
            rate: '3.6500000000',
            effectiveAt: Carbon::parse('2026-09-01 10:00:00'),
            createdBy: $this->user,
            source: 'manual'
        );

        $this->assertInstanceOf(ExchangeRate::class, $rate);
        $this->assertSame($this->companyA->id, $rate->company_id);
        $this->assertSame('ILS', $rate->base_currency_code);
        $this->assertSame('USD', $rate->currency_code);
        $this->assertSame('3.6500000000', $rate->rate);
        $this->assertSame('manual', $rate->source);
        $this->assertSame($this->user->id, $rate->created_by);
    }

    public function test_cannot_record_rate_for_base_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot record exchange rate for company base currency [ILS]');

        $this->service->recordRate(
            company: $this->companyA,
            currencyCode: 'ILS',
            rate: '1.0000000000'
        );
    }

    public function test_cannot_record_rate_for_disabled_currency(): void
    {
        // Disable JOD in company A
        CompanyScope::executeWithoutScope(function () {
            CompanyCurrency::where('company_id', $this->companyA->id)
                ->where('currency_code', 'JOD')
                ->update(['enabled' => false]);
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency [JOD] is not enabled');

        $this->service->recordRate(
            company: $this->companyA,
            currencyCode: 'JOD',
            rate: '5.1000000000'
        );
    }

    public function test_historical_rate_resolution(): void
    {
        // T1: 3.50 on Sep 01
        $this->service->recordRate(
            company: $this->companyA,
            currencyCode: 'USD',
            rate: '3.5000000000',
            effectiveAt: Carbon::parse('2026-09-01 08:00:00')
        );

        // T2: 3.60 on Sep 15
        $this->service->recordRate(
            company: $this->companyA,
            currencyCode: 'USD',
            rate: '3.6000000000',
            effectiveAt: Carbon::parse('2026-09-15 08:00:00')
        );

        // Query before Sep 15 returns Sep 01 rate (3.50)
        $rateAtSep10 = $this->service->resolveRate(
            $this->companyA,
            'USD',
            Carbon::parse('2026-09-10 12:00:00')
        );
        $this->assertSame('3.5000000000', $rateAtSep10->toDecimalString());

        // Query after Sep 15 returns latest rate (3.60)
        $rateAtSep20 = $this->service->resolveRate(
            $this->companyA,
            'USD',
            Carbon::parse('2026-09-20 12:00:00')
        );
        $this->assertSame('3.6000000000', $rateAtSep20->toDecimalString());

        // Query without timestamp returns absolute latest (3.60)
        $rateLatest = $this->service->resolveRate($this->companyA, 'USD');
        $this->assertSame('3.6000000000', $rateLatest->toDecimalString());

        // Query before Sep 01 throws UnresolvedExchangeRateException
        $this->expectException(UnresolvedExchangeRateException::class);
        $this->service->resolveRate(
            $this->companyA,
            'USD',
            Carbon::parse('2026-08-31 23:59:59')
        );
    }

    public function test_resolving_base_currency_returns_one_without_db_lookup(): void
    {
        $rate = $this->service->resolveRate($this->companyA, 'ILS');

        $this->assertInstanceOf(ExchangeRateValueObject::class, $rate);
        $this->assertTrue($rate->isOne());
        $this->assertSame('1.0000000000', $rate->toDecimalString());
    }

    public function test_tenant_isolation_on_exchange_rates(): void
    {
        // Company A has USD rate 3.65
        $this->service->recordRate(
            company: $this->companyA,
            currencyCode: 'USD',
            rate: '3.6500000000',
            effectiveAt: now()
        );

        // Switch to Company B context
        app(CompanyContext::class)->setCompany($this->companyB, $this->user);

        // Company B has base USD, and enabled ILS. Record ILS rate 0.27
        $this->service->recordRate(
            company: $this->companyB,
            currencyCode: 'ILS',
            rate: '0.2739726027',
            effectiveAt: now()
        );

        // Company B cannot see Company A's USD rate
        $this->expectException(UnresolvedExchangeRateException::class);
        $this->service->resolveRate($this->companyB, 'JOD'); // No JOD rate in company B
    }
}
