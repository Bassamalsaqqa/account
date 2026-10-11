<?php

declare(strict_types=1);

namespace App\Application\AI;

use RuntimeException;

final class AssistantUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable')
    {
        parent::__construct('Sales assistant is unavailable; continue with manual entry.');
    }
}
