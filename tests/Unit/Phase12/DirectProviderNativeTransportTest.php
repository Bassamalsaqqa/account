<?php

declare(strict_types=1);

namespace Tests\Unit\Phase12;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCredential;
use App\Infrastructure\AI\DirectProviderTransport;
use App\Infrastructure\AI\DirectTextProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Tests\Support\Phase12\LocalSyntheticHttpServer;
use Tests\TestCase;

class DirectProviderNativeTransportTest extends TestCase
{
    private static ?LocalSyntheticHttpServer $server = null;

    private static ?string $scenario = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$server = LocalSyntheticHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$scenario = null;
        self::$server->resetStats();

        Http::swap(new Factory);

        config([
            'ai_assistant.enabled' => true,
            'ai_assistant.provider' => 'direct',
            'ai_assistant.provider_approved' => true,
            'ai_assistant.speech_enabled' => true,
            'ai_assistant.max_audio_bytes' => 2000000,
            'ai_assistant.max_text_characters' => 4000,
            'ai_assistant.max_input_tokens' => 16000,
            'ai_assistant.max_output_tokens' => 4000,
            'ai_assistant.timeout_seconds' => 10,
        ]);

        // Production transport uses native cURL DNS and reconstructs destination URLs
        // from the server catalog. Test middleware verifies that the destination host
        // originates strictly from approved catalog endpoints before loopback reroute.
        Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
            $originalHost = $request->getUri()->getHost();
            $approvedHosts = ['api.deepseek.com', 'api.groq.com', 'api.openai.com', 'dashscope-intl.aliyuncs.com'];

            if (! in_array($originalHost, $approvedHosts, true)) {
                throw new LogicException("Untrusted destination host attempted: {$originalHost}");
            }

            $uri = $request->getUri()
                ->withScheme('http')
                ->withHost('127.0.0.1')
                ->withPort(self::$server->port());

            if (self::$scenario !== null) {
                $request = $request->withHeader('X-Synthetic-Scenario', self::$scenario);
            }

