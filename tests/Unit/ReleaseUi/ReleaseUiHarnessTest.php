<?php

declare(strict_types=1);

namespace Tests\Unit\ReleaseUi;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\ReleaseUi\ReleaseUiSession;

class ReleaseUiHarnessTest extends TestCase
{
    private ?string $tempDir = null;

    /** @var list<string> */
    private array $extraDirs = [];

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            ReleaseUiSession::cleanupStateDir($this->tempDir);
        }
        foreach ($this->extraDirs as $dir) {
            if (is_dir($dir)) {
                $files = scandir($dir);
                if (is_array($files)) {
                    foreach ($files as $f) {
                        if ($f !== '.' && $f !== '..' && is_file($dir.'/'.$f)) {
                            @unlink($dir.'/'.$f);
                        }
                    }
                }
                @rmdir($dir);
            }
        }
        parent::tearDown();
    }

    // ── Core security invariants ──

    public function test_state_directory_creation_enforces_0700_permissions(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();

        $this->assertDirectoryExists($this->tempDir);
        $stat = stat($this->tempDir);
        $this->assertNotFalse($stat);
        $this->assertSame(0700, $stat['mode'] & 0777);
        $this->assertSame(posix_getuid(), $stat['uid']);
    }

    public function test_secure_file_writing_enforces_0600_permissions(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();
        $filePath = ReleaseUiSession::writeSecureFile($this->tempDir, 'test.json', json_encode(['foo' => 'bar']));

        $this->assertFileExists($filePath);
        $stat = stat($filePath);
        $this->assertNotFalse($stat);
        $this->assertSame(0600, $stat['mode'] & 0777);
        $this->assertSame(posix_getuid(), $stat['uid']);
    }

    public function test_state_directory_verification_refuses_outside_paths(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing state directory outside authorized prefix');

        ReleaseUiSession::verifyStateDir('/etc');
    }

    public function test_schema_name_validation_enforces_strict_naming_regex(): void
    {
        $valid = 'accounting_p8_tmp_'.bin2hex(random_bytes(6));
        $this->assertSame(1, preg_match(ReleaseUiSession::SCHEMA_REGEX, $valid));

        $invalidNames = [
            'accounting_p8_tmp_2f13bbe960c8; DROP TABLE users;',
            'accounting_p8_tmp_short',
            'accounting_p8_tmp_2f13bbe960c8extra',
            'production_database',
            'information_schema',
        ];

        foreach ($invalidNames as $invalid) {
            $this->assertSame(0, preg_match(ReleaseUiSession::SCHEMA_REGEX, $invalid), "Expected failure for {$invalid}");
        }
    }

    public function test_process_identity_verification_refuses_unmatched_or_foreign_processes(): void
    {
        // PID 1 is init/systemd - current user does not own it (or it lacks expected tokens)
        $this->assertFalse(ReleaseUiSession::verifyProcessIdentity(1, ['php', '-S']));

        // Current PHP process has posix_getpid(), but does not match web server token
        $currentPid = posix_getpid();
        $this->assertFalse(ReleaseUiSession::verifyProcessIdentity($currentPid, ['php', '-S', '127.0.0.1:9999']));
        $this->assertTrue(ReleaseUiSession::verifyProcessIdentity($currentPid, [basename(PHP_BINARY)]));
    }

    public function test_secure_write_refuses_existing_file_and_symlink_without_changing_target(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();
        $target = ReleaseUiSession::writeSecureFile($this->tempDir, 'target', 'preserved');
        symlink($target, $this->tempDir.'/link');
        foreach (['target', 'link'] as $name) {
            try {
                ReleaseUiSession::writeSecureFile($this->tempDir, $name, 'overwritten');
                $this->fail('Existing file was replaced.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Refusing to replace', $error->getMessage());
            }
            $this->assertSame('preserved', file_get_contents($target));
        }
        unlink($this->tempDir.'/link');
    }

    public function test_process_identity_requires_whole_arguments(): void
    {
        $this->assertFalse(ReleaseUiSession::verifyProcessIdentity(posix_getpid(), ['phpu']));
        $this->assertFalse(ReleaseUiSession::verifyProcessIdentity(posix_getpid(), ['phpunit --filter']));
    }

    // ── Bug 1 fix: versioned PHP binary identity check ──

    public function test_process_identity_matches_versioned_php_binary(): void
    {
        // basename(PHP_BINARY) may be 'php8.4', 'php8.3', etc. — identity check must use
        // the actual binary name, not hardcoded 'php'.
        $currentPid = posix_getpid();
        $phpBase = basename(PHP_BINARY);

        // Must match when using the actual binary name
        $this->assertTrue(
            ReleaseUiSession::verifyProcessIdentity($currentPid, [$phpBase]),
            "Identity check should match actual PHP binary name: {$phpBase}"
        );

        // Must NOT match a hardcoded 'php' when binary is 'php8.4'
        if ($phpBase !== 'php') {
            $this->assertFalse(
                ReleaseUiSession::verifyProcessIdentity($currentPid, ['php', '-S', '127.0.0.1:9999']),
                "Identity check must not match 'php' when binary is '{$phpBase}'"
            );
        }
    }

    // ── Bug 3 fix: stale pointer recovery ──

    public function test_safe_write_pointer_recovers_from_stale_crashed_session(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();

        // Simulate a stale pointer from a crashed session with a dead PID
        $stalePointer = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release_ui_pointer_'.posix_getuid().'.json';
        $staleData = [
            'state_dir' => $this->tempDir,
            'owner_pid' => 2147483647, // Very high PID — almost certainly not running
            'session_token' => str_repeat('a', 64),
        ];

        // Clean up any leftover pointer from previous test runs
        if (is_file($stalePointer)) {
            @unlink($stalePointer);
        }

        // Create the stale pointer manually
        $oldUmask = umask(0077);
        file_put_contents($stalePointer, json_encode($staleData, JSON_THROW_ON_ERROR));
        umask($oldUmask);
        chmod($stalePointer, 0600);

        try {
            // safeWritePointer should detect dead owner and recover
            $newData = [
                'state_dir' => $this->tempDir,
                'owner_pid' => posix_getpid(),
                'session_token' => bin2hex(random_bytes(32)),
            ];
            $path = ReleaseUiSession::safeWritePointer($newData, [basename(PHP_BINARY)]);

            $this->assertFileExists($path);
            $written = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(posix_getpid(), $written['owner_pid']);
        } finally {
            if (is_file($stalePointer)) {
                @unlink($stalePointer);
            }
        }
    }

    public function test_safe_write_pointer_refuses_live_owner(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();

        $livePointer = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release_ui_pointer_'.posix_getuid().'.json';
        $liveData = [
            'state_dir' => $this->tempDir,
            'owner_pid' => posix_getpid(), // Current process — definitely alive
            'session_token' => str_repeat('b', 64),
        ];

        if (is_file($livePointer)) {
            @unlink($livePointer);
        }

        $oldUmask = umask(0077);
        file_put_contents($livePointer, json_encode($liveData, JSON_THROW_ON_ERROR));
        umask($oldUmask);
        chmod($livePointer, 0600);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Refusing to replace active session pointer');

            ReleaseUiSession::safeWritePointer([
                'state_dir' => $this->tempDir,
                'owner_pid' => posix_getpid(),
                'session_token' => bin2hex(random_bytes(32)),
            ], [basename(PHP_BINARY)]);
        } finally {
            if (is_file($livePointer)) {
                @unlink($livePointer);
            }
        }
    }

    // ── Bug 4 fix: idempotent stop signal ──

    public function test_stop_signal_is_idempotent_on_correct_token(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();
        $token = bin2hex(random_bytes(32));

        // First write succeeds
        $path1 = ReleaseUiSession::writeStopSignal($this->tempDir, $token);
        $this->assertFileExists($path1);
        $this->assertSame($token, file_get_contents($path1));

        // Second write with same token is idempotent — no exception
        $path2 = ReleaseUiSession::writeStopSignal($this->tempDir, $token);
        $this->assertSame($path1, $path2);
        $this->assertSame($token, file_get_contents($path2));
    }

    public function test_stop_signal_refuses_mismatched_token(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();
        $token1 = bin2hex(random_bytes(32));
        $token2 = bin2hex(random_bytes(32));

        ReleaseUiSession::writeStopSignal($this->tempDir, $token1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mismatched token');

        ReleaseUiSession::writeStopSignal($this->tempDir, $token2);
    }

    // ── Bug 5 fix: canonical path handling ──

    public function test_canonical_path_resolves_symlinks(): void
    {
        $realDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release_ui_canonical_test_'.bin2hex(random_bytes(4));
        $linkPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release_ui_canonical_link_'.bin2hex(random_bytes(4));

        mkdir($realDir, 0700);
        symlink($realDir, $linkPath);

        try {
            $this->assertSame(
                ReleaseUiSession::canonicalPath($realDir),
                ReleaseUiSession::canonicalPath($linkPath),
                'canonicalPath must resolve symlinks to the same real path'
            );
        } finally {
            @unlink($linkPath);
            @rmdir($realDir);
        }
    }

    public function test_owner_process_tokens_uses_actual_binary_name(): void
    {
        $tokens = ReleaseUiSession::ownerProcessTokens();
        $this->assertCount(1, $tokens);
        $this->assertSame(basename(PHP_BINARY), $tokens[0]);
    }

    // ── Cleanup refusal and ownership verification ──

    public function test_cleanup_refuses_dead_owner_and_preserves_state_and_legacy_files(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();
        $sandbox = $this->tempDir;
        $sessionDir = $sandbox.'/release_ui_'.bin2hex(random_bytes(8));
        mkdir($sessionDir, 0700);
        $this->extraDirs[] = $sessionDir;
        $pointer = ['state_dir' => $sessionDir, 'owner_pid' => 2147483647,
            'session_token' => str_repeat('a', 64), 'schema' => 'accounting_p8_tmp_123456789abc'];
        $pointerFile = $sandbox.'/release_ui_pointer_'.posix_getuid().'.json';
        file_put_contents($pointerFile, json_encode($pointer, JSON_THROW_ON_ERROR));
        chmod($pointerFile, 0600);
        file_put_contents($sessionDir.'/state.json', json_encode($pointer, JSON_THROW_ON_ERROR));
        chmod($sessionDir.'/state.json', 0600);
        $legacy = $sandbox.'/release_ui_state.json';
        file_put_contents($legacy, 'preserved');
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2).'/Support/ReleaseUi/cleanup.php'],
            env: ['TMPDIR' => $sandbox, 'TMP' => $sandbox, 'TEMP' => $sandbox],
        );
        $process->run();
        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('ownership could not be verified', $process->getErrorOutput());
        $this->assertFileExists($pointerFile);
        $this->assertFileExists($sessionDir.'/state.json');
        $this->assertFileDoesNotExist($sessionDir.'/stop.signal');
        $this->assertSame('preserved', file_get_contents($legacy));
        unlink($sessionDir.'/state.json');
        rmdir($sessionDir);
    }

    // ── Process identity: PID boundary and special values ──

    public function test_process_identity_refuses_pid_zero_and_negative(): void
    {
        $this->assertFalse(ReleaseUiSession::verifyProcessIdentity(0, [basename(PHP_BINARY)]));
        $this->assertFalse(ReleaseUiSession::verifyProcessIdentity(-1, [basename(PHP_BINARY)]));
        $this->assertFalse(ReleaseUiSession::verifyProcessIdentity(1, [basename(PHP_BINARY)]));
    }

    public function test_process_identity_refuses_nonexistent_high_pid(): void
    {
        // PID 2^31-1 is extremely unlikely to exist
        $this->assertFalse(ReleaseUiSession::verifyProcessIdentity(2147483647, [basename(PHP_BINARY)]));
    }

    // ── Ownership refusal: state dir outside prefix, wrong perms ──

    public function test_state_dir_verification_refuses_wrong_permissions(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release_ui_'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        chmod($dir, 0755);
        $this->extraDirs[] = $dir;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid owner or permissions');

        ReleaseUiSession::verifyStateDir($dir);
    }

    public function test_state_dir_verification_refuses_non_hex_suffix(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release_ui_not_hex_values_';
        mkdir($dir, 0700);
        $this->extraDirs[] = $dir;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing state directory outside authorized prefix');

        ReleaseUiSession::verifyStateDir($dir);
    }

    public function test_safe_write_pointer_detects_pid_reuse_when_start_time_mismatches(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();

        // Simulate a pointer left by a process that died, whose PID was subsequently reused
        // by the current process, but with a different start time
        $stalePointer = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release_ui_pointer_'.posix_getuid().'.json';
        $staleData = [
            'state_dir' => $this->tempDir,
            'owner_pid' => posix_getpid(),
            'owner_start_time' => 1, // Mismatched start time indicates PID reuse
            'session_token' => str_repeat('a', 64),
        ];

        if (is_file($stalePointer)) {
            @unlink($stalePointer);
        }

        $oldUmask = umask(0077);
        file_put_contents($stalePointer, json_encode($staleData, JSON_THROW_ON_ERROR));
        umask($oldUmask);
        chmod($stalePointer, 0600);

        try {
            // safeWritePointer should detect that start_time mismatches actual process start_time,
            // conclude PID reuse occurred (original supervisor is dead), and reclaim the pointer
            $newData = [
                'state_dir' => $this->tempDir,
                'owner_pid' => posix_getpid(),
                'owner_start_time' => ReleaseUiSession::getProcessStartTime(posix_getpid()),
                'session_token' => bin2hex(random_bytes(32)),
            ];
            $path = ReleaseUiSession::safeWritePointer($newData, [basename(PHP_BINARY)]);

            $this->assertFileExists($path);
            $written = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($newData['owner_start_time'], $written['owner_start_time']);
        } finally {
            if (is_file($stalePointer)) {
                @unlink($stalePointer);
            }
        }
    }

    public function test_safe_write_pointer_refuses_live_owner_with_matching_start_time(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();

        $livePointer = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release_ui_pointer_'.posix_getuid().'.json';
        $currentPid = posix_getpid();
        $currentStartTime = ReleaseUiSession::getProcessStartTime($currentPid);

        $liveData = [
            'state_dir' => $this->tempDir,
            'owner_pid' => $currentPid,
            'owner_start_time' => $currentStartTime,
            'session_token' => str_repeat('b', 64),
        ];

        if (is_file($livePointer)) {
            @unlink($livePointer);
        }

        $oldUmask = umask(0077);
        file_put_contents($livePointer, json_encode($liveData, JSON_THROW_ON_ERROR));
        umask($oldUmask);
        chmod($livePointer, 0600);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Refusing to replace active session pointer');

            ReleaseUiSession::safeWritePointer([
                'state_dir' => $this->tempDir,
                'owner_pid' => $currentPid,
                'owner_start_time' => $currentStartTime,
                'session_token' => bin2hex(random_bytes(32)),
            ], [basename(PHP_BINARY)]);
        } finally {
            if (is_file($livePointer)) {
                @unlink($livePointer);
            }
        }
    }

    public function test_read_pointer_refuses_invalid_permissions(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();
        $pointerPath = $this->tempDir.'/test_pointer.json';

        file_put_contents($pointerPath, json_encode(['foo' => 'bar']));
        chmod($pointerPath, 0644); // Insecure: world-readable

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not owned by current user');

        ReleaseUiSession::readPointer($pointerPath);
    }

    public function test_cleanup_refuses_when_owner_start_time_indicates_pid_reuse(): void
    {
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();
        $sandbox = $this->tempDir;
        $sessionDir = $sandbox.'/release_ui_'.bin2hex(random_bytes(8));
        mkdir($sessionDir, 0700);
        $this->extraDirs[] = $sessionDir;

        // Simulate pointer pointing to current PID, but with bogus start time (PID reuse)
        $pointer = [
            'state_dir' => $sessionDir,
            'owner_pid' => posix_getpid(),
            'owner_start_time' => 1, // Will not match current process start time
            'session_token' => str_repeat('a', 64),
            'schema' => 'accounting_p8_tmp_123456789abc',
        ];

        $pointerFile = $sandbox.'/release_ui_pointer_'.posix_getuid().'.json';
        file_put_contents($pointerFile, json_encode($pointer, JSON_THROW_ON_ERROR));
        chmod($pointerFile, 0600);
        file_put_contents($sessionDir.'/state.json', json_encode($pointer, JSON_THROW_ON_ERROR));
        chmod($sessionDir.'/state.json', 0600);

        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2).'/Support/ReleaseUi/cleanup.php'],
            env: ['TMPDIR' => $sandbox, 'TMP' => $sandbox, 'TEMP' => $sandbox],
        );
        $process->run();

        // Must refuse and preserve state for inspection
        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('ownership could not be verified', $process->getErrorOutput());
        $this->assertFileExists($pointerFile);
        $this->assertFileExists($sessionDir.'/state.json');
        $this->assertFileDoesNotExist($sessionDir.'/stop.signal');

        unlink($sessionDir.'/state.json');
        rmdir($sessionDir);
    }

    // ── Integration smoke: disposable start/stop on owned fixture ──

    public function test_concurrent_startups_preserve_one_live_session_pointer(): void
    {
        $this->assertNull(ReleaseUiSession::readPointer(), 'Do not disturb an existing UI session.');
        $this->tempDir = ReleaseUiSession::createPrivateStateDir();
        $script = <<<'PHP'
<?php
require $argv[1];
use Tests\Support\ReleaseUi\ReleaseUiSession;
$dir = $argv[2];
$slot = $argv[3];
file_put_contents($dir.'/ready-'.$slot, 'ready');
$deadline = microtime(true) + 10;
while (! is_file($dir.'/go')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Start barrier timeout'); }
    usleep(1000);
}
try {
    ReleaseUiSession::safeWritePointer([
        'state_dir' => $dir, 'owner_pid' => getmypid(),
        'owner_start_time' => ReleaseUiSession::getProcessStartTime(getmypid()),
        'slot' => $slot,
    ], [basename(PHP_BINARY)]);
    file_put_contents($dir.'/result-'.$slot, 'winner');
} catch (RuntimeException $error) {
    if (! str_contains($error->getMessage(), 'still alive')) { throw $error; }
    file_put_contents($dir.'/result-'.$slot, 'refused');
}
while (! is_file($dir.'/release')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Release barrier timeout'); }
    usleep(1000);
}
PHP;
        $path = ReleaseUiSession::writeSecureFile($this->tempDir, 'pointer-race.php', $script);
        ReleaseUiSession::writePointer(['owner_pid' => PHP_INT_MAX, 'state_dir' => $this->tempDir]);
        $processes = [];
        try {
            foreach (['a', 'b'] as $slot) {
                $process = new Process([PHP_BINARY, $path, dirname(__DIR__, 3).'/vendor/autoload.php', $this->tempDir, $slot]);
                $process->setTimeout(15)->start();
                $processes[] = $process;
            }
            foreach (['ready-a', 'ready-b'] as $marker) {
                $this->waitForMarker($this->tempDir.'/'.$marker, $processes);
            }
            touch($this->tempDir.'/go');
            foreach (['result-a', 'result-b'] as $marker) {
                $this->waitForMarker($this->tempDir.'/'.$marker, $processes);
            }
            $results = [file_get_contents($this->tempDir.'/result-a'), file_get_contents($this->tempDir.'/result-b')];
            sort($results);
            $this->assertSame(['refused', 'winner'], $results);
            $pointer = ReleaseUiSession::readPointer();
            $this->assertIsArray($pointer);
            $this->assertArrayHasKey('slot', $pointer);
            $this->assertSame('winner', file_get_contents($this->tempDir.'/result-'.$pointer['slot']));
        } finally {
            touch($this->tempDir.'/release');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
        }
    }

    /** @param list<Process> $processes */
    private function waitForMarker(string $path, array $processes): void
    {
        $deadline = microtime(true) + 5;
        while (! is_file($path)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('Pointer worker exited: '.$process->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                $this->fail('Pointer worker barrier timed out.');
            }
            usleep(1000);
        }
    }

    public function test_integration_serve_starts_and_cleanup_stops_on_disposable_fixture(): void
    {
        // This test verifies the complete serve.php → cleanup.php lifecycle
        // on a real disposable schema. It requires MariaDB on port 3342.
        $root = dirname(__DIR__, 3);

        $env = [
            'APP_ENV' => 'testing',
            'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            'PHASE8_TEST_DB_HOST' => '127.0.0.1',
            'PHASE8_TEST_DB_PORT' => '3342',
            'PHASE8_TEST_DB_USERNAME' => 'test_runner',
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => '1',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
        ];

        // Verify MariaDB is reachable before attempting the integration test
        $dbCheck = @fsockopen('127.0.0.1', 3342);
        if (! is_resource($dbCheck)) {
            $this->markTestSkipped('MariaDB not reachable on port 3342 — skipping integration smoke test.');
        }
        fclose($dbCheck);

        // Start serve.php as a background process
        $serveProcess = new Process(
            [PHP_BINARY, $root.'/tests/Support/ReleaseUi/serve.php'],
            $root,
            $env
        );
        $serveProcess->setTimeout(45);
        $serveProcess->start();

        // Wait for SERVER_STARTED_OK (up to 30 seconds for migration + seeding)
        $started = false;
        $output = '';
        for ($wait = 0; $wait < 60; $wait++) {
            usleep(500000);
            $output = $serveProcess->getOutput();
            if (str_contains($output, 'SERVER_STARTED_OK')) {
                $started = true;
                break;
            }
            if (! $serveProcess->isRunning()) {
                break;
            }
        }

        if (! $started) {
            if ($serveProcess->isRunning()) {
                $serveProcess->stop(2, SIGTERM);
            }
            $this->fail(
                "serve.php did not emit SERVER_STARTED_OK within 30s.\n"
                ."STDOUT: {$output}\n"
                ."STDERR: {$serveProcess->getErrorOutput()}"
            );
        }

        // Extract port and verify connectivity
        preg_match('/URL: http:\/\/127\.0\.0\.1:(\d+)/', $output, $portMatch);
        $this->assertNotEmpty($portMatch, 'Could not extract port from serve.php output');
        $port = (int) $portMatch[1];

        $sock = @fsockopen('127.0.0.1', $port);
        $this->assertIsResource($sock, "Web server not listening on port {$port}");
        fclose($sock);

        $httpContext = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $login = file_get_contents("http://127.0.0.1:{$port}/login", false, $httpContext);
        $loginHeaders = $http_response_header;

        // Run cleanup.php to request shutdown
        $cleanupProcess = new Process(
            [PHP_BINARY, $root.'/tests/Support/ReleaseUi/cleanup.php'],
            $root,
            $env
        );
        $cleanupProcess->setTimeout(20);
        $cleanupProcess->run();

        // Wait for serve.php to finish
        for ($wait = 0; $wait < 20; $wait++) {
            usleep(500000);
            if (! $serveProcess->isRunning()) {
                break;
            }
        }

        if ($serveProcess->isRunning()) {
            $serveProcess->stop(2, SIGTERM);
        }

        $this->assertSame(
            0,
            $cleanupProcess->getExitCode(),
            "cleanup.php failed: {$cleanupProcess->getErrorOutput()}"
        );

        $this->assertSame(0, $serveProcess->getExitCode(), $serveProcess->getErrorOutput());
        $this->assertIsString($login);
        $this->assertMatchesRegularExpression('/^HTTP\/\S+ (200|302)\b/', $loginHeaders[0] ?? '');

        // Verify pointer file was cleaned up
        $pointerFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release_ui_pointer_'.posix_getuid().'.json';
        // Allow brief race — check with a small delay
        usleep(200000);
        clearstatcache();
        $this->assertFileDoesNotExist($pointerFile, 'Session pointer should be cleaned up after shutdown');
    }
}
