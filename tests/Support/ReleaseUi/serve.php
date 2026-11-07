<?php

declare(strict_types=1);

namespace Tests\Support\ReleaseUi;

require_once __DIR__.'/../../../vendor/autoload.php';

use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;

// Ensure test administrative environment for DisposableMariaDbSchema
putenv('APP_ENV=testing');
putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
putenv('PHASE8_TEST_DB_PORT=3342');
putenv('PHASE8_TEST_DB_USERNAME=test_runner');
putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');
$_ENV['APP_ENV'] = 'testing';
$_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';
$_ENV['PHASE8_TEST_DB_HOST'] = '127.0.0.1';
$_ENV['PHASE8_TEST_DB_PORT'] = '3342';
$_ENV['PHASE8_TEST_DB_USERNAME'] = 'test_runner';
$_ENV['PHASE8_TEST_DB_EMPTY_PASSWORD'] = '1';

$root = dirname(__DIR__, 3);

// Track resources that need cleanup on early failure
$schema = null;
$serverProcess = null;
$stateDir = null;
$pointerFile = null;
$stopRequested = false;

// Install signal handling early so signals during setup cleanly trigger finally cleanup
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    if (defined('SIGHUP')) {
        pcntl_signal(SIGHUP, SIG_IGN);
    }
    pcntl_signal(SIGTERM, function () use (&$stopRequested) {
        $stopRequested = true;
        throw new RuntimeException('Supervisor received SIGTERM during execution.');
    });
    pcntl_signal(SIGINT, function () use (&$stopRequested) {
        $stopRequested = true;
        throw new RuntimeException('Supervisor received SIGINT during execution.');
    });
}

