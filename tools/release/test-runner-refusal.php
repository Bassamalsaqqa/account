<?php

declare(strict_types=1);

require_once __DIR__.'/AccountQualificationRunner.php';

use Tools\Release\AccountQualificationRunner;
use Tools\Release\QualificationRefusalException;

final class RefusalTestRunner
{
    private int $passCount = 0;

    private int $failCount = 0;

    public function runTest(string $description, callable $fn): void
    {
        echo "TEST: {$description}... ";
        try {
            $fn();
            echo "[PASS]\n";
            $this->passCount++;
        } catch (Throwable $e) {
            echo '[FAIL] '.$e->getMessage()."\n";
            $this->failCount++;
        }
    }

    public function getPassCount(): int
    {
        return $this->passCount;
    }

    public function getFailCount(): int
    {
        return $this->failCount;
    }
}

$rootDir = dirname(__DIR__, 2);
$runner = new AccountQualificationRunner($rootDir);
$testRunner = new RefusalTestRunner;

function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $expStr = var_export($expected, true);
        $actStr = var_export($actual, true);
        throw new RuntimeException("Assertion failed: expected {$expStr}, got {$actStr}. {$message}");
    }
}

function assertTrue(bool $condition, string $message = ''): void
{
    if (! $condition) {
        throw new RuntimeException("Assertion failed: expected true. {$message}");
    }
}

function assertFalse(bool $condition, string $message = ''): void
{
    if ($condition) {
        throw new RuntimeException("Assertion failed: expected false. {$message}");
    }
}

echo "=== Running Account Qualification Runner Refusal & Integrity Tests ===\n\n";

// 1. Version and Help
$testRunner->runTest('Runner version is defined and matches Phase G', function () {
    assertEquals('1.0.0-phaseG', AccountQualificationRunner::VERSION);
    assertEquals('Account Local Release Qualification Runner (Phase G)', AccountQualificationRunner::RUNNER_NAME);
});

// 2. Commit verification: correct commit vs wrong commit
$testRunner->runTest('Passes commit check when exact HEAD commit matches', function () use ($runner) {
    $head = trim((string) shell_exec('git rev-parse HEAD'));
    $verified = $runner->checkGitCommit($head);
    assertEquals($head, $verified);

    // Also support prefix of at least 7 characters
    $prefix = substr($head, 0, 7);
    $verifiedPrefix = $runner->checkGitCommit($prefix);
    assertEquals($head, $verifiedPrefix);
});

$testRunner->runTest('Fails closed on wrong commit', function () use ($runner) {
    $wrongCommit = '0123456789abcdef0123456789abcdef01234567';
    $refused = false;
    try {
        $runner->checkGitCommit($wrongCommit);
    } catch (QualificationRefusalException $e) {
        $refused = true;
        assertTrue(str_contains($e->getMessage(), 'Commit mismatch'), 'Expected commit mismatch message');
        assertTrue(str_contains($e->getMessage(), 'fail closed on wrong commit'), 'Expected fail closed message');
        assertEquals(AccountQualificationRunner::EXIT_REFUSAL_COMMIT_OR_TREE, $e->getCode());
    }
    assertTrue($refused, 'Runner did not refuse wrong commit');
});

// 3. Platform requirements
$testRunner->runTest('Passes platform requirements check on supported PHP and Node', function () use ($runner) {
    $runner->checkPlatformRequirements();
    assertTrue(version_compare(PHP_VERSION, '8.4.0', '>='));
});

// 4. Locked dependencies check
$testRunner->runTest('Passes locked dependencies check when composer.lock and package-lock.json match', function () use ($runner) {
    $runner->checkLockedDependencies();
    assertTrue(file_exists($runner->getRootDir().'/composer.lock'));
    assertTrue(file_exists($runner->getRootDir().'/package-lock.json'));
});

// 5. Database configuration refusal tests
$testRunner->runTest('Fails closed when APP_ENV is not testing', function () use ($runner) {
    putenv('APP_ENV=production');
    putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
    putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
    putenv('PHASE8_TEST_DB_PORT=3306');
    putenv('PHASE8_TEST_DB_USERNAME=test');
    putenv('PHASE8_TEST_DB_PASSWORD=');
    putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');

    $refused = false;
    try {
        $runner->checkDisposableDatabaseConfiguration();
    } catch (QualificationRefusalException $e) {
        $refused = true;
        assertTrue(str_contains($e->getMessage(), "APP_ENV must be set to 'testing'"));
        assertEquals(AccountQualificationRunner::EXIT_REFUSAL_DATABASE, $e->getCode());
    } finally {
        putenv('APP_ENV');
    }
    assertTrue($refused, 'Runner did not refuse APP_ENV !== testing');
});

