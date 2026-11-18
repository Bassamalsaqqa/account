<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class MobileEventAuthenticationException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $message = 'Authentication failed or token/device/membership has been revoked.')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 401;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
