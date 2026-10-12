<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\AiProviderConnection;
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
            if ($capability !== DeepSeekDefaults::CAPABILITY) {
                throw new AssistantUnavailable('provider_configuration');
            }
            $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $intent);
            // Historical personal preferences and fallback rows remain audit evidence only.
            $id = $policy->settings['text_connection'] ?? null;
            if ($selectedId !== null && $selectedId !== '' && $selectedId !== $id) {
                throw new AssistantUnavailable('proposal_stale');
            }
            if (! is_string($id) || $id === '') {
                throw new AssistantUnavailable('provider_configuration');
            }
            $primary = app(AiGovernance::class)->routeConnection($company, $actor, $id, $intent, $capability)['connection'];
            $routes = [$primary];

            return $routes;
        });
    }
}
