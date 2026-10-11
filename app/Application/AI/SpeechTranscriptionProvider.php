<?php

declare(strict_types=1);

namespace App\Application\AI;

interface SpeechTranscriptionProvider
{
    /** @return array{text: string, confidence: int, usage: array<string, int>} */
    public function transcribe(string $wav, string $locale): array;
}
