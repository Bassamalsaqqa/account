<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Application\Reporting\Presentation\ReportPresenter;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\CsvReportWriter;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportCsvController
{
    public function __invoke(Request $request, string $reportKey): StreamedResponse
    {
        $company = app(ReportingGuard::class)->company(app(CompanyContext::class)->company());
        $input = $request->query('filters', []);
        abort_unless(is_array($input), 422);
        $input['page'] = 1;
        $input['per_page'] = 100;
        $registry = app(ReportRegistry::class);
        $presenter = app(ReportPresenter::class);
        // Authorize and validate before beginning the HTTP response.
        try {
            $first = $registry->execute($company, $reportKey, $input);
        } catch (InvalidReportFilterException|ReportingException|InvalidArgumentException|ValidationException $exception) {
            abort(422, $exception->getMessage());
        }
        $definition = $registry->definition($reportKey);
        $columns = $presenter->columns($definition['columns'], $first);

        return response()->streamDownload(function () use ($company, $reportKey, $input, $registry, $presenter, $columns): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to open export stream.');
            }
            try {
                app(CsvReportWriter::class)->write($stream, function (int $page) use ($company, $reportKey, $input, $registry, $presenter, $columns): ReportResult {
                    $input['page'] = $page;
                    // Every chunk executes the canonical guarded query, including the first.
                    $result = $registry->execute($company, $reportKey, $input);
                    $rows = array_map(fn (array $row): array => $presenter->exportRow($row, $columns), $result->rows);

                    return new ReportResult($result->reportType, $result->filters, [], $rows, $result->currency, $result->pagination);
                }, $columns);
            } finally {
                fclose($stream);
            }
        }, 'report-'.str_replace('.', '-', $reportKey).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
