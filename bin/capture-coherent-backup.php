#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Small Trader Accounting — Fail-Closed Coherent Recovery Point Capture Tool
 *
 * Standalone root-operated utility to capture transaction-consistent SQL,
 * baseline code, build assets, configuration, and private files under verified
 * maintenance quiescence.
 *
 * Security & Integrity Invariants:
 * - Default mode is safe DRY-RUN / PREFLIGHT (0 mutations, exit 0 on valid checks).
 * - Explicit --execute required for actual capture.
 * - Umask 077 enforced immediately: directories 0700, files 0600.
 * - Zero directory creation in dry-run mode (validates existing ancestor + future path without mkdir).
 * - Target directory MUST be outside public web root and non-symlinked.
 * - Execute mode requires verified maintenance mode active (storage/framework/down)
 *   and quiescence; fails closed if maintenance is OFF or git HEAD cannot be pinned.
 * - Requires clean git working tree in execute mode (fails closed on uncommitted tracked modifications).
 * - Dotenv dependency required (autoloaded from app-dir vendor); fails closed if Dotenv missing.
 * - MySQL .my.cnf options generated with proper escaping/quoting, host/port strictly validated.
 * - Temporary .my.cnf wiped in finally block even on failure.
 * - Unique nonce per capture point with exclusive mkdir (refuses existing point dir / collision).
 * - All component commands checked: any nonzero exit aborts immediately, writes FAILED marker,
 *   never reports SUCCESS, and refuses to finalize complete bundle.
 * - Captures tracked git bundle, vendor, build, storage/app, public assets, and config.
 * - Detects and refuses cached config (bootstrap/cache/config.php) discrepancy against .env.
 * - Zero plaintext secrets on stdout, stderr, or manifest.
 */

namespace SmallTrader\Recovery;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RuntimeException;
use Throwable;

final class CoherentBackupCapture
{
    private string $appDir;

    private string $publicDir;

    private string $targetBaseDir;

    private string $mysqldumpBin;

    private string $gitBin;

    private string $tarBin;

    private bool $execute;

    private ?string $expectedSourceSha;

    private ?string $expectedBuildManifestSha;

