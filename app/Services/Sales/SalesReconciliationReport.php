<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Company;

final readonly class SalesReconciliationReport
{
    /**
     * @param  list<string>  $violations
     * @param  array<string, mixed>  $stats
     */
    public function __construct(
        public Company $company,
        public bool $isHealthy,
        public array $violations = [],
        public array $stats = [],
    ) {}
}
