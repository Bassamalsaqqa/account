<?php

declare(strict_types=1);

namespace Tests\Support\Pilot;

use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PilotServer
{
    private const int DEFAULT_EXPIRY_SECONDS = 7200; // 2 hours

    /**
     * Finds an open non-privileged port on 127.0.0.1 within the given range.
     */
    public static function findOpenPort(int $start = 18000, int $end = 19000): int
    {
        for ($p = $start; $p <= $end; $p++) {
            $sock = @fsockopen('127.0.0.1', $p);
            if (! is_resource($sock)) {
                return $p;
            }
            fclose($sock);
        }
        throw new RuntimeException("No available loopback port found in range {$start}-{$end}.");
    }

    /**
     * Starts the reproducible tracked pilot harness on HTTPS loopback.
     *
     * @return array<string, mixed> Runtime pilot metadata (public, NO embedded secrets)
     */
    public static function start(string $stateDir, ?int $httpsPort = null, ?int $phpPort = null): array
    {
        $root = dirname(__DIR__, 3);
        if (file_exists($stateDir.'/pilot-state.json')) {
            throw new RuntimeException('Existing pilot state must be preserved; choose a new owned directory.');
        }

        if (! is_dir($stateDir)) {
            mkdir($stateDir, 0700, true);
        }
        chmod($stateDir, 0700);

        // 1. Generate Ephemeral Certificates (CA, Valid Server, Mismatch Hostname, Untrusted)
        $certsDir = $stateDir.DIRECTORY_SEPARATOR.'certs';
        $certs = PilotCertificateManager::generate($certsDir);

        // 2. Select Ephemeral Ports
        $phpPort ??= self::findOpenPort(18000, 18399);
        $httpsPort ??= self::findOpenPort(18400, 18599);
        $wrongHostnamePort = self::findOpenPort(18600, 18799);
        $untrustedPort = self::findOpenPort(18800, 18999);

        // 3. Provision Owned Disposable Database Schema on MariaDB 127.0.0.1:3342
        $adminPdo = new PDO('mysql:host=127.0.0.1;port=3342;charset=utf8mb4', 'test_runner', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $schemaName = 'accounting_p8_tmp_'.bin2hex(random_bytes(6));
        $adminPdo->exec("CREATE DATABASE `{$schemaName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $proofPath = $stateDir.DIRECTORY_SEPARATOR.'db_proof.json';
        $proofNonce = bin2hex(random_bytes(16));
        file_put_contents($proofPath, json_encode([
            'schema' => $schemaName,
            'host' => '127.0.0.1',
            'port' => '3342',
            'nonce' => $proofNonce,
        ], JSON_THROW_ON_ERROR));
        chmod($proofPath, 0600);

        $ephemeralKey = 'base64:'.base64_encode(random_bytes(32));

        $dbEnv = [
            'PHASE8_DISPOSABLE_DB_PROOF' => $proofPath,
            'PHASE8_DISPOSABLE_DB_NONCE' => $proofNonce,
            'APP_ENV' => 'testing',
            'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3342',
            'DB_USERNAME' => 'test_runner',
            'DB_PASSWORD' => '',
            'DB_DATABASE' => $schemaName,
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'MOBILE_API_ENABLED' => 'true',
            'MOBILE_V2_API_ENABLED' => 'false',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
        ];

        // Run migrations
        $migrateProc = new Process([PHP_BINARY, 'artisan', 'migrate', '--database=mysql', '--force'], $root, $dbEnv);
        $migrateProc->setTimeout(120)->mustRun();

        // Extract applied migrations list
        $stmt = $adminPdo->query("SELECT migration FROM `{$schemaName}`.migrations ORDER BY id ASC");
        $expectedMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Ensure roles and permissions exist
        $roleProc = new Process([
            PHP_BINARY,
            'artisan',
            'tinker',
            '--execute=app(App\Services\Tenancy\CompanyRoleService::class)->ensurePermissionsExist();',
        ], $root, $dbEnv);
        $roleProc->setTimeout(30)->mustRun();

        // 4. Seed Ephemeral Two-Tenant Fixtures
        $credentialFile = $stateDir.DIRECTORY_SEPARATOR.'credentials.json';
        $seederScript = $stateDir.DIRECTORY_SEPARATOR.'run_seed.php';

        $seederCode = <<<'PHP'
<?php
require '%ROOT%/vendor/autoload.php';
$app = require '%ROOT%/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $meta = Tests\Support\Pilot\PilotFixtures::seed('%CRED_FILE%');
    echo "PILOT_JSON_START\n" . json_encode($meta, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\nPILOT_JSON_END\n";
} catch (\Throwable $e) {
    echo "SEED_ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
PHP;

        $seederCode = str_replace(['%ROOT%', '%CRED_FILE%'], [$root, $credentialFile], $seederCode);
        file_put_contents($seederScript, $seederCode, LOCK_EX);
        chmod($seederScript, 0600);

        $seedEnv = $dbEnv;
        $seedEnv['APP_KEY'] = $ephemeralKey;

        $seedProcess = new Process([PHP_BINARY, $seederScript], $root, $seedEnv);
        $seedProcess->setTimeout(60)->mustRun();
        @unlink($seederScript);

        $seedOutput = $seedProcess->getOutput();
        if (! str_contains($seedOutput, 'PILOT_JSON_START') || ! str_contains($seedOutput, 'PILOT_JSON_END')) {
            throw new RuntimeException("Seeding failed:\n".$seedOutput."\n".$seedProcess->getErrorOutput());
        }

        $jsonStr = substr(
            $seedOutput,
            strpos($seedOutput, 'PILOT_JSON_START') + 16,
            strpos($seedOutput, 'PILOT_JSON_END') - (strpos($seedOutput, 'PILOT_JSON_START') + 16)
        );
        $fixtureMeta = json_decode(trim($jsonStr), true, flags: JSON_THROW_ON_ERROR);

        // 5. Generate Multi-Endpoint Caddy JSON Configuration
        $caddyJsonFile = $stateDir.DIRECTORY_SEPARATOR.'caddy.json';
        $caddyConfig = [
            'admin' => ['disabled' => true],
            'apps' => [
                'http' => [
                    'servers' => [
                        'srv0' => [
                            'listen' => ["127.0.0.1:{$httpsPort}"],
                            'routes' => [[
                                'handle' => [[
                                    'handler' => 'reverse_proxy',
                                    'upstreams' => [['dial' => "127.0.0.1:{$phpPort}"]],
                                ]],
                            ]],
                            'tls_connection_policies' => [[
                                'certificate_selection' => ['any_tag' => ['cert0']],
                            ]],
                            'automatic_https' => ['disable' => true],
                        ],
                        'srv1' => [
                            'listen' => ["127.0.0.1:{$wrongHostnamePort}"],
                            'routes' => [[
                                'handle' => [[
                                    'handler' => 'reverse_proxy',
                                    'upstreams' => [['dial' => "127.0.0.1:{$phpPort}"]],
                                ]],
                            ]],
                            'tls_connection_policies' => [[
                                'certificate_selection' => ['any_tag' => ['cert1']],
                                'default_sni' => 'wrong.hostname.local',
                            ]],
                            'automatic_https' => ['disable' => true],
                        ],
                        'srv2' => [
                            'listen' => ["127.0.0.1:{$untrustedPort}"],
                            'routes' => [[
                                'handle' => [[
                                    'handler' => 'reverse_proxy',
                                    'upstreams' => [['dial' => "127.0.0.1:{$phpPort}"]],
                                ]],
                            ]],
                            'tls_connection_policies' => [[
                                'certificate_selection' => ['any_tag' => ['cert2']],
                            ]],
                            'automatic_https' => ['disable' => true],
                        ],
                    ],
                ],
                'tls' => [
                    'certificates' => [
                        'load_files' => [
                            [
                                'certificate' => $certs['server_cert'],
                                'key' => $certs['server_key'],
                                'tags' => ['cert0'],
                            ],
                            [
                                'certificate' => $certs['mismatch_server_cert'],
                                'key' => $certs['mismatch_server_key'],
                                'tags' => ['cert1'],
                            ],
                            [
                                'certificate' => $certs['untrusted_server_cert'],
                                'key' => $certs['untrusted_server_key'],
                                'tags' => ['cert2'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents($caddyJsonFile, json_encode($caddyConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        chmod($caddyJsonFile, 0600);

        // 6. Launch PHP built-in server (detached with setsid)
        $serverScript = $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';

        $serverEnv = [
            'APP_NAME' => 'SmallTraderAccounting',
            'APP_ENV' => 'local',
            'APP_KEY' => $ephemeralKey,
            'APP_DEBUG' => 'false',
            'APP_URL' => "https://127.0.0.1:{$httpsPort}",
            'LOG_CHANNEL' => 'stack',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3342',
            'DB_DATABASE' => $schemaName,
            'DB_USERNAME' => 'test_runner',
            'DB_PASSWORD' => '',
            'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'MOBILE_API_ENABLED' => 'true',
            'MOBILE_V2_API_ENABLED' => 'false',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
        ];

        $phpPidFile = $stateDir.DIRECTORY_SEPARATOR.'php_server.pid';
        $phpLogFile = $stateDir.DIRECTORY_SEPARATOR.'php_server.log';
        $phpCmd = sprintf(
            'setsid %s -S 127.0.0.1:%d %s > %s 2>&1 & echo $! > %s',
            escapeshellarg(PHP_BINARY),
            $phpPort,
            escapeshellarg($serverScript),
            escapeshellarg($phpLogFile),
            escapeshellarg($phpPidFile)
        );

        $phpLauncher = new Process(['bash', '-c', $phpCmd], $root.'/public', $serverEnv);
        $phpLauncher->mustRun();
        $phpPid = (int) trim(file_get_contents($phpPidFile));

        // 7. Launch Caddy reverse proxy (detached with setsid)
        $caddyPidFile = $stateDir.DIRECTORY_SEPARATOR.'caddy.pid';
        $caddyLogFile = $stateDir.DIRECTORY_SEPARATOR.'caddy.log';
        $caddyCmd = sprintf(
            'setsid caddy run --config %s > %s 2>&1 & echo $! > %s',
            escapeshellarg($caddyJsonFile),
            escapeshellarg($caddyLogFile),
            escapeshellarg($caddyPidFile)
        );

        $caddyLauncher = new Process(['bash', '-c', $caddyCmd], $stateDir);
        $caddyLauncher->mustRun();
        $caddyPid = (int) trim(file_get_contents($caddyPidFile));

        // 8. Readiness check (HTTPS loopback with CA verification)
        $ready = false;
        $baseUrl = "https://127.0.0.1:{$httpsPort}";
        for ($i = 0; $i < 20; $i++) {
            usleep(250000); // 250ms
            $ch = curl_init("{$baseUrl}/api/mobile/v1/auth/challenge");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'device_id' => '00000000-0000-4000-8000-000000000001',
                    'app_version' => '1.0.0',
                    'platform' => 'android',
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_CAINFO => $certs['ca_cert'],
                CURLOPT_TIMEOUT => 2,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($response) && str_contains($response, 'challenge_id')) {
                $ready = true;
                break;
            }
        }

        if (! $ready) {
            self::stop($stateDir, false);
            throw new RuntimeException("Pilot server failed HTTPS readiness check on {$baseUrl}.");
        }

        $startTime = now()->toIso8601String();
        $expiryTime = now()->addSeconds(self::DEFAULT_EXPIRY_SECONDS)->toIso8601String();

        $wrongHostnameUrl = "https://127.0.0.1:{$wrongHostnamePort}";
        $untrustedCertUrl = "https://127.0.0.1:{$untrustedPort}";

        $identity = static function (int $pid): string {
            $stat = file_get_contents('/proc/'.$pid.'/stat');
            $fields = preg_split('/\s+/', trim(substr($stat, strrpos($stat, ')') + 1)));

            return $fields[19];
        };

        $flagProbe = new Process([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode(["mobile.enabled"=>config("mobile.enabled"),"mobile.v2_enabled"=>config("mobile.v2_enabled")]);',
        ], $root, $serverEnv);
        $flagProbe->mustRun();
        $flags = json_decode($flagProbe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if ($flags !== ['mobile.enabled' => true, 'mobile.v2_enabled' => false]) {
            throw new RuntimeException('Pilot activation flags do not match the reviewed read-only scope.');
        }

        // 9. Build Consensus State and Handoff Structure (camelCase required fields)
        $state = [
            'status' => 'RUNNING',
            'baseUrl' => $baseUrl,
            'backendCommitSha' => trim((new Process(['git', 'rev-parse', 'HEAD'], $root))->mustRun()->getOutput()),
            'manifestDigest' => [
                'candidateRev3' => 'e8e3fe672a8f7aceb3152c3dd990b4fb137fa517b24038e468659e0fdf5a87a7',
                'primaryPinRev2' => 'ba75352349e552f53b02ad7159570171f88943dc26e5bdf2a4b013386b0d0659',
            ],
            'credentialsPath' => $credentialFile,
            'deviceId' => $fixtureMeta['scenarios']['SCN-01-CHALLENGE']['device_id'],
            'deviceName' => 'Pixel 8 Pilot',
            'appVersion' => '1.0.0',
            'platform' => 'android',
            'companyId' => $fixtureMeta['company_a']['public_id'],
            'invalidCompanyId' => $fixtureMeta['company_b']['public_id'],
            'caCertPath' => $certs['ca_cert'],
            'wrongHostnameUrl' => $wrongHostnameUrl,
            'untrustedCertUrl' => $untrustedCertUrl,
            'expectedMigrations' => $expectedMigrations,
            'resolvedFlags' => [
                'mobileV1' => $flags['mobile.enabled'],
                'mobileV2' => $flags['mobile.v2_enabled'],
                'financial_v2_active' => false,
                'offline_capture_active' => false,
                'mobile.enabled' => $flags['mobile.enabled'],
                'mobile.v2_enabled' => $flags['mobile.v2_enabled'],
            ],
            'ttlExpiresAt' => $expiryTime,
            // Internal runner references
            'https_port' => $httpsPort,
            'wrong_hostname_port' => $wrongHostnamePort,
            'untrusted_port' => $untrustedPort,
            'php_port' => $phpPort,
            'processIdentities' => ['php_pid' => $identity($phpPid), 'caddy_pid' => $identity($caddyPid)],
            'php_pid' => $phpPid,
            'caddy_pid' => $caddyPid,
            'schema_name' => $schemaName,
            'db_host' => '127.0.0.1',
            'db_port' => 3342,
            'db_user' => 'test_runner',
            'start_time' => $startTime,
            'fixtures' => $fixtureMeta,
        ];

        $stateFile = $stateDir.DIRECTORY_SEPARATOR.'pilot-state.json';
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        chmod($stateFile, 0600);

        $handoffFile = $stateDir.DIRECTORY_SEPARATOR.'handoff.json';
        file_put_contents($handoffFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        chmod($handoffFile, 0644);

        $expiry = new Process(['bash', '-c', 'setsid python3 '.escapeshellarg(__DIR__.'/expire-pilot.py').' '.escapeshellarg($stateFile).' > /dev/null 2>&1 &']);
        $expiry->mustRun();

        return $state;
    }

    /**
     * Gracefully stops the pilot server components.
     */
    public static function stop(string $stateDir, bool $dropSchema = false): void
    {
        $stateFile = $stateDir.DIRECTORY_SEPARATOR.'pilot-state.json';
        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            $phpPid = $state['php_pid'] ?? null;
            $caddyPid = $state['caddy_pid'] ?? null;
            $schemaName = $state['schema_name'] ?? null;

            foreach (['caddy_pid' => $caddyPid, 'php_pid' => $phpPid] as $name => $pid) {
                if ($pid && is_file('/proc/'.$pid.'/stat')) {
                    $stat = file_get_contents('/proc/'.$pid.'/stat');
                    $fields = preg_split('/\s+/', trim(substr($stat, strrpos($stat, ')') + 1)));
                    if (($state['processIdentities'][$name] ?? null) !== $fields[19]) {
                        throw new RuntimeException('Process ownership changed; refusing to stop it.');
                    }
                    posix_kill((int) $pid, SIGTERM);
                }
            }

            if ($dropSchema && $schemaName && preg_match('/^accounting_p8_tmp_[a-f0-9]{12}$/D', $schemaName)) {
                try {
                    $pdo = new PDO('mysql:host=127.0.0.1;port=3342', 'test_runner', '');
                    $pdo->exec("DROP DATABASE IF EXISTS `{$schemaName}`");
                } catch (\Throwable) {
                    // Ignore drop errors on shutdown
                }
            }

            @unlink($stateFile);
        }
    }
}
