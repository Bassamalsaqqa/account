<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportResult;
use InvalidArgumentException;
use RuntimeException;

final class CsvReportWriter
{
    public const int MAX_ROWS = 50_000;

    public const int MAX_BYTES = 52_428_800; // 50 MB

    public const int MAX_SECONDS = 30;

    /**
     * The fetch callback must invoke the guarded report query with identical
     * filters and a bounded page size on every call, including the first page.
     *
     * @param  resource  $stream
     * @param  callable(int): ReportResult  $fetch
     * @param  list<array{key: string, label: string, type: 'text'|'decimal'}>  $columns
     */
    public function write($stream, callable $fetch, array $columns, ?float $deadline = null): void
    {
        if (! is_resource($stream) || $columns === []) {
            throw new InvalidArgumentException('A writable stream and explicit export columns are required.');
        }
        $startTime = microtime(true);
        $effectiveDeadline = $deadline ?? ($startTime + self::MAX_SECONDS);
        $totalRows = 0;

        // Authorize and fetch before sending even a header.
        if (microtime(true) > $effectiveDeadline) {
            throw new RuntimeException('Export execution time limit exceeded.');
        }
        $result = $fetch(1);
        if (($result->pagination['total'] ?? 0) > self::MAX_ROWS) {
            throw new RuntimeException('Export exceeded maximum row limit.');
        }
        if (fwrite($stream, "\xEF\xBB\xBF") === false) {
            throw new RuntimeException('Unable to write CSV encoding marker.');
        }
        $this->row($stream, array_map(fn (array $column): string => CsvCellFormatter::text($column['label']), $columns));

        $page = 1;
        while (true) {
            foreach ($result->rows as $row) {
                if (microtime(true) > $effectiveDeadline) {
                    throw new RuntimeException('Export execution time limit exceeded.');
                }
                $totalRows++;
                if ($totalRows > self::MAX_ROWS) {
                    throw new RuntimeException('Export exceeded maximum row limit.');
                }
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column['key']] ?? null;
                    if ($value !== null && ! is_string($value) && ! is_int($value)) {
                        throw new InvalidArgumentException('CSV values must be exact strings or integer identifiers.');
                    }
                    $value = $value === null ? null : (string) $value;
                    $values[] = $this->cell($column['type'], $value);
                }
                $this->row($stream, $values);
                $position = ftell($stream);
                if ($position !== false && $position > self::MAX_BYTES) {
                    throw new RuntimeException('Export byte limit exceeded.');
                }
            }

            if (microtime(true) > $effectiveDeadline) {
                throw new RuntimeException('Export execution time limit exceeded.');
            }
            $bytes = ftell($stream);
            if ($bytes !== false && $bytes > self::MAX_BYTES) {
                throw new RuntimeException('Export byte limit exceeded.');
            }

            if ($result->pagination === null) {
                break;
            }
            $current = $result->pagination['current_page'] ?? null;
            $last = $result->pagination['last_page'] ?? null;
            if (! is_int($current) || ! is_int($last) || $current !== $page || $last < $page) {
                throw new InvalidArgumentException('Invalid report pagination for CSV export.');
            }
            if ($page === $last) {
                break;
            }
            // The query reauthorizes each chunk; cached public Livewire state is
            // never accepted as the financial payload or permission decision.
            $result = $fetch(++$page);
        }
    }

    private function cell(string $type, ?string $value): string
    {
        return match ($type) {
            'decimal' => CsvCellFormatter::decimal($value),
            'text' => CsvCellFormatter::text($value),
            default => throw new InvalidArgumentException('Unsupported CSV column type.'),
        };
    }

    /**
     * @param  resource  $stream
     * @param  list<string>  $values
     */
    private function row($stream, array $values): void
    {
        if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Unable to write CSV row.');
        }
    }
}
