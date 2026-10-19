<?php

declare(strict_types=1);

namespace App\Application\CompanyDataExport\Services;

use App\Application\CompanyDataExport\Datasets\ExportDatasetRegistry;
use App\Application\CompanyDataExport\DTO\ExportResult;
use App\Application\CompanyDataExport\Security\ExportGuard;
use App\Application\CompanyDataExport\Support\ExportSnapshotSession;
use App\Application\Reporting\Support\CsvCellFormatter;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;
use UnexpectedValueException;
use ZipArchive;

final class CompanyDataExportService
{
    public const float HARD_MAX_SECONDS = 30.0;

    public const int HARD_MAX_TOTAL_ROWS = 50_000;

    public const int HARD_MAX_ROWS_PER_DATASET = 50_000;

    public const int HARD_MAX_COMPRESSED_BYTES = 52_428_800; // 50 MB

    public const int HARD_MAX_UNCOMPRESSED_BYTES = 52_428_800; // 50 MB

    public const int HARD_MAX_MEMORY_BYTES = 134_217_728; // 128 MB

    public const int MIN_CHUNK_SIZE = 100;

    public const int MAX_CHUNK_SIZE = 250;

    public function __construct(
        private readonly ExportGuard $guard,
        private readonly ExportSnapshotSession $snapshotSession,
        private readonly AuditService $auditService
    ) {}

