<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt\Exceptions;

use RuntimeException;

class SecurityException extends RuntimeException
{
}

if (! class_exists('SecurityException', false)) {
    class_alias(SecurityException::class, 'SecurityException');
}
