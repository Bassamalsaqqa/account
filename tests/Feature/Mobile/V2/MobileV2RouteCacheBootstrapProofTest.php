<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V2;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Route Cache Bootstrap Qualification & Isolation Proof.
 *
 * Verifies that:
 * 1. Route compilation executes in an isolated fresh PHP bootstrap with cache paths outside checkout.
 * 2. When mobile.v2_cash_slice_enabled is OFF (with master flags ON), routes are strictly absent
 *    from compiled route cache and HTTP requests hit MobileV2ApiBoundary fallback returning 404 (NOT_FOUND).
 * 3. When mobile.v2_cash_slice_enabled is ON (with master flags ON), both POST /sales/online-cash
 *    and GET /offline-events/{id}/status are compiled into route cache and reach mobile.v2.auth (401 UNAUTHENTICATED).
 * 4. Stale cache invariant: Changing the runtime environment flag does NOT activate routes without
 *    an explicit cache regeneration ceremony (route:clear -> route:cache).
 * 5. Master flags fail-closed: When MOBILE_API_ENABLED or MOBILE_V2_API_ENABLED is OFF, outer
 *    MobileV2ApiBoundary returns 404 (MOBILE_API_DISABLED) even if cash slice flag is ON.
 * 6. Uses fake/testing sqlite in-memory connection with zero persistent MariaDB database sockets.
 */
final class MobileV2RouteCacheBootstrapProofTest extends TestCase
{
    private string $tempDir;

    private string $routesCacheFile;

    private string $configCacheFile;

    private string $servicesCacheFile;

    private string $packagesCacheFile;

