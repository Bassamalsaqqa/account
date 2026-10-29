<?php

declare(strict_types=1);

namespace App\Application\Platform;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Calendar policy only: payment history and administrative suspension are separate. */
final class SubscriptionTimeline
{
    /**
     * Renewal is the first unpaid company-local date. A seven-day grace includes
     * renewal day through renewal + 6; read-only begins at renewal + 7 midnight.
     * Normalize date arithmetic to UTC dates after determining the local day,
     * so daylight-saving changes never become fractional or 24-hour grace days.
     *
     * @param  list<int>  $reminderDays
     * @return array{state: string, days_until_renewal: int, read_only_from: string, reminder_due: bool}
     */
    public function evaluate(string $renewalDate, string $timezone, int $graceDays, array $reminderDays, DateTimeImmutable $instant): array
    {
        if ($graceDays < 0 || $graceDays > 365 || ! $this->validReminders($reminderDays)) {
            throw new InvalidArgumentException('Invalid subscription calendar policy.');
        }

        $utc = new DateTimeZone('UTC');
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', $renewalDate, $utc);
        if ($due === false || $due->format('Y-m-d') !== $renewalDate) {
            throw new InvalidArgumentException('An exact renewal calendar date is required.');
        }

        $localDate = $instant->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
        $today = new DateTimeImmutable($localDate, $utc);
        $days = (int) $today->diff($due)->format('%r%a');
        $readOnly = $due->modify('+'.$graceDays.' days');

        return [
            'state' => match (true) {
                $today >= $readOnly => 'read_only',
                $days <= 0 => 'expired_in_grace',
                $days <= max($reminderDays) => 'approaching_renewal',
                default => 'active',
            },
            'days_until_renewal' => $days,
            'read_only_from' => $readOnly->format('Y-m-d'),
            'reminder_due' => in_array($days, $reminderDays, true),
        ];
    }

    /** Validate runtime configuration as well as correctly typed callers.
     * @param  array<array-key, mixed>  $days
     */
    private function validReminders(array $days): bool
    {
        if ($days === []) {
            return false;
        }
        foreach ($days as $day) {
            if (! is_int($day) || $day < 1 || $day > 365) {
                return false;
            }
        }

        return true;
    }
}
