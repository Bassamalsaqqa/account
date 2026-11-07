<?php

declare(strict_types=1);

namespace Tests\Support\ReleaseUi;

require_once __DIR__.'/../../../vendor/autoload.php';

// Only the live supervisor owns the DisposableMariaDbSchema instance. A schema
// name in a state file is never authority to drop a database or kill a server.
try {
    $pointer = ReleaseUiSession::readPointer();
    if ($pointer === null) {
        fwrite(STDOUT, "No active Release UI session pointer. Legacy state is left untouched.\n");
        exit(0);
    }

    $stateDir = (string) ($pointer['state_dir'] ?? '');
    ReleaseUiSession::verifyStateDir($stateDir);
    $statePath = $stateDir.'/state.json';
    $state = ReleaseUiSession::readPointer($statePath);
    $token = (string) ($pointer['session_token'] ?? '');
    $ownerPid = (int) ($pointer['owner_pid'] ?? 0);

    // Bug 5 fix: use canonical paths for token matching so symlinks and relative paths
    // don't cause false negatives. Also use basename(PHP_BINARY) for versioned binaries.
    $serveScriptAbsolute = ReleaseUiSession::canonicalPath(__DIR__.'/serve.php');
    $serveScriptRelative = 'tests/Support/ReleaseUi/serve.php';

    $ownerMatches = ReleaseUiSession::verifyProcessIdentity($ownerPid, [basename(PHP_BINARY), $serveScriptAbsolute])
        || ReleaseUiSession::verifyProcessIdentity($ownerPid, [basename(PHP_BINARY), $serveScriptRelative]);

    // Bug 5 fix: canonicalize both sides of the CWD comparison for symlinked project dirs
    $cwd = @readlink("/proc/{$ownerPid}/cwd");
    $expectedCwd = ReleaseUiSession::canonicalPath(dirname(__DIR__, 3));
    if ($cwd === false || ReleaseUiSession::canonicalPath($cwd) !== $expectedCwd) {
        $ownerMatches = false;
    }

    // Bug 3 fix: verify process start time if recorded in pointer, preventing PID reuse hijacking
    $recordedStartTime = isset($pointer['owner_start_time']) ? (int) $pointer['owner_start_time'] : null;
    if ($recordedStartTime !== null) {
        $actualStartTime = ReleaseUiSession::getProcessStartTime($ownerPid);
        if ($actualStartTime === null || $actualStartTime !== $recordedStartTime) {
            $ownerMatches = false;
        }
    }

    if ($state === null || ! preg_match('/^[a-f0-9]{64}$/D', $token)
        || ! hash_equals($token, (string) ($state['session_token'] ?? ''))
        || ($state['owner_pid'] ?? null) !== $ownerPid
        || ($state['state_dir'] ?? null) !== $stateDir
        || ! $ownerMatches) {
        throw new \RuntimeException('Session ownership could not be verified; state preserved for inspection.');
    }

    // Bug 4 fix: use idempotent writeStopSignal instead of exclusive writeSecureFile
    ReleaseUiSession::writeStopSignal($stateDir, $token);

    for ($attempt = 0; $attempt < 100; $attempt++) {
        usleep(100000);
        clearstatcache();
        if (is_file($stateDir.'/cleanup.failed')) {
            throw new \RuntimeException('Supervisor cleanup failed; diagnostic state preserved.');
        }
        if (! file_exists($stateDir) && ! file_exists(ReleaseUiSession::activeSessionPointerFile())) {
            fwrite(STDOUT, "Supervisor completed owned session cleanup.\n");
            exit(0);
        }
    }
    throw new \RuntimeException('Supervisor cleanup was not confirmed within ten seconds; state preserved.');
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
