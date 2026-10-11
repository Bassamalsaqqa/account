<?php

declare(strict_types=1);

namespace App\Application\AI;

final class ProviderCapabilityRegistry
{
    public const VERSION = '2026-10-11.1';

    /** @return array<string, array<string, mixed>> */
    public function catalog(): array
    {
        return [
            'deepseek' => [
                'regions' => ['global' => 'https://api.deepseek.com'],
                'models' => ['deepseek-flash' => ['text_structured']],
                'protocol' => 'chat_json', 'strict_json' => false, 'streaming' => false,
                'context_cache' => true, 'audio_to_structured' => false, 'local_transcription' => false,
            ],
            'qwen' => [
                'regions' => [
                    'beijing' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                    'singapore' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
                ],
                'models' => [
                    'qwen-plus' => ['text_structured'],
                    'qwen3-asr-flash' => ['audio_transcription'],
                ],
                'protocol' => 'qwen', 'strict_json' => false, 'streaming' => false,
                'context_cache' => false, 'audio_to_structured' => false, 'local_transcription' => false,
            ],
            'groq' => [
                'regions' => ['global' => 'https://api.groq.com/openai/v1'],
                'models' => [
                    'whisper-large-v3-turbo' => ['audio_transcription'],
                    'whisper-large-v3' => ['audio_transcription'],
                ],
                'protocol' => 'multipart_stt', 'strict_json' => false, 'streaming' => false,
                'context_cache' => false, 'audio_to_structured' => false, 'local_transcription' => false,
                'minimum_billed_audio_seconds' => 10,
            ],
            'openai' => [
                'regions' => ['global' => 'https://api.openai.com/v1'],
                'models' => [
                    'gpt-4o-mini' => ['text_structured'],
                    'gpt-4o-mini-transcribe' => ['audio_transcription'],
                    'whisper-1' => ['audio_transcription'],
                ],
                'protocol' => 'openai', 'strict_json' => true, 'streaming' => false,
                'context_cache' => false, 'audio_to_structured' => false, 'local_transcription' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function resolve(string $provider, string $model, string $region, string $capability): array
    {
        $entry = $this->catalog()[$provider] ?? null;
        if ($entry === null || ! isset($entry['regions'][$region], $entry['models'][$model])
            || ! in_array($capability, $entry['models'][$model], true)
            || ! in_array($capability, ['text_structured', 'audio_transcription'], true)) {
            throw new AssistantUnavailable('provider_configuration');
        }

        return [...$entry, 'provider' => $provider, 'model' => $model, 'region' => $region,
            'capability' => $capability, 'base_url' => $entry['regions'][$region], 'catalog_version' => self::VERSION];
    }
}
