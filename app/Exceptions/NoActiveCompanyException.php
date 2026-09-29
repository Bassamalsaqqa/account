<?php

namespace App\Exceptions;

use RuntimeException;

class NoActiveCompanyException extends RuntimeException
{
    public function __construct(string $message = 'No active company context resolved.')
    {
        parent::__construct($message);
    }
}
