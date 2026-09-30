<?php

declare(strict_types=1);

namespace App\Domain\Posting\Exceptions;

use DomainException;

final class ReversalException extends DomainException
{
    public static function cannotReverseReversal(int $batchId): self
    {
        return new self("Cannot reverse a reversal batch [{$batchId}]. Reversals cannot be chained.");
    }

    public static function notPosted(int $batchId, string $status): self
    {
        return new self("Only posted batches can be reversed. Batch [{$batchId}] current status is [{$status}].");
    }

    public static function conflictingPriorReversal(string $idempotencyKey, int $originalId, ?int $priorReversalOfId): self
    {
        return new self("Prior reversal batch found with idempotency key [{$idempotencyKey}], but it reverses batch [{$priorReversalOfId}] rather than target batch [{$originalId}].");
    }

    public static function incoherentReversalState(string $message): self
    {
        return new self("Incoherent reversal state detected: {$message}");
    }
}
