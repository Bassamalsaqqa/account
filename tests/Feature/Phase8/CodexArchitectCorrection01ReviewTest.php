<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Presentation\DashboardReports;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Queries\MoneyBalanceReportQuery;
use App\Application\Reporting\Support\OperationalReportRead;
use Carbon\Carbon;

final class CodexArchitectCorrection01ReviewTest extends TradeTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_review_explicit_constructor_period_remains_historical(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->activateUser($this->owner);
        $explicit = new ReportFilters(period: ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company));
        $result = app(MoneyBalanceReportQuery::class)->execute($this->company, $explicit, $this->owner);
        $this->assertSame('2026-10-31', $result->meta['as_of_date']);
    }

    public function test_review_constructor_dto_still_revalidates_resource_ownership(): void
    {
        $this->activateUser($this->owner);
        $input = new ReportFilters(period: ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company), moneyAccountId: 2147483647);
        $this->expectException(InvalidReportFilterException::class);
        app(MoneyBalanceReportQuery::class)->execute($this->company, $input, $this->owner);
    }

    public function test_review_constructor_dto_uses_company_timezone(): void
    {
        $input = new ReportFilters(period: new ReportPeriod('custom', '2026-10-01', '2026-10-31', 'UTC'));
        $validated = OperationalReportRead::filters($this->company, $input, []);
        $this->assertSame((string) $this->company->timezone, $validated->period->timezone);
        $this->assertSame('2026-10-01', $validated->period->startDate);
        $this->assertSame('2026-10-31', $validated->period->endDate);
    }

    public function test_review_dto_with_implicit_period_defaults_to_today(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->activateUser($this->owner);
        $implicit = (new ReportFilters(period: ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company)))
            ->withPeriod(ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company), false);
        $result = app(MoneyBalanceReportQuery::class)->execute($this->company, $implicit, $this->owner);
        $this->assertSame('2026-10-20', $result->meta['as_of_date']);
    }

    public function test_review_from_array_with_empty_input_defaults_to_today_in_company_timezone(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->activateUser($this->owner);
        $result = app(MoneyBalanceReportQuery::class)->execute($this->company, [], $this->owner);
        $this->assertSame('2026-10-20', $result->meta['as_of_date']);
        $this->assertSame((string) $this->company->timezone, $result->filters['period']['timezone']);
    }

    public function test_review_from_array_preserves_explicit_custom_dates(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->activateUser($this->owner);
        $result = app(MoneyBalanceReportQuery::class)->execute($this->company, [
            'period' => [
                'preset' => 'custom',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-31',
            ],
        ], $this->owner);
        $this->assertSame('2026-10-31', $result->meta['as_of_date']);
    }

    public function test_review_all_three_money_aliases_default_to_today_and_support_explicit_dates(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->activateUser($this->owner);
        $registry = app(ReportRegistry::class);

        foreach (['money.balances', 'money.cash', 'money.bank'] as $alias) {
            $defaultResult = $registry->execute($this->company, $alias, []);
            $this->assertSame('2026-10-20', $defaultResult->meta['as_of_date'], "{$alias} must default to today");

            $explicitResult = $registry->execute($this->company, $alias, [
                'period' => [
                    'preset' => 'custom',
                    'start_date' => '2026-10-01',
                    'end_date' => '2026-10-31',
                ],
            ]);
            $this->assertSame('2026-10-31', $explicitResult->meta['as_of_date'], "{$alias} must preserve explicit date");
        }
    }

    public function test_review_dashboard_positions_reads_money_balances_as_of_today(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->activateUser($this->owner);
        $dashboard = app(DashboardReports::class)->read($this->company, 'this_month');
        $this->assertSame('2026-10-20', $dashboard['today']);
        $this->assertArrayHasKey('money.balances', $dashboard['positions']);
    }

    public function test_review_foreign_and_nonexistent_identities_throw_exception(): void
    {
        $this->activateUser($this->owner);
        $period = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);

        $fields = [
            'customerId' => 999999,
            'vendorId' => 999999,
            'productId' => 999999,
            'warehouseId' => 999999,
            'employeeId' => 999999,
            'moneyAccountId' => 999999,
        ];

        foreach ($fields as $prop => $id) {
            $caught = false;
            try {
                $input = new ReportFilters(...['period' => $period, $prop => $id]);
                $input->validateForCompany($this->company);
            } catch (InvalidReportFilterException) {
                $caught = true;
            }
            $this->assertTrue($caught, "Expected InvalidReportFilterException for nonexistent {$prop}");
        }
    }

    public function test_review_unconfigured_currency_throws_exception(): void
    {
        $this->activateUser($this->owner);
        $period = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);

        $input = new ReportFilters(period: $period, currencyCode: 'EUR');
        $this->expectException(InvalidReportFilterException::class);
        $input->validateForCompany($this->company);
    }
}
