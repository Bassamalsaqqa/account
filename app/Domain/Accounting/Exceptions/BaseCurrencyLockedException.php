<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use DomainException;

final class BaseCurrencyLockedException extends DomainException
{
    public static function forCompany(int $companyId, string $currentBase, string $requestedBase): self
    {
        return new self("Cannot change base currency from [{$currentBase}] to [{$requestedBase}] for company [{$companyId}] because posting history exists.");
    }
}