$testRunner->runTest('Fails closed when PHASE8_ALLOW_DISPOSABLE_DB is missing or not 1', function () use ($runner) {
    putenv('APP_ENV=testing');
    putenv('PHASE8_ALLOW_DISPOSABLE_DB=0');
    putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
    putenv('PHASE8_TEST_DB_PORT=3306');
    putenv('PHASE8_TEST_DB_USERNAME=test');
    putenv('PHASE8_TEST_DB_PASSWORD=');
    putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');

    $refused = false;
    try {
        $runner->checkDisposableDatabaseConfiguration();
    } catch (QualificationRefusalException $e) {
        $refused = true;
        assertTrue(str_contains($e->getMessage(), "PHASE8_ALLOW_DISPOSABLE_DB must be '1'"));
        assertEquals(AccountQualificationRunner::EXIT_REFUSAL_DATABASE, $e->getCode());
    } finally {
        putenv('APP_ENV');
        putenv('PHASE8_ALLOW_DISPOSABLE_DB');
    }
    assertTrue($refused, 'Runner did not refuse PHASE8_ALLOW_DISPOSABLE_DB !== 1');
});

$testRunner->runTest('Fails closed when PHASE8_TEST_DB_PORT is persistent port 3353', function () use ($runner) {
    putenv('APP_ENV=testing');
    putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
    putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
    putenv('PHASE8_TEST_DB_PORT=3353');
    putenv('PHASE8_TEST_DB_USERNAME=test');
    putenv('PHASE8_TEST_DB_PASSWORD=');
    putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');

    $refused = false;
    try {
        $runner->checkDisposableDatabaseConfiguration();
    } catch (QualificationRefusalException $e) {
        $refused = true;
        assertTrue(str_contains($e->getMessage(), 'Forbidden: cannot run tests against persistent development preview database on port 3353'));
        assertEquals(AccountQualificationRunner::EXIT_REFUSAL_DATABASE, $e->getCode());
    } finally {
        putenv('APP_ENV');
        putenv('PHASE8_ALLOW_DISPOSABLE_DB');
        putenv('PHASE8_TEST_DB_PORT');
    }
    assertTrue($refused, 'Runner did not refuse port 3353');
});

$testRunner->runTest('Fails closed when PHASE8_TEST_DB_HOST is not loopback', function () use ($runner) {
    putenv('APP_ENV=testing');
    putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
    putenv('PHASE8_TEST_DB_HOST=192.168.1.100');
    putenv('PHASE8_TEST_DB_PORT=3306');
    putenv('PHASE8_TEST_DB_USERNAME=test');
    putenv('PHASE8_TEST_DB_PASSWORD=');
    putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');

    $refused = false;
    try {
        $runner->checkDisposableDatabaseConfiguration();
    } catch (QualificationRefusalException $e) {
        $refused = true;
        assertTrue(str_contains($e->getMessage(), 'must be a local loopback address'));
        assertEquals(AccountQualificationRunner::EXIT_REFUSAL_DATABASE, $e->getCode());
    } finally {
        putenv('APP_ENV');
        putenv('PHASE8_ALLOW_DISPOSABLE_DB');
        putenv('PHASE8_TEST_DB_HOST');
    }
    assertTrue($refused, 'Runner did not refuse remote host');
});

$testRunner->runTest('Passes database configuration check when valid test loopback settings provided', function () use ($runner) {
    putenv('APP_ENV=testing');
    putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
    putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
    putenv('PHASE8_TEST_DB_PORT=3342');
    putenv('PHASE8_TEST_DB_USERNAME=test_runner');
    putenv('PHASE8_TEST_DB_PASSWORD=secret');

    $config = $runner->checkDisposableDatabaseConfiguration();
    assertEquals('127.0.0.1', $config['PHASE8_TEST_DB_HOST']);
    assertEquals('3342', $config['PHASE8_TEST_DB_PORT']);
    assertEquals('test_runner', $config['PHASE8_TEST_DB_USERNAME']);
    assertEquals('secret', $config['PHASE8_TEST_DB_PASSWORD']);

    putenv('APP_ENV');
    putenv('PHASE8_ALLOW_DISPOSABLE_DB');
    putenv('PHASE8_TEST_DB_HOST');
    putenv('PHASE8_TEST_DB_PORT');
    putenv('PHASE8_TEST_DB_USERNAME');
    putenv('PHASE8_TEST_DB_PASSWORD');
});

