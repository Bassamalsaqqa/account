<?php

declare(strict_types=1);

namespace Tests\Unit\AiV22;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCredential;
use App\Infrastructure\AI\DeepSeekPlanningTool;
use App\Infrastructure\AI\DeepSeekSdkAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SdkToolIntentContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_assistant.enabled' => true, 'ai_assistant.provider_approved' => true,
            'ai_assistant.provider' => 'direct', 'ai_assistant.max_input_tokens' => 6000,
            'ai_assistant.max_output_tokens' => 4000]);
        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> */
    private static function arguments(): array
    {
        return ['version' => 1, 'type' => 'report_query', 'report_key' => 'sales.summary',
            'filters' => ['preset' => 'this_month'], 'display' => 'summary'];
    }

    /** @param array<string, mixed>|null $arguments
     * @return array<string, mixed> */
    private static function toolCall(string $id = 'call_1', string $name = 'run_report', ?array $arguments = null): array
    {
        return ['id' => $id, 'type' => 'function', 'function' => ['name' => $name,
            'arguments' => json_encode($arguments ?? self::arguments(), JSON_THROW_ON_ERROR)]];
    }

    /** @return array<string, mixed> */
    private static function reply(): array
    {
        return ['model' => 'deepseek-flash', 'choices' => [['message' => ['role' => 'assistant',
            'content' => null, 'tool_calls' => [self::toolCall()]], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40]];
    }

    /** @param list<string> $reports
     * @param array<string, mixed>|null $body
     * @return array<string, mixed> */
    private function plan(array $reports = ['sales.summary'], ?array $body = null): array
    {
        return app(DeepSeekSdkAdapter::class)->sendToolIntents(new ProviderCredential('synthetic-never-real-key'),
            $body ?? ['messages' => [['role' => 'system', 'content' => 'Propose reviewed read tools only.'],
                ['role' => 'user', 'content' => 'How much did we sell this month?']], 'max_tokens' => 100], $reports);
    }

    public function test_one_sdk_response_returns_proposals_and_usage_without_tool_execution_or_result_egress(): void
    {
        Http::fake(['*' => Http::response(self::reply())]);
        $result = $this->plan();
        $this->assertSame(['input_units' => 100, 'output_units' => 40], $result['usage']);
        $this->assertSame([['id' => 'call_1', 'name' => 'run_report', 'arguments' => self::arguments()]], $result['proposals']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://api.deepseek.com/chat/completions', $request->url());
            $this->assertSame('deepseek-flash', $request['model']);
            $this->assertSame(['type' => 'disabled'], $request['thinking']);
            $this->assertSame('required', $request['tool_choice']);
            $this->assertSame(100, $request['max_tokens']);
            $this->assertFalse($request['stream']);
            $this->assertArrayNotHasKey('response_format', $request->data());
            $this->assertArrayNotHasKey('max_completion_tokens', $request->data());
            $this->assertCount(2, $request['messages']);
            $this->assertSame(['system', 'user'], array_column($request['messages'], 'role'));
            $this->assertSame(['clarify', 'run_report'], array_column(array_column($request['tools'], 'function'), 'name'));
            $function = $request['tools'][1]['function'];
            $this->assertSame(['sales.summary'], $function['parameters']['properties']['report_key']['enum']);
            $this->assertFalse($function['parameters']['additionalProperties']);
            $this->assertFalse($function['parameters']['properties']['filters']['additionalProperties']);

            return true;
        });
        // Any high-level SDK tool execution would hit this fail-closed handler rather than return proposals.
        $this->expectException(\LogicException::class);
        DeepSeekPlanningTool::clarify()->handle(new \Laravel\Ai\Tools\Request([]));
    }

    public function test_multiple_read_proposals_are_returned_without_second_model_round(): void
    {
        $reply = self::reply();
        $previous = self::arguments();
        $previous['filters']['preset'] = 'last_month';
        $reply['choices'][0]['message']['tool_calls'][] = self::toolCall('call_2', arguments: $previous);
        Http::fake(['*' => Http::response($reply)]);
        $this->assertCount(2, $this->plan()['proposals']);
        Http::assertSentCount(1);
    }

    public function test_tool_call_only_response_with_absent_content_is_valid(): void
    {
        $reply = self::reply();
        unset($reply['choices'][0]['message']['content']);
        Http::fake(['*' => Http::response($reply)]);
        $this->assertCount(1, $this->plan()['proposals']);
        Http::assertSentCount(1);
    }

    public function test_no_report_permission_exposes_only_static_clarification_schema(): void
    {
        $reply = self::reply();
        $reply['choices'][0]['message']['tool_calls'] = [self::toolCall(name: 'clarify', arguments: ['version' => 1, 'reason' => 'intent'])];
        Http::fake(['*' => Http::response($reply)]);
        $this->assertSame('clarify', $this->plan([])['proposals'][0]['name']);
        Http::assertSent(fn (Request $request): bool => count($request['tools']) === 1
            && $request['tools'][0]['function']['name'] === 'clarify');
        Http::assertSentCount(1);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidReplies(): array
    {
        $cases = [];
        foreach (['stop', 'length', 'content_filter', 'unknown'] as $finish) {
            $reply = self::reply();
            $reply['choices'][0]['finish_reason'] = $finish;
            $cases['finish_'.$finish] = [$reply];
        }
        foreach (['missing', 'negative', 'over_input', 'over_output', 'string'] as $usage) {
            $reply = self::reply();
            $reply['usage'] = match ($usage) {
                'missing' => [], 'negative' => ['prompt_tokens' => -1, 'completion_tokens' => 1],
                'over_input' => ['prompt_tokens' => 6001, 'completion_tokens' => 1],
                'over_output' => ['prompt_tokens' => 1, 'completion_tokens' => 101],
                default => ['prompt_tokens' => '1', 'completion_tokens' => 1],
            };
            $cases['usage_'.$usage] = [$reply];
        }
        foreach (['unknown', 'duplicate', 'too_many', 'empty', 'wrong_type', 'wrong_role', 'bad_id',
            'bad_json', 'list_json', 'numeric_json', 'unexpected_argument', 'raw_id', 'url',
            'unapproved_report', 'invalid_filter', 'clarify_and_read', 'clarify_private_prose', 'extra_choice'] as $kind) {
            $reply = self::reply();
            $call = self::toolCall();
            $args = self::arguments();
            switch ($kind) {
                case 'unknown': $call['function']['name'] = 'execute_sql';
                    break;
                case 'duplicate': $reply['choices'][0]['message']['tool_calls'][] = $call;
                    break;
                case 'too_many': $reply['choices'][0]['message']['tool_calls'] = [self::toolCall('a'), self::toolCall('b'), self::toolCall('c'), self::toolCall('d')];
                    break;
                case 'empty': $reply['choices'][0]['message']['tool_calls'] = [];
                    break;
                case 'wrong_type': $call['type'] = 'sql';
                    break;
                case 'wrong_role': $reply['choices'][0]['message']['role'] = 'user';
                    break;
                case 'bad_id': $call['id'] = 'https://example.test';
                    break;
                case 'bad_json': $call['function']['arguments'] = '{';
                    break;
                case 'list_json': $call['function']['arguments'] = '[]';
                    break;
                case 'numeric_json': $call['function']['arguments'] = '2';
                    break;
                case 'unexpected_argument': $args['company_id'] = 5;
                    break;
                case 'raw_id': $args['filters']['customer_id'] = '5';
                    break;
                case 'url': $args['filters']['url'] = 'https://example.test';
                    break;
                case 'unapproved_report': $args['report_key'] = 'vendors.balances';
                    break;
                case 'invalid_filter': $args['filters']['preset'] = 'whatever';
                    break;
                case 'clarify_and_read': $reply['choices'][0]['message']['tool_calls'][] = self::toolCall('b', 'clarify', ['version' => 1, 'reason' => 'entity']);
                    break;
                case 'clarify_private_prose': $call = self::toolCall(name: 'clarify', arguments: ['version' => 1, 'reason' => 'entity', 'message' => 'PRIVATE_MODEL_PROSE']);
                    break;
                case 'extra_choice': $reply['choices'][] = $reply['choices'][0];
                    break;
            }
            if ($args !== self::arguments()) {
                $call['function']['arguments'] = json_encode($args, JSON_THROW_ON_ERROR);
            }
            if (! in_array($kind, ['too_many', 'empty'], true)) {
                $reply['choices'][0]['message']['tool_calls'][0] = $call;
            }
            $cases[$kind] = [$reply];
        }

        return $cases;
    }

    /** @param array<string, mixed> $reply */
    #[DataProvider('invalidReplies')]
    public function test_malformed_or_unapproved_proposals_and_usage_fail_without_retry(array $reply): void
    {
        Http::fake(['*' => Http::response($reply)]);
        try {
            $this->plan();
            $this->fail('Untrusted response must fail closed.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('PRIVATE_MODEL_PROSE', (string) $e);
        }
        Http::assertSentCount(1);
    }

    public function test_input_boundary_refuses_history_and_invalid_report_allowlists_before_dispatch(): void
    {
        foreach ([['arbitrary.sql'], ['sales.summary', 'sales.summary'], [5]] as $reports) {
            try {
                $this->plan($reports);
                $this->fail('Invalid allowlist must fail.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('input_invalid', $e->reason);
            }
        }
        try {
            $this->plan(body: ['messages' => [['role' => 'system', 'content' => 'Static'],
                ['role' => 'user', 'content' => 'Question'], ['role' => 'tool', 'content' => 'PRIVATE_REPORT_RESULT']], 'max_tokens' => 100]);
            $this->fail('Tool result history must be refused.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('input_invalid', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_disable_switch_refuses_planning_without_dispatch(): void
    {
        config(['ai_assistant.enabled' => false]);
        try {
            $this->plan();
            $this->fail('Disabled planning must fail.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('disabled', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_entire_prompt_and_static_tool_schemas_are_bounded_before_dispatch(): void
    {
        try {
            $this->plan(body: ['messages' => [['role' => 'system', 'content' => str_repeat('s', 5500)],
                ['role' => 'user', 'content' => 'Question']], 'max_tokens' => 100]);
            $this->fail('Schema-inclusive input exposure must be bounded.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('input_invalid', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_unknown_body_overrides_cannot_enter_the_sdk_request(): void
    {
        try {
            $this->plan(body: ['messages' => [['role' => 'system', 'content' => 'Static'],
                ['role' => 'user', 'content' => 'Question']], 'max_tokens' => 100, 'endpoint' => 'https://example.test']);
            $this->fail('Caller protocol overrides must be refused.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('input_invalid', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_http_error_is_sanitized_and_never_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'PRIVATE_PROVIDER_BODY']], 402)]);
        try {
            $this->plan();
            $this->fail('Provider credit refusal must fail.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_credit', $e->reason);
            $this->assertSame(402, $e->httpStatus);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_BODY', (string) $e);
        }
        Http::assertSentCount(1);
    }

    public function test_timeout_is_sanitized_without_retry_or_tool_execution(): void
    {
        Http::fake(fn () => throw new ConnectionException('PRIVATE_TRANSPORT_DETAILS'));
        try {
            $this->plan();
            $this->fail('Provider timeout must fail.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_timeout', $e->reason);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('PRIVATE_TRANSPORT_DETAILS', (string) $e);
        }
    }

    public function test_response_body_cap_is_preserved_for_tool_planning(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 131073))]);
        try {
            $this->plan();
            $this->fail('Oversized response must fail.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }
        Http::assertSentCount(1);
    }
}
