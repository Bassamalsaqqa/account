<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

final class LegacyGlOpeningOverlapException extends RuntimeException
{
    public function __construct(string $message = 'General ledger already contains unreviewed generic opening balances for this control account.')
    {
        parent::__construct($message);
    }
}
