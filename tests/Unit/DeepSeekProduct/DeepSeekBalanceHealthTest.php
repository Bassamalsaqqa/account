<?php

declare(strict_types=1);

namespace Tests\Unit\DeepSeekProduct;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCredential;
use App\Infrastructure\AI\DeepSeekBalanceHealth;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeepSeekBalanceHealthTest extends TestCase
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

    private function credential(string $secret = 'synthetic-never-real-key-12345'): ProviderCredential
    {
        return new ProviderCredential($secret);
    }

    private function health(): DeepSeekBalanceHealth
    {
        return app(DeepSeekBalanceHealth::class);
    }

    /** @return array<string, mixed> */
    private function validBalancePayload(bool $available = true): array
    {
        return [
            'is_available' => $available,
            'balance_infos' => [
                [
                    'currency' => 'USD',
                    'total_balance' => '10.50',
                    'granted_balance' => '0.00',
                    'topped_up_balance' => '10.50',
                ],
                [
                    'currency' => 'CNY',
                    'total_balance' => '100.00',
                    'granted_balance' => '20.00',
                    'topped_up_balance' => '80.00',
                ],
            ],
        ];
    }

    public function test_2xx_available_returns_key_valid_funds_available(): void
    {
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::response($this->validBalancePayload(true), 200),
        ]);

        $result = $this->health()->check($this->credential());

        $this->assertSame('key_valid_funds_available', $result);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://api.deepseek.com/user/balance', $request->url());
            $this->assertSame('GET', $request->method());
            $this->assertSame('Bearer synthetic-never-real-key-12345', $request->header('Authorization')[0]);
            $this->assertContains('application/json', $request->header('Accept'));

            return true;
        });
    }

    public function test_2xx_no_credit_returns_no_available_credit(): void
    {
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::response($this->validBalancePayload(false), 200),
        ]);

        $result = $this->health()->check($this->credential());

        $this->assertSame('no_available_credit', $result);
        Http::assertSentCount(1);
    }

    #[DataProvider('emptyBalanceAvailability')]
    public function test_valid_empty_balance_list_honors_availability(bool $available, string $expected): void
    {
        Http::fake(fn () => Http::response(['is_available' => $available, 'balance_infos' => []]));
        $this->assertSame($expected, $this->health()->check($this->credential()));
        Http::assertSentCount(1);
    }

    /** @return list<array{bool, string}> */
    public static function emptyBalanceAvailability(): array
    {
        return [[false, 'no_available_credit'], [true, 'key_valid_funds_available']];
    }

    /** @return list<array{int, string}> */
    public static function httpErrorStatuses(): array
    {
        return [
            [401, 'provider_authentication'],
            [429, 'provider_rate_limit'],
            [500, 'provider_outage'],
            [502, 'provider_outage'],
            [503, 'provider_outage'],
            [504, 'provider_outage'],
            [400, 'provider_format'],
            [422, 'provider_parameters'],
            [403, 'provider_invalid'],
            [404, 'provider_invalid'],
        ];
    }

    #[DataProvider('httpErrorStatuses')]
    public function test_http_error_statuses_are_mapped_safely_and_distinctly(int $status, string $expectedReason): void
    {
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::response(['error' => ['message' => 'INTERNAL_SECRET_ERROR']], $status),
        ]);

        try {
            $this->health()->check($this->credential());
            $this->fail("Expected AssistantUnavailable for HTTP {$status}");
        } catch (AssistantUnavailable $e) {
            $this->assertSame($expectedReason, $e->diagnosticReason());
            $this->assertSame($status, $e->httpStatus);
            $this->assertStringNotContainsString('INTERNAL_SECRET_ERROR', (string) $e);
        }

        Http::assertSentCount(1);
    }

    public function test_timeout_throws_provider_timeout_without_raw_payload(): void
    {
        $called = false;
        Http::fake(function () use (&$called) {
            $called = true;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        try {
            $this->health()->check($this->credential());
            $this->fail('Expected AssistantUnavailable for connection timeout');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_timeout', $e->diagnosticReason());
            $this->assertNull($e->httpStatus);
            $this->assertStringNotContainsString('cURL error 28', (string) $e);
        }

        $this->assertTrue($called);
    }

    public function test_oversized_response_is_rejected_before_unbounded_decode(): void
    {
        $oversizedBody = str_repeat(' ', DeepSeekBalanceHealth::MAX_RESPONSE_BYTES + 1);
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::response($oversizedBody, 200),
        ]);

        try {
            $this->health()->check($this->credential());
            $this->fail('Expected AssistantUnavailable for oversized response');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->diagnosticReason());
        }

        Http::assertSentCount(1);
    }

    public function test_fake_stream_chunked_oversize_is_rejected_before_decode(): void
    {
        $stream = Utils::streamFor(str_repeat('x', DeepSeekBalanceHealth::MAX_RESPONSE_BYTES + 100));
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::response($stream, 200),
        ]);

        try {
            $this->health()->check($this->credential());
            $this->fail('Expected AssistantUnavailable for oversized stream');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->diagnosticReason());
        }

        Http::assertSentCount(1);
    }

    public function test_malformed_json_is_rejected(): void
    {
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::response('{invalid-json-content', 200),
        ]);

        try {
            $this->health()->check($this->credential());
            $this->fail('Expected AssistantUnavailable for malformed JSON');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->diagnosticReason());
        }

        Http::assertSentCount(1);
    }

    /** @return array<string, array{string}> */
    public static function invalidShapes(): array
    {
        return [
            'non-array json' => ['"string-only"'],
            'array list json' => ['["item1"]'],
            'missing is_available' => [json_encode(['balance_infos' => [['currency' => 'USD', 'total_balance' => '0.00', 'granted_balance' => '0.00', 'topped_up_balance' => '0.00']]])],
            'string is_available' => [json_encode(['is_available' => 'true', 'balance_infos' => [['currency' => 'USD', 'total_balance' => '0.00', 'granted_balance' => '0.00', 'topped_up_balance' => '0.00']]])],
            'integer is_available' => [json_encode(['is_available' => 1, 'balance_infos' => [['currency' => 'USD', 'total_balance' => '0.00', 'granted_balance' => '0.00', 'topped_up_balance' => '0.00']]])],
            'missing balance_infos' => [json_encode(['is_available' => true])],
            'non-array balance_infos' => [json_encode(['is_available' => true, 'balance_infos' => 'not-array'])],
            'empty object balance_infos' => ['{"is_available":true,"balance_infos":{}}'],
            'assoc balance_infos' => [json_encode(['is_available' => true, 'balance_infos' => ['key' => 'val']])],
            'too many balance_infos (>10)' => [json_encode(['is_available' => true, 'balance_infos' => array_fill(0, 11, ['currency' => 'USD', 'total_balance' => '0.00', 'granted_balance' => '0.00', 'topped_up_balance' => '0.00'])])],
            'missing currency' => [json_encode(['is_available' => true, 'balance_infos' => [['total_balance' => '0.00', 'granted_balance' => '0.00', 'topped_up_balance' => '0.00']]])],
            'lowercase currency' => [json_encode(['is_available' => true, 'balance_infos' => [['currency' => 'usd', 'total_balance' => '0.00', 'granted_balance' => '0.00', 'topped_up_balance' => '0.00']]])],
            'missing total_balance' => [json_encode(['is_available' => true, 'balance_infos' => [['currency' => 'USD', 'granted_balance' => '0.00', 'topped_up_balance' => '0.00']]])],
            'float total_balance' => [json_encode(['is_available' => true, 'balance_infos' => [['currency' => 'USD', 'total_balance' => 10.5, 'granted_balance' => '0.00', 'topped_up_balance' => '0.00']]])],
            'non-numeric string balance' => [json_encode(['is_available' => true, 'balance_infos' => [['currency' => 'USD', 'total_balance' => 'invalid-num', 'granted_balance' => '0.00', 'topped_up_balance' => '0.00']]])],
            'null granted_balance' => [json_encode(['is_available' => true, 'balance_infos' => [['currency' => 'USD', 'total_balance' => '0.00', 'granted_balance' => null, 'topped_up_balance' => '0.00']]])],
        ];
    }

    #[DataProvider('invalidShapes')]
    public function test_invalid_json_shapes_are_rejected(string $body): void
    {
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::response($body, 200),
        ]);

        try {
            $this->health()->check($this->credential());
            $this->fail('Expected AssistantUnavailable for invalid shape');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->diagnosticReason());
        }

        Http::assertSentCount(1);
    }

    public function test_no_exact_balance_exposed_in_result_or_credential(): void
    {
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::response([
                'is_available' => true,
                'balance_infos' => [
                    [
                        'currency' => 'USD',
                        'total_balance' => '999999.99',
                        'granted_balance' => '111111.11',
                        'topped_up_balance' => '888888.88',
                    ],
                ],
            ], 200),
        ]);

        $cred = $this->credential();
        $result = $this->health()->check($cred);

        $this->assertSame('key_valid_funds_available', $result);
        $this->assertStringNotContainsString('999999.99', $result);
        $this->assertSame(['secret' => '[REDACTED]'], $cred->__debugInfo());
    }

    public function test_client_enforces_tls_timeouts_and_security_options(): void
    {
        $captured = null;
        Http::fake(function (Request $request, array $options) use (&$captured) {
            $captured = $options;
            $this->assertSame(DeepSeekBalanceHealth::ENDPOINT, $request->url());
            $this->assertSame('GET', $request->method());

            return Http::response($this->validBalancePayload());
        });
        $this->health()->check($this->credential());
        $this->assertNotNull($captured);
        $this->assertTrue($captured['verify']);
        $this->assertFalse($captured['allow_redirects']);
        $this->assertSame('', $captured['proxy']);
        $this->assertTrue($captured['stream']);
        $this->assertLessThanOrEqual(5, $captured['connect_timeout']);
        $this->assertLessThanOrEqual(10, $captured['timeout']);
        $this->assertSame(5, $captured['read_timeout']);
        $this->assertSame(5, $captured['curl'][CURLOPT_LOW_SPEED_TIME]);
        Http::assertSentCount(1);
    }
}
