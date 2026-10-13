<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AiDiagnostics;
use App\Application\AI\AssistantIntentProposal;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCredential;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\DeepSeekProvider;
use Laravel\Ai\ToolChoice;
use Throwable;

final class DeepSeekSdkAdapter
{
    /**
     * One planning response only. Caller owns authorization, consent, reservations and local execution.
     * No SDK agent, tool handlers, tool results, conversation replay or automatic retry is involved.
     *
     * @param  array<string, mixed>  $body
     * @param  array<array-key, mixed>  $approvedReportKeys  Validated here as a list of report keys; caller must authorize each for this turn.
     * @return array{proposals: list<array{id: string, name: string, arguments: array<string, mixed>}>, usage: array{input_units: int, output_units: int}}
     */
    public function sendToolIntents(ProviderCredential $credential, array $body, array $approvedReportKeys): array
    {
        if (config('ai_assistant.enabled') !== true || config('ai_assistant.provider_approved') !== true
            || config('ai_assistant.provider') !== 'direct') {
            throw new AssistantUnavailable('disabled');
        }
        if (! extension_loaded('curl')) {
            throw new AssistantUnavailable('provider_configuration');
        }
        $messages = $body['messages'] ?? null;
        $limit = $body['max_tokens'] ?? null;
        $bodyKeys = array_keys($body);
        sort($bodyKeys);
        if ($bodyKeys !== ['max_tokens', 'messages'] || ! is_array($messages) || ! array_is_list($messages) || count($messages) !== 2
            || ($messages[0]['role'] ?? null) !== 'system' || ($messages[1]['role'] ?? null) !== 'user'
            || ! is_string($messages[0]['content'] ?? null) || ! is_string($messages[1]['content'] ?? null)
            || trim($messages[0]['content']) === '' || trim($messages[1]['content']) === ''
            || strlen($messages[0]['content']) > 24000 || mb_strlen($messages[1]['content']) > 4000
            || ! is_int($limit) || $limit < 1 || $limit > (int) config('ai_assistant.max_output_tokens')
            || ! array_is_list($approvedReportKeys) || count($approvedReportKeys) > count(AssistantReportCatalog::KEYS)
            || count(array_unique($approvedReportKeys, SORT_REGULAR)) !== count($approvedReportKeys)) {
            throw new AssistantUnavailable('input_invalid');
        }
        $validatedReportKeys = [];
        foreach ($approvedReportKeys as $reportKey) {
            if (! is_string($reportKey) || ! in_array($reportKey, AssistantReportCatalog::KEYS, true)) {
                throw new AssistantUnavailable('input_invalid');
            }
            $validatedReportKeys[] = $reportKey;
        }
        $approvedReportKeys = $validatedReportKeys;
        $tools = [DeepSeekPlanningTool::clarify()];
        if ($approvedReportKeys !== []) {
            $tools[] = DeepSeekPlanningTool::report($approvedReportKeys);
        }
        foreach ($messages as $message) {
            $keys = array_keys($message);
            sort($keys);
            if ($keys !== ['content', 'role']) {
                throw new AssistantUnavailable('input_invalid');
            }
        }
        // Whole prompt + reviewed SDK schemas: byte-bound tokens conservatively, including framing.
        // Reporting results and stored company records must never be placed in this input.
        $definitions = array_map(fn (DeepSeekPlanningTool $tool): array => ['type' => 'function', 'function' => [
            'name' => $tool->name(), 'description' => $tool->description(),
            'parameters' => (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema(),
        ]], $tools);
        $encoded = json_encode(['messages' => $messages, 'tools' => $definitions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || strlen($encoded) + 256 > (int) config('ai_assistant.max_input_tokens')) {
            throw new AssistantUnavailable('input_invalid');
        }
        try {
            return $credential->use(function (string $key) use ($messages, $limit, $tools, $approvedReportKeys): array {
                $events = new Dispatcher;
                $provider = new DeepSeekProvider(['name' => 'accounting_deepseek', 'driver' => 'deepseek', 'key' => $key], $events);
                $step = (new BoundedDeepSeekGateway($events))->generateTextStep($provider, 'deepseek-flash',
                    $messages[0]['content'], [new UserMessage($messages[1]['content'])], $tools, null,
                    new TextGenerationOptions(toolChoice: new ToolChoice(ToolChoice::required),
                        providerOptions: ['max_tokens' => $limit, 'stream' => false, 'thinking' => ['type' => 'disabled']]),
                    null, new StepContext(isFinalStep: true));

                return $this->validateToolResponse($step->raw?->json(), $limit, $approvedReportKeys);
            });
        } catch (AssistantUnavailable $e) {
            AiDiagnostics::record($e, $e->failureStage ?? 'provider_response');
            throw $e;
        } catch (ConnectionException|ProviderConnectionException) {
            $failure = new AssistantUnavailable('provider_timeout', failureStage: 'provider_transport');
            AiDiagnostics::record($failure, 'provider_transport');
            throw $failure;
        } catch (Throwable) {
            $failure = new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
            AiDiagnostics::record($failure, 'provider_response');
            throw $failure;
        }
    }

    /**
     * @param  list<string>  $approvedReportKeys
     * @return array{proposals: list<array{id: string, name: string, arguments: array<string, mixed>}>, usage: array{input_units: int, output_units: int}}
     */
    private function validateToolResponse(mixed $data, int $limit, array $approvedReportKeys): array
    {
        $choices = is_array($data) ? ($data['choices'] ?? null) : null;
        if (! is_array($choices) || ! array_is_list($choices) || count($choices) !== 1
            || ($choices[0]['finish_reason'] ?? null) !== 'tool_calls'
            || ($choices[0]['message']['role'] ?? null) !== 'assistant') {
            throw new AssistantUnavailable('provider_invalid');
        }
        $message = $choices[0]['message'];
        if (isset($message['content']) && (! is_string($message['content']) || strlen($message['content']) > 4000)) {
            throw new AssistantUnavailable('provider_invalid');
        }
        $calls = $message['tool_calls'] ?? null;
        if (! is_array($calls) || ! array_is_list($calls) || count($calls) < 1 || count($calls) > 3) {
            throw new AssistantUnavailable('provider_invalid');
        }
        $proposals = [];
        $seen = [];
        foreach ($calls as $call) {
            $id = $call['id'] ?? null;
            $name = $call['function']['name'] ?? null;
            $json = $call['function']['arguments'] ?? null;
            if (($call['type'] ?? null) !== 'function' || ! is_string($id)
                || ! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) || isset($seen[$id])
                || ! in_array($name, ['run_report', 'clarify'], true)
                || ! is_string($json) || strlen($json) > 8192) {
                throw new AssistantUnavailable('provider_invalid');
            }
            $seen[$id] = true;
            $decoded = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof \stdClass) {
                throw new AssistantUnavailable('provider_invalid');
            }
            $arguments = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if ($name === 'run_report') {
                if (! ($decoded->filters ?? null) instanceof \stdClass
                    || ! in_array($arguments['report_key'] ?? null, $approvedReportKeys, true)) {
                    throw new AssistantUnavailable('provider_invalid');
                }
                try {
                    AssistantIntentProposal::fromArray($arguments);
                } catch (AssistantUnavailable) {
                    throw new AssistantUnavailable('provider_invalid');
                }
            } else {
                $keys = array_keys($arguments);
                sort($keys);
                if ($keys !== ['reason', 'version'] || $arguments['version'] !== 1
                    || ! in_array($arguments['reason'], DeepSeekPlanningTool::CLARIFICATION_REASONS, true)
                    || count($calls) !== 1) {
                    throw new AssistantUnavailable('provider_invalid');
                }
            }
            $proposals[] = ['id' => $id, 'name' => $name, 'arguments' => $arguments];
        }
        $input = $data['usage']['prompt_tokens'] ?? null;
        $output = $data['usage']['completion_tokens'] ?? null;
        if (! is_int($input) || $input < 0 || $input > (int) config('ai_assistant.max_input_tokens')
            || ! is_int($output) || $output < 0 || $output > $limit) {
            throw new AssistantUnavailable('provider_invalid');
        }

        return ['proposals' => $proposals, 'usage' => ['input_units' => $input, 'output_units' => $output]];
    }

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
            AiDiagnostics::record($e, $e->failureStage ?? 'provider_response');
            throw $e;
        } catch (ConnectionException) {
            $failure = new AssistantUnavailable('provider_timeout', failureStage: 'provider_transport');
            AiDiagnostics::record($failure, 'provider_transport');
            throw $failure;
        } catch (Throwable) {
            // SDK/provider exceptions can include raw payloads. Never chain or propagate them.
            $failure = new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
            AiDiagnostics::record($failure, 'provider_response');
            throw $failure;
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
