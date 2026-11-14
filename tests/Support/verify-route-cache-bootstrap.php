<?php

declare(strict_types=1);

/**
 * Standalone Route Cache Bootstrap & Isolation Verification Proof.
 *
 * Runs an out-of-process verification of route caching behavior:
 * - Isolated cache paths outside checkout (sys_get_temp_dir).
 * - Fake/testing sqlite in-memory DB (no persistent DB sockets).
 * - Cycle 1 (Cash OFF, Master ON): route:clear -> route:cache -> route:list -> assert absent -> probe HTTP (404 NOT_FOUND).
 * - Cycle 2 (Cash ON without rebuild): probe HTTP (stale 404 NOT_FOUND invariant).
 * - Cycle 3 (Cash ON with rebuild ceremony): route:clear -> route:cache -> assert present -> probe HTTP (401 UNAUTHENTICATED auth pipeline).
 * - Cycle 4 (Master OFF fail-closed): probe HTTP (404 MOBILE_API_DISABLED).
 * - Cleanup: deletes all temporary files and directories.
 */

use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';

$tmpDir = sys_get_temp_dir().'/route_cache_proof_'.bin2hex(random_bytes(8));
if (! is_dir($tmpDir)) {
    mkdir($tmpDir, 0700, true);
}

$routesCacheFile = $tmpDir.'/routes-v7.php';
$configCacheFile = $tmpDir.'/config.php';
$servicesCacheFile = $tmpDir.'/services.php';
$packagesCacheFile = $tmpDir.'/packages.php';
$eventsCacheFile = $tmpDir.'/events.php';

$cleanup = function () use ($tmpDir, $routesCacheFile, $configCacheFile, $servicesCacheFile, $packagesCacheFile, $eventsCacheFile): void {
    foreach ([$routesCacheFile, $configCacheFile, $servicesCacheFile, $packagesCacheFile, $eventsCacheFile] as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }
    if (is_dir($tmpDir)) {
        @rmdir($tmpDir);
    }
};

register_shutdown_function($cleanup);

$getEnv = function (bool $cashSliceEnabled, bool $masterFlagsEnabled = true) use ($routesCacheFile, $configCacheFile, $servicesCacheFile, $packagesCacheFile, $eventsCacheFile): array {
    return [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('r', 32)),
        'APP_ROUTES_CACHE' => $routesCacheFile,
        'APP_CONFIG_CACHE' => $configCacheFile,
        'APP_SERVICES_CACHE' => $servicesCacheFile,
        'APP_PACKAGES_CACHE' => $packagesCacheFile,
        'APP_EVENTS_CACHE' => $eventsCacheFile,
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'MOBILE_API_ENABLED' => $masterFlagsEnabled ? 'true' : 'false',
        'MOBILE_V2_API_ENABLED' => $masterFlagsEnabled ? 'true' : 'false',
        'MOBILE_V2_CASH_SLICE_ENABLED' => $cashSliceEnabled ? 'true' : 'false',
    ];
};

$basePath = dirname(__DIR__, 2);

$runArtisan = function (array $args, array $env) use ($basePath): Process {
    $command = array_merge([PHP_BINARY, $basePath.'/artisan'], $args);
    $process = new Process($command, $basePath, $env, timeout: 60);
    $process->mustRun();

    return $process;
};

$dispatchHttp = function (array $env) use ($basePath): array {
    $script = <<<'PHP'
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$postCashReq = Illuminate\Http\Request::create('/api/mobile/v2/companies/COM-TEST/sales/online-cash', 'POST');
$postCashRes = $kernel->handle($postCashReq);

$getStatusReq = Illuminate\Http\Request::create('/api/mobile/v2/companies/COM-TEST/offline-events/01JTEST00000000000000000000/status', 'GET');
$getStatusRes = $kernel->handle($getStatusReq);

echo json_encode([
    'post_cash' => [
        'status' => $postCashRes->getStatusCode(),
        'body' => json_decode((string) $postCashRes->getContent(), true),
    ],
    'get_status' => [
        'status' => $getStatusRes->getStatusCode(),
        'body' => json_decode((string) $getStatusRes->getContent(), true),
    ],
]);

$kernel->terminate($postCashReq, $postCashRes);
$kernel->terminate($getStatusReq, $getStatusRes);
PHP;

    $process = new Process([PHP_BINARY, '-r', $script], $basePath, $env, timeout: 60);
    $process->mustRun();

    return json_decode($process->getOutput(), true);
};

echo "=== ROUTE CACHE BOOTSTRAP PROOF ===\n";
echo "Isolated cache directory: {$tmpDir}\n";

// Cycle 1: Cash OFF, Master ON
echo "\n--- Cycle 1: Cash OFF, Master ON ---\n";
$envOff = $getEnv(false, true);
$runArtisan(['route:clear'], $envOff);
$runArtisan(['route:cache'], $envOff);
if (! file_exists($routesCacheFile)) {
    throw new RuntimeException("Route cache file not created at {$routesCacheFile}");
}

