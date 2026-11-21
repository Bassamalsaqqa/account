<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MobileRouteCacheTest extends TestCase
{
    private ?string $tempRouteCache = null;

    public function test_cached_settings_read_retains_master_gate_and_authentication(): void
    {
        $uri = '/api/mobile/v1/companies/01M3WMTQQZE7P30Z3KC93N7YJ9/settings/business';
        $enabled = $this->runWorker(mobileEnabled: true, cached: true, method: 'GET', uri: $uri);
        $this->assertSame(401, $enabled['status']);
        $disabled = $this->runWorker(mobileEnabled: false, cached: true, method: 'GET', uri: $uri);
        $this->assertSame(404, $disabled['status']);
    }

    protected function tearDown(): void
    {
        if ($this->tempRouteCache !== null && file_exists($this->tempRouteCache)) {
            @unlink($this->tempRouteCache);
            $this->tempRouteCache = null;
        }

        parent::tearDown();
    }

    /**
     * Execute an out-of-process fresh application bootstrap and HTTP boundary invocation
     * using owned disposable APP_ROUTES_CACHE paths to guarantee zero runtime cache mutation.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function runWorker(
        bool $mobileEnabled,
        bool $cached,
        string $method,
        string $uri,
        array $headers = [],
        string $body = ''
    ): array {
        $cacheFile = '';
        if ($cached) {
            $this->tempRouteCache = tempnam(sys_get_temp_dir(), 'test_app_routes_').'.php';
            $cacheFile = $this->tempRouteCache;

            // Cache routes into isolated temporary path via owned artisan command
            $cacheProcess = new Process(
                [PHP_BINARY, 'artisan', 'route:cache'],
                base_path(),
                [
                    'APP_ROUTES_CACHE' => $cacheFile,
                    'MOBILE_API_ENABLED' => $mobileEnabled ? 'true' : 'false',
                    'APP_ENV' => 'testing',
                    'CACHE_STORE' => 'array',
                    'CACHE_DRIVER' => 'array',
                    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                ]
            );
            $cacheProcess->mustRun();
            $this->assertFileExists($cacheFile);
        }

        try {
            $input = [
                'mobile_enabled' => $mobileEnabled,
                'method' => $method,
                'uri' => $uri,
                'headers' => array_merge([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ], $headers),
                'body' => $body,
            ];

            $workerProcess = new Process(
                [
                    PHP_BINARY,
                    base_path('tests/Support/Mobile/route-boundary-worker.php'),
                    $cacheFile,
                    json_encode($input, JSON_THROW_ON_ERROR),
                ],
                base_path(),
                [
                    'APP_ROUTES_CACHE' => $cacheFile,
                    'APP_ENV' => 'testing',
                    'CACHE_STORE' => 'array',
                    'CACHE_DRIVER' => 'array',
                    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                ]
            );
            $workerProcess->mustRun();

            /** @var array<string, mixed> $result */
            $result = json_decode($workerProcess->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            return $result;
        } finally {
            if ($cacheFile !== '' && file_exists($cacheFile)) {
                @unlink($cacheFile);
                $this->tempRouteCache = null;
            }
        }
    }

    public function test_fresh_bootstrap_uncached_when_disabled_returns_404_and_zero_v2(): void
    {
        $res = $this->runWorker(
            mobileEnabled: false,
            cached: false,
            method: 'POST',
            uri: '/api/mobile/v1/auth/challenge',
            body: '{}'
        );

        $this->assertFalse($res['routes_are_cached']);
        $this->assertSame(0, $res['v2_count']);
        $this->assertSame([], $res['v2_routes']);
        $this->assertSame(404, $res['status']);
        $this->assertSame('MOBILE_API_DISABLED', $res['body']['error']['code']);
    }

    public function test_fresh_bootstrap_cached_when_disabled_returns_404_and_zero_v2(): void
    {
        $res = $this->runWorker(
            mobileEnabled: false,
            cached: true,
            method: 'POST',
            uri: '/api/mobile/v1/auth/challenge',
            body: '{}'
        );

        $this->assertTrue($res['routes_are_cached']);
        $this->assertSame(0, $res['v2_count']);
        $this->assertSame([], $res['v2_routes']);
        $this->assertSame(404, $res['status']);
        $this->assertSame('MOBILE_API_DISABLED', $res['body']['error']['code']);
    }

    public function test_fresh_bootstrap_uncached_when_enabled_executes_http_boundary_and_zero_v2(): void
    {
        // 1. Validation error on invalid payload returns 422 VALIDATION_FAILED through real boundary
        $valRes = $this->runWorker(
            mobileEnabled: true,
            cached: false,
            method: 'POST',
            uri: '/api/mobile/v1/auth/challenge',
            body: '{}'
        );

        $this->assertFalse($valRes['routes_are_cached']);
        $this->assertSame(49, $valRes['v1_count']);
        $this->assertSame(0, $valRes['v2_count']);
        $this->assertSame([], $valRes['v2_routes']);
        $this->assertSame(422, $valRes['status']);
        $this->assertSame('VALIDATION_FAILED', $valRes['body']['error']['code']);
        $this->assertArrayHasKey('device_id', $valRes['body']['error']['details']);

        // 2. Unauthenticated request to protected company endpoint returns 401 UNAUTHENTICATED
        $authRes = $this->runWorker(
            mobileEnabled: true,
            cached: false,
            method: 'GET',
            uri: '/api/mobile/v1/companies/COMP-123/bootstrap'
        );

        $this->assertSame(401, $authRes['status']);
        $this->assertSame('UNAUTHENTICATED', $authRes['body']['error']['code']);

        // 3. V2 route fails closed with 404
        $v2Res = $this->runWorker(
            mobileEnabled: true,
            cached: false,
            method: 'POST',
            uri: '/api/mobile/v2/auth/login',
            body: '{}'
        );

        $this->assertSame(404, $v2Res['status']);
    }

    public function test_fresh_bootstrap_cached_when_enabled_executes_http_boundary_and_zero_v2(): void
    {
        // 1. Validation error on invalid payload returns 422 VALIDATION_FAILED through compiled route cache
        $valRes = $this->runWorker(
            mobileEnabled: true,
            cached: true,
            method: 'POST',
            uri: '/api/mobile/v1/auth/challenge',
            body: '{}'
        );

        $this->assertTrue($valRes['routes_are_cached']);
        $this->assertSame(49, $valRes['v1_count']);
        $this->assertSame(0, $valRes['v2_count']);
        $this->assertSame([], $valRes['v2_routes']);
        $this->assertSame(422, $valRes['status']);
        $this->assertSame('VALIDATION_FAILED', $valRes['body']['error']['code']);
        $this->assertArrayHasKey('device_id', $valRes['body']['error']['details']);

        // 2. Unauthenticated request to protected company endpoint returns 401 UNAUTHENTICATED
        $authRes = $this->runWorker(
            mobileEnabled: true,
            cached: true,
            method: 'GET',
            uri: '/api/mobile/v1/companies/COMP-123/bootstrap'
        );

        $this->assertSame(401, $authRes['status']);
        $this->assertSame('UNAUTHENTICATED', $authRes['body']['error']['code']);

        // 3. V2 route fails closed with 404
        $v2Res = $this->runWorker(
            mobileEnabled: true,
            cached: true,
            method: 'POST',
            uri: '/api/mobile/v2/auth/login',
            body: '{}'
        );

        $this->assertSame(404, $v2Res['status']);
    }
}
