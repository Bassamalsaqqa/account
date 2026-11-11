<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract;

final readonly class StrictJsonNumber implements StrictJsonElement
{
    public function __construct(
        public string $rawString,
        public int $value,
        public bool $isInt = true
    ) {}

    public function asInt(): int
    {
        return $this->value;
    }

    public function asLong(): int
    {
        return $this->value;
    }
}
