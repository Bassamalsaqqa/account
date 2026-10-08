<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\DTO\ReportPeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class ReportPeriodTest extends Phase8TestCase
{
    public function test_all_period_presets_resolve_correctly(): void
    {
        // Deterministic reference clock: Wednesday 2026-10-08 14:30:00 in Asia/Hebron
        $clock = CarbonImmutable::parse('2026-10-08 14:30:00', 'Asia/Hebron');

        // 1. Today
        $today = ReportPeriod::fromPreset(ReportPeriod::PRESET_TODAY, $this->company, $clock);
        $this->assertSame('today', $today->preset);
        $this->assertSame('2026-10-08', $today->startDate);
        $this->assertSame('2026-10-08', $today->endDate);
        $this->assertSame('Asia/Hebron', $today->timezone);

        // 2. Yesterday
        $yesterday = ReportPeriod::fromPreset(ReportPeriod::PRESET_YESTERDAY, $this->company, $clock);
        $this->assertSame('yesterday', $yesterday->preset);
        $this->assertSame('2026-10-07', $yesterday->startDate);
        $this->assertSame('2026-10-07', $yesterday->endDate);

        // 3. This week
        $thisWeek = ReportPeriod::fromPreset(ReportPeriod::PRESET_THIS_WEEK, $this->company, $clock);
        $this->assertSame('this_week', $thisWeek->preset);
        $this->assertSame('2026-10-05', $thisWeek->startDate);
        $this->assertSame('2026-10-11', $thisWeek->endDate);

        // 4. Last week
        $lastWeek = ReportPeriod::fromPreset(ReportPeriod::PRESET_LAST_WEEK, $this->company, $clock);
        $this->assertSame('last_week', $lastWeek->preset);
        $this->assertSame('2026-09-28', $lastWeek->startDate);
        $this->assertSame('2026-10-04', $lastWeek->endDate);

        // 5. This month
        $thisMonth = ReportPeriod::fromPreset(ReportPeriod::PRESET_THIS_MONTH, $this->company, $clock);
        $this->assertSame('this_month', $thisMonth->preset);
        $this->assertSame('2026-10-01', $thisMonth->startDate);
        $this->assertSame('2026-10-31', $thisMonth->endDate);

        // 6. Last month
        $lastMonth = ReportPeriod::fromPreset(ReportPeriod::PRESET_LAST_MONTH, $this->company, $clock);
        $this->assertSame('last_month', $lastMonth->preset);
        $this->assertSame('2026-09-01', $lastMonth->startDate);
        $this->assertSame('2026-09-30', $lastMonth->endDate);

        // 7. This quarter (Q4: Oct 1 - Dec 31)
        $thisQuarter = ReportPeriod::fromPreset(ReportPeriod::PRESET_THIS_QUARTER, $this->company, $clock);
        $this->assertSame('this_quarter', $thisQuarter->preset);
        $this->assertSame('2026-10-01', $thisQuarter->startDate);
        $this->assertSame('2026-10-31', $thisMonth->endDate);
        $this->assertSame('2026-12-31', $thisQuarter->endDate);

        // 8. This year
        $thisYear = ReportPeriod::fromPreset(ReportPeriod::PRESET_THIS_YEAR, $this->company, $clock);
        $this->assertSame('this_year', $thisYear->preset);
        $this->assertSame('2026-01-01', $thisYear->startDate);
        $this->assertSame('2026-12-31', $thisYear->endDate);

        // 9. Last year
        $lastYear = ReportPeriod::fromPreset(ReportPeriod::PRESET_LAST_YEAR, $this->company, $clock);
        $this->assertSame('last_year', $lastYear->preset);
        $this->assertSame('2025-01-01', $lastYear->startDate);
        $this->assertSame('2025-12-31', $lastYear->endDate);

        // 10. Custom
        $custom = ReportPeriod::custom('2026-03-01', '2026-03-15', $this->company);
        $this->assertSame('custom', $custom->preset);
        $this->assertSame('2026-03-01', $custom->startDate);
        $this->assertSame('2026-03-15', $custom->endDate);
    }

    public function test_company_timezone_and_midnight_boundary(): void
    {
        // 23:30 in UTC on 2026-10-08 is 02:30 on 2026-10-09 in Asia/Hebron (UTC+3)
        $utcMidnightClock = CarbonImmutable::parse('2026-10-08 23:30:00', 'UTC');

        $periodHebron = ReportPeriod::fromPreset(ReportPeriod::PRESET_TODAY, 'Asia/Hebron', $utcMidnightClock);
        $periodUtc = ReportPeriod::fromPreset(ReportPeriod::PRESET_TODAY, 'UTC', $utcMidnightClock);

        $this->assertSame('2026-10-09', $periodHebron->startDate);
        $this->assertSame('2026-10-08', $periodUtc->startDate);
    }

    public function test_year_boundary_resolution(): void
    {
        // Dec 31 at 23:59:59 vs Jan 01 at 00:00:01
        $newYearEve = CarbonImmutable::parse('2026-12-31 23:59:59', 'Asia/Hebron');
        $newYearDay = CarbonImmutable::parse('2027-01-01 00:00:01', 'Asia/Hebron');

        $periodEve = ReportPeriod::fromPreset(ReportPeriod::PRESET_THIS_YEAR, 'Asia/Hebron', $newYearEve);
        $this->assertSame('2026-01-01', $periodEve->startDate);
        $this->assertSame('2026-12-31', $periodEve->endDate);

        $periodDay = ReportPeriod::fromPreset(ReportPeriod::PRESET_THIS_YEAR, 'Asia/Hebron', $newYearDay);
        $this->assertSame('2027-01-01', $periodDay->startDate);
        $this->assertSame('2027-12-31', $periodDay->endDate);

        $lastYearFromDay = ReportPeriod::fromPreset(ReportPeriod::PRESET_LAST_YEAR, 'Asia/Hebron', $newYearDay);
        $this->assertSame('2026-01-01', $lastYearFromDay->startDate);
        $this->assertSame('2026-12-31', $lastYearFromDay->endDate);
    }

    public function test_invalid_dates_and_ranges_fail_closed(): void
    {
        // Invalid calendar date (Feb 30)
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::custom('2026-02-30', '2026-03-01', $this->company);
    }

    public function test_start_after_end_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::custom('2026-10-15', '2026-10-10', $this->company);
    }

    public function test_invalid_preset_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::fromPreset('non_existent_preset', $this->company);
    }

    public function test_contains_and_equals_helpers(): void
    {
        $period = ReportPeriod::custom('2026-05-01', '2026-05-31', 'Asia/Hebron');

        $this->assertTrue($period->contains('2026-05-01'));
        $this->assertTrue($period->contains('2026-05-15'));
        $this->assertTrue($period->contains('2026-05-31'));
        $this->assertFalse($period->contains('2026-04-30'));
        $this->assertFalse($period->contains('2026-06-01'));

        $same = ReportPeriod::custom('2026-05-01', '2026-05-31', 'Asia/Hebron');
        $this->assertTrue($period->equals($same));

        $different = ReportPeriod::custom('2026-05-01', '2026-05-30', 'Asia/Hebron');
        $this->assertFalse($period->equals($different));
    }
}
