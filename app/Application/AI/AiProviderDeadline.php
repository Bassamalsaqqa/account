<?php

declare(strict_types=1);

namespace App\Application\AI;

/** Wall-clock allowance shared by the primary and optional fallback in one foreground request. */
final class AiProviderDeadline
{
    private ?int $started = null;

    public function remaining(): int
    {
        $this->started ??= hrtime(true);
        $configured = min(25, max(1, (int) config('ai_assistant.timeout_seconds')));
        $phpLimit = (int) ini_get('max_execution_time');
        $budget = $phpLimit > 0 ? min($configured, max(1, $phpLimit - 5)) : $configured;
        $elapsedSeconds = intdiv(hrtime(true) - $this->started, 1000000000);
        $remaining = $budget - $elapsedSeconds;
        if ($remaining < 1) {
            throw new AssistantUnavailable('provider_outage');
        }

        return $remaining;
    }
}
