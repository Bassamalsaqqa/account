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

echo "=== Running Account Qualification Runner Refusal & Inventory Tests ===\n\n";

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

// 7. JUnit XML Parsing & Diagnostics Preservation
$testRunner->runTest('Correctly parses JUnit XML and preserves failures without filtering to green', function () use ($runner) {
    $sampleXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Feature Tests" tests="10" assertions="25" errors="1" failures="2" skipped="1" time="3.14">
    <testcase name="test_passing" class="Tests\Feature\ExampleTest" assertions="5" time="0.1"/>
    <testcase name="test_failing" class="Tests\Feature\ExampleTest" assertions="3" time="0.2">
      <failure message="Failed asserting that 1 is 2.">Stack trace here...</failure>
    </testcase>
    <testcase name="test_error" class="Tests\Feature\ExampleTest" assertions="0" time="0.05">
      <error message="RuntimeException: Database deadlocked">Exception trace...</error>
    </testcase>
  </testsuite>
</testsuites>
XML;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_junit_');
    file_put_contents($tempFile, $sampleXml);

    $stats = $runner->parseJunitReport($tempFile);
    unlink($tempFile);

    assertEquals(10, $stats['tests']);
    assertEquals(25, $stats['assertions']);
    assertEquals(2, $stats['failures']);
    assertEquals(1, $stats['errors']);
    assertEquals(1, $stats['skipped']);
    assertEquals(2, count($stats['failure_messages']));
    assertTrue(str_contains($stats['failure_messages'][0], 'Failed asserting that 1 is 2.'));
    assertTrue(str_contains($stats['failure_messages'][1], 'RuntimeException: Database deadlocked'));
});

// 8. Result Aggregation across multiple batches
$testRunner->runTest('Aggregates totals accurately across multiple batches and preserves failures', function () use ($runner) {
    $batch1 = [
        'status' => 'PASSED',
        'tests' => 50,
        'assertions' => 120,
        'failures' => 0,
        'errors' => 0,
        'skipped' => 2,
        'duration' => 12.5,
        'failure_messages' => [],
    ];
    $batch2 = [
        'status' => 'FAILED',
        'tests' => 45,
        'assertions' => 110,
        'failures' => 1,
        'errors' => 1,
        'skipped' => 0,
        'duration' => 15.0,
        'failure_messages' => ['Tests\Feature\Phase1\Test::test_fail: Broken balance'],
    ];

    $totals = $runner->aggregateResults([$batch1, $batch2]);
    assertEquals(2, $totals['total_batches']);
    assertEquals(1, $totals['passed_batches']);
    assertEquals(1, $totals['failed_batches']);
    assertEquals(95, $totals['total_tests']);
    assertEquals(230, $totals['total_assertions']);
    assertEquals(1, $totals['total_failures']);
    assertEquals(1, $totals['total_errors']);
    assertEquals(2, $totals['total_skipped']);
    assertEquals(27.5, $totals['total_duration']);
    assertEquals(1, count($totals['failure_messages']));
});

// 9. CLI end-to-end refusal via process execution
$testRunner->runTest('CLI entrypoint refuses on wrong commit with exit code 2', function () use ($rootDir) {
    $cmd = PHP_BINARY.' '.escapeshellarg($rootDir.'/tools/release/qualify').' --preflight-only --expected-commit=badc0ffee0000000000000000000000000000000 2>&1';
    exec($cmd, $output, $code);
    assertEquals(AccountQualificationRunner::EXIT_REFUSAL_COMMIT_OR_TREE, $code, 'Expected exit code 2');
    assertTrue(str_contains(implode("\n", $output), 'Commit mismatch'));
});

$testRunner->runTest('CLI entrypoint refuses on invalid DB when full run requested without DB slot with exit code 4', function () use ($rootDir) {
    // Unset all test DB variables
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
    // Temporarily introduce a dirty tracked modification
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
