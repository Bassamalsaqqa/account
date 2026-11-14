<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt\Exceptions;

use LogicException;

class IllegalStateException extends LogicException
{
}

if (! class_exists('IllegalStateException', false)) {
    class_alias(IllegalStateException::class, 'IllegalStateException');
}
