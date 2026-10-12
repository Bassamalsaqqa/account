<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCredential;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\DeepSeekProvider;
use Throwable;

final class DeepSeekSdkAdapter
{
    /** @param array<string, mixed> $route
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function send(array $route, ProviderCredential $credential, array $body): array
    {
        if (config('ai_assistant.enabled') !== true || config('ai_assistant.provider_approved') !== true
            || config('ai_assistant.provider') !== 'direct') {
            throw new AssistantUnavailable('disabled');
        }
        $route = app(ProviderCapabilityRegistry::class)->resolve((string) ($route['provider'] ?? ''),
            (string) ($route['model'] ?? ''), (string) ($route['region'] ?? ''), (string) ($route['capability'] ?? ''));
        if ($route['provider'] !== 'deepseek' || $route['capability'] !== 'text_structured' || ! extension_loaded('curl')) {
            throw new AssistantUnavailable('provider_configuration');
        }
        $messages = $body['messages'] ?? [];
        $limit = $body['max_tokens'] ?? null;
        if (count($messages) !== 2 || ($messages[0]['role'] ?? null) !== 'system' || ($messages[1]['role'] ?? null) !== 'user'
            || ! is_string($messages[0]['content'] ?? null) || ! is_string($messages[1]['content'] ?? null)
            || ! is_int($limit) || $limit < 1 || $limit > (int) config('ai_assistant.max_output_tokens')) {
            throw new AssistantUnavailable('input_invalid');
        }
        try {
            return $credential->use(function (string $key) use ($messages, $limit): array {
                // Request-local provider and event dispatcher: no global config/credential cache,
                // conversation persistence, tools, high-level agent events, fallback or retries.
                $events = new Dispatcher;
                $provider = new DeepSeekProvider(['name' => 'accounting_deepseek', 'driver' => 'deepseek', 'key' => $key], $events);
                $gateway = new BoundedDeepSeekGateway($events);
                $step = $gateway->generateTextStep($provider, 'deepseek-flash', $messages[0]['content'],
                    [new UserMessage($messages[1]['content'])], [], null,
                    new TextGenerationOptions(providerOptions: ['max_tokens' => $limit, 'stream' => false,
                        'thinking' => ['type' => 'disabled'], 'response_format' => ['type' => 'json_object']]),
                    null, new StepContext(isFinalStep: true));
                $data = $step->raw?->json();
                if (! is_array($data) || $step->toolCalls !== [] || strlen($step->text) > 65536) {
                    throw new AssistantUnavailable('provider_invalid');
                }

                return $data;
            });
        } catch (AssistantUnavailable $e) {
            throw $e;
        } catch (ConnectionException) {
            throw new AssistantUnavailable('provider_timeout');
        } catch (Throwable) {
            // SDK/provider exceptions can include raw payloads. Never chain or propagate them.
            throw new AssistantUnavailable('provider_invalid');
        }
    }

    /** @return array<string, int> */
    public function testConnection(ProviderCredential $credential): array
    {
        $route = app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
        $result = $this->send($route, $credential, ['messages' => [
            ['role' => 'system', 'content' => 'Return JSON only: {"status":"ok"}'],
            ['role' => 'user', 'content' => 'Connection test.'],
        ], 'max_tokens' => 32]);
        if (($result['choices'][0]['finish_reason'] ?? '') !== 'stop'
            || json_decode($result['choices'][0]['message']['content'] ?? '', true) !== ['status' => 'ok']) {
            throw new AssistantUnavailable('provider_invalid');
        }
        $usage = [];
        foreach (['prompt_tokens' => 'input_units', 'completion_tokens' => 'output_units'] as $source => $target) {
            $value = $result['usage'][$source] ?? null;
            if (! is_int($value) || $value < 0 || $value > ($target === 'output_units' ? 32 : (int) config('ai_assistant.max_input_tokens'))) {
                throw new AssistantUnavailable('provider_invalid');
            }
            $usage[$target] = $value;
        }

        return $usage;
    }
}
