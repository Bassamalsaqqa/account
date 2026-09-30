<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use DomainException;

final class SystemAccountConflictException extends DomainException
{
    public static function codeConflict(string $systemKey, string $code, int $existingAccountId, ?string $existingKey): self
    {
        return new self(sprintf(
            'Cannot provision system account [%s] with code [%s]. Code is already occupied by account ID [%d] with system key [%s].',
            $systemKey,
            $code,
            $existingAccountId,
            $existingKey ?? 'none'
        ));
    }

    public static function keyConflict(string $systemKey, string $code, int $existingAccountId, string $existingCode): self
    {
        return new self(sprintf(
            'System key [%s] is already assigned to account ID [%d] with code [%s], conflicting with requested code [%s].',
            $systemKey,
            $existingAccountId,
            $existingCode,
            $code
        ));
    }

    public static function semanticConflict(string $systemKey, string $attribute, mixed $expected, mixed $actual): self
    {
        return new self(sprintf(
            'System account [%s] has conflicting %s: expected [%s], found [%s].',
            $systemKey,
            $attribute,
            (string) $expected,
            (string) $actual
        ));
    }
}
