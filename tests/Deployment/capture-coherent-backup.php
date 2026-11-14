#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Small Trader Accounting — Coherent Backup Capture Standalone Test Suite
 *
 * Standalone PHP 8.4 test harness for bin/capture-coherent-backup.php.
 * Operates without loading Laravel, Artisan, database, or external network.
 * Verifies safe dry-run default, refusal of public destination, symlink refusal,
 * secret redaction, 0700/0600 permissions, .my.cnf cleanup, and inert tar validation.
 */

namespace SmallTrader\Tests\Deployment;

use RuntimeException;

final class CaptureCoherentBackupTestRunner
{
    private string $tempDir;

    private string $phpBinary;

    private string $captureScript;

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

    public function testDefaultModeIsDryRunPreflight(): void
    {
        $fixture = $this->createFixtureEnvironment('default_dryrun');

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(0, $exitCode, 'Default mode should exit 0 on valid preflight');
        $raw = implode("\n", $output);
        $json = json_decode($raw, true);

        $this->assertIsArray($json, 'Output must be valid JSON');
        $this->assertSame('PREFLIGHT_PASSED', $json['status'] ?? null);
        $this->assertSame('DRY_RUN', $json['mode'] ?? null);
        $this->assertSame(false, $json['execute_authorized'] ?? null);

        // Verify NO files or subdirectories were created in target dir
        $files = array_diff(scandir($fixture['targetDir']) ?: [], ['.', '..']);
        $this->assertSame([], $files, 'Dry run must not create any files in target directory');
    }

    public function testExplicitDryRunFlag(): void
    {
        $fixture = $this->createFixtureEnvironment('explicit_dryrun');

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

        $this->assertSame(0, $exitCode, '--dry-run flag should exit 0');
        $raw = implode("\n", $output);
        $json = json_decode($raw, true);
        $this->assertSame('DRY_RUN', $json['mode'] ?? null);
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
        $raw = implode("\n", $output);
        $json = json_decode($raw, true);
        $this->assertSame('FAIL', $json['status'] ?? null);
        $this->assertSame('TARGET_DIR_INSIDE_PUBLIC_ROOT', $json['error'] ?? null);
    }

