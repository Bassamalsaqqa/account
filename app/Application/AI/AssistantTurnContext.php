<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/** Short-lived user-supplied request labels only. Never caches canonical answers for replay. */
final class AssistantTurnContext
{
    public const int TTL = 1200;

    /** @param list<array<string, mixed>> $proposals */
    public function store(Company $company, User $actor, string $sessionId, array $proposals): string
    {
        $token = (string) Str::uuid();
        $json = json_encode(['scope' => $this->scope($company, $actor, $sessionId), 'proposals' => $proposals], JSON_THROW_ON_ERROR);
        if (strlen($json) > 8192) {
            throw new AssistantUnavailable('input_invalid');
        }
        Cache::put($this->key($company, $actor, $sessionId, $token), Crypt::encryptString($json), self::TTL);

        return $token;
    }

    /** @return list<array<string, mixed>>|null */
    public function read(Company $company, User $actor, string $sessionId, ?string $token): ?array
    {
        if ($token === null) {
            return null;
        }
        if (! Str::isUuid($token)) {
            throw new AssistantUnavailable('proposal_stale');
        }
        $key = $this->key($company, $actor, $sessionId, $token);
        $cipher = Cache::get($key);
        try {
            if (! is_string($cipher) || strlen($cipher) > 16000) {
                throw new AssistantUnavailable('proposal_stale');
            }
            $data = json_decode(Crypt::decryptString($cipher), true, 12, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['scope'] ?? null) !== $this->scope($company, $actor, $sessionId)
                || ! is_array($data['proposals'] ?? null) || ! array_is_list($data['proposals']) || count($data['proposals']) > 3) {
                throw new AssistantUnavailable('proposal_stale');
            }
            $proposals = [];
            foreach ($data['proposals'] as $proposal) {
                if (! is_array($proposal)) {
                    throw new AssistantUnavailable('proposal_stale');
                }
                $intent = AssistantIntentProposal::fromArray($proposal);
                app(AssistantReportCatalog::class)->authorize($company, $actor, $intent->reportKey);
                $proposals[] = $proposal;
            }

            return $proposals;
        } catch (Throwable) {
            Cache::forget($key);
            throw new AssistantUnavailable('proposal_stale');
        }
    }

    /** Recomputed each turn; permission downgrade invalidates rather than redelivers stored data. */
    public function scope(Company $company, User $actor, string $sessionId): string
    {
        $governance = app(AiGovernance::class);
        $policy = $governance->authorizeUse($company, $actor, 'question');
        $keys = app(AssistantReportCatalog::class)->permittedKeys($company, $actor);
        $freshPermissions = $actor->getAllPermissions()->where('guard_name', 'web')->pluck('name')->all();
        $permissions = [];
        foreach (['inventory.cost.view', 'purchasing.cost.view', 'reports.cost.view', 'reports.profit.view', 'money.cash.view', 'money.bank.view'] as $permission) {
            $permissions[$permission] = in_array($permission, $freshPermissions, true);
        }

        return hash_hmac('sha256', json_encode([$company->id, $actor->id, $sessionId, $policy->revision, $keys, $permissions], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function key(Company $company, User $actor, string $sessionId, string $token): string
    {
        return 'ai-turn-context:'.hash('sha256', $company->id.':'.$actor->id.':'.$sessionId.':'.$token);
    }
}
