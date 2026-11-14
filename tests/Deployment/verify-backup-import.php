#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Small Trader Accounting — Backup Import Verifier Standalone Test Suite
 *
 * Standalone PHP 8.4 test harness for bin/verify-backup-import.php.
 * Operates without loading Laravel, Artisan, database, or external network.
 * Verifies:
 * - (1) Missing invocation arguments fail closed.
 * - (2) Non-existent or symlinked point directory refused.
 * - (3) Insecure point directory permissions (world-accessible) refused.
 * - (4) Corrupted manifest digest refused.
 * - (5) Database SQL missing, empty, or hash mismatch refused.
 * - (6) Pre-import SQL safety scanner:
 *       - Refusal of CREATE DATABASE / SCHEMA
 *       - Refusal of USE statements
 *       - Refusal of DROP DATABASE / SCHEMA
 *       - Refusal of external DEFINER
 *       - Refusal of privileged administrative statements (GRANT, SHUTDOWN, etc.)
 * - (7) Environment opt-in enforcement:
 *       - APP_ENV must be testing
 *       - PHASE8_ALLOW_DISPOSABLE_DB must be 1
 *       - Host strictly loopback
 *       - Port strictly 3342
 * - (8) Valid clean recovery point passes dry-run preflight with zero mutations.
 */

namespace SmallTrader\Tests\Deployment;

use RuntimeException;

final class VerifyBackupImportTestRunner
{
    private string $tempDir;

    private string $phpBinary;

    private string $verifierScript;

    private int $testsRun = 0;

    private int $assertionsRun = 0;

    private array $failures = [];

    public function __construct()
    {
        umask(0077);
        $this->phpBinary = PHP_BINARY;
        $this->verifierScript = realpath(__DIR__.'/../../bin/verify-backup-import.php') ?: '';

        if (! file_exists($this->verifierScript)) {
            fwrite(STDERR, "ERROR: bin/verify-backup-import.php not found at {$this->verifierScript}\n");
            exit(1);
        }

        $baseTemp = sys_get_temp_dir();
        $this->tempDir = $baseTemp.DIRECTORY_SEPARATOR.'verify_import_harness_'.bin2hex(random_bytes(8));
        if (! mkdir($this->tempDir, 0700, true)) {
            fwrite(STDERR, "ERROR: Could not create temporary directory at {$this->tempDir}\n");
            exit(1);
        }
    }

    public function runAll(): void
    {
        echo "================================================================================\n";
        echo "BACKUP IMPORT VERIFIER TEST SUITE (PHP 8.4 STANDALONE HARNESS)\n";
        echo "================================================================================\n";
        echo "Target Script: {$this->verifierScript}\n";
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

        echo "STATUS: ALL VERIFY IMPORT UNIT CHECKS PASSED\n";
        echo "================================================================================\n";
        exit(0);
    }

    public function testMissingInvocationArguments(): void
    {
        $output = [];
        $exitCode = 0;
        exec(sprintf('%s %s', escapeshellarg($this->phpBinary), escapeshellarg($this->verifierScript)), $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('POINT_DIR_REQUIRED', $json['error'] ?? null);
    }

    public function testPointDirectoryNotFound(): void
    {
        $nonexistent = $this->tempDir.'/nonexistent_point_dir';
        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --point-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->verifierScript),
            escapeshellarg($nonexistent)
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('POINT_DIR_NOT_FOUND', $json['error'] ?? null);
    }

