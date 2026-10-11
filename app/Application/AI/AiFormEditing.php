<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\Company;
use App\Models\User;

final class AiFormEditing
{
    /** @return array<string, mixed> */
    public function adopt(Company $company, User $actor, string $token, string $sessionId, string $intent): array
    {
        $store = app(AiFormState::class);
        $state = $store->read($company, $actor, $token, $sessionId);
        if (isset($state['data']['replacement']) || $state['intent'] !== $intent || ! in_array(($state['data']['lifecycle'] ?? null), ['handoff', 'editing'], true)
            || ($state['data']['resolved']['questions'] ?? null) !== []) {
            throw new AssistantUnavailable('proposal_stale');
        }
        $data = $state['data'];
        if ($data['lifecycle'] === 'handoff') {
            $data['payload'] = $data['resolved']['payload'];
        }
        $data['lifecycle'] = 'editing';
        $data['payload_hash'] = $this->hash($data['payload']);
        $revision = $store->replace($company, $actor, $token, $sessionId, $state['form_key'], $state['revision'], $data);

        return ['form_key' => $state['form_key'], 'revision' => $revision, 'data' => $data];
    }

    /** Manual edits synchronize memory without invoking providers or business writers.
     * @param  array<string, mixed>  $payload
     */
    public function synchronize(Company $company, User $actor, string $token, string $sessionId, string $formKey, int $revision, array $payload): int
    {
        $store = app(AiFormState::class);
        $state = $store->read($company, $actor, $token, $sessionId, $formKey);
        if ($state['revision'] !== $revision || ($state['data']['lifecycle'] ?? null) !== 'editing') {
            throw new AssistantUnavailable('form_changed');
        }
        $hash = $this->hash($payload);
        if (hash_equals($state['data']['payload_hash'], $hash)) {
            return $revision;
        }
        $data = $state['data'];
        $this->manualOrigins($payload, $data['payload'], '', $data['resolved']['origins']);
        $data['resolved']['unresolved'] = array_values(array_filter($data['resolved']['unresolved'], fn ($path) => in_array(data_get($payload, $path), [null, '', []], true)));
        $data['payload'] = $payload;
        $data['payload_hash'] = $hash;
        $data['last_change'] = 'manual';
        unset($data['undo']);
        $data['changed_fields'] = [];

        return $store->replace($company, $actor, $token, $sessionId, $formKey, $revision, $data);
    }

    /** @param array<string, mixed> $payload */
    public function hash(array $payload): string
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65536) {
            throw new AssistantUnavailable('input_invalid');
        }
        $this->rejectFloats($payload);

        return hash('sha256', $encoded);
    }

    /** @param array<array-key, mixed> $current
     * @param  array<array-key, mixed>  $previous
     * @param  array<string, string>  $origins
     */
    private function manualOrigins(array $current, array $previous, string $prefix, array &$origins): void
    {
        foreach ($current as $field => $value) {
            $path = $prefix === '' ? (string) $field : $prefix.'.'.$field;
            if (is_array($value)) {
                $before = $previous[$field] ?? [];
                $this->manualOrigins($value, is_array($before) ? $before : [], $path, $origins);
            } elseif ($value !== ($previous[$field] ?? null)) {
                $origins[$path] = 'human_edit';
            }
        }
    }

    private function rejectFloats(mixed $value): void
    {
        if (is_float($value) || is_object($value) || is_resource($value)) {
            throw new AssistantUnavailable('input_invalid');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->rejectFloats($item);
            }
        }
    }
}