            return $request->withUri($uri);
        });
    }

    public function test_native_transport_chat_completion_success_with_sink_and_nonseekable_body(): void
    {
        self::$scenario = 'default';
        $route = app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
        $credential = new ProviderCredential('dummy-synthetic-credential-key');

        $result = app(DirectProviderTransport::class)->send($route, $credential, [
            'model' => 'deepseek-flash',
            'stream' => false,
            'messages' => [['role' => 'system', 'content' => 'Return JSON.'], ['role' => 'user', 'content' => 'hello world']],
            'max_tokens' => 100,
            'response_format' => ['type' => 'json_object'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame('stop', $result['choices'][0]['finish_reason'] ?? null);
        $this->assertSame('{"intent":"sales"}', $result['choices'][0]['message']['content'] ?? null);
        $this->assertSame(['prompt_tokens' => 10, 'completion_tokens' => 5], $result['usage'] ?? null);
        $this->assertSame(1, self::$server->requestCount());

        $lastReq = self::$server->lastRequest();
        $this->assertNotNull($lastReq);
        $this->assertStringStartsWith('POST /chat/completions', $lastReq['request_line']);
        $this->assertSame('Bearer dummy-synthetic-credential-key', $lastReq['headers']['authorization'] ?? null);
        $this->assertStringContainsString('application/json', $lastReq['headers']['content-type'] ?? '');
        $this->assertArrayNotHasKey('proxy', $lastReq['headers']);
        $this->assertArrayNotHasKey('proxy-authorization', $lastReq['headers']);
    }

    public function test_native_transport_audio_transcription_multipart_success(): void
    {
        self::$scenario = 'audio';
        $route = app(ProviderCapabilityRegistry::class)->resolve('groq', 'whisper-large-v3-turbo', 'global', 'audio_transcription');
        $credential = new ProviderCredential('dummy-synthetic-credential-key');
        $audioWav = $this->wav();

        $result = app(DirectProviderTransport::class)->send($route, $credential, [
            'model' => 'whisper-large-v3-turbo',
        ], $audioWav);

        $this->assertIsArray($result);
        $this->assertSame('تسجيل صوتي تجريبي', $result['text'] ?? null);
        $this->assertSame(1, self::$server->requestCount());

        $lastReq = self::$server->lastRequest();
        $this->assertNotNull($lastReq);
        $this->assertStringContainsString('/audio/transcriptions', $lastReq['request_line']);
        $this->assertStringContainsString('multipart/form-data', $lastReq['headers']['content-type'] ?? '');
    }

    public function test_native_transport_tolerates_deepseek_whitespace_keepalives(): void
    {
        self::$scenario = 'whitespace_keepalive';
        $route = app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
        $credential = new ProviderCredential('dummy-synthetic-credential-key');

        $result = app(DirectProviderTransport::class)->send($route, $credential, [
            'model' => 'deepseek-flash',
            'stream' => false,
            'messages' => [['role' => 'system', 'content' => 'Return JSON.'], ['role' => 'user', 'content' => 'test whitespace']],
            'max_tokens' => 100,
        ]);

        $this->assertIsArray($result);
        $this->assertSame('stop', $result['choices'][0]['finish_reason'] ?? null);
        $this->assertSame(1, self::$server->requestCount());
    }

    public function test_environment_proxy_cannot_poison_native_transport_to_closed_port(): void
    {
        $prevHttp = getenv('HTTP_PROXY');
        $prevHttps = getenv('HTTPS_PROXY');
        // Poison proxy pointing to closed port 9
        putenv('HTTP_PROXY=http://127.0.0.1:9');
        putenv('HTTPS_PROXY=http://127.0.0.1:9');

        try {
            self::$scenario = 'default';
            $route = app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
            $credential = new ProviderCredential('dummy-synthetic-credential-key');

            $result = app(DirectProviderTransport::class)->send($route, $credential, [
                'model' => 'deepseek-flash',
                'stream' => false,
                'messages' => [['role' => 'system', 'content' => 'Return JSON.'], ['role' => 'user', 'content' => 'proxy immunity check']],
                'max_tokens' => 100,
            ]);

            $this->assertIsArray($result);
            $this->assertSame('stop', $result['choices'][0]['finish_reason'] ?? null);
            $this->assertSame(1, self::$server->requestCount());
        } finally {
            putenv($prevHttp === false ? 'HTTP_PROXY' : 'HTTP_PROXY='.$prevHttp);
            putenv($prevHttps === false ? 'HTTPS_PROXY' : 'HTTPS_PROXY='.$prevHttps);
        }
    }

    public function test_native_302_redirect_is_rejected_without_followup_and_exactly_one_request(): void
    {
        self::$scenario = 'redirect_302';
        $route = app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
        $credential = new ProviderCredential('dummy-synthetic-credential-key');

        try {
            app(DirectProviderTransport::class)->send($route, $credential, [
                'model' => 'deepseek-flash',
                'stream' => false,
                'messages' => [['role' => 'system', 'content' => 'Return JSON.'], ['role' => 'user', 'content' => 'check redirect']],
                'max_tokens' => 100,
            ]);
            $this->fail('Native 302 redirect must not succeed.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }

        $this->assertSame(1, self::$server->requestCount(), 'Must not follow up redirect to closed port.');
    }

    public function test_native_transport_enforces_response_sink_byte_bound_with_single_request(): void
    {
        self::$scenario = 'oversized';
        $route = app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
        $credential = new ProviderCredential('dummy-synthetic-credential-key');

        try {
            app(DirectProviderTransport::class)->send($route, $credential, [
                'model' => 'deepseek-flash',
                'stream' => false,
                'messages' => [['role' => 'system', 'content' => 'Return JSON.'], ['role' => 'user', 'content' => 'request huge']],
                'max_tokens' => 100,
            ]);
            $this->fail('Oversized response must not succeed.');
        } catch (AssistantUnavailable $e) {
            // Write abort during transfer surfaces as provider_outage or provider_invalid; both terminate transfer
            $this->assertContains($e->reason, ['provider_outage', 'provider_invalid']);
        }

        $this->assertSame(1, self::$server->requestCount(), 'Must not retry failed oversized transfer.');
    }

    public function test_native_transport_maps_outage_status_codes_without_retry(): void
    {
        $route = app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
        $credential = new ProviderCredential('dummy-synthetic-credential-key');

        foreach (['outage_503', 'outage_429', 'outage_500'] as $scenario) {
            self::$server->resetStats();
            self::$scenario = $scenario;
            try {
                app(DirectProviderTransport::class)->send($route, $credential, [
                    'model' => 'deepseek-flash',
                    'stream' => false,
                    'messages' => [['role' => 'system', 'content' => 'Return JSON.'], ['role' => 'user', 'content' => 'check outage']],
                    'max_tokens' => 100,
                ]);
                $this->fail("Expected provider_outage for {$scenario}");
            } catch (AssistantUnavailable $e) {
                $this->assertSame($scenario === 'outage_429' ? 'provider_rate_limit' : 'provider_outage', $e->reason, "Scenario {$scenario} must retain its safe diagnostic");
            }
            $this->assertSame(1, self::$server->requestCount(), "Scenario {$scenario} must not retry");
        }
    }

    public function test_native_transport_maps_rejected_status_codes_without_retry(): void
    {
        $route = app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
        $credential = new ProviderCredential('dummy-synthetic-credential-key');

        foreach (['rejected_401', 'rejected_400', 'balance_402', 'invalid_parameters_422'] as $scenario) {
            self::$server->resetStats();
            self::$scenario = $scenario;
            try {
                app(DirectProviderTransport::class)->send($route, $credential, [
                    'model' => 'deepseek-flash',
                    'stream' => false,
                    'messages' => [['role' => 'system', 'content' => 'Return JSON.'], ['role' => 'user', 'content' => 'check rejected']],
                    'max_tokens' => 100,
                ]);
                $this->fail("Expected provider_rejected for {$scenario}");
            } catch (AssistantUnavailable $e) {
                $expected = ['rejected_401' => 'provider_authentication', 'rejected_400' => 'provider_format', 'balance_402' => 'provider_credit', 'invalid_parameters_422' => 'provider_parameters'];
                $this->assertSame($expected[$scenario], $e->reason, "Scenario {$scenario} must retain its safe diagnostic");
            }
            $this->assertSame(1, self::$server->requestCount(), "Scenario {$scenario} must not retry");
        }
    }

    public function test_native_transport_rejects_malformed_json_and_empty_response_without_retry(): void
    {
        $route = app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
        $credential = new ProviderCredential('dummy-synthetic-credential-key');

        foreach (['malformed_json', 'empty_response'] as $scenario) {
            self::$server->resetStats();
            self::$scenario = $scenario;
            try {
                app(DirectProviderTransport::class)->send($route, $credential, [
                    'model' => 'deepseek-flash',
                    'stream' => false,
                    'messages' => [['role' => 'system', 'content' => 'Return JSON.'], ['role' => 'user', 'content' => 'check invalid body']],
                    'max_tokens' => 100,
                ]);
                $this->fail("Expected provider_invalid for {$scenario}");
            } catch (AssistantUnavailable $e) {
                $this->assertSame('provider_invalid', $e->reason, "Scenario {$scenario} must map to provider_invalid");
            }
            $this->assertSame(1, self::$server->requestCount(), "Scenario {$scenario} must not retry");
        }
    }

    public function test_direct_text_provider_extract_end_to_end_via_native_transport(): void
    {
        self::$scenario = 'default';
        $credential = new ProviderCredential('dummy-synthetic-credential-key');
        $schema = [
            'type' => 'object',
            'properties' => ['intent' => ['type' => 'string']],
            'required' => ['intent'],
            'additionalProperties' => false,
        ];

        $result = app(DirectTextProvider::class)->extract(
            'deepseek',
            'deepseek-flash',
            'global',
            $credential,
            'فاتورة بيع تجريبية',
            'sales',
            $schema
        );

        $this->assertSame(['intent' => 'sales'], $result['proposal']);
        $this->assertSame('unknown', $result['cost_status']);
        $this->assertSame(['input_units' => 10, 'output_units' => 5], $result['usage']);
        $this->assertSame(1, self::$server->requestCount());
    }

    public function test_direct_text_provider_rejects_finish_reason_length_via_native_transport(): void
    {
        self::$scenario = 'finish_reason_length';
        $credential = new ProviderCredential('dummy-synthetic-credential-key');
        $schema = [
            'type' => 'object',
            'properties' => ['intent' => ['type' => 'string']],
            'required' => ['intent'],
            'additionalProperties' => false,
        ];

        try {
            app(DirectTextProvider::class)->extract(
                'deepseek',
                'deepseek-flash',
                'global',
                $credential,
                'فاتورة بيع تجريبية',
                'sales',
                $schema
            );
            $this->fail('Finish reason length must be refused by DirectTextProvider.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }

        $this->assertSame(1, self::$server->requestCount());
    }

    private function wav(): string
    {
        $data = str_repeat("\0", 16000);

        return 'RIFF'.pack('V', strlen($data) + 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($data)).$data;
    }
}