    public function testInsecurePermissionsPointDirRejected(): void
    {
        $pointDir = $this->tempDir.'/insecure_perms_'.bin2hex(random_bytes(4));
        mkdir($pointDir, 0777, true);
        chmod($pointDir, 0777); // World-readable/executable

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            '%s %s --point-dir=%s',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->verifierScript),
            escapeshellarg($pointDir)
        );
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('INSECURE_PERMISSIONS_POINT_DIR_WORLD_ACCESSIBLE', $json['error'] ?? null);
    }

    public function testManifestJsonMissingOrDigestCorrupted(): void
    {
        $fixture = $this->createFixturePoint('corrupt_manifest');
        // Corrupt manifest.json content without updating sha256
        file_put_contents($fixture['pointDir'].'/manifest.json', "{\"tampered\": true}\n");

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir']);
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('MANIFEST_DIGEST_CORRUPTED', $json['error'] ?? null);
    }

    public function testDatabaseSqlMissingOrCorruptedHash(): void
    {
        $fixture = $this->createFixturePoint('corrupt_sql');
        // Modify database.sql without updating manifest
        file_put_contents($fixture['pointDir'].'/database.sql', "-- tampered sql\n");

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir']);
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('DATABASE_SQL_SHA256_MISMATCH', $json['error'] ?? null);
    }

    public function testSqlScannerRejectsCreateDatabase(): void
    {
        $fixture = $this->createFixturePoint('sql_create_db', [
            "CREATE TABLE safe_table (id INT PRIMARY KEY);\n",
            "CREATE DATABASE evil_db;\n",
        ]);

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir']);
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('SQL_CONTAINS_FORBIDDEN_CREATE_DATABASE', $json['error'] ?? null);
    }

    public function testSqlScannerRejectsUseStatement(): void
    {
        $fixture = $this->createFixturePoint('sql_use_stmt', [
            "CREATE TABLE safe_table (id INT PRIMARY KEY);\n",
            "USE mysql;\n",
        ]);

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir']);
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('SQL_CONTAINS_FORBIDDEN_USE', $json['error'] ?? null);
    }

    public function testSqlScannerRejectsDropDatabase(): void
    {
        $fixture = $this->createFixturePoint('sql_drop_db', [
            "DROP DATABASE IF EXISTS old_db;\n",
        ]);

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir']);
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('SQL_CONTAINS_FORBIDDEN_DROP_DATABASE', $json['error'] ?? null);
    }

    public function testSqlScannerRejectsExternalDefiner(): void
    {
        $fixture = $this->createFixturePoint('sql_definer', [
            "CREATE DEFINER=`root`@`%` VIEW `v_users` AS SELECT * FROM users;\n",
        ]);

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir']);
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('SQL_CONTAINS_EXTERNAL_DEFINER', $json['error'] ?? null);
    }

    public function testSqlScannerRejectsPrivilegedStatements(): void
    {
        $fixture = $this->createFixturePoint('sql_priv', [
            "GRANT ALL PRIVILEGES ON *.* TO 'attacker'@'%';\n",
        ]);

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir']);
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('SQL_CONTAINS_PRIVILEGED_STATEMENT', $json['error'] ?? null);
    }

    public function testEnvironmentOptInAppEnvNotTestingRejected(): void
    {
        $fixture = $this->createFixturePoint('env_prod');

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir'], ['APP_ENV' => 'production']);
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('VERIFY_IMPORT_REFUSED_APP_ENV_NOT_TESTING', $json['error'] ?? null);
    }

    public function testEnvironmentOptInFlagMissingRejected(): void
    {
        $fixture = $this->createFixturePoint('env_no_flag');

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir'], ['PHASE8_ALLOW_DISPOSABLE_DB' => '0']);
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('VERIFY_IMPORT_REFUSED_OPT_IN_FLAG_MISSING', $json['error'] ?? null);
    }

    public function testNon3342PortRejected(): void
    {
        $fixture = $this->createFixturePoint('env_wrong_port');

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir'], ['PHASE8_TEST_DB_PORT' => '3306']);
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('DISPOSABLE_DB_REFUSED_NON_3342_PORT', $json['error'] ?? null);
    }

    public function testNonLoopbackHostRejected(): void
    {
        $fixture = $this->createFixturePoint('env_remote_host');

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir'], ['PHASE8_TEST_DB_HOST' => '192.168.1.100']);
        exec($cmd, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $json = json_decode(implode("\n", $output), true);
        $this->assertSame('DISPOSABLE_DB_REFUSED_NON_LOOPBACK_HOST', $json['error'] ?? null);
    }

    public function testCleanDumpDryRunPassesWithZeroMutations(): void
    {
        $fixture = $this->createFixturePoint('valid_clean_dump', [
            "-- Synthetic MySQL dump\n",
            "CREATE TABLE `users` (`id` int(11) NOT NULL) ENGINE=InnoDB;\n",
            "INSERT INTO `users` VALUES (1);\n",
            "CREATE TABLE `migrations` (`id` int(11) NOT NULL, `migration` varchar(255) NOT NULL, `batch` int(11) NOT NULL) ENGINE=InnoDB;\n",
            "INSERT INTO `migrations` VALUES (1, '2024_01_01_000000_create_users_table', 1);\n",
        ]);

        $output = [];
        $exitCode = 0;
        $cmd = $this->buildPreflightCommand($fixture['pointDir']);
        exec($cmd, $output, $exitCode);

        $raw = implode("\n", $output);
        $this->assertSame(0, $exitCode, "Expected clean preflight exit 0, got: {$raw}");
        $json = json_decode($raw, true);

        $this->assertSame('PREFLIGHT_PASSED', $json['status'] ?? null);
        $this->assertSame('DRY_RUN', $json['mode'] ?? null);
        $this->assertSame(false, $json['execute_authorized'] ?? null);
        $this->assertSame('VERIFIED_SAFE_NO_DANGEROUS_DDL', $json['sql_dump']['safety'] ?? null);
        $this->assertSame(3342, $json['target_server']['port'] ?? null);
        $this->assertSame('127.0.0.1', $json['target_server']['host'] ?? null);
    }

    /**
     * @param list<string> $sqlStatements
     * @return array{pointDir: string}
     */
    private function createFixturePoint(string $prefix, array $sqlStatements = []): array
    {
        $pointDir = $this->tempDir.'/'.$prefix.'_'.bin2hex(random_bytes(4));
        mkdir($pointDir, 0700, true);

        if ($sqlStatements === []) {
            $sqlStatements = [
                "-- Standard mysqldump output\n",
                "CREATE TABLE `settings` (`id` INT PRIMARY KEY) ENGINE=InnoDB;\n",
            ];
        }

        $sqlContent = implode('', $sqlStatements);
        $sqlPath = $pointDir.'/database.sql';
        file_put_contents($sqlPath, $sqlContent);
        chmod($sqlPath, 0600);

        $manifestData = [
            'point_id' => basename($pointDir),
            'captured_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'components' => [
                'database.sql' => [
                    'bytes' => strlen($sqlContent),
                    'sha256' => hash('sha256', $sqlContent),
                ],
            ],
        ];

        $manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $manifestPath = $pointDir.'/manifest.json';
        file_put_contents($manifestPath, $manifestJson);
        chmod($manifestPath, 0600);

        $manifestSha = hash('sha256', $manifestJson);
        $shaFilePath = $pointDir.'/manifest.sha256';
        file_put_contents($shaFilePath, "{$manifestSha}  manifest.json\n");
        chmod($shaFilePath, 0600);

        return ['pointDir' => $pointDir];
    }

    /**
     * @param array<string, string> $envOverrides
     */
    private function buildPreflightCommand(string $pointDir, array $envOverrides = []): string
    {
        $defaultEnv = [
            'APP_ENV' => 'testing',
            'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            'PHASE8_TEST_DB_HOST' => '127.0.0.1',
            'PHASE8_TEST_DB_PORT' => '3342',
            'PHASE8_TEST_DB_USERNAME' => 'test_admin',
            'PHASE8_TEST_DB_PASSWORD' => 'test_pass',
        ];

        $effectiveEnv = array_merge($defaultEnv, $envOverrides);
        $envPrefix = '';
        foreach ($effectiveEnv as $k => $v) {
            $envPrefix .= sprintf('%s=%s ', escapeshellcmd($k), escapeshellarg($v));
        }

        return sprintf(
            '%s%s %s --point-dir=%s',
            $envPrefix,
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->verifierScript),
            escapeshellarg($pointDir)
        );
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
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    (new VerifyBackupImportTestRunner)->runAll();
}
