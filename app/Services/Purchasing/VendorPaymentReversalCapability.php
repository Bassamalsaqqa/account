<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

/** Opaque object identity; only the active vendor payment reversal scope can recognize it. */
final class VendorPaymentReversalCapability
{
    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Vendor payment reversal capabilities cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Vendor payment reversal capabilities cannot be restored.');
    }
}
