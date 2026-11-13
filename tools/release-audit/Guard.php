<?php

declare(strict_types=1);

namespace ReleaseAudit;

use RuntimeException;
use Tests\Support\DisposableMariaDbSchema;

final class Guard
{
    public static function implementationDigest(): string
    {
        $files = [];
        foreach (['audit.php', 'Guard.php', 'Fingerprint.php', 'compare.php'] as $name) {
            $digest = hash_file('sha256', __DIR__.'/'.$name);
            if (! is_string($digest)) {
                self::refuse();
            }
            $files[$name] = $digest;
        }

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    public static function refuse(): never
    {
        throw new RuntimeException('AUDIT_REFUSED');
    }

    public static function path(string $path, bool $file): void
    {
        if (! str_starts_with($path, '/') || realpath($path) !== $path) {
            self::refuse();
        }
        $part = '';
        foreach (explode('/', trim($path, '/')) as $name) {
            $part .= '/'.$name;
            if (is_link($part) || in_array($name, ['public', 'public_html', '.', '..'], true)) {
                self::refuse();
            }
        }
        if (($file ? ! is_file($path) : ! is_dir($path)) || (fileperms($path) & 0777) !== ($file ? 0600 : 0700)
            || fileowner($path) !== posix_geteuid()) {
            self::refuse();
        }
    }

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    public static function preflight(array $args, string $root): array
    {
        if (PHP_SAPI !== 'cli' || count($args) !== 6 || $args[1] !== '--disposable-test') {
            self::refuse();
        }
        [$script, $mode, $control, $pin, $code, $output] = $args;
        if (! preg_match('/^[a-f0-9]{64}$/D', $pin) || ! preg_match('/^[a-f0-9]{40}$/D', $code)) {
            self::refuse();
        }
        self::path(dirname($control), false);
        self::path($control, true);
        self::path(dirname($output), false);
        if (! preg_match('/^audit-[a-f0-9]{24}\.json$/D', basename($output))) {
            self::refuse();
        }
        if (file_exists($output) || is_link($output) || ! hash_equals($pin, hash_file('sha256', $control))) {
            self::refuse();
        }
        $head = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
        $dirty = shell_exec('git -C '.escapeshellarg($root).' status --porcelain --untracked-files=normal 2>/dev/null') ?? '';
        if ($head !== $code || trim($dirty) !== '') {
            self::refuse();
        }
        $c = json_decode((string) file_get_contents($control), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($c) || ($c['mode'] ?? null) !== 'disposable-test' || ($c['host'] ?? null) !== '127.0.0.1'
            || ($c['port'] ?? null) !== 3342 || ($c['user'] ?? null) !== 'test_runner' || ($c['password'] ?? null) !== ''
            || ! is_string($c['schema'] ?? null) || ! preg_match('/^accounting_p8_tmp_[a-f0-9]{12}$/D', $c['schema'])
            || ! is_string($c['server_hostname'] ?? null) || $c['server_hostname'] === ''
            || ! is_int($c['server_id'] ?? null) || $c['server_id'] < 1) {
            self::refuse();
        }

        $limits = [
            'max_execution_seconds' => 60,
            'max_total_rows' => 500000,
            'max_total_bytes' => 104857600,
            'max_memory_bytes' => 67108864,
        ];
        if (array_key_exists('limits', $c)) {
            if (! is_array($c['limits'])) {
                self::refuse();
            }
            foreach ($c['limits'] as $k => $v) {
                if (! array_key_exists($k, $limits) || ! is_int($v)) {
                    self::refuse();
                }
                if ($k === 'max_execution_seconds' && ($v < 1 || $v > 300)) {
                    self::refuse();
                }
                if ($k === 'max_total_rows' && ($v < 1 || $v > 5000000)) {
                    self::refuse();
                }
                if ($k === 'max_total_bytes' && ($v < 1024 || $v > 1073741824)) {
                    self::refuse();
                }
                if ($k === 'max_memory_bytes' && ($v < 1048576 || $v > 268435456)) {
                    self::refuse();
                }
                $limits[$k] = $v;
            }
        }
        $allowedKeys = ['mode', 'host', 'port', 'user', 'password', 'schema', 'server_hostname', 'server_id', 'limits'];
        foreach (array_keys($c) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                self::refuse();
            }
        }
        $c['limits'] = $limits;

        // Cryptographic runner capability is mandatory before opening a socket.
        require_once $root.'/vendor/autoload.php';
        DisposableMariaDbSchema::assertPrimarySchema($c['schema']);

        return $c;
    }
}
