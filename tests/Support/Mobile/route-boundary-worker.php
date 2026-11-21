<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../../vendor/autoload.php';

$cachedRoutesPath = $argv[1] ?? '';
$input = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);

if (! empty($cachedRoutesPath) && file_exists($cachedRoutesPath)) {
    putenv("APP_ROUTES_CACHE={$cachedRoutesPath}");
    $_ENV['APP_ROUTES_CACHE'] = $cachedRoutesPath;
    $_SERVER['APP_ROUTES_CACHE'] = $cachedRoutesPath;
} else {
    putenv('APP_ROUTES_CACHE');
    unset($_ENV['APP_ROUTES_CACHE'], $_SERVER['APP_ROUTES_CACHE']);
}

$mobileEnabled = (bool) ($input['mobile_enabled'] ?? false);
putenv('MOBILE_API_ENABLED='.($mobileEnabled ? 'true' : 'false'));
$_ENV['MOBILE_API_ENABLED'] = $mobileEnabled ? 'true' : 'false';
$_SERVER['MOBILE_API_ENABLED'] = $mobileEnabled ? 'true' : 'false';
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
$_ENV['APP_KEY'] = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
$_SERVER['APP_KEY'] = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

// Fresh out-of-process application bootstrap
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$routesAreCached = $app->routesAreCached();

// Inspect registered routes
$router = $app['router'];
$routes = $router->getRoutes()->getRoutes();

$v1Routes = [];
$v2Routes = [];
foreach ($routes as $route) {
    $uri = $route->uri();
    if (str_starts_with($uri, 'api/mobile/v1')) {
        $v1Routes[] = $uri;
    }
    if (str_contains($uri, 'mobile/v2') && ! str_contains($uri, 'fallbackPlaceholder')) {
        $v2Routes[] = $uri;
    }
}

// Execute actual HTTP boundary request through full kernel middleware pipeline
$method = $input['method'] ?? 'GET';
$uri = $input['uri'] ?? '/';
$headers = $input['headers'] ?? [];
$body = $input['body'] ?? '';

$server = ['REMOTE_ADDR' => '127.0.0.1'];
foreach ($headers as $k => $v) {
    $upper = strtoupper(str_replace('-', '_', $k));
    if (in_array($upper, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
        $server[$upper] = $v;
    } else {
        $server['HTTP_'.$upper] = $v;
    }
}

$request = Request::create($uri, $method, [], [], [], $server, $body);
$response = $kernel->handle($request);

$content = (string) $response->getContent();
$json = json_decode($content, true);

echo json_encode([
    'routes_are_cached' => $routesAreCached,
    'v1_count' => count($v1Routes),
    'v2_count' => count($v2Routes),
    'v2_routes' => $v2Routes,
    'status' => $response->getStatusCode(),
    'body' => $json !== null ? $json : $content,
], JSON_THROW_ON_ERROR);
