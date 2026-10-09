<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Support\CsvReportWriter;
use App\Application\Reporting\Support\TradePageAccumulator;
use PHPUnit\Framework\TestCase;

final class Correction03ResourceLimitsTest extends TestCase
{
    private function filters(int $page, int $size = 100): ReportFilters
    {
        return new ReportFilters(new ReportPeriod('custom', '2026-10-01', '2026-10-31', 'Asia/Hebron'), page: $page, perPage: $size);
    }

    public function test_extreme_pages_are_rejected_before_retention_or_overflow(): void
    {
        foreach ([501, 1000000000, PHP_INT_MAX] as $page) {
            try {
                $this->filters($page);
                $this->fail('Unsafe page accepted');
            } catch (InvalidReportFilterException $e) {
                $this->assertStringContainsString('safe', $e->getMessage());
            }
        }
        $this->assertSame(500, $this->filters(500)->page);
    }

    public function test_heap_is_bounded_stable_and_retains_exact_currency_order(): void
    {
        $compare = static fn (array $a, array $b): int => [$a['currency'], $a['rank']] <=> [$b['currency'], $b['rank']];
        $rows = [];
        $heap = new TradePageAccumulator($this->filters(2, 25), $compare);
        for ($i = 0; $i < 10000; $i++) {
            $row = ['id' => $i, 'currency' => $i % 2 === 0 ? 'USD' : 'ILS', 'rank' => $i % 99];
            $rows[] = $row;
            $heap->add($row);
        }
        usort($rows, $compare);
        $this->assertSame(array_slice($rows, 25, 25), $heap->rows());
        $this->assertSame(50, $heap->retainedCount());
        $this->assertSame(10000, $heap->count());
        $this->assertSame($heap->rows(), $heap->rows());
        $empty = new TradePageAccumulator($this->filters(3, 25), $compare);
        foreach (array_slice($rows, 0, 30) as $row) {
            $empty->add($row);
        }
        $this->assertSame([], $empty->rows());
        $this->assertSame(30, $empty->count());
    }

    public function test_csv_capacity_is_fifty_thousand_and_oversized_export_fails_before_headers(): void
    {
        $stream = tmpfile();
        $writer = new CsvReportWriter;
        $columns = [['key' => 'id', 'label' => 'ID', 'type' => 'text']];
        $writer->write($stream, static function (int $page): ReportResult {
            $rows = [];
            for ($i = 1; $i <= 100; $i++) {
                $rows[] = ['id' => (string) (($page - 1) * 100 + $i)];
            }

            return new ReportResult('sales.unpaid', [], [], $rows, [], ['current_page' => $page, 'per_page' => 100, 'total' => 50000, 'last_page' => 500]);
        }, $columns);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        $this->assertSame(50001, substr_count($content, "\r\n"));
        $this->assertStringEndsWith("50000\r\n", $content);
        $stream = tmpfile();
        try {
            $writer->write($stream, static fn (int $page): ReportResult => new ReportResult('sales.unpaid', [], [], [], [], ['total' => 50001]), $columns);
            $this->fail('Oversized export accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Export exceeded maximum row limit.', $e->getMessage());
            $this->assertSame(0, ftell($stream));
        } finally {
            fclose($stream);
        }
    }
}