// 6. Test inventory discovery and disjoint complete batch partitioning
$testRunner->runTest('Discovers all test files and creates disjoint complete partition', function () use ($runner) {
    $inventory = $runner->discoverTestInventory();
    assertTrue(count($inventory) >= 400, 'Expected at least 400 test files, got '.count($inventory));

    // Test different batch sizes: 20, 25, 30, 50
    foreach ([20, 25, 30, 50] as $batchSize) {
        $batches = $runner->partitionInventory($inventory, $batchSize);

        $totalBatchedFiles = 0;
        $seen = [];

        foreach ($batches as $batch) {
            assertEquals($batch['count'], count($batch['files']), 'Batch count mismatch');
            foreach ($batch['files'] as $f) {
                assertTrue(! isset($seen[$f]), "Duplicate file {$f} detected across batches (disjoint violation)");
                $seen[$f] = true;
                $totalBatchedFiles++;
            }
        }

        assertEquals(count($inventory), $totalBatchedFiles, 'Complete inventory count mismatch');
        assertEquals(count($inventory), count($seen), 'Unique batched files mismatch');
    }
});

// 7. Finding 1: Static checks fail-closed on non-zero exit code without undocumented advisory bypasses
$testRunner->runTest('Static checks fail-closed when composer audit or npm audit has non-zero exit code', function () use ($runner) {
    // Check 1: composer_audit FAILED with exit code 1
    $staticResults = [
        'pint' => ['status' => 'PASSED', 'exit_code' => 0],
        'phpstan' => ['status' => 'PASSED', 'exit_code' => 0],
        'js_tests' => ['status' => 'PASSED', 'exit_code' => 0],
        'build' => ['status' => 'PASSED', 'exit_code' => 0],
        'composer_audit' => ['status' => 'FAILED', 'exit_code' => 1],
        'npm_audit' => ['status' => 'PASSED', 'exit_code' => 0],
    ];
    assertFalse($runner->allStaticChecksPassed($staticResults), 'Static checks should fail-closed on composer_audit non-zero exit');

    // Check 2: npm_audit FAILED with exit code 1
    $staticResults['composer_audit'] = ['status' => 'PASSED', 'exit_code' => 0];
    $staticResults['npm_audit'] = ['status' => 'FAILED', 'exit_code' => 1];
    assertFalse($runner->allStaticChecksPassed($staticResults), 'Static checks should fail-closed on npm_audit non-zero exit');

    // Check 3: All passed with exit code 0
    $staticResults['npm_audit'] = ['status' => 'PASSED', 'exit_code' => 0];
    assertTrue($runner->allStaticChecksPassed($staticResults), 'Static checks should pass when all exit codes are 0');
});

// 8. Finding 2: Strict numeric validation for batch, batch-size, and batch-timeout
$testRunner->runTest('Strict validation refuses invalid --batch, --batch-size, and --batch-timeout arguments', function () use ($runner) {
    $invalidBatches = ['--batch=abc', '--batch=0', '--batch=-1', '--batch=1.5'];
    foreach ($invalidBatches as $arg) {
        $caught = false;
        try {
            $runner->parseArguments(['qualify', $arg]);
        } catch (RuntimeException $e) {
            $caught = true;
            assertTrue(str_contains($e->getMessage(), 'Invalid --batch value'));
        }
        assertTrue($caught, "Expected exception for {$arg}");
    }

    $invalidBatchSizes = ['--batch-size=0', '--batch-size=-5', '--batch-size=xyz'];
    foreach ($invalidBatchSizes as $arg) {
        $caught = false;
        try {
            $runner->parseArguments(['qualify', $arg]);
        } catch (RuntimeException $e) {
            $caught = true;
            assertTrue(str_contains($e->getMessage(), 'Invalid --batch-size value'));
        }
        assertTrue($caught, "Expected exception for {$arg}");
    }

    $invalidTimeouts = ['--batch-timeout=0', '--batch-timeout=-10', '--batch-timeout=9999999'];
    foreach ($invalidTimeouts as $arg) {
        $caught = false;
        try {
            $runner->parseArguments(['qualify', $arg]);
        } catch (RuntimeException $e) {
            $caught = true;
            assertTrue(str_contains($e->getMessage(), 'Invalid --batch-timeout value'));
        }
        assertTrue($caught, "Expected exception for {$arg}");
    }
});

