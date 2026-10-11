<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\PcmWave;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCredential;

final class DirectSpeechProvider
{
    /** @return array{text: string, usage: array<string, int>, cost_status: string} */
    public function transcribe(string $provider, string $model, string $region, ProviderCredential $credential, string $wav, string $locale): array
    {
        app(PcmWave::class)->seconds($wav);
        if (! in_array($locale, ['ar', 'en', 'mixed'], true)) {
            throw new AssistantUnavailable('input_invalid');
        }
        $route = app(ProviderCapabilityRegistry::class)->resolve($provider, $model, $region, 'audio_transcription');
        $body = ['model' => $model];
        if ($provider === 'qwen') {
            $body += [
                'messages' => [['role' => 'user', 'content' => [['type' => 'input_audio',
                    'input_audio' => ['data' => 'data:audio/wav;base64,'.base64_encode($wav)]]]]],
                'stream' => false, 'asr_options' => ['enable_itn' => false],
            ];
            if ($locale !== 'mixed') {
                $body['asr_options']['language'] = $locale;
            }
            // Qwen's 10MB bound is on encoded input, not raw multipart size.
            if (strlen((string) $body['messages'][0]['content'][0]['input_audio']['data']) > 10000000) {
                throw new AssistantUnavailable('audio_invalid');
            }
        } else {
            $body['response_format'] = 'json';
            if ($locale !== 'mixed') {
                $body['language'] = $locale;
            }
        }
        $result = app(DirectProviderTransport::class)->send($route, $credential, $body, $wav);
        $text = $provider === 'qwen' ? ($result['choices'][0]['message']['content'] ?? null) : ($result['text'] ?? null);
        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > (int) config('ai_assistant.max_text_characters')
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', $text)) {
            throw new AssistantUnavailable('provider_invalid');
        }

        return ['text' => trim($text), 'usage' => [], 'cost_status' => 'unknown'];
    }
}
