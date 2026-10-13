<?php

declare(strict_types=1);

namespace Tests\Unit\DeepSeekProduct;

use App\Application\AI\AssistantUnavailable;
use App\Infrastructure\AI\DeepSeekHttpBoundary;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

class DeepSeekHttpBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai_assistant.enabled' => true,
            'ai_assistant.provider_approved' => true,
            'ai_assistant.provider' => 'direct',
            'ai_assistant.timeout_seconds' => 15,
        ]);
        Http::preventStrayRequests();
    }

    public function test_fixed_endpoint_options_tls_and_no_redirects_proxy_retries(): void
    {
        $sink = null;
        $client = DeepSeekHttpBoundary::createRequest('synthetic-test-key-12345', DeepSeekHttpBoundary::CHAT_MAX_BYTES, 10, null, $sink);

        $reflection = new \ReflectionProperty($client, 'baseUrl');
        $this->assertSame('https://api.deepseek.com/', $reflection->getValue($client));

        $options = $client->getOptions();
        $this->assertTrue($options['verify'], 'TLS verification must be enabled');
        $this->assertFalse($options['allow_redirects'], 'Redirects must be disabled');
        $this->assertSame('', $options['proxy'], 'Proxy must be disabled');
        $this->assertFalse($options['expect'], 'Expect header must be disabled');
        $this->assertTrue($options['stream'], 'Streaming must be enabled');
        $this->assertSame(DeepSeekHttpBoundary::CONNECT_TIMEOUT_SECONDS, $options['connect_timeout']);
        $this->assertLessThanOrEqual(10, $options['timeout']);
        $this->assertSame(DeepSeekHttpBoundary::READ_TIMEOUT_SECONDS, $options['read_timeout']);
        $this->assertSame(DeepSeekHttpBoundary::LOW_SPEED_LIMIT_BYTES, $options['curl'][CURLOPT_LOW_SPEED_LIMIT]);
        $this->assertSame(DeepSeekHttpBoundary::LOW_SPEED_TIME_SECONDS, $options['curl'][CURLOPT_LOW_SPEED_TIME]);
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $options['curl'], 'Must use native DNS resolution');

        $sink->close();
    }

    public function test_streaming_sink_rejects_oversized_payload_during_write(): void
    {
        $sink = null;
        DeepSeekHttpBoundary::createRequest('synthetic-test-key-12345', 100, 10, null, $sink);
        $this->assertInstanceOf(StreamInterface::class, $sink);

        // Writing within bound succeeds
        $sink->write(str_repeat('a', 50));

        // Writing that exceeds the byte cap immediately throws AssistantUnavailable
        $this->expectException(AssistantUnavailable::class);
        try {
            $sink->write(str_repeat('b', 51));
        } finally {
            $sink->close();
        }
    }

    public function test_empty_stalled_body_timeout_throws_provider_timeout(): void
    {
        $stalledStream = FnStream::decorate(Utils::streamFor(''), [
            'eof' => fn () => false,
            'read' => fn (int $length) => '',
        ]);
        $response = new Response(new PsrResponse(200, [], $stalledStream));

        try {
            DeepSeekHttpBoundary::readBoundedBody($response, DeepSeekHttpBoundary::BALANCE_MAX_BYTES);
            $this->fail('Expected AssistantUnavailable for stalled stream read');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_timeout', $e->diagnosticReason());
            $this->assertSame('provider_transport', $e->failureStage);
        }
    }

    public function test_read_bounded_body_rejects_oversized_response(): void
    {
        $oversizedBody = str_repeat('x', DeepSeekHttpBoundary::BALANCE_MAX_BYTES + 10);
        $response = new Response(new PsrResponse(200, [], Utils::streamFor($oversizedBody)));

        try {
            DeepSeekHttpBoundary::readBoundedBody($response, DeepSeekHttpBoundary::BALANCE_MAX_BYTES);
            $this->fail('Expected AssistantUnavailable for oversized response');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->diagnosticReason());
            $this->assertSame('provider_response', $e->failureStage);
        }
    }

    /** @return list<array{int, string}> */
    public static function httpErrorStatuses(): array
    {
        return [
            [400, 'provider_format'],
            [401, 'provider_authentication'],
            [402, 'provider_credit'],
            [408, 'provider_timeout'],
            [422, 'provider_parameters'],
            [429, 'provider_rate_limit'],
            [500, 'provider_outage'],
            [502, 'provider_outage'],
            [503, 'provider_outage'],
            [504, 'provider_outage'],
            [403, 'provider_invalid'],
            [404, 'provider_invalid'],
        ];
    }

    #[DataProvider('httpErrorStatuses')]
    public function test_sanitized_distinct_http_error_mappings(int $status, string $expectedReason): void
    {
        $response = new Response(new PsrResponse(
            $status,
            ['Content-Type' => 'application/json'],
            '{"error":{"message":"CONFIDENTIAL_KEY_LEAK_IN_PROVIDER"}}'
        ));

        try {
            DeepSeekHttpBoundary::assertSuccessfulResponse($response);
            $this->fail("Expected AssistantUnavailable for HTTP {$status}");
        } catch (AssistantUnavailable $e) {
            $this->assertSame($expectedReason, $e->diagnosticReason());
            $this->assertSame($status, $e->httpStatus);
            $this->assertSame('provider_http', $e->failureStage);
            $this->assertStringNotContainsString('CONFIDENTIAL_KEY_LEAK_IN_PROVIDER', (string) $e);
        }
    }

    public function test_chat_request_after_response_accepts_valid_json_object(): void
    {
        Http::fake([
            'https://api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '{"intent":"sales"}'], 'finish_reason' => 'stop'],
                ],
            ], 200),
        ]);

        $client = DeepSeekHttpBoundary::createChatRequest('synthetic-test-key-12345');
        $response = $client->post('chat/completions', ['messages' => []]);

        $this->assertTrue($response->successful());
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertSame('stop', $data['choices'][0]['finish_reason']);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string}> */
    public static function invalidChatBodies(): array
    {
        return [
            'raw list' => ['["item1","item2"]'],
            'string literal' => ['"just a string"'],
            'malformed json' => ['{unclosed json'],
            'empty body' => [''],
        ];
    }

    #[DataProvider('invalidChatBodies')]
    public function test_chat_request_after_response_rejects_non_object_or_malformed_json(string $body): void
    {
        Http::fake([
            'https://api.deepseek.com/*' => Http::response($body, 200),
        ]);

        $client = DeepSeekHttpBoundary::createChatRequest('synthetic-test-key-12345');

        $this->expectException(AssistantUnavailable::class);
        $client->post('chat/completions', ['messages' => []]);
    }

    public function test_request_local_streams_are_isolated_and_never_share_state(): void
    {
        $sink1 = null;
        $sink2 = null;

        $client1 = DeepSeekHttpBoundary::createRequest('synthetic-test-key-1', 1000, 10, null, $sink1);
        $client2 = DeepSeekHttpBoundary::createRequest('synthetic-test-key-2', 1000, 10, null, $sink2);

        $this->assertNotSame($sink1, $sink2, 'Each request must receive its own distinct sink instance');

        $sink1->write('payload-for-first');
        $this->assertSame('payload-for-first', (string) $sink1);
        $this->assertSame('', (string) $sink2, 'Second stream must remain unaffected by writes to the first');

        $sink1->close();
        $sink2->close();
    }
}
