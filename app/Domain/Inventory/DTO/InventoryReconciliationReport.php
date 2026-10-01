<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTO;

final readonly class InventoryReconciliationReport
{
    /**
     * @param  list<string>  $discrepancies  Combined list of all findings
     * @param  list<string>  $historyCorruptions  Authoritative-history corruptions that prevent rebuild
     * @param  list<string>  $cacheDiscrepancies  Derived-vs-cached drifts that rebuild can repair
     */
    public function __construct(
        public int $companyId,
        public bool $isHealthy,
        public array $discrepancies,
        public int $checkedProducts,
        public int $checkedWarehouses,
        public int $checkedMovements,
        public string $totalValuationBase,
        public array $historyCorruptions = [],
        public array $cacheDiscrepancies = [],
    ) {}

    public function hasHistoryCorruption(): bool
    {
        return ! empty($this->historyCorruptions);
    }

    public function hasCacheDiscrepancies(): bool
    {
        return ! empty($this->cacheDiscrepancies);
    }
}
