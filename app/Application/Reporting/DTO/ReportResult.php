<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

class ReportResult
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $totals
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $currency
     * @param  array<string, mixed>|null  $pagination
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $reportType,
        public readonly array $filters,
        public readonly array $totals,
        public readonly array $rows,
        public readonly array $currency,
        public readonly ?array $pagination = null,
        public readonly string $generatedAt = '',
        public readonly array $meta = []
    ) {
        self::assertScalarPayload([$filters, $totals, $rows, $currency, $pagination, $meta]);
    }

    /** @param array<mixed> $values */
    private static function assertScalarPayload(array $values): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                self::assertScalarPayload($value);
            } elseif ($value !== null && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                throw new \InvalidArgumentException('Report payloads contain only authorized scalar arrays and exact decimal strings.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'report_type' => $this->reportType,
            'filters' => $this->filters,
            'totals' => $this->totals,
            'rows' => $this->rows,
            'currency' => $this->currency,
            'pagination' => $this->pagination,
            'generated_at' => $this->generatedAt,
            'meta' => $this->meta,
        ];
    }
}