// 9. Finding 2: Nonexistent batch leads to failure and cannot return zero executed green
$testRunner->runTest('Nonexistent batch refuses to execute and fails closed', function () use ($runner) {
    $inventory = $runner->discoverTestInventory();
    $batches = $runner->partitionInventory($inventory, 25);
    $totalBatches = count($batches);

    // Call runner with nonexistent batch 999
    $opts = $runner->parseArguments(['qualify', '--preflight-only', '--batch=999', '--allow-dirty']);
    // When run directly with DB check bypassed via static-only or targeted batch beyond range
    $opts['batch'] = 999;
    $opts['skip-static'] = true;
    $opts['allow-dirty'] = true;

    // Create disposable outDir
    $tempDir = $runner->createDefaultOutputDir();
    $opts['out-dir'] = $tempDir;

    // Running with batch 999 must return EXIT_TEST_FAILURE (1), never EXIT_SUCCESS (0)
    $code = $runner->run($opts);
    assertEquals(AccountQualificationRunner::EXIT_TEST_FAILURE, $code, 'Nonexistent batch must return exit code 1');

    // Clean up tempDir
    shell_exec('rm -rf '.escapeshellarg($tempDir));
});

// 10. Finding 3: Missing, empty, or malformed JUnit fails closed
$testRunner->runTest('parseJunitReport returns invalid and failure messages on missing, empty, or malformed XML', function () use ($runner) {
    // Missing file
    $resMissing = $runner->parseJunitReport('/tmp/nonexistent_junit_file_'.bin2hex(random_bytes(4)).'.xml');
    assertFalse($resMissing['valid']);
    assertEquals(0, $resMissing['tests']);
    assertTrue(count($resMissing['failure_messages']) > 0);
    assertTrue(str_contains($resMissing['failure_messages'][0], 'does not exist'));

    // Empty file
    $emptyFile = tempnam(sys_get_temp_dir(), 'empty_junit_');
    file_put_contents($emptyFile, '');
    $resEmpty = $runner->parseJunitReport($emptyFile);
    unlink($emptyFile);
    assertFalse($resEmpty['valid']);
    assertEquals(0, $resEmpty['tests']);
    assertTrue(count($resEmpty['failure_messages']) > 0);
    assertTrue(str_contains($resEmpty['failure_messages'][0], 'empty'));

    // Malformed XML
    $malformedFile = tempnam(sys_get_temp_dir(), 'malformed_junit_');
    file_put_contents($malformedFile, '<testsuites><testsuite name="unclosed">');
    $resMalformed = $runner->parseJunitReport($malformedFile);
    unlink($malformedFile);
    assertFalse($resMalformed['valid']);
    assertEquals(0, $resMalformed['tests']);
    assertTrue(count($resMalformed['failure_messages']) > 0);
    assertTrue(str_contains($resMalformed['failure_messages'][0], 'malformed'));
});

