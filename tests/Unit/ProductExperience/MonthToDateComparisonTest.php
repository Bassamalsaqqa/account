<?php

declare(strict_types=1);

namespace Tests\Unit\ProductExperience;

use App\Application\Reporting\DTO\ReportPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class MonthToDateComparisonTest extends TestCase
{
    public function test_mid_month_does_not_compare_with_later_prior_month_dates(): void
    {
        $periods = ReportPeriod::monthToDateComparison('Asia/Hebron', CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Hebron'));
        $this->assertSame('2026-10-01', $periods['current']->startDate);
        $this->assertSame('2026-10-15', $periods['current']->endDate);
        $this->assertSame('2026-09-01', $periods['previous']->startDate);
        $this->assertSame('2026-09-15', $periods['previous']->endDate);
        $this->assertFalse($periods['unequal_days']);
    }

    public function test_short_prior_month_clamps_without_overflow_in_leap_and_non_leap_years(): void
    {
        foreach (['2024-03-31' => '2024-02-29', '2026-03-31' => '2026-02-28'] as $today => $previousEnd) {
            $periods = ReportPeriod::monthToDateComparison('UTC', CarbonImmutable::parse($today, 'UTC'));
            $this->assertSame($today, $periods['current']->endDate);
            $this->assertSame($previousEnd, $periods['previous']->endDate);
            $this->assertTrue($periods['unequal_days']);
        }
    }

    public function test_company_local_midnight_and_year_boundary_are_used(): void
    {
        $periods = ReportPeriod::monthToDateComparison('Asia/Hebron', CarbonImmutable::parse('2025-12-31 23:30:00', 'UTC'));
        $this->assertSame('2026-01-01', $periods['current']->startDate);
        $this->assertSame('2026-01-01', $periods['current']->endDate);
        $this->assertSame('2025-12-01', $periods['previous']->startDate);
        $this->assertSame('2025-12-01', $periods['previous']->endDate);
        $this->assertSame('Asia/Hebron', $periods['current']->timezone);
        $this->assertFalse($periods['unequal_days']);
    }

    public function test_short_current_month_preserves_same_day_comparison_and_canonical_full_month_behavior(): void
    {
        $clock = CarbonImmutable::parse('2026-02-28 12:00:00', 'UTC');
        $periods = ReportPeriod::monthToDateComparison('UTC', $clock);
        $this->assertSame('2026-01-28', $periods['previous']->endDate);
        $this->assertFalse($periods['unequal_days']);
        $fullMonth = ReportPeriod::fromPreset('this_month', 'UTC', $clock);
        $this->assertSame('2026-02-28', $fullMonth->endDate);
        $this->assertSame('2026-01-31', $fullMonth->previous()->endDate);
        $custom = ReportPeriod::custom('2026-10-01', '2026-10-15', 'UTC')->previous();
        $this->assertSame('2026-09-16', $custom->startDate);
        $this->assertSame('2026-09-30', $custom->endDate);
    }
}