    private ?string $expectedHtaccessSha;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(array $options = [])
    {
        umask(0077);

        $this->execute = (bool) ($options['execute'] ?? false);

        $this->mysqldumpBin = (string) ($options['mysqldump-bin'] ?? 'mysqldump');
        $this->gitBin = (string) ($options['git-bin'] ?? 'git');
        $this->tarBin = (string) ($options['tar-bin'] ?? 'tar');

        $this->expectedSourceSha = isset($options['expected-source-sha']) ? (string) $options['expected-source-sha'] : null;
        $this->expectedBuildManifestSha = isset($options['expected-build-sha']) ? (string) $options['expected-build-sha'] : null;
        $this->expectedHtaccessSha = isset($options['expected-htaccess-sha']) ? (string) $options['expected-htaccess-sha'] : null;

        $rawApp = (string) ($options['app-dir'] ?? getcwd() ?: '.');
        $rawPublic = (string) ($options['public-dir'] ?? dirname($rawApp).'/public_html/account');
        $rawTarget = (string) ($options['target-dir'] ?? dirname($rawApp).'/accounting-backups');

        $this->appDir = $this->assertDirectoryPath($rawApp, 'APP_DIR', requireExists: true);
        $this->publicDir = $this->assertDirectoryPath($rawPublic, 'PUBLIC_DIR', requireExists: true);

        // Security check: validate target base directory without creating it in dry-run
        $this->targetBaseDir = $this->assertTargetBaseDir($rawTarget);

        // Security check: Target base directory MUST NOT be inside public web root
        $this->assertNonPublicTarget($this->targetBaseDir, $this->publicDir);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        // 1. Autoload Dotenv strictly from app-dir vendor
        $this->ensureDotenvLoaded();

        // 2. Parse .env via Dotenv::parse without Laravel boot
        $envPath = $this->appDir.'/.env';
        if (! is_file($envPath) || ! is_readable($envPath)) {
            throw new RuntimeException('ENV_FILE_NOT_FOUND_OR_UNREADABLE');
        }
        $envContent = (string) file_get_contents($envPath);
        $parsedEnv = \Dotenv\Dotenv::parse($envContent);

        $dbName = (string) ($parsedEnv['DB_DATABASE'] ?? '');
        $dbUser = (string) ($parsedEnv['DB_USERNAME'] ?? '');
        $dbPass = (string) ($parsedEnv['DB_PASSWORD'] ?? '');
        $dbHost = (string) ($parsedEnv['DB_HOST'] ?? '127.0.0.1');
        $dbPort = (string) ($parsedEnv['DB_PORT'] ?? '3306');

        if ($dbName === '' || $dbUser === '') {
            throw new RuntimeException('DATABASE_CONFIG_INCOMPLETE');
        }

        // Validate host and port strictly
        $this->assertValidHostAndPort($dbHost, $dbPort);

        // 3. Inspect cached configuration (bootstrap/cache/config.php) if present
        $cachedConfigPath = $this->appDir.'/bootstrap/cache/config.php';
        if (is_file($cachedConfigPath)) {
            $this->assertCachedConfigMatchesEnv($cachedConfigPath, $parsedEnv);
        }

        // 4. Compute live metadata hashes & pins
        $gitHead = $this->resolveGitHead();
        if ($gitHead === 'UNKNOWN_GIT_HEAD' || ! preg_match('/^[a-f0-9]{40}$/D', $gitHead)) {
            throw new RuntimeException('GIT_HEAD_CANNOT_BE_PINNED');
        }

        // Pin checks
        if ($this->expectedSourceSha !== null && ! hash_equals($this->expectedSourceSha, $gitHead)) {
            throw new RuntimeException('SOURCE_SHA_PIN_MISMATCH');
        }

        $htaccessSha256 = $this->hashFileIfExists($this->publicDir.'/.htaccess');
        if ($this->expectedHtaccessSha !== null) {
            if ($htaccessSha256 === null || ! hash_equals($this->expectedHtaccessSha, $htaccessSha256)) {
                throw new RuntimeException('HTACCESS_SHA_PIN_MISMATCH');
            }
        }

        $buildManifestSha256 = $this->hashFileIfExists($this->publicDir.'/build/manifest.json');
        if ($this->expectedBuildManifestSha !== null) {
            if ($buildManifestSha256 === null || ! hash_equals($this->expectedBuildManifestSha, $buildManifestSha256)) {
                throw new RuntimeException('BUILD_MANIFEST_SHA_PIN_MISMATCH');
            }
        }

        $envSha256 = hash('sha256', $envContent);
        $appKeyRef = isset($parsedEnv['APP_KEY']) && is_string($parsedEnv['APP_KEY'])
            ? substr(hash('sha256', $parsedEnv['APP_KEY']), 0, 16)
            : 'NOT_CONFIGURED';

        $downFile = $this->appDir.'/storage/framework/down';
        $isMaintenanceActive = is_file($downFile);
        $maintenanceStatus = $isMaintenanceActive ? 'MAINTENANCE_ACTIVE' : 'MAINTENANCE_OFF';

        // 5. Dry-Run / Preflight Mode (DEFAULT, 0 WRITES)
        if (! $this->execute) {
            return [
                'status' => 'PREFLIGHT_PASSED',
                'mode' => 'DRY_RUN',
                'execute_authorized' => false,
                'captured_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'app_dir' => $this->appDir,
                'public_dir' => $this->publicDir,
                'target_base_dir' => $this->targetBaseDir,
                'git_head' => $gitHead,
                'htaccess_sha256' => $htaccessSha256,
                'build_manifest_sha256' => $buildManifestSha256,
                'env_sha256' => $envSha256,
                'app_key_ref' => $appKeyRef,
                'maintenance_status' => $maintenanceStatus,
                'database' => [
                    'connection' => (string) ($parsedEnv['DB_CONNECTION'] ?? 'mysql'),
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'database' => $dbName,
                    'user' => $dbUser,
                    'password' => '[REDACTED]',
                ],
                'isolation' => 'VERIFIED_NON_PUBLIC',
                'permissions' => 'UMASK_077_ENFORCED',
            ];
        }

        // =====================================================================
        // EXECUTE MODE: Strict Preconditions
        // =====================================================================

        // Precondition A: Maintenance mode MUST be active
        if (! $isMaintenanceActive) {
            throw new RuntimeException('EXECUTE_REFUSED_MAINTENANCE_OFF');
        }

        // Precondition B: Clean tracked working tree (no uncommitted edits)
        $this->assertCleanTrackedTree();

        // Precondition C: Ensure target base directory exists with 0700
        if (! is_dir($this->targetBaseDir)) {
            if (! mkdir($this->targetBaseDir, 0700, true)) {
                throw new RuntimeException('FAILED_TO_CREATE_TARGET_BASE_DIR');
            }
        }
        chmod($this->targetBaseDir, 0700);

        // Precondition D: Collision-proof unique nonce directory with EXCLUSIVE creation
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $timestamp = $now->format('Ymd_His\Z');
        $nonce = bin2hex(random_bytes(6));
        $pointId = 'coherent_recovery_'.$timestamp.'_'.substr($gitHead, 0, 7).'_'.$nonce;
        $pointDir = $this->targetBaseDir.'/'.$pointId;

        // Exclusive directory creation
        if (file_exists($pointDir)) {
            throw new RuntimeException('POINT_DIR_ALREADY_EXISTS_COLLISION_REFUSED');
        }
        if (! mkdir($pointDir, 0700)) {
            throw new RuntimeException('FAILED_EXCLUSIVE_MKDIR_POINT_DIR');
        }
        chmod($pointDir, 0700);

        // Track capture lifecycle to write FAILED marker on any interruption
        $captureCompleted = false;
        $components = [];

        try {
            // Step 1: SQL Dump with safe option escaping and .my.cnf cleanup
            $myCnfPath = $pointDir.'/.my.cnf';
            $sqlPath = $pointDir.'/database.sql';
            try {
                $cnfContent = "[client]\n"
                    .'host = '.$this->escapeIniValue($dbHost)."\n"
                    .'port = '.(int) $dbPort."\n"
                    .'user = '.$this->escapeIniValue($dbUser)."\n"
                    .'password = '.$this->escapeIniValue($dbPass)."\n";

                // Exclusive file creation for .my.cnf
                $handle = fopen($myCnfPath, 'x');
                if ($handle === false) {
                    throw new RuntimeException('FAILED_EXCLUSIVE_CREATE_MY_CNF');
                }
                fwrite($handle, $cnfContent);
                fclose($handle);
                chmod($myCnfPath, 0600);

                $dumpCmd = sprintf(
                    '%s --defaults-extra-file=%s --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces %s > %s',
                    escapeshellcmd($this->mysqldumpBin),
                    escapeshellarg($myCnfPath),
                    escapeshellarg($dbName),
                    escapeshellarg($sqlPath)
                );

                $dumpExit = 0;
                $dumpOutput = [];
                exec($dumpCmd.' 2>&1', $dumpOutput, $dumpExit);

                if ($dumpExit !== 0 || ! is_file($sqlPath) || filesize($sqlPath) === 0) {
                    throw new RuntimeException('MYSQLDUMP_EXECUTION_FAILED');
                }
                chmod($sqlPath, 0600);

                $components['database.sql'] = [
                    'bytes' => filesize($sqlPath),
                    'sha256' => (string) hash_file('sha256', $sqlPath),
                ];
            } finally {
                if (is_file($myCnfPath)) {
                    unlink($myCnfPath);
                }
            }

            // Step 2: Tracked Clean Git Bundle (Pin verified)
            $bundlePath = $pointDir.'/source.bundle';
            $gitCmd = sprintf(
                '%s -C %s bundle create %s HEAD',
                escapeshellcmd($this->gitBin),
                escapeshellarg($this->appDir),
                escapeshellarg($bundlePath)
            );
            $gitExit = 0;
            $gitOutput = [];
            exec($gitCmd.' 2>&1', $gitOutput, $gitExit);

            if ($gitExit !== 0 || ! is_file($bundlePath) || filesize($bundlePath) === 0) {
                throw new RuntimeException('GIT_BUNDLE_CREATION_FAILED');
            }
            chmod($bundlePath, 0600);
            $components['source.bundle'] = [
                'bytes' => filesize($bundlePath),
                'sha256' => (string) hash_file('sha256', $bundlePath),
            ];

            // Step 3: Config Archive (.env + .htaccess)
            $configTarPath = $pointDir.'/config.tar.gz';
            $configItems = [];
            if (is_file($envPath)) {
                $configItems[] = '-C '.escapeshellarg($this->appDir).' .env';
            }
            if (is_file($this->publicDir.'/.htaccess')) {
                $configItems[] = '-C '.escapeshellarg($this->publicDir).' .htaccess';
            }
            if ($configItems === []) {
                throw new RuntimeException('NO_CONFIGURATION_FILES_TO_CAPTURE');
            }
            $tarConfigCmd = sprintf(
                '%s -czf %s %s',
                escapeshellcmd($this->tarBin),
                escapeshellarg($configTarPath),
                implode(' ', $configItems)
            );
            $tarConfigExit = 0;
            exec($tarConfigCmd.' 2>&1', $configOutput, $tarConfigExit);
            if ($tarConfigExit !== 0 || ! is_file($configTarPath) || filesize($configTarPath) === 0) {
                throw new RuntimeException('CONFIG_ARCHIVE_FAILED');
            }
            chmod($configTarPath, 0600);
            $components['config.tar.gz'] = [
                'bytes' => filesize($configTarPath),
                'sha256' => (string) hash_file('sha256', $configTarPath),
            ];

            // Step 4: Private Storage Archive
            $privateStoragePath = $pointDir.'/private_storage.tar.gz';
            $storageApp = $this->appDir.'/storage/app';
            if (! is_dir($storageApp)) {
                throw new RuntimeException('STORAGE_APP_DIR_MISSING');
            }
            $tarStorageCmd = sprintf(
                '%s -czf %s -C %s .',
                escapeshellcmd($this->tarBin),
                escapeshellarg($privateStoragePath),
                escapeshellarg($storageApp)
            );
            $tarStorageExit = 0;
            exec($tarStorageCmd.' 2>&1', $storageOutput, $tarStorageExit);
            if ($tarStorageExit !== 0 || ! is_file($privateStoragePath) || filesize($privateStoragePath) === 0) {
                throw new RuntimeException('PRIVATE_STORAGE_ARCHIVE_FAILED');
            }
            chmod($privateStoragePath, 0600);
            $components['private_storage.tar.gz'] = [
                'bytes' => filesize($privateStoragePath),
                'sha256' => (string) hash_file('sha256', $privateStoragePath),
            ];

            // Step 5: Build Assets Archive
            $buildDir = $this->publicDir.'/build';
            if (! is_dir($buildDir)) {
                throw new RuntimeException('BUILD_DIR_MISSING');
            }
            $buildTarPath = $pointDir.'/build_assets.tar.gz';
            $tarBuildCmd = sprintf(
                '%s -czf %s -C %s .',
                escapeshellcmd($this->tarBin),
                escapeshellarg($buildTarPath),
                escapeshellarg($buildDir)
            );
            $tarBuildExit = 0;
            exec($tarBuildCmd.' 2>&1', $buildOutput, $tarBuildExit);
            if ($tarBuildExit !== 0 || ! is_file($buildTarPath) || filesize($buildTarPath) === 0) {
                throw new RuntimeException('BUILD_ARCHIVE_FAILED');
            }
            chmod($buildTarPath, 0600);
            $components['build_assets.tar.gz'] = [
                'bytes' => filesize($buildTarPath),
                'sha256' => (string) hash_file('sha256', $buildTarPath),
            ];

            // Step 6: Public Assets Archive (excluding build/)
            $publicAssetsPath = $pointDir.'/public_assets.tar.gz';
            $tarPublicCmd = sprintf(
                '%s -czf %s --exclude=build -C %s .',
                escapeshellcmd($this->tarBin),
                escapeshellarg($publicAssetsPath),
                escapeshellarg($this->publicDir)
            );
            $tarPublicExit = 0;
            exec($tarPublicCmd.' 2>&1', $publicOutput, $tarPublicExit);
            if ($tarPublicExit !== 0 || ! is_file($publicAssetsPath) || filesize($publicAssetsPath) === 0) {
                throw new RuntimeException('PUBLIC_ASSETS_ARCHIVE_FAILED');
            }
            chmod($publicAssetsPath, 0600);
            $components['public_assets.tar.gz'] = [
                'bytes' => filesize($publicAssetsPath),
                'sha256' => (string) hash_file('sha256', $publicAssetsPath),
            ];

            // Step 7: Vendor Archive (Mandatory runtime code capture)
            $vendorDir = $this->appDir.'/vendor';
            if (! is_dir($vendorDir)) {
                throw new RuntimeException('VENDOR_DIR_MISSING');
            }
            $vendorTarPath = $pointDir.'/vendor.tar.gz';
            $tarVendorCmd = sprintf(
                '%s -czf %s -C %s .',
                escapeshellcmd($this->tarBin),
                escapeshellarg($vendorTarPath),
                escapeshellarg($vendorDir)
            );
            $tarVendorExit = 0;
            exec($tarVendorCmd.' 2>&1', $vendorOutput, $tarVendorExit);
            if ($tarVendorExit !== 0 || ! is_file($vendorTarPath) || filesize($vendorTarPath) === 0) {
                throw new RuntimeException('VENDOR_ARCHIVE_FAILED');
            }
            chmod($vendorTarPath, 0600);
            $components['vendor.tar.gz'] = [
                'bytes' => filesize($vendorTarPath),
                'sha256' => (string) hash_file('sha256', $vendorTarPath),
            ];

            // Step 8: Manifest Recording
            $completedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            $manifestData = [
                'version' => 1,
                'point_id' => $pointId,
                'captured_at' => $now->format('Y-m-d\TH:i:s\Z'),
                'completed_at' => $completedAt,
                'code_head' => $gitHead,
                'htaccess_sha256' => $htaccessSha256,
                'build_manifest_sha256' => $buildManifestSha256,
                'env_sha256' => $envSha256,
                'app_key_ref' => $appKeyRef,
                'maintenance_status' => $maintenanceStatus,
                'components' => $components,
            ];

            $manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $manifestPath = $pointDir.'/manifest.json';
            file_put_contents($manifestPath, $manifestJson.PHP_EOL);
            chmod($manifestPath, 0600);

            // Step 9: Manifest SHA256 Checksums
            $shaLines = [];
            foreach ($components as $name => $meta) {
                $shaLines[] = $meta['sha256'].'  '.$name;
            }
            $shaLines[] = hash_file('sha256', $manifestPath).'  manifest.json';
            $shaFilePath = $pointDir.'/manifest.sha256';
            file_put_contents($shaFilePath, implode("\n", $shaLines)."\n");
            chmod($shaFilePath, 0600);

            // Step 10: Complete Combined Recovery Bundle
            $completeBundlePath = $pointDir.'/complete_recovery_bundle.tar';
            $bundleMembers = array_keys($components);
            $bundleMembers[] = 'manifest.json';
            $bundleMembers[] = 'manifest.sha256';

            $tarBundleCmd = sprintf(
                '%s -cf %s -C %s %s',
                escapeshellcmd($this->tarBin),
                escapeshellarg($completeBundlePath),
                escapeshellarg($pointDir),
                implode(' ', array_map('escapeshellarg', $bundleMembers))
            );
            $tarBundleExit = 0;
            exec($tarBundleCmd.' 2>&1', $bundleOutput, $tarBundleExit);

            if ($tarBundleExit !== 0 || ! is_file($completeBundlePath) || filesize($completeBundlePath) === 0) {
                throw new RuntimeException('FINAL_BUNDLE_ARCHIVE_FAILED');
            }
            chmod($completeBundlePath, 0600);

            $captureCompleted = true;

            return [
                'status' => 'SUCCESS',
                'mode' => 'EXECUTE',
                'point_id' => $pointId,
                'point_dir' => $pointDir,
                'complete_bundle' => $completeBundlePath,
                'components_count' => count($components),
                'manifest_sha256' => (string) hash_file('sha256', $manifestPath),
                'isolation' => 'VERIFIED_NON_PUBLIC',
                'permissions' => '0700_DIR_0600_FILES',
            ];
        } catch (Throwable $e) {
            if (! $captureCompleted && is_dir($pointDir)) {
                // Write FAILED marker into point dir to prevent accidental adoption
                file_put_contents($pointDir.'/CAPTURE_FAILED', $e->getMessage().PHP_EOL);
                chmod($pointDir.'/CAPTURE_FAILED', 0600);
            }
            throw $e;
        }
    }

