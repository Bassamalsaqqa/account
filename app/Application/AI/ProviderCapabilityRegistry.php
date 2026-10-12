<?php

declare(strict_types=1);

namespace App\Application\AI;

final class ProviderCapabilityRegistry
{
    public const VERSION = '2026-10-12.deepseek-only.1';

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
        ];
    }

    /** @return array<string, mixed> */
    public function resolve(string $provider, string $model, string $region, string $capability): array
    {
        $entry = $this->catalog()[$provider] ?? null;
        if ($entry === null || ! isset($entry['regions'][$region], $entry['models'][$model])
            || ! in_array($capability, $entry['models'][$model], true)
            || $capability !== 'text_structured') {
            throw new AssistantUnavailable('provider_configuration');
        }

        return [...$entry, 'provider' => $provider, 'model' => $model, 'region' => $region,
            'capability' => $capability, 'base_url' => $entry['regions'][$region], 'catalog_version' => self::VERSION];
    }
}
