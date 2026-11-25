<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

final class ActiveSettlementBlockedException extends RuntimeException
{
    public function __construct(string $message = 'Cannot reverse opening trade item while active payment allocations exist.')
    {
        parent::__construct($message);
    }
}
