<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use DomainException;

class IdempotencyConflictException extends DomainException {}
