<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class MobileEventForeignScopeException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $message = 'Event scope does not match authorized actor and device context.')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 403;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
