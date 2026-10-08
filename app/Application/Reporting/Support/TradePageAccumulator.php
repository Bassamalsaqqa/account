<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use Closure;

/** Retains only the requested sorted prefix; domain records are processed in bounded batches. */
final class TradePageAccumulator
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];

    private int $count = 0;

    /** @param Closure(array<string,mixed>,array<string,mixed>):int $compare */
    public function __construct(private ReportFilters $filters, private Closure $compare) {}

    /** @param array<string,mixed> $row */
    public function add(array $row): void
    {
        $this->count++;
        $limit = $this->filters->page * $this->filters->perPage;
        $low = 0;
        $high = count($this->rows);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if (($this->compare)($row, $this->rows[$mid]) < 0) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }
        if ($low < $limit) {
            array_splice($this->rows, $low, 0, [$row]);
            if (count($this->rows) > $limit) {
                array_pop($this->rows);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function rows(): array
    {
        return array_slice($this->rows, ($this->filters->page - 1) * $this->filters->perPage, $this->filters->perPage);
    }

    public function count(): int
    {
        return $this->count;
    }
}
