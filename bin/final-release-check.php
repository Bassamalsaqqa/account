#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Small Trader Accounting — Read-Only Final Release Verification & Fingerprint Helper
 *
 * Standalone root-operated utility to verify:
 * 1. Strict deployed source commit, build manifest, and .htaccess pins.
 * 2. Production host app and public web root layout confirmation.
 * 3. Read-only database session:
 *    - Strict 0-socket / 0-connection preflight by default (execution requires explicit --execute).
 *    - SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ.
 *    - START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY.
 *    - Strict statement timeouts: tries MariaDB max_statement_time then MySQL max_execution_time,
 *      failing closed if neither is supported.
 * 4. Deterministic per-table row byte hash and DDL preservation:
 *    - Compound PK sorted strictly by Seq_in_index.
 *    - Tables without PK sorted deterministically by BINARY columns to prevent collation ties.
 *    - Length-framed, distinct null representation (R<cols>; N; S<len>:<val>).
 *    - Strict preservation: AUTO_INCREMENT=\d+ counters preserved on all preservation tables
 *      (normalized only on fixed operational mutable tables).
 *    - Fixed operational mutable allowlist: sessions, cache, cache_locks, jobs, job_batches,
 *      failed_jobs, password_reset_tokens.
 *    - Comparison: Operational allowlist applies ROW churn only. Any schema/DDL diff or missing table
 *      (even on operational tables) FAILS preservation.
 *    - Classification taint protection: rejects any client-forged operational table classification.
 * 5. Execution of core reconciliation services:
 *    - Mode `reconcile`: executes all 6 services across all companies context-free using a minimal
 *      isolated container wrapping the SAME read-only PDO connection.
 *    - Mode `reconcile-contracts`: reflection contract verification of all 6 services.
 * 6. Secure output file creation:
 *    - Rejects symlinks, verifies nonpublic private directory permissions, uses exclusive creation (mode 'x').
 */

