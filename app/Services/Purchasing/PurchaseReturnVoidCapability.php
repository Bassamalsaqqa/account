<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use LogicException;

/** Opaque runtime object identity; only the active purchase return void scope can recognize it. */
final class PurchaseReturnVoidCapability
{
    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Purchase return void capabilities cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Purchase return void capabilities cannot be restored.');
    }
}
