<?php

declare(strict_types=1);

namespace Tests\Unit\ManagedSaas;

use App\Application\Platform\SubscriptionTimeline;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscriptionTimelineTest extends TestCase
{
    public static function boundaries(): array
    {
        return [
            'before reminder window' => ['2026-10-01T12:00:00Z', 'UTC', 7, 'active', 28, false],
            'fourteen day reminder' => ['2026-10-15T12:00:00Z', 'UTC', 7, 'approaching_renewal', 14, true],
            'inside window without duplicate reminder' => ['2026-10-16T12:00:00Z', 'UTC', 7, 'approaching_renewal', 13, false],
            'seven day reminder' => ['2026-10-22T12:00:00Z', 'UTC', 7, 'approaching_renewal', 7, true],
            'one day reminder' => ['2026-10-28T23:59:59Z', 'UTC', 7, 'approaching_renewal', 1, true],
            'renewal midnight enters grace' => ['2026-10-29T00:00:00Z', 'UTC', 7, 'expired_in_grace', 0, false],
            'last grace second' => ['2026-11-04T23:59:59Z', 'UTC', 7, 'expired_in_grace', -6, false],
            'first read-only second' => ['2026-11-05T00:00:00Z', 'UTC', 7, 'read_only', -7, false],
            'no grace' => ['2026-10-29T00:00:00Z', 'UTC', 0, 'read_only', 0, false],
            'local date is ahead of UTC' => ['2026-10-28T21:30:00Z', 'Asia/Amman', 7, 'expired_in_grace', 0, false],
            'local date is behind UTC' => ['2026-10-29T01:00:00Z', 'America/New_York', 7, 'approaching_renewal', 1, true],
            'DST extra hour remains last grace day' => ['2026-11-05T04:59:59Z', 'America/New_York', 7, 'expired_in_grace', -6, false],
            'DST extra hour does not delay cutoff' => ['2026-11-05T05:00:00Z', 'America/New_York', 7, 'read_only', -7, false],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_calendar_boundaries(string $instant, string $timezone, int $grace, string $state, int $days, bool $reminder): void
    {
        $result = (new SubscriptionTimeline)->evaluate('2026-10-29', $timezone, $grace, [14, 7, 1], new DateTimeImmutable($instant));
        $this->assertSame($state, $result['state']);
        $this->assertSame($days, $result['days_until_renewal']);
        $this->assertSame($reminder, $result['reminder_due']);
        $this->assertSame($grace === 0 ? '2026-10-29' : '2026-11-05', $result['read_only_from']);
    }

    public static function invalidPolicies(): array
    {
        return [
            ['2026-02-30', 7, [14, 7, 1]],
            ['2026-2-01', 7, [14, 7, 1]],
            ['2026-10-29', -1, [14, 7, 1]],
            ['2026-10-29', 366, [14, 7, 1]],
            ['2026-10-29', 7, []],
            ['2026-10-29', 7, [0]],
            ['2026-10-29', 7, [1.5]],
            ['2026-10-29', 7, ['7']],
        ];
    }

    #[DataProvider('invalidPolicies')]
    public function test_invalid_calendar_policy_is_rejected(string $date, int $grace, array $reminders): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SubscriptionTimeline)->evaluate($date, 'UTC', $grace, $reminders, new DateTimeImmutable('2026-10-29T12:00:00Z'));
    }
}
