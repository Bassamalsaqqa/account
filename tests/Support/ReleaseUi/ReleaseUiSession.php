<?php

declare(strict_types=1);

namespace Tests\Support\ReleaseUi;

use RuntimeException;

final class ReleaseUiSession
{
    public const string SCHEMA_REGEX = '/^accounting_p8_tmp_[a-f0-9]{12}$/D';

    /**
     * Create a private unique state directory with 0700 permissions.
     */
    public static function createPrivateStateDir(): string
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release_ui_'.bin2hex(random_bytes(8));
        if (! mkdir($base, 0700, true)) {
            throw new RuntimeException("Failed to create private state directory at {$base}");
        }

        // Ensure permissions are strictly 0700 regardless of umask
        chmod($base, 0700);

        $stat = stat($base);
        if ($stat === false || ($stat['mode'] & 0777) !== 0700 || $stat['uid'] !== posix_getuid()) {
            throw new RuntimeException("Insecure state directory permissions on {$base}");
        }

        return $base;
    }

    /**
     * Write a secure file within a private state directory with 0600 permissions.
     */
    public static function writeSecureFile(string $dir, string $filename, string $content): string
    {
        self::verifyStateDir($dir);

        $path = $dir.DIRECTORY_SEPARATOR.basename($filename);
        self::createExclusiveFile($path, $content);

        return $path;
    }

    /**
     * Path to the user-isolated active session pointer file.
     */
    public static function activeSessionPointerFile(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'release_ui_pointer_'.posix_getuid().'.json';
    }

    /**
     * Write active session pointer file with 0600 permissions.
     *
     * @param  array<string, mixed>  $data
     */
    public static function writePointer(array $data): string
    {
        $pointer = self::activeSessionPointerFile();
        self::createExclusiveFile($pointer, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $pointer;
    }

    /**
     * Safely write the pointer file, recovering from stale pointers left by crashed sessions.
     *
     * If the pointer file already exists, verifies it belongs to a dead session (owner PID
     * is no longer running or does not match expected identity, or PID reuse is detected
     * via process start time) before replacing it.
     * Never replaces a pointer belonging to a live, verified owner — fail closed.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $ownerTokens  Expected /proc/pid/cmdline tokens for a live owner
     */
    public static function safeWritePointer(array $data, array $ownerTokens): string
    {
        return self::withPointerLock(fn (): string => self::replaceStalePointer($data, $ownerTokens));
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $ownerTokens
     */
    private static function replaceStalePointer(array $data, array $ownerTokens): string
    {
        $pointer = self::activeSessionPointerFile();

        if (is_file($pointer) && ! is_link($pointer)) {
            // Existing pointer — check if the owner session is still alive
            $existing = self::readPointer($pointer);
            if ($existing !== null) {
                $existingPid = (int) ($existing['owner_pid'] ?? 0);
                $isAlive = false;

                if ($existingPid > 1) {
                    $recordedStartTime = isset($existing['owner_start_time']) ? (int) $existing['owner_start_time'] : null;
                    $actualStartTime = self::getProcessStartTime($existingPid);

                    // If recorded start time exists and mismatches actual start time, PID was reused
                    if ($recordedStartTime !== null && $actualStartTime !== null && $recordedStartTime !== $actualStartTime) {
                        $isAlive = false; // PID reuse detected! Original supervisor is dead.
                    } else {
                        $isAlive = self::verifyProcessIdentity($existingPid, $ownerTokens);
                    }
                }

                if ($isAlive) {
                    throw new RuntimeException(
                        "Refusing to replace active session pointer: owner PID {$existingPid} is still alive. "
                        .'Use cleanup.php to stop the running session first.'
                    );
                }
            }

            // Owner is dead or unverifiable — safe to reclaim pointer file.
            // Notice: We NEVER drop or touch any schema mentioned in $existing['schema'].
            // Only the in-memory DisposableMariaDbSchema owner drops its schema.
            // State is left intact in state_dir for inspection.
            $stat = stat($pointer);
            if ($stat === false || $stat['uid'] !== posix_getuid()) {
                throw new RuntimeException("Stale pointer file {$pointer} is not owned by current user.");
            }
            @unlink($pointer);
        }

        return self::writePointer($data);
    }

    /** @template T
     * @param  callable(): T  $operation
     * @return T
     */
    private static function withPointerLock(callable $operation): mixed
    {
        // Never unlink this lock: every supervisor must lock the same inode.
        $path = self::activeSessionPointerFile().'.lock';
        $oldUmask = umask(0077);
        try {
            $handle = @fopen($path, 'c+');
        } finally {
            umask($oldUmask);
        }
        if ($handle === false) {
            throw new RuntimeException('Cannot open session pointer lock.');
        }
        try {
            clearstatcache(true, $path);
            $stat = fstat($handle);
            $pathStat = lstat($path);
            if ($stat === false || $pathStat === false || is_link($path)
                || $stat['uid'] !== posix_getuid() || ($stat['mode'] & 0777) !== 0600
                || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
                || $stat['ino'] !== $pathStat['ino'] || $stat['dev'] !== $pathStat['dev']) {
                throw new RuntimeException('Unsafe session pointer lock.');
            }
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire session pointer lock.');
            }
            try {
                return $operation();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    public static function removeOwnedPointer(string $dir, ?string $pointerPath = null): void
    {
        self::withPointerLock(static function () use ($dir, $pointerPath): void {
            $pointer = $pointerPath ?? self::activeSessionPointerFile();
            $pointerData = self::readPointer($pointer);
            if (($pointerData['state_dir'] ?? null) === $dir && is_file($pointer) && ! is_link($pointer)) {
                if (! unlink($pointer)) {
                    throw new RuntimeException('Cannot remove owned session pointer.');
                }
            }
        });
    }

    /**
     * Retrieve process start time in clock ticks since system boot from /proc/[pid]/stat.
     * Returns null if unavailable or invalid.
     */
    public static function getProcessStartTime(int $pid): ?int
    {
        if ($pid <= 1) {
            return null;
        }

        $statFile = "/proc/{$pid}/stat";
        if (! is_file($statFile)) {
            return null;
        }

        $stat = @file_get_contents($statFile);
        if ($stat === false || $stat === '') {
            return null;
        }

        $lastParen = strrpos($stat, ')');
        if ($lastParen === false) {
            return null;
        }

        $rest = trim(substr($stat, $lastParen + 1));
        $fields = explode(' ', $rest);

        // Field 22 in /proc/[pid]/stat is starttime (index 19 in $rest because fields 0..2 are before or include comm)
        if (isset($fields[19]) && ctype_digit($fields[19])) {
            return (int) $fields[19];
        }

        return null;
    }

    private static function createExclusiveFile(string $path, string $content): void
    {
        $oldUmask = umask(0077);
        try {
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                throw new RuntimeException("Refusing to replace existing file: {$path}");
            }
            try {
                if (fwrite($handle, $content) !== strlen($content) || ! fflush($handle)) {
                    throw new RuntimeException("Failed to write private file: {$path}");
                }
            } finally {
                fclose($handle);
            }
        } finally {
            umask($oldUmask);
        }
    }

    /**
     * Read and validate active session pointer file.
     *
     * @phpstan-impure
     *
     * @return array<string, mixed>|null
     */
    public static function readPointer(?string $pointerPath = null): ?array
    {
        $path = $pointerPath ?? self::activeSessionPointerFile();
        if (! is_file($path) || is_link($path)) {
            return null;
        }

        $stat = stat($path);
        if ($stat === false || $stat['uid'] !== posix_getuid() || ($stat['mode'] & 0777) !== 0600) {
            throw new RuntimeException("Session pointer {$path} is not owned by current user.");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Verify that a process belongs to current user and its commandline matches expected tokens.
     *
     * Token matching uses canonical paths (realpath) for any token that looks like a filesystem
     * path, so that symlinked or relative-path launches still match.
     *
     * @param  list<string>  $expectedTokens
     */
    public static function verifyProcessIdentity(int $pid, array $expectedTokens): bool
    {
        if ($pid <= 1) {
            return false;
        }

        $procDir = "/proc/{$pid}";
        if (! is_dir($procDir)) {
            return false;
        }

        $stat = stat($procDir);
        if ($stat === false || $stat['uid'] !== posix_getuid()) {
            return false;
        }

        $cmdlineFile = "{$procDir}/cmdline";
        if (! is_file($cmdlineFile)) {
            return false;
        }

        $cmdline = file_get_contents($cmdlineFile);
        if ($cmdline === false || $cmdline === '') {
            return false;
        }

        $args = explode("\0", $cmdline);
        if (end($args) === '') {
            array_pop($args);
        }

        $executable = @readlink($procDir.'/exe');
        $procCwd = @readlink($procDir.'/cwd');

        // Build list of valid argument 0 candidates for process matching
        $arg0Candidates = [$args[0] ?? '', basename($args[0] ?? '')];
        if ($executable !== false) {
            $arg0Candidates[] = $executable;
            $arg0Candidates[] = basename($executable);
            if ($executable === realpath(PHP_BINARY)) {
                $arg0Candidates[] = basename(PHP_BINARY);
                $arg0Candidates[] = PHP_BINARY;
            }
        }

        // Canonicalize any path-like arguments for comparison, resolving relative paths against process CWD
        $canonicalArgs = array_map(static function (string $arg) use ($procCwd): string {
            if (str_contains($arg, DIRECTORY_SEPARATOR) || str_contains($arg, '/')) {
                if ($procCwd !== false && ! str_starts_with($arg, '/')) {
                    $real = realpath($procCwd.DIRECTORY_SEPARATOR.$arg);
                    if ($real !== false) {
                        return $real;
                    }
                }
                $real = realpath($arg);
                if ($real !== false) {
                    return $real;
                }
            }

            return $arg;
        }, $args);

        foreach ($expectedTokens as $token) {
            // Canonicalize the expected token too
            $canonicalToken = $token;
            if (str_contains($token, DIRECTORY_SEPARATOR) || str_contains($token, '/')) {
                $real = realpath($token);
                if ($real !== false) {
                    $canonicalToken = $real;
                }
            }

            $matched = in_array($token, $args, true)
                || in_array($canonicalToken, $args, true)
                || in_array($token, $canonicalArgs, true)
                || in_array($canonicalToken, $canonicalArgs, true)
                || in_array($token, $arg0Candidates, true);

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify that directory is inside temp, starts with release_ui_, is owned by UID with 0700.
     */
    public static function verifyStateDir(string $dir): void
    {
        $real = realpath($dir);
        if ($real === false || ! is_dir($real) || is_link($dir)) {
            throw new RuntimeException("Invalid state directory {$dir}");
        }

        $expectedPrefix = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'release_ui_';
        if (! str_starts_with($real, $expectedPrefix) || dirname($real) !== realpath(sys_get_temp_dir()) || ! preg_match('/^release_ui_[a-f0-9]{16}$/D', basename($real))) {
            throw new RuntimeException("Refusing state directory outside authorized prefix: {$real}");
        }

        $stat = stat($real);
        if ($stat === false || $stat['uid'] !== posix_getuid() || ($stat['mode'] & 0777) !== 0700) {
            throw new RuntimeException("State directory {$real} has invalid owner or permissions.");
        }
    }

    /**
     * Write a stop signal file idempotently. If the signal file already exists with the
     * correct token, this is a no-op. This prevents cleanup.php from failing on retry.
     */
    public static function writeStopSignal(string $dir, string $token): string
    {
        self::verifyStateDir($dir);

        $path = $dir.DIRECTORY_SEPARATOR.'stop.signal';

        // If stop signal already exists, verify its content is the correct token
        if (is_file($path) && ! is_link($path)) {
            $stat = stat($path);
            if ($stat === false || $stat['uid'] !== posix_getuid() || ($stat['mode'] & 0777) !== 0600) {
                throw new RuntimeException("Existing stop signal file has invalid owner or permissions: {$path}");
            }

            $existing = file_get_contents($path);
            if ($existing !== false && hash_equals($token, trim($existing))) {
                // Already signaled with correct token — idempotent success
                return $path;
            }

            // Wrong token — refuse (do not overwrite a signal from a different session)
            throw new RuntimeException("Stop signal file exists with mismatched token: {$path}");
        }

        try {
            self::createExclusiveFile($path, $token);
        } catch (RuntimeException $collisionError) {
            // If another caller created the file concurrently, verify it has the expected token
            if (is_file($path) && ! is_link($path)) {
                $stat = stat($path);
                if ($stat !== false && $stat['uid'] === posix_getuid() && ($stat['mode'] & 0777) === 0600) {
                    $existing = file_get_contents($path);
                    if ($existing !== false && hash_equals($token, trim($existing))) {
                        return $path;
                    }
                }
            }
            throw $collisionError;
        }

        return $path;
    }

    /**
     * Canonicalize a path for comparison, handling symlinks.
     * Returns the resolved real path, or the original if resolution fails.
     */
    public static function canonicalPath(string $path): string
    {
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    /**
     * Return the expected owner process identity tokens, using the actual PHP binary basename.
     *
     * @return list<string>
     */
    public static function ownerProcessTokens(): array
    {
        return [basename(PHP_BINARY)];
    }

    /**
     * Clean up verified state directory and remove pointer file.
     */
    public static function cleanupStateDir(string $dir, ?string $pointerPath = null): void
    {
        try {
            self::verifyStateDir($dir);
        } catch (\Throwable) {
            return;
        }

        $files = scandir($dir);
        if (is_array($files)) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $path = $dir.DIRECTORY_SEPARATOR.$file;
                if (is_file($path) && ! is_link($path)) {
                    $stat = stat($path);
                    if ($stat !== false && $stat['uid'] === posix_getuid()) {
                        @unlink($path);
                    }
                }
            }
        }

        @rmdir($dir);

        self::removeOwnedPointer($dir, $pointerPath);
    }
}
