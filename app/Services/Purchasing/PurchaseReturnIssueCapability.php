<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

/** Opaque object identity; only the active Purchase Return posting scope can recognize it. */
final class PurchaseReturnIssueCapability
{
    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Purchase return issue capabilities cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Purchase return issue capabilities cannot be restored.');
    }
}
