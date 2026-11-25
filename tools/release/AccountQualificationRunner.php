<?php

declare(strict_types=1);

namespace Tools\Release;

use RuntimeException;
use Throwable;

/**
 * Account Local Release Qualification Runner (Phase G).
 *
 * Enforces fail-closed preflight verification (exact commit, clean tracked source,
 * locked dependencies, and valid disposable database configuration), provides
 * deterministic disjoint complete test inventory batching, executes batches via
 * tests/Support/run-phase8-disposable.php, isolates JUnit/logs outside tracked source,
 * and aggregates actual totals without filtering failures.
 */
final class AccountQualificationRunner
{
    public const string VERSION = '1.0.0-phaseG';

    public const string RUNNER_NAME = 'Account Local Release Qualification Runner (Phase G)';

    public const int DEFAULT_BATCH_SIZE = 25;

    public const int DEFAULT_BATCH_TIMEOUT = 900;

    public const int MAX_TIMEOUT = 14400;

    public const int EXIT_SUCCESS = 0;

    public const int EXIT_TEST_FAILURE = 1;

    public const int EXIT_REFUSAL_COMMIT_OR_TREE = 2;

    public const int EXIT_REFUSAL_DEPENDENCY_OR_PLATFORM = 3;

    public const int EXIT_REFUSAL_DATABASE = 4;

    public const int EXIT_REFUSAL_OUTDIR = 5;

    public const int MIN_PHP_VERSION_ID = 80400;

    /** @var list<string> */
    public const array REQUIRED_EXTENSIONS = [
        'pdo_mysql',
        'bcmath',
        'mbstring',
        'intl',
        'gd',
        'zip',
        'xml',
        'curl',
    ];

    private string $rootDir;

    private bool $verbose = false;

    public function __construct(?string $rootDir = null, bool $verbose = false)
    {
        $this->rootDir = $rootDir !== null ? rtrim($rootDir, '/\\') : dirname(__DIR__, 2);
        $this->verbose = $verbose;
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * Parse CLI arguments into options dictionary with strict numeric and format validation.
     *
     * @param  list<string>  $argv
     * @return array<string, mixed>
     */
    public function parseArguments(array $argv): array
    {
        $options = [
            'expected-commit' => getenv('QUALIFY_EXPECTED_COMMIT') ?: null,
            'allow-dirty' => false,
            'preflight-only' => false,
            'static-only' => false,
            'list-batches' => false,
            'inventory' => false,
            'batch' => null,
            'batch-size' => self::DEFAULT_BATCH_SIZE,
            'batch-timeout' => self::DEFAULT_BATCH_TIMEOUT,
            'out-dir' => null,
            'skip-static' => false,
            'skip-db' => false,
            'verbose' => false,
            'version' => false,
            'help' => false,
        ];

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if ($arg === '--version' || $arg === '-V') {
                $options['version'] = true;
            } elseif ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
            } elseif ($arg === '--verbose' || $arg === '-v') {
                $options['verbose'] = true;
            } elseif ($arg === '--preflight-only') {
                $options['preflight-only'] = true;
            } elseif ($arg === '--static-only') {
                $options['static-only'] = true;
            } elseif ($arg === '--list-batches') {
                $options['list-batches'] = true;
            } elseif ($arg === '--inventory') {
                $options['inventory'] = true;
            } elseif ($arg === '--allow-dirty') {
                $options['allow-dirty'] = true;
            } elseif ($arg === '--skip-static') {
                $options['skip-static'] = true;
            } elseif ($arg === '--skip-db') {
                $options['skip-db'] = true;
            } elseif (str_starts_with($arg, '--expected-commit=')) {
                $commitVal = trim(substr($arg, 18));
                if ($commitVal === '') {
                    throw new RuntimeException('Invalid --expected-commit: SHA value cannot be empty.');
                }
                $options['expected-commit'] = $commitVal;
            } elseif (str_starts_with($arg, '--batch=')) {
                $val = substr($arg, 8);
                if (! ctype_digit($val) || (int) $val < 1) {
                    throw new RuntimeException("Invalid --batch value '{$val}': must be a strictly positive integer.");
                }
                $options['batch'] = (int) $val;
            } elseif (str_starts_with($arg, '--batch-size=')) {
                $val = substr($arg, 13);
                if (! ctype_digit($val) || (int) $val < 1) {
                    throw new RuntimeException("Invalid --batch-size value '{$val}': must be a strictly positive integer.");
                }
                $options['batch-size'] = (int) $val;
            } elseif (str_starts_with($arg, '--batch-timeout=')) {
                $val = substr($arg, 16);
                if (! ctype_digit($val) || (int) $val < 1 || (int) $val > self::MAX_TIMEOUT) {
                    throw new RuntimeException("Invalid --batch-timeout value '{$val}': must be an integer between 1 and ".self::MAX_TIMEOUT.' seconds.');
                }
                $options['batch-timeout'] = (int) $val;
            } elseif (str_starts_with($arg, '--out-dir=')) {
                $outDirVal = rtrim(substr($arg, 10), '/\\');
                if ($outDirVal === '') {
                    throw new RuntimeException('Invalid --out-dir: path cannot be empty.');
                }
                $options['out-dir'] = $outDirVal;
            } elseif ($arg === '--') {
                break;
            } else {
                throw new RuntimeException("Unknown option: '{$arg}'. Use --help for usage.");
            }
        }

        $this->verbose = (bool) $options['verbose'];

