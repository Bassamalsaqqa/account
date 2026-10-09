<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Application\Reporting\Presentation\ReportPresenter;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\CsvReportWriter;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportCsvController
{
    public function __invoke(Request $request, string $reportKey): StreamedResponse
    {
        $guard = app(ReportingGuard::class);
        $company = $guard->company(app(CompanyContext::class)->company());
        $registry = app(ReportRegistry::class);
        abort_unless($registry->allows($company, $reportKey), 403);

        $input = $request->query('filters', []);
        abort_unless(is_array($input), 422);

        $definition = $registry->definition($reportKey);
        $input = array_replace($definition['defaults'], $input);
        foreach ($definition['required'] as $field) {
            if (! isset($input[$field]) || $input[$field] === '') {
                abort(422, 'A required report selection is missing.');
            }
        }

        // Freeze period and dates to avoid clock rollover or mutable state
        if (in_array('period', $definition['filters'], true)) {
            try {
                $defaultPeriod = $definition['current']
                    ? ReportPeriod::fromPreset(ReportPeriod::PRESET_TODAY, $company)
                    : null;
                $validatedFilters = ReportFilters::fromArray($company, $input, $defaultPeriod);
                $from = $validatedFilters->period->startDate;
                $to = $validatedFilters->period->endDate;

                $input['from'] = $from;
                $input['to'] = $to;
                $input['start_date'] = $from;
                $input['end_date'] = $to;
                $input['preset'] = ReportPeriod::PRESET_CUSTOM;
                $input['period'] = [
                    'preset' => ReportPeriod::PRESET_CUSTOM,
                    'from' => $from,
                    'to' => $to,
                    'start_date' => $from,
                    'end_date' => $to,
                ];
            } catch (InvalidReportFilterException|ReportingException|InvalidArgumentException|ValidationException $exception) {
                abort(422, $exception->getMessage());
            }
        }

        $input['per_page'] = 100;
        $frozenInput = $input;
        $presenter = app(ReportPresenter::class);

        // Pre-preparation live authority fingerprint
        $captureFingerprint = static function () use ($guard, $company, $reportKey, $registry): array {
            $user = $guard->authorize($company);

            return [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'company_status' => Company::whereKey($company->id)->value('status'),
                'membership_status' => CompanyUser::where('company_id', $company->id)->where('user_id', $user->id)->value('status'),
                'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
                'allowed' => $registry->allows($company, $reportKey),
            ];
        };

        $exportDeadline = microtime(true) + 30.0;
        $preFingerprint = $captureFingerprint();

        $spool = tmpfile();
        if ($spool === false) {
            throw new \RuntimeException('Unable to create temporary export spool.');
        }

        $ownsTransaction = false;
        $modifiedSessionTimeout = false;
        $spoolClosed = false;
        $previousStatementTimeout = null;
        $budgetState = new class
        {
            public bool $active = false;

            public function isActive(): bool
            {
                return $this->active;
            }
        };

        try {
            if (DB::transactionLevel() === 0) {
                try {
                    $previousStatementTimeout = (string) DB::scalar('SELECT @@session.max_statement_time');
                    if (! preg_match('/^\d+(?:\.\d+)?$/D', $previousStatementTimeout)) {
                        throw new \RuntimeException('Invalid database statement timeout value.');
                    }
                    DB::statement('SET SESSION max_statement_time = 30');
                    $modifiedSessionTimeout = true;
                } catch (\Throwable $e) {
                    abort(500, 'Database statement timeout protection could not be established.');
                }

                // Limit each statement to the remaining preparation budget,
                // including statements issued internally by a report query.
                $budgetState->active = true;
                DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) use ($budgetState, $exportDeadline): void {
                    if (! $budgetState->isActive()) {
                        return;
                    }
                    $remaining = $exportDeadline - microtime(true);
                    if ($remaining <= 0) {
                        throw new \RuntimeException('Export execution time limit exceeded.');
                    }
                    // Timing arithmetic is not financial arithmetic. The fixed
                    // numeric format is safe SQL and independent of locale.
                    $connection->getPdo()->exec('SET SESSION max_statement_time = '.sprintf('%.6F', $remaining));
                });
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                DB::beginTransaction();
                $ownsTransaction = true;
            } elseif (! app()->environment('testing')) {
                abort(500, 'Cannot export CSV inside an active transaction.');
            }

            if (microtime(true) > $exportDeadline) {
                throw new \RuntimeException('Export execution time limit exceeded.');
            }

            $firstInput = $frozenInput;
            $firstInput['page'] = 1;
            $first = $registry->execute($company, $reportKey, $firstInput);
            $columns = $presenter->columns($definition['columns'], $first);

            app(CsvReportWriter::class)->write($spool, function (int $page) use ($company, $reportKey, $frozenInput, $registry, $presenter, $columns): ReportResult {
                $pageInput = $frozenInput;
                $pageInput['page'] = $page;
                $result = $registry->execute($company, $reportKey, $pageInput);
                $rows = array_map(fn (array $row): array => $presenter->exportRow($row, $columns), $result->rows);

                return new ReportResult($result->reportType, $result->filters, [], $rows, $result->currency, $result->pagination);
            }, $columns, $exportDeadline);
        } catch (\Throwable $e) {
            fclose($spool);
            $spoolClosed = true;

            if ($e instanceof InvalidReportFilterException || $e instanceof ReportingException || $e instanceof InvalidArgumentException || $e instanceof ValidationException) {
                abort(422, $e->getMessage());
            }
            if ($e instanceof AuthorizationException) {
                abort(403, $e->getMessage());
            }
            if ($e instanceof \RuntimeException && (
                str_contains($e->getMessage(), 'Export exceeded maximum') ||
                str_contains($e->getMessage(), 'Export execution time limit') ||
                str_contains($e->getMessage(), 'Export byte limit')
            )) {
                abort(422, $e->getMessage());
            }
            if (str_contains($e->getMessage(), 'max_statement_time') || str_contains($e->getMessage(), 'Query execution was interrupted')) {
                abort(422, 'Export execution time limit exceeded.');
            }

            throw $e;
        } finally {
            $budgetState->active = false;
            $cleanupFailed = false;
            if ($ownsTransaction) {
                try {
                    DB::rollBack();
                } catch (\Throwable $e) {
                    $cleanupFailed = true;
                    try {
                        DB::purge();
                    } catch (\Throwable) {
                    }
                }
            }
            if ($modifiedSessionTimeout && $previousStatementTimeout !== null) {
                try {
                    DB::statement('SET SESSION max_statement_time = '.$previousStatementTimeout);
                } catch (\Throwable) {
                    $cleanupFailed = true;
                    try {
                        DB::purge();
                    } catch (\Throwable) {
                    }
                }
            }
            if ($cleanupFailed) {
                if (! $spoolClosed) {
                    fclose($spool);
                    $spoolClosed = true;
                }
                abort(500, 'Failed to close export transaction snapshot cleanly.');
            }
        }

        // Fresh live authority re-validation outside the snapshot transaction
        try {
            $postFingerprint = $captureFingerprint();
            if ($postFingerprint !== $preFingerprint) {
                fclose($spool);
                $spoolClosed = true;
                abort(403, 'Authority changed or revoked during export preparation.');
            }
        } catch (\Throwable $e) {
            if (! $spoolClosed) {
                fclose($spool);
                $spoolClosed = true;
            }
            if ($e instanceof AuthorizationException) {
                abort(403, 'Authority changed or revoked during export preparation.');
            }

            throw $e;
        }

        rewind($spool);

        $filename = 'report-'.str_replace('.', '-', $reportKey).'.csv';

        return response()->streamDownload(function () use ($spool, $captureFingerprint, $preFingerprint): void {
            try {
                $live = $captureFingerprint();
                if ($live !== $preFingerprint) {
                    abort(403, 'Authority changed or revoked before export delivery.');
                }

                $bytesSent = 0;
                $checkpointInterval = 1048576; // 1 MB
                $lastCheckpoint = 0;

                while (! feof($spool)) {
                    $chunk = fread($spool, 65536);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    flush();

                    $bytesSent += strlen($chunk);
                    if ($bytesSent - $lastCheckpoint >= $checkpointInterval) {
                        $lastCheckpoint = $bytesSent;
                        $check = $captureFingerprint();
                        if ($check !== $preFingerprint) {
                            break;
                        }
                    }
                }
            } finally {
                fclose($spool);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