namespace SmallTrader\Recovery;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class FinalReleaseCheck
{
    public const array OPERATIONAL_MUTABLE_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ];

    public const array ALLOWED_MODES = [
        'snapshot',
        'compare',
        'reconcile',
        'reconcile-contracts',
    ];

    private string $appDir;

    private string $publicDir;

    private ?string $expectedSourceSha;

    private ?string $expectedBuildSha;

    private ?string $expectedHtaccessSha;

    private string $mode;

    private bool $execute;

    private ?string $baselineFile;

    private ?string $targetFile;

    private ?string $outputFile;

    private string $dbName = '';

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        umask(0077);

        $this->mode = (string) ($options['mode'] ?? 'snapshot');
        if (! in_array($this->mode, self::ALLOWED_MODES, true)) {
            $sanitized = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $this->mode) ?? 'UNKNOWN');
            throw new RuntimeException("UNKNOWN_MODE_{$sanitized}");
        }

        $this->execute = (bool) ($options['execute'] ?? false);

        $this->expectedSourceSha = isset($options['expected-source-sha'])
            ? (string) $options['expected-source-sha']
            : null;

        $this->expectedBuildSha = isset($options['expected-build-sha'])
            ? (string) $options['expected-build-sha']
            : null;

        $this->expectedHtaccessSha = isset($options['expected-htaccess-sha'])
            ? (string) $options['expected-htaccess-sha']
            : null;

        $this->baselineFile = isset($options['baseline']) ? (string) $options['baseline'] : null;
        $this->targetFile = isset($options['target']) ? (string) $options['target'] : null;
        $this->outputFile = isset($options['output-file']) ? (string) $options['output-file'] : null;

        $rawApp = (string) ($options['app-dir'] ?? getcwd() ?: '.');
        $rawPublic = (string) ($options['public-dir'] ?? dirname($rawApp).'/public_html/account');

        $this->appDir = $this->assertDirectory($rawApp, 'APP_DIR');
        $this->publicDir = $this->assertDirectory($rawPublic, 'PUBLIC_DIR');

        if ($this->outputFile !== null) {
            $this->assertSafeOutputFile($this->outputFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        // 1. Confirm deployed pins and exact host app layout
        $layoutReport = $this->verifyPinsAndLayout();

        // 2. Default without --execute: 0-socket / 0-connection dry-run preflight
        if (! $this->execute && $this->mode !== 'compare') {
            return [
                'status' => 'SUCCESS',
                'mode' => strtoupper($this->mode),
                'execution' => 'DRY_RUN_ZERO_SOCKET',
                'socket_connected' => false,
                'layout' => $layoutReport,
                'message' => 'PREFLIGHT_VERIFIED_ZERO_SOCKET',
            ];
        }

        // 3. Execute selected verification mode
        return match ($this->mode) {
            'compare' => $this->runCompareMode($layoutReport),
            'reconcile' => $this->runReconcileMode($layoutReport),
            'reconcile-contracts' => $this->runReconcileContractsMode($layoutReport),
            'snapshot' => $this->runSnapshotMode($layoutReport),
            default => throw new RuntimeException("UNKNOWN_MODE_{$this->mode}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyPinsAndLayout(): array
    {
        // Confirm host application layout files
        $requiredAppFiles = ['.env', 'artisan', 'composer.json', 'bootstrap/app.php'];
        foreach ($requiredAppFiles as $f) {
            if (! is_file($this->appDir.'/'.$f)) {
                $token = strtoupper(str_replace(['.', '/', '-'], '_', $f));
                throw new RuntimeException("APPLICATION_LAYOUT_INVALID_MISSING_{$token}");
            }
        }

        // Confirm public web root layout files
        $requiredPublicFiles = ['.htaccess', 'build/manifest.json'];
        foreach ($requiredPublicFiles as $f) {
            if (! is_file($this->publicDir.'/'.$f)) {
                $token = strtoupper(str_replace(['.', '/', '-'], '_', $f));
                throw new RuntimeException("PUBLIC_LAYOUT_INVALID_MISSING_{$token}");
            }
        }

        // Check git HEAD commit
        $gitHead = $this->resolveGitHead();
        if ($this->execute && $this->expectedSourceSha === null) {
            throw new RuntimeException('EXECUTE_REQUIRES_SOURCE_SHA_PIN');
        }
        if ($this->expectedSourceSha !== null && ! hash_equals($this->expectedSourceSha, $gitHead)) {
            throw new RuntimeException('SOURCE_SHA_PIN_MISMATCH');
        }

        // Check build manifest sha256
        $buildManifestPath = $this->publicDir.'/build/manifest.json';
        $actualBuildSha = (string) hash_file('sha256', $buildManifestPath);
        if ($this->execute && $this->expectedBuildSha === null) {
            throw new RuntimeException('EXECUTE_REQUIRES_BUILD_SHA_PIN');
        }
        if ($this->expectedBuildSha !== null && ! hash_equals($this->expectedBuildSha, $actualBuildSha)) {
            throw new RuntimeException('BUILD_SHA_PIN_MISMATCH');
        }

        // Check .htaccess sha256
        $htaccessPath = $this->publicDir.'/.htaccess';
        $actualHtaccessSha = (string) hash_file('sha256', $htaccessPath);
        if ($this->execute && $this->expectedHtaccessSha === null) {
            throw new RuntimeException('EXECUTE_REQUIRES_HTACCESS_SHA_PIN');
        }
        if ($this->expectedHtaccessSha !== null && ! hash_equals($this->expectedHtaccessSha, $actualHtaccessSha)) {
            throw new RuntimeException('HTACCESS_SHA_PIN_MISMATCH');
        }

        return [
            'app_dir' => $this->appDir,
            'public_dir' => $this->publicDir,
            'git_head' => $gitHead,
            'build_manifest_sha256' => $actualBuildSha,
            'htaccess_sha256' => $actualHtaccessSha,
            'pins_verified' => true,
        ];
    }

    /**
     * @param array<string, mixed> $layoutReport
     * @return array<string, mixed>
     */
    private function runSnapshotMode(array $layoutReport): array
    {
        $pdo = $this->createReadOnlyConnection();

        // Persistent multiquery read-only consistent snapshot
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

        try {
            $fingerprint = self::computeFingerprint($pdo);
        } finally {
            try {
                $pdo->rollBack();
            } catch (Throwable) {
            }
        }

        $tablesCount = count($fingerprint['tables']);
        $preservationCount = count($fingerprint['preservation_tables']);
        $operationalCount = count($fingerprint['operational_tables']);

        $summary = [
            'status' => 'SUCCESS',
            'mode' => 'SNAPSHOT',
            'verified_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'layout' => $layoutReport,
            'metrics' => [
                'total_tables' => $tablesCount,
                'preservation_tables_count' => $preservationCount,
                'operational_tables_count' => $operationalCount,
                'total_rows' => $fingerprint['total_rows'],
                'schema_digest' => $fingerprint['schema_digest'],
                'preservation_rows_digest' => $fingerprint['preservation_rows_digest'],
            ],
            'fingerprint' => $fingerprint,
        ];

        if ($this->outputFile !== null) {
            $this->writeOutputFileExclusively($this->outputFile, $summary);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $layoutReport
     * @return array<string, mixed>
     */
    private function runCompareMode(array $layoutReport): array
    {
        if ($this->baselineFile === null || ! is_file($this->baselineFile)) {
            throw new RuntimeException('BASELINE_FILE_REQUIRED_FOR_COMPARE');
        }
        if ($this->targetFile === null || ! is_file($this->targetFile)) {
            throw new RuntimeException('TARGET_FILE_REQUIRED_FOR_COMPARE');
        }

        $baselineRaw = (string) file_get_contents($this->baselineFile);
        $targetRaw = (string) file_get_contents($this->targetFile);

        $base = json_decode($baselineRaw, true);
        $target = json_decode($targetRaw, true);

        if (! is_array($base) || ! isset($base['fingerprint']) || ! is_array($target) || ! isset($target['fingerprint'])) {
            throw new RuntimeException('INVALID_FINGERPRINT_PAYLOAD');
        }

        $diffResult = self::compareFingerprints($base['fingerprint'], $target['fingerprint']);

        if ($diffResult['preservation_diffs_count'] > 0) {
            throw new RuntimeException('PRESERVATION_TABLE_DIFF_DETECTED: '.implode(',', array_keys($diffResult['preservation_diffs'])));
        }

        $summary = [
            'status' => 'SUCCESS',
            'mode' => 'COMPARE',
            'classification' => $diffResult['operational_diffs_count'] > 0
                ? 'OPERATIONAL_VOLATILITY_ONLY'
                : 'FINGERPRINTS_EQUAL',
            'layout' => $layoutReport,
            'comparison' => $diffResult,
        ];

        if ($this->outputFile !== null) {
            $this->writeOutputFileExclusively($this->outputFile, $summary);
        }

        return $summary;
    }

    /**
     * Execute all 6 reconciliation services across all companies using a minimal isolated container
     *
     * @param array<string, mixed> $layoutReport
     * @return array<string, mixed>
     */
    private function runReconcileMode(array $layoutReport): array
    {
        $this->ensureVendorAutoloadLoaded();
        $pdo = $this->createReadOnlyConnection();

        // Persistent multiquery read-only consistent snapshot
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

        $app = null;
        $manager = null;
        $connection = null;
        $domains = [];
        $healthy = true;

        try {
            // Minimal isolated container: no full service providers, sessions, cache, or background jobs.
            $app = new \Illuminate\Foundation\Application($this->appDir);
            $app->instance('config', new \Illuminate\Config\Repository([
                'database' => [
                    'default' => 'release_check',
                    'connections' => [],
                ],
            ]));
            $app->singleton(\App\Support\Tenancy\CompanyContext::class);

            // Wrap the SAME read-only PDO connection
            $connection = new \Illuminate\Database\MySqlConnection($pdo, $this->dbName, '', ['name' => 'release_check']);
            $manager = new \Illuminate\Database\DatabaseManager($app, new \Illuminate\Database\Connectors\ConnectionFactory($app));
            $manager->extend('release_check', fn () => $connection);
            $app['config']->set('database.connections.release_check', ['driver' => 'release_check']);
            $app->instance('db', $manager);
            \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
            \Illuminate\Database\Eloquent\Model::setConnectionResolver($manager);

            $companies = \App\Models\Company::orderBy('id')->get();
            $serviceMap = [
                'accounting' => \App\Services\Accounting\AccountingReconciliationService::class,
                'inventory' => \App\Services\Inventory\InventoryReconciliationService::class,
                'sales' => \App\Services\Sales\SalesReconciliationService::class,
                'payables' => \App\Services\Purchasing\PayablesReconciliationService::class,
                'money' => \App\Services\Money\MoneyReconciliationService::class,
                'phase7' => \App\Services\Accounting\Phase7ReconciliationService::class,
            ];

            foreach ($companies as $company) {
                foreach ($serviceMap as $domain => $class) {
                    $service = $app->make($class);
                    $report = $domain === 'inventory'
                        ? $service->auditCompany($company, true)
                        : $service->reconcile($company, true);

                    $findings = $domain === 'inventory' ? $report->discrepancies : $report->violations;
                    $ok = ($report->isHealthy === true) && ($findings === []);
                    if (! $ok) {
                        $healthy = false;
                    }

                    $domains[] = [
                        'company_id' => $company->id,
                        'domain' => $domain,
                        'healthy' => $ok,
                        'count' => count($findings),
                        'digest' => hash('sha256', serialize($findings)),
                    ];
                }
            }
        } finally {
            try {
                $pdo->rollBack();
            } catch (Throwable) {
            }

            try {
                if ($manager instanceof \Illuminate\Database\DatabaseManager) {
                    $manager->purge('release_check');
                    $manager->disconnect('release_check');
                }
            } catch (Throwable) {
            }
            try {
                \Illuminate\Database\Eloquent\Model::unsetConnectionResolver();
            } catch (Throwable) {
            }
            try {
                \Illuminate\Support\Facades\Facade::clearResolvedInstances();
                \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
            } catch (Throwable) {
            }
            if ($app instanceof \Illuminate\Foundation\Application) {
                $app->flush();
            }
        }

        $summary = [
            'status' => ($healthy && $domains !== []) ? 'SUCCESS' : 'FAIL',
            'mode' => 'RECONCILE',
            'healthy' => $healthy && $domains !== [],
            'verified_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'layout' => $layoutReport,
            'companies_evaluated' => count($domains) === 0 ? 0 : intdiv(count($domains), 6),
            'services_evaluated_count' => count($domains),
            'domains' => $domains,
        ];

        if ($this->outputFile !== null) {
            $this->writeOutputFileExclusively($this->outputFile, $summary);
        }

        if (! $healthy || $domains === []) {
            throw new RuntimeException('RECONCILIATION_UNHEALTHY_OR_EMPTY_SCOPE');
        }

        return $summary;
    }

    /**
     * Verify the 6 core reconciliation service contracts and reflection signatures
     *
     * @param array<string, mixed> $layoutReport
     * @return array<string, mixed>
     */
    private function runReconcileContractsMode(array $layoutReport): array
    {
        $this->ensureVendorAutoloadLoaded();

        $services = [
            'AccountingReconciliationService' => [
                'class' => 'App\Services\Accounting\AccountingReconciliationService',
                'method' => 'reconcile',
                'system_flag' => 'isSystem',
            ],
            'InventoryReconciliationService' => [
                'class' => 'App\Services\Inventory\InventoryReconciliationService',
                'method' => 'auditCompany',
                'system_flag' => 'fromCli',
            ],
            'SalesReconciliationService' => [
                'class' => 'App\Services\Sales\SalesReconciliationService',
                'method' => 'reconcile',
                'system_flag' => 'isSystem',
            ],
            'PayablesReconciliationService' => [
                'class' => 'App\Services\Purchasing\PayablesReconciliationService',
                'method' => 'reconcile',
                'system_flag' => 'isSystem',
            ],
            'MoneyReconciliationService' => [
                'class' => 'App\Services\Money\MoneyReconciliationService',
                'method' => 'reconcile',
                'system_flag' => 'isSystem',
            ],
            'Phase7ReconciliationService' => [
                'class' => 'App\Services\Accounting\Phase7ReconciliationService',
                'method' => 'reconcile',
                'system_flag' => 'isSystem',
            ],
        ];

        $contractAudit = [];
        foreach ($services as $name => $spec) {
            if (! class_exists($spec['class'])) {
                throw new RuntimeException("SERVICE_CLASS_NOT_FOUND_{$name}");
            }
            $refClass = new ReflectionClass($spec['class']);
            if (! $refClass->hasMethod($spec['method'])) {
                throw new RuntimeException("SERVICE_METHOD_NOT_FOUND_{$name}_{$spec['method']}");
            }
            $refMethod = $refClass->getMethod($spec['method']);
            $params = $refMethod->getParameters();
            if (count($params) < 2) {
                throw new RuntimeException("SERVICE_SIGNATURE_MISMATCH_{$name}");
            }

            $contractAudit[$name] = [
                'class' => $spec['class'],
                'method' => $spec['method'],
                'parameters_count' => count($params),
                'verified' => true,
            ];
        }

        return [
            'status' => 'SUCCESS',
            'mode' => 'RECONCILE_CONTRACTS',
            'layout' => $layoutReport,
            'services_count' => count($contractAudit),
            'services' => $contractAudit,
            'containment' => 'READ_ONLY_CONTRACTS_VERIFIED',
        ];
    }

    /**
     * Compute full deterministic PK-ordered row byte hash and DDL hash
     *
     * @return array<string, mixed>
     */
    public static function computeFingerprint(PDO $pdo): array
    {
        // 1. Fetch all base table names in deterministic alphabetical order
        $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];
        while ($row = $tablesStmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = (string) $row[0];
        }
        sort($tables);

        $tableResults = [];
        $preservationTables = [];
        $operationalTables = [];
        $totalRows = 0;
        $schemaSignatures = [];
        $preservationRowSignatures = [];

        foreach ($tables as $name) {
            $isOperational = in_array($name, self::OPERATIONAL_MUTABLE_TABLES, true);
            $classification = $isOperational ? 'operational_mutable' : 'preservation';

            // Normalized vs full DDL
            $createStmt = $pdo->query(sprintf('SHOW CREATE TABLE `%s`', str_replace('`', '``', $name)));
            $createRow = $createStmt->fetch(PDO::FETCH_NUM);
            $rawDdl = (string) ($createRow[1] ?? '');

            // For preservation tables, KEEP actual AUTO_INCREMENT! Full preservation requires detecting leaked financial sequence changes.
            // For operational tables only, normalize DDL by stripping volatile AUTO_INCREMENT=\d+ counters.
            if ($isOperational) {
                $ddlForHash = (string) preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $rawDdl);
            } else {
                $ddlForHash = $rawDdl;
            }
            $ddlSha256 = hash('sha256', $ddlForHash);
            $schemaSignatures[] = "{$name}:{$ddlSha256}";

            // Columns and Primary Key for deterministic ordering
            $colsStmt = $pdo->query(sprintf('SELECT * FROM `%s` LIMIT 0', str_replace('`', '``', $name)));
            $colCount = $colsStmt->columnCount();
            $cols = [];
            for ($c = 0; $c < $colCount; $c++) {
                $meta = $colsStmt->getColumnMeta($c);
                $cols[] = (string) ($meta['name'] ?? "col_{$c}");
            }

            // Detect primary key columns with Seq_in_index sort
            $pkStmt = $pdo->query(sprintf("SHOW KEYS FROM `%s` WHERE Key_name = 'PRIMARY'", str_replace('`', '``', $name)));
            $pkRows = [];
            while ($kRow = $pkStmt->fetch(PDO::FETCH_ASSOC)) {
                $colName = (string) ($kRow['Column_name'] ?? '');
                if ($colName !== '') {
                    $pkRows[] = [
                        'col' => $colName,
                        'seq' => (int) ($kRow['Seq_in_index'] ?? 0),
                    ];
                }
            }
            usort($pkRows, fn ($a, $b) => $a['seq'] <=> $b['seq']);
            $pkCols = array_map(fn ($r) => $r['col'], $pkRows);

            if ($pkCols !== []) {
                $orderBy = implode(', ', array_map(fn ($col) => sprintf('`%s` ASC', str_replace('`', '``', $col)), $pkCols));
            } else {
                // Table has no primary key: sort deterministically by ALL columns with BINARY collation to eliminate collation ties
                $orderBy = implode(', ', array_map(fn ($col) => sprintf('BINARY `%s` ASC', str_replace('`', '``', $col)), $cols));
            }

            // Stream rows with length-framing and distinct null encoding
            $selectCols = implode(', ', array_map(fn ($col) => sprintf('`%s`', str_replace('`', '``', $col)), $cols));
            $rowsStmt = $pdo->query(sprintf('SELECT %s FROM `%s` ORDER BY %s', $selectCols, str_replace('`', '``', $name), $orderBy));

            $hashContext = hash_init('sha256');
            $rowCount = 0;

            while (($row = $rowsStmt->fetch(PDO::FETCH_NUM)) !== false) {
                $rowCount++;
                $totalRows++;

                // Length-framed row header: R<numCols>;
                hash_update($hashContext, "R{$colCount};");

                foreach ($row as $val) {
                    if ($val === null) {
                        hash_update($hashContext, 'N;');
                    } else {
                        $strVal = (string) $val;
                        $strLen = strlen($strVal);
                        hash_update($hashContext, "S{$strLen}:{$strVal}");
                    }
                }
            }

            $rowsSha256 = hash_final($hashContext);

            $tableRecord = [
                'rows' => $rowCount,
                'rows_sha256' => $rowsSha256,
                'ddl_sha256' => $ddlSha256,
                'classification' => $classification,
            ];

            if ($isOperational) {
                $operationalTables[$name] = $tableRecord;
            } else {
                $preservationTables[$name] = $tableRecord;
                $preservationRowSignatures[] = "{$name}:{$rowsSha256}";
            }

            $tableResults[$name] = $tableRecord;
        }

        return [
            'total_rows' => $totalRows,
            'schema_digest' => hash('sha256', implode("\n", $schemaSignatures)),
            'preservation_rows_digest' => hash('sha256', implode("\n", $preservationRowSignatures)),
            'tables' => $tableResults,
            'preservation_tables' => $preservationTables,
            'operational_tables' => $operationalTables,
        ];
    }

    /**
     * Compare two fingerprints and separate preservation differences from operational differences
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public static function compareFingerprints(array $base, array $target): array
    {
        $preservationDiffs = [];
        $operationalDiffs = [];

        $allTables = array_unique(array_merge(
            array_keys($base['tables'] ?? []),
            array_keys($target['tables'] ?? [])
        ));
        sort($allTables);

        foreach ($allTables as $table) {
            $b = $base['tables'][$table] ?? null;
            $t = $target['tables'][$table] ?? null;
            $isOperational = in_array($table, self::OPERATIONAL_MUTABLE_TABLES, true);

            // Classification taint detection: verify incoming classification matches fixed allowlist
            if ($b !== null) {
                $bClass = $b['classification'] ?? '';
                if ($bClass === 'operational_mutable' && ! $isOperational) {
                    throw new RuntimeException("CLASSIFICATION_TAINT_DETECTED: table '{$table}' in baseline falsely claimed as operational");
                }
            }
            if ($t !== null) {
                $tClass = $t['classification'] ?? '';
                if ($tClass === 'operational_mutable' && ! $isOperational) {
                    throw new RuntimeException("CLASSIFICATION_TAINT_DETECTED: table '{$table}' in target falsely claimed as operational");
                }
            }

            // ANY missing table (whether preservation or operational) is a PRESERVATION FAILURE
            if ($b === null || $t === null) {
                $preservationDiffs[$table] = [
                    'type' => 'TABLE_MISSING',
                    'baseline_present' => $b !== null,
                    'target_present' => $t !== null,
                ];
                continue;
            }

            $bDdl = (string) ($b['ddl_sha256'] ?? $b['normalized_ddl_sha256'] ?? '');
            $tDdl = (string) ($t['ddl_sha256'] ?? $t['normalized_ddl_sha256'] ?? '');
            $ddlMatch = hash_equals($bDdl, $tDdl);

            $bRows = (string) ($b['rows_sha256'] ?? '');
            $tRows = (string) ($t['rows_sha256'] ?? '');
            $rowsMatch = hash_equals($bRows, $tRows);

            // ANY DDL mismatch (whether preservation or operational) is a PRESERVATION FAILURE
            if (! $ddlMatch) {
                $preservationDiffs[$table] = [
                    'type' => 'DDL_MISMATCH',
                    'baseline_ddl_sha256' => $bDdl,
                    'target_ddl_sha256' => $tDdl,
                    'ddl_match' => false,
                    'rows_match' => $rowsMatch,
                ];
                continue;
            }

            // Only ROW churn is permitted for operational tables.
            if (! $rowsMatch) {
                $diff = [
                    'type' => 'ROWS_MISMATCH',
                    'baseline_rows' => $b['rows'] ?? 0,
                    'target_rows' => $t['rows'] ?? 0,
                    'ddl_match' => true,
                    'rows_match' => false,
                ];
                if ($isOperational) {
                    $operationalDiffs[$table] = $diff;
                } else {
                    $preservationDiffs[$table] = $diff;
                }
            }
        }

        return [
            'tables_compared' => count($allTables),
            'preservation_diffs_count' => count($preservationDiffs),
            'preservation_diffs' => $preservationDiffs,
            'operational_diffs_count' => count($operationalDiffs),
            'operational_diffs' => $operationalDiffs,
        ];
    }

    /**
     * Configure statement timeout: tries MariaDB max_statement_time then MySQL max_execution_time,
     * failing closed if neither is supported.
     */
    public static function configureStatementTimeout(PDO $pdo, int $timeoutSeconds = 5): void
    {
        if ($timeoutSeconds <= 0) {
            throw new RuntimeException('INVALID_STATEMENT_TIMEOUT');
        }

        // 1. Try MariaDB max_statement_time (seconds)
        try {
            $pdo->exec(sprintf('SET SESSION max_statement_time = %d', $timeoutSeconds));

            return;
        } catch (Throwable) {
            // Fall through to MySQL
        }

        // 2. Try MySQL max_execution_time (milliseconds)
        try {
            $timeoutMs = $timeoutSeconds * 1000;
            $pdo->exec(sprintf('SET SESSION max_execution_time = %d', $timeoutMs));

            return;
        } catch (Throwable) {
            // Fall through
        }

        throw new RuntimeException('STATEMENT_TIMEOUT_UNSUPPORTED');
    }

    private function createReadOnlyConnection(): PDO
    {
        $this->ensureVendorAutoloadLoaded();

        // Parse .env strictly without booting framework
        $envPath = $this->appDir.'/.env';
        if (! is_file($envPath)) {
            throw new RuntimeException('ENV_FILE_NOT_FOUND');
        }

        $env = \Dotenv\Dotenv::parse((string) file_get_contents($envPath));
        $host = (string) ($env['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($env['DB_PORT'] ?? '3306');
        $this->dbName = (string) ($env['DB_DATABASE'] ?? '');
        $user = (string) ($env['DB_USERNAME'] ?? '');
        $pass = (string) ($env['DB_PASSWORD'] ?? '');

        if ($this->dbName === '' || $user === '') {
            throw new RuntimeException('DATABASE_CREDENTIALS_INCOMPLETE');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $this->dbName);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Enforce statement timeout fail closed
        self::configureStatementTimeout($pdo, 5);

        return $pdo;
    }

    public function ensureVendorAutoloadLoaded(): void
    {
        $autoload = $this->appDir.'/vendor/autoload.php';
        if (! is_file($autoload)) {
            $candidates = [
                $autoload,
                dirname($this->appDir, 2).'/vendor/autoload.php',
            ];
            $found = false;
            foreach ($candidates as $c) {
                if (is_file($c)) {
                    require_once $c;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $worktreeParent = dirname($this->appDir);
                $matches = glob($worktreeParent.'/*/vendor/autoload.php') ?: [];
                if ($matches !== []) {
                    require_once $matches[0];
                    $found = true;
                }
            }
            if (! $found) {
                throw new RuntimeException('VENDOR_AUTOLOAD_NOT_FOUND');
            }
        } else {
            require_once $autoload;
        }
    }

    public function assertSafeOutputFile(string $filePath): void
    {
        if (is_link($filePath)) {
            throw new RuntimeException('OUTPUT_FILE_SYMLINK_REJECTED');
        }

        $realTargetDir = realpath(dirname($filePath));
        if ($realTargetDir === false || ! is_dir($realTargetDir)) {
            throw new RuntimeException('OUTPUT_DIRECTORY_NOT_FOUND');
        }

        // Must NOT be inside public web root
        $realPublicDir = realpath($this->publicDir);
        if ($realPublicDir !== false) {
            if ($realTargetDir === $realPublicDir || str_starts_with($realTargetDir, $realPublicDir.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('OUTPUT_FILE_INSIDE_PUBLIC_WEB_ROOT_REJECTED');
            }
        }

        // Enforce private directory permissions (not world-accessible)
        $perms = fileperms($realTargetDir) & 0777;
        if (($perms & 0007) !== 0) {
            throw new RuntimeException('OUTPUT_DIRECTORY_INSECURE_PERMISSIONS');
        }
    }

    private function writeOutputFileExclusively(string $filePath, array $payload): void
    {
        $this->assertSafeOutputFile($filePath);

        $handle = @fopen($filePath, 'x');
        if ($handle === false) {
            throw new RuntimeException('OUTPUT_FILE_CREATION_FAILED_ALREADY_EXISTS_OR_EXCLUSIVE_DENIED');
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (fwrite($handle, $json) !== strlen($json)) {
            fclose($handle);
            @unlink($filePath);
            throw new RuntimeException('OUTPUT_FILE_WRITE_INCOMPLETE');
        }

        fclose($handle);
        chmod($filePath, 0600);
    }

    private function resolveGitHead(): string
    {
        $out = [];
        $exit = 0;
        exec(sprintf('git -C %s rev-parse HEAD 2>&1', escapeshellarg($this->appDir)), $out, $exit);
        if ($exit === 0 && ! empty($out[0]) && preg_match('/^[a-f0-9]{40}$/D', trim($out[0]))) {
            return trim($out[0]);
        }

        return 'UNKNOWN_GIT_HEAD';
    }

    private function assertDirectory(string $path, string $context): string
    {
        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("DIRECTORY_NOT_FOUND_{$context}");
        }

        return $real;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = [];
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                echo "Usage: php bin/final-release-check.php [options]\n";
                echo "  --mode=<snapshot|compare|reconcile|reconcile-contracts>  Operation mode (default: snapshot)\n";
                echo "  --execute                                                Execute actual inspection\n";
                echo "  --app-dir=<path>                                         Application root path\n";
                echo "  --public-dir=<path>                                      Public web root path\n";
                echo "  --expected-source-sha=<h>                                Expected 40-char commit SHA\n";
                echo "  --expected-build-sha=<h>                                 Expected 64-char build manifest SHA256\n";
                echo "  --expected-htaccess-sha=<h>                              Expected 64-char .htaccess SHA256\n";
                echo "  --baseline=<path>                                        Baseline fingerprint file (compare mode)\n";
                echo "  --target=<path>                                          Target fingerprint file (compare mode)\n";
                echo "  --output-file=<path>                                     Path to write output report\n";
                exit(0);
            }
            if ($arg === '--execute') {
                $options['execute'] = true;
            } elseif (preg_match('/^--([a-z-]+)=(.*)$/sD', $arg, $m)) {
                $options[$m[1]] = $m[2];
            } else {
                throw new RuntimeException('UNKNOWN_OPTION');
            }
        }

        $checker = new FinalReleaseCheck($options);
        $result = $checker->run();

        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        $msg = $e instanceof RuntimeException && preg_match('/^[A-Z0-9_]+$/D', $e->getMessage())
            ? $e->getMessage()
            : 'RELEASE_CHECK_FAILED';

        echo json_encode(['status' => 'FAIL', 'error' => $msg], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
}
