<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class FinancialExecutionDeniedException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $message = 'Financial authority is unresolved on mobile. Bearer token qualifies staff and device identity only; financial posting operations remain disabled.')
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
