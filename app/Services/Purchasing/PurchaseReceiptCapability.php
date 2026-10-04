<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

/** Opaque object identity; only the active posting scope can recognize it. */
final class PurchaseReceiptCapability
{
    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Purchase receipt capabilities cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Purchase receipt capabilities cannot be restored.');
    }
}