        return $options;
    }

    /**
     * Check if a requested output directory already exists and contains files.
     * Refuses collisions with potentially stale JUnit or log files.
     *
     * @throws QualificationRefusalException
     */
    public function checkOutDirCollision(string $outDir): void
    {
        if (is_dir($outDir)) {
            $scanned = scandir($outDir);
            $existing = array_diff($scanned !== false ? $scanned : [], ['.', '..']);
            if (! empty($existing)) {
                throw new QualificationRefusalException(
                    "Output directory '{$outDir}' already exists and is not empty. Refusing collision with potentially stale artifacts. Specify a clean or fresh directory.",
                    self::EXIT_REFUSAL_OUTDIR
                );
            }
        }
    }

    /**
     * Run qualification execution according to parsed options.
     *
     * @param  array<string, mixed>  $options
     * @return int Exit code
     */
    public function run(array $options): int
    {
        if ($options['version']) {
            $this->printVersion();

            return self::EXIT_SUCCESS;
        }

        if ($options['help']) {
            $this->printHelp();

            return self::EXIT_SUCCESS;
        }

        // Output directory resolution and collision prevention
        if ($options['out-dir'] !== null) {
            $outDir = (string) $options['out-dir'];
            try {
                $this->checkOutDirCollision($outDir);
            } catch (QualificationRefusalException $e) {
                $this->logError('[REFUSAL: OUTDIR] '.$e->getMessage());

                return self::EXIT_REFUSAL_OUTDIR;
            }
        } else {
            $outDir = $this->createDefaultOutputDir();
        }

        if (! is_dir($outDir) && ! mkdir($outDir, 0700, true)) {
            $this->logError("Failed to create output directory: {$outDir}");

            return self::EXIT_TEST_FAILURE;
        }

        $this->logInfo('=== '.self::RUNNER_NAME.' v'.self::VERSION.' ===');
        $this->logInfo('Workspace root: '.$this->rootDir);
        $this->logInfo('Artifacts directory: '.$outDir);

        // Track skipped checks for strict full release qualification eligibility
        $skippedChecks = [];

        // 1. Commit and git tree checks (fail-closed)
        try {
            $headCommit = $this->checkGitCommit($options['expected-commit']);
            $this->logInfo("Current commit: {$headCommit} (verified)");

            if ($options['allow-dirty']) {
                $skippedChecks[] = 'clean_working_tree_verification';
                $this->logInfo('Tracked working tree: DIRTY CHECK BYPASSED (--allow-dirty specified)');
            } else {
                $this->checkTrackedSourceClean(false);
                $this->logInfo('Tracked working tree: clean');
            }
        } catch (QualificationRefusalException $e) {
            $this->logError('[REFUSAL: COMMIT/TREE] '.$e->getMessage());

            return self::EXIT_REFUSAL_COMMIT_OR_TREE;
        }

        // 2. Platform and dependencies checks (fail-closed)
        try {
            $this->checkPlatformRequirements();
            $this->logInfo('Platform: PHP '.PHP_VERSION.', required extensions loaded');

            $this->checkLockedDependencies();
            $this->logInfo('Dependencies: composer.lock & package-lock.json verified and locked');
        } catch (QualificationRefusalException $e) {
            $this->logError('[REFUSAL: PLATFORM/DEPENDENCY] '.$e->getMessage());

            return self::EXIT_REFUSAL_DEPENDENCY_OR_PLATFORM;
        }

        // 3. Database configuration check (fail-closed if DB execution requested)
        $dbEnv = [];
        $requiresDb = ! $options['preflight-only']
            && ! $options['static-only']
            && ! $options['skip-db']
            && ! $options['list-batches']
            && ! $options['inventory'];
        if ($requiresDb) {
            try {
                $dbEnv = $this->checkDisposableDatabaseConfiguration();
                $this->logInfo("Database: valid disposable MariaDB on {$dbEnv['PHASE8_TEST_DB_HOST']}:{$dbEnv['PHASE8_TEST_DB_PORT']}");
            } catch (QualificationRefusalException $e) {
                $this->logError('[REFUSAL: DATABASE] '.$e->getMessage());

                return self::EXIT_REFUSAL_DATABASE;
            }
        } else {
            // In preflight-only mode, test DB configuration if provided, but report if absent
            try {
                $dbEnv = $this->checkDisposableDatabaseConfiguration();
                $this->logInfo('Database: valid disposable MariaDB configuration present');
            } catch (QualificationRefusalException $e) {
                if ($options['preflight-only']) {
                    $this->logInfo('Database: disposable configuration absent or invalid ('.$e->getMessage().')');
                }
            }
        }

        // 4. Test inventory discovery and deterministic disjoint partitioning
        $inventory = $this->discoverTestInventory();
        $batchSize = (int) $options['batch-size'];
        $batches = $this->partitionInventory($inventory, $batchSize);
        $totalBatchCount = count($batches);
        $this->logInfo('Test Inventory: '.count($inventory).' test files partitioned into '.$totalBatchCount." disjoint batches (batch size: {$batchSize})");

        if ($options['inventory']) {
            $this->outputInventoryJson($inventory, $batches);

            return self::EXIT_SUCCESS;
        }

        if ($options['list-batches']) {
            $this->outputBatchesList($batches);

            return self::EXIT_SUCCESS;
        }

        // Validate targeted batch if specified
        $targetBatch = null;
        if ($options['batch'] !== null) {
            $targetBatch = (int) $options['batch'];
            if ($targetBatch < 1 || $targetBatch > $totalBatchCount) {
                $this->logError("Requested batch {$targetBatch} does not exist. Available batch range is 1 to {$totalBatchCount}.");

                return self::EXIT_TEST_FAILURE;
            }
            $skippedChecks[] = "remaining_disjoint_batches (targeted batch {$targetBatch}/{$totalBatchCount} only)";
        }

        $emptyTotals = [
            'total_batches' => 0,
            'passed_batches' => 0,
            'failed_batches' => 0,
            'total_tests' => 0,
            'total_assertions' => 0,
            'total_failures' => 0,
            'total_errors' => 0,
            'total_skipped' => 0,
            'total_duration' => 0.0,
            'failure_messages' => [],
        ];

        if ($options['preflight-only']) {
            $skippedChecks[] = 'static_analysis_suite';
            $skippedChecks[] = 'disposable_database_tests';
            $coverage = [
                'expected_inventory_count' => count($inventory),
                'completed_files_count' => 0,
                'coverage_percentage' => 0.0,
                'full_inventory_completed' => false,
            ];

            $this->logInfo('Preflight qualification checks: ALL PASSED');
            $this->writeReportFile(
                $outDir,
                $headCommit,
                [],
                [],
                true,
                false,
                'PARTIAL_SCOPE_PASSED',
                $coverage,
                $skippedChecks,
                [],
                $emptyTotals
            );

            return self::EXIT_SUCCESS;
        }

        $staticResults = [];
        $staticPassed = true;
        if ($options['skip-static']) {
            $skippedChecks[] = 'static_analysis_suite';
            $this->logInfo('Static analysis suite: SKIPPED (--skip-static)');
        } else {
            $this->logInfo("\n--- Executing Static Analysis Suite ---");
            $staticResults = $this->runStaticChecks($outDir);
            $staticPassed = $this->allStaticChecksPassed($staticResults);
            if (! $staticPassed) {
                $this->logError('Static analysis suite failed (fail-closed on non-zero exit code).');
                $coverage = [
                    'expected_inventory_count' => count($inventory),
                    'completed_files_count' => 0,
                    'coverage_percentage' => 0.0,
                    'full_inventory_completed' => false,
                ];
                $failedTotals = array_merge($emptyTotals, [
                    'failure_messages' => ['Static analysis suite failed.'],
                ]);
                $this->writeReportFile(
                    $outDir,
                    $headCommit,
                    $staticResults,
                    [],
                    false,
                    false,
                    'FAILED',
                    $coverage,
                    $skippedChecks,
                    [],
                    $failedTotals
                );
                $this->printSummary($failedTotals, false, false, $coverage, $skippedChecks, $outDir);

                return self::EXIT_TEST_FAILURE;
            }
            $this->logInfo('Static analysis suite: ALL PASSED');
        }

        if ($options['static-only'] || $options['skip-db']) {
            $skippedChecks[] = 'disposable_database_tests';
            $coverage = [
                'expected_inventory_count' => count($inventory),
                'completed_files_count' => 0,
                'coverage_percentage' => 0.0,
                'full_inventory_completed' => false,
            ];
            $this->logInfo("\n--- Qualification Completed (Static/Preflight Scope Only) ---");
            $this->writeReportFile(
                $outDir,
                $headCommit,
                $staticResults,
                [],
                true,
                false,
                'PARTIAL_SCOPE_PASSED',
                $coverage,
                $skippedChecks,
                [],
                $emptyTotals
            );
            $this->printSummary($emptyTotals, true, false, $coverage, $skippedChecks, $outDir);

            return self::EXIT_SUCCESS;
        }

        // 5. Execute disjoint complete test batches
        $this->logInfo("\n--- Executing Disjoint Disposable Test Batches ---");
        $batchResults = [];
        /** @var list<string> $completedFiles */
        $completedFiles = [];

        foreach ($batches as $batch) {
            $bIdx = $batch['index'];
            if ($targetBatch !== null && $bIdx !== $targetBatch) {
                continue;
            }

            $this->logInfo("\n[Batch {$bIdx}/{$totalBatchCount}] Running ".count($batch['files']).' test files...');
            $result = $this->runBatch(
                $bIdx,
                $totalBatchCount,
                $batch['files'],
                $outDir,
                (int) $options['batch-timeout'],
                $dbEnv
            );
            $batchResults[] = $result;

            if ($result['status'] === 'PASSED') {
                $this->logInfo("  -> Batch {$bIdx} PASSED ({$result['tests']} tests, {$result['assertions']} assertions in ".round($result['duration'], 2).'s)');
                foreach ($batch['files'] as $f) {
                    $completedFiles[] = $f;
                }
            } else {
                $this->logError("  -> Batch {$bIdx} FAILED (exit code: {$result['exit_code']}, failures: {$result['failures']}, errors: {$result['errors']})");
                if (! empty($result['failure_messages'])) {
                    foreach ($result['failure_messages'] as $msg) {
                        $this->logError('     * '.$msg);
                    }
                }
            }
        }

        $completedFiles = array_values(array_unique($completedFiles));
        sort($completedFiles, SORT_STRING);

        $totals = $this->aggregateResults($batchResults);
        $expectedCount = count($inventory);
        $completedCount = count($completedFiles);
        $coveragePct = $expectedCount > 0 ? round(($completedCount / $expectedCount) * 100, 2) : 0.0;
        $fullCoverage = $expectedCount > 0 && $completedCount === $expectedCount;

        $coverage = [
            'expected_inventory_count' => $expectedCount,
            'completed_files_count' => $completedCount,
            'coverage_percentage' => $coveragePct,
            'full_inventory_completed' => $fullCoverage,
        ];

        // Fail closed if zero batches ran
        $hasExecutedBatches = count($batchResults) > 0;
        $allPassed = $hasExecutedBatches
            && $totals['failed_batches'] === 0
            && $totals['total_tests'] > 0
            && $totals['total_failures'] === 0
            && $totals['total_errors'] === 0;

        // Full release qualification strictly requires full scope, clean tree, no skips, 100% coverage
        $fullReleaseQualified = $allPassed
            && $totals['total_skipped'] === 0
            && empty($skippedChecks)
            && $targetBatch === null
            && count($batchResults) === $totalBatchCount
            && $totalBatchCount > 0
            && $fullCoverage;

        $verdict = $fullReleaseQualified ? 'FULL_QUALIFICATION_PASSED' : ($allPassed ? 'PARTIAL_SCOPE_PASSED' : 'FAILED');

        $this->writeReportFile(
            $outDir,
            $headCommit,
            $staticResults,
            $batchResults,
            $allPassed,
            $fullReleaseQualified,
            $verdict,
            $coverage,
            $skippedChecks,
            $completedFiles,
            $totals
        );

        $this->printSummary($totals, $allPassed, $fullReleaseQualified, $coverage, $skippedChecks, $outDir);

        return $allPassed ? self::EXIT_SUCCESS : self::EXIT_TEST_FAILURE;
    }

    /**
     * Check that current Git commit matches expected commit.
     */
    public function checkGitCommit(?string $expectedCommit): string
    {
        $head = $this->executeShellCommand('git rev-parse HEAD', $this->rootDir);
        $headCommit = trim($head['stdout']);

        if ($headCommit === '' || $head['exit_code'] !== 0) {
            throw new QualificationRefusalException('Unable to determine current git HEAD commit.', self::EXIT_REFUSAL_COMMIT_OR_TREE);
        }

        if ($expectedCommit !== null && $expectedCommit !== '') {
            $expected = strtolower(trim($expectedCommit));
            $actual = strtolower($headCommit);

            $match = false;
            if (strlen($expected) === 40 && $expected === $actual) {
                $match = true;
            } elseif (strlen($expected) >= 7 && str_starts_with($actual, $expected)) {
                $match = true;
            }

            if (! $match) {
                throw new QualificationRefusalException(
                    "Commit mismatch: expected '{$expectedCommit}', actual HEAD is '{$headCommit}' (fail closed on wrong commit).",
                    self::EXIT_REFUSAL_COMMIT_OR_TREE
                );
            }
        }

        return $headCommit;
    }

    /**
     * Check that tracked source files are clean (no uncommitted modifications).
     */
    public function checkTrackedSourceClean(bool $allowDirty = false): void
    {
        if ($allowDirty) {
            return;
        }

        // Refresh git index stat cache to ensure stat-only changes do not falsely report dirty
        $this->executeShellCommand('git update-index -q --refresh', $this->rootDir);

        // Check tracked modifications against HEAD
        $cmd = $this->executeShellCommand('git diff-index --quiet HEAD --', $this->rootDir);
        if ($cmd['exit_code'] !== 0) {
            $diffFiles = $this->executeShellCommand('git diff-index --name-only HEAD --', $this->rootDir);
            $files = trim($diffFiles['stdout']);
            throw new QualificationRefusalException(
                "Dirty tracked source detected: [{$files}]. Commit or stash tracked changes before qualification.",
                self::EXIT_REFUSAL_COMMIT_OR_TREE
            );
        }
    }

    /**
     * Verify that lockfiles exist and match dependency manifests.
     */
    public function checkLockedDependencies(): void
    {
        $composerJson = $this->rootDir.'/composer.json';
        $composerLock = $this->rootDir.'/composer.lock';
        $packageJson = $this->rootDir.'/package.json';
        $packageLock = $this->rootDir.'/package-lock.json';

        if (! is_file($composerJson) || ! is_file($composerLock)) {
            throw new QualificationRefusalException('Composer manifest or lockfile missing.', self::EXIT_REFUSAL_DEPENDENCY_OR_PLATFORM);
        }

        if (! is_file($packageJson) || ! is_file($packageLock)) {
            throw new QualificationRefusalException('NPM package.json or package-lock.json missing.', self::EXIT_REFUSAL_DEPENDENCY_OR_PLATFORM);
        }

        // Validate composer.lock against composer.json strictly
        $validate = $this->executeShellCommand('composer validate --no-check-all --strict', $this->rootDir);
        if ($validate['exit_code'] !== 0) {
            $err = trim($validate['stdout']."\n".$validate['stderr']);
            throw new QualificationRefusalException("Composer lockfile validation failed: {$err}", self::EXIT_REFUSAL_DEPENDENCY_OR_PLATFORM);
        }
    }

    /**
     * Verify PHP version, required extensions, and Node.js.
     */
    public function checkPlatformRequirements(): void
    {
        if (version_compare(PHP_VERSION, '8.4.0', '<')) {
            throw new QualificationRefusalException('PHP version '.PHP_VERSION.' does not meet required PHP 8.4+ (>= 80400).', self::EXIT_REFUSAL_DEPENDENCY_OR_PLATFORM);
        }

        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (! extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        if (! empty($missing)) {
            throw new QualificationRefusalException('Missing required PHP extensions: '.implode(', ', $missing), self::EXIT_REFUSAL_DEPENDENCY_OR_PLATFORM);
        }

        // Node.js availability check
        $nodeCheck = $this->executeShellCommand('node -v', $this->rootDir);
        if ($nodeCheck['exit_code'] !== 0) {
            throw new QualificationRefusalException('Node.js is not installed or not in PATH.', self::EXIT_REFUSAL_DEPENDENCY_OR_PLATFORM);
        }
    }

    /**
     * Verify disposable MariaDB configuration parameters.
     *
     * @return array<string, string> Validated test environment settings
     */
    public function checkDisposableDatabaseConfiguration(): array
    {
        $appEnv = getenv('APP_ENV');
        if ($appEnv !== 'testing') {
            throw new QualificationRefusalException("APP_ENV must be set to 'testing', currently: '{$appEnv}'.", self::EXIT_REFUSAL_DATABASE);
        }

        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1') {
            throw new QualificationRefusalException("PHASE8_ALLOW_DISPOSABLE_DB must be '1' to opt into disposable database testing.", self::EXIT_REFUSAL_DATABASE);
        }

        $host = getenv('PHASE8_TEST_DB_HOST');
        if ($host === false || ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new QualificationRefusalException('PHASE8_TEST_DB_HOST must be a local loopback address (127.0.0.1, localhost, or ::1).', self::EXIT_REFUSAL_DATABASE);
        }

        $port = getenv('PHASE8_TEST_DB_PORT');
        if ($port === false || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new QualificationRefusalException('PHASE8_TEST_DB_PORT must be a valid integer port between 1 and 65535.', self::EXIT_REFUSAL_DATABASE);
        }

        if ((int) $port === 3353) {
            throw new QualificationRefusalException('Forbidden: cannot run tests against persistent development preview database on port 3353.', self::EXIT_REFUSAL_DATABASE);
        }

        $username = getenv('PHASE8_TEST_DB_USERNAME');
        if ($username === false || trim($username) === '') {
            throw new QualificationRefusalException('PHASE8_TEST_DB_USERNAME must be provided.', self::EXIT_REFUSAL_DATABASE);
        }

        $password = getenv('PHASE8_TEST_DB_PASSWORD');
        if ($password === false && getenv('PHASE8_TEST_DB_EMPTY_PASSWORD') !== '1') {
            throw new QualificationRefusalException('PHASE8_TEST_DB_PASSWORD or PHASE8_TEST_DB_EMPTY_PASSWORD=1 must be provided.', self::EXIT_REFUSAL_DATABASE);
        }

        return [
            'APP_ENV' => 'testing',
            'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            'PHASE8_TEST_DB_HOST' => $host,
            'PHASE8_TEST_DB_PORT' => (string) $port,
            'PHASE8_TEST_DB_USERNAME' => $username,
            'PHASE8_TEST_DB_PASSWORD' => $password !== false ? (string) $password : '',
        ];
    }

    /**
     * Discover all test files across configured testsuites in phpunit.xml and test directories.
     *
     * @return list<string> Relative test file paths
     */
    public function discoverTestInventory(): array
    {
        $testDirs = [];
        $phpunitXml = $this->rootDir.'/phpunit.xml';
        if (is_file($phpunitXml)) {
            $xml = @simplexml_load_file($phpunitXml);
            if ($xml !== false && isset($xml->testsuites)) {
                $dirNodes = $xml->xpath('//testsuite/directory');
                if (is_array($dirNodes)) {
                    foreach ($dirNodes as $dirNode) {
                        $dir = trim((string) $dirNode);
                        if ($dir !== '') {
                            $testDirs[] = $dir;
                        }
                    }
                }
            }
        }

        if (empty($testDirs)) {
            $testDirs = ['tests/Unit', 'tests/Feature'];
        }

        $testDirs = array_values(array_unique($testDirs));
        $files = [];

        foreach ($testDirs as $relDir) {
            $absDir = $this->rootDir.'/'.$relDir;
            if (! is_dir($absDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if ($item->isFile() && str_ends_with($item->getFilename(), 'Test.php')) {
                    $relPath = str_replace('\\', '/', substr($item->getPathname(), strlen($this->rootDir) + 1));
                    $files[] = $relPath;
                }
            }
        }

        // Support schema unit test if present
        $supportTest = 'tests/Support/DisposableMariaDbSchemaTest.php';
        if (is_file($this->rootDir.'/'.$supportTest)) {
            $files[] = $supportTest;
        }

        sort($files, SORT_STRING);

        return array_values(array_unique($files));
    }

    /**
     * Partition test inventory into deterministic disjoint batches.
     *
     * @param  list<string>  $files
     * @return list<array{index: int, files: list<string>, count: int, digest: string}>
     */
    public function partitionInventory(array $files, int $batchSize): array
    {
        $chunks = array_chunk($files, max(1, $batchSize));
        $batches = [];
        $assigned = [];

        foreach ($chunks as $idx => $chunk) {
            $batchIndex = $idx + 1;
            foreach ($chunk as $f) {
                if (isset($assigned[$f])) {
                    throw new RuntimeException("Disjoint invariant violated: file '{$f}' assigned multiple times.");
                }
                $assigned[$f] = $batchIndex;
            }

            $digest = hash('sha256', implode("\n", $chunk));
            $batches[] = [
                'index' => $batchIndex,
                'files' => $chunk,
                'count' => count($chunk),
                'digest' => $digest,
            ];
        }

        if (count($assigned) !== count($files)) {
            throw new RuntimeException('Complete inventory invariant violated: expected '.count($files).' files, partitioned '.count($assigned));
        }

        return $batches;
    }

    /**
     * Run static analysis and linting checks.
     * Fails closed on any check with a non-zero exit code without advisory bypasses.
     *
     * @return array<string, array{status: string, exit_code: int, duration: float, log_file: string}>
     */
    public function runStaticChecks(string $outDir): array
    {
        $staticDir = $outDir.'/static';
        if (! is_dir($staticDir)) {
            mkdir($staticDir, 0700, true);
        }

        $checks = [
            'pint' => 'vendor/bin/pint --test',
            'phpstan' => 'vendor/bin/phpstan analyse --no-progress',
            'js_tests' => 'node --test tests/Frontend/*.test.js tests/Frontend/*.test.mjs tests/JavaScript/*.test.mjs',
            'build' => 'npm run build',
            'composer_audit' => 'composer audit',
            'npm_audit' => 'npm audit',
        ];

        $results = [];

        foreach ($checks as $name => $cmd) {
            $this->logInfo("  Running static check: {$name} ('{$cmd}')...");
            $logFile = $staticDir."/{$name}.log";
            $start = microtime(true);

            $exec = $this->executeShellCommand($cmd, $this->rootDir);
            $duration = microtime(true) - $start;

            file_put_contents($logFile, "COMMAND: {$cmd}\nEXIT CODE: {$exec['exit_code']}\nDURATION: {$duration}s\n\n--- STDOUT ---\n{$exec['stdout']}\n\n--- STDERR ---\n{$exec['stderr']}\n");

            // Fails closed: non-zero exit code on any check (including audits) is FAILED
            $passed = $exec['exit_code'] === 0;
            $status = $passed ? 'PASSED' : 'FAILED';

            $results[$name] = [
                'status' => $status,
                'exit_code' => $exec['exit_code'],
                'duration' => $duration,
                'log_file' => $logFile,
            ];

            $this->logInfo("  -> {$name}: {$status} (".round($duration, 2).'s)');
        }

        return $results;
    }

    /**
     * Verify that all static checks passed with exit code 0.
     * Strictly fails closed without undocumented advisory bypasses.
     *
     * @param  array<string, array{status: string, exit_code?: int}>  $staticResults
     */
    public function allStaticChecksPassed(array $staticResults): bool
    {
        if (empty($staticResults)) {
            return false;
        }

        foreach ($staticResults as $name => $res) {
            $exitCode = $res['exit_code'] ?? ($res['status'] === 'PASSED' ? 0 : 1);
            if ($res['status'] !== 'PASSED' || $exitCode !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute one bounded test batch via tests/Support/run-phase8-disposable.php.
     *
     * @param  list<string>  $files
     * @param  array<string, string>  $dbEnv
     * @return array{
     *     batch: int,
     *     total_batches: int,
     *     status: string,
     *     exit_code: int,
     *     duration: float,
     *     tests: int,
     *     assertions: int,
     *     failures: int,
     *     errors: int,
     *     skipped: int,
     *     failure_messages: list<string>,
     *     junit_file: string,
     *     log_file: string
     * }
     */
    public function runBatch(
        int $batchNumber,
        int $totalBatches,
        array $files,
        string $outDir,
        int $timeout,
        array $dbEnv
    ): array {
        $batchDir = $outDir.'/batches/batch_'.sprintf('%02d', $batchNumber);
        if (! is_dir($batchDir)) {
            mkdir($batchDir, 0700, true);
        }

        $junitFile = $batchDir.'/junit.xml';
        $logFile = $batchDir.'/output.log';

        // Stale artifact elimination: remove old junit/log files if present before running
        if (is_file($junitFile)) {
            unlink($junitFile);
        }
        if (is_file($logFile)) {
            unlink($logFile);
        }

        $args = array_merge(
            ['--log-junit', $junitFile],
            $files
        );

        $escapedArgs = array_map('escapeshellarg', $args);
        $cmd = PHP_BINARY.' tests/Support/run-phase8-disposable.php -- '.implode(' ', $escapedArgs);

        $env = array_merge($_ENV, $dbEnv, [
            'PHASE8_TEST_TIMEOUT' => (string) $timeout,
        ]);

        $start = microtime(true);
        $exec = $this->executeShellCommand($cmd, $this->rootDir, $env, $timeout);
        $duration = microtime(true) - $start;

        file_put_contents(
            $logFile,
            "BATCH: {$batchNumber}/{$totalBatches}\nCOMMAND: {$cmd}\nEXIT CODE: {$exec['exit_code']}\nDURATION: {$duration}s\nTIMED OUT: ".($exec['timed_out'] ? 'YES' : 'NO')."\n\n--- OUTPUT ---\n".$exec['stdout']."\n".$exec['stderr']
        );

        $junitStats = $this->parseJunitReport($junitFile);

        // Fail-closed invariants:
        // 1. Process exit code must be 0
        // 2. Process must not have timed out
        // 3. JUnit report must exist, be non-empty, and contain valid XML
        // 4. Test count must be non-zero when files were assigned
        // 5. Failures and errors must be zero
        $hasProcessFailure = $exec['exit_code'] !== 0 || $exec['timed_out'];
        $hasJunitFailure = ! $junitStats['valid'] || ($junitStats['tests'] === 0 && ! empty($files));
        $hasAssertionFailure = $junitStats['failures'] > 0 || $junitStats['errors'] > 0;

        $failed = $hasProcessFailure || $hasJunitFailure || $hasAssertionFailure;
        $status = $failed ? 'FAILED' : 'PASSED';

        $failureMessages = $junitStats['failure_messages'];

        if ($exec['timed_out']) {
            $failureMessages[] = "Batch {$batchNumber}: Process timed out after {$timeout}s";
        } elseif ($exec['exit_code'] !== 0) {
            $failureMessages[] = "Batch {$batchNumber}: Process exited with non-zero exit code {$exec['exit_code']}";
        }

        if (! $junitStats['valid']) {
            $failureMessages[] = "Batch {$batchNumber}: JUnit report failure ({$junitStats['error_message']})";
        } elseif ($junitStats['tests'] === 0 && ! empty($files)) {
            $failureMessages[] = "Batch {$batchNumber}: JUnit report recorded zero executed tests for ".count($files).' test files';
        }

        $failuresCount = $junitStats['failures'];
        $errorsCount = $junitStats['errors'];

        // If batch failed due to process crash, timeout, or invalid JUnit, ensure failure count is at least 1
        if ($failed && $failuresCount === 0 && $errorsCount === 0) {
            $failuresCount = 1;
        }

        return [
            'batch' => $batchNumber,
            'total_batches' => $totalBatches,
            'status' => $status,
            'exit_code' => $exec['exit_code'],
            'duration' => $duration,
            'tests' => $junitStats['tests'],
            'assertions' => $junitStats['assertions'],
            'failures' => $failuresCount,
            'errors' => $errorsCount,
            'skipped' => $junitStats['skipped'],
            'failure_messages' => array_values(array_unique($failureMessages)),
            'junit_file' => $junitFile,
            'log_file' => $logFile,
        ];
    }

    /**
     * Parse JUnit XML report to extract test counts and failure diagnostics.
     * Counts leaf suites and testcase records to prevent multi-level duplicate aggregation.
     * Fails closed on missing, empty, or malformed XML.
     *
     * @return array{
     *     valid: bool,
     *     error_message: string|null,
     *     tests: int,
     *     assertions: int,
     *     failures: int,
     *     errors: int,
     *     skipped: int,
     *     failure_messages: list<string>
     * }
     */
    public function parseJunitReport(string $junitFile): array
    {
        $defaultInvalid = static function (string $reason) use ($junitFile): array {
            return [
                'valid' => false,
                'error_message' => $reason,
                'tests' => 0,
                'assertions' => 0,
                'failures' => 0,
                'errors' => 0,
                'skipped' => 0,
                'failure_messages' => ["JUnit report invalid: {$reason} ({$junitFile})"],
            ];
        };

        if (! is_file($junitFile)) {
            return $defaultInvalid('JUnit report file does not exist');
        }

        $fileSize = filesize($junitFile);
        if ($fileSize === false || $fileSize === 0) {
            return $defaultInvalid('JUnit report file is empty (0 bytes)');
        }

        try {
            $xml = @simplexml_load_file($junitFile);
            if ($xml === false) {
                return $defaultInvalid('JUnit report XML is malformed');
            }

            // Count leaf suites (testsuite elements that do not contain child testsuite elements)
            // This prevents counting tests and assertions multiple times across parent aggregate suites.
            $leafSuites = $xml->xpath('//testsuite[not(testsuite)]') ?: [];
            $leafTests = 0;
            $leafAssertions = 0;
            $leafFailures = 0;
            $leafErrors = 0;
            $leafSkipped = 0;

            foreach ($leafSuites as $suite) {
                if (isset($suite['tests'])) {
                    $leafTests += (int) $suite['tests'];
                }
                if (isset($suite['assertions'])) {
                    $leafAssertions += (int) $suite['assertions'];
                }
                if (isset($suite['failures'])) {
                    $leafFailures += (int) $suite['failures'];
                }
                if (isset($suite['errors'])) {
                    $leafErrors += (int) $suite['errors'];
                }
                if (isset($suite['skipped'])) {
                    $leafSkipped += (int) $suite['skipped'];
                }
            }

            // Also inspect actual testcase records to capture testcases placed outside standard suites
            $testCases = $xml->xpath('//testcase') ?: [];
            $caseCount = count($testCases);
            $caseAssertions = 0;
            $caseFailures = 0;
            $caseErrors = 0;
            $caseSkipped = 0;

            foreach ($testCases as $tc) {
                if (isset($tc['assertions'])) {
                    $caseAssertions += (int) $tc['assertions'];
                }
                if (isset($tc->failure) && count($tc->failure) > 0) {
                    $caseFailures++;
                }
                if (isset($tc->error) && count($tc->error) > 0) {
                    $caseErrors++;
                }
                if (isset($tc->skipped) && count($tc->skipped) > 0) {
                    $caseSkipped++;
                }
            }

            // Reconcile leaf suites and testcase records without aggregate inflation
            $tests = max($leafTests, $caseCount);
            $assertions = max($leafAssertions, $caseAssertions);
            $failures = max($leafFailures, $caseFailures);
            $errors = max($leafErrors, $caseErrors);
            $skipped = max($leafSkipped, $caseSkipped);

            $failureMessages = [];
            $diagNodes = $xml->xpath('//failure | //error') ?: [];

            foreach ($diagNodes as $node) {
                $parent = $node->xpath('..')[0] ?? null;
                $testName = 'Unknown Target';
                if ($parent !== null) {
                    if ($parent->getName() === 'testcase') {
                        $class = (string) ($parent['class'] ?? '');
                        $name = (string) ($parent['name'] ?? '');
                        $testName = ($class !== '' && $name !== '') ? "{$class}::{$name}" : ($name !== '' ? $name : 'Unknown Test');
                    } elseif ($parent->getName() === 'testsuite') {
                        $suiteName = (string) ($parent['name'] ?? 'Unknown Suite');
                        $testName = "Suite: {$suiteName}";
                    }
                }

                $msgAttr = trim((string) ($node['message'] ?? ''));
                $bodyText = trim((string) $node);
                $diag = $msgAttr !== '' ? $msgAttr : $bodyText;
                if ($diag === '') {
                    $diag = 'Test failed with no explicit message.';
                }
                if (strlen($diag) > 400) {
                    $diag = substr($diag, 0, 397).'...';
                }

                $failureMessages[] = "{$testName}: {$diag}";
            }

            return [
                'valid' => true,
                'error_message' => null,
                'tests' => $tests,
                'assertions' => $assertions,
                'failures' => $failures,
                'errors' => $errors,
                'skipped' => $skipped,
                'failure_messages' => array_values(array_unique($failureMessages)),
            ];
        } catch (Throwable $e) {
            return $defaultInvalid("Exception parsing XML: {$e->getMessage()}");
        }
    }

    /**
     * Aggregate test counts across all completed batches.
     *
     * @param list<array{
     *     status: string,
     *     tests: int,
     *     assertions: int,
     *     failures: int,
     *     errors: int,
     *     skipped: int,
     *     duration: float,
     *     failure_messages: list<string>
     * }> $batchResults
     * @return array{
     *     total_batches: int,
     *     passed_batches: int,
     *     failed_batches: int,
     *     total_tests: int,
     *     total_assertions: int,
     *     total_failures: int,
     *     total_errors: int,
     *     total_skipped: int,
     *     total_duration: float,
     *     failure_messages: list<string>
     * }
     */
    public function aggregateResults(array $batchResults): array
    {
        $totals = [
            'total_batches' => count($batchResults),
            'passed_batches' => 0,
            'failed_batches' => 0,
            'total_tests' => 0,
            'total_assertions' => 0,
            'total_failures' => 0,
            'total_errors' => 0,
            'total_skipped' => 0,
            'total_duration' => 0.0,
            'failure_messages' => [],
        ];

        foreach ($batchResults as $res) {
            if ($res['status'] === 'PASSED') {
                $totals['passed_batches']++;
            } else {
                $totals['failed_batches']++;
            }

            $totals['total_tests'] += $res['tests'];
            $totals['total_assertions'] += $res['assertions'];
            $totals['total_failures'] += $res['failures'];
            $totals['total_errors'] += $res['errors'];
            $totals['total_skipped'] += $res['skipped'];
            $totals['total_duration'] += $res['duration'];

            foreach ($res['failure_messages'] as $msg) {
                $totals['failure_messages'][] = $msg;
            }
        }

        $totals['failure_messages'] = array_values(array_unique($totals['failure_messages']));

        return $totals;
    }

    public function createDefaultOutputDir(): string
    {
        $dir = sys_get_temp_dir().'/account-qualify-'.date('Ymd-His').'-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $coverage
     * @param  list<string>  $skippedChecks
     */
    public function printSummary(
        array $totals,
        bool $allPassed,
        bool $fullReleaseQualified,
        array $coverage,
        array $skippedChecks,
        string $outDir
    ): void {
        $sep = str_repeat('=', 80);
        if ($fullReleaseQualified) {
            $resLabel = 'ALL QUALIFICATION GATES PASSED (Full Release Qualification Scope: 100% Coverage)';
        } elseif ($allPassed) {
            $resLabel = 'PARTIAL SCOPE PASSED (NOT FULL QUALIFICATION)';
        } else {
            $resLabel = 'QUALIFICATION FAILED';
        }

        $this->logInfo("\n".$sep);
        $this->logInfo("  {$resLabel}");
        $this->logInfo($sep);
        $this->logInfo('Full Qualified: '.($fullReleaseQualified ? 'YES' : 'NO (partial scope or skipped checks)'));
        if (! empty($skippedChecks)) {
            $this->logInfo('Skipped Checks: '.implode(', ', $skippedChecks));
        }
        $this->logInfo("File Coverage:  {$coverage['completed_files_count']} / {$coverage['expected_inventory_count']} files ({$coverage['coverage_percentage']}%)");
        $this->logInfo("Batches:        {$totals['passed_batches']} / {$totals['total_batches']} passed ({$totals['failed_batches']} failed)");
        $this->logInfo("Tests:          {$totals['total_tests']}");
        $this->logInfo("Assertions:     {$totals['total_assertions']}");
        $this->logInfo("Failures:       {$totals['total_failures']}");
        $this->logInfo("Errors:         {$totals['total_errors']}");
        $this->logInfo("Skipped:        {$totals['total_skipped']}");
        $this->logInfo('Duration:       '.round($totals['total_duration'], 2).'s');
        $this->logInfo("Artifacts:      {$outDir}");
        $this->logInfo($sep);
    }

    /**
     * @param  array<string, mixed>  $staticResults
     * @param  list<array<string, mixed>>  $batchResults
     * @param  array<string, mixed>  $coverage
     * @param  list<string>  $skippedChecks
     * @param  list<string>  $completedFiles
     * @param  array<string, mixed>  $totals
     */
    public function writeReportFile(
        string $outDir,
        string $commit,
        array $staticResults,
        array $batchResults,
        bool $allPassed,
        bool $fullReleaseQualified,
        string $verdict,
        array $coverage,
        array $skippedChecks,
        array $completedFiles,
        array $totals
    ): void {
        $report = [
            'runner' => self::RUNNER_NAME,
            'version' => self::VERSION,
            'commit' => $commit,
            'timestamp' => date('c'),
            'full_release_qualified' => $fullReleaseQualified,
            'verdict' => $verdict,
            'passed' => $allPassed,
            'coverage' => $coverage,
            'skipped_checks' => $skippedChecks,
            'completed_files' => $completedFiles,
            'totals' => $totals,
            'static_checks' => $staticResults,
            'batches' => $batchResults,
        ];

        file_put_contents(
            $outDir.'/qualification-summary.json',
            (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param  list<string>  $inventory
     * @param  list<array{index: int, files: list<string>, count: int, digest: string}>  $batches
     */
    private function outputInventoryJson(array $inventory, array $batches): void
    {
        $payload = [
            'runner' => self::RUNNER_NAME,
            'version' => self::VERSION,
            'total_files' => count($inventory),
            'total_batches' => count($batches),
            'inventory' => $inventory,
            'batches' => $batches,
        ];

        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * @param  list<array{index: int, files: list<string>, count: int, digest: string}>  $batches
     */
    private function outputBatchesList(array $batches): void
    {
        echo '=== Disjoint Complete Test Batches ('.count($batches)." total) ===\n";
        foreach ($batches as $batch) {
            echo sprintf("Batch %02d: %3d files (SHA-256: %s)\n", $batch['index'], $batch['count'], substr($batch['digest'], 0, 16));
            if ($this->verbose) {
                foreach ($batch['files'] as $f) {
                    echo "  - {$f}\n";
                }
            }
        }
    }

    private function printVersion(): void
    {
        echo self::RUNNER_NAME.' v'.self::VERSION."\n";
    }

    private function printHelp(): void
    {
        echo self::RUNNER_NAME.' v'.self::VERSION."\n\n";
        echo "Usage: php tools/release/qualify [options]\n\n";
        echo "Options:\n";
        echo "  --expected-commit=<sha>   Fail closed if current git HEAD does not match.\n";
        echo "  --allow-dirty             Bypass dirty tracked working tree check (dev only; marks partial).\n";
        echo "  --preflight-only          Run all fail-closed preflight checks and exit.\n";
        echo "  --static-only             Run preflight checks and static analysis suite (no DB).\n";
        echo "  --list-batches            Show disjoint complete test inventory batches and exit.\n";
        echo "  --inventory               Output JSON inventory of discovered tests and batches.\n";
        echo "  --batch=<n>               Run only batch N (1-indexed) over disposable MariaDB.\n";
        echo '  --batch-size=<n>          Test files per batch (default: '.self::DEFAULT_BATCH_SIZE.").\n";
        echo '  --batch-timeout=<n>       Timeout per batch in seconds (default: '.self::DEFAULT_BATCH_TIMEOUT.").\n";
        echo "  --out-dir=<path>          Directory for private JUnit XML and logs (refuses non-empty collisions).\n";
        echo "  --skip-static             Skip static analysis during run (marks partial).\n";
        echo "  --skip-db                 Skip MariaDB tests (preflight + static only; marks partial).\n";
        echo "  -v, --verbose             Print verbose output.\n";
        echo "  -V, --version             Display runner version and exit.\n";
        echo "  -h, --help                Display this help message.\n\n";
        echo "Exit Codes:\n";
        echo "  0: Success (all checks and tests passed)\n";
        echo "  1: Test or static analysis failure\n";
        echo "  2: Refusal on wrong commit or dirty tracked source\n";
        echo "  3: Refusal on dependencies or platform requirements\n";
        echo "  4: Refusal on disposable database configuration\n";
        echo "  5: Refusal on output directory collision\n";
    }

    /**
     * Execute shell command with process timeout enforcement and stream safety.
     *
     * @param  array<string, string>|null  $env
     * @return array{stdout: string, stderr: string, exit_code: int, timed_out: bool}
     */
    public function executeShellCommand(string $command, string $cwd, ?array $env = null, ?int $timeout = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (! is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Failed to start process', 'exit_code' => 1, 'timed_out' => false];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = microtime(true);
        $timedOut = false;

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, 100000); // 100ms

            if ($changed !== false && $changed > 0) {
                foreach ($read as $r) {
                    if ($r === $pipes[1]) {
                        $chunk = fread($pipes[1], 8192);
                        if ($chunk !== false) {
                            $stdout .= $chunk;
                        }
                    } elseif ($r === $pipes[2]) {
                        $chunk = fread($pipes[2], 8192);
                        if ($chunk !== false) {
                            $stderr .= $chunk;
                        }
                    }
                }
            }

            $status = proc_get_status($process);
            if (! $status['running']) {
                $remOut = stream_get_contents($pipes[1]);
                if ($remOut !== false) {
                    $stdout .= $remOut;
                }
                $remErr = stream_get_contents($pipes[2]);
                if ($remErr !== false) {
                    $stderr .= $remErr;
                }
                break;
            }

            if ($timeout !== null && (microtime(true) - $startTime) > $timeout) {
                $timedOut = true;
                proc_terminate($process, 9); // SIGKILL
                $stderr .= "\n[ERROR] Command exceeded timeout of {$timeout} seconds and was terminated.\n";
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            $exitCode = 124;
        }

        return [
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
        ];
    }

    private function logInfo(string $message): void
    {
        fwrite(STDOUT, $message.PHP_EOL);
    }

    private function logError(string $message): void
    {
        fwrite(STDERR, $message.PHP_EOL);
    }
}

final class QualificationRefusalException extends RuntimeException {}
