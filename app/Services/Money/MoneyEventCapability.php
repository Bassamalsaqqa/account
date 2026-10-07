<?php

declare(strict_types=1);

namespace App\Services\Money;

final class MoneyEventCapability
{
    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Money event authority cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Money event authority cannot be restored.');
    }
}
