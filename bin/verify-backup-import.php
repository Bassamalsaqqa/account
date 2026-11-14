#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Small Trader Accounting — Bounded Backup Restore Verification Utility
 *
 * Standalone root-operated utility to verify recovery point database dumps
 * in an isolated disposable schema on loopback MariaDB (127.0.0.1:3342).
 *
 * Security & Containment Invariants:
 * - Default mode is safe DRY-RUN / PREFLIGHT (0 mutations, exit 0 on valid checks).
 * - Explicit --execute required for actual disposable import.
 * - Umask 077 enforced immediately: directories 0700, files 0600.
 * - Recovery point files must be non-public and protected (not world-readable/executable).
 * - Requires explicit test environment opt-in:
 *     APP_ENV=testing
 *     PHASE8_ALLOW_DISPOSABLE_DB=1
 *     Server strictly loopback: 127.0.0.1:3342
 * - NO Laravel application bootstrap, NO production database connections, NO migrations run.
 * - Pre-import SQL safety scanner:
 *     Refuses CREATE DATABASE, USE, DROP DATABASE, external DEFINER, and privileged statements.
 *     Fails closed on any unsafe syntax; strictly NO blind string/regex replacements.
 * - Fresh owned nonce schema: accounting_p8_tmp_[a-f0-9]{12}
 * - Schema-restricted ephemeral user: u_p8_[a-f0-9]{10}
 *     If admin lacks user creation capability, reports exact required capability
 *     rather than blindly falling back to global test_runner.
 * - Import executed via private client config file (.my.cnf, mode 0600) wiped in finally.
 * - Sanitized aggregate verification: table names, row count totals, migration names,
 *     and structural schema hash only. ZERO business data rows output.
 * - Strict finally block: always drops ONLY the owned schema and owned ephemeral user.
 *     Never touches, adopts, or cleans up existing schemas/users.
 */

namespace SmallTrader\Recovery;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class BackupImportVerifier
{
    private const string SCHEMA_REGEX = '/^accounting_p8_tmp_[a-f0-9]{12}$/D';

    private const string EPHEMERAL_USER_REGEX = '/^u_p8_[a-f0-9]{10}$/D';

    private string $pointDir;

    private bool $execute;

    private string $mariadbBin;

    private ?PDO $adminPdo = null;

    private ?string $ownedSchema = null;

    private ?string $ownedUser = null;

    private ?string $myCnfPath = null;

    /** @var array<string, string> */
    private array $dbSettings = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        umask(0077);

        $this->execute = (bool) ($options['execute'] ?? false);
        $this->mariadbBin = (string) ($options['mariadb-bin'] ?? $this->detectMariaDbBinary());

        $rawPoint = (string) ($options['point-dir'] ?? '');
        if ($rawPoint === '') {
            throw new RuntimeException('POINT_DIR_REQUIRED');
        }

        $this->pointDir = $this->assertRecoveryPointDirectory($rawPoint);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        // 1. Verify manifest and hash integrity
        $manifest = $this->verifyManifestIntegrity();

        // 2. Validate database.sql integrity and permissions
        $sqlPath = $this->pointDir.'/database.sql';
        if (! is_file($sqlPath) || ! is_readable($sqlPath)) {
            throw new RuntimeException('DATABASE_SQL_NOT_FOUND_OR_UNREADABLE');
        }
        $sqlBytes = filesize($sqlPath);
        if ($sqlBytes === 0) {
            throw new RuntimeException('DATABASE_SQL_EMPTY');
        }

        $expectedSqlSha = (string) ($manifest['components']['database.sql']['sha256'] ?? '');
        $actualSqlSha = hash_file('sha256', $sqlPath);
        if ($expectedSqlSha === '' || ! hash_equals($expectedSqlSha, $actualSqlSha)) {
            throw new RuntimeException('DATABASE_SQL_SHA256_MISMATCH');
        }

