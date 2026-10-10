#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Small Trader Accounting — Offline Recovery Preflight Inspector
 *
 * Standalone dependency-free pre-bootstrap inspector for disaster recovery rehearsals
 * and upgrade compatibility proofs. Runs in PHP 8.4 without loading Laravel, vendor,
 * Composer autoloader, or Artisan.
 *
 * Operational boundary:
 * - Preflight inspection only; NOT a restore executor.
 * - Always reports bootstrap_authorized=false and dedicated exit 2 on successful check.
 * - Fails closed (exit 1) on corrupt ciphertext, untrusted manifest, unsafe DB, or unquarantined caches.
 */

namespace SmallTrader\Recovery;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RuntimeException;
use Throwable;

final class RecoveryInspection
{
    private const HISTORICAL_CODE_SHA = '96c310f30a07e97ab8e04d5afbf0b2bb805f4317';

    private const CURRENT_CODE_SHA_OPTIONS = [
        '8d8428261cd2a10690ab77a5c7271e46b5ff5217',
        'c74c9a4e0b4135055dbb8ed7d3e3715d2ddba24f',
    ];

    /**
     * @param  array<string, string>  $options
     * @return array<string, mixed>
     */
    public function inspect(array $options): array
    {
        $required = [
            'manifest',
            'manifest-sha256',
            'expected-code',
            'scratch',
            'ownership-proof',
            'target-host',
            'target-port',
            'target-schema',
            'target-user',
        ];
        $allowed = array_merge($required, ['mode']);
        $keys = array_keys($options);
        $this->require(array_diff($required, $keys) === [] && array_diff($keys, $allowed) === [], 'INVOCATION');

        $this->require((bool) preg_match('/^[a-f0-9]{64}$/D', $options['manifest-sha256']), 'TRUSTED_DIGEST');
        $this->require((bool) preg_match('/^[a-f0-9]{40}$/D', $options['expected-code']), 'CODE_IDENTITY');

        $manifestPath = $this->plainPath($options['manifest']);
        $this->require(is_file($manifestPath) && filesize($manifestPath) <= 65536, 'MANIFEST_SIZE');
        $this->require(hash_equals($options['manifest-sha256'], (string) hash_file('sha256', $manifestPath)), 'MANIFEST_TRUST');

        // Trusted digest is verified before JSON decoding or component parsing
        $manifest = $this->json($manifestPath);
        $fields = [
            'version',
            'mode',
            'point_id',
            'captured_at',
            'completed_at',
            'off_host_verified_at',
            'code_sha',
            'build_sha256',
            'schema',
            'key_history_refs',
            'config_ref',
            'components',
        ];
        $this->require(array_diff($fields, array_keys($manifest)) === [] && array_diff(array_keys($manifest), $fields) === [], 'MANIFEST_FIELDS');
        $this->require($manifest['version'] === 1 && in_array($manifest['mode'], ['historical', 'current'], true), 'MANIFEST_VERSION');

        if (isset($options['mode'])) {
            $this->require($manifest['mode'] === $options['mode'], 'MANIFEST_MODE');
        }

        $this->require(is_string($manifest['point_id']) && (bool) preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $manifest['point_id']), 'POINT_IDENTITY');
        $this->require($manifest['code_sha'] === $options['expected-code'] && is_string($manifest['build_sha256']) && (bool) preg_match('/^[a-f0-9]{64}$/D', $manifest['build_sha256']), 'CODE_BUILD_IDENTITY');

        if ($manifest['mode'] === 'historical') {
            $this->require($manifest['code_sha'] === self::HISTORICAL_CODE_SHA, 'HISTORICAL_CODE_SHA');
        } else {
            $this->require(in_array($manifest['code_sha'], self::CURRENT_CODE_SHA_OPTIONS, true), 'CURRENT_CODE_SHA');
        }

        $schema = $manifest['mode'] === 'historical' ? ['tables' => 80, 'migrations' => 61] : ['tables' => 83, 'migrations' => 63];
        $this->require($manifest['schema'] === $schema, 'SCHEMA_IDENTITY');

