<?php

declare(strict_types=1);

namespace Tests\Unit\Phase12;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCostEstimate;
use App\Application\AI\ProviderCredential;
use App\Infrastructure\AI\DirectSpeechProvider;
use App\Infrastructure\AI\DirectTextProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Provider Capability Matrix Test
 *
 * Covers all catalogued provider/model/region combinations and negative cases
 * that are NOT already tested in DirectProviderContractTest.
 *
 * Every test that makes HTTP calls uses Http::swap(new Factory) + Http::preventStrayRequests()
 * to prevent factory state from leaking across independent mock cases.
 */
class ProviderCapabilityMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'ai_assistant.enabled' => true, 'ai_assistant.provider' => 'direct',
            'ai_assistant.provider_approved' => true,
            'ai_assistant.speech_enabled' => true,
            'ai_assistant.max_audio_bytes' => 10485760,
            'ai_assistant.max_audio_seconds' => 60,
            'ai_assistant.max_text_characters' => 4096,
            'ai_assistant.timeout_seconds' => 10,
            'ai_assistant.max_output_tokens' => 512,
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. All text-provider resolve() combos — base_url, protocol, strict_json
    // -------------------------------------------------------------------------

    public function test_catalog_resolve_returns_correct_attributes_for_every_text_provider(): void
    {
        $registry = app(ProviderCapabilityRegistry::class);

        // deepseek / global
        $r = $registry->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
        $this->assertSame('https://api.deepseek.com', $r['base_url']);
        $this->assertSame('chat_json', $r['protocol']);
        $this->assertFalse($r['strict_json']);
        $this->assertSame('deepseek', $r['provider']);
        $this->assertSame('deepseek-flash', $r['model']);
        $this->assertSame('global', $r['region']);
        $this->assertSame('text_structured', $r['capability']);

        // qwen-plus / beijing
        $r = $registry->resolve('qwen', 'qwen-plus', 'beijing', 'text_structured');
        $this->assertSame('https://dashscope.aliyuncs.com/compatible-mode/v1', $r['base_url']);
        $this->assertSame('qwen', $r['protocol']);
        $this->assertFalse($r['strict_json']);

        // qwen-plus / singapore
        $r = $registry->resolve('qwen', 'qwen-plus', 'singapore', 'text_structured');
        $this->assertSame('https://dashscope-intl.aliyuncs.com/compatible-mode/v1', $r['base_url']);
        $this->assertSame('qwen', $r['protocol']);

        // openai / gpt-4o-mini / global
        $r = $registry->resolve('openai', 'gpt-4o-mini', 'global', 'text_structured');
        $this->assertSame('https://api.openai.com/v1', $r['base_url']);
        $this->assertSame('openai', $r['protocol']);
        $this->assertTrue($r['strict_json']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 2. All 6 speech-model resolve() registrations
    // -------------------------------------------------------------------------

    public function test_catalog_resolve_succeeds_for_every_registered_speech_model(): void
    {
        $registry = app(ProviderCapabilityRegistry::class);
        $cases = [
            ['groq', 'whisper-large-v3-turbo', 'global', 'https://api.groq.com/openai/v1'],
            ['groq', 'whisper-large-v3', 'global', 'https://api.groq.com/openai/v1'],
            ['openai', 'gpt-4o-mini-transcribe', 'global', 'https://api.openai.com/v1'],
            ['openai', 'whisper-1', 'global', 'https://api.openai.com/v1'],
            ['qwen', 'qwen3-asr-flash', 'beijing', 'https://dashscope.aliyuncs.com/compatible-mode/v1'],
            ['qwen', 'qwen3-asr-flash', 'singapore', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1'],
        ];
        foreach ($cases as [$provider, $model, $region, $expectedBase]) {
            $r = $registry->resolve($provider, $model, $region, 'audio_transcription');
            $this->assertSame($expectedBase, $r['base_url'], "base_url mismatch for $provider/$model/$region");
            $this->assertSame($provider, $r['provider']);
            $this->assertSame($model, $r['model']);
            $this->assertSame('audio_transcription', $r['capability']);
        }
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 16. catalog_version is exposed by resolve()
    // -------------------------------------------------------------------------

    public function test_resolve_exposes_catalog_version_constant(): void
    {
        $r = app(ProviderCapabilityRegistry::class)->resolve('openai', 'gpt-4o-mini', 'global', 'text_structured');
        $this->assertSame(ProviderCapabilityRegistry::VERSION, $r['catalog_version']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 3. Qwen speech beijing endpoint — full round-trip with data URI
    // -------------------------------------------------------------------------

    public function test_qwen_asr_beijing_endpoint_uses_dashscope_and_data_uri(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'مرحباً']]]])]);

        $result = app(DirectSpeechProvider::class)->transcribe(
            'qwen', 'qwen3-asr-flash', 'beijing', $this->credential(), $this->wav(), 'ar'
        );

        $this->assertSame('مرحباً', $result['text']);
        $this->assertSame('unknown', $result['cost_status']);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(
                'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
                $request->url()
            );
            $this->assertSame('qwen3-asr-flash', $request['model']);
            // Must use data URI, not multipart
            $this->assertFalse($request->hasFile('file'));
            $data = $request['messages'][0]['content'][0]['input_audio']['data'];
            $this->assertStringStartsWith('data:audio/wav;base64,', $data);
            $this->assertSame('data:audio/wav;base64,'.base64_encode($this->wav()), $data);
            // ar locale must set language
            $this->assertSame(['enable_itn' => false, 'language' => 'ar'], $request['asr_options']);

            return true;
        });
    }

    // -------------------------------------------------------------------------
    // 4. whisper-1 speech round-trip — multipart, /audio/transcriptions path
    // -------------------------------------------------------------------------

    public function test_whisper1_speech_sends_multipart_to_audio_transcriptions_endpoint(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['text' => 'purchase soap'])]);

        $result = app(DirectSpeechProvider::class)->transcribe(
            'openai', 'whisper-1', 'global', $this->credential(), $this->wav(), 'en'
        );

        $this->assertSame('purchase soap', $result['text']);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringEndsWith('/audio/transcriptions', $request->url());
            $this->assertSame('https://api.openai.com/v1/audio/transcriptions', $request->url());
            $parts = collect($request->data())->keyBy('name');
            $this->assertSame('whisper-1', $parts['model']['contents']);
            $this->assertTrue($request->hasFile('file', $this->wav(), 'recording.wav'));
            // en locale must set language field
            $this->assertSame('en', $parts['language']['contents']);
            // No data URI
            $this->assertFalse(str_contains((string) json_encode($request->data()), 'data:audio/wav;base64,'));

            return true;
        });
    }

    // -------------------------------------------------------------------------
    // 5. groq whisper-large-v3 (non-turbo) round-trip
    // -------------------------------------------------------------------------

    public function test_groq_whisper_large_v3_non_turbo_sends_multipart(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['text' => 'اشتريت خبز'])]);

        $result = app(DirectSpeechProvider::class)->transcribe(
            'groq', 'whisper-large-v3', 'global', $this->credential(), $this->wav(), 'ar'
        );

        $this->assertSame('اشتريت خبز', $result['text']);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringEndsWith('/audio/transcriptions', $request->url());
            $parts = collect($request->data())->keyBy('name');
            $this->assertSame('whisper-large-v3', $parts['model']['contents']);
            $this->assertTrue($request->hasFile('file', $this->wav(), 'recording.wav'));
            // ar locale must send language
            $this->assertSame('ar', $parts['language']['contents']);

            return true;
        });
    }

    // -------------------------------------------------------------------------
    // 6. DeepSeek sends thinking:disabled; OpenAI and Qwen do NOT
    // -------------------------------------------------------------------------

    public function test_deepseek_sends_thinking_disabled_field_and_others_do_not(): void
    {
        $schema = ['type' => 'object'];
        $providers = [
            ['deepseek', 'deepseek-flash', 'global', true],
            ['openai', 'gpt-4o-mini', 'global', false],
            ['qwen', 'qwen-plus', 'singapore', false],
        ];

        foreach ($providers as [$provider, $model, $region, $expectThinking]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response([
                'choices' => [['message' => ['content' => '{"x":1}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
            ])]);

            app(DirectTextProvider::class)->extract($provider, $model, $region, $this->credential(), 'some text', 'auto', $schema);

            Http::assertSent(function (Request $request) use ($provider, $expectThinking): bool {
                if ($expectThinking) {
                    $this->assertArrayHasKey('thinking', $request->data());
                    $this->assertSame(['type' => 'disabled'], $request['thinking']);
                } else {
                    $this->assertArrayNotHasKey('thinking', $request->data(),
                        "Provider $provider must NOT send thinking field");
                }

                return true;
            });
        }
    }

    // -------------------------------------------------------------------------
    // 7. qwen-plus beijing text round-trip — verifies beijing base URL
    // -------------------------------------------------------------------------

    public function test_qwen_plus_beijing_text_round_trip_hits_beijing_endpoint(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '{"total":"99.50"}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 6],
        ])]);

        $result = app(DirectTextProvider::class)->extract(
            'qwen', 'qwen-plus', 'beijing', $this->credential(), 'فاتورة شراء', 'purchase', ['type' => 'object']
        );

        $this->assertSame(['total' => '99.50'], $result['proposal']);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(
                'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
                $request->url()
            );
            $this->assertSame('qwen-plus', $request['model']);
            $this->assertSame('json_object', $request['response_format']['type']);

            return true;
        });
    }

    // -------------------------------------------------------------------------
    // 8a. ar locale sends language field for Groq/OpenAI multipart
    // -------------------------------------------------------------------------

    public function test_ar_locale_sends_language_field_for_groq_and_openai_multipart(): void
    {
        foreach ([['groq', 'whisper-large-v3-turbo'], ['openai', 'whisper-1']] as [$provider, $model]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['text' => 'شراء زيت'])]);

            app(DirectSpeechProvider::class)->transcribe(
                $provider, $model, 'global', $this->credential(), $this->wav(), 'ar'
            );

            Http::assertSent(function (Request $request) use ($provider): bool {
                $parts = collect($request->data())->keyBy('name');
                $this->assertTrue($parts->has('language'), "Provider $provider with ar locale must send language field");
                $this->assertSame('ar', $parts['language']['contents']);

                return true;
            });
        }
    }

    // -------------------------------------------------------------------------
    // 8b. mixed locale does NOT send language field for Groq/OpenAI multipart
    // -------------------------------------------------------------------------

    public function test_mixed_locale_omits_language_field_for_groq_and_openai_multipart(): void
    {
        foreach ([['groq', 'whisper-large-v3-turbo'], ['openai', 'gpt-4o-mini-transcribe']] as [$provider, $model]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['text' => 'mixed speech'])]);

            app(DirectSpeechProvider::class)->transcribe(
                $provider, $model, 'global', $this->credential(), $this->wav(), 'mixed'
            );

            Http::assertSent(function (Request $request) use ($provider): bool {
                $parts = collect($request->data())->keyBy('name');
                $this->assertFalse($parts->has('language'),
                    "Provider $provider with mixed locale must NOT send language field");

                return true;
            });
        }
    }

    // -------------------------------------------------------------------------
    // 8c. mixed locale does NOT force language for Qwen either
    // -------------------------------------------------------------------------

    public function test_mixed_locale_omits_language_for_qwen_asr(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'نص مختلط']]]])]);

        app(DirectSpeechProvider::class)->transcribe(
            'qwen', 'qwen3-asr-flash', 'singapore', $this->credential(), $this->wav(), 'mixed'
        );

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(['enable_itn' => false], $request['asr_options'],
                'mixed locale must not inject language into asr_options');

            return true;
        });
    }

    // -------------------------------------------------------------------------
    // 9. 403 maps to provider_rejected
    // -------------------------------------------------------------------------

    public function test_403_response_maps_to_provider_rejected(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 403)]);

        try {
            app(DirectTextProvider::class)->extract(
                'openai', 'gpt-4o-mini', 'global', $this->credential(), 'Sale', 'sales', ['type' => 'object']
            );
            $this->fail('403 must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_rejected', $e->reason);
        }
        Http::assertSentCount(1);
    }

    // -------------------------------------------------------------------------
    // 10. 429 and 502 retain distinct safe diagnostics
    // -------------------------------------------------------------------------

    public function test_429_and_502_retain_distinct_safe_diagnostics(): void
    {
        foreach ([429, 502] as $status) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response([], $status)]);

            try {
                app(DirectTextProvider::class)->extract(
                    'deepseek', 'deepseek-flash', 'global', $this->credential(), 'Sale', 'auto', ['type' => 'object']
                );
                $this->fail("HTTP $status must throw AssistantUnavailable.");
            } catch (AssistantUnavailable $e) {
                $this->assertSame($status === 429 ? 'provider_rate_limit' : 'provider_outage', $e->reason, "HTTP $status must retain its safe diagnostic");
            }
            Http::assertSentCount(1);
        }
    }

    // -------------------------------------------------------------------------
    // 11. Oversized response body (>131072 bytes) maps to provider_invalid
    // -------------------------------------------------------------------------

    public function test_oversized_response_body_maps_to_provider_invalid(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        // Response body > 131072 bytes
        $bigBody = str_repeat('x', 131073);
        Http::fake(['*' => Http::response($bigBody, 200, ['Content-Type' => 'application/json'])]);

        try {
            app(DirectTextProvider::class)->extract(
                'openai', 'gpt-4o-mini', 'global', $this->credential(), 'Sale', 'sales', ['type' => 'object']
            );
            $this->fail('Oversized response must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }
    }

    // -------------------------------------------------------------------------
    // 12. JSON array (list) response maps to provider_invalid
    // -------------------------------------------------------------------------

    public function test_json_array_response_maps_to_provider_invalid(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([['choices' => []]], 200)]);

        try {
            app(DirectTextProvider::class)->extract(
                'openai', 'gpt-4o-mini', 'global', $this->credential(), 'Sale', 'auto', ['type' => 'object']
            );
            $this->fail('JSON list response must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }
    }

    // -------------------------------------------------------------------------
    // 13. Qwen speech — empty text content maps to provider_invalid
    // -------------------------------------------------------------------------

    public function test_qwen_speech_empty_text_response_maps_to_provider_invalid(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        // Qwen returns choices with an empty content string
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '   ']]]])]);

        try {
            app(DirectSpeechProvider::class)->transcribe(
                'qwen', 'qwen3-asr-flash', 'singapore', $this->credential(), $this->wav(), 'ar'
            );
            $this->fail('Empty Qwen ASR text must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }
    }

    // -------------------------------------------------------------------------
    // 14. PcmWave truncated header rejected before any HTTP call
    // -------------------------------------------------------------------------

    public function test_pcmwave_rejects_truncated_header_before_http_call(): void
    {
        // Only 20 bytes — well below the 46-byte minimum
        $malformedWav = 'RIFF'.pack('V', 12).'WAVEfmt ';

        try {
            app(DirectSpeechProvider::class)->transcribe(
                'groq', 'whisper-large-v3-turbo', 'global', $this->credential(), $malformedWav, 'mixed'
            );
            $this->fail('Malformed WAV must throw AssistantUnavailable before any HTTP call.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('audio_invalid', $e->reason);
        }
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 14b. PcmWave: completely empty bytes rejected as audio_invalid
    // -------------------------------------------------------------------------

    public function test_pcmwave_rejects_empty_bytes_as_audio_invalid(): void
    {
        try {
            app(DirectSpeechProvider::class)->transcribe(
                'openai', 'whisper-1', 'global', $this->credential(), '', 'en'
            );
            $this->fail('Empty WAV bytes must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('audio_invalid', $e->reason);
        }
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 15. ProviderCostEstimate: qwen/openai do NOT apply 10-second billing floor
    // -------------------------------------------------------------------------

    public function test_cost_estimate_non_groq_providers_do_not_apply_10_second_billing_floor(): void
    {
        $estimate = app(ProviderCostEstimate::class);

        // 1000 ms = 1 second at 7 micros/min → ceil(1000 * 7 / 60000) = ceil(0.116...) = 1
        // For groq, floor is 10s (10000 ms), so groq would bill ceil(10000 * 7 / 60000) = ceil(1.166) = 2
        $qwenCost = $estimate->audio('qwen', 1000, 7);
        $openaiCost = $estimate->audio('openai', 1000, 7);
        $groqCost = $estimate->audio('groq', 1000, 7);

        // qwen and openai: no floor padding, 1000 ms * 7 / 60000 = 0.116 → ceiling = 1
        $this->assertSame(1, $qwenCost, 'qwen must not apply 10-second billing floor');
        $this->assertSame(1, $openaiCost, 'openai must not apply 10-second billing floor');
        // groq: floor is 10000 ms, so cost is higher
        $this->assertSame(2, $groqCost, 'groq must apply 10-second billing floor');

        // Verify the local estimate uses duration without the Groq floor; actual billing remains unverified
        $this->assertLessThan($groqCost, $qwenCost,
            'Short audio should cost less for qwen/openai than groq due to no floor');

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 17. speech_enabled=false blocks audio_transcription even for valid route
    // -------------------------------------------------------------------------

    public function test_speech_enabled_false_blocks_audio_transcription_with_disabled_reason(): void
    {
        config(['ai_assistant.speech_enabled' => false]);

        try {
            app(DirectSpeechProvider::class)->transcribe(
                'groq', 'whisper-large-v3-turbo', 'global', $this->credential(), $this->wav(), 'mixed'
            );
            $this->fail('Disabled speech must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('disabled', $e->reason);
        }
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 18. provider_approved=false blocks all calls
    // -------------------------------------------------------------------------

    public function test_provider_approved_false_blocks_text_extraction(): void
    {
        config(['ai_assistant.provider_approved' => false]);

        try {
            app(DirectTextProvider::class)->extract(
                'openai', 'gpt-4o-mini', 'global', $this->credential(), 'Sale receipt', 'sales', ['type' => 'object']
            );
            $this->fail('Unapproved provider must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('disabled', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_provider_approved_false_blocks_speech_transcription(): void
    {
        config(['ai_assistant.provider_approved' => false]);

        try {
            app(DirectSpeechProvider::class)->transcribe(
                'openai', 'whisper-1', 'global', $this->credential(), $this->wav(), 'en'
            );
            $this->fail('Unapproved provider must throw AssistantUnavailable for speech.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('disabled', $e->reason);
        }
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Additional: unsupported model for a valid provider rejects cleanly
    // -------------------------------------------------------------------------

    public function test_unsupported_model_for_valid_provider_throws_provider_configuration(): void
    {
        try {
            app(ProviderCapabilityRegistry::class)->resolve('groq', 'gpt-4o-mini', 'global', 'audio_transcription');
            $this->fail('Wrong model must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_configuration', $e->reason);
        }

        // qwen ASR model cannot be used for text_structured
        try {
            app(ProviderCapabilityRegistry::class)->resolve('qwen', 'qwen3-asr-flash', 'beijing', 'text_structured');
            $this->fail('ASR model used as text_structured must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_configuration', $e->reason);
        }

        // deepseek does not support audio_transcription
        try {
            app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'audio_transcription');
            $this->fail('deepseek audio_transcription must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_configuration', $e->reason);
        }

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Additional: ProviderCredential with invalid secret throws provider_configuration
    // -------------------------------------------------------------------------

    public function test_provider_credential_rejects_short_secret_as_provider_configuration(): void
    {
        try {
            new ProviderCredential('tooshort');
            $this->fail('Short secret must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_configuration', $e->reason);
        }

        // Whitespace character in secret
        try {
            new ProviderCredential('synthetic-key-with space-inside-it!');
            $this->fail('Whitespace in secret must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_configuration', $e->reason);
        }

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Additional: ProviderCostEstimate out-of-range inputs throw budget_configuration
    // -------------------------------------------------------------------------

    public function test_cost_estimate_rejects_out_of_range_inputs(): void
    {
        $estimate = app(ProviderCostEstimate::class);

        try {
            $estimate->audio('openai', 0, 100); // milliseconds < 1
            $this->fail('Zero ms must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('budget_configuration', $e->reason);
        }

        try {
            $estimate->audio('openai', 60001, 100); // milliseconds > 60000
            $this->fail('Over-limit ms must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('budget_configuration', $e->reason);
        }

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Additional: invalid locale rejects before any HTTP call
    // -------------------------------------------------------------------------

    public function test_invalid_locale_rejected_as_input_invalid_before_http_call(): void
    {
        try {
            app(DirectSpeechProvider::class)->transcribe(
                'groq', 'whisper-large-v3-turbo', 'global', $this->credential(), $this->wav(), 'fr'
            );
            $this->fail('Unsupported locale must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('input_invalid', $e->reason);
        }
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Additional: invalid intent rejected as input_invalid before HTTP call
    // -------------------------------------------------------------------------

    public function test_invalid_intent_rejected_as_input_invalid_before_http_call(): void
    {
        try {
            app(DirectTextProvider::class)->extract(
                'openai', 'gpt-4o-mini', 'global', $this->credential(), 'Some text', 'refund', ['type' => 'object']
            );
            $this->fail('Invalid intent must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('input_invalid', $e->reason);
        }
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Additional: finish_reason != stop maps to provider_invalid for speech-driven text
    // -------------------------------------------------------------------------

    public function test_text_provider_finish_reason_length_maps_to_provider_invalid(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '{"x":1}'], 'finish_reason' => 'length']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ])]);

        try {
            app(DirectTextProvider::class)->extract(
                'qwen', 'qwen-plus', 'beijing', $this->credential(), 'some text', 'auto', ['type' => 'object']
            );
            $this->fail('finish_reason=length must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }
    }

    // -------------------------------------------------------------------------
    // Additional: text content that is a JSON list rejected as provider_invalid
    // -------------------------------------------------------------------------

    public function test_text_provider_json_list_content_maps_to_provider_invalid(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '[1,2,3]'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ])]);

        try {
            app(DirectTextProvider::class)->extract(
                'openai', 'gpt-4o-mini', 'global', $this->credential(), 'some text', 'auto', ['type' => 'object']
            );
            $this->fail('JSON array content must throw AssistantUnavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function credential(): ProviderCredential
    {
        return new ProviderCredential('synthetic-key-no-external-provider');
    }

    /**
     * Produce a minimal valid PCM16 mono 8 kHz WAV that passes PcmWave::seconds().
     * 16000 bytes of silence = 1 second at 8000 Hz / 16-bit mono.
     */
    private function wav(): string
    {
        $data = str_repeat("\0", 16000);

        return 'RIFF'.pack('V', strlen($data) + 36)
            .'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            .'data'.pack('V', strlen($data))
            .$data;
    }
}
