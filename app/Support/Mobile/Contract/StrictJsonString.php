<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract;

final readonly class StrictJsonString implements StrictJsonElement
{
    public function __construct(
        public string $value
    ) {}
}
