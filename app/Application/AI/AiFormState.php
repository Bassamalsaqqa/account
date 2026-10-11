<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class AiFormState
{
    /** @param array<string, mixed> $data */
    public function create(Company $company, User $actor, string $formKey, string $sessionId, string $intent, array $data, int $revision = 0, ?int $expiresAt = null): string
    {
        if (! Str::isUuid($formKey) || $sessionId === '' || $revision < 0 || $revision > 1000000) {
            throw new AssistantUnavailable('invalid_request');
        }
        $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $intent);
        $token = (string) Str::uuid();
        $state = ['company_id' => (int) $company->id, 'user_id' => (int) $actor->id,
            'form_key' => $formKey, 'session_hash' => hash('sha256', $sessionId), 'intent' => $intent,
            'policy_revision' => (int) $policy->revision, 'revision' => $revision,
            'expires_at' => min(now()->addSeconds((int) config('ai_assistant.proposal_ttl_seconds'))->timestamp, $expiresAt ?? PHP_INT_MAX),
            'data' => $data];
        $this->write($token, $state);

        return $token;
    }

    /** @return array<string, mixed> */
    public function read(Company $company, User $actor, string $token, string $sessionId, ?string $formKey = null): array
    {
        $state = $this->ownedState($company, $actor, $token, $sessionId, $formKey);
        $policy = app(AiGovernance::class)->authorizeUse($company, $actor, (string) $state['intent']);
        if ((int) $policy->revision !== ($state['policy_revision'] ?? null)) {
            throw new AssistantUnavailable('proposal_stale');
        }
        foreach ($state['data']['connections'] ?? [] as $connection) {
            if (! is_array($connection)) {
                throw new AssistantUnavailable('proposal_stale');
            }
            app(AiGovernance::class)->dispatchConnection($company, $actor, (string) $connection['public_id'],
                (string) $state['intent'], (string) $connection['capability'], (int) $connection['revision']);
        }

        if (isset($state['data']['request_id'])) {
            $request = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)
                ->whereKey($state['data']['request_id'])->first();
            if ($request === null || ! in_array($request->status, ['form_ready', 'clarifying', 'transcribed'], true)
                || $request->form_key !== $state['form_key']) {
                throw new AssistantUnavailable('proposal_stale');
            }
        }

        return $state;
    }

    /** @return array<string, mixed> */
    private function ownedState(Company $company, User $actor, string $token, string $sessionId, ?string $formKey = null): array
    {
        if (! Str::isUuid($token) || $sessionId === '') {
            throw new AssistantUnavailable('proposal_stale');
        }
        try {
            $ciphertext = Cache::get($this->key($token));
            if (! is_string($ciphertext)) {
                throw new AssistantUnavailable('proposal_stale');
            }
            $state = json_decode(Crypt::decryptString($ciphertext), true, 24, JSON_THROW_ON_ERROR);
            if (! is_array($state) || ($state['company_id'] ?? null) !== (int) $company->id
                || ($state['user_id'] ?? null) !== (int) $actor->id || ($state['expires_at'] ?? 0) <= now()->timestamp
                || ! hash_equals((string) ($state['session_hash'] ?? ''), hash('sha256', $sessionId))
                || ($formKey !== null && ($state['form_key'] ?? null) !== $formKey)) {
                throw new AssistantUnavailable('proposal_stale');
            }
        } catch (Throwable) {
            throw new AssistantUnavailable('proposal_stale');
        }

        return $state;
    }

    /** Compare-and-swap prevents stale inference or Undo from overwriting intervening manual edits.
     * @param  array<string, mixed>  $data
     */
    public function replace(Company $company, User $actor, string $token, string $sessionId, string $formKey, int $expectedRevision, array $data): int
    {
        return DB::transaction(function () use ($company, $actor, $token, $sessionId, $formKey, $expectedRevision, $data): int {
            // Match command/cancel order: company lock before the cache revision lock.
            $owned = $this->ownedState($company, $actor, $token, $sessionId, $formKey);
            app(AiGovernance::class)->authorizeUse($company, $actor, $owned['intent']);

            return Cache::lock($this->key($token).':lock', 10)->block(3, function () use ($company, $actor, $token, $sessionId, $formKey, $expectedRevision, $data): int {
                $state = $this->read($company, $actor, $token, $sessionId, $formKey);
                if ($state['revision'] !== $expectedRevision) {
                    throw new AssistantUnavailable('form_changed');
                }
                $state['revision'] = $expectedRevision + 1;
                $state['data'] = $data;
                $this->write($token, $state);

                return $state['revision'];
            });
        });
    }

    public function forget(Company $company, User $actor, string $token, string $sessionId): void
    {
        // Cleanup remains possible after revocation; ownership and session binding still apply.
        $this->ownedState($company, $actor, $token, $sessionId);
        $this->forgetForSession($actor, $token, $sessionId);
    }

    /** Cleanup only: no company data is returned, and ownership remains required after membership revocation. */
    public function forgetForSession(User $actor, string $token, string $sessionId): void
    {
        if (! Str::isUuid($token) || $sessionId === '') {
            throw new AssistantUnavailable('proposal_stale');
        }
        Cache::lock($this->key($token).':lock', 10)->block(3, function () use ($actor, $token, $sessionId): void {
            try {
                $ciphertext = Cache::get($this->key($token));
                if (! is_string($ciphertext)) {
                    return;
                }
                $state = json_decode(Crypt::decryptString($ciphertext), true, 24, JSON_THROW_ON_ERROR);
                if (! is_array($state) || ($state['user_id'] ?? null) !== (int) $actor->id
                    || ! hash_equals((string) ($state['session_hash'] ?? ''), hash('sha256', $sessionId))) {
                    throw new AssistantUnavailable('proposal_stale');
                }
                Cache::forget($this->key($token));
            } catch (Throwable) {
                throw new AssistantUnavailable('proposal_stale');
            }
        });
    }

    /** @param array<string, mixed> $state */
    private function write(string $token, array $state): void
    {
        $remaining = (int) $state['expires_at'] - now()->timestamp;
        if ($remaining <= 0) {
            throw new AssistantUnavailable('proposal_stale');
        }
        Cache::put($this->key($token), Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)), $remaining);
    }

    private function key(string $token): string
    {
        return 'ai-form-state:'.hash('sha256', $token);
    }
}