    public function testRefusesSymlinksInPaths(): void
    {
        $fixture = $this->createFixtureEnvironment('refuse_symlinks');
        $realApp = $fixture['appDir'];
        $symlinkApp = $this->tempDir.'/app_symlink_'.bin2hex(random_bytes(4));
        symlink($realApp, $symlinkApp);

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($symlinkApp),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode, 'Should fail when path is a symlink');
        $raw = implode("\n", $output);
        $json = json_decode($raw, true);
        $this->assertSame('FAIL', $json['status'] ?? null);
        $this->assertSame('SYMLINK_REFUSED_APP_DIR', $json['error'] ?? null);
    }

    public function testSecretRedactionInDryRunAndOutput(): void
    {
        $fixture = $this->createFixtureEnvironment('secret_redaction');
        $secretPassword = 'super-secret-password-xyz-12345';
        $secretKey = 'base64:very-secret-application-key-999';

        file_put_contents($fixture['appDir'].'/.env', "DB_DATABASE=test_db\nDB_USERNAME=test_user\nDB_PASSWORD={$secretPassword}\nAPP_KEY={$secretKey}\n");

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(0, $exitCode);
        $raw = implode("\n", $output);

        $this->assertFalse(str_contains($raw, $secretPassword), 'DB password must NEVER appear in output');
        $this->assertFalse(str_contains($raw, $secretKey), 'APP_KEY secret value must NEVER appear in output');

        $json = json_decode($raw, true);
        $this->assertSame('[REDACTED]', $json['database']['password'] ?? null);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/D', (string) ($json['app_key_ref'] ?? ''));
    }

    public function testMissingEnvOrIncompleteDbConfigFailsClosed(): void
    {
        $fixture = $this->createFixtureEnvironment('missing_env');
        unlink($fixture['appDir'].'/.env');

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --app-dir=%s --public-dir=%s --target-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->captureScript),
            escapeshellarg($fixture['appDir']),
            escapeshellarg($fixture['publicDir']),
            escapeshellarg($fixture['targetDir'])
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('ENV_FILE_NOT_FOUND_OR_UNREADABLE', $json['error'] ?? null);
    }

    public function testExecuteCleansUpMyCnfEvenOnDumpFailure(): void
    {
        $fixture = $this->createFixtureEnvironment('dump_failure');

        // Create a failing mock mysqldump script
        $failingDump = $this->tempDir.'/mock_failing_dump.sh';
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

        $this->assertSame(1, $exitCode, 'Execution should fail when mysqldump exits 1');

        // Verify .my.cnf was wiped and is nowhere in targetDir
        $found = shell_exec(sprintf('find %s -name ".my.cnf"', escapeshellarg($fixture['targetDir'])));
        $this->assertSame('', trim((string) $found), '.my.cnf must be cleaned up on failure');
    }

    public function testSuccessfulExecuteCreatesCoherentBundleAndPermissions(): void
    {
        $fixture = $this->createFixtureEnvironment('successful_exec');

        // Create a successful mock mysqldump script that writes dummy SQL
        $mockDump = $this->tempDir.'/mock_success_dump.sh';
        file_put_contents($mockDump, "#!/bin/sh\necho \"-- COHERENT SQL DUMP TEST\"\nexit 0\n");
        chmod($mockDump, 0755);

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

        $raw = implode("\n", $output);
        $this->assertSame(0, $exitCode, "Capture execute failed: {$raw}");
        $json = json_decode($raw, true);

        $this->assertSame('SUCCESS', $json['status'] ?? null);
        $this->assertSame('EXECUTE', $json['mode'] ?? null);
        $this->assertSame('0700_DIR_0600_FILES', $json['permissions'] ?? null);

        $pointDir = (string) ($json['point_dir'] ?? '');
        $this->assertTrue(is_dir($pointDir), 'Point dir must exist');

        // Verify POSIX mode 0700 on point directory
        $dirPerms = fileperms($pointDir) & 0777;
        $this->assertSame(0700, $dirPerms, 'Point directory must have 0700 permissions');

        // Verify 0600 on created files
        $sqlFile = $pointDir.'/database.sql';
        $this->assertTrue(is_file($sqlFile));
        $this->assertSame(0600, fileperms($sqlFile) & 0777, 'database.sql must have 0600 permissions');

        $manifestFile = $pointDir.'/manifest.json';
        $this->assertTrue(is_file($manifestFile));
        $this->assertSame(0600, fileperms($manifestFile) & 0777, 'manifest.json must have 0600 permissions');

        $shaFile = $pointDir.'/manifest.sha256';
        $this->assertTrue(is_file($shaFile));
        $this->assertSame(0600, fileperms($shaFile) & 0777, 'manifest.sha256 must have 0600 permissions');

        $bundleFile = $pointDir.'/complete_recovery_bundle.tar';
        $this->assertTrue(is_file($bundleFile));
        $this->assertSame(0600, fileperms($bundleFile) & 0777, 'complete_recovery_bundle.tar must have 0600 permissions');

        // Verify manifest checksums match files
        $verifyOut = [];
        $verifyExit = 0;
        exec(sprintf('cd %s && sha256sum -c manifest.sha256', escapeshellarg($pointDir)), $verifyOut, $verifyExit);
        $this->assertSame(0, $verifyExit, 'sha256sum -c manifest.sha256 must pass cleanly');
    }

    public function testInertTarValidation(): void
    {
        $fixture = $this->createFixtureEnvironment('inert_tar');

        $mockDump = $this->tempDir.'/mock_dump_tar.sh';
        file_put_contents($mockDump, "#!/bin/sh\necho \"-- TEST SQL\"\nexit 0\n");
        chmod($mockDump, 0755);

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
    }

    /**
     * @return array{appDir: string, publicDir: string, targetDir: string}
     */
    private function createFixtureEnvironment(string $prefix): array
    {
        $id = $prefix.'_'.bin2hex(random_bytes(4));
        $appDir = $this->tempDir.'/'.$id.'_app';
        $publicDir = $this->tempDir.'/'.$id.'_public';
        $targetDir = $this->tempDir.'/'.$id.'_backups';

        mkdir($appDir.'/storage/framework', 0700, true);
        mkdir($appDir.'/storage/app', 0700, true);
        mkdir($publicDir.'/build', 0700, true);
        mkdir($targetDir, 0700, true);

        file_put_contents($appDir.'/.env', "DB_DATABASE=test_db\nDB_USERNAME=test_user\nDB_PASSWORD=test_pass\nAPP_KEY=base64:1234567890123456789012345678901234567890123=\n");
        file_put_contents($publicDir.'/.htaccess', "# Test htaccess\n");
        file_put_contents($publicDir.'/build/manifest.json', "{\"test\": true}\n");

        return [
            'appDir' => $appDir,
            'publicDir' => $publicDir,
            'targetDir' => $targetDir,
        ];
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

    private function assertIsArray(mixed $value, string $message = ''): void
    {
        $this->assertionsRun++;
        if (! is_array($value)) {
            throw new RuntimeException($message ?: 'Expected array');
        }
    }

    private function assertNotEmpty(mixed $value, string $message = ''): void
    {
        $this->assertionsRun++;
        if (empty($value)) {
            throw new RuntimeException($message ?: 'Expected non-empty value');
        }
    }

    private function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        $this->assertionsRun++;
        if (! preg_match($pattern, $string)) {
            throw new RuntimeException($message ?: "String [{$string}] does not match pattern [{$pattern}]");
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    (new CaptureCoherentBackupTestRunner)->runAll();
}