    private function ensureDotenvLoaded(): void
    {
        if (class_exists(\Dotenv\Dotenv::class)) {
            return;
        }

        $appVendorAutoload = $this->appDir.'/vendor/autoload.php';
        if (is_file($appVendorAutoload)) {
            require_once $appVendorAutoload;
        }

        if (! class_exists(\Dotenv\Dotenv::class)) {
            throw new RuntimeException('DOTENV_DEPENDENCY_MISSING');
        }
    }

    private function assertValidHostAndPort(string $host, string $port): void
    {
        if (! ctype_digit($port) || (int) $port <= 0 || (int) $port > 65535) {
            throw new RuntimeException('INVALID_DATABASE_PORT');
        }

        // Host must be IP or valid domain/localhost
        if ($host === '' || str_contains($host, "\n") || str_contains($host, "\r") || str_contains($host, ' ') || str_contains($host, ';')) {
            throw new RuntimeException('INVALID_DATABASE_HOST');
        }
    }

    private function escapeIniValue(string $value): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('NEWLINE_IN_CREDENTIAL_NOT_SUPPORTED');
        }

        // MySQL option files support double-quoted strings with escaped backslashes and double quotes
        return '"'.addcslashes($value, "\\\"").'"';
    }

    /**
     * @param  array<string, string>  $parsedEnv
     */
    private function assertCachedConfigMatchesEnv(string $cachedConfigPath, array $parsedEnv): void
    {
        $raw = (string) file_get_contents($cachedConfigPath);
        // Look for database credentials in cached config
        $envDb = $parsedEnv['DB_DATABASE'] ?? '';
        if ($envDb !== '' && ! str_contains($raw, "'database' => '{$envDb}'") && ! str_contains($raw, "\"database\" => \"{$envDb}\"")) {
            throw new RuntimeException('CACHED_CONFIG_DATABASE_DISCREPANCY');
        }
    }

    private function assertCleanTrackedTree(): void
    {
        $out = [];
        $exit = 0;
        exec(escapeshellcmd($this->gitBin)." -C ".escapeshellarg($this->appDir)." status --porcelain --untracked-files=no 2>&1", $out, $exit);

        if ($exit !== 0) {
            throw new RuntimeException('GIT_STATUS_CHECK_FAILED');
        }

        if ($out !== []) {
            throw new RuntimeException('TRACKED_TREE_NOT_CLEAN');
        }
    }

    private function resolveGitHead(): string
    {
        $out = [];
        $exit = 0;
        exec(escapeshellcmd($this->gitBin)." -C ".escapeshellarg($this->appDir)." rev-parse HEAD 2>/dev/null", $out, $exit);
        if ($exit === 0 && isset($out[0]) && preg_match('/^[a-f0-9]{40}$/D', trim($out[0]))) {
            return trim($out[0]);
        }

        // Direct inspect of .git/HEAD
        $headFile = $this->appDir.'/.git/HEAD';
        if (is_file($headFile)) {
            $headContent = trim((string) file_get_contents($headFile));
            if (str_starts_with($headContent, 'ref: ')) {
                $ref = substr($headContent, 5);
                $refFile = $this->appDir.'/.git/'.$ref;
                if (is_file($refFile)) {
                    $commit = trim((string) file_get_contents($refFile));
                    if (preg_match('/^[a-f0-9]{40}$/D', $commit)) {
                        return $commit;
                    }
                }
            } elseif (preg_match('/^[a-f0-9]{40}$/D', $headContent)) {
                return $headContent;
            }
        }

        return 'UNKNOWN_GIT_HEAD';
    }

    private function hashFileIfExists(string $path): ?string
    {
        return is_file($path) ? (string) hash_file('sha256', $path) : null;
    }

    private function assertDirectoryPath(string $path, string $context, bool $requireExists = true): string
    {
        if (str_contains($path, "\0") || str_contains($path, '://')) {
            throw new RuntimeException("INVALID_PATH_CHARACTERS_{$context}");
        }

        if ($requireExists && ! file_exists($path)) {
            throw new RuntimeException("DIRECTORY_NOT_FOUND_{$context}");
        }

        // Validate no symlinks in ancestors
        $cursor = $path;
        do {
            if (is_link($cursor)) {
                throw new RuntimeException("SYMLINK_REFUSED_{$context}");
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        } while ($cursor !== '.' && $cursor !== '');

        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("CANONICAL_PATH_FAILED_{$context}");
        }

        return $real;
    }

    private function assertTargetBaseDir(string $path): string
    {
        if (str_contains($path, "\0") || str_contains($path, '://')) {
            throw new RuntimeException('INVALID_PATH_CHARACTERS_TARGET_DIR');
        }

        // Find closest existing ancestor without creating anything in constructor/dry-run
        $cursor = $path;
        while (! file_exists($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }

        if (! file_exists($cursor)) {
            throw new RuntimeException('TARGET_DIR_ANCESTOR_NOT_FOUND');
        }

        // Validate ancestors for symlinks
        $anc = $cursor;
        do {
            if (is_link($anc)) {
                throw new RuntimeException('SYMLINK_REFUSED_TARGET_DIR');
            }
            $p = dirname($anc);
            if ($p === $anc) {
                break;
            }
            $anc = $p;
        } while ($anc !== '.' && $anc !== '');

        $realAncestor = realpath($cursor);
        if ($realAncestor === false) {
            throw new RuntimeException('CANONICAL_PATH_FAILED_TARGET_DIR');
        }

        // Return canonicalized target base path
        $rel = substr($path, strlen($cursor));
        return rtrim($realAncestor.($rel !== '' ? $rel : ''), '/');
    }

    private function assertNonPublicTarget(string $targetDir, string $publicDir): void
    {
        $normalizedTarget = str_replace('\\', '/', $targetDir);
        $normalizedPublic = str_replace('\\', '/', $publicDir);

        if (str_starts_with($normalizedTarget, $normalizedPublic) || str_contains($normalizedTarget, '/public_html') || str_contains($normalizedTarget, '/public/')) {
            throw new RuntimeException('TARGET_DIR_INSIDE_PUBLIC_ROOT');
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = [];
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                echo "Usage: php bin/capture-coherent-backup.php [options]\n";
                echo "  --execute                 Execute actual capture (default: safe dry-run / preflight)\n";
                echo "  --dry-run                 Explicitly run in safe dry-run mode\n";
                echo "  --app-dir=<path>          Application root directory\n";
                echo "  --public-dir=<path>       Public web root directory\n";
                echo "  --target-dir=<path>       Backup destination base directory\n";
                echo "  --expected-source-sha=<h> Expected 40-char Git commit SHA\n";
                echo "  --expected-build-sha=<h>  Expected 64-char build manifest SHA256\n";
                echo "  --expected-htaccess-sha=h Expected 64-char .htaccess SHA256\n";
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

        $capture = new CoherentBackupCapture($options);
        $result = $capture->run();

        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        $msg = $e instanceof RuntimeException && preg_match('/^[A-Z0-9_]+$/D', $e->getMessage())
            ? $e->getMessage()
            : 'CAPTURE_FAILED';
        echo json_encode(['status' => 'FAIL', 'error' => $msg], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
}
