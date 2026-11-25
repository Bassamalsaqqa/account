<?php

declare(strict_types=1);

namespace App\Services\Operations\DTO;

final readonly class ReconciliationRunResult
{
    /**
     * @param  array<string, array{healthy: bool, violation_count: int}>  $domainSummary
     * @param  list<array{company_public_id: string, domain: string, category: string, status: string, count: int}>  $findings
     */
    public function __construct(
        public string $status,
        public float $durationSeconds,
        public int $companiesAudited,
        public array $domainSummary,
        public array $findings = [],
        public ?string $errorMessage = null,
        public bool $skippedDisabled = false,
        public bool $lockContended = false,
    ) {}

    public function isHealthy(): bool
    {
        return $this->status === 'HEALTHY';
    }

    public function hasNonzeroFindings(): bool
    {
        return $this->status === 'NONZERO_FINDINGS';
    }

    public function isFailure(): bool
    {
        return $this->status === 'FAILURE' || $this->status === 'TIMEOUT_ABORTED';
    }

    public function wasRefused(): bool
    {
        return $this->skippedDisabled || $this->lockContended;
    }

    public function totalViolations(): int
    {
        $sum = 0;
        foreach ($this->findings as $finding) {
            $sum += $finding['count'];
        }

        return $sum > 0 ? $sum : count($this->findings);
    }
}
