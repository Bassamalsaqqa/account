<?php

declare(strict_types=1);

namespace App\Services\Accounting;

final class OpeningTradePostingCapability
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $actorId,
        public readonly string $side,
        public readonly int $partyId,
    ) {}
}