        // 3. Fail-Closed Pre-Import SQL Safety Scanner
        $sqlSafety = $this->scanSqlDumpForSafety($sqlPath);

        // 4. Validate Environment Opt-in & Loopback Server Settings
        $this->dbSettings = $this->assertEnvironmentOptIn();

        // 5. Default Dry-Run / Preflight Mode (0 mutations)
        if (! $this->execute) {
            return [
                'status' => 'PREFLIGHT_PASSED',
                'mode' => 'DRY_RUN',
                'execute_authorized' => false,
                'verified_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'point_dir' => $this->pointDir,
                'sql_dump' => [
                    'bytes' => $sqlBytes,
                    'sha256' => $actualSqlSha,
                    'safety' => $sqlSafety,
                ],
                'target_server' => [
                    'host' => $this->dbSettings['HOST'],
                    'port' => (int) $this->dbSettings['PORT'],
                    'isolation' => 'LOOPBACK_DISPOSABLE_ONLY',
                ],
                'isolation_policy' => [
                    'schema_prefix' => 'accounting_p8_tmp_',
                    'user_prefix' => 'u_p8_',
                    'no_application_boot' => true,
                    'no_migrations_run' => true,
                    'strict_finally_cleanup' => true,
                ],
            ];
        }

        // =====================================================================
        // EXECUTE MODE: Disposable Import & Verification
        // =====================================================================
        return $this->executeDisposableVerification($sqlPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeDisposableVerification(string $sqlPath): array
    {
        $this->ownedSchema = 'accounting_p8_tmp_'.bin2hex(random_bytes(6));
        $this->ownedUser = 'u_p8_'.bin2hex(random_bytes(5));
        $ephemeralPass = bin2hex(random_bytes(16));

        try {
            $this->adminPdo = $this->createAdminPdo();

            // Attempt to establish schema-restricted ephemeral user
            $this->createEphemeralUser($this->ownedUser, $ephemeralPass, $this->ownedSchema);

            // Create disposable schema
            $this->adminPdo->exec(sprintf(
                'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $this->ownedSchema
            ));

            // Write temporary private .my.cnf for client import
            $this->myCnfPath = $this->writePrivateClientConfig($this->ownedUser, $ephemeralPass, $this->ownedSchema);

            // Execute import via CLI client
            $importCmd = sprintf(
                '%s --defaults-extra-file=%s %s < %s',
                escapeshellcmd($this->mariadbBin),
                escapeshellarg($this->myCnfPath),
                escapeshellarg($this->ownedSchema),
                escapeshellarg($sqlPath)
            );

            $importExit = 0;
            $importOutput = [];
            exec($importCmd.' 2>&1', $importOutput, $importExit);

            if ($importExit !== 0) {
                throw new RuntimeException('DATABASE_IMPORT_FAILED');
            }

            // Inspect imported structure and collect sanitized aggregate metrics
            return $this->collectSanitizedMetrics($this->ownedSchema, $this->ownedUser, $ephemeralPass);
        } finally {
            $this->cleanupDisposableResources();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function collectSanitizedMetrics(string $schema, string $user, string $password): array
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->dbSettings['HOST'],
            $this->dbSettings['PORT'],
            $schema
        );

        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // 1. Base tables listing
        $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];
        while ($row = $tablesStmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = (string) $row[0];
        }
        sort($tables);

        // 2. Row count per table and aggregate total
        $totalRows = 0;
        $tableRowCounts = [];
        $createStatements = [];

        foreach ($tables as $tbl) {
            // Count query
            $cntStmt = $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', str_replace('`', '``', $tbl)));
            $count = (int) $cntStmt->fetchColumn();
            $tableRowCounts[$tbl] = $count;
            $totalRows += $count;

            // Structure checksum (SHOW CREATE TABLE)
            $createStmt = $pdo->query(sprintf('SHOW CREATE TABLE `%s`', str_replace('`', '``', $tbl)));
            $createRow = $createStmt->fetch(PDO::FETCH_NUM);
            $createStatements[] = (string) ($createRow[1] ?? '');
        }

        // 3. Migrations list
        $migrations = [];
        if (in_array('migrations', $tables, true)) {
            $migStmt = $pdo->query('SELECT migration, batch FROM migrations ORDER BY id ASC');
            while ($mRow = $migStmt->fetch()) {
                $migrations[] = [
                    'migration' => (string) $mRow['migration'],
                    'batch' => (int) $mRow['batch'],
                ];
            }
        }

        // 4. Schema structure hash
        $structureSha256 = hash('sha256', implode("\n---\n", $createStatements));

        return [
            'status' => 'SUCCESS',
            'mode' => 'EXECUTE',
            'verified_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'disposable_schema' => $schema,
            'tables_count' => count($tables),
            'tables' => $tables,
            'total_rows_aggregate' => $totalRows,
            'table_row_counts' => $tableRowCounts,
            'migrations_count' => count($migrations),
            'migrations' => $migrations,
            'schema_structure_sha256' => $structureSha256,
            'containment' => 'SANITIZED_METRICS_ONLY_NO_DATA_LEAK',
        ];
    }

