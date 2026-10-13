<?php

declare(strict_types=1);

namespace App\Application\AI;

/**
 * Bounded, redacted, immutable report result DTO for assistant presentation.
 * Contains only authorized scalar fields and exact decimal strings.
 * Never sent to an external LLM.
 */
final readonly class AssistantReportDto
{
    /**
     * @param  array<string, mixed>  $totals
     * @param  list<array<string, mixed>>  $rows  Bounded to at most 5 rows
     * @param  array{base_currency_code: string, filter_currency_code: ?string}  $currency
     * @param  array{preset: string, start_date: string, end_date: string, timezone: string}|null  $period
     * @param  array<string, mixed>|null  $scope
     * @param  array<string, mixed>|null  $comparison  Bounded adjacent comparison data
     */
    public function __construct(
        public string $reportKey,
        public string $title,
        public string $periodLabel,
        public string $timezone,
        public string $generatedAt,
        public array $currency,
        public array $totals,
        public array $rows,
        public int $totalRows,
        public string $sourceUrl,
        public bool $isCurrent,
        public ?array $period = null,
        public ?string $asOf = null,
        public ?array $scope = null,
        public ?array $comparison = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'report_key' => $this->reportKey,
            'title' => $this->title,
            'period_label' => $this->periodLabel,
            'timezone' => $this->timezone,
            'generated_at' => $this->generatedAt,
            'currency' => $this->currency,
            'totals' => $this->totals,
            'rows' => $this->rows,
            'total_rows' => $this->totalRows,
            'source_url' => $this->sourceUrl,
            'is_current' => $this->isCurrent,
            'period' => $this->period,
            'as_of' => $this->asOf,
            'scope' => $this->scope,
            'comparison' => $this->comparison,
        ];
    }
}