$listProcess = $runArtisan(['route:list', '--json'], $envOff);
$routesOff = json_decode($listProcess->getOutput(), true);
$cashRoutesOff = array_filter($routesOff, fn ($r) => str_contains((string) ($r['uri'] ?? ''), 'sales/online-cash'));
$statusRoutesOff = array_filter($routesOff, fn ($r) => str_contains((string) ($r['uri'] ?? ''), 'offline-events/{clientEventId}/status'));

if (! empty($cashRoutesOff) || ! empty($statusRoutesOff)) {
    throw new RuntimeException('FAILURE: Financial routes present when flag is OFF!');
}
echo "PASS: Flag OFF excludes cash and status routes from route cache.\n";

$probeOff = $dispatchHttp($envOff);
if ($probeOff['post_cash']['status'] !== 404 || $probeOff['get_status']['status'] !== 404) {
    throw new RuntimeException('FAILURE: HTTP probe did not return 404 when flag is OFF!');
}
if (($probeOff['post_cash']['body']['error']['code'] ?? null) !== 'NOT_FOUND' || ($probeOff['get_status']['body']['error']['code'] ?? null) !== 'NOT_FOUND') {
    throw new RuntimeException('FAILURE: Expected error code NOT_FOUND on fallback 404!');
}
echo "PASS: HTTP probe returns boundary 404 with code NOT_FOUND for both endpoints.\n";

// Cycle 2: Stale Cache Invariant (Cash ON without rebuild)
echo "\n--- Cycle 2: Stale Cache Invariant (Cash ON without rebuild) ---\n";
$envOn = $getEnv(true, true);
$probeStale = $dispatchHttp($envOn);
if ($probeStale['post_cash']['status'] !== 404 || $probeStale['get_status']['status'] !== 404) {
    throw new RuntimeException('FAILURE: Stale cache incorrectly exposed routes without rebuild ceremony!');
}
if (($probeStale['post_cash']['body']['error']['code'] ?? null) !== 'NOT_FOUND' || ($probeStale['get_status']['body']['error']['code'] ?? null) !== 'NOT_FOUND') {
    throw new RuntimeException('FAILURE: Expected error code NOT_FOUND on stale 404!');
}
echo "PASS: Stale cache preserves 404 boundary (NOT_FOUND); flag change does not bypass compiled cache.\n";

// Cycle 3: Cash ON with rebuild ceremony
echo "\n--- Cycle 3: Cash ON with Rebuild Ceremony ---\n";
$runArtisan(['route:clear'], $envOn);
$runArtisan(['route:cache'], $envOn);

$listProcessOn = $runArtisan(['route:list', '--json'], $envOn);
$routesOn = json_decode($listProcessOn->getOutput(), true);
$cashRoutesOn = array_filter($routesOn, fn ($r) => str_contains((string) ($r['uri'] ?? ''), 'sales/online-cash'));
$statusRoutesOn = array_filter($routesOn, fn ($r) => str_contains((string) ($r['uri'] ?? ''), 'offline-events/{clientEventId}/status'));

if (empty($cashRoutesOn) || empty($statusRoutesOn)) {
    throw new RuntimeException('FAILURE: Financial routes missing when flag is ON with rebuilt cache!');
}
echo "PASS: Rebuilt route cache includes both POST sales/online-cash and GET offline-events/status.\n";

$probeOn = $dispatchHttp($envOn);
if ($probeOn['post_cash']['status'] !== 401 || $probeOn['get_status']['status'] !== 401) {
    throw new RuntimeException("FAILURE: HTTP probe did not enter auth pipeline! Got: post_cash={$probeOn['post_cash']['status']}, get_status={$probeOn['get_status']['status']}");
}
if (($probeOn['post_cash']['body']['error']['code'] ?? null) !== 'UNAUTHENTICATED' || ($probeOn['get_status']['body']['error']['code'] ?? null) !== 'UNAUTHENTICATED') {
    throw new RuntimeException('FAILURE: Expected error code UNAUTHENTICATED on 401! Got post_cash code: '.(string) ($probeOn['post_cash']['body']['error']['code'] ?? 'null'));
}
echo "PASS: HTTP probe reaches mobile.v2.auth middleware and returns 401 (UNAUTHENTICATED).\n";

// Cycle 4: Master Flags OFF Fail-Closed
echo "\n--- Cycle 4: Master Flags OFF Fail-Closed ---\n";
$envMasterOff = $getEnv(true, false);
$probeMasterOff = $dispatchHttp($envMasterOff);
if ($probeMasterOff['post_cash']['status'] !== 404 || $probeMasterOff['get_status']['status'] !== 404) {
    throw new RuntimeException('FAILURE: Master flags OFF did not fail closed!');
}
if (($probeMasterOff['post_cash']['body']['error']['code'] ?? null) !== 'MOBILE_API_DISABLED') {
    throw new RuntimeException('FAILURE: Expected error code MOBILE_API_DISABLED when master flags are OFF!');
}
echo "PASS: Master flags OFF fail closed with 404 (MOBILE_API_DISABLED) even when cash flag is ON.\n";

echo "\nAll route cache isolation and bootstrap invariants verified successfully.\n";
