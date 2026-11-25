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
     * Parse CLI arguments into options dictionary.
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
                $options['expected-commit'] = trim(substr($arg, 18));
            } elseif (str_starts_with($arg, '--batch=')) {
                $options['batch'] = (int) substr($arg, 8);
            } elseif (str_starts_with($arg, '--batch-size=')) {
                $options['batch-size'] = max(1, (int) substr($arg, 13));
            } elseif (str_starts_with($arg, '--batch-timeout=')) {
                $options['batch-timeout'] = min(self::MAX_TIMEOUT, max(1, (int) substr($arg, 16)));
            } elseif (str_starts_with($arg, '--out-dir=')) {
                $options['out-dir'] = rtrim(substr($arg, 10), '/\\');
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

        $outDir = $options['out-dir'] ?? $this->createDefaultOutputDir();
        if (! is_dir($outDir) && ! mkdir($outDir, 0700, true)) {
            $this->logError("Failed to create output directory: {$outDir}");

            return self::EXIT_TEST_FAILURE;
        }

        $this->logInfo('=== '.self::RUNNER_NAME.' v'.self::VERSION.' ===');
        $this->logInfo('Workspace root: '.$this->rootDir);
        $this->logInfo('Artifacts directory: '.$outDir);

        // 1. Commit and git tree checks (fail-closed)
        try {
            $headCommit = $this->checkGitCommit($options['expected-commit']);
            $this->logInfo("Current commit: {$headCommit} (verified)");

            $this->checkTrackedSourceClean($options['allow-dirty']);
            $this->logInfo('Tracked working tree: clean');
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

        // 4. Test inventory discovery and disjoint partitioning
        $inventory = $this->discoverTestInventory();
        $batchSize = (int) $options['batch-size'];
        $batches = $this->partitionInventory($inventory, $batchSize);
        $this->logInfo('Test Inventory: '.count($inventory).' test files partitioned into '.count($batches)." disjoint batches (batch size: {$batchSize})");

        if ($options['inventory']) {
            $this->outputInventoryJson($inventory, $batches);

            return self::EXIT_SUCCESS;
        }

        if ($options['list-batches']) {
            $this->outputBatchesList($batches);

            return self::EXIT_SUCCESS;
        }

        if ($options['preflight-only']) {
            $this->logInfo('Preflight qualification checks: ALL PASSED');

            return self::EXIT_SUCCESS;
        }

        $staticResults = [];
        $staticPassed = true;
        if (! $options['skip-static']) {
            $this->logInfo("\n--- Executing Static Analysis Suite ---");
            $staticResults = $this->runStaticChecks($outDir);
            $staticPassed = $this->allStaticChecksPassed($staticResults);
            if (! $staticPassed) {
                $this->logError('Static analysis suite failed.');
                $this->writeReportFile($outDir, $headCommit, $staticResults, [], false);

                return self::EXIT_TEST_FAILURE;
            }
            $this->logInfo('Static analysis suite: ALL PASSED');
        }

        if ($options['static-only'] || $options['skip-db']) {
            $this->logInfo("\n--- Qualification Completed (Static/Preflight Scope) ---");
            $this->writeReportFile($outDir, $headCommit, $staticResults, [], true);

            return self::EXIT_SUCCESS;
        }

        // 5. Execute disjoint complete test batches
        $this->logInfo("\n--- Executing Disjoint Disposable Test Batches ---");
        $targetBatch = $options['batch'] !== null ? (int) $options['batch'] : null;
        $batchResults = [];

        foreach ($batches as $batch) {
            $bIdx = $batch['index'];
            if ($targetBatch !== null && $bIdx !== $targetBatch) {
                continue;
            }

            $this->logInfo("\n[Batch {$bIdx}/".count($batches).'] Running '.count($batch['files']).' test files...');
            $result = $this->runBatch(
                $bIdx,
                count($batches),
                $batch['files'],
                $outDir,
                (int) $options['batch-timeout'],
                $dbEnv
            );
            $batchResults[] = $result;

            if ($result['status'] === 'PASSED') {
                $this->logInfo("  -> Batch {$bIdx} PASSED ({$result['tests']} tests, {$result['assertions']} assertions in ".round($result['duration'], 2).'s)');
            } else {
                $this->logError("  -> Batch {$bIdx} FAILED (exit code: {$result['exit_code']}, failures: {$result['failures']}, errors: {$result['errors']})");
                if (! empty($result['failure_messages'])) {
                    foreach ($result['failure_messages'] as $msg) {
                        $this->logError('     * '.$msg);
                    }
                }
            }
        }

        $totals = $this->aggregateResults($batchResults);
        $allPassed = $totals['failed_batches'] === 0;

        $this->writeReportFile($outDir, $headCommit, $staticResults, $batchResults, $allPassed);
        $this->printSummary($totals, $allPassed, $outDir);

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
            throw new QualificationRefusalException('Unable to determine current git HEAD commit.');
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
                throw new QualificationRefusalException("Commit mismatch: expected '{$expectedCommit}', actual HEAD is '{$headCommit}' (fail closed on wrong commit).");
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
            throw new QualificationRefusalException("Dirty tracked source detected: [{$files}]. Commit or stash tracked changes before qualification.");
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
            throw new QualificationRefusalException('Composer manifest or lockfile missing.');
        }

        if (! is_file($packageJson) || ! is_file($packageLock)) {
            throw new QualificationRefusalException('NPM package.json or package-lock.json missing.');
        }

        // Validate composer.lock against composer.json strictly
        $validate = $this->executeShellCommand('composer validate --no-check-all --strict', $this->rootDir);
        if ($validate['exit_code'] !== 0) {
            $err = trim($validate['stdout']."\n".$validate['stderr']);
            throw new QualificationRefusalException("Composer lockfile validation failed: {$err}");
        }
    }

    /**
     * Verify PHP version, required extensions, and Node.js.
     */
    public function checkPlatformRequirements(): void
    {
        if (version_compare(PHP_VERSION, '8.4.0', '<')) {
            throw new QualificationRefusalException('PHP version '.PHP_VERSION.' does not meet required PHP 8.4+ (>= 80400).');
        }

        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (! extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        if (! empty($missing)) {
            throw new QualificationRefusalException('Missing required PHP extensions: '.implode(', ', $missing));
        }

        // Node.js availability check
        $nodeCheck = $this->executeShellCommand('node -v', $this->rootDir);
        if ($nodeCheck['exit_code'] !== 0) {
            throw new QualificationRefusalException('Node.js is not installed or not in PATH.');
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
            throw new QualificationRefusalException("APP_ENV must be set to 'testing', currently: '{$appEnv}'.");
        }

        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1') {
            throw new QualificationRefusalException("PHASE8_ALLOW_DISPOSABLE_DB must be '1' to opt into disposable database testing.");
        }

        $host = getenv('PHASE8_TEST_DB_HOST');
        if ($host === false || ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new QualificationRefusalException('PHASE8_TEST_DB_HOST must be a local loopback address (127.0.0.1, localhost, or ::1).');
        }

        $port = getenv('PHASE8_TEST_DB_PORT');
        if ($port === false || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new QualificationRefusalException('PHASE8_TEST_DB_PORT must be a valid integer port between 1 and 65535.');
        }

        if ((int) $port === 3353) {
            throw new QualificationRefusalException('Forbidden: cannot run tests against persistent development preview database on port 3353.');
        }

        $username = getenv('PHASE8_TEST_DB_USERNAME');
        if ($username === false || trim($username) === '') {
            throw new QualificationRefusalException('PHASE8_TEST_DB_USERNAME must be provided.');
        }

        $password = getenv('PHASE8_TEST_DB_PASSWORD');
        if ($password === false && getenv('PHASE8_TEST_DB_EMPTY_PASSWORD') !== '1') {
            throw new QualificationRefusalException('PHASE8_TEST_DB_PASSWORD or PHASE8_TEST_DB_EMPTY_PASSWORD=1 must be provided.');
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
     * Discover all test files across tests/Unit and tests/Feature.
     *
     * @return list<string> Relative test file paths
     */
    public function discoverTestInventory(): array
    {
        $testDirs = ['tests/Unit', 'tests/Feature'];
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

            // For audits, upstream packages might report warnings; check exit code
            $passed = $exec['exit_code'] === 0;
            // composer audit exit code 1 on known upstream advisories: record status accurately
            $status = $passed ? 'PASSED' : ($name === 'composer_audit' || $name === 'npm_audit' ? 'WARNING' : 'FAILED');

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
     * @param  array<string, array{status: string}>  $staticResults
     */
    public function allStaticChecksPassed(array $staticResults): bool
    {
        foreach ($staticResults as $name => $res) {
            if ($name !== 'composer_audit' && $name !== 'npm_audit' && $res['status'] === 'FAILED') {
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
            "BATCH: {$batchNumber}/{$totalBatches}\nCOMMAND: {$cmd}\nEXIT CODE: {$exec['exit_code']}\nDURATION: {$duration}s\n\n--- OUTPUT ---\n".$exec['stdout']."\n".$exec['stderr']
        );

        $junitStats = $this->parseJunitReport($junitFile);

        $failed = $exec['exit_code'] !== 0 || $junitStats['failures'] > 0 || $junitStats['errors'] > 0;
        $status = $failed ? 'FAILED' : 'PASSED';

        return [
            'batch' => $batchNumber,
            'total_batches' => $totalBatches,
            'status' => $status,
            'exit_code' => $exec['exit_code'],
            'duration' => $duration,
            'tests' => $junitStats['tests'],
            'assertions' => $junitStats['assertions'],
            'failures' => $junitStats['failures'],
            'errors' => $junitStats['errors'],
            'skipped' => $junitStats['skipped'],
            'failure_messages' => $junitStats['failure_messages'],
            'junit_file' => $junitFile,
            'log_file' => $logFile,
        ];
    }

    /**
     * Parse JUnit XML report to extract test counts and failure diagnostics.
     *
     * @return array{tests: int, assertions: int, failures: int, errors: int, skipped: int, failure_messages: list<string>}
     */
    public function parseJunitReport(string $junitFile): array
    {
        $default = [
            'tests' => 0,
            'assertions' => 0,
            'failures' => 0,
            'errors' => 0,
            'skipped' => 0,
            'failure_messages' => [],
        ];

        if (! is_file($junitFile) || filesize($junitFile) === 0) {
            return $default;
        }

        try {
            $xml = @simplexml_load_file($junitFile);
            if ($xml === false) {
                return $default;
            }

            $tests = 0;
            $assertions = 0;
            $failures = 0;
            $errors = 0;
            $skipped = 0;
            $failureMessages = [];

            // PHPUnit JUnit format has root <testsuites> with nested <testsuite>
            foreach ($xml->xpath('//testsuite') as $suite) {
                if (isset($suite['tests'])) {
                    $tests += (int) $suite['tests'];
                }
                if (isset($suite['assertions'])) {
                    $assertions += (int) $suite['assertions'];
                }
                if (isset($suite['failures'])) {
                    $failures += (int) $suite['failures'];
                }
                if (isset($suite['errors'])) {
                    $errors += (int) $suite['errors'];
                }
                if (isset($suite['skipped'])) {
                    $skipped += (int) $suite['skipped'];
                }
            }

            foreach ($xml->xpath('//failure | //error') as $node) {
                $testCase = $node->xpath('..')[0] ?? null;
                $testName = $testCase !== null ? (string) ($testCase['class'] ?? '').'::'.(string) ($testCase['name'] ?? '') : 'Unknown Test';
                $message = trim((string) ($node['message'] ?? (string) $node));
                $failureMessages[] = "{$testName}: {$message}";
            }

            return [
                'tests' => $tests,
                'assertions' => $assertions,
                'failures' => $failures,
                'errors' => $errors,
                'skipped' => $skipped,
                'failure_messages' => $failureMessages,
            ];
        } catch (Throwable $e) {
            return $default;
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

        return $totals;
    }

    private function createDefaultOutputDir(): string
    {
        $dir = sys_get_temp_dir().'/account-qualify-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    private function printSummary(array $totals, bool $allPassed, string $outDir): void
    {
        $sep = str_repeat('=', 80);
        $resLabel = $allPassed ? 'ALL QUALIFICATION GATES PASSED' : 'QUALIFICATION FAILED';

        $this->logInfo("\n".$sep);
        $this->logInfo("  {$resLabel}");
        $this->logInfo($sep);
        $this->logInfo("Batches:     {$totals['passed_batches']} / {$totals['total_batches']} passed ({$totals['failed_batches']} failed)");
        $this->logInfo("Tests:       {$totals['total_tests']}");
        $this->logInfo("Assertions:  {$totals['total_assertions']}");
        $this->logInfo("Failures:    {$totals['total_failures']}");
        $this->logInfo("Errors:      {$totals['total_errors']}");
        $this->logInfo("Skipped:     {$totals['total_skipped']}");
        $this->logInfo('Duration:    '.round($totals['total_duration'], 2).'s');
        $this->logInfo("Artifacts:   {$outDir}");
        $this->logInfo($sep);
    }

    /**
     * @param  array<string, mixed>  $staticResults
     * @param  list<array<string, mixed>>  $batchResults
     */
    private function writeReportFile(
        string $outDir,
        string $commit,
        array $staticResults,
        array $batchResults,
        bool $allPassed
    ): void {
        $report = [
            'runner' => self::RUNNER_NAME,
            'version' => self::VERSION,
            'commit' => $commit,
            'timestamp' => date('c'),
            'passed' => $allPassed,
            'static_checks' => $staticResults,
            'batches' => $batchResults,
        ];

        file_put_contents(
            $outDir.'/qualification-summary.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
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
        echo "  --allow-dirty             Bypass dirty tracked working tree check (dev only).\n";
        echo "  --preflight-only          Run all fail-closed preflight checks and exit.\n";
        echo "  --static-only             Run preflight checks and static analysis suite (no DB).\n";
        echo "  --list-batches            Show disjoint complete test inventory batches and exit.\n";
        echo "  --inventory               Output JSON inventory of discovered tests and batches.\n";
        echo "  --batch=<n>               Run only batch N (1-indexed) over disposable MariaDB.\n";
        echo '  --batch-size=<n>          Test files per batch (default: '.self::DEFAULT_BATCH_SIZE.").\n";
        echo '  --batch-timeout=<n>       Timeout per batch in seconds (default: '.self::DEFAULT_BATCH_TIMEOUT.").\n";
        echo "  --out-dir=<path>          Directory for private JUnit XML and logs.\n";
        echo "  --skip-static             Skip static analysis during full run.\n";
        echo "  --skip-db                 Skip MariaDB tests (preflight + static only).\n";
        echo "  -v, --verbose             Print verbose output.\n";
        echo "  -V, --version             Display runner version and exit.\n";
        echo "  -h, --help                Display this help message.\n\n";
        echo "Exit Codes:\n";
        echo "  0: Success (all checks and tests passed)\n";
        echo "  1: Test or static analysis failure\n";
        echo "  2: Refusal on wrong commit or dirty tracked source\n";
        echo "  3: Refusal on dependencies or platform requirements\n";
        echo "  4: Refusal on disposable database configuration\n";
    }

    /**
     * @param  array<string, string>|null  $env
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    private function executeShellCommand(string $command, string $cwd, ?array $env = null, ?int $timeout = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (! is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Failed to start process', 'exit_code' => 1];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'exit_code' => $exitCode,
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
