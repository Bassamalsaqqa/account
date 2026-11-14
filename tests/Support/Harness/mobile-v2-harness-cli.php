<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Application as ConsoleApplication;
use Tests\Support\Harness\DisposableMobileV2BackendHarness;
use Tests\Support\Harness\MobileV2HarnessCommand;

// CLI entrypoint for Mobile V2 Harness driver (tests/Support/Harness)
// Does NOT modify production application or console commands.
// Zero-framework bootstrap on --prepare or argument validation failure.

$argvList = $_SERVER['argv'] ?? [];

$isPrepare = false;
$isProvision = false;
$isReadinessOnly = false;
$isStart = false;
$isRun = false;
$backendHead = null;
$androidHead = null;
$pairingManifest = null;
$pairingManifestSha = null;
$readinessHoldSeconds = null;

foreach ($argvList as $arg) {
    if ($arg === '--prepare') {
        $isPrepare = true;
    } elseif ($arg === '--provision') {
        $isProvision = true;
    } elseif ($arg === '--readiness-only') {
        $isReadinessOnly = true;
    } elseif ($arg === '--start') {
        $isStart = true;
    } elseif ($arg === '--run') {
        $isRun = true;
    } elseif (str_starts_with($arg, '--readiness-hold-seconds=')) {
        $readinessHoldSeconds = substr($arg, 25);
    } elseif (str_starts_with($arg, '--backend-head=')) {
        $backendHead = substr($arg, 15);
    } elseif (str_starts_with($arg, '--android-head=')) {
        $androidHead = substr($arg, 15);
    } elseif (str_starts_with($arg, '--pairing-manifest=')) {
        $pairingManifest = substr($arg, 19);
    } elseif (str_starts_with($arg, '--pairing-manifest-sha=')) {
        $pairingManifestSha = substr($arg, 23);
    }
}

// Default to --prepare if no execution action flag given
if (! $isProvision && ! $isReadinessOnly && ! $isStart && ! $isRun) {
    $isPrepare = true;
}

// SAFE AUTOLOAD ONLY (no bootstrap/app.php, no Laravel kernel, zero repo .env loading)
require __DIR__.'/../../../vendor/autoload.php';

if ($readinessHoldSeconds !== null) {
    try {
        $parsedHold = DisposableMobileV2BackendHarness::assertReadinessHoldBounds($readinessHoldSeconds);
        if ($parsedHold > 0 && ! $isReadinessOnly) {
            fwrite(STDERR, "Error: --readiness-hold-seconds is strictly restricted to --readiness-only mode.\n");
            exit(1);
        }
        DisposableMobileV2BackendHarness::assertPcntlAvailableForHold($parsedHold);
        if ($parsedHold > 0) {
            $regOk = DisposableMobileV2BackendHarness::registerEarlyHoldSignalHandlers(static function () {});
            if (! $regOk) {
                fwrite(STDERR, "Error: Harness refused: failed to verify early signal registration for SIGINT/SIGTERM.\n");
                exit(1);
            }
        }
    } catch (\InvalidArgumentException|\RuntimeException $e) {
        fwrite(STDERR, "Error: {$e->getMessage()}\n");
        exit(1);
    }
}

if ($isPrepare) {
    echo "Mobile V2 Harness — Source-Only Prepare (zero sockets, zero DB, zero framework boot)\n";
    echo '  QUALIFIED_EXECUTION_HEAD   : '.DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD."\n";
    echo '  QUALIFIED_APP_TREE         : '.DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE."\n";
    echo '  REQUIRED_BASE_BRANCH       : '.DisposableMobileV2BackendHarness::REQUIRED_BASE_BRANCH."\n";
    echo '  BACKEND_LOOPBACK_PORT      : '.DisposableMobileV2BackendHarness::BACKEND_LOOPBACK_PORT."\n";
    echo '  TLS_PROXY_PORT             : '.DisposableMobileV2BackendHarness::TLS_PROXY_PORT."\n";
    echo '  ANDROID_CANDIDATE_KNOWN_BAD: '.DisposableMobileV2BackendHarness::ANDROID_CANDIDATE_KNOWN_BAD."\n";
    echo '  CA5A_MANIFEST_SHA256       : '.DisposableMobileV2BackendHarness::CA5A_MANIFEST_SHA256."\n";
    echo '  CA5A_CANONICAL_DIGEST      : '.DisposableMobileV2BackendHarness::CA5A_CANONICAL_DIGEST."\n\n";
    echo "TLS certificate covers SAN: localhost, 127.0.0.1, 10.0.2.2 (Android emulator)\n";
    echo 'Android base URL (emulator):   https://10.0.2.2:'.DisposableMobileV2BackendHarness::TLS_PROXY_PORT."/api/mobile/v2/\n\n";
    echo "To run foreground lifecycle (requires Root coordination & approved Android HEAD):\n";
    echo "  MOBILE_V2_HARNESS_ENABLE=1 MOBILE_V2_HARNESS_START_LISTENER=1 PHASE8_ALLOW_DISPOSABLE_DB=1 \\\n";
    echo "    PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \\\n";
    echo "    PHASE8_TEST_DB_USERNAME=<user> PHASE8_TEST_DB_PASSWORD=<pass> \\\n";
    echo "    php tests/Support/Harness/mobile-v2-harness-cli.php --run --backend-head=<40hex> --android-head=<40hex> \\\n";
    echo "      --pairing-manifest-sha=<64hex>\n\n";
    echo "Prepare phase complete. Zero framework booted. Zero listeners started.\n";
    exit(0);
}

