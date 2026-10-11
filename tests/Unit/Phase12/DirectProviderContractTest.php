<?php

declare(strict_types=1);

namespace Tests\Unit\Phase12;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCostEstimate;
use App\Application\AI\ProviderCredential;
use App\Infrastructure\AI\DirectProviderTransport;
use App\Infrastructure\AI\DirectSpeechProvider;
use App\Infrastructure\AI\DirectTextProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class DirectProviderContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['ai_assistant.enabled' => true, 'ai_assistant.provider' => 'direct', 'ai_assistant.provider_approved' => true,
            'ai_assistant.speech_enabled' => true]);
    }

    public function test_catalog_rejects_wrong_capability_region_model_and_unreviewed_provider(): void
    {
        foreach ([
            ['deepseek', 'deepseek-flash', 'global', 'audio_transcription'],
            ['qwen', 'qwen3-asr-flash', 'singapore', 'text_structured'],
            ['openai', 'unknown', 'global', 'text_structured'],
            ['openai', 'gpt-4o-mini', 'private', 'text_structured'],
            ['evil', 'model', 'global', 'text_structured'],
        ] as $selection) {
            try {
                app(ProviderCapabilityRegistry::class)->resolve(...$selection);
                $this->fail('Invalid catalog selection must fail.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('provider_configuration', $e->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_deepseek_and_qwen_use_json_object_and_openai_uses_strict_schema(): void
    {
        $schema = ['type' => 'object', 'properties' => ['intent' => ['type' => 'string']],
            'required' => ['intent'], 'additionalProperties' => false];
        foreach ([['deepseek', 'deepseek-flash', 'global'], ['qwen', 'qwen-plus', 'singapore'], ['openai', 'gpt-4o-mini', 'global']] as [$provider, $model, $region]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"intent":"unsupported"}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 8]])]);
            $result = app(DirectTextProvider::class)->extract($provider, $model, $region, $this->credential(), 'An unsupported receipt', 'auto', $schema);
            $this->assertSame(['intent' => 'unsupported'], $result['proposal']);
            $this->assertSame('unknown', $result['cost_status']);
            $this->assertSame(['input_units' => 15, 'output_units' => 8], $result['usage']);
            Http::assertSent(function (Request $request) use ($provider, $model): bool {
                $this->assertSame($model, $request['model']);
                $this->assertFalse($request['stream']);
                $this->assertSame($provider === 'openai' ? 'json_schema' : 'json_object', $request['response_format']['type']);
                if ($provider === 'openai') {
                    $this->assertTrue($request['response_format']['json_schema']['strict']);
                }
                $this->assertCount(2, $request['messages']);
                $this->assertArrayNotHasKey('tools', $request->data());

                return true;
            });
        }
    }

    public function test_groq_and_openai_send_actual_wav_multipart_and_never_model_audio_urls(): void
    {
        foreach ([['groq', 'whisper-large-v3-turbo'], ['openai', 'gpt-4o-mini-transcribe']] as [$provider, $model]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['text' => 'شراء كرتون صابون'])]);
            $result = app(DirectSpeechProvider::class)->transcribe($provider, $model, 'global', $this->credential(), $this->wav(), 'mixed');
            $this->assertSame('شراء كرتون صابون', $result['text']);
            $this->assertSame('unknown', $result['cost_status']);
            Http::assertSent(function (Request $request) use ($model): bool {
                $this->assertStringEndsWith('/audio/transcriptions', $request->url());
                $parts = collect($request->data())->keyBy('name');
                $this->assertSame($model, $parts['model']['contents']);
                $this->assertTrue($request->hasFile('file', $this->wav(), 'recording.wav'));
                $this->assertFalse($parts->has('language'));

                return true;
            });
        }
    }

    public function test_qwen_asr_uses_region_specific_json_data_uri_without_multipart(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'اشتريت صابون']]]])]);
        $result = app(DirectSpeechProvider::class)->transcribe('qwen', 'qwen3-asr-flash', 'singapore', $this->credential(), $this->wav(), 'ar');
        $this->assertSame('اشتريت صابون', $result['text']);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions', $request->url());
            $this->assertSame('qwen3-asr-flash', $request['model']);
            $this->assertSame(['enable_itn' => false, 'language' => 'ar'], $request['asr_options']);
            $this->assertFalse($request->hasFile('file'));
            $data = $request['messages'][0]['content'][0]['input_audio']['data'];
            $this->assertSame('data:audio/wav;base64,'.base64_encode($this->wav()), $data);

            return true;
        });
    }

    public function test_disabled_transport_and_unapproved_provider_send_nothing(): void
    {
        $route = app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
        foreach ([['ai_assistant.enabled', false, 'disabled'], ['ai_assistant.provider_approved', false, 'disabled']] as [$key, $value, $reason]) {
            $original = config($key);
            config([$key => $value]);
            try {
                app(DirectProviderTransport::class)->send($route, $this->credential(), []);
                $this->fail('Unsafe transport must refuse.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame($reason, $e->reason);
            }
            config([$key => $original]);
        }
        Http::assertNothingSent();
    }

    public function test_outage_and_invalid_output_are_distinct_and_never_retry(): void
    {
        $schema = ['type' => 'object'];
        foreach ([[503, [], 'provider_outage'], [401, [], 'provider_rejected'],
            [200, ['choices' => [['message' => ['content' => '{invalid'], 'finish_reason' => 'stop']]], 'provider_invalid'],
            [200, ['choices' => [['message' => ['content' => '{}'], 'finish_reason' => 'length']]], 'provider_invalid']] as [$status, $body, $reason]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response($body, $status)]);
            try {
                app(DirectTextProvider::class)->extract('deepseek', 'deepseek-flash', 'global', $this->credential(), 'Sale', 'sales', $schema);
                $this->fail('Invalid inference must refuse.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertStringNotContainsString('synthetic-key', $e->getMessage());
            }
            Http::assertSentCount(1);
        }
    }

    public function test_secret_envelope_cannot_serialize_or_debug_and_estimates_round_up_billing_floor(): void
    {
        $credential = $this->credential();
        $this->assertStringNotContainsString('synthetic-key', print_r($credential, true));
        try {
            serialize($credential);
            $this->fail('Secrets must not serialize.');
        } catch (LogicException $e) {
            $this->assertStringNotContainsString('synthetic-key', $e->getMessage());
        }
        $estimate = app(ProviderCostEstimate::class);
        $this->assertSame(2, $estimate->audio('groq', 1000, 7));
        $this->assertSame(1, $estimate->audio('openai', 1000, 7));
        $this->assertSame(7, $estimate->audio('groq', 60000, 7));
        $this->assertSame(1, $estimate->tokens(1, 1, 1, 1));
        Http::assertNothingSent();
    }

    private function credential(): ProviderCredential
    {
        return new ProviderCredential('synthetic-key-no-external-provider');
    }

    private function wav(): string
    {
        $data = str_repeat("\0", 16000);

        return 'RIFF'.pack('V', strlen($data) + 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($data)).$data;
    }
}
