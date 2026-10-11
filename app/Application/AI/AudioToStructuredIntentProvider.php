<?php

declare(strict_types=1);

namespace App\Application\AI;

/**
 * Experimental capability only. No production implementation or automatic selection.
 */
interface AudioToStructuredIntentProvider
{
    /** @return array<string, mixed> */
    public function extractAudio(string $audio, string $locale, string $allowedIntent): array;
}