// Early precondition guards across ALL execution modes (--provision, --readiness-only, --start, --run)
if ($backendHead === null || $backendHead === '') {
    fwrite(STDERR, "Error: --backend-head=<40hex> is required for --provision, --readiness-only, --start, or --run.\n");
    exit(1);
}

if ($androidHead === null || $androidHead === '') {
    fwrite(STDERR, "Error: --android-head=<40hex> is required for --provision, --readiness-only, --start, or --run.\n");
    exit(1);
}

if ($androidHead === DisposableMobileV2BackendHarness::ANDROID_CANDIDATE_KNOWN_BAD) {
    fwrite(STDERR, "Harness start refused: Android candidate '{$androidHead}' is marked CANDIDATE_KNOWN_BAD.\n".
        "Android PR 23 review identified changes required (gateway 15-field durable OfflineSaleV2 direct POST to 7-key online cash).\n".
        "Zero background servers, TLS sockets, or DB listeners started.\n");
    exit(1);
}

if (($isStart || $isRun || $isReadinessOnly) && getenv('MOBILE_V2_HARNESS_START_LISTENER') !== '1') {
    fwrite(STDERR, "Harness start refused: MOBILE_V2_HARNESS_START_LISTENER is not set.\n".
        "Listener startup is held pending Android thread 83e6a721 readiness and Root coordination grant.\n".
        "Zero background servers, TLS sockets, or DB listeners started.\n");
    exit(1);
}

if ($pairingManifestSha === null || $pairingManifestSha === '') {
    fwrite(STDERR, "Harness refused: --pairing-manifest-sha=<64hex> is mandatory for --provision, --readiness-only, --start, or --run.\n");
    exit(1);
}

// Enforce pre-socket invariant checks, dual manifest pin, and lifecycle scope BEFORE loading Laravel framework or repo .env
try {
    DisposableMobileV2BackendHarness::assertPreSocketInvariants($backendHead, $androidHead);
    $manifestInfo = DisposableMobileV2BackendHarness::assertDualManifestPin(
        backendHead: $backendHead,
        peerAndroidHead: $androidHead,
        manifestPath: $pairingManifest,
        manifestShaPin: $pairingManifestSha,
    );
    $requestedMode = $isProvision ? 'provision' : ($isReadinessOnly ? 'readiness' : 'start');
    DisposableMobileV2BackendHarness::assertLifecycleScope($pairingManifest, $pairingManifestSha, $requestedMode);
} catch (Throwable $e) {
    fwrite(STDERR, "Harness refused before framework boot: {$e->getMessage()}\n");
    exit(1);
}

// Ensure isolated testing environment without loading repository .env defaults
$cliCacheDir = sys_get_temp_dir().'/cli_harness_cache_'.bin2hex(random_bytes(6));
if (! is_dir($cliCacheDir)) {
    mkdir($cliCacheDir, 0700, true);
}

$isolatedEnv = [
    'APP_ENV' => 'testing',
    'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
    'MOBILE_V2_HARNESS_ENABLE' => '1',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3342',
    'DB_DATABASE' => 'unconnectable_preboot_guard',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'APP_CONFIG_CACHE' => $cliCacheDir.'/config.php',
    'APP_SERVICES_CACHE' => $cliCacheDir.'/services.php',
    'APP_PACKAGES_CACHE' => $cliCacheDir.'/packages.php',
    'APP_ROUTES_CACHE' => $cliCacheDir.'/routes-v7.php',
];

foreach ($isolatedEnv as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$console = new ConsoleApplication('Mobile V2 Harness CLI', '1.0.0');
$command = new MobileV2HarnessCommand;
$command->setLaravel($app);
$console->addCommand($command);
$console->setDefaultCommand('mobile:v2-harness', true);

$status = $console->run();
exit($status);
