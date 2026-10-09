<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\DTO\ReportPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportPeriodInputBoundaryTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedInputs(): iterable
    {
        yield 'array preset' => [['preset' => ['today']]];
        yield 'boolean preset' => [['preset' => true]];
        yield 'array from' => [['preset' => 'custom', 'from' => ['2026-10-01'], 'to' => '2026-10-31']];
        yield 'numeric date' => [['preset' => 'custom', 'from' => 20261001, 'to' => '2026-10-31']];
        yield 'unexpected field' => [['company_id' => 1]];
    }

    #[DataProvider('malformedInputs')]
    public function test_malformed_period_data_is_controlled_validation(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::fromArray($input, 'UTC');
    }

    public function test_explicit_business_dates_are_not_timezone_converted(): void
    {
        $period = ReportPeriod::fromArray(['preset' => 'custom', 'from' => '2026-12-31', 'to' => '2027-01-01'], 'Pacific/Auckland');
        $this->assertSame('2026-12-31', $period->startDate);
        $this->assertSame('2027-01-01', $period->endDate);
    }
}
