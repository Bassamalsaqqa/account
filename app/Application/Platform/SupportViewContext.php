<?php

declare(strict_types=1);

namespace App\Application\Platform;

use Illuminate\Auth\Access\AuthorizationException;

/** Request-local support identity; never a tenant permission or persistent login. */
final class SupportViewContext
{
    /** @var array<string, mixed>|null */
    private ?array $details = null;

    /** @param array<string, mixed> $details */
    public function activate(array $details): void
    {
        $this->details = $details;
    }

    public function clear(): void
    {
        $this->details = null;
    }

    public function active(): bool
    {
        return $this->details !== null;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details ?? [];
    }

    public function assertWritable(): void
    {
        if ($this->active()) {
            throw new AuthorizationException(__('support.read_only'));
        }
    }
}
