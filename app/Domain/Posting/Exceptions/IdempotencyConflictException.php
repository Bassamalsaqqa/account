<?php

declare(strict_types=1);

namespace App\Domain\Posting\Exceptions;

use DomainException;

final class IdempotencyConflictException extends DomainException
{
    public static function mismatchedPayload(string $idempotencyKey, string $existingDesc, string $attemptedDesc): self
    {
        return new self("Idempotency key [{$idempotencyKey}] is already registered with [{$existingDesc}], but was attempted with conflicting financial command [{$attemptedDesc}].");
    }

    public static function alreadyReversed(string $idempotencyKey, int $batchId): self
    {
        return new self("Idempotency key [{$idempotencyKey}] references an already-reversed posting batch [{$batchId}] and cannot be reused for new transactions.");
    }
}
