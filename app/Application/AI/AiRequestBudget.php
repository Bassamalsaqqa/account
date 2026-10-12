<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\AiCompanyPolicy;
use App\Models\AiProviderConnection;
use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class AiRequestBudget
{
    /** @param list<AiProviderConnection> $connections
     * @return array{request: AssistantRequest, created: bool}
     */
    public function reserve(Company $company, User $actor, string $key, string $formKey, string $purpose, string $intent, string $inputHash, array $connections = [], int $audioMilliseconds = 60000): array
    {
        if (! Str::isUuid($key) || ! Str::isUuid($formKey) || ! preg_match('/\A[a-f0-9]{64}\z/', $inputHash)
            || ! in_array($purpose, ['intent', 'patch', 'question'], true) || count($connections) > 1) {
            throw new AssistantUnavailable('invalid_request');
        }

        return DB::transaction(function () use ($company, $actor, $key, $formKey, $purpose, $intent, $inputHash, $connections, $audioMilliseconds): array {
            $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $intent);
            $existing = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)
                ->where('request_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->input_hash !== $inputHash || $existing->purpose !== $purpose || $existing->form_key !== $formKey || ($existing->intent_kind !== $intent && ! ($intent === 'auto' && in_array($existing->intent_kind, ['sales', 'purchase'], true)))) {
                    throw new AssistantUnavailable('request_conflict');
                }

                return ['request' => $existing, 'created' => false];
            }
            $reserved = 0;
            $reference = null;
            $first = null;
            $source = null;
            $plan = [];
            foreach ($connections as $connection) {
                if ((int) $connection->company_id !== (int) $company->id) {
                    throw new AssistantUnavailable('provider_configuration');
                }
                $grant = app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id, $intent,
                    'text_structured', (int) $connection->revision);
                $connection = $grant['connection'];
                $plan[] = ['id' => (int) $connection->id, 'revision' => (int) $connection->revision,
                    'public_id' => $connection->public_id];
                $reserved += $this->estimate($policy, $connection, $purpose, $audioMilliseconds);
                $reference = $this->pricing($policy, $connection)['reference'];
                $first ??= $connection;
                $source = $connection->credential_source;
            }
            if ($connections !== []) {
                $perRequest = $this->limit($policy, 'request_micros', 'request_cost_ceiling_micros');
                if ($reserved < 1 || $reserved > $perRequest) {
                    throw new AssistantUnavailable('budget');
                }
            }
            $rateKey = 'ai-transaction:'.$company->id.':'.$actor->id;
            if (RateLimiter::tooManyAttempts($rateKey, 6)) {
                throw new AssistantUnavailable('budget');
            }
            $day = now('UTC')->startOfDay();
            $month = now('UTC')->startOfMonth();
            foreach (['company', 'user'] as $scope) {
                $query = AssistantRequest::where('company_id', $company->id);
                if ($scope === 'user') {
                    $query->where('user_id', $actor->id);
                }
                $countLimit = $this->limit($policy, 'daily_'.$scope.'_requests', 'daily_'.$scope.'_requests');
                if ((clone $query)->where('created_at', '>=', $day)->count() >= $countLimit) {
                    throw new AssistantUnavailable('budget');
                }
                if ($connections !== []) {
                    foreach (['daily' => $day, 'monthly' => $month] as $period => $start) {
                        $limit = $this->limit($policy, $period.'_'.$scope.'_micros', $period.'_'.$scope.'_cost_ceiling_micros');
                        $used = (int) (clone $query)->where('created_at', '>=', $start)->sum('reserved_cost_micros');
                        if ($used > $limit - $reserved) {
                            throw new AssistantUnavailable('budget');
                        }
                    }
                }
            }
            RateLimiter::hit($rateKey, 60);
            $request = AssistantRequest::create([
                'company_id' => $company->id, 'user_id' => $actor->id, 'request_key' => $key,
                'form_key' => $formKey, 'purpose' => $purpose, 'intent_kind' => $intent, 'input_hash' => $inputHash,
                'provider' => $first->provider ?? 'deterministic', 'model' => $first?->model, 'region' => $first?->region,
                'credential_source' => $first?->credential_source, 'connection_id' => $first?->id,
                'connection_revision' => $first?->revision, 'policy_revision' => $policy->revision,
                'status' => 'pending', 'reserved_cost_micros' => $reserved, 'estimated_cost_micros' => $reserved,
                'cost_status' => $connections === [] ? 'no_text_call' : 'unknown',
                'pricing_reference' => $reference, 'attempt_plan' => $plan,
            ]);

            return ['request' => $request, 'created' => true];
        });
    }

    public function beginAttempt(Company $company, User $actor, AssistantRequest $request, AiProviderConnection $connection): void
    {
        DB::transaction(function () use ($company, $actor, $request, $connection): void {
            $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $request->intent_kind, $request->purpose === 'transcription');
            $fresh = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $planned = $fresh->attempt_plan[(int) $fresh->attempt_count] ?? null;
            if (! is_array($planned) || ($planned['id'] ?? null) !== (int) $connection->id
                || ($planned['revision'] ?? null) !== (int) $connection->revision) {
                throw new AssistantUnavailable('fallback_invalid');
            }
            if ($fresh->status !== 'pending' || $fresh->created_at->addSeconds((int) config('ai_assistant.proposal_ttl_seconds'))->isPast()
                || (int) $fresh->attempt_count >= 1
                || (int) $fresh->policy_revision !== (int) $policy->revision
                || $fresh->credential_source !== $connection->credential_source) {
                throw new AssistantUnavailable('request_processed');
            }
            app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id, $request->intent_kind,
                $request->purpose === 'transcription' ? 'audio_transcription' : 'text_structured', (int) $connection->revision);
            $fresh->update(['attempt_count' => (int) $fresh->attempt_count + 1]);
        });
    }

    /** @param array<string, mixed> $usage */
    public function recordUsage(Company $company, User $actor, AssistantRequest $request, array $usage): void
    {
        if (array_diff(array_keys($usage), ['input_units', 'output_units']) !== []) {
            throw new AssistantUnavailable('provider_invalid');
        }
        foreach ($usage as $value) {
            if (! is_int($value) || $value < 0 || $value > 1000000000) {
                throw new AssistantUnavailable('provider_invalid');
            }
        }
        DB::transaction(function () use ($company, $actor, $request, $usage): void {
            app(AiGovernance::class)->authorizeUse($company, $actor, $request->intent_kind, $request->purpose === 'transcription');
            $fresh = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $update = [];
            foreach ($usage as $field => $value) {
                $update[$field] = (int) $fresh->{$field} + $value;
            }
            $fresh->update($update);
            // No refunds or invented reported/invoiced cost for unknown timeout/billing outcomes.
        });
    }

    private function estimate(AiCompanyPolicy $policy, AiProviderConnection $connection, string $purpose, int $audioMilliseconds): int
    {
        $pricing = $this->pricing($policy, $connection);
        if (! is_array($pricing) || ! is_string($pricing['reference'] ?? null)
            || ! is_string($pricing['checked_at'] ?? null)) {
            throw new AssistantUnavailable('budget_configuration');
        }
        foreach (['input_micros_per_million', 'output_micros_per_million', 'audio_micros_per_minute'] as $field) {
            if (! is_int($pricing[$field] ?? null)) {
                throw new AssistantUnavailable('budget_configuration');
            }
        }
        $estimate = app(ProviderCostEstimate::class);

        return $purpose === 'transcription'
            ? $estimate->audio($connection->provider, $audioMilliseconds, $pricing['audio_micros_per_minute'])
            : $estimate->tokens((int) config('ai_assistant.max_input_tokens'), (int) config('ai_assistant.max_output_tokens'),
                $pricing['input_micros_per_million'], $pricing['output_micros_per_million']);
    }

    /** @return array<string, mixed>|null */
    private function pricing(AiCompanyPolicy $policy, AiProviderConnection $connection): ?array
    {
        $route = implode('|', [$connection->provider, $connection->model, $connection->region, $connection->capability]);
        $pricing = $policy->settings['pricing'][$connection->public_id] ?? $policy->settings['pricing'][$route] ?? null;

        return is_array($pricing) ? $pricing : null;
    }

    private function limit(AiCompanyPolicy $policy, string $name, string $platformName): int
    {
        $value = $policy->settings['limits'][$name] ?? null;
        $platform = config('ai_assistant.'.$platformName);
        if (! is_int($value) || $value < 1 || ! is_int($platform) || $platform < 1) {
            throw new AssistantUnavailable('budget_configuration');
        }

        return min($value, $platform);
    }
}
