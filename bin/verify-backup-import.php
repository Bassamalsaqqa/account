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
 * - Recovery point files must be non-public, owned by current operator UID, with exact permissions:
 *     Directory: 0700
 *     Files: 0600
 * - Mandatory external pin --expected-manifest-sha required on execute (64-char hex SHA256).
 * - Requires explicit test environment opt-in:
 *     APP_ENV=testing
 *     PHASE8_ALLOW_DISPOSABLE_DB=1
 *     Server strictly loopback: 127.0.0.1:3342
 * - NO Laravel application bootstrap, NO production database connections, NO migrations run.
 * - Streaming SQL State Machine Scanner:
 *     Lexes quoted strings ('...', "...", `...`), line comments (-- , #), block comments,
 *     and executable comments (/*!... * /). Preserves quote state across multiline statements.
 *     Refuses CREATE DATABASE, USE, DROP DATABASE, external DEFINER, privileged statements,
 *     and client commands (\!, \.).
 *     Strictly NO blind string replacements. NO raw SQL or business data printed in errors.
 * - Fresh owned nonce schema: accounting_p8_tmp_[a-f0-9]{12}
 * - Schema-restricted ephemeral user: u_p8_[a-f0-9]{10}
 *     Schema name wildcards escaped in GRANT (addcslashes($schema, '_%')).
 *     Specific table privileges only; NO broad GRANT ALL; NO global FLUSH PRIVILEGES.
 * - Collision & Cleanup Ownership Safety:
 *     Tracks schemaCreated and userCreated booleans only AFTER exclusive creation succeeds.
 *     If creation fails or aborts, collision object is NEVER dropped.
 *     Finally block drops ONLY owned created resources and verifies absence before reporting SUCCESS.
 * - MariaDB CLI import hardening:
 *     --defaults-extra-file, --binary-mode, --local-infile=0, --skip-reconnect.
 * - Sanitized aggregate verification:
 *     Asserts expected 104 tables and 79 migrations before SUCCESS.
 *     ZERO business data rows output.
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

    private ?string $expectedManifestSha;

    private int $expectedTablesCount;

    private int $expectedMigrationsCount;

    private ?PDO $adminPdo = null;

    private bool $schemaCreated = false;

    private bool $userCreated = false;

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

        $this->expectedManifestSha = isset($options['expected-manifest-sha'])
            ? (string) $options['expected-manifest-sha']
            : null;

        $this->expectedTablesCount = isset($options['expected-tables-count'])
            ? (int) $options['expected-tables-count']
            : 104;

        $this->expectedMigrationsCount = isset($options['expected-migrations-count'])
            ? (int) $options['expected-migrations-count']
            : 79;

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
        // 1. Verify manifest, external pin, and file permissions
        $manifest = $this->verifyManifestIntegrity();

        // 2. Validate database.sql integrity, permissions, and owner UID
        $sqlPath = $this->pointDir.'/database.sql';
        $this->assertSecureFile($sqlPath, 'DATABASE_SQL');

        $sqlBytes = filesize($sqlPath);
        if ($sqlBytes === 0) {
            throw new RuntimeException('DATABASE_SQL_EMPTY');
        }

        $expectedSqlSha = (string) ($manifest['components']['database.sql']['sha256'] ?? '');
        $actualSqlSha = hash_file('sha256', $sqlPath);
        if ($expectedSqlSha === '' || ! hash_equals($expectedSqlSha, $actualSqlSha)) {
            throw new RuntimeException('DATABASE_SQL_SHA256_MISMATCH');
        }

        // 3. Streaming SQL State Machine Scanner (lexes strings, comments, and client commands)
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
                'expected_metrics' => [
                    'tables_count' => $this->expectedTablesCount,
                    'migrations_count' => $this->expectedMigrationsCount,
                ],
                'isolation_policy' => [
                    'schema_prefix' => 'accounting_p8_tmp_',
                    'user_prefix' => 'u_p8_',
                    'grant_pattern_escaped' => true,
                    'no_global_flush' => true,
                    'no_application_boot' => true,
                    'no_migrations_run' => true,
                    'strict_finally_cleanup' => true,
                ],
            ];
        }

        // =====================================================================
        // EXECUTE MODE: Disposable Import & Verification
        // =====================================================================
        if ($this->expectedManifestSha === null || ! preg_match('/^[a-f0-9]{64}$/D', $this->expectedManifestSha)) {
            throw new RuntimeException('EXECUTE_REQUIRES_MANIFEST_SHA_PIN');
        }

        return $this->executeDisposableVerification($sqlPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeDisposableVerification(string $sqlPath): array
    {
        $candidateSchema = 'accounting_p8_tmp_'.bin2hex(random_bytes(6));
        $candidateUser = 'u_p8_'.bin2hex(random_bytes(5));
        $ephemeralPass = bin2hex(random_bytes(16));

        try {
            $this->adminPdo = $this->createAdminPdo();

            // Collision guard: verify candidate schema does not already exist
            $checkStmt = $this->adminPdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $checkStmt->execute([$candidateSchema]);
            if ($checkStmt->fetchColumn() !== false) {
                throw new RuntimeException('SCHEMA_COLLISION_DETECTED');
            }

            // Create disposable schema exclusively
            $this->adminPdo->exec(sprintf(
                'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $candidateSchema
            ));
            // Mark schema created ONLY after successful execution
            $this->ownedSchema = $candidateSchema;
            $this->schemaCreated = true;

            // Attempt to establish schema-restricted ephemeral user
            $this->createEphemeralUser($candidateUser, $ephemeralPass, $candidateSchema);

            // Write temporary private .my.cnf for client import
            $this->myCnfPath = $this->writePrivateClientConfig($candidateUser, $ephemeralPass, $candidateSchema);

            // Execute import via CLI client with hardening flags
            $importCmd = sprintf(
                '%s --defaults-extra-file=%s --binary-mode --local-infile=0 --skip-reconnect %s < %s',
                escapeshellcmd($this->mariadbBin),
                escapeshellarg($this->myCnfPath),
                escapeshellarg($candidateSchema),
                escapeshellarg($sqlPath)
            );

            $importExit = 0;
            $importOutput = [];
            exec($importCmd.' 2>&1', $importOutput, $importExit);

            if ($importExit !== 0) {
                throw new RuntimeException('DATABASE_IMPORT_FAILED');
            }

            // Inspect imported structure and collect sanitized aggregate metrics
            $metrics = $this->collectSanitizedMetrics($candidateSchema, $candidateUser, $ephemeralPass);

            // Assert expected counts before SUCCESS
            if (($metrics['tables_count'] ?? 0) !== $this->expectedTablesCount) {
                throw new RuntimeException('TABLE_COUNT_MISMATCH');
            }
            if (($metrics['migrations_count'] ?? 0) !== $this->expectedMigrationsCount) {
                throw new RuntimeException('MIGRATION_COUNT_MISMATCH');
            }

            return $metrics;
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
            $cntStmt = $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', str_replace('`', '``', $tbl)));
            $count = (int) $cntStmt->fetchColumn();
            $tableRowCounts[$tbl] = $count;
            $totalRows += $count;

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
            // Mark user created ONLY after successful execution
            $this->ownedUser = $user;
            $this->userCreated = true;

            // Wildcard-safe schema pattern: escape '_' and '%'
            $escapedSchema = addcslashes($schema, '_%');
            $specificPrivileges = 'SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER';

            $this->adminPdo->exec(sprintf(
                "GRANT %s ON `%s`.* TO '%s'@'127.0.0.1'",
                $specificPrivileges,
                $escapedSchema,
                $user
            ));
            // Note: NO FLUSH PRIVILEGES
        } catch (PDOException $e) {
            throw new RuntimeException(
                'USER_CREATION_PRIVILEGE_MISSING: Admin test account lacks CREATE USER or GRANT privileges '.
                'to establish schema-restricted ephemeral account. Refusing global test_runner import.'
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
        $cleanupErrors = [];

        // 1. Wipe and unlink temporary .my.cnf
        if ($this->myCnfPath !== null && is_file($this->myCnfPath)) {
            $size = filesize($this->myCnfPath);
            if ($size > 0) {
                file_put_contents($this->myCnfPath, random_bytes($size));
            }
            unlink($this->myCnfPath);
            $this->myCnfPath = null;
        }

        // 2. Drop user ONLY if it was actually created by this instance
        if ($this->userCreated && $this->ownedUser !== null && $this->adminPdo !== null) {
            try {
                $this->adminPdo->exec(sprintf("DROP USER '%s'@'127.0.0.1'", $this->ownedUser));
                $this->userCreated = false;
            } catch (Throwable) {
                $cleanupErrors[] = 'USER_DROP_FAILED';
            }
        }

        // 3. Drop schema ONLY if it was actually created by this instance
        if ($this->schemaCreated && $this->ownedSchema !== null && $this->adminPdo !== null) {
            try {
                $this->adminPdo->exec(sprintf('DROP DATABASE `%s`', $this->ownedSchema));
                $this->schemaCreated = false;
            } catch (Throwable) {
                $cleanupErrors[] = 'SCHEMA_DROP_FAILED';
            }
        }

        // 4. Verify absence of owned resources before exiting
        if ($this->adminPdo !== null) {
            if ($this->ownedSchema !== null) {
                try {
                    $st = $this->adminPdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
                    $st->execute([$this->ownedSchema]);
                    if ($st->fetchColumn() !== false) {
                        $cleanupErrors[] = 'OWNED_SCHEMA_STILL_EXISTS';
                    }
                } catch (Throwable) {
                    $cleanupErrors[] = 'SCHEMA_VERIFICATION_FAILED';
                }
            }
            if ($this->ownedUser !== null) {
                try {
                    $ust = $this->adminPdo->prepare("SELECT User FROM mysql.user WHERE User = ? AND Host = '127.0.0.1'");
                    $ust->execute([$this->ownedUser]);
                    if ($ust->fetchColumn() !== false) {
                        $cleanupErrors[] = 'OWNED_USER_STILL_EXISTS';
                    }
                } catch (Throwable) {
                    $cleanupErrors[] = 'USER_VERIFICATION_FAILED';
                }
            }
        }

        if ($cleanupErrors !== []) {
            throw new RuntimeException('CLEANUP_FAILED_RESOURCES_REMAIN: '.implode(',', $cleanupErrors));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyManifestIntegrity(): array
    {
        $manifestPath = $this->pointDir.'/manifest.json';
        $shaFilePath = $this->pointDir.'/manifest.sha256';

        $this->assertSecureFile($manifestPath, 'MANIFEST_JSON');
        $this->assertSecureFile($shaFilePath, 'MANIFEST_SHA256_FILE');

        $actualManifestSha = hash_file('sha256', $manifestPath);
        $shaContent = (string) file_get_contents($shaFilePath);
        if (! preg_match('/^([a-f0-9]{64})\s+manifest\.json$/mD', $shaContent, $m)) {
            throw new RuntimeException('MANIFEST_SHA256_RECORD_NOT_FOUND');
        }
        if (! hash_equals($m[1], $actualManifestSha)) {
            throw new RuntimeException('MANIFEST_DIGEST_CORRUPTED');
        }

        if ($this->expectedManifestSha !== null && ! hash_equals($this->expectedManifestSha, $actualManifestSha)) {
            throw new RuntimeException('MANIFEST_SHA_PIN_MISMATCH');
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($decoded) || ! isset($decoded['components']) || ! is_array($decoded['components'])) {
            throw new RuntimeException('MANIFEST_MALFORMED');
        }

        return $decoded;
    }

    /**
     * Streaming SQL State Machine Scanner
     * Correctly lexes single-quoted strings, double-quoted strings, backticks,
     * block comments, line comments, and executable comments (/*!... * /).
     * Preserves quote state across multiline statements and buffers.
     */
    private function scanSqlDumpForSafety(string $sqlPath): string
    {
        $handle = fopen($sqlPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('UNABLE_TO_OPEN_SQL_DUMP');
        }

        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;
        $inExecComment = false;

        $lineNum = 1;
        $currentTokens = [];
        $currentToken = '';

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $len = strlen($chunk);
                for ($i = 0; $i < $len; $i++) {
                    $c = $chunk[$i];
                    $next = $i + 1 < $len ? $chunk[$i + 1] : '';

                    if ($c === "\n") {
                        $lineNum++;
                        if ($inLineComment) {
                            $inLineComment = false;
                            continue;
                        }
                    }

                    if ($inLineComment) {
                        continue;
                    }

                    if ($inBlockComment) {
                        if ($c === '*' && $next === '/') {
                            $inBlockComment = false;
                            $i++;
                        }
                        continue;
                    }

                    if ($inExecComment) {
                        if ($c === '*' && $next === '/') {
                            $inExecComment = false;
                            $i++;
                            continue;
                        }
                        // SQL inside /*!... */ is executed: process normally below
                    }

                    // Inside single-quoted string literal
                    if ($inSingleQuote) {
                        if ($c === '\\') {
                            $i++; // skip escaped character
                            continue;
                        }
                        if ($c === "'") {
                            if ($next === "'") {
                                $i++; // '' is escaped single quote
                                continue;
                            }
                            $inSingleQuote = false;
                        }
                        continue;
                    }

                    // Inside double-quoted string literal
                    if ($inDoubleQuote) {
                        if ($c === '\\') {
                            $i++;
                            continue;
                        }
                        if ($c === '"') {
                            if ($next === '"') {
                                $i++;
                                continue;
                            }
                            $inDoubleQuote = false;
                        }
                        continue;
                    }

                    // Inside backticks identifier
                    if ($inBacktick) {
                        if ($c === '`') {
                            if ($next === '`') {
                                $i++;
                                continue;
                            }
                            $inBacktick = false;
                        }
                        continue;
                    }

                    // Outside quotes and comments: check start of comments or quotes
                    if ($c === "'") {
                        $this->flushToken($currentToken, $currentTokens);
                        $inSingleQuote = true;
                        continue;
                    }
                    if ($c === '"') {
                        $this->flushToken($currentToken, $currentTokens);
                        $inDoubleQuote = true;
                        continue;
                    }
                    if ($c === '`') {
                        $this->flushToken($currentToken, $currentTokens);
                        $inBacktick = true;
                        continue;
                    }
                    if ($c === '#' || ($c === '-' && $next === '-')) {
                        $this->flushToken($currentToken, $currentTokens);
                        $inLineComment = true;
                        if ($c === '-') {
                            $i++;
                        }
                        continue;
                    }
                    if ($c === '/' && $next === '*') {
                        $this->flushToken($currentToken, $currentTokens);
                        $i++;
                        $afterStar = $i + 1 < $len ? $chunk[$i + 1] : '';
                        if ($afterStar === '!') {
                            $inExecComment = true;
                            $i++; // skip !
                            // Skip optional version digits (e.g. /*!50013 ...)
                            while ($i + 1 < $len && ctype_digit($chunk[$i + 1])) {
                                $i++;
                            }
                        } else {
                            $inBlockComment = true;
                        }
                        continue;
                    }

                    // Client command escape (\! or \.) outside strings
                    if ($c === '\\' && ($next === '!' || $next === '.')) {
                        throw new RuntimeException("SQL_CONTAINS_CLIENT_COMMAND at line {$lineNum}");
                    }

                    // Statement delimiter
                    if ($c === ';') {
                        $this->flushToken($currentToken, $currentTokens);
                        $this->inspectStatementTokens($currentTokens, $lineNum);
                        $currentTokens = [];
                        continue;
                    }

                    // Token accumulation
                    if (ctype_alnum($c) || $c === '_' || $c === '@') {
                        $currentToken .= $c;
                    } else {
                        $this->flushToken($currentToken, $currentTokens);
                    }
                }
            }

            // Flush any trailing token
            $this->flushToken($currentToken, $currentTokens);
            if ($currentTokens !== []) {
                $this->inspectStatementTokens($currentTokens, $lineNum);
            }
        } finally {
            fclose($handle);
        }

        return 'VERIFIED_SAFE_NO_DANGEROUS_DDL';
    }

    private function flushToken(string &$currentToken, array &$tokens): void
    {
        if ($currentToken !== '') {
            $tokens[] = strtoupper($currentToken);
            $currentToken = '';
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function inspectStatementTokens(array $tokens, int $lineNum): void
    {
        if ($tokens === []) {
            return;
        }

        // 1. Refuse CREATE DATABASE / SCHEMA
        $count = count($tokens);
        for ($i = 0; $i < $count - 1; $i++) {
            if ($tokens[$i] === 'CREATE' && ($tokens[$i + 1] === 'DATABASE' || $tokens[$i + 1] === 'SCHEMA')) {
                throw new RuntimeException("SQL_CONTAINS_FORBIDDEN_CREATE_DATABASE at line {$lineNum}");
            }
            if ($tokens[$i] === 'DROP' && ($tokens[$i + 1] === 'DATABASE' || $tokens[$i + 1] === 'SCHEMA')) {
                throw new RuntimeException("SQL_CONTAINS_FORBIDDEN_DROP_DATABASE at line {$lineNum}");
            }
        }

        // 2. Refuse USE statement
        if ($tokens[0] === 'USE') {
            throw new RuntimeException("SQL_CONTAINS_FORBIDDEN_USE at line {$lineNum}");
        }

        // 3. Refuse external DEFINER clauses (even inside /*!... * /)
        if (in_array('DEFINER', $tokens, true)) {
            throw new RuntimeException("SQL_CONTAINS_EXTERNAL_DEFINER at line {$lineNum}");
        }

        // 4. Refuse privileged administrative statements
        $first = $tokens[0];
        if (in_array($first, ['GRANT', 'REVOKE', 'SHUTDOWN'], true)) {
            throw new RuntimeException("SQL_CONTAINS_PRIVILEGED_STATEMENT at line {$lineNum}");
        }
        if (in_array($first, ['ALTER', 'CREATE', 'DROP'], true) && isset($tokens[1]) && $tokens[1] === 'USER') {
            throw new RuntimeException("SQL_CONTAINS_PRIVILEGED_STATEMENT at line {$lineNum}");
        }
        if ($first === 'SET' && isset($tokens[1]) && $tokens[1] === 'GLOBAL') {
            throw new RuntimeException("SQL_CONTAINS_PRIVILEGED_STATEMENT at line {$lineNum}");
        }
        if ($first === 'FLUSH' && in_array('PRIVILEGES', $tokens, true)) {
            throw new RuntimeException("SQL_CONTAINS_PRIVILEGED_STATEMENT at line {$lineNum}");
        }
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

        if (! in_array($settings['HOST'], ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('DISPOSABLE_DB_REFUSED_NON_LOOPBACK_HOST');
        }

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

        // Validate ancestors for symlinks and non-public path
        $anc = $real;
        do {
            if (is_link($anc)) {
                throw new RuntimeException('SYMLINK_REFUSED_POINT_DIR');
            }
            $p = dirname($anc);
            if ($p === $anc) {
                break;
            }
            $anc = $p;
        } while ($anc !== '.' && $anc !== '');

        if (str_contains($real, '/public_html') || str_contains($real, '/public/')) {
            throw new RuntimeException('TARGET_DIR_INSIDE_PUBLIC_ROOT');
        }

        // Validate owner UID matches current process
        $stat = stat($real);
        if ($stat === false || $stat['uid'] !== getmyuid()) {
            throw new RuntimeException('POINT_DIR_OWNER_UID_MISMATCH');
        }

        // Exact permissions check: must be exactly 0700
        $perms = $stat['mode'] & 0777;
        if ($perms !== 0700) {
            throw new RuntimeException('POINT_DIR_PERMISSIONS_NOT_0700');
        }

        return $real;
    }

    private function assertSecureFile(string $path, string $context): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("{$context}_NOT_FOUND_OR_UNREADABLE");
        }

        if (is_link($path)) {
            throw new RuntimeException("SYMLINK_REFUSED_{$context}");
        }

        $stat = stat($path);
        if ($stat === false || $stat['uid'] !== getmyuid()) {
            throw new RuntimeException("{$context}_OWNER_UID_MISMATCH");
        }

        $perms = $stat['mode'] & 0777;
        if ($perms !== 0600) {
            throw new RuntimeException("{$context}_PERMISSIONS_NOT_0600");
        }
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
                echo "  --execute                      Execute disposable MariaDB import (default: safe preflight)\n";
                echo "  --dry-run                      Explicitly run in safe dry-run mode\n";
                echo "  --point-dir=<path>             Path to recovery point directory\n";
                echo "  --expected-manifest-sha=<h>    Expected 64-char manifest SHA256 pin\n";
                echo "  --expected-tables-count=<n>    Expected table count (default: 104)\n";
                echo "  --expected-migrations-count=<n> Expected migrations count (default: 79)\n";
                echo "  --mariadb-bin=<path>           Path to mariadb/mysql client CLI\n";
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
