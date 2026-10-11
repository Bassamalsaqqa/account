<?php

declare(strict_types=1);

namespace App\Application\AI;

use LogicException;
use SensitiveParameter;

/**
 * Request-local secret envelope. Never place in Livewire, sessions, queues or evidence.
 */
final class ProviderCredential
{
    public function __construct(#[SensitiveParameter] private readonly string $secret)
    {
        if (strlen($secret) < 16 || strlen($secret) > 4096 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
            throw new AssistantUnavailable('provider_configuration');
        }
    }

    public function use(#[SensitiveParameter] callable $operation): mixed
    {
        return $operation($this->secret);
    }

    public function __serialize(): array
    {
        throw new LogicException('Provider credentials cannot be serialized.');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['secret' => '[REDACTED]'];
    }
}
