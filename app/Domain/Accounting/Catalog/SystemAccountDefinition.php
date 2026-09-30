<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Catalog;

final readonly class SystemAccountDefinition
{
    public function __construct(
        public string $systemKey,
        public string $code,
        public string $nameAr,
        public string $nameEn,
        public string $accountType,
        public string $normalBalance,
        public bool $isControl = false,
        public bool $isSystem = true,
        public ?string $parentSystemKey = null,
    ) {}
}
