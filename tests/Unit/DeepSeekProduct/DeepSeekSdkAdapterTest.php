<?php

declare(strict_types=1);

namespace Tests\Unit\DeepSeekProduct;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCredential;
use App\Infrastructure\AI\DeepSeekSdkAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeepSeekSdkAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_assistant.enabled' => true, 'ai_assistant.provider_approved' => true, 'ai_assistant.provider' => 'direct']);
        Http::preventStrayRequests();
    }

    private function verifyConnection(): array
    {
        return app(DeepSeekSdkAdapter::class)->testConnection(new ProviderCredential('synthetic-never-real-key'));
    }

    public function test_sdk_builds_one_fixed_no_tool_request_and_preserves_usage(): void
    {
        Http::fake(['https://api.deepseek.com/*' => Http::response([
            'model' => 'deepseek-flash', 'choices' => [['message' => ['content' => '{"status":"ok"}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 14, 'completion_tokens' => 5],
        ])]);
        $this->assertSame(['input_units' => 14, 'output_units' => 5], $this->verifyConnection());
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://api.deepseek.com/chat/completions', $request->url());
            $this->assertSame('deepseek-flash', $request['model']);
            $this->assertSame(32, $request['max_tokens']);
            $this->assertSame(['type' => 'disabled'], $request['thinking']);
            $this->assertSame(['type' => 'json_object'], $request['response_format']);
            $this->assertFalse($request['stream']);
            $this->assertArrayNotHasKey('max_completion_tokens', $request->data());
            $this->assertArrayNotHasKey('tools', $request->data());

            return true;
        });
    }

    public static function statuses(): array
    {
        return [[400, 'provider_format'], [401, 'provider_authentication'], [402, 'provider_credit'],
            [422, 'provider_parameters'], [429, 'provider_rate_limit'], [503, 'provider_outage'], [302, 'provider_invalid']];
    }

    #[DataProvider('statuses')]
    public function test_errors_are_sanitized_distinct_and_never_retried(int $status, string $reason): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'PRIVATE_PROVIDER_CONTENT']], $status)]);
        try {
            $this->verifyConnection();
            $this->fail('Provider errors must fail.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($status, $e->httpStatus);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_CONTENT', (string) $e);
        }
        Http::assertSentCount(1);
    }

    public static function invalidReplies(): array
    {
        return [
            ['{"status":"wrong"}', ['prompt_tokens' => 5, 'completion_tokens' => 2]],
            ['{"status":"ok"}', []], ['{"status":"ok"}', ['prompt_tokens' => 5, 'completion_tokens' => 33]],
            ['[]', ['prompt_tokens' => 5, 'completion_tokens' => 2]],
        ];
    }

    #[DataProvider('invalidReplies')]
    public function test_verification_requires_exact_reply_and_real_bounded_usage(string $reply, array $usage): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $reply], 'finish_reason' => 'stop']], 'usage' => $usage])]);
        $this->expectException(AssistantUnavailable::class);
        $this->verifyConnection();
    }

    public function test_disabled_test_cannot_bypass_dispatch_switches(): void
    {
        config(['ai_assistant.enabled' => false]);
        try {
            $this->verifyConnection();
            $this->fail('Disabled dispatch must fail.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('disabled', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_oversized_response_fails_before_sdk_parsing(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 131073))]);
        $this->expectException(AssistantUnavailable::class);
        $this->verifyConnection();
    }
}
