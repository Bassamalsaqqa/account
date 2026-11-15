<?php

declare(strict_types=1);

use Tests\Support\Harness\DisposableMobileV2BackendHarness;
use Tests\Support\Harness\TestTransportProxy;

// CLI entrypoint for Mobile V2 Test Transport Proxy
// Intercepts traffic between Caddy TLS proxy and PHP HTTP backend to simulate true socket drops.
// Autoload only; zero framework bootstrap.

$argvList = $_SERVER['argv'] ?? [];

$listenPort = DisposableMobileV2BackendHarness::BACKEND_LOOPBACK_PORT;
$upstreamPort = DisposableMobileV2BackendHarness::BACKEND_INTERNAL_PORT;
$configFile = null;
$targetEventId = null;
$targetSchema = null;
$targetCompanyId = null;
$expectedDigest = null;
$witnessPath = null;
$dbHost = '127.0.0.1';
$dbPort = 3342;
$dbUser = 'root';
$dbPassword = '';

foreach (array_slice($argvList, 1) as $arg) {
    if (str_starts_with($arg, '--listen-port=')) {
        $listenPort = (int) substr($arg, 14);
    } elseif (str_starts_with($arg, '--upstream-port=')) {
        $upstreamPort = (int) substr($arg, 16);
    } elseif (str_starts_with($arg, '--config=')) {
        $configFile = substr($arg, 9);
    } elseif (str_starts_with($arg, '--target-event-id=')) {
        $targetEventId = substr($arg, 18);
    } elseif (str_starts_with($arg, '--target-schema=')) {
        $targetSchema = substr($arg, 16);
    } elseif (str_starts_with($arg, '--target-company-id=')) {
        $targetCompanyId = (int) substr($arg, 20);
    } elseif (str_starts_with($arg, '--expected-digest=')) {
        $expectedDigest = substr($arg, 18);
    } elseif (str_starts_with($arg, '--witness-path=')) {
        $witnessPath = substr($arg, 15);
    } elseif (str_starts_with($arg, '--db-host=')) {
        $dbHost = substr($arg, 10);
    } elseif (str_starts_with($arg, '--db-port=')) {
        $dbPort = (int) substr($arg, 10);
    } elseif (str_starts_with($arg, '--db-user=')) {
        $dbUser = substr($arg, 10);
    } elseif (str_starts_with($arg, '--db-password=')) {
        $dbPassword = substr($arg, 14);
    }
}

if ($configFile !== null && is_file($configFile)) {
    $cfgData = json_decode((string) file_get_contents($configFile), true);
    if (is_array($cfgData)) {
        $listenPort = (int) ($cfgData['listen_port'] ?? $listenPort);
        $upstreamPort = (int) ($cfgData['upstream_port'] ?? $upstreamPort);
        $targetEventId = (string) ($cfgData['target_event_id'] ?? $targetEventId);
        $targetSchema = (string) ($cfgData['target_schema'] ?? $targetSchema);
        $targetCompanyId = (int) ($cfgData['target_company_id'] ?? $targetCompanyId);
        $expectedDigest = (string) ($cfgData['expected_digest'] ?? $expectedDigest);
        $witnessPath = (string) ($cfgData['witness_path'] ?? $witnessPath);
        $dbHost = (string) ($cfgData['db_host'] ?? $dbHost);
        $dbPort = (int) ($cfgData['db_port'] ?? $dbPort);
        $dbUser = (string) ($cfgData['db_user'] ?? $dbUser);
        $dbPassword = (string) ($cfgData['db_password'] ?? $dbPassword);
    }
}

require __DIR__.'/../../../vendor/autoload.php';

$proxyConfig = [
    'listen_port' => $listenPort,
    'upstream_port' => $upstreamPort,
    'target_event_id' => $targetEventId,
    'target_schema' => $targetSchema,
    'target_company_id' => $targetCompanyId,
    'expected_digest' => $expectedDigest,
    'witness_path' => $witnessPath,
    'db_host' => $dbHost,
    'db_port' => $dbPort,
    'db_user' => $dbUser,
    'db_password' => $dbPassword,
];

$proxy = new TestTransportProxy($proxyConfig);

// Setup signal handling if pcntl available
if (function_exists('pcntl_signal')) {
    $shutdown = function () use ($proxy) {
        $proxy->stop();
        exit(0);
    };
    pcntl_signal(SIGTERM, $shutdown);
    pcntl_signal(SIGINT, $shutdown);
}

$proxy->run();
