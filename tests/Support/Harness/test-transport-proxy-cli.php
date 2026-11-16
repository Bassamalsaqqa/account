<?php

declare(strict_types=1);

// SAFE AUTOLOAD FIRST
require_once __DIR__.'/../../../vendor/autoload.php';

use Tests\Support\Harness\DisposableMobileV2BackendHarness;
use Tests\Support\Harness\TestTransportProxy;

/**
 * CLI Runner for Mobile V2 Test Transport Proxy.
 *
 * Strict Security Invariants:
 * 1. Default-deny: refuses execution without mandatory --config=<path>.
 * 2. Autoload loaded first before referencing any harness classes or constants.
 * 3. Config file must be verified: absolute path, no symlinks, parent 0700 owned by current UID,
 *    file 0600 owned by current UID.
 * 4. DB credentials loaded exclusively from verified private credentials file (never on CLI argv).
 * 5. Fixed loopback ports only (18080/18081) and DB port 3342 on 127.0.0.1; arbitrary ports denied.
 * 6. Enforces exact scope ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION and unexpired TTL.
 */

$argvList = $_SERVER['argv'] ?? [];
$configFile = null;

foreach (array_slice($argvList, 1) as $arg) {
    if (str_starts_with($arg, '--config=')) {
        $configFile = substr($arg, 9);
    } else {
        fwrite(STDERR, "Error: Unknown argument '{$arg}'. Transport proxy accepts only --config=<path>.\n");
        exit(1);
    }
}

if ($configFile === null || $configFile === '') {
    fwrite(STDERR, "Error: Missing mandatory --config=<path>. TestTransportProxy defaults to NO-EXECUTE.\n");
    exit(1);
}

// 1. Security verification on config path
function verifyPathSecurity(string $filePath, string $description, int $expectedFilePerms = 0600): void
{
    if (! str_starts_with($filePath, '/')) {
        throw new RuntimeException("Security violation: {$description} '{$filePath}' must be an absolute path.");
    }

    // Assert no symlink components in entire path
    $parts = explode('/', trim($filePath, '/'));
    $current = '';
    foreach ($parts as $part) {
        $current .= '/'.$part;
        if (is_link($current)) {
            throw new RuntimeException("Security violation: {$description} component '{$current}' is a symlink.");
        }
    }

    $parentDir = dirname($filePath);
    if (! is_dir($parentDir)) {
        throw new RuntimeException("Security violation: {$description} parent directory '{$parentDir}' does not exist.");
    }

    $parentPerms = fileperms($parentDir) & 0777;
    if ($parentPerms !== 0700) {
        throw new RuntimeException(sprintf("Security violation: {$description} parent directory permissions must be 0700, got %04o.", $parentPerms));
    }

    if (fileowner($parentDir) !== getmyuid()) {
        throw new RuntimeException("Security violation: {$description} parent directory must be owned by current UID.");
    }

    if (! is_file($filePath)) {
        throw new RuntimeException("Security violation: {$description} '{$filePath}' does not exist or is not a regular file.");
    }

    $filePerms = fileperms($filePath) & 0777;
    if ($filePerms !== $expectedFilePerms) {
        throw new RuntimeException(sprintf("Security violation: {$description} file permissions must be %04o, got %04o.", $expectedFilePerms, $filePerms));
    }

    if (fileowner($filePath) !== getmyuid()) {
        throw new RuntimeException("Security violation: {$description} file must be owned by current UID.");
    }
}

try {
    verifyPathSecurity($configFile, 'Config file', 0600);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: Config file security verification failed: {$e->getMessage()}\n");
    exit(1);
}

$rawJson = (string) file_get_contents($configFile);
$config = json_decode($rawJson, true);
if (! is_array($config)) {
    fwrite(STDERR, "Error: Config file does not contain valid JSON.\n");
    exit(1);
}

// 2. Validate mandatory Root advanced scope
$expectedScope = DisposableMobileV2BackendHarness::SCOPE_ADVANCED_EXECUTION;
if (($config['scope'] ?? '') !== $expectedScope) {
    fwrite(STDERR, "Error: Scope mismatch: expected '{$expectedScope}', got '".($config['scope'] ?? 'null')."'.\n");
    exit(1);
}

