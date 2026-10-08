<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Support\CsvReportWriter;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CsvReportWriterTest extends TestCase
{
    public function test_all_pages_are_fetched_and_exact_values_and_text_survive(): void
    {
        $stream = fopen('php://temp', 'w+');
        $pages = [];
        (new CsvReportWriter)->write($stream, function (int $page) use (&$pages): ReportResult {
            $pages[] = $page;

            return $this->reportResult([['party' => $page === 1 ? '=unsafe' : "اسم,\nثان", 'amount' => '-0.001', 'currency' => 'JOD']], $page, 3);
        }, $this->columns());
        rewind($stream);
        $this->assertSame("\xEF\xBB\xBF", fread($stream, 3));
        $this->assertSame(['Party', 'Amount', 'Currency'], fgetcsv($stream, null, ',', '"', ''));
        $this->assertSame(["'=unsafe", '-0.001', 'JOD'], fgetcsv($stream, null, ',', '"', ''));
        $this->assertSame(["اسم,\nثان", '-0.001', 'JOD'], fgetcsv($stream, null, ',', '"', ''));
        $this->assertSame(["اسم,\nثان", '-0.001', 'JOD'], fgetcsv($stream, null, ',', '"', ''));
        $this->assertFalse(fgetcsv($stream, null, ',', '"', ''));
        $this->assertSame([1, 2, 3], $pages);
        fclose($stream);
    }

    public function test_denied_first_fetch_does_not_emit_a_header(): void
    {
        $stream = fopen('php://temp', 'w+');
        try {
            (new CsvReportWriter)->write($stream, fn (int $page) => throw new AuthorizationException, $this->columns());
            $this->fail('Unauthorized fetch was accepted.');
        } catch (AuthorizationException) {
            rewind($stream);
            $this->assertSame('', stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_revocation_between_chunks_is_not_swallowed(): void
    {
        $stream = fopen('php://temp', 'w+');
        $pages = [];
        try {
            (new CsvReportWriter)->write($stream, function (int $page) use (&$pages): ReportResult {
                $pages[] = $page;
                if ($page === 2) {
                    throw new AuthorizationException;
                }

                return $this->reportResult([['party' => 'Allowed', 'amount' => '1.000000', 'currency' => 'ILS']], 1, 2);
            }, $this->columns());
            $this->fail('Revoked access was accepted.');
        } catch (AuthorizationException) {
            $this->assertSame([1, 2], $pages);
        } finally {
            fclose($stream);
        }
    }

    public function test_structured_or_float_payloads_cannot_bypass_exact_export_types(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->expectException(InvalidArgumentException::class);
        try {
            (new CsvReportWriter)->write($stream, fn (int $page) => $this->reportResult([['party' => ['secret' => 'value'], 'amount' => '1', 'currency' => 'ILS']], 1, 1), $this->columns());
        } finally {
            fclose($stream);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function reportResult(array $rows, int $page, int $last): ReportResult
    {
        return new ReportResult('fixture', [], [], $rows, [], ['current_page' => $page, 'last_page' => $last]);
    }

    /** @return list<array{key: string, label: string, type: 'text'|'decimal'}> */
    private function columns(): array
    {
        return [
            ['key' => 'party', 'label' => 'Party', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'decimal'],
            ['key' => 'currency', 'label' => 'Currency', 'type' => 'text'],
        ];
    }
}