try {
    // 1. Create Private State Directory (0700)
    $stateDir = ReleaseUiSession::createPrivateStateDir();
    $sessionToken = bin2hex(random_bytes(32));

    // 2. Provision Isolated Disposable Schema (Strict Ownership)
    fwrite(STDOUT, "Provisioning isolated disposable schema on 127.0.0.1:3342...\n");
    $schema = DisposableMariaDbSchema::create();
    $schemaName = $schema->schemaName();
    fwrite(STDOUT, "Created owned schema: {$schemaName}\n");

    // 3. Migrate Schema
    fwrite(STDOUT, "Migrating schema {$schemaName}...\n");
    $schema->migrate();
    fwrite(STDOUT, "Migration complete.\n");

    // 4. Seed fixtures in a child process targeting this schema
    $credentialFile = $stateDir.DIRECTORY_SEPARATOR.'credentials.json';
    $seederScript = $stateDir.DIRECTORY_SEPARATOR.'seeder.php';

    $seederCode = <<<'PHP'
<?php
require '%ROOT%/vendor/autoload.php';
$app = require '%ROOT%/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $meta = Tests\Support\ReleaseUi\ReleaseUiFixtures::seed('%CRED_FILE%');
    echo "JSON_OUTPUT:" . json_encode($meta, JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo "EXCEPTION:" . $e->getMessage() . "\n" . $e->getTraceAsString();
    exit(1);
}
PHP;

    $seederCode = str_replace(
        ['%ROOT%', '%CRED_FILE%'],
        [$root, $credentialFile],
        $seederCode
    );
    ReleaseUiSession::writeSecureFile($stateDir, 'seeder.php', $seederCode);

    $env = $schema->environment();
    $env['DB_DATABASE'] = $schemaName;
    $env['DB_PORT'] = '3342';
    $env['DB_HOST'] = '127.0.0.1';
    $env['DB_USERNAME'] = 'test_runner';
    $env['DB_PASSWORD'] = '';
    $env['SESSION_DRIVER'] = 'database';
    $env['CACHE_STORE'] = 'array';
    foreach (DisposableMariaDbSchema::testSettings() as $k => $v) {
        $env['PHASE8_TEST_DB_'.$k] = $v;
    }

    fwrite(STDOUT, "Seeding UI release dataset...\n");
    $seedProcess = new Process([PHP_BINARY, $seederScript], $root, $env);
    $seedProcess->setTimeout(60)->mustRun();
    unlink($seederScript);

    $fixtureOutput = $seedProcess->getOutput();
    if (! str_contains($fixtureOutput, 'JSON_OUTPUT:')) {
        throw new RuntimeException("Seeder did not return valid JSON output:\n".$fixtureOutput."\n".$seedProcess->getErrorOutput());
    }

    $jsonStr = substr($fixtureOutput, strpos($fixtureOutput, 'JSON_OUTPUT:') + 12);
    $fixtures = json_decode(trim($jsonStr), true, flags: JSON_THROW_ON_ERROR);
    fwrite(STDOUT, 'Seeding complete. User: '.$fixtures['user_email']."\n");

    // 5. Find open port
    $port = 8088;
    for ($p = 8088; $p <= 8099; $p++) {
        $sock = @fsockopen('127.0.0.1', $p);
        if (! is_resource($sock)) {
            $port = $p;
            break;
        }
        fclose($sock);
    }

    // 6. Generate ephemeral APP_KEY
    $ephemeralKey = 'base64:'.base64_encode(random_bytes(32));

    // 7. Launch web server process via Symfony Process (Foreground Supervisor)
    $serverScript = $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';

    $serverEnv = [
        'APP_NAME' => 'SmallTraderAccounting',
        'APP_ENV' => 'local',
        'APP_KEY' => $ephemeralKey,
        'APP_DEBUG' => 'true',
        'APP_URL' => "http://127.0.0.1:{$port}",
        'LOG_CHANNEL' => 'stack',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3342',
        'DB_DATABASE' => $schemaName,
        'DB_USERNAME' => 'test_runner',
        'DB_PASSWORD' => '',
        'SESSION_DRIVER' => 'database',
        'SESSION_LIFETIME' => '120',
        'SESSION_SECURE_COOKIE' => 'false',
        'CACHE_STORE' => 'array',
        'VITE_APP_URL' => "http://127.0.0.1:{$port}",
        'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
    ];

    $serverProcess = new Process(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", $serverScript],
        $root.'/public',
        $serverEnv
    );
    $serverProcess->setTimeout(null);
    $serverProcess->start();

    $serverPid = $serverProcess->getPid();
    // Bug 1 fix: use basename(PHP_BINARY) instead of hardcoded 'php' for versioned binaries (php8.4 etc.)
    if ($serverPid === null || ! ReleaseUiSession::verifyProcessIdentity($serverPid, [basename(PHP_BINARY), '-S', "127.0.0.1:{$port}"])) {
        $serverProcess->stop(1);
        $serverProcess = null; // Prevent double-stop in outer finally
        throw new RuntimeException('Failed to verify started web server process identity.');
    }

    // Wait for port to be listening (up to 5 seconds)
    $bound = false;
    for ($attempt = 0; $attempt < 10; $attempt++) {
        usleep(500000);
        $sock = @fsockopen('127.0.0.1', $port);
        if (is_resource($sock)) {
            fclose($sock);
            $bound = true;
            break;
        }
    }

    if (! $bound) {
        $serverProcess->stop(1);
        $serverProcess = null; // Prevent double-stop in outer finally
        throw new RuntimeException("Server failed to bind on port {$port}.");
    }

    $ownerPid = posix_getpid();
    $ownerStartTime = ReleaseUiSession::getProcessStartTime($ownerPid);

    // 8. Write state file & pointer file with 0600 mode
    $stateData = [
        'port' => $port,
        'app_url' => "http://127.0.0.1:{$port}",
        'schema' => $schemaName,
        'state_dir' => $stateDir,
        'credential_file' => $credentialFile,
        'user_email' => $fixtures['user_email'],
        'owner_pid' => $ownerPid,
        'owner_start_time' => $ownerStartTime,
        'server_pid' => $serverPid,
        'session_token' => $sessionToken,
        'fixtures' => $fixtures,
        'started_at' => date('c'),
    ];

    $stateFile = ReleaseUiSession::writeSecureFile($stateDir, 'state.json', json_encode($stateData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    // Bug 3 fix: use safeWritePointer to recover from stale pointer files left by crashed sessions
    $pointerFile = ReleaseUiSession::safeWritePointer([
        'state_dir' => $stateDir,
        'state_file' => $stateFile,
        'owner_pid' => $ownerPid,
        'owner_start_time' => $ownerStartTime,
        'server_pid' => $serverPid,
        'schema' => $schemaName,
        'session_token' => $sessionToken,
        'port' => $port,
        'started_at' => date('c'),
    ], ReleaseUiSession::ownerProcessTokens());

    fwrite(STDOUT, "SERVER_STARTED_OK\n");
    fwrite(STDOUT, "URL: http://127.0.0.1:{$port}\n");
    fwrite(STDOUT, "OWNER_PID: {$ownerPid}\n");
    fwrite(STDOUT, "SERVER_PID: {$serverPid}\n");
    fwrite(STDOUT, "SCHEMA: {$schemaName}\n");
    fwrite(STDOUT, "STATE_DIR: {$stateDir}\n");
    fwrite(STDOUT, "CLEANUP_COMMAND: php tests/Support/ReleaseUi/cleanup.php\n");
    fflush(STDOUT);

    // 9. Foreground supervisor loop
    $stopSignalFile = $stateDir.DIRECTORY_SEPARATOR.'stop.signal';

    while (! $stopRequested && $serverProcess->isRunning()) {
        if (file_exists($stopSignalFile)) {
            $signalContent = @file_get_contents($stopSignalFile);
            if ($signalContent !== false && hash_equals($sessionToken, trim($signalContent))) {
                fwrite(STDOUT, "Authorized stop signal received.\n");
                $stopRequested = true;
                break;
            }
        }
        usleep(250000);
    }
} finally {
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, SIG_IGN);
        pcntl_signal(SIGINT, SIG_IGN);
    }
    $cleanupFailed = false;
    // Bug 2 + Bug 6 fix: comprehensive cleanup that handles early failures and DB drop exceptions
    $schemaName = $schema !== null ? $schema->schemaName() : 'unknown';
    fwrite(STDOUT, "Shutting down Release UI supervisor and dropping owned schema {$schemaName}...\n");

    // Stop web server if it was started
    if ($serverProcess !== null && $serverProcess->isRunning()) {
        try {
            $serverProcess->stop(2, SIGTERM);
        } catch (\Throwable $serverStopError) {
            $cleanupFailed = true;
            fwrite(STDERR, "WARNING: Server process stop failed: {$serverStopError->getMessage()}\n");
        }
    }

    // Drop the owned schema — if this fails, still clean up state files
    if ($schema !== null) {
        try {
            $schema->drop();
        } catch (\Throwable $dropError) {
            $cleanupFailed = true;
            fwrite(STDERR, "WARNING: Schema drop failed: {$dropError->getMessage()}\n");
            fwrite(STDERR, "Schema {$schemaName} may be orphaned — manual cleanup required.\n");
        }
    }

    // Cleanup state directory and pointer — always attempted even if schema drop failed
    if ($stateDir !== null) {
        try {
            if ($cleanupFailed) {
                // Keep diagnostic state, never turn a schema name into drop authority.
                ReleaseUiSession::writeSecureFile($stateDir, 'cleanup.failed', 'Owned resource cleanup failed.');
                ReleaseUiSession::removeOwnedPointer($stateDir, $pointerFile);
            } else {
                ReleaseUiSession::cleanupStateDir($stateDir, $pointerFile);
            }
        } catch (\Throwable $cleanupError) {
            $cleanupFailed = true;
            fwrite(STDERR, "WARNING: State cleanup failed: {$cleanupError->getMessage()}\n");
        }
    }

    if ($cleanupFailed) {
        fwrite(STDERR, "TERMINATED_WITH_ERRORS: diagnostic state preserved.\n");
        exit(1);
    }
    fwrite(STDOUT, "Release UI session cleanly terminated.\n");
}
