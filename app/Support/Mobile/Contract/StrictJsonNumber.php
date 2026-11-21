<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract;

final readonly class StrictJsonNumber implements StrictJsonElement
{
    public function __construct(
        public string $rawString,
        public int $value,
        public bool $isInt = true
    ) {
        if (! $isInt || ! preg_match('/\A(?:0|-?[1-9][0-9]*)\z/', $rawString)
            || (string) $value !== $rawString
            || $value < ContractConstants::MIN_SAFE_INTEGER
            || $value > ContractConstants::MAX_SAFE_INTEGER) {
            throw new Exceptions\CanonicalSerializationException('Invalid or inconsistent strict integer');
        }
    }

    public function asInt(): int
    {
        return $this->value;
    }

    public function asLong(): int
    {
        return $this->value;
    }
}
