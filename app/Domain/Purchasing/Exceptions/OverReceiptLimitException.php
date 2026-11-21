<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Exceptions;

use DomainException;

final class OverReceiptLimitException extends DomainException
{
    public static function forLine(string $linePublicId, string $requestedQuantity, string $remainingQuantity): self
    {
        return new self("Line [{$linePublicId}] cannot receive [{$requestedQuantity}]. Maximum remaining receivable quantity is [{$remainingQuantity}].");
    }
}