// 3. Validate TTL expiration
$ttlExpiresAt = (string) ($config['ttl_expires_at'] ?? '');
if ($ttlExpiresAt === '' || strtotime($ttlExpiresAt) === false) {
    fwrite(STDERR, "Error: Config missing valid ISO8601 ttl_expires_at.\n");
    exit(1);
}
if (strtotime($ttlExpiresAt) < time()) {
    fwrite(STDERR, "Error: Transport proxy grant expired at '{$ttlExpiresAt}'.\n");
    exit(1);
}

// 4. Secure DB credentials extraction from verified credentials file
$credentialsFile = (string) ($config['credentials_file'] ?? '');
if ($credentialsFile === '') {
    fwrite(STDERR, "Error: Config missing mandatory credentials_file path.\n");
    exit(1);
}

try {
    verifyPathSecurity($credentialsFile, 'DB credentials file', 0600);
    $rawCreds = (string) file_get_contents($credentialsFile);
    $creds = json_decode($rawCreds, true);
    if (! is_array($creds) || ! isset($creds['db_user']) || ! isset($creds['db_password'])) {
        throw new RuntimeException('Credentials file must contain db_user and db_password.');
    }
    $dbUser = (string) $creds['db_user'];
    $dbPass = (string) $creds['db_password'];
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: Credentials verification failed: {$e->getMessage()}\n");
    exit(1);
}

// 5. Verified DisposableMariaDbSchema DB ownership proof assertion
$targetSchema = (string) ($config['target_schema'] ?? '');
$proofPath = (string) ($config['proof_path'] ?? '');
$proofNonce = (string) ($config['proof_nonce'] ?? '');

if ($proofPath === '' || $proofNonce === '') {
    fwrite(STDERR, "Error: Config missing mandatory proof_path or proof_nonce for schema ownership assertion.\n");
    exit(1);
}

try {
    verifyPathSecurity($proofPath, 'DB ownership proof file', 0600);
    if (! preg_match('/^[0-9a-f]{32,64}$/', $proofNonce)) {
        throw new RuntimeException('DB ownership proof nonce must be 32-64 hex characters.');
    }

    putenv('APP_ENV=testing');
    putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
    putenv("PHASE8_DISPOSABLE_DB_PROOF={$proofPath}");
    putenv("PHASE8_DISPOSABLE_DB_NONCE={$proofNonce}");
    putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
    putenv('PHASE8_TEST_DB_PORT=3342');

    \Tests\Support\DisposableMariaDbSchema::assertPrimarySchema($targetSchema);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: DB schema ownership proof assertion failed: {$e->getMessage()}\n");
    exit(1);
}

// 6. Construct proxy configuration with fixed loopback ports only
$proxyConfig = [
    'listen_port' => DisposableMobileV2BackendHarness::BACKEND_LOOPBACK_PORT,
    'upstream_port' => DisposableMobileV2BackendHarness::BACKEND_INTERNAL_PORT,
    'target_event_id' => (string) ($config['target_event_id'] ?? ''),
    'target_schema' => $targetSchema,
    'target_company_id' => (int) ($config['target_company_id'] ?? 0),
    'target_company_public_id' => (string) ($config['target_company_public_id'] ?? ''),
    'expected_digest' => (string) ($config['expected_digest'] ?? ''),
    'target_nonce_hash' => (string) ($config['target_nonce_hash'] ?? ''),
    'witness_path' => (string) ($config['witness_path'] ?? ''),
    'db_host' => '127.0.0.1',
    'db_port' => TestTransportProxy::DEFAULT_DB_PORT,
    'db_user' => $dbUser,
    'db_password' => $dbPass,
    'owner_pid' => (int) ($config['owner_pid'] ?? 0),
    'ttl_expires_at' => $ttlExpiresAt,
];

try {
    $proxy = new TestTransportProxy($proxyConfig);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: Proxy initialization failed: {$e->getMessage()}\n");
    exit(1);
}

// 6. Setup signal handling
if (function_exists('pcntl_signal')) {
    $termHandler = function (int $signo) use ($proxy): void {
        $proxy->stop();
    };
    pcntl_signal(SIGTERM, $termHandler);
    pcntl_signal(SIGINT, $termHandler);
}

try {
    $proxy->run();
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Proxy error: {$e->getMessage()}\n");
    exit(1);
}
