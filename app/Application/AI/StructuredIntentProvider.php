<?php

declare(strict_types=1);

namespace App\Application\AI;

interface StructuredIntentProvider
{
    /** @return array{proposal: array<string, mixed>, confidence: int, usage: array<string, int>} */
    public function extract(string $text, string $locale): array;
}
