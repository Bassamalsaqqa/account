<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class AiTranscriptRevisions
{
    /** Reinterpret only after explicit user submission; never mutate the current form.
     * @param  array<string, mixed>  $payload
     */
    public function prepare(Company $company, User $actor, string $token, string $session, string $formKey,
        int $revision, array $payload, string $text): string
    {
        $store = app(AiFormState::class);
        $state = $store->read($company, $actor, $token, $session, $formKey);
        $this->current($state, $revision, $payload);
        $connectionId = null;
        foreach ($state['data']['connections'] as $connection) {
            if ($connection['capability'] === 'text_structured') {
                $connectionId = $connection['public_id'];
                break;
            }
        }
        $nextForm = (string) Str::uuid();
        $result = app(TransactionAssistant::class)->prepare($company, $actor, $text, $state['intent'],
            $nextForm, (string) Str::uuid(), $session, $connectionId);
        try {
            if (! in_array($result['state'], ['handoff', 'clarifying'], true)) {
                throw new AssistantUnavailable($result['state'] === 'unsupported' ? 'intent_unsupported' : 'intent_uncertain');
            }
            $original = $store->read($company, $actor, $token, $session, $formKey);
            $this->current($original, $revision, $payload);
            $next = $store->read($company, $actor, $result['token'], $session, $nextForm);
            $data = $next['data'];
            $data['connections'] = [...$original['data']['connections'], ...$data['connections']];
            $data['replacement'] = ['token' => $token, 'form_key' => $formKey, 'revision' => $revision,
                'payload_hash' => app(AiFormEditing::class)->hash($payload)];
            $store->replace($company, $actor, $result['token'], $session, $nextForm, $next['revision'], $data);

            return $result['token'];
        } catch (Throwable $e) {
            app(TransactionAssistant::class)->cancel($company, $actor, $nextForm, $session);
            throw $e;
        }
    }

    /** A short acknowledgement checks the latest human fields before any navigation.
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function acknowledge(Company $company, User $actor, string $token, string $session, string $formKey,
        string $prepared, array $payload): array
    {
        return DB::transaction(function () use ($company, $actor, $token, $session, $formKey, $prepared, $payload): array {
            $store = app(AiFormState::class);
            $next = $store->read($company, $actor, $prepared, $session);
            $binding = $next['data']['replacement'] ?? [];
            if (($binding['token'] ?? null) !== $token || ($binding['form_key'] ?? null) !== $formKey
                || ($next['data']['lifecycle'] ?? null) === 'editing') {
                throw new AssistantUnavailable('proposal_stale');
            }
            $original = $store->read($company, $actor, $token, $session, $formKey);
            $this->current($original, $binding['revision'], $payload);
            if (! hash_equals($binding['payload_hash'], app(AiFormEditing::class)->hash($payload))
                || $next['intent'] !== $original['intent']) {
                throw new AssistantUnavailable('form_changed');
            }
            $questions = $next['data']['resolved']['questions'];
            if ($questions !== []) {
                return ['state' => 'clarifying', 'token' => $prepared, 'questions' => $questions];
            }
            if (($next['data']['lifecycle'] ?? null) !== 'handoff') {
                throw new AssistantUnavailable('proposal_stale');
            }
            $data = $next['data'];
            unset($data['replacement']);
            $store->replace($company, $actor, $prepared, $session, $next['form_key'], $next['revision'], $data);
            $store->forget($company, $actor, $token, $session);

            return ['state' => 'handoff', 'token' => $prepared, 'intent' => $next['intent']];
        });
    }

    /** @param array<string, mixed> $payload
     * @param  array<array-key, mixed>  $choices
     */
    public function clarify(Company $company, User $actor, string $token, string $session, string $formKey,
        string $prepared, array $payload, array $choices): string
    {
        $next = app(AiFormState::class)->read($company, $actor, $prepared, $session);
        if (($next['data']['lifecycle'] ?? null) !== 'clarifying') {
            throw new AssistantUnavailable('proposal_stale');
        }
        $this->acknowledge($company, $actor, $token, $session, $formKey, $prepared, $payload);
        app(TransactionAssistant::class)->clarify($company, $actor, $prepared, $session, $next['form_key'], $choices);

        return $prepared;
    }

    public function discard(Company $company, User $actor, string $prepared, string $session, string $formKey): void
    {
        $next = app(AiFormState::class)->read($company, $actor, $prepared, $session);
        if (($next['data']['replacement']['form_key'] ?? null) !== $formKey) {
            throw new AssistantUnavailable('proposal_stale');
        }
        app(TransactionAssistant::class)->cancel($company, $actor, $next['form_key'], $session);
    }

    /** @param array<string, mixed> $state
     * @param  array<string, mixed>  $payload
     */
    private function current(array $state, int $revision, array $payload): void
    {
        if (($state['data']['lifecycle'] ?? null) !== 'editing' || $state['revision'] !== $revision
            || ! hash_equals($state['data']['payload_hash'], app(AiFormEditing::class)->hash($payload))) {
            throw new AssistantUnavailable('form_changed');
        }
    }
}
