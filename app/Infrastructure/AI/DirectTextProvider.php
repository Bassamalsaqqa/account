<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCredential;
use JsonException;

final class DirectTextProvider
{
    /**
     * Provider JSON is untrusted. The caller must validate schema, references and permissions.
     *
     * @param  array<string, mixed>  $schema
     * @return array{proposal: array<string, mixed>, usage: array<string, int>, cost_status: string}
     */
    public function extract(string $provider, string $model, string $region, ProviderCredential $credential, string $text, string $intent, array $schema): array
    {
        if (! in_array($intent, ['sales', 'purchase', 'auto', 'patch'], true)
            || mb_strlen($text) > (int) config('ai_assistant.max_text_characters') || trim($text) === '') {
            throw new AssistantUnavailable('input_invalid');
        }
        $route = app(ProviderCapabilityRegistry::class)->resolve($provider, $model, $region, 'text_structured');
        $format = $route['strict_json']
            ? ['type' => 'json_schema', 'json_schema' => ['name' => 'transaction_proposal', 'strict' => true, 'schema' => $schema]]
            : ['type' => 'json_object'];
        $schemaJson = json_encode($schema, JSON_THROW_ON_ERROR);
        $system = 'Return JSON only. No tools or database IDs. Decimal strings. Null for missing or uncertain fields. Allowed intent: '.$intent.'. '
            .($route['strict_json'] ? 'Follow the response schema.' : 'Schema: '.$schemaJson);
        // Byte-bound input conservatively rather than guessing a token count from characters.
        if (strlen($system) + strlen($text) + ($route['strict_json'] ? strlen($schemaJson) : 0) + 256 > (int) config('ai_assistant.max_input_tokens')) {
            throw new AssistantUnavailable('input_invalid');
        }
        $body = [
            'model' => $model, 'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $text],
            ],
            'response_format' => $format,
            'max_tokens' => (int) config('ai_assistant.max_output_tokens'),
        ];
        if ($provider === 'deepseek') {
            $body['thinking'] = ['type' => 'disabled'];
        }
        if ($provider === 'qwen') {
            $body['enable_thinking'] = false;
        }
        $result = app(DirectProviderTransport::class)->send($route, $credential, $body);
        $content = $result['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || strlen($content) > 65536
            || ($result['choices'][0]['finish_reason'] ?? '') !== 'stop') {
            throw new AssistantUnavailable('provider_invalid');
        }
        try {
            $proposal = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AssistantUnavailable('provider_invalid');
        }
        if (! is_array($proposal) || array_is_list($proposal)) {
            throw new AssistantUnavailable('provider_invalid');
        }
        $usage = [];
        foreach (['prompt_tokens' => 'input_units', 'completion_tokens' => 'output_units'] as $from => $to) {
            $count = $result['usage'][$from] ?? null;
            if ($count !== null) {
                if (! is_int($count) || $count < 0 || $count > (int) config('ai_assistant.'.($to === 'input_units' ? 'max_input_tokens' : 'max_output_tokens'))) {
                    throw new AssistantUnavailable('provider_invalid');
                }
                $usage[$to] = $count;
            }
        }

        return ['proposal' => $proposal, 'usage' => $usage, 'cost_status' => 'unknown'];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{plan: array<string, mixed>, usage: array<string, int>, cost_status: string}
     */
    public function planQuestion(string $provider, string $model, string $region, ProviderCredential $credential, string $text, array $schema): array
    {
        if (mb_strlen($text) > (int) config('ai_assistant.max_text_characters') || trim($text) === '') {
            throw new AssistantUnavailable('input_invalid');
        }
        $route = app(ProviderCapabilityRegistry::class)->resolve($provider, $model, $region, 'text_structured');
        $format = $route['strict_json']
            ? ['type' => 'json_schema', 'json_schema' => ['name' => 'assistant_report_plan', 'strict' => true, 'schema' => $schema]]
            : ['type' => 'json_object'];
        $schemaJson = json_encode($schema, JSON_THROW_ON_ERROR);
        $system = 'Return JSON only for question planning. Allowed report queries only. No tools or database IDs. '
            .($route['strict_json'] ? 'Follow the response schema.' : 'Schema: '.$schemaJson);
        if (strlen($system) + strlen($text) + ($route['strict_json'] ? strlen($schemaJson) : 0) + 256 > (int) config('ai_assistant.max_input_tokens')) {
            throw new AssistantUnavailable('input_invalid');
        }
        $body = [
            'model' => $model, 'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $text],
            ],
            'response_format' => $format,
            'max_tokens' => (int) config('ai_assistant.max_output_tokens'),
        ];
        if ($provider === 'deepseek') {
            $body['thinking'] = ['type' => 'disabled'];
        }
        if ($provider === 'qwen') {
            $body['enable_thinking'] = false;
        }
        $result = app(DirectProviderTransport::class)->send($route, $credential, $body);
        $content = $result['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || strlen($content) > 65536
            || ($result['choices'][0]['finish_reason'] ?? '') !== 'stop') {
            throw new AssistantUnavailable('provider_invalid');
        }
        try {
            $plan = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AssistantUnavailable('provider_invalid');
        }
        if (! is_array($plan) || array_is_list($plan)) {
            throw new AssistantUnavailable('provider_invalid');
        }
        $usage = [];
        foreach (['prompt_tokens' => 'input_units', 'completion_tokens' => 'output_units'] as $from => $to) {
            $count = $result['usage'][$from] ?? null;
            if ($count !== null) {
                if (! is_int($count) || $count < 0 || $count > (int) config('ai_assistant.'.($to === 'input_units' ? 'max_input_tokens' : 'max_output_tokens'))) {
                    throw new AssistantUnavailable('provider_invalid');
                }
                $usage[$to] = $count;
            }
        }

        return ['plan' => $plan, 'usage' => $usage, 'cost_status' => 'unknown'];
    }
}
