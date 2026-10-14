<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/** Opaque one-use identity, valid only inside its executing canonical action. */
final class StockEventCapability
{
    private function __clone() {}

    /** @return array<string,mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Stock event capabilities cannot be serialized.');
    }

    /** @param array<string,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Stock event capabilities cannot be restored.');
    }
}
