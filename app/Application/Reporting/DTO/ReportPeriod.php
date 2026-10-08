<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Models\Company;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportPeriod
{
    public const string PRESET_TODAY = 'today';

    public const string PRESET_YESTERDAY = 'yesterday';

    public const string PRESET_THIS_WEEK = 'this_week';

    public const string PRESET_LAST_WEEK = 'last_week';

    public const string PRESET_THIS_MONTH = 'this_month';

    public const string PRESET_LAST_MONTH = 'last_month';

    public const string PRESET_THIS_QUARTER = 'this_quarter';

    public const string PRESET_THIS_YEAR = 'this_year';

    public const string PRESET_LAST_YEAR = 'last_year';

    public const string PRESET_CUSTOM = 'custom';

    /**
     * @var list<string>
     */
    public const array ALLOWED_PRESETS = [
        self::PRESET_TODAY,
        self::PRESET_YESTERDAY,
        self::PRESET_THIS_WEEK,
        self::PRESET_LAST_WEEK,
        self::PRESET_THIS_MONTH,
        self::PRESET_LAST_MONTH,
        self::PRESET_THIS_QUARTER,
        self::PRESET_THIS_YEAR,
        self::PRESET_LAST_YEAR,
        self::PRESET_CUSTOM,
    ];

    public function __construct(
        public string $preset,
        public string $startDate,
        public string $endDate,
        public string $timezone
    ) {
        if (! in_array($this->preset, self::ALLOWED_PRESETS, true)) {
            throw new InvalidArgumentException("Unknown period preset [{$this->preset}].");
        }

        self::validateDateString($this->startDate);
        self::validateDateString($this->endDate);

        if ($this->startDate > $this->endDate) {
            throw new InvalidArgumentException("Start date [{$this->startDate}] cannot be after end date [{$this->endDate}].");
        }

        self::validateTimezoneString($this->timezone);
    }

    /**
     * Resolve preset period in Company timezone.
     */
    public static function fromPreset(
        string $preset,
        Company|string $companyOrTimezone,
        ?CarbonImmutable $clock = null
    ): self {
        $tz = self::resolveTimezone($companyOrTimezone);
        $now = $clock !== null ? $clock->setTimezone($tz) : CarbonImmutable::now($tz);

        return match ($preset) {
            self::PRESET_TODAY => new self(
                preset: self::PRESET_TODAY,
                startDate: $now->format('Y-m-d'),
                endDate: $now->format('Y-m-d'),
                timezone: $tz
            ),
            self::PRESET_YESTERDAY => new self(
                preset: self::PRESET_YESTERDAY,
                startDate: $now->subDay()->format('Y-m-d'),
                endDate: $now->subDay()->format('Y-m-d'),
                timezone: $tz
            ),
            self::PRESET_THIS_WEEK => new self(
                preset: self::PRESET_THIS_WEEK,
                startDate: $now->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'),
                endDate: $now->endOfWeek(CarbonImmutable::SUNDAY)->format('Y-m-d'),
                timezone: $tz
            ),
            self::PRESET_LAST_WEEK => new self(
                preset: self::PRESET_LAST_WEEK,
                startDate: $now->subWeek()->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'),
                endDate: $now->subWeek()->endOfWeek(CarbonImmutable::SUNDAY)->format('Y-m-d'),
                timezone: $tz
            ),
            self::PRESET_THIS_MONTH => new self(
                preset: self::PRESET_THIS_MONTH,
                startDate: $now->startOfMonth()->format('Y-m-d'),
                endDate: $now->endOfMonth()->format('Y-m-d'),
                timezone: $tz
            ),
            self::PRESET_LAST_MONTH => (function () use ($now, $tz) {
                $lastMonth = $now->subMonthNoOverflow();

                return new self(
                    preset: self::PRESET_LAST_MONTH,
                    startDate: $lastMonth->startOfMonth()->format('Y-m-d'),
                    endDate: $lastMonth->endOfMonth()->format('Y-m-d'),
                    timezone: $tz
                );
            })(),
            self::PRESET_THIS_QUARTER => new self(
                preset: self::PRESET_THIS_QUARTER,
                startDate: $now->startOfQuarter()->format('Y-m-d'),
                endDate: $now->endOfQuarter()->format('Y-m-d'),
                timezone: $tz
            ),
            self::PRESET_THIS_YEAR => new self(
                preset: self::PRESET_THIS_YEAR,
                startDate: $now->startOfYear()->format('Y-m-d'),
                endDate: $now->endOfYear()->format('Y-m-d'),
                timezone: $tz
            ),
            self::PRESET_LAST_YEAR => new self(
                preset: self::PRESET_LAST_YEAR,
                startDate: $now->subYear()->startOfYear()->format('Y-m-d'),
                endDate: $now->subYear()->endOfYear()->format('Y-m-d'),
                timezone: $tz
            ),
            self::PRESET_CUSTOM => throw new InvalidArgumentException('Custom period requires explicit startDate and endDate. Use ReportPeriod::custom().'),
            default => throw new InvalidArgumentException("Unknown period preset [{$preset}]."),
        };
    }

    /**
     * Create an immutable custom date range without timezone date shifting.
     */
    public static function custom(
        string $startDate,
        string $endDate,
        Company|string $companyOrTimezone
    ): self {
        $tz = self::resolveTimezone($companyOrTimezone);

        return new self(
            preset: self::PRESET_CUSTOM,
            startDate: $startDate,
            endDate: $endDate,
            timezone: $tz
        );
    }

    /**
     * Resolve period from input array data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(
        array $data,
        Company|string $companyOrTimezone,
        ?CarbonImmutable $clock = null
    ): self {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, ['preset', 'from', 'to', 'start_date', 'end_date', 'timezone'], true)) {
                throw new InvalidArgumentException('Unknown period field ['.(string) $key.'].');
            }
        }
        foreach (['preset', 'from', 'to', 'start_date', 'end_date', 'timezone'] as $key) {
            if (isset($data[$key]) && ! is_string($data[$key])) {
                throw new InvalidArgumentException("Period field [$key] must be text.");
            }
        }
        $preset = $data['preset'] ?? self::PRESET_THIS_MONTH;

        if ($preset === self::PRESET_CUSTOM) {
            $from = (string) ($data['from'] ?? $data['start_date'] ?? '');
            $to = (string) ($data['to'] ?? $data['end_date'] ?? '');

            return self::custom($from, $to, $companyOrTimezone);
        }

        return self::fromPreset($preset, $companyOrTimezone, $clock);
    }

    /**
     * Check if a given YYYY-MM-DD date falls within this period (inclusive).
     */
    public function contains(string $date): bool
    {
        self::validateDateString($date);

        return $date >= $this->startDate && $date <= $this->endDate;
    }

    public function equals(self $other): bool
    {
        return $this->preset === $other->preset
            && $this->startDate === $other->startDate
            && $this->endDate === $other->endDate
            && $this->timezone === $other->timezone;
    }

    /**
     * @return array{preset: string, start_date: string, end_date: string, timezone: string}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'timezone' => $this->timezone,
        ];
    }

    private static function resolveTimezone(Company|string $companyOrTimezone): string
    {
        if ($companyOrTimezone instanceof Company) {
            return (string) $companyOrTimezone->timezone;
        }

        return $companyOrTimezone;
    }

    private static function validateDateString(string $date): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException("Date [{$date}] must be in YYYY-MM-DD format.");
        }

        [$y, $m, $d] = explode('-', $date);
        if (! checkdate((int) $m, (int) $d, (int) $y)) {
            throw new InvalidArgumentException("Date [{$date}] is not a valid calendar date.");
        }
    }

    private static function validateTimezoneString(string $timezone): void
    {
        try {
            if ($timezone === '') {
                throw new InvalidArgumentException('Company timezone is required.');
            }
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new InvalidArgumentException("Invalid timezone [{$timezone}].");
        }
    }
}
