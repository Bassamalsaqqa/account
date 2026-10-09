<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use Closure;

/** Bounded top-K max heap: worst retained row at root, stable on equal sort keys. */
final class TradePageAccumulator
{
    /** @var list<array{row:array<string,mixed>,sequence:int}> */
    private array $heap = [];

    private int $count = 0;

    private int $limit;

    /** @param Closure(array<string,mixed>,array<string,mixed>):int $compare */
    public function __construct(private ReportFilters $filters, private Closure $compare)
    {
        ReportFilters::assertSafePage($filters->page, $filters->perPage);
        $this->limit = $filters->page * $filters->perPage;
    }

    /** @param array<string,mixed> $row */
    public function add(array $row): void
    {
        $entry = ['row' => $row, 'sequence' => $this->count++];
        if (count($this->heap) < $this->limit) {
            $index = count($this->heap);
            $this->heap[] = $entry;
            while ($index > 0) {
                $parent = intdiv($index - 1, 2);
                if ($this->compareEntries($this->heap[$index], $this->heap[$parent]) <= 0) {
                    break;
                }
                [$this->heap[$index], $this->heap[$parent]] = [$this->heap[$parent], $this->heap[$index]];
                $index = $parent;
            }
        } elseif ($this->compareEntries($entry, $this->heap[0]) < 0) {
            $this->heap[0] = $entry;
            $index = 0;
            $size = count($this->heap);
            while (($child = $index * 2 + 1) < $size) {
                if ($child + 1 < $size && $this->compareEntries($this->heap[$child + 1], $this->heap[$child]) > 0) {
                    $child++;
                }
                if ($this->compareEntries($this->heap[$child], $this->heap[$index]) <= 0) {
                    break;
                }
                [$this->heap[$index], $this->heap[$child]] = [$this->heap[$child], $this->heap[$index]];
                $index = $child;
            }
        }
    }

    /** @param array{row:array<string,mixed>,sequence:int} $a
     * @param array{row:array<string,mixed>,sequence:int} $b */
    private function compareEntries(array $a, array $b): int
    {
        return (($this->compare)($a['row'], $b['row'])) ?: ($a['sequence'] <=> $b['sequence']);
    }

    /** @return list<array<string,mixed>> */
    public function rows(): array
    {
        $sorted = $this->heap;
        usort($sorted, $this->compareEntries(...));

        return array_column(array_slice($sorted, ($this->filters->page - 1) * $this->filters->perPage, $this->filters->perPage), 'row');
    }

    public function retainedCount(): int
    {
        return count($this->heap);
    }

    public function count(): int
    {
        return $this->count;
    }
}
