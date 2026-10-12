<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\AI\AiDiagnostics;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCredential;
use App\Infrastructure\AI\BoundedDeepSeekGateway;
use App\Infrastructure\AI\DirectTextProvider;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Providers\DeepSeekProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeepSeekOnlyTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_assistant.enabled' => true, 'ai_assistant.provider' => 'direct', 'ai_assistant.provider_approved' => true]);
        Http::preventStrayRequests();
    }

    public function test_catalog_contains_exactly_one_reviewed_text_route(): void
    {
        $registry = app(ProviderCapabilityRegistry::class);
        $this->assertSame(['deepseek'], array_keys($registry->catalog()));
        $route = $registry->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
        $this->assertSame(['deepseek-flash' => ['text_structured']], $route['models']);
        $this->assertSame(['global' => 'https://api.deepseek.com'], $route['regions']);
        $this->assertSame('https://api.deepseek.com', $route['base_url']);
        $this->assertSame(ProviderCapabilityRegistry::VERSION, $route['catalog_version']);
        $this->assertFalse($route['streaming']);
        $this->assertFalse($route['audio_to_structured']);
        Http::assertNothingSent();
    }

    public static function retiredRoutes(): array
    {
        return [
            ['openai', 'gpt-4o-mini', 'global', 'text_structured'],
            ['qwen', 'qwen-plus', 'beijing', 'text_structured'],
            ['qwen', 'qwen-plus', 'singapore', 'text_structured'],
            ['groq', 'whisper-large-v3', 'global', 'audio_transcription'],
            ['openai', 'whisper-1', 'global', 'audio_transcription'],
            ['deepseek', 'deepseek-flash', 'global', 'audio_transcription'],
            ['deepseek', 'deepseek-reasoner', 'global', 'text_structured'],
            ['deepseek', 'deepseek-flash', 'beijing', 'text_structured'],
            ['https://example.invalid', 'deepseek-flash', 'global', 'text_structured'],
        ];
    }

    #[DataProvider('retiredRoutes')]
    public function test_retired_or_client_supplied_routes_fail_before_dispatch(string $provider, string $model, string $region, string $capability): void
    {
        try {
            app(ProviderCapabilityRegistry::class)->resolve($provider, $model, $region, $capability);
            $this->fail('Only the internal DeepSeek text route is supported.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_configuration', $e->reason);
        }
        Http::assertNothingSent();
    }

    public static function workflows(): array
    {
        return [['sales'], ['purchase'], ['auto'], ['patch'], ['question']];
    }

    #[DataProvider('workflows')]
    public function test_all_supported_text_workflows_use_one_sdk_request_and_preserve_exact_payload_and_usage(string $workflow): void
    {
        $proposal = ['intent' => $workflow, 'amount' => '0.010000'];
        Http::fake(['https://api.deepseek.com/*' => Http::response($this->reply(json_encode($proposal, JSON_THROW_ON_ERROR)))]);
        $result = $this->runWorkflow($workflow);
        $this->assertSame($proposal, $result[$workflow === 'question' ? 'plan' : 'proposal']);
        $this->assertSame(['input_units' => 17, 'output_units' => 8], $result['usage']);
        $this->assertSame('unknown', $result['cost_status']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://api.deepseek.com/chat/completions', $request->url());
            $this->assertSame('deepseek-flash', $request['model']);
            $this->assertSame(['type' => 'json_object'], $request['response_format']);
            $this->assertSame(['type' => 'disabled'], $request['thinking']);
            $this->assertFalse($request['stream']);
            $this->assertCount(2, $request['messages']);
            $this->assertStringContainsString('Schema:', $request['messages'][0]['content']);
            $this->assertArrayNotHasKey('tools', $request->data());
            $this->assertArrayNotHasKey('enable_thinking', $request->data());

            return true;
        });
    }

    public static function invalidReplies(): array
    {
        return [
            ['{invalid', 'stop', ['prompt_tokens' => 17, 'completion_tokens' => 8]],
            ['[]', 'stop', ['prompt_tokens' => 17, 'completion_tokens' => 8]],
            ['"plain text"', 'stop', ['prompt_tokens' => 17, 'completion_tokens' => 8]],
            ['{"intent":"sales"}', 'length', ['prompt_tokens' => 17, 'completion_tokens' => 8]],
            [str_repeat('x', 65537), 'stop', ['prompt_tokens' => 17, 'completion_tokens' => 8]],
            ['{"intent":"sales"}', 'stop', ['prompt_tokens' => -1, 'completion_tokens' => 8]],
            ['{"intent":"sales"}', 'stop', ['prompt_tokens' => 6001, 'completion_tokens' => 8]],
            ['{"intent":"sales"}', 'stop', ['prompt_tokens' => 17, 'completion_tokens' => 4001]],
            ['{"intent":"sales"}', 'stop', ['prompt_tokens' => '17', 'completion_tokens' => 8]],
            ['{"intent":"sales"}', 'stop', ['prompt_tokens' => 17, 'completion_tokens' => 8.5]],
        ];
    }

    #[DataProvider('invalidReplies')]
    public function test_untrusted_response_json_completion_and_token_limits_fail_without_retry(string $content, string $finish, array $usage): void
    {
        Http::fake(['*' => Http::response($this->reply($content, $finish, $usage))]);
        try {
            $this->runWorkflow('sales');
            $this->fail('Invalid provider output must be rejected.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }
        Http::assertSentCount(1);
    }

    #[DataProvider('workflows')]
    public function test_schema_and_text_byte_budget_is_enforced_before_any_dispatch(string $workflow): void
    {
        config(['ai_assistant.max_input_tokens' => 300]);
        try {
            $this->runWorkflow($workflow, ['type' => 'object', 'description' => str_repeat('x', 301)]);
            $this->fail('The full schema and input must fit the conservative byte budget.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('input_invalid', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_stale_non_deepseek_selection_is_not_reinterpreted_as_the_approved_route(): void
    {
        foreach (['extract', 'planQuestion'] as $method) {
            try {
                if ($method === 'extract') {
                    app(DirectTextProvider::class)->extract('qwen', 'qwen-plus', 'singapore', $this->credential(), 'Sale', 'sales', ['type' => 'object']);
                } else {
                    app(DirectTextProvider::class)->planQuestion('openai', 'gpt-4o-mini', 'global', $this->credential(), 'Sales total', ['type' => 'object']);
                }
                $this->fail('A retired route must not silently change provider.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('provider_configuration', $e->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_blank_oversized_and_unknown_intent_inputs_are_refused_locally(): void
    {
        foreach ([['sales', ' '], ['sales', str_repeat('x', 4001)], ['payroll', 'Salary'], ['question', ' ']] as [$workflow, $text]) {
            try {
                $provider = app(DirectTextProvider::class);
                if ($workflow === 'question') {
                    $provider->planQuestion('deepseek', 'deepseek-flash', 'global', $this->credential(), $text, ['type' => 'object']);
                } else {
                    $provider->extract('deepseek', 'deepseek-flash', 'global', $this->credential(), $text, $workflow, ['type' => 'object']);
                }
                $this->fail('Invalid input must not dispatch or broaden the operation.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('input_invalid', $e->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_sdk_gateway_preserves_fixed_https_tls_dns_and_no_proxy_redirect_controls(): void
    {
        $gateway = new BoundedDeepSeekGateway(new Dispatcher);
        $provider = new DeepSeekProvider(['name' => 'synthetic', 'driver' => 'deepseek', 'key' => 'synthetic-never-real-key'], new Dispatcher);
        $client = (new \ReflectionMethod($gateway, 'client'))->invoke($gateway, $provider);
        $options = $client->getOptions();
        $this->assertSame('https://api.deepseek.com/', (new \ReflectionProperty($client, 'baseUrl'))->getValue($client));
        $this->assertTrue($options['verify']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertLessThanOrEqual(25, $options['timeout']);
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $options['curl']);
        Http::assertNothingSent();
    }

    public function test_diagnostics_keep_only_safe_http_status_and_stage(): void
    {
        Log::shouldReceive('notice')->once()->with('AI provider request refused.',
            ['failure_code' => 'provider_authentication', 'stage' => 'provider_http', 'http_status' => 401]);
        AiDiagnostics::record(new AssistantUnavailable('provider_authentication', 401), 'provider_http');
        AiDiagnostics::record(new AssistantUnavailable('private raw body', 401), 'private raw stage');
        $this->addToAssertionCount(1);
        Http::assertNothingSent();
    }

    private function runWorkflow(string $workflow, array $schema = ['type' => 'object']): array
    {
        $provider = app(DirectTextProvider::class);

        return $workflow === 'question'
            ? $provider->planQuestion('deepseek', 'deepseek-flash', 'global', $this->credential(), 'Sales total', $schema)
            : $provider->extract('deepseek', 'deepseek-flash', 'global', $this->credential(), 'Sale', $workflow, $schema);
    }

    private function credential(): ProviderCredential
    {
        return new ProviderCredential('synthetic-never-real-key');
    }

    private function reply(string $content, string $finish = 'stop', array $usage = ['prompt_tokens' => 17, 'completion_tokens' => 8]): array
    {
        return ['model' => 'deepseek-flash', 'choices' => [['message' => ['content' => $content], 'finish_reason' => $finish]], 'usage' => $usage];
    }
}
