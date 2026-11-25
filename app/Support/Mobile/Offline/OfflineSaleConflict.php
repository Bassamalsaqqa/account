<?php

declare(strict_types=1);

namespace App\Support\Mobile\Offline;

use RuntimeException;

final class OfflineSaleConflict extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
