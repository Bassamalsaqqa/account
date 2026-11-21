<?php

declare(strict_types=1);

namespace App\Services\Accounting;

final class OpeningTradeReversalCapability
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $actorId,
        public readonly int $originalBatchId,
    ) {}
}
