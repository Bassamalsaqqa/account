<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\SpeechTranscriptionProvider;

final class GatewaySpeechProvider implements SpeechTranscriptionProvider
{
    public function __construct(private GatewayTransport $transport) {}

    public function transcribe(string $wav, string $locale): array
    {
        $data = $this->transport->send('transcription', ['version' => 1, 'locale' => $locale], $wav);
        if (array_diff(array_keys($data), ['text', 'confidence', 'usage']) !== []
            || ! is_string($data['text'] ?? null) || ! is_int($data['confidence'] ?? null)
            || ! is_array($data['usage'] ?? null)) {
            throw new AssistantUnavailable('provider_invalid');
        }

        return ['text' => $data['text'], 'confidence' => $data['confidence'], 'usage' => $data['usage']];
    }
}
