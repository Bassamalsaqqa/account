<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class IdempotencyConflictException extends RuntimeException
{
    public function __construct(string $message = 'Idempotency conflict detected.')
    {
        parent::__construct($message);
    }
}