    /**
     * Generate the complete disk-backed business data export archive.
     *
     * @param  array<string, mixed>  $preFingerprint
     *
     * @throws Throwable
     */
    public function export(
        Company $company,
        User $actor,
        array $preFingerprint,
        ?Request $request = null
    ): ExportResult {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('company_data_export.zip_requirement_missing'));
        }

        if ($request !== null) {
            $this->guard->bindRequest($request);
        }

        // 1. Validate finite configuration and reject values exceeding hard ceilings
        $maxSeconds = (float) config('company_data_export.max_execution_seconds', self::HARD_MAX_SECONDS);
        $maxRowsPerDataset = (int) config('company_data_export.max_rows_per_dataset', self::HARD_MAX_ROWS_PER_DATASET);
        $maxTotalRows = (int) config('company_data_export.max_total_rows', self::HARD_MAX_TOTAL_ROWS);
        $maxBytes = (int) config('company_data_export.max_bytes', self::HARD_MAX_COMPRESSED_BYTES);
        $maxUncompressedBytes = (int) config('company_data_export.max_uncompressed_bytes', self::HARD_MAX_UNCOMPRESSED_BYTES);
        $maxMemoryBytes = (int) config('company_data_export.max_memory_bytes', self::HARD_MAX_MEMORY_BYTES);
        $chunkSize = (int) config('company_data_export.chunk_size', self::MAX_CHUNK_SIZE);

        if (! is_finite($maxSeconds) || $maxSeconds <= 0 || $maxSeconds > self::HARD_MAX_SECONDS
            || $maxRowsPerDataset <= 0 || $maxRowsPerDataset > self::HARD_MAX_ROWS_PER_DATASET
            || $maxTotalRows <= 0 || $maxTotalRows > self::HARD_MAX_TOTAL_ROWS
            || $maxBytes <= 0 || $maxBytes > self::HARD_MAX_COMPRESSED_BYTES
            || $maxUncompressedBytes <= 0 || $maxUncompressedBytes > self::HARD_MAX_UNCOMPRESSED_BYTES
            || $maxMemoryBytes <= 0 || $maxMemoryBytes > self::HARD_MAX_MEMORY_BYTES
            || $chunkSize < self::MIN_CHUNK_SIZE || $chunkSize > self::MAX_CHUNK_SIZE) {
            throw new RuntimeException('Invalid or excessive resource cap configuration for company data export.');
        }

        $deadline = microtime(true) + $maxSeconds;

        $baseTemp = (string) config('company_data_export.temp_directory', storage_path('app/private/company_exports'));
        if (! is_dir($baseTemp) && ! mkdir($baseTemp, 0700, true) && ! is_dir($baseTemp)) {
            throw new RuntimeException('Unable to create base export directory.');
        }

        $tempDir = $baseTemp.DIRECTORY_SEPARATOR.'export_'.(int) $company->id.'_'.bin2hex(random_bytes(12));
        if (! mkdir($tempDir, 0700, true)) {
            throw new RuntimeException('Unable to create temporary export directory.');
        }

        // Register shutdown function to guarantee directory cleanup on fatal error or unconsumed response
        $zipPath = null;
        $cleanupCallback = static function () use ($tempDir): void {
            if (is_dir($tempDir)) {
                self::cleanDirectory($tempDir);
            }
        };
        register_shutdown_function($cleanupCallback);

        $datasetManifest = [];
        $totalRecords = 0;
        $totalUncompressedBytes = 0;

        try {
            // 2. Establish the MariaDB consistent snapshot and capture data-as-of timestamp
            $dataAsOf = now('UTC')->toIso8601String();
            $this->snapshotSession->begin($maxSeconds, $deadline);

            // 3. Stream each dataset to disk as a typed CSV
            foreach (ExportDatasetRegistry::all() as $dataset) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException(__('company_data_export.execution_timeout'));
                }
                if (memory_get_usage(true) > $maxMemoryBytes) {
                    throw new RuntimeException(__('company_data_export.memory_limit_exceeded'));
                }

                // Update MariaDB statement timeout with remaining deadline
                $remainingTimeout = max(1, (int) ceil($deadline - microtime(true)));
                $this->snapshotSession->applyStatementTimeout($remainingTimeout);

                $csvPath = $tempDir.DIRECTORY_SEPARATOR.$dataset['filename'];
                $fp = fopen($csvPath, 'w');
                if ($fp === false) {
                    throw new RuntimeException("Unable to create export file [{$dataset['filename']}].");
                }

                try {
                    // Write UTF-8 BOM
                    if (fwrite($fp, "\xEF\xBB\xBF") === false) {
                        throw new RuntimeException("Unable to write encoding marker for [{$dataset['filename']}].");
                    }
                    $totalUncompressedBytes += 3;

                    // Write CSV headers
                    $headers = array_map(
                        fn (array $col): string => CsvCellFormatter::text($col['label']),
                        $dataset['columns']
                    );
                    $headerWritten = fputcsv($fp, $headers, ',', '"', '', "\r\n");
                    if ($headerWritten === false) {
                        throw new RuntimeException("Unable to write CSV headers for [{$dataset['filename']}].");
                    }
                    $totalUncompressedBytes += $headerWritten;

                    $datasetRows = 0;
                    foreach ($dataset['fetch']($company, $chunkSize) as $chunk) {
                        foreach ($chunk as $row) {
                            if (microtime(true) >= $deadline) {
                                throw new RuntimeException(__('company_data_export.execution_timeout'));
                            }
                            if (memory_get_usage(true) > $maxMemoryBytes) {
                                throw new RuntimeException(__('company_data_export.memory_limit_exceeded'));
                            }

                            $datasetRows++;
                            $totalRecords++;
                            if ($datasetRows > $maxRowsPerDataset || $totalRecords > $maxTotalRows) {
                                throw new RuntimeException(__('company_data_export.row_limit_exceeded'));
                            }

                            $values = [];
                            foreach ($dataset['columns'] as $col) {
                                $colKey = $col['key'];
                                if (! array_key_exists($colKey, $row)) {
                                    throw new UnexpectedValueException("Missing required column [{$colKey}] in dataset [{$dataset['key']}].");
                                }

                                $val = $row[$colKey];
                                $strVal = $val === null ? null : (string) $val;

                                $values[] = self::formatCell($col['type'], $strVal);
                            }

                            $rowWritten = fputcsv($fp, $values, ',', '"', '', "\r\n");
                            if ($rowWritten === false) {
                                throw new RuntimeException("Unable to write CSV row in [{$dataset['filename']}].");
                            }

                            $totalUncompressedBytes += $rowWritten;
                            if ($totalUncompressedBytes > $maxUncompressedBytes) {
                                throw new RuntimeException(__('company_data_export.byte_limit_exceeded'));
                            }
                        }
                    }

                    $datasetManifest[] = [
                        'key' => $dataset['key'],
                        'filename' => $dataset['filename'],
                        'row_count' => $datasetRows,
                        'columns' => array_map(fn ($c) => $c['key'], $dataset['columns']),
                        'description' => $dataset['description'],
                    ];
                } finally {
                    fclose($fp);
                }
            }

            // 4. Generate manifest.json
            $manifestData = [
                'manifest_version' => '1.0',
                'data_as_of' => $dataAsOf,
                'generated_at' => now('UTC')->toIso8601String(),
                'company' => [
                    'id' => (int) $company->id,
                    'public_id' => (string) $company->public_id,
                    'name_ar' => (string) $company->name_ar,
                    'name_en' => (string) ($company->name_en ?? ''),
                    'legal_name_ar' => (string) ($company->legal_name_ar ?? $company->name_ar),
                    'legal_name_en' => (string) ($company->legal_name_en ?? $company->name_en ?? ''),
                    'base_currency_code' => (string) $company->base_currency_code,
                    'timezone' => (string) ($company->timezone ?? 'UTC'),
                ],
                'exported_by' => [
                    'user_id' => (int) $actor->id,
                    'name' => (string) $actor->name,
                    'email' => (string) $actor->email,
                    'role' => 'Owner',
                ],
                'source_version' => [
                    'baseline_commit' => 'd27adbad93fce8bd7aaf8afa6cdfaa0e33b91bde',
                    'feature' => 'A.2 Company Data Export V1',
                    'application' => (string) config('app.name', 'Small Trader Accounting'),
                ],
                'datasets' => $datasetManifest,
                'total_datasets' => count($datasetManifest),
                'total_records' => $totalRecords,
                'total_uncompressed_bytes' => $totalUncompressedBytes,
                'completeness' => [
                    'status' => 'complete',
                    'partial' => false,
                    'truncated' => false,
                ],
                'exclusions' => config('company_data_export.exclusions', []),
                'limits' => [
                    'max_rows_per_dataset' => $maxRowsPerDataset,
                    'max_total_rows' => $maxTotalRows,
                    'max_bytes' => $maxBytes,
                    'max_uncompressed_bytes' => $maxUncompressedBytes,
                    'max_execution_seconds' => (int) $maxSeconds,
                ],
                'reconstruction_limitations' => [
                    'This export represents a point-in-time read-only business reference archive, NOT a restorable system backup.',
                    'Calculated customer/vendor currency balances and stock on hand represent authoritative point-in-time projections derived from immutable ledger batches and stock movements at the moment of export.',
                    'Do not sum monetary amounts across unlike currencies without currency-specific exchange rate conversion.',
                    'Decimal columns preserve exact fractional strings as stored in the database.',
                    'Relational identifiers link entities within this archive but must not be used to blindly overwrite existing database primary keys.',
                ],
            ];

            $manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($totalUncompressedBytes + strlen($manifestJson) > $maxUncompressedBytes) {
                throw new RuntimeException(__('company_data_export.byte_limit_exceeded'));
            }
            $manifestPath = $tempDir.DIRECTORY_SEPARATOR.'manifest.json';
            if (file_put_contents($manifestPath, $manifestJson) === false) {
                throw new RuntimeException('Unable to write manifest.json.');
            }

            // 5. Create ZIP package
            if (microtime(true) >= $deadline) {
                throw new RuntimeException(__('company_data_export.execution_timeout'));
            }
            if (memory_get_usage(true) > $maxMemoryBytes) {
                throw new RuntimeException(__('company_data_export.memory_limit_exceeded'));
            }

            $zipFilename = 'company_'.(int) $company->id.'_data_export_'.now('UTC')->format('Ymd_His').'.zip';
            $zipPath = $tempDir.DIRECTORY_SEPARATOR.$zipFilename;

            $zip = new ZipArchive;
            $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($opened !== true) {
                throw new RuntimeException(__('company_data_export.archive_creation_failed'));
            }

            // PHP 8.4 ext-zip supports cancellation during compression.
            $zip->registerCancelCallback(static function () use ($deadline, $maxMemoryBytes): int {
                return microtime(true) >= $deadline || memory_get_usage(true) > $maxMemoryBytes ? 1 : 0;
            });

            foreach ($datasetManifest as $entry) {
                if (microtime(true) >= $deadline) {
                    $zip->close();
                    throw new RuntimeException(__('company_data_export.execution_timeout'));
                }
                if (memory_get_usage(true) > $maxMemoryBytes) {
                    $zip->close();
                    throw new RuntimeException(__('company_data_export.memory_limit_exceeded'));
                }

                $fileOnDisk = $tempDir.DIRECTORY_SEPARATOR.$entry['filename'];
                if (! $zip->addFile($fileOnDisk, $entry['filename'])) {
                    $zip->close();
                    throw new RuntimeException(__('company_data_export.archive_creation_failed'));
                }
            }

            if (microtime(true) >= $deadline) {
                $zip->close();
                throw new RuntimeException(__('company_data_export.execution_timeout'));
            }

            if (! $zip->addFile($manifestPath, 'manifest.json')) {
                $zip->close();
                throw new RuntimeException(__('company_data_export.archive_creation_failed'));
            }

            if (! $zip->close()) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException(__('company_data_export.execution_timeout'));
                }
                throw new RuntimeException(__('company_data_export.archive_creation_failed'));
            }

            if (! is_file($zipPath) || ($size = filesize($zipPath)) === false || $size === 0) {
                throw new RuntimeException(__('company_data_export.archive_creation_failed'));
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException(__('company_data_export.execution_timeout'));
            }
            if (memory_get_usage(true) > $maxMemoryBytes) {
                throw new RuntimeException(__('company_data_export.memory_limit_exceeded'));
            }

            if ($size > $maxBytes) {
                throw new RuntimeException(__('company_data_export.byte_limit_exceeded'));
            }

            // Delete individual CSVs and manifest after bundling into ZIP to free temp disk space
            foreach ($datasetManifest as $entry) {
                @unlink($tempDir.DIRECTORY_SEPARATOR.$entry['filename']);
            }
            @unlink($manifestPath);

            // 6. Restore database snapshot session BEFORE fresh authority re-check
            $this->snapshotSession->restore();

            // 7. Fresh live authority re-validation outside the snapshot transaction
            $this->guard->assertFingerprintValid($preFingerprint, $company, $actor, $request);

            // 8. Record immutable audit event ('company.data_export.prepared')
            $this->auditService->log(
                companyId: (int) $company->id,
                eventKey: 'company.data_export.prepared',
                summary: __('company_data_export.audit_summary'),
                actorUserId: (int) $actor->id,
                subject: null,
                before: null,
                after: null,
                meta: [
                    'archive_name' => $zipFilename,
                    'datasets_count' => count($datasetManifest),
                    'total_records' => $totalRecords,
                    'total_uncompressed_bytes' => $totalUncompressedBytes,
                    'byte_size' => $size,
                    'data_as_of' => $dataAsOf,
                    'baseline_commit' => 'd27adbad93fce8bd7aaf8afa6cdfaa0e33b91bde',
                ]
            );

            return new ExportResult(
                zipPath: $zipPath,
                tempDir: $tempDir,
                manifest: $manifestData,
                archiveFilename: $zipFilename,
                byteSize: $size
            );
        } catch (Throwable $e) {
            // Fail closed: restore session and clean up all temporary artifacts immediately
            try {
                $this->snapshotSession->restore();
            } catch (Throwable) {
            }

            self::cleanDirectory($tempDir);
            throw $e;
        }
    }

    /**
     * Format a CSV cell value preserving exact decimals and Excel-safe leading zero identifiers.
     */
    private static function formatCell(string $type, ?string $value): string
    {
        if ($type === 'decimal') {
            return CsvCellFormatter::decimal($value);
        }

        if ($value === null || $value === '') {
            return '';
        }

        // Leading-zero identifier fields (e.g. '0123', '0599123456', '00123')
        // Prefix with apostrophe to preserve leading zeros in Excel without using formula syntax
        if (preg_match('/^0\d+$/D', $value) === 1) {
            return "'".$value;
        }

        return CsvCellFormatter::text($value);
    }

    /**
     * Recursively delete a directory and all its contents without following symlinks.
     * Bounded recursion depth protects against circular references or runaway scans.
     */
    public static function cleanDirectory(string $dir, int $depth = 0, int $maxDepth = 4): void
    {
        if ($depth > $maxDepth) {
            return;
        }

        if (! is_dir($dir) || is_link($dir)) {
            if (is_link($dir)) {
                @unlink($dir);
            }

            return;
        }

        $items = @scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                self::cleanDirectory($path, $depth + 1, $maxDepth);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