// 11. Finding 4: Realistic nested PHPUnit JUnit XML does not sum nested aggregates multiple times
$testRunner->runTest('Correctly parses realistic nested PHPUnit JUnit XML without duplicate aggregate summation', function () use ($runner) {
    // In realistic PHPUnit XML, <testsuite name="Feature"> aggregates its child testsuites.
    // Querying //testsuite must NOT add parent and child counts together.
    $nestedXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Feature" tests="4" assertions="12" errors="0" failures="1" skipped="0" time="0.5">
    <testsuite name="Tests\Feature\Phase1\OrderTest" file="/app/tests/Feature/Phase1/OrderTest.php" tests="2" assertions="6" errors="0" failures="0" skipped="0" time="0.2">
      <testcase name="test_order_create" class="Tests\Feature\Phase1\OrderTest" classname="Tests.Feature.Phase1.OrderTest" file="/app/tests/Feature/Phase1/OrderTest.php" line="10" assertions="3" time="0.1"/>
      <testcase name="test_order_update" class="Tests\Feature\Phase1\OrderTest" classname="Tests.Feature.Phase1.OrderTest" file="/app/tests/Feature/Phase1/OrderTest.php" line="20" assertions="3" time="0.1"/>
    </testsuite>
    <testsuite name="Tests\Feature\Phase1\InvoiceTest" file="/app/tests/Feature/Phase1/InvoiceTest.php" tests="2" assertions="6" errors="0" failures="1" skipped="0" time="0.3">
      <testcase name="test_invoice_post" class="Tests\Feature\Phase1\InvoiceTest" classname="Tests.Feature.Phase1.InvoiceTest" file="/app/tests/Feature/Phase1/InvoiceTest.php" line="15" assertions="4" time="0.15"/>
      <testcase name="test_invoice_void" class="Tests\Feature\Phase1\InvoiceTest" classname="Tests.Feature.Phase1.InvoiceTest" file="/app/tests/Feature/Phase1/InvoiceTest.php" line="25" assertions="2" time="0.15">
        <failure message="Invoice balance mismatch">Failed asserting that 100.00 is 0.00.</failure>
      </testcase>
    </testsuite>
  </testsuite>
</testsuites>
XML;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_nested_junit_');
    file_put_contents($tempFile, $nestedXml);

    $stats = $runner->parseJunitReport($tempFile);
    unlink($tempFile);

    assertTrue($stats['valid'], 'Expected valid parse of nested XML');
    assertEquals(4, $stats['tests'], 'Total tests should be 4 (not doubled to 8 by parent aggregate)');
    assertEquals(12, $stats['assertions'], 'Total assertions should be 12 (not doubled to 24 by parent aggregate)');
    assertEquals(1, $stats['failures'], 'Total failures should be 1 (not doubled to 2 by parent aggregate)');
    assertEquals(0, $stats['errors']);
    assertEquals(0, $stats['skipped']);
    assertEquals(1, count($stats['failure_messages']));
    assertTrue(str_contains($stats['failure_messages'][0], 'Tests\Feature\Phase1\InvoiceTest::test_invoice_void: Invoice balance mismatch'));
});

// 12. Finding 5: Outdir collision refusal prevents stale artifact reuse
$testRunner->runTest('Refuses collision when output directory already exists and is not empty', function () use ($runner) {
    $tempDir = sys_get_temp_dir().'/test_outdir_collision_'.bin2hex(random_bytes(4));
    mkdir($tempDir, 0700, true);
    file_put_contents($tempDir.'/stale.txt', 'stale content');

    $refused = false;
    try {
        $runner->checkOutDirCollision($tempDir);
    } catch (QualificationRefusalException $e) {
        $refused = true;
        assertTrue(str_contains($e->getMessage(), 'already exists and is not empty'));
        assertEquals(AccountQualificationRunner::EXIT_REFUSAL_OUTDIR, $e->getCode());
    } finally {
        shell_exec('rm -rf '.escapeshellarg($tempDir));
    }

    assertTrue($refused, 'checkOutDirCollision did not refuse non-empty directory');
});

// 13. Finding 3 & 5: Process timeout and no-JUnit execution fails closed
$testRunner->runTest('executeShellCommand enforces timeout and terminates hanging process', function () use ($runner) {
    // Run sleep 3 with a 1 second timeout
    $start = microtime(true);
    $res = $runner->executeShellCommand('sleep 3', $runner->getRootDir(), null, 1);
    $duration = microtime(true) - $start;

    assertTrue($res['timed_out'], 'Process should have timed out');
    assertEquals(124, $res['exit_code'], 'Exit code should be 124 on timeout');
    assertTrue($duration < 2.5, "Process should have been terminated around 1s, took {$duration}s");
    assertTrue(str_contains($res['stderr'], 'exceeded timeout of 1 seconds'));
});