    private string $eventsCacheFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/route_cache_proof_'.bin2hex(random_bytes(8));
        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0700, true);
        }

        $this->routesCacheFile = $this->tempDir.'/routes-v7.php';
        $this->configCacheFile = $this->tempDir.'/config.php';
        $this->servicesCacheFile = $this->tempDir.'/services.php';
        $this->packagesCacheFile = $this->tempDir.'/packages.php';
        $this->eventsCacheFile = $this->tempDir.'/events.php';
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDir();
        parent::tearDown();
    }

    private function cleanupTempDir(): void
    {
        $files = [
            $this->routesCacheFile,
            $this->configCacheFile,
            $this->servicesCacheFile,
            $this->packagesCacheFile,
            $this->eventsCacheFile,
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    /**
     * @return array<string, string>
     */
    private function isolatedEnv(bool $cashSliceEnabled, bool $masterFlagsEnabled = true): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'APP_ROUTES_CACHE' => $this->routesCacheFile,
            'APP_CONFIG_CACHE' => $this->configCacheFile,
            'APP_SERVICES_CACHE' => $this->servicesCacheFile,
            'APP_PACKAGES_CACHE' => $this->packagesCacheFile,
            'APP_EVENTS_CACHE' => $this->eventsCacheFile,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'MOBILE_API_ENABLED' => $masterFlagsEnabled ? 'true' : 'false',
            'MOBILE_V2_API_ENABLED' => $masterFlagsEnabled ? 'true' : 'false',
            'MOBILE_V2_CASH_SLICE_ENABLED' => $cashSliceEnabled ? 'true' : 'false',
        ];
    }

    /**
     * Run an Artisan command in a fresh PHP process with isolated cache paths.
     *
     * @param  array<int, string>  $args
     * @param  array<string, string>  $env
     */
    private function runArtisanProcess(array $args, array $env): Process
    {
        $command = array_merge([PHP_BINARY, base_path('artisan')], $args);
        $process = new Process($command, base_path(), $env, timeout: 60);
        $process->mustRun();

        return $process;
    }

    /**
     * Execute an isolated HTTP request verification script in a fresh PHP bootstrap.
     *
     * @param  array<string, string>  $env
     * @return array{
     *     post_cash: array{status: int, body: array<string, mixed>|null},
     *     get_status: array{status: int, body: array<string, mixed>|null}
     * }
     */
    private function dispatchIsolatedHttpProbe(array $env): array
    {
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

        $process = new Process([PHP_BINARY, '-r', $script], base_path(), $env, timeout: 60);
        $process->mustRun();

        /** @var array{post_cash: array{status: int, body: array<string, mixed>|null}, get_status: array{status: int, body: array<string, mixed>|null}} $decoded */
        $decoded = json_decode($process->getOutput(), true);

        return $decoded;
    }

    /**
     * Retrieve compiled routes from route:list in JSON format.
     *
     * @param  array<string, string>  $env
     * @return array<int, array<string, mixed>>
     */
    private function fetchRouteList(array $env): array
    {
        $process = $this->runArtisanProcess(['route:list', '--json'], $env);
        /** @var array<int, array<string, mixed>> $routes */
        $routes = json_decode($process->getOutput(), true);

        return $routes;
    }

    #[Test]
    public function test_route_cache_flag_off_excludes_financial_endpoints_and_returns_boundary_404(): void
    {
        $envOff = $this->isolatedEnv(cashSliceEnabled: false, masterFlagsEnabled: true);

        // 1. Clear any prior cache
        $this->runArtisanProcess(['route:clear'], $envOff);
        $this->assertFileDoesNotExist($this->routesCacheFile);

        // 2. Compile route cache with cash slice flag OFF
        $this->runArtisanProcess(['route:cache'], $envOff);
        $this->assertFileExists($this->routesCacheFile);

        // 3. Inspect cached route collection
        $routes = $this->fetchRouteList($envOff);
        $cashRoutes = array_filter(
            $routes,
            static fn (array $r): bool => str_contains((string) ($r['uri'] ?? ''), 'sales/online-cash')
                || str_contains((string) ($r['name'] ?? ''), 'mobile.v2.sales.online-cash')
        );
        $statusRoutes = array_filter(
            $routes,
            static fn (array $r): bool => str_contains((string) ($r['uri'] ?? ''), 'offline-events/{clientEventId}/status')
                || str_contains((string) ($r['name'] ?? ''), 'mobile.v2.offline-events.status')
        );

        $this->assertEmpty($cashRoutes, 'Route cache compiled with flag OFF must not register POST sales/online-cash.');
        $this->assertEmpty($statusRoutes, 'Route cache compiled with flag OFF must not register GET offline-events/status.');

        // 4. Probe HTTP endpoints in fresh bootstrap process using compiled route cache
        $probe = $this->dispatchIsolatedHttpProbe($envOff);

        $this->assertSame(404, $probe['post_cash']['status']);
        $this->assertIsArray($probe['post_cash']['body']);
        $this->assertSame('NOT_FOUND', $probe['post_cash']['body']['error']['code'] ?? null);

        $this->assertSame(404, $probe['get_status']['status']);
        $this->assertIsArray($probe['get_status']['body']);
        $this->assertSame('NOT_FOUND', $probe['get_status']['body']['error']['code'] ?? null);
    }

    #[Test]
    public function test_route_cache_flag_on_includes_both_cash_and_status_endpoints(): void
    {
        $envOn = $this->isolatedEnv(cashSliceEnabled: true, masterFlagsEnabled: true);

        // 1. Clear prior cache
        $this->runArtisanProcess(['route:clear'], $envOn);
        $this->assertFileDoesNotExist($this->routesCacheFile);

        // 2. Compile route cache with cash slice flag ON
        $this->runArtisanProcess(['route:cache'], $envOn);
        $this->assertFileExists($this->routesCacheFile);

        // 3. Inspect cached route collection
        $routes = $this->fetchRouteList($envOn);
        $cashRoutes = array_filter(
            $routes,
            static fn (array $r): bool => str_contains((string) ($r['uri'] ?? ''), 'sales/online-cash')
                || ((string) ($r['name'] ?? '')) === 'mobile.v2.sales.online-cash'
        );
        $statusRoutes = array_filter(
            $routes,
            static fn (array $r): bool => str_contains((string) ($r['uri'] ?? ''), 'offline-events/{clientEventId}/status')
                || ((string) ($r['name'] ?? '')) === 'mobile.v2.offline-events.status'
        );

        $this->assertNotEmpty($cashRoutes, 'Route cache compiled with flag ON must include POST sales/online-cash.');
        $this->assertNotEmpty($statusRoutes, 'Route cache compiled with flag ON must include GET offline-events/status.');

        // 4. Probe HTTP endpoints in fresh bootstrap process using compiled route cache
        // With routes registered and master flags ON, unauthenticated requests enter mobile.v2.auth middleware and receive 401 UNAUTHENTICATED
        $probe = $this->dispatchIsolatedHttpProbe($envOn);

        $this->assertSame(401, $probe['post_cash']['status'], 'Registered cash route must enter auth pipeline and yield 401.');
        $this->assertSame('UNAUTHENTICATED', $probe['post_cash']['body']['error']['code'] ?? null);

        $this->assertSame(401, $probe['get_status']['status'], 'Registered status route must enter auth pipeline and yield 401.');
        $this->assertSame('UNAUTHENTICATED', $probe['get_status']['body']['error']['code'] ?? null);
    }

    #[Test]
    public function test_stale_cache_requires_explicit_rebuild_ceremony_on_flag_toggle(): void
    {
        $envOff = $this->isolatedEnv(cashSliceEnabled: false, masterFlagsEnabled: true);
        $envOn = $this->isolatedEnv(cashSliceEnabled: true, masterFlagsEnabled: true);

        // 1. Warm cache with flag OFF
        $this->runArtisanProcess(['route:clear'], $envOff);
        $this->runArtisanProcess(['route:cache'], $envOff);
        $this->assertFileExists($this->routesCacheFile);

        // 2. Attempt to invoke with environment flag toggled ON, but WITHOUT clearing or rebuilding cache
        $probeStale = $this->dispatchIsolatedHttpProbe($envOn);

        // Stale compiled route cache does not reflect the new environment variable; endpoints remain 404 NOT_FOUND
        $this->assertSame(404, $probeStale['post_cash']['status'], 'Stale route cache without rebuild must keep cash endpoint 404.');
        $this->assertSame('NOT_FOUND', $probeStale['post_cash']['body']['error']['code'] ?? null);

        $this->assertSame(404, $probeStale['get_status']['status'], 'Stale route cache without rebuild must keep status endpoint 404.');
        $this->assertSame('NOT_FOUND', $probeStale['get_status']['body']['error']['code'] ?? null);

        // 3. Perform the mandatory route cache regeneration ceremony: route:clear then route:cache
        $this->runArtisanProcess(['route:clear'], $envOn);
        $this->runArtisanProcess(['route:cache'], $envOn);

        // 4. Probe again after regeneration ceremony
        $probeFresh = $this->dispatchIsolatedHttpProbe($envOn);
        $this->assertSame(401, $probeFresh['post_cash']['status'], 'Rebuilt route cache must now route cash endpoint to 401.');
        $this->assertSame('UNAUTHENTICATED', $probeFresh['post_cash']['body']['error']['code'] ?? null);

        $this->assertSame(401, $probeFresh['get_status']['status'], 'Rebuilt route cache must now route status endpoint to 401.');
        $this->assertSame('UNAUTHENTICATED', $probeFresh['get_status']['body']['error']['code'] ?? null);
    }

    #[Test]
    public function test_master_flags_off_fail_closed_even_when_cash_slice_flag_is_on(): void
    {
        // When master flags are OFF, outer MobileV2ApiBoundary rejects requests with 404 MOBILE_API_DISABLED
        $envMasterOff = $this->isolatedEnv(cashSliceEnabled: true, masterFlagsEnabled: false);

        $this->runArtisanProcess(['route:clear'], $envMasterOff);
        $this->runArtisanProcess(['route:cache'], $envMasterOff);
        $this->assertFileExists($this->routesCacheFile);

        $probe = $this->dispatchIsolatedHttpProbe($envMasterOff);

        $this->assertSame(404, $probe['post_cash']['status'], 'Master flags OFF must yield 404.');
        $this->assertSame('MOBILE_API_DISABLED', $probe['post_cash']['body']['error']['code'] ?? null);

        $this->assertSame(404, $probe['get_status']['status'], 'Master flags OFF must yield 404.');
        $this->assertSame('MOBILE_API_DISABLED', $probe['get_status']['body']['error']['code'] ?? null);
    }
}