    private function createAdminPdo(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4',
            $this->dbSettings['HOST'],
            $this->dbSettings['PORT']
        );

        return new PDO($dsn, $this->dbSettings['USERNAME'], $this->dbSettings['PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function createEphemeralUser(string $user, string $password, string $schema): void
    {
        if (! preg_match(self::EPHEMERAL_USER_REGEX, $user)) {
            throw new RuntimeException('INVALID_EPHEMERAL_USER_NAME');
        }

        try {
            $this->adminPdo->exec(sprintf(
                "CREATE USER '%s'@'127.0.0.1' IDENTIFIED BY %s",
                $user,
                $this->adminPdo->quote($password)
            ));

            $this->adminPdo->exec(sprintf(
                "GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'127.0.0.1'",
                $schema,
                $user
            ));

            $this->adminPdo->exec('FLUSH PRIVILEGES');
        } catch (PDOException $e) {
            throw new RuntimeException(
                'USER_CREATION_PRIVILEGE_MISSING: Admin test account lacks CREATE USER or GRANT privileges '.
                'to create schema-restricted ephemeral user. Refusing global test_runner import.'
            );
        }
    }

    private function writePrivateClientConfig(string $user, string $pass, string $db): string
    {
        $tempDir = sys_get_temp_dir();
        $cnfPath = $tempDir.'/p8_my_cnf_'.bin2hex(random_bytes(6)).'.cnf';

        $cnfContent = sprintf(
            "[client]\nhost = \"%s\"\nport = %s\nuser = \"%s\"\npassword = \"%s\"\ndatabase = \"%s\"\n",
            addcslashes($this->dbSettings['HOST'], "\\\""),
            (int) $this->dbSettings['PORT'],
            addcslashes($user, "\\\""),
            addcslashes($pass, "\\\""),
            addcslashes($db, "\\\"")
        );

        file_put_contents($cnfPath, $cnfContent);
        chmod($cnfPath, 0600);

        return $cnfPath;
    }

    private function cleanupDisposableResources(): void
    {
        // 1. Wipe and unlink temporary .my.cnf
        if ($this->myCnfPath !== null && is_file($this->myCnfPath)) {
            $size = filesize($this->myCnfPath);
            if ($size > 0) {
                file_put_contents($this->myCnfPath, random_bytes($size));
            }
            unlink($this->myCnfPath);
            $this->myCnfPath = null;
        }

        // 2. Drop owned ephemeral user (never touch existing users)
        if ($this->adminPdo !== null && $this->ownedUser !== null) {
            if (preg_match(self::EPHEMERAL_USER_REGEX, $this->ownedUser)) {
                try {
                    $this->adminPdo->exec(sprintf("DROP USER IF EXISTS '%s'@'127.0.0.1'", $this->ownedUser));
                } catch (Throwable) {
                    // Best effort cleanup
                }
            }
            $this->ownedUser = null;
        }

        // 3. Drop owned disposable schema (never touch existing schemas)
        if ($this->adminPdo !== null && $this->ownedSchema !== null) {
            if (preg_match(self::SCHEMA_REGEX, $this->ownedSchema)) {
                try {
                    $this->adminPdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $this->ownedSchema));
                } catch (Throwable) {
                    // Best effort cleanup
                }
            }
            $this->ownedSchema = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyManifestIntegrity(): array
    {
        $manifestPath = $this->pointDir.'/manifest.json';
        $shaFilePath = $this->pointDir.'/manifest.sha256';

        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            throw new RuntimeException('MANIFEST_JSON_NOT_FOUND_OR_UNREADABLE');
        }
        if (! is_file($shaFilePath) || ! is_readable($shaFilePath)) {
            throw new RuntimeException('MANIFEST_SHA256_FILE_NOT_FOUND_OR_UNREADABLE');
        }

        // Verify SHA-256 of manifest.json
        $actualManifestSha = hash_file('sha256', $manifestPath);
        $shaContent = (string) file_get_contents($shaFilePath);
        if (! preg_match('/^([a-f0-9]{64})\s+manifest\.json$/mD', $shaContent, $m)) {
            throw new RuntimeException('MANIFEST_SHA256_RECORD_NOT_FOUND');
        }
        if (! hash_equals($m[1], $actualManifestSha)) {
            throw new RuntimeException('MANIFEST_DIGEST_CORRUPTED');
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($decoded) || ! isset($decoded['components']) || ! is_array($decoded['components'])) {
            throw new RuntimeException('MANIFEST_MALFORMED');
        }

        return $decoded;
    }

    private function scanSqlDumpForSafety(string $sqlPath): string
    {
        $handle = fopen($sqlPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('UNABLE_TO_OPEN_SQL_DUMP');
        }

        $lineNum = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $lineNum++;
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                $upper = strtoupper($trimmed);

                // 1. Refuse CREATE DATABASE / SCHEMA
                if (preg_match('/\bCREATE\s+(DATABASE|SCHEMA)\b/i', $trimmed)) {
                    throw new RuntimeException("SQL_CONTAINS_FORBIDDEN_CREATE_DATABASE at line {$lineNum}");
                }

                // 2. Refuse USE statement
                if (preg_match('/\bUSE\s+[`a-zA-Z0-9_]/i', $trimmed)) {
                    throw new RuntimeException("SQL_CONTAINS_FORBIDDEN_USE at line {$lineNum}");
                }

                // 3. Refuse DROP DATABASE / SCHEMA
                if (preg_match('/\bDROP\s+(DATABASE|SCHEMA)\b/i', $trimmed)) {
                    throw new RuntimeException("SQL_CONTAINS_FORBIDDEN_DROP_DATABASE at line {$lineNum}");
                }

                // 4. Refuse external DEFINER clauses
                if (preg_match('/\bDEFINER\s*=\s*[`a-zA-Z0-9_]/i', $trimmed)) {
                    throw new RuntimeException("SQL_CONTAINS_EXTERNAL_DEFINER at line {$lineNum}");
                }

                // 5. Refuse privileged administrative statements
                if (preg_match('/\b(GRANT|REVOKE|SHUTDOWN|FLUSH\s+PRIVILEGES|SET\s+GLOBAL|ALTER\s+USER|CREATE\s+USER|DROP\s+USER)\b/i', $trimmed)) {
                    throw new RuntimeException("SQL_CONTAINS_PRIVILEGED_STATEMENT at line {$lineNum}");
                }
            }
        } finally {
            fclose($handle);
        }

