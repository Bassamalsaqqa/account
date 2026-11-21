<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract;

final readonly class StrictJsonBoolean implements StrictJsonElement
{
    public function __construct(
        public bool $value
    ) {}
}