        $this->require(is_array($manifest['key_history_refs']) && count($manifest['key_history_refs']) >= 1 && count($manifest['key_history_refs']) <= 32, 'KEY_REFERENCES');
        foreach ($manifest['key_history_refs'] as $reference) {
            $this->require(is_array($reference) && array_keys($reference) === ['id', 'cipher'] && is_string($reference['id']) && (bool) preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $reference['id']) && $reference['cipher'] === 'AES-256-CBC', 'KEY_REFERENCES');
        }

        $this->require(is_string($manifest['config_ref']) && (bool) preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $manifest['config_ref']), 'CONFIG_REFERENCE');

        $captured = $this->timestamp($manifest['captured_at']);
        $completed = $this->timestamp($manifest['completed_at']);
        $now = time();
        $this->require($captured <= $completed && $completed <= $now, 'POINT_TIMESTAMPS');

        $offHost = $manifest['off_host_verified_at'];
        if ($offHost !== null) {
            $verified = $this->timestamp($offHost);
            $this->require($completed <= $verified && $verified <= $now, 'OFF_HOST_TIMESTAMP');
        }

        $components = $manifest['components'];
        $names = ['sql', 'code_build', 'private_files', 'public_assets', 'encrypted_records', 'configuration'];
        $this->require(is_array($components) && count($components) === count($names) && array_diff($names, array_keys($components)) === [], 'COMPONENT_COVERAGE');

        foreach ($components as $component) {
            $this->require(is_array($component) && array_keys($component) === ['file', 'bytes', 'sha256', 'point_id'], 'COMPONENT_FIELDS');
            $this->require($component['point_id'] === $manifest['point_id'], 'COMPONENT_COHERENCE');
            $this->require(is_string($component['file']) && (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,120}\.enc$/D', $component['file']), 'COMPONENT_PATH');
            $this->require(is_int($component['bytes']) && $component['bytes'] > 0 && $component['bytes'] <= 536870912, 'COMPONENT_SIZE');
            $this->require(is_string($component['sha256']) && (bool) preg_match('/^[a-f0-9]{64}$/D', $component['sha256']), 'COMPONENT_HASH');
            $file = $this->plainPath(dirname($manifestPath).DIRECTORY_SEPARATOR.$component['file']);
            $this->require(is_file($file) && filesize($file) === $component['bytes'], 'COMPONENT_SIZE');
            $this->require(hash_equals($component['sha256'], (string) hash_file('sha256', $file)), 'COMPONENT_HASH');
        }

        $scratch = $this->plainPath($options['scratch']);
        $this->require(is_dir($scratch), 'SCRATCH_DIRECTORY');
        $this->privateMode($scratch, true);
        $entries = 0;
        $this->scanScratch($scratch, 0, $entries);

        $this->require(in_array($options['target-host'], ['127.0.0.1', '::1'], true), 'TARGET_HOST');
        $this->require(ctype_digit($options['target-port']) && (int) $options['target-port'] > 0 && (int) $options['target-port'] <= 65535, 'TARGET_PORT');
        $this->require((bool) preg_match('/^accounting_p10_restore_[a-f0-9]{12}$/D', $options['target-schema']), 'TARGET_SCHEMA');
        $this->require((bool) preg_match('/^p10_restore_[a-f0-9]{12}$/D', $options['target-user']), 'TARGET_USER');

        $proofPath = $this->plainPath($options['ownership-proof']);
        $this->require(is_file($proofPath) && filesize($proofPath) <= 2048, 'OWNERSHIP_FILE');
        $this->privateMode($proofPath, false);
        $proof = $this->json($proofPath);
        $this->require(array_keys($proof) === ['host', 'port', 'schema', 'user', 'nonce'], 'OWNERSHIP_FIELDS');

        foreach (['host', 'port', 'schema', 'user'] as $field) {
            $this->require(is_string($proof[$field]) && hash_equals($options['target-'.$field], $proof[$field]), 'OWNERSHIP_BINDING');
        }
        $this->require(is_string($proof['nonce']) && (bool) preg_match('/^[a-f0-9]{64}$/D', $proof['nonce']), 'OWNERSHIP_NONCE');

        return [
            'status' => 'INSPECTION_ONLY_BOOTSTRAP_BLOCKED',
            'bootstrap_authorized' => false,
            'mode' => $manifest['mode'],
            'ciphertext_component_checks' => 'PASS',
            'scratch_entries_inspected' => $entries,
            'ownership_metadata_binding' => 'MATCHED_NOT_SERVER_VERIFIED',
            'permissions' => PHP_OS_FAMILY === 'Windows' ? 'WINDOWS_ACL_NOT_VERIFIED' : 'POSIX_MODES_CHECKED',
            'off_host_custody' => $offHost === null ? 'NOT_VERIFIED' : 'DECLARED_NOT_INDEPENDENTLY_VERIFIED',
            'recovery_point_age_seconds' => $now - $captured,
            'provisional_24h_age' => $manifest['mode'] === 'historical' ? 'NOT_APPLICABLE_TO_CURRENT_RPO' : ($now - $captured > 86400 ? 'STALE' : 'WITHIN_TARGET'),
            'effective_database_connection' => 'NOT_VERIFIED',
            'network_ingress_egress_jobs' => 'NOT_VERIFIED',
            'actual_restore_and_key_recovery' => 'NOT_VERIFIED',
            'observed_rpo_rto' => 'NOT_VERIFIED',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $file): array
    {
        $raw = (string) file_get_contents($file);
        $this->require(! preg_match('/base64:[A-Za-z0-9+\/]{43}=|APP_KEY\s*=|"(?:password|secret|key_value)"\s*:\s*"(?!\[REDACTED\]|"")[^"]{8,}"/i', $raw), 'SECRET_EXPOSURE_REFUSED');
        $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $this->require(is_array($value) && ! array_is_list($value), 'JSON_OBJECT');

        return $value;
    }

    private function timestamp(mixed $value): int
    {
        $this->require(is_string($value) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value), 'TIMESTAMP_FORMAT');
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $this->require($time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value, 'TIMESTAMP_VALUE');

        return $time->getTimestamp();
    }

    private function plainPath(string $path): string
    {
        $this->require(! str_contains($path, "\0") && ! str_contains($path, '://'), 'FILESYSTEM_PATH');
        // Inspect the submitted path and resolved ancestors; symlinks cannot be accepted silently.
        $cursor = $path;
        do {
            $this->require(! is_link($cursor), 'SYMLINK_REFUSED');
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        } while ($cursor !== '.' && $cursor !== '');

        $real = realpath($path);
        $this->require($real !== false, 'FILESYSTEM_PATH');

        return $real;
    }

    private function privateMode(string $path, bool $directory): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->require((fileperms($path) & 0777) === ($directory ? 0700 : 0600), 'PRIVATE_PERMISSIONS');
        }
    }

    private function scanScratch(string $directory, int $depth, int &$entries): void
    {
        $this->require($depth <= 20, 'SCRATCH_DEPTH');
        $normalizedDir = str_replace('\\', '/', $directory);
        $inBootstrapCache = str_ends_with($normalizedDir, '/bootstrap/cache');
        $inQuarantine = str_ends_with($normalizedDir, '/quarantine') || str_ends_with($normalizedDir, '/.quarantine');

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            $this->require(++$entries <= 20000, 'SCRATCH_ENTRIES');
            $this->require(! $entry->isLink(), 'SYMLINK_REFUSED');
            $name = $entry->getFilename();

            // Refuse active .env unless inside explicit quarantine
            if (! $inQuarantine) {
                $this->require(! preg_match('/^\.env(?:\.|$)/i', $name), 'ACTIVE_ARCHIVED_CONFIGURATION');
            }

            // Refuse stale framework caches only within bootstrap/cache (permitting legitimate config/services.php)
            if ($inBootstrapCache) {
                $this->require(! preg_match('/^(?:config|routes[^\/]*|services|packages)\.php$/i', $name), 'ACTIVE_STALE_FRAMEWORK_CACHE');
            }

            if ($entry->isDir()) {
                $this->privateMode($entry->getPathname(), true);
                $this->scanScratch($entry->getPathname(), $depth + 1, $entries);
            } else {
                $this->require($entry->isFile(), 'SCRATCH_SPECIAL_FILE');
                $this->privateMode($entry->getPathname(), false);
            }
        }
    }

    private function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new RuntimeException($reason);
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    // Filesystem warnings can contain submitted paths; keep CLI failures sanitized.
    set_error_handler(static function (): never {
        throw new RuntimeException('FILESYSTEM_READ_FAILURE');
    });
    try {
        $options = [];
        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                echo "Usage: php bin/recovery-preflight.php --manifest=<path> --manifest-sha256=<hex> ...\n";
                exit(0);
            }
            if (! preg_match('/^--([a-z0-9-]+)=(.+)$/sD', $argument, $match) || isset($options[$match[1]])) {
                throw new RuntimeException('INVOCATION');
            }
            $options[$match[1]] = $match[2];
        }

        $result = (new RecoveryInspection)->inspect($options);
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
        // Preflight inspection completed safely; bootstrap remains blocked until authorized.
        exit(2);
    } catch (Throwable $exception) {
        $reason = $exception instanceof RuntimeException && preg_match('/^[A-Z_]+$/D', $exception->getMessage())
            ? $exception->getMessage()
            : 'MALFORMED_OR_UNREADABLE_INPUT';
        echo json_encode(['status' => 'FAIL', 'bootstrap_authorized' => false, 'reason' => $reason], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
}
