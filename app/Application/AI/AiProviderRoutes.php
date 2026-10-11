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
            $routes = $this->resolve($company, $actor, $intent, $capability, $selectedId);
            foreach ($routes as $connection) {
                app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id, $intent, $capability);
            }

            return $routes;
        });
    }

    /** @return list<AiProviderConnection> */
    public function resolve(Company $company, User $actor, string $intent, string $capability, ?string $selectedId = null): array
    {
        return DB::transaction(function () use ($company, $actor, $intent, $capability, $selectedId): array {
            if (! in_array($capability, ['text_structured', 'audio_transcription'], true)) {
                throw new AssistantUnavailable('provider_configuration');
            }
            $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $intent, $capability === 'audio_transcription');
            $routeName = $capability === 'audio_transcription' ? 'speech_connection' : 'text_connection';
            $preference = AiUserPreference::where('company_id', $company->id)->where('user_id', $actor->id)->first();
            $id = ($selectedId !== null && $selectedId !== '') ? $selectedId : ($preference?->preferences[$routeName] ?? null);
            if ($id === null || $id === '') {
                $id = $policy->settings[$routeName] ?? null;
            }
            if (! is_string($id) || $id === '') {
                throw new AssistantUnavailable('provider_configuration');
            }
            $primary = app(AiGovernance::class)->routeConnection($company, $actor, $id, $intent, $capability)['connection'];
            $routes = [$primary];
            if (($policy->settings['fallback']['enabled'] ?? false) === true) {
                $fallbackId = $policy->settings['fallback'][$routeName] ?? null;
                if (is_string($fallbackId) && $fallbackId !== '') {
                    $fallback = app(AiGovernance::class)->routeConnection($company, $actor, $fallbackId, $intent, $capability)['connection'];
                    if ($fallback->id === $primary->id || $fallback->credential_source !== $primary->credential_source) {
                        throw new AssistantUnavailable('fallback_invalid');
                    }
                    $routes[] = $fallback;
                }
            }

            return $routes;
        });
    }

    public function preference(Company $company, User $actor, string $capability, ?string $connectionId = null): void
    {
        DB::transaction(function () use ($company, $actor, $capability, $connectionId): void {
            if (! in_array($capability, ['text_structured', 'audio_transcription'], true)) {
                throw new AssistantUnavailable('provider_configuration');
            }
            app(AiGovernance::class)->authorizeUse($company, $actor, 'auto', $capability === 'audio_transcription');
            $preference = AiUserPreference::firstOrCreate(['company_id' => $company->id, 'user_id' => $actor->id]);
            $values = $preference->preferences ?? [];
            $key = $capability === 'audio_transcription' ? 'speech_connection' : 'text_connection';
            if ($connectionId === null || $connectionId === '') {
                unset($values[$key]);
            } else {
                $values[$key] = $connectionId;
            }
            $preference->update(['preferences' => $values]);
            $policy = app(AiGovernance::class)->authorizeUse($company, $actor, 'auto');
            if ($connectionId !== null && $connectionId !== '' || is_string($policy->settings[$key] ?? null)) {
                $this->resolve($company, $actor, 'auto', $capability);
            }
        });
    }
}
