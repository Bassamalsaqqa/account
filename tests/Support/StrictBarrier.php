<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use RuntimeException;

/** Test orchestration only. A failed rendezvous must never authorize effects. */
final class StrictBarrier
{
    private bool $held = false;

    public function observe(string $sql, string $table): bool
    {
        if (! preg_match('/^[a-z_]+$/D', $table)) {
            throw new RuntimeException('Invalid barrier table.');
        }
        if (! preg_match('/\bfrom\s+`?'.preg_quote($table, '/').'`?\s.*\bfor update\b/i', $sql)) {
            return false;
        }
        $this->held = true;

        return true;
    }

    public function requireHeld(): void
    {
        if (! $this->held) {
            throw new RuntimeException('Required held-lock ACK missing.');
        }
    }

    public static function await(Closure $matched, Closure $alive, int $timeoutMs = 15000): void
    {
        $deadline = hrtime(true) + $timeoutMs * 1000000;
        while (! $matched()) {
            if (! $alive()) {
                throw new RuntimeException('Barrier peer exited before ACK.');
            }
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Barrier deadline expired before ACK.');
            }
            usleep(1000);
        }
    }
}
