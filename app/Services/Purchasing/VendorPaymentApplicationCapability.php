<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use LogicException;

/** Opaque object identity; only the active vendor payment application scope can recognize it. */
final class VendorPaymentApplicationCapability
{
    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Vendor payment application capabilities cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Vendor payment application capabilities cannot be restored.');
    }
}
