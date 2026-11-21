<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use LogicException;

/**
 * Opaque object identity token. Only an active GoodsReceiptScope can recognize and validate it.
 */
final class GoodsReceiptCapability
{
    private function __clone() {}

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Goods receipt capabilities cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Goods receipt capabilities cannot be restored.');
    }
}
