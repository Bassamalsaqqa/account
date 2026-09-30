<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use DomainException;

final class ImmutableRecordException extends DomainException
{
    public static function cannotModify(string $recordType): self
    {
        return new self("Posted financial {$recordType} is immutable and cannot be updated.");
    }

    public static function cannotDelete(string $recordType): self
    {
        return new self("Posted financial {$recordType} cannot be deleted. Use reversal instead.");
    }
}
