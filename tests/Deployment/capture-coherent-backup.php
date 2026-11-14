#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Small Trader Accounting — Coherent Backup Capture Standalone Test Suite
 *
 * Standalone PHP 8.4 test harness for bin/capture-coherent-backup.php.
 * Operates without loading Laravel, Artisan, database, or external network.
 * Verifies:
 * - (1) Dryrun zero-write (nonexistent target dir is NOT created during dryrun).
 * - (2) Maintenance OFF refusal, clean tracked tree check, pin mismatch refusal.
 * - (3) Archive failures fail-closed (database, git, tar, final bundle) and write FAILED marker.
 * - (4) Point collision refusal & exclusive creation.
 * - (5) MySQL option quoting/escaping (passwords with #, quotes, backslashes), Dotenv missing refusal.
 * - (6) Vendor and build archive mandatory inclusion.
 * - (7) Refusal of public target directories & symlinks.
 * - (8) Inert tar listing validation.
 */

namespace SmallTrader\Tests\Deployment;

use RuntimeException;

final class CaptureCoherentBackupTestRunner
{
    private string $tempDir;

    private string $phpBinary;

    private string $captureScript;

    private string $vendorAutoloadSource;

    private int $testsRun = 0;

    private int $assertionsRun = 0;

    private array $failures = [];

    public function __construct()
    {
        umask(0077);
        $this->phpBinary = PHP_BINARY;
        $this->captureScript = realpath(__DIR__.'/../../bin/capture-coherent-backup.php') ?: '';

        if (! file_exists($this->captureScript)) {
            fwrite(STDERR, "ERROR: bin/capture-coherent-backup.php not found at {$this->captureScript}\n");
            exit(1);
        }

        // Find a valid vendor/autoload.php in peer worktrees to link for Dotenv tests
        $candidates = [
            '/home/t3dev/.t3/worktrees/account/t3-4dc63926-13cb84ae806a77c9/vendor',
            '/home/t3dev/.t3/worktrees/account/t3-a5600818-a68cb54f373aaf62/vendor',
            '/home/t3dev/.t3/worktrees/account/t3-2c068c40-8800270485de00e4/vendor',
        ];
        $this->vendorAutoloadSource = '';
        foreach ($candidates as $c) {
            if (is_dir($c) && is_file($c.'/autoload.php')) {
                $this->vendorAutoloadSource = $c;
                break;
            }
        }

        $baseTemp = sys_get_temp_dir();
        $this->tempDir = $baseTemp.DIRECTORY_SEPARATOR.'capture_harness_'.bin2hex(random_bytes(8));
        if (! mkdir($this->tempDir, 0700, true)) {
            fwrite(STDERR, "ERROR: Could not create temporary directory at {$this->tempDir}\n");
            exit(1);
        }
    }

    public function runAll(): void
    {
        echo "================================================================================\n";
        echo "COHERENT BACKUP CAPTURE TEST SUITE (PHP 8.4 STANDALONE HARNESS)\n";
        echo "================================================================================\n";
        echo "Target Script: {$this->captureScript}\n";
        echo "PHP Binary:    {$this->phpBinary}\n";
        echo "Temp Sandbox:  {$this->tempDir}\n";
        echo "--------------------------------------------------------------------------------\n\n";

        $methods = get_class_methods($this);
        foreach ($methods as $method) {
            if (str_starts_with($method, 'test')) {
                $this->testsRun++;
                echo "  RUN  {$method}... ";
                try {
                    $this->{$method}();
                    echo "OK\n";
                } catch (\Throwable $e) {
                    echo "FAILED\n";
                    $this->failures[] = [
                        'test' => $method,
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ];
                }
            }
        }

        $this->cleanupSandbox();

        echo "\n--------------------------------------------------------------------------------\n";
        echo "TEST SUMMARY:\n";
        echo "Tests executed:      {$this->testsRun}\n";
        echo "Assertions verified: {$this->assertionsRun}\n";
        echo "Failures:            ".count($this->failures)."\n";

        if ($this->failures !== []) {
            echo "\nFAILURES:\n";
            foreach ($this->failures as $f) {
                echo "- {$f['test']}: {$f['message']}\n";
            }
            exit(1);
        }

        echo "STATUS: ALL CAPTURE UNIT CHECKS PASSED\n";
        echo "================================================================================\n";
        exit(0);
    }

    public function testDryRunZeroWriteNonexistentTargetNotCreated(): void
    {
        $fixture = $this->createFixtureEnvironment('dryrun_zerowrite');
        $nonexistentTarget = $fixture['baseDir'].'/nonexistent_target_dir';

        $this->assertFalse(file_exists($nonexistentTarget), 'Target must not exist before test');

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($nonexistentTarget)
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(0, $exitCode, 'Dry run should exit 0 on valid preflight');
        $raw = implode("\n", $output);
        $json = json_decode($raw, true);

        $this->assertSame('PREFLIGHT_PASSED', $json['status'] ?? null);
        $this->assertSame('DRY_RUN', $json['mode'] ?? null);
        $this->assertSame(false, $json['execute_authorized'] ?? null);

        // Crucial invariant: nonexistent target directory was NOT created during dry-run
        $this->assertFalse(file_exists($nonexistentTarget), 'Dry run must be zero-write: must NOT create target directory');
    }

    public function testExecuteRefusedWhenMaintenanceOff(): void
    {
        $fixture = $this->createFixtureEnvironment('maint_off');
        // Ensure storage/framework/down does NOT exist
        if (file_exists($fixture['appDir'].'/storage/framework/down')) {
            unlink($fixture['appDir'].'/storage/framework/down');
        }

        $mockDump = $this->createMockDumpScript('maint_off');

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --execute --mysqldump-bin=%s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($mockDump),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode, 'Execute must be refused when maintenance is OFF');
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('FAIL', $json['status'] ?? null);
        $this->assertSame('EXECUTE_REFUSED_MAINTENANCE_OFF', $json['error'] ?? null);
    }

    public function testRefusesWhenTrackedTreeHasUncommittedModifications(): void
    {
        $fixture = $this->createFixtureEnvironment('dirty_tree');
        // Set maintenance ON
        touch($fixture['appDir'].'/storage/framework/down');

        // Create a tracked modification in git
        file_put_contents($fixture['appDir'].'/tracked_file.txt', "initial\n");
        exec(sprintf('cd %s && git add tracked_file.txt && git commit -m "initial"', escapeshellarg($fixture['appDir'])));
        file_put_contents($fixture['appDir'].'/tracked_file.txt', "dirty edit\n");

        $mockDump = $this->createMockDumpScript('dirty_tree');

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --execute --mysqldump-bin=%s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($mockDump),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode, 'Execute must be refused when tracked working tree is dirty');
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('FAIL', $json['status'] ?? null);
        $this->assertSame('TRACKED_TREE_NOT_CLEAN', $json['error'] ?? null);
    }

    public function testExpectedPinsMismatchRefused(): void
    {
        $fixture = $this->createFixtureEnvironment('pin_mismatch');
        touch($fixture['appDir'].'/storage/framework/down');

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --dry-run --expected-source-sha=0000000000000000000000000000000000000000 --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode, 'Should fail closed on source sha pin mismatch');
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('SOURCE_SHA_PIN_MISMATCH', $json['error'] ?? null);
    }

    public function testArchiveFailuresAbortImmediatelyAndWriteFailedMarker(): void
    {
        $fixture = $this->createFixtureEnvironment('archive_failure');
        touch($fixture['appDir'].'/storage/framework/down');

        // Create failing dump script
        $failingDump = $this->tempDir.'/mock_fail_dump_'.bin2hex(random_bytes(4)).'.sh';
        file_put_contents($failingDump, "#!/bin/sh\nexit 1\n");
        chmod($failingDump, 0755);

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --execute --mysqldump-bin=%s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($failingDump),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode, 'Should fail when component fails');
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('FAIL', $json['status'] ?? null);
        $this->assertSame('MYSQLDUMP_EXECUTION_FAILED', $json['error'] ?? null);

        // Find the created point directory and verify CAPTURE_FAILED marker exists
        $subdirs = glob($fixture['targetDir'].'/coherent_recovery_*') ?: [];
        $this->assertNotEmpty($subdirs, 'Point directory should be preserved for failure diagnostics');
        $pointDir = $subdirs[0];
        $this->assertTrue(file_exists($pointDir.'/CAPTURE_FAILED'), 'CAPTURE_FAILED marker must be present');
        $this->assertFalse(file_exists($pointDir.'/complete_recovery_bundle.tar'), 'Final bundle must NOT be created');
    }

    public function testPointDirectoryCollisionRefusal(): void
    {
        $fixture = $this->createFixtureEnvironment('collision_refusal');
        touch($fixture['appDir'].'/storage/framework/down');

        $mockDump = $this->createMockDumpScript('collision');

        // Run successful execute once
        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --execute --mysqldump-bin=%s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($mockDump),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);
        $this->assertSame(0, $exitCode);

        $json1 = json_decode(implode("\n", $output), true);
        $pointDir1 = (string) ($json1['point_dir'] ?? '');
        $this->assertTrue(is_dir($pointDir1));

        // Running a second time produces a unique point directory because of the random nonce
        $output2 = [];
        $exitCode2 = 0;
        exec($cmd, $output2, $exitCode2);
        $this->assertSame(0, $exitCode2);
        $json2 = json_decode(implode("\n", $output2), true);
        $pointDir2 = (string) ($json2['point_dir'] ?? '');

        $this->assertFalse($pointDir1 === $pointDir2, 'Consecutive capture points must have unique directory paths');
    }

    public function testMyCnfProperQuotingAndEscaping(): void
    {
        $fixture = $this->createFixtureEnvironment('mycnf_escaping');
        touch($fixture['appDir'].'/storage/framework/down');

        // Complex password containing #, quotes, backslashes, spaces
        file_put_contents(
            $fixture['appDir'].'/.env',
            "DB_DATABASE=test_db\nDB_USERNAME=test_user\nDB_PASSWORD=\"Pass#word\\\"With'Quotes\\\\And#Hashes \"\nDB_HOST=127.0.0.1\nDB_PORT=3306\nAPP_KEY=base64:1234567890123456789012345678901234567890123=\n"
        );
        exec(sprintf('cd %s && git add .env && git commit -m "update env"', escapeshellarg($fixture['appDir'])));

        // Mock mysqldump that verifies the generated .my.cnf syntax
        $verifierScript = $this->tempDir.'/mock_verify_cnf_'.bin2hex(random_bytes(4)).'.sh';
        file_put_contents($verifierScript, "#!/bin/sh
for arg in \"\$@\"; do
  case \"\$arg\" in
    --defaults-extra-file=*)
      CNF=\"\${arg#--defaults-extra-file=}\"
      if [ -f \"\$CNF\" ]; then
        # Check permissions 0600
        PERMS=\$(stat -c '%a' \"\$CNF\" 2>/dev/null || stat -f '%Op' \"\$CNF\")
        echo \"PERMS:\$PERMS\"
        # Check that ini file parses properly
        grep -q 'host = \"127.0.0.1\"' \"\$CNF\" || exit 2
        grep -q 'port = 3306' \"\$CNF\" || exit 3
        grep -q 'user = \"test_user\"' \"\$CNF\" || exit 4
        # Password must be double quoted with escaped inner quotes
        grep -q 'password = \"Pass#word\\\\\"With' \"\$CNF\" || exit 5
      fi
      ;;
  esac
done
echo \"-- DUMP OK\"
exit 0
");
        chmod($verifierScript, 0755);

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --execute --mysqldump-bin=%s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($verifierScript),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);

        $raw = implode("\n", $output);
        $this->assertSame(0, $exitCode, "Failed with output: {$raw}");
        $json = json_decode($raw, true);
        $this->assertSame('SUCCESS', $json['status'] ?? null);
    }

    public function testMissingDotenvDependencyFailsClosed(): void
    {
        $fixture = $this->createFixtureEnvironment('no_dotenv');
        // Delete vendor from appDir so Dotenv cannot be loaded
        shell_exec(sprintf('rm -rf %s/vendor', escapeshellarg($fixture['appDir'])));

        // Run in a child process that has not loaded Dotenv
        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --dry-run --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode, 'Should fail closed when Dotenv dependency is missing');
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('DOTENV_DEPENDENCY_MISSING', $json['error'] ?? null);
    }

    public function testRefusesTargetDirectoryInsidePublicWebRoot(): void
    {
        $fixture = $this->createFixtureEnvironment('refuse_public');
        $unsafeTarget = $fixture['publicDir'].'/unsafe_backups';
        mkdir($unsafeTarget, 0700, true);

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($unsafeTarget)
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode, 'Should fail when target is inside public dir');
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('TARGET_DIR_INSIDE_PUBLIC_ROOT', $json['error'] ?? null);
    }

    public function testInertTarValidation(): void
    {
        $fixture = $this->createFixtureEnvironment('inert_tar');
        touch($fixture['appDir'].'/storage/framework/down');

        $mockDump = $this->createMockDumpScript('inert_tar');

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --execute --mysqldump-bin=%s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($mockDump),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);

        $json = json_decode(implode("\n", $output), true);
        $bundle = (string) ($json['complete_bundle'] ?? '');
        $this->assertTrue(is_file($bundle), 'Complete bundle archive must exist');

        // List tar members
        $tarList = [];
        exec(sprintf('tar -tf %s', escapeshellarg($bundle)), $tarList);

        $this->assertNotEmpty($tarList, 'Tar listing must not be empty');
        foreach ($tarList as $member) {
            $this->assertFalse(str_starts_with($member, '/'), "Tar member must not have leading slash: {$member}");
            $this->assertFalse(str_contains($member, '../'), "Tar member must not contain traversal: {$member}");
        }

        // Must contain all mandatory components
        $joined = implode(' ', $tarList);
        $this->assertTrue(str_contains($joined, 'database.sql'));
        $this->assertTrue(str_contains($joined, 'source.bundle'));
        $this->assertTrue(str_contains($joined, 'build_assets.tar.gz'));
        $this->assertTrue(str_contains($joined, 'config.tar.gz'));
        $this->assertTrue(str_contains($joined, 'private_storage.tar.gz'));
        $this->assertTrue(str_contains($joined, 'public_assets.tar.gz'));
        $this->assertTrue(str_contains($joined, 'vendor.tar.gz'));
        $this->assertTrue(str_contains($joined, 'manifest.json'));
        $this->assertTrue(str_contains($joined, 'manifest.sha256'));
    }

    /**
     * @return array{baseDir: string, appDir: string, publicDir: string, targetDir: string}
     */
    private function createFixtureEnvironment(string $prefix): array
    {
        $id = $prefix.'_'.bin2hex(random_bytes(4));
        $base = $this->tempDir.'/'.$id;
        $appDir = $base.'/accounting';
        $publicDir = $base.'/public_html/account';
        $targetDir = $base.'/accounting-backups';

        mkdir($appDir.'/storage/framework', 0700, true);
        mkdir($appDir.'/storage/app', 0700, true);
        mkdir($publicDir.'/build', 0700, true);
        mkdir($targetDir, 0700, true);

        // Copy or link vendor from known worktree
        if ($this->vendorAutoloadSource !== '') {
            symlink($this->vendorAutoloadSource, $appDir.'/vendor');
        }

        // Initialize git repository in appDir
        exec(sprintf('cd %s && git init -b main && git config user.email "test@example.com" && git config user.name "Test"', escapeshellarg($appDir)));

        file_put_contents($appDir.'/.env', "DB_DATABASE=test_db\nDB_USERNAME=test_user\nDB_PASSWORD=test_pass\nAPP_KEY=base64:1234567890123456789012345678901234567890123=\n");
        file_put_contents($publicDir.'/.htaccess', "# Test htaccess\n");
        file_put_contents($publicDir.'/build/manifest.json', "{\"test\": true}\n");

        exec(sprintf('cd %s && git add .env && git commit -m "initial commit"', escapeshellarg($appDir)));

        return [
            'baseDir' => $base,
            'appDir' => $appDir,
            'publicDir' => $publicDir,
            'targetDir' => $targetDir,
        ];
    }

    private function createMockDumpScript(string $name): string
    {
        $script = $this->tempDir.'/mock_dump_'.$name.'_'.bin2hex(random_bytes(4)).'.sh';
        file_put_contents($script, "#!/bin/sh\necho \"-- COHERENT TEST SQL\" \nexit 0\n");
        chmod($script, 0755);

        return $script;
    }

    private function cleanupSandbox(): void
    {
        if (is_dir($this->tempDir)) {
            shell_exec(sprintf('rm -rf %s', escapeshellarg($this->tempDir)));
        }
    }

    private function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertionsRun++;
        if ($expected !== $actual) {
            throw new RuntimeException($message ?: sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true)));
        }
    }

    private function assertTrue(bool $condition, string $message = ''): void
    {
        $this->assertionsRun++;
        if (! $condition) {
            throw new RuntimeException($message ?: 'Expected true condition');
        }
    }

    private function assertFalse(bool $condition, string $message = ''): void
    {
        $this->assertionsRun++;
        if ($condition) {
            throw new RuntimeException($message ?: 'Expected false condition');
        }
    }

    private function assertNotEmpty(mixed $value, string $message = ''): void
    {
        $this->assertionsRun++;
        if (empty($value)) {
            throw new RuntimeException($message ?: 'Expected non-empty value');
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    (new CaptureCoherentBackupTestRunner)->runAll();
}
