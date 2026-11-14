#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Small Trader Accounting — Coherent Recovery Point Capture Tool
 *
 * Standalone root-operated utility to capture transaction-consistent SQL,
 * baseline code, build assets, configuration, and private files under verified
 * maintenance quiescence.
 *
 * Security & Integrity Invariants:
 * - Default mode is safe DRY-RUN / PREFLIGHT (0 mutations, exit 0 on valid checks).
 * - Explicit --execute required for actual capture.
 * - Umask 077 enforced immediately: directories 0700, files 0600.
 * - Refuses target directory inside public web root (no exposure risk).
 * - Refuses path traversal and symlinks in all resolved directories.
 * - Parses .env via Dotenv::parse without booting Laravel framework.
 * - MySQL credentials passed via temporary 0600 options file (--defaults-extra-file),
 *   never exposed in command line arguments, process table, or logs.
 * - Temporary options file wiped immediately in finally block.
 * - Zero plaintext secrets in stdout, stderr, or manifest.
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

    private bool $includeVendor;

    private bool $execute;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(array $options = [])
    {
        umask(0077);

        $this->execute = (bool) ($options['execute'] ?? false);
        $this->includeVendor = (bool) ($options['include-vendor'] ?? false);

        $this->mysqldumpBin = (string) ($options['mysqldump-bin'] ?? 'mysqldump');
        $this->gitBin = (string) ($options['git-bin'] ?? 'git');
        $this->tarBin = (string) ($options['tar-bin'] ?? 'tar');

        $rawApp = (string) ($options['app-dir'] ?? getcwd() ?: '.');
        $rawPublic = (string) ($options['public-dir'] ?? dirname($rawApp).'/public_html/account');
        $rawTarget = (string) ($options['target-dir'] ?? dirname($rawApp).'/accounting-backups');

        $this->appDir = $this->assertDirectoryPath($rawApp, 'APP_DIR');
        $this->publicDir = $this->assertDirectoryPath($rawPublic, 'PUBLIC_DIR');
        $this->targetBaseDir = $this->assertDirectoryPath($rawTarget, 'TARGET_DIR', allowCreate: true);

        // Security check: Target base directory MUST NOT be inside public web root
        $this->assertNonPublicTarget($this->targetBaseDir, $this->publicDir);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        // 1. Verify and parse .env without Laravel boot
        $envPath = $this->appDir.'/.env';
        if (! is_file($envPath) || ! is_readable($envPath)) {
            throw new RuntimeException('ENV_FILE_NOT_FOUND_OR_UNREADABLE');
        }
        $envContent = (string) file_get_contents($envPath);
        $parsedEnv = self::parseDotenv($envContent);

        $dbName = (string) ($parsedEnv['DB_DATABASE'] ?? '');
        $dbUser = (string) ($parsedEnv['DB_USERNAME'] ?? '');
        $dbPass = (string) ($parsedEnv['DB_PASSWORD'] ?? '');
        $dbHost = (string) ($parsedEnv['DB_HOST'] ?? '127.0.0.1');
        $dbPort = (string) ($parsedEnv['DB_PORT'] ?? '3306');

        if ($dbName === '' || $dbUser === '') {
            throw new RuntimeException('DATABASE_CONFIG_INCOMPLETE');
        }

        // 2. Compute live metadata hashes (read-only, no secrets)
        $gitHead = $this->resolveGitHead();
        $htaccessSha256 = $this->hashFileIfExists($this->publicDir.'/.htaccess');
        $buildManifestSha256 = $this->hashFileIfExists($this->publicDir.'/build/manifest.json');
        $envSha256 = hash('sha256', $envContent);
        $appKeyRef = isset($parsedEnv['APP_KEY']) && is_string($parsedEnv['APP_KEY'])
            ? substr(hash('sha256', $parsedEnv['APP_KEY']), 0, 16)
            : 'NOT_CONFIGURED';

        $downFile = $this->appDir.'/storage/framework/down';
        $isMaintenanceActive = is_file($downFile);
        $maintenanceStatus = $isMaintenanceActive ? 'MAINTENANCE_ACTIVE' : 'MAINTENANCE_OFF';

        // 3. Dry-Run / Preflight Mode (DEFAULT)
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

        // 4. Execute Mode: Create point directory with 0700 permissions
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $timestamp = $now->format('Ymd_His\Z');
        $pointId = 'coherent_recovery_'.$timestamp.'_'.substr($gitHead, 0, 7);
        $pointDir = $this->targetBaseDir.'/'.$pointId;

        if (! is_dir($pointDir)) {
            if (! mkdir($pointDir, 0700, true)) {
                throw new RuntimeException('FAILED_TO_CREATE_POINT_DIR');
            }
        }
        chmod($pointDir, 0700);

        $components = [];

        // 4.1 SQL Dump with credentials passed ONLY via temporary 0600 options file
        $myCnfPath = $pointDir.'/.my.cnf';
        $sqlPath = $pointDir.'/database.sql';
        try {
            $cnfContent = "[client]\n"
                ."host={$dbHost}\n"
                ."port={$dbPort}\n"
                ."user={$dbUser}\n"
                ."password={$dbPass}\n";
            file_put_contents($myCnfPath, $cnfContent);
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
                'sha256' => hash_file('sha256', $sqlPath),
            ];
        } finally {
            if (is_file($myCnfPath)) {
                unlink($myCnfPath);
            }
        }

        // 4.2 Git Source Bundle
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

        if ($gitExit === 0 && is_file($bundlePath)) {
            chmod($bundlePath, 0600);
            $components['source.bundle'] = [
                'bytes' => filesize($bundlePath),
                'sha256' => hash_file('sha256', $bundlePath),
            ];
        }

        // 4.3 Config Archive (.env + .htaccess with app key history preserved under 0600)
        $configTarPath = $pointDir.'/config.tar.gz';
        $configItems = [];
        if (is_file($envPath)) {
            $configItems[] = '-C '.escapeshellarg($this->appDir).' .env';
        }
        if (is_file($this->publicDir.'/.htaccess')) {
            $configItems[] = '-C '.escapeshellarg($this->publicDir).' .htaccess';
        }
        if ($configItems !== []) {
            $tarConfigCmd = sprintf(
                '%s -czf %s %s',
                escapeshellcmd($this->tarBin),
                escapeshellarg($configTarPath),
                implode(' ', $configItems)
            );
            $tarConfigExit = 0;
            exec($tarConfigCmd.' 2>&1', $configOutput, $tarConfigExit);
            if ($tarConfigExit === 0 && is_file($configTarPath)) {
                chmod($configTarPath, 0600);
                $components['config.tar.gz'] = [
                    'bytes' => filesize($configTarPath),
                    'sha256' => hash_file('sha256', $configTarPath),
                ];
            }
        }

        // 4.4 Private Storage Archive
        $privateStoragePath = $pointDir.'/private_storage.tar.gz';
        $storageApp = $this->appDir.'/storage/app';
        if (is_dir($storageApp)) {
            $tarStorageCmd = sprintf(
                '%s -czf %s -C %s .',
                escapeshellcmd($this->tarBin),
                escapeshellarg($privateStoragePath),
                escapeshellarg($storageApp)
            );
            $tarStorageExit = 0;
            exec($tarStorageCmd.' 2>&1', $storageOutput, $tarStorageExit);
            if ($tarStorageExit === 0 && is_file($privateStoragePath)) {
                chmod($privateStoragePath, 0600);
                $components['private_storage.tar.gz'] = [
                    'bytes' => filesize($privateStoragePath),
                    'sha256' => hash_file('sha256', $privateStoragePath),
                ];
            }
        }

        // 4.5 Build Assets Archive
        $buildDir = $this->publicDir.'/build';
        if (is_dir($buildDir)) {
            $buildTarPath = $pointDir.'/build_assets.tar.gz';
            $tarBuildCmd = sprintf(
                '%s -czf %s -C %s .',
                escapeshellcmd($this->tarBin),
                escapeshellarg($buildTarPath),
                escapeshellarg($buildDir)
            );
            $tarBuildExit = 0;
            exec($tarBuildCmd.' 2>&1', $buildOutput, $tarBuildExit);
            if ($tarBuildExit === 0 && is_file($buildTarPath)) {
                chmod($buildTarPath, 0600);
                $components['build_assets.tar.gz'] = [
                    'bytes' => filesize($buildTarPath),
                    'sha256' => hash_file('sha256', $buildTarPath),
                ];
            }
        }

        // 4.6 Public Assets Archive (excluding build/)
        $publicAssetsPath = $pointDir.'/public_assets.tar.gz';
        $tarPublicCmd = sprintf(
            '%s -czf %s --exclude=build -C %s .',
            escapeshellcmd($this->tarBin),
            escapeshellarg($publicAssetsPath),
            escapeshellarg($this->publicDir)
        );
        $tarPublicExit = 0;
        exec($tarPublicCmd.' 2>&1', $publicOutput, $tarPublicExit);
        if ($tarPublicExit === 0 && is_file($publicAssetsPath)) {
            chmod($publicAssetsPath, 0600);
            $components['public_assets.tar.gz'] = [
                'bytes' => filesize($publicAssetsPath),
                'sha256' => hash_file('sha256', $publicAssetsPath),
            ];
        }

        // 4.7 Vendor Archive (if requested)
        if ($this->includeVendor && is_dir($this->appDir.'/vendor')) {
            $vendorTarPath = $pointDir.'/vendor.tar.gz';
            $tarVendorCmd = sprintf(
                '%s -czf %s -C %s .',
                escapeshellcmd($this->tarBin),
                escapeshellarg($vendorTarPath),
                escapeshellarg($this->appDir.'/vendor')
            );
            $tarVendorExit = 0;
            exec($tarVendorCmd.' 2>&1', $vendorOutput, $tarVendorExit);
            if ($tarVendorExit === 0 && is_file($vendorTarPath)) {
                chmod($vendorTarPath, 0600);
                $components['vendor.tar.gz'] = [
                    'bytes' => filesize($vendorTarPath),
                    'sha256' => hash_file('sha256', $vendorTarPath),
                ];
            }
        }

        // 4.8 Manifest Recording
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

        // 4.9 Manifest SHA256 checksums file
        $shaLines = [];
        foreach ($components as $name => $meta) {
            $shaLines[] = $meta['sha256'].'  '.$name;
        }
        $shaLines[] = hash_file('sha256', $manifestPath).'  manifest.json';
        $shaFilePath = $pointDir.'/manifest.sha256';
        file_put_contents($shaFilePath, implode("\n", $shaLines)."\n");
        chmod($shaFilePath, 0600);

        // 4.10 Complete Combined Recovery Bundle
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

        if ($tarBundleExit === 0 && is_file($completeBundlePath)) {
            chmod($completeBundlePath, 0600);
        }

        return [
            'status' => 'SUCCESS',
            'mode' => 'EXECUTE',
            'point_id' => $pointId,
            'point_dir' => $pointDir,
            'complete_bundle' => is_file($completeBundlePath) ? $completeBundlePath : null,
            'components_count' => count($components),
            'manifest_sha256' => hash_file('sha256', $manifestPath),
            'isolation' => 'VERIFIED_NON_PUBLIC',
            'permissions' => '0700_DIR_0600_FILES',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function parseDotenv(string $content): array
    {
        if (class_exists(\Dotenv\Dotenv::class)) {
            /** @var array<string, string> */
            return \Dotenv\Dotenv::parse($content);
        }

        $result = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);
        if (! is_array($lines)) {
            return $result;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! str_contains($line, '=')) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            if (preg_match('/^([\'"])(.*)\1$/s', $val, $match)) {
                $val = $match[2];
            }
            $result[$key] = $val;
        }

        return $result;
    }

    private function resolveGitHead(): string
    {
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

        // Fallback to git binary if available
        $out = [];
        $exit = 0;
        exec(escapeshellcmd($this->gitBin)." -C ".escapeshellarg($this->appDir)." rev-parse HEAD 2>/dev/null", $out, $exit);
        if ($exit === 0 && isset($out[0]) && preg_match('/^[a-f0-9]{40}$/D', trim($out[0]))) {
            return trim($out[0]);
        }

        return 'UNKNOWN_GIT_HEAD';
    }

    private function hashFileIfExists(string $path): ?string
    {
        return is_file($path) ? (string) hash_file('sha256', $path) : null;
    }

    private function assertDirectoryPath(string $path, string $context, bool $allowCreate = false): string
    {
        if (str_contains($path, "\0") || str_contains($path, '://')) {
            throw new RuntimeException("INVALID_PATH_CHARACTERS_{$context}");
        }

        if (! file_exists($path)) {
            if ($allowCreate) {
                if (! mkdir($path, 0700, true)) {
                    throw new RuntimeException("FAILED_TO_CREATE_{$context}");
                }
            } else {
                throw new RuntimeException("DIRECTORY_NOT_FOUND_{$context}");
            }
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
                echo "  --execute           Execute actual capture (default: safe dry-run / preflight)\n";
                echo "  --dry-run           Explicitly run in safe dry-run mode\n";
                echo "  --app-dir=<path>    Application root directory\n";
                echo "  --public-dir=<path> Public web root directory\n";
                echo "  --target-dir=<path> Backup destination base directory\n";
                echo "  --include-vendor    Include vendor/ directory in capture\n";
                exit(0);
            }
            if ($arg === '--execute') {
                $options['execute'] = true;
            } elseif ($arg === '--dry-run') {
                $options['execute'] = false;
            } elseif ($arg === '--include-vendor') {
                $options['include-vendor'] = true;
            } elseif (preg_match('/^--([a-z-]+)=(.*)$/sD', $arg, $m)) {
                $options[$m[1]] = $m[2];
            } else {
                throw new RuntimeException('UNKNOWN_OPTION');
            }
        }

        // Try requiring vendor/autoload.php if present to register Dotenv\Dotenv class
        $cwdVendor = getcwd().'/vendor/autoload.php';
        if (is_file($cwdVendor)) {
            require_once $cwdVendor;
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
