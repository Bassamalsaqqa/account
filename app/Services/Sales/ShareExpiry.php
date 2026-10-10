<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\PublicShare;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/** Expiry instants are stored in UTC; existing grants are never rewritten on read. */
final class ShareExpiry
{
    public function catalogDate(?string $date, string $timezone, ?Carbon $now = null): ?Carbon
    {
        Validator::make(['expires' => $date], ['expires' => 'nullable|date_format:Y-m-d'])->validate();
        if ($date === null) {
            return null;
        }
        $local = Carbon::createFromFormat('!Y-m-d', $date, $timezone);
        if (! $local instanceof Carbon) {
            throw new InvalidArgumentException('Invalid Company-local catalog expiry date.');
        }
        $expiry = $local->addDay()->startOfDay()->utc();
        if ($expiry->lessThanOrEqualTo($now ?? Carbon::now('UTC'))) {
            throw new InvalidArgumentException('Catalog expiry date has already ended in the Company timezone.');
        }

        return $expiry;
    }

    public function catalogLabel(PublicShare $share, string $timezone): ?string
    {
        $expiry = $share->expires_at?->copy()->setTimezone($timezone);
        if ($this->catalogDateBoundary($share, $timezone)) {
            $expiry?->subSecond(); // Exclusive next-day boundary denotes the preceding valid-through date.
        }

        return $expiry?->format('Y-m-d');
    }

    public function catalogDateBoundary(PublicShare $share, string $timezone): bool
    {
        if ($share->access_profile !== 'catalog_v2') {
            return false;
        }
        $local = $share->expires_at?->copy()->setTimezone($timezone);

        // A later Company timezone change must not imply a different full-day lifetime.
        return $local === null || $local->equalTo($local->copy()->startOfDay());
    }

    public function displayInstant(?Carbon $expiry, string $timezone): ?string
    {
        return $expiry?->copy()->setTimezone($timezone)->toIso8601String();
    }

    /** A day means exactly 86,400 elapsed seconds, independent of DST/calendar boundaries. */
    public function financialLifetime(int $days, bool $statement, ?Carbon $issuance = null): Carbon
    {
        if ($days < 1 || $days > ($statement ? 30 : 365)) {
            throw new InvalidArgumentException('Expiry exceeds the permitted subject lifetime.');
        }

        return ($issuance ?? Carbon::now('UTC'))->copy()->utc()->addSeconds($days * 86400);
    }

    public function assertFinancialInstant(Carbon $expires, bool $statement, Carbon $issuance): void
    {
        if ($expires->lessThanOrEqualTo($issuance) || $expires->greaterThan($this->financialLifetime($statement ? 30 : 365, $statement, $issuance))) {
            throw new InvalidArgumentException('Expiry exceeds the permitted subject lifetime.');
        }
    }
}
