<?php

declare(strict_types=1);

namespace App\Application\Reporting\Exceptions;

class MissingSystemAccountException extends ReportingException
{
    public static function forSystemKey(string $systemKey, int $companyId): self
    {
        return new self("Required system account [{$systemKey}] is missing or corrupt for company [{$companyId}]. Failing closed.");
    }
}
