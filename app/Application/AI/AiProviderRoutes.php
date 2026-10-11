<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\AiProviderConnection;
use App\Models\AiUserPreference;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AiProviderRoutes
{
    /** @return list<AiProviderConnection> */
    public function select(Company $company, User $actor, string $intent, string $capability, ?string $selectedId = null): array
    {
        return DB::transaction(function () use ($company, $actor, $intent, $capability, $selectedId): array {
            if (! in_array($capability, ['text_structured', 'audio_transcription'], true)) {
                throw new AssistantUnavailable('provider_configuration');
            }
            $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $intent, $capability === 'audio_transcription');
            $routeName = $capability === 'audio_transcription' ? 'speech_connection' : 'text_connection';
            $preference = AiUserPreference::where('company_id', $company->id)->where('user_id', $actor->id)->first();
            $id = $selectedId ?? $preference?->preferences[$routeName] ?? $policy->settings[$routeName] ?? null;
            if (! is_string($id)) {
                throw new AssistantUnavailable('provider_configuration');
            }
            $primary = app(AiGovernance::class)->dispatchConnection($company, $actor, $id, $intent, $capability)['connection'];
            $routes = [$primary];
            if (($policy->settings['fallback']['enabled'] ?? false) === true) {
                $fallbackId = $policy->settings['fallback'][$routeName] ?? null;
                if (is_string($fallbackId)) {
                    $fallback = app(AiGovernance::class)->dispatchConnection($company, $actor, $fallbackId, $intent, $capability)['connection'];
                    if ($fallback->id === $primary->id || $fallback->credential_source !== $primary->credential_source) {
                        throw new AssistantUnavailable('fallback_invalid');
                    }
                    $routes[] = $fallback;
                }
            }

            return $routes;
        });
    }

    public function preference(Company $company, User $actor, string $capability, string $connectionId): void
    {
        DB::transaction(function () use ($company, $actor, $capability, $connectionId): void {
            $this->select($company, $actor, 'auto', $capability, $connectionId);
            $preference = AiUserPreference::firstOrCreate(['company_id' => $company->id, 'user_id' => $actor->id]);
            $values = $preference->preferences ?? [];
            $values[$capability === 'audio_transcription' ? 'speech_connection' : 'text_connection'] = $connectionId;
            $preference->update(['preferences' => $values]);
        });
    }
}
