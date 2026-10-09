<?php

declare(strict_types=1);

namespace App\Application\Reporting\Exceptions;

use InvalidArgumentException;

class InvalidReportFilterException extends InvalidArgumentException
{
    public static function unknownKey(string $key): self
    {
        return new self("Unknown or disallowed report filter key [{$key}].");
    }

    public static function foreignEntity(string $filterName, mixed $id, int $companyId): self
    {
        return new self("Filter [{$filterName}] with ID [{$id}] does not belong to company [{$companyId}] or does not exist.");
    }

    public static function invalidValue(string $filterName, mixed $value, string $reason): self
    {
        return new self("Invalid value [{$value}] for filter [{$filterName}]: {$reason}");
    }
}