// 14. Finding 6: Partial qualification (static-only / skip-db / allow-dirty / partial batch) never labels full release qualification passed
$testRunner->runTest('Partial batch or skipped checks never label full release qualification passed', function () use ($runner) {
    $tempDir = $runner->createDefaultOutputDir();

    $staticResults = [
        'pint' => ['status' => 'PASSED', 'exit_code' => 0, 'duration' => 1.0, 'log_file' => ''],
    ];
    $totals = [
        'total_batches' => 1,
        'passed_batches' => 1,
        'failed_batches' => 0,
        'total_tests' => 10,
        'total_assertions' => 20,
        'total_failures' => 0,
        'total_errors' => 0,
        'total_skipped' => 0,
        'total_duration' => 5.0,
        'failure_messages' => [],
    ];
    $coverage = [
        'expected_inventory_count' => 435,
        'completed_files_count' => 25,
        'coverage_percentage' => 5.75,
        'full_inventory_completed' => false,
    ];
    $skippedChecks = ['remaining_disjoint_batches (targeted batch 1/18 only)', 'clean_working_tree_verification'];

    // Write report for a partial run
    $runner->writeReportFile(
        $tempDir,
        'abcdef0123456789abcdef0123456789abcdef01',
        $staticResults,
        [],
        true, // passed
        false, // fullReleaseQualified
        'PARTIAL_SCOPE_PASSED',
        $coverage,
        $skippedChecks,
        ['tests/Feature/Phase1/TestA.php'],
        $totals
    );

    $reportPath = $tempDir.'/qualification-summary.json';
    assertTrue(is_file($reportPath), 'Report file should exist');

    $report = json_decode((string) file_get_contents($reportPath), true);
    assertFalse($report['full_release_qualified'], 'full_release_qualified must be false for partial execution');
    assertEquals('PARTIAL_SCOPE_PASSED', $report['verdict']);
    assertEquals(435, $report['coverage']['expected_inventory_count']);
    assertEquals(25, $report['coverage']['completed_files_count']);
    assertEquals(5.75, $report['coverage']['coverage_percentage']);
    assertFalse($report['coverage']['full_inventory_completed']);
    assertEquals(2, count($report['skipped_checks']));

    shell_exec('rm -rf '.escapeshellarg($tempDir));
});

$testRunner->runTest('Positive skipped PHPUnit tests fail full release qualification even when failures and errors are zero', function () use ($runner) {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Tests\Feature\SampleSkippedTest" file="/app/tests/Feature/SampleSkippedTest.php" tests="2" assertions="1" errors="0" failures="0" skipped="1" time="0.2">
    <testcase name="test_passes" class="Tests\Feature\SampleSkippedTest" classname="Tests.Feature.SampleSkippedTest" file="/app/tests/Feature/SampleSkippedTest.php" line="10" assertions="1" time="0.1"/>
    <testcase name="test_skipped" class="Tests\Feature\SampleSkippedTest" classname="Tests.Feature.SampleSkippedTest" file="/app/tests/Feature/SampleSkippedTest.php" line="20" assertions="0" time="0.1">
      <skipped/>
    </testcase>
  </testsuite>
</testsuites>
XML;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_skipped_junit_');
    file_put_contents($tempFile, $xml);
    $stats = $runner->parseJunitReport($tempFile);
    unlink($tempFile);

    assertTrue($stats['valid']);
    assertEquals(1, $stats['skipped'], 'Skipped test count must be preserved');

    $totalsWithSkip = [
        'total_batches' => 1,
        'passed_batches' => 1,
        'failed_batches' => 0,
        'total_tests' => $stats['tests'],
        'total_assertions' => $stats['assertions'],
        'total_failures' => $stats['failures'],
        'total_errors' => $stats['errors'],
        'total_skipped' => $stats['skipped'],
        'total_duration' => 0.2,
        'failure_messages' => [],
    ];
    assertFalse(
        $runner->isFullReleaseQualified(true, $totalsWithSkip, [], null, 1, 1, true),
        'Positive total_skipped must refuse full release qualification'
    );

    $totalsWithoutSkip = $totalsWithSkip;
    $totalsWithoutSkip['total_skipped'] = 0;
    assertTrue(
        $runner->isFullReleaseQualified(true, $totalsWithoutSkip, [], null, 1, 1, true),
        'Clean full-coverage run with total_skipped === 0 should qualify'
    );
});

// 15. CLI end-to-end refusal via process execution
$testRunner->runTest('CLI entrypoint refuses on wrong commit with exit code 2', function () use ($rootDir) {
    $cmd = PHP_BINARY.' '.escapeshellarg($rootDir.'/tools/release/qualify').' --preflight-only --expected-commit=badc0ffee0000000000000000000000000000000 2>&1';
    exec($cmd, $output, $code);
    assertEquals(AccountQualificationRunner::EXIT_REFUSAL_COMMIT_OR_TREE, $code, 'Expected exit code 2');
    assertTrue(str_contains(implode("\n", $output), 'Commit mismatch'));
});