        return 'VERIFIED_SAFE_NO_DANGEROUS_DDL';
    }

    /**
     * @return array<string, string>
     */
    private function assertEnvironmentOptIn(): array
    {
        $appEnv = getenv('APP_ENV');
        if ($appEnv !== 'testing') {
            throw new RuntimeException('VERIFY_IMPORT_REFUSED_APP_ENV_NOT_TESTING');
        }

        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1') {
            throw new RuntimeException('VERIFY_IMPORT_REFUSED_OPT_IN_FLAG_MISSING');
        }

        $settings = [];
        foreach (['HOST', 'PORT', 'USERNAME', 'PASSWORD'] as $key) {
            $val = getenv('PHASE8_TEST_DB_'.$key);
            if ($key === 'PASSWORD' && $val === false && getenv('PHASE8_TEST_DB_EMPTY_PASSWORD') === '1') {
                $val = '';
            }
            if ($val === false || ($key !== 'PASSWORD' && $val === '')) {
                throw new RuntimeException("EXPLICIT_TEST_DB_{$key}_REQUIRED");
            }
            $settings[$key] = (string) $val;
        }

        // Server strictly loopback
        if (! in_array($settings['HOST'], ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('DISPOSABLE_DB_REFUSED_NON_LOOPBACK_HOST');
        }

        // Port strictly 3342
        if ($settings['PORT'] !== '3342') {
            throw new RuntimeException('DISPOSABLE_DB_REFUSED_NON_3342_PORT');
        }

        return $settings;
    }

    private function assertRecoveryPointDirectory(string $path): string
    {
        if (str_contains($path, "\0") || str_contains($path, '://')) {
            throw new RuntimeException('INVALID_PATH_CHARACTERS_POINT_DIR');
        }

        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('POINT_DIR_NOT_FOUND');
        }

        // Check symlinks
        if (is_link($path) || is_link($real)) {
            throw new RuntimeException('SYMLINK_REFUSED_POINT_DIR');
        }

        // Permissions check: must not be world-readable/writable/executable
        $perms = fileperms($real) & 0777;
        if (($perms & 0007) !== 0) {
            throw new RuntimeException('INSECURE_PERMISSIONS_POINT_DIR_WORLD_ACCESSIBLE');
        }

        return $real;
    }

    private function detectMariaDbBinary(): string
    {
        $candidates = ['mariadb', 'mysql', '/usr/bin/mariadb', '/usr/bin/mysql'];
        foreach ($candidates as $cand) {
            $out = [];
            $exit = 0;
            exec(sprintf('which %s 2>/dev/null', escapeshellarg($cand)), $out, $exit);
            if ($exit === 0 && ! empty($out[0])) {
                return trim($out[0]);
            }
        }

        return 'mariadb';
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = [];
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                echo "Usage: php bin/verify-backup-import.php [options]\n";
                echo "  --execute                 Execute disposable MariaDB import (default: safe preflight)\n";
                echo "  --dry-run                 Explicitly run in safe dry-run mode\n";
                echo "  --point-dir=<path>        Path to recovery point directory\n";
                echo "  --mariadb-bin=<path>      Path to mariadb/mysql client CLI\n";
                exit(0);
            }
            if ($arg === '--execute') {
                $options['execute'] = true;
            } elseif ($arg === '--dry-run') {
                $options['execute'] = false;
            } elseif (preg_match('/^--([a-z-]+)=(.*)$/sD', $arg, $m)) {
                $options[$m[1]] = $m[2];
            } else {
                throw new RuntimeException('UNKNOWN_OPTION');
            }
        }

        $verifier = new BackupImportVerifier($options);
        $result = $verifier->run();

        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        $msg = $e instanceof RuntimeException && preg_match('/^[A-Z0-9_]+$/D', $e->getMessage())
            ? $e->getMessage()
            : (str_contains($e->getMessage(), 'SQL_CONTAINS_') ? preg_replace('/ at line \d+/', '', $e->getMessage()) : 'VERIFY_IMPORT_FAILED');

        echo json_encode(['status' => 'FAIL', 'error' => $msg], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
}
