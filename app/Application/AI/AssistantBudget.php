<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class AssistantBudget
{
    public function reserve(Company $company, User $actor, string $key, string $purpose, string $hash): AssistantRequest
    {
        if (! Str::isUuid($key) || ! in_array($purpose, ['intent', 'transcription'], true)) {
            throw new AssistantUnavailable('invalid_request');
        }

        return DB::transaction(function () use ($company, $actor, $key, $purpose, $hash): AssistantRequest {
            app(AssistantAccess::class)->authorize($company, $actor, $purpose === 'transcription');
            // Serialize both company and actor reservations before checking aggregate caps.
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $existing = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->where('request_key', $key)->first();
            if ($existing !== null) {
                if ($existing->input_hash !== $hash || $existing->purpose !== $purpose) {
                    throw new AssistantUnavailable('request_conflict');
                }

                return $existing;
            }
            $minuteKey = 'ai-sales:'.$company->id.':'.$actor->id;
            if (RateLimiter::tooManyAttempts($minuteKey, 6)) {
                throw new AssistantUnavailable('budget');
            }
            RateLimiter::hit($minuteKey, 60);
            $ceiling = (int) config('ai_assistant.request_cost_ceiling_micros');
            if ($ceiling < 1 || $ceiling > 1000000000) {
                throw new AssistantUnavailable('budget');
            }
            $day = now('UTC')->startOfDay();
            $month = now('UTC')->startOfMonth();
            foreach ([false, true] as $actorOnly) {
                $query = AssistantRequest::where('company_id', $company->id);
                if ($actorOnly) {
                    $query->where('user_id', $actor->id);
                }
                $prefix = $actorOnly ? 'user' : 'company';
                $dailyLimit = (int) config('ai_assistant.daily_'.$prefix.'_requests');
                if ($dailyLimit < 1 || $dailyLimit > 1000 || (clone $query)->where('created_at', '>=', $day)->count() >= $dailyLimit) {
                    throw new AssistantUnavailable('budget');
                }
                foreach (['daily' => $day, 'monthly' => $month] as $period => $start) {
                    $cap = (int) config('ai_assistant.'.$period.'_'.$prefix.'_cost_ceiling_micros');
                    $reserved = (int) (clone $query)->where('created_at', '>=', $start)->sum('reserved_cost_micros');
                    if ($cap < 1 || $cap > 1000000000000 || $reserved > $cap - $ceiling) {
                        throw new AssistantUnavailable('budget');
                    }
                }
            }

            return AssistantRequest::create([
                'company_id' => $company->id, 'user_id' => $actor->id, 'request_key' => $key,
                'purpose' => $purpose, 'input_hash' => $hash, 'provider' => 'gateway',
                'status' => 'pending', 'reserved_cost_micros' => $ceiling,
            ]);
        });
    }

    /** @param array<string, mixed> $usage */
    public function finish(AssistantRequest $request, array $usage): void
    {
        if (array_diff(array_keys($usage), ['cost_micros', 'input_units', 'output_units']) !== []
            || ! isset($usage['cost_micros'], $usage['input_units'], $usage['output_units'])) {
            throw new AssistantUnavailable('provider_invalid');
        }
        foreach ($usage as $value) {
            if (! is_int($value) || $value < 0 || $value > 1000000000) {
                throw new AssistantUnavailable('provider_invalid');
            }
        }
        if ($usage['cost_micros'] > $request->reserved_cost_micros) {
            throw new AssistantUnavailable('budget');
        }
        $request->update(['reported_cost_micros' => $usage['cost_micros'], 'input_units' => $usage['input_units'], 'output_units' => $usage['output_units']]);
        // Reservations are never refunded on timeout/error or under-reported billing.
    }

    /** @return array<string, int> */
    public function summary(Company $company, User $actor, bool $companyWide = false): array
    {
        app(AssistantAccess::class)->authorize($company, $actor);
        if ($companyWide) {
            DB::transaction(fn () => app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'settings.company.manage'));
        }
        $query = AssistantRequest::where('company_id', $company->id);
        if (! $companyWide) {
            $query->where('user_id', $actor->id);
        }
        $day = now('UTC')->startOfDay();
        $month = now('UTC')->startOfMonth();

        return [
            'daily_requests' => (clone $query)->where('created_at', '>=', $day)->count(),
            'daily_reserved_micros' => (int) (clone $query)->where('created_at', '>=', $day)->sum('reserved_cost_micros'),
            'monthly_reserved_micros' => (int) (clone $query)->where('created_at', '>=', $month)->sum('reserved_cost_micros'),
            'monthly_reported_micros' => (int) (clone $query)->where('created_at', '>=', $month)->sum('reported_cost_micros'),
            'unreported_requests' => (clone $query)->where('created_at', '>=', $month)->whereNull('reported_cost_micros')->count(),
        ];
    }
}