$testRunner->runTest('CLI entrypoint refuses on invalid DB when full run requested without DB slot with exit code 4', function () use ($rootDir) {
    $cmd = 'env -u APP_ENV -u PHASE8_ALLOW_DISPOSABLE_DB -u PHASE8_TEST_DB_HOST -u PHASE8_TEST_DB_PORT -u PHASE8_TEST_DB_USERNAME -u PHASE8_TEST_DB_PASSWORD '.PHP_BINARY.' '.escapeshellarg($rootDir.'/tools/release/qualify').' --allow-dirty 2>&1';
    exec($cmd, $output, $code);
    assertEquals(AccountQualificationRunner::EXIT_REFUSAL_DATABASE, $code, 'Expected exit code 4');
    assertTrue(str_contains(implode("\n", $output), 'REFUSAL: DATABASE'));
});

$testRunner->runTest('CLI entrypoint passes preflight check when commit matches', function () use ($rootDir) {
    $head = trim((string) shell_exec('git rev-parse HEAD'));
    $cmd = PHP_BINARY.' '.escapeshellarg($rootDir.'/tools/release/qualify').' --preflight-only --expected-commit='.escapeshellarg($head).' --allow-dirty 2>&1';
    exec($cmd, $output, $code);
    assertEquals(AccountQualificationRunner::EXIT_SUCCESS, $code, 'Expected exit code 0 on valid preflight: '.implode("\n", $output));
    assertTrue(str_contains(implode("\n", $output), 'Preflight qualification checks: ALL PASSED'));
});

$testRunner->runTest('CLI entrypoint refuses on dirty tracked source with exit code 2', function () use ($rootDir) {
    $targetFile = $rootDir.'/composer.json';
    $original = (string) file_get_contents($targetFile);
    try {
        file_put_contents($targetFile, $original."\n");
        $cmd = PHP_BINARY.' '.escapeshellarg($rootDir.'/tools/release/qualify').' --preflight-only 2>&1';
        exec($cmd, $output, $code);
        assertEquals(AccountQualificationRunner::EXIT_REFUSAL_COMMIT_OR_TREE, $code, 'Expected exit code 2 on dirty tracked tree');
        assertTrue(str_contains(implode("\n", $output), 'Dirty tracked source detected'));
    } finally {
        file_put_contents($targetFile, $original);
        shell_exec('git update-index -q --refresh');
    }
});

$testRunner->runTest('CLI entrypoint refuses on existing non-empty outdir with exit code 5', function () use ($rootDir) {
    $tempDir = sys_get_temp_dir().'/cli_collision_'.bin2hex(random_bytes(4));
    mkdir($tempDir, 0700, true);
    file_put_contents($tempDir.'/stale.log', 'old log');

    $head = trim((string) shell_exec('git rev-parse HEAD'));
    $cmd = PHP_BINARY.' '.escapeshellarg($rootDir.'/tools/release/qualify').' --preflight-only --expected-commit='.escapeshellarg($head).' --allow-dirty --out-dir='.escapeshellarg($tempDir).' 2>&1';
    exec($cmd, $output, $code);
    shell_exec('rm -rf '.escapeshellarg($tempDir));

    assertEquals(AccountQualificationRunner::EXIT_REFUSAL_OUTDIR, $code, 'Expected exit code 5 for outdir collision');
    assertTrue(str_contains(implode("\n", $output), 'REFUSAL: OUTDIR'));
});

$testRunner->runTest('CLI entrypoint --list-batches outputs deterministic disjoint complete batches', function () use ($rootDir) {
    $cmd = PHP_BINARY.' '.escapeshellarg($rootDir.'/tools/release/qualify').' --list-batches --batch-size=25 --allow-dirty 2>&1';
    exec($cmd, $output, $code);
    assertEquals(AccountQualificationRunner::EXIT_SUCCESS, $code, 'Expected exit code 0 on --list-batches');
    $outText = implode("\n", $output);
    assertTrue(str_contains($outText, 'Disjoint Complete Test Batches'));
    assertTrue(str_contains($outText, 'Batch 01:'));
});

echo "\n=======================================================\n";
echo "Refusal & Batching Test Results: {$testRunner->getPassCount()} PASSED, {$testRunner->getFailCount()} FAILED\n";
echo "=======================================================\n";

exit($testRunner->getFailCount() > 0 ? 1 : 0);
