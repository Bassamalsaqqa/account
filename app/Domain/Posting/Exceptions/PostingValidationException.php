<?php

declare(strict_types=1);

namespace App\Domain\Posting\Exceptions;

use DomainException;

final class PostingValidationException extends DomainException
{
    public static function lineMustHaveSingleDirection(int $lineNumber): self
    {
        return new self("Line [{$lineNumber}] must have either a positive debit or positive credit, never both or neither.");
    }

    public static function minimumLinesRequired(): self
    {
        return new self('A double-entry posting batch requires at least two lines.');
    }

    public static function duplicateLineNumbers(): self
    {
        return new self('Posting lines must have unique sequential line numbers.');
    }

    public static function debitsDoNotEqualCredits(string $debits, string $credits): self
    {
        return new self("Posting batch is unbalanced. Total debits [{$debits}] must equal total credits [{$credits}].");
    }

    public static function accountNotFoundOrInactive(int $accountId, int $companyId): self
    {
        return new self("Ledger account ID [{$accountId}] does not exist, is inactive, or does not belong to company [{$companyId}].");
    }

    public static function invalidCurrency(string $currency, string $reason): self
    {
        return new self("Invalid currency [{$currency}]: {$reason}.");
    }

    public static function invalidPoster(int $userId, int $companyId, ?string $customMessage = null): self
    {
        if ($customMessage !== null) {
            return new self($customMessage);
        }

        return new self("User [{$userId}] is not an active member of company [{$companyId}].");
    }

    public static function reversalOnlyAllowedViaService(): self
    {
        return new self('Reversal postings and links can only be created via AccountingReversalService.');
    }
}
