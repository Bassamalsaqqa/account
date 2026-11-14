<?php

declare(strict_types=1);

namespace Tests\Support\Harness;

use Illuminate\Console\Command;

/**
 * Minimal CLI harness driver for the Mobile V2 paired HTTPS integration backend.
 * Placed under tests/Support/Harness (not app/Console/Commands) to prevent production auto-discovery.
 *
 * Command lifecycle:
 *   --prepare   Source-only validation: asserts invariants, generates TLS config source.
 *               Default mode (zero sockets, zero DB connections, zero listeners).
 *   --provision  Self-test provision: provisions schema, verifies @@port 3342, and cleans up.
 *   --run       Foreground paired lifecycle: provisions schema, launches supervised
 *               backend & Caddy TLS proxy, maintains supervision loop, and runs guaranteed cleanup on exit.
 *   --start     Launches the supervised PHP HTTP backend and Caddy TLS proxy.
 *
 * Usage examples:
 *   php tests/Support/Harness/mobile-v2-harness-cli.php --prepare
 *   php tests/Support/Harness/mobile-v2-harness-cli.php --provision --backend-head=<40hex>
 *   php tests/Support/Harness/mobile-v2-harness-cli.php --run --backend-head=<40hex> --android-head=<40hex>
 */
class MobileV2HarnessCommand extends Command
{
    protected $signature = 'mobile:v2-harness
        {--prepare         : Source-only phase: validate invariants and emit TLS config source (no sockets, no framework boot)}
        {--provision       : Self-test provision: provision disposable schema, assert PDO 3342, and clean up}
        {--start           : Start transport lifecycle (requires existing live harness owner)}
        {--run             : Foreground paired lifecycle: provision schema, start supervised transport, wait for shutdown, guaranteed cleanup}
        {--backend-head=   : Full 40-hex SHA of the current backend worktree HEAD (mandatory)}
        {--android-head=   : Full 40-hex SHA of the peer Android integration HEAD}';

    protected $description = 'Drive the Mobile V2 paired HTTPS integration harness (source-only by default)';

    public function handle(): int
    {
        $prepare = (bool) $this->option('prepare');
        $provision = (bool) $this->option('provision');
        $start = (bool) $this->option('start');
        $run = (bool) $this->option('run');

        if (! $prepare && ! $provision && ! $start && ! $run) {
            $prepare = true;
        }

        $backendHead = (string) ($this->option('backend-head') ?? '');
        $androidHead = (string) ($this->option('android-head') ?? '');

        if ($prepare) {
            return $this->handlePrepare();
        }

        if ($backendHead === '') {
            $this->error('Mandatory option --backend-head=<40hex> is required.');

            return self::FAILURE;
        }

        if ($run) {
            return $this->handleRun($backendHead, $androidHead);
        }

        if ($start) {
            return $this->handleStart($backendHead, $androidHead);
        }

        if ($provision) {
            return $this->handleProvision($backendHead, $androidHead !== '' ? $androidHead : null);
        }

        return $this->handlePrepare();
    }

    private function handlePrepare(): int
    {
        $this->line('Mobile V2 Harness — Source-Only Prepare (zero sockets, zero DB)');
        $this->line('  QUALIFIED_EXECUTION_HEAD   : '.DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD);
        $this->line('  QUALIFIED_APP_TREE         : '.DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE);
        $this->line('  REQUIRED_BASE_BRANCH       : '.DisposableMobileV2BackendHarness::REQUIRED_BASE_BRANCH);
        $this->line('  BACKEND_LOOPBACK_PORT      : '.DisposableMobileV2BackendHarness::BACKEND_LOOPBACK_PORT);
        $this->line('  TLS_PROXY_PORT             : '.DisposableMobileV2BackendHarness::TLS_PROXY_PORT);
        $this->line('  ANDROID_CANDIDATE_KNOWN_BAD: '.DisposableMobileV2BackendHarness::ANDROID_CANDIDATE_KNOWN_BAD);
        $this->line('  CA5A_MANIFEST_SHA256       : '.DisposableMobileV2BackendHarness::CA5A_MANIFEST_SHA256);
        $this->line('  CA5A_CANONICAL_DIGEST      : '.DisposableMobileV2BackendHarness::CA5A_CANONICAL_DIGEST);
        $this->newLine();
        $this->line('TLS certificate will cover SAN: localhost, 127.0.0.1, 10.0.2.2 (Android emulator)');
        $this->line('Android base URL (emulator):   https://10.0.2.2:'.DisposableMobileV2BackendHarness::TLS_PROXY_PORT.'/api/mobile/v2/');
        $this->newLine();
        $this->line('To run foreground lifecycle (Root coordination required):');
        $this->line('  MOBILE_V2_HARNESS_ENABLE=1 MOBILE_V2_HARNESS_START_LISTENER=1 PHASE8_ALLOW_DISPOSABLE_DB=1 \\');
        $this->line('    PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \\');
        $this->line('    PHASE8_TEST_DB_USERNAME=<user> PHASE8_TEST_DB_PASSWORD=<pass> \\');
        $this->line('    php tests/Support/Harness/mobile-v2-harness-cli.php --run --backend-head=<40hex> --android-head=<40hex>');
        $this->newLine();
        $this->info('Prepare phase complete. Zero listeners started.');

        return self::SUCCESS;
    }

    private function handleProvision(string $backendHead, ?string $androidHead): int
    {
        $this->line('Mobile V2 Harness — Provision Self-Test');

        if (getenv('MOBILE_V2_HARNESS_ENABLE') !== '1') {
            $this->error('Refused: MOBILE_V2_HARNESS_ENABLE must be 1.');

            return self::FAILURE;
        }

        $harness = new DisposableMobileV2BackendHarness;

        try {
            $result = $harness->provision(
                expectedBackendHead: $backendHead,
                peerAndroidHead: $androidHead,
            );

            $manifest = $result['manifest'];
            $this->info('Provision self-test succeeded.');
            $this->line('  Schema       : '.$manifest['schema_name']);
            $this->line('  Company      : '.$manifest['company_public_id']);
            $this->line('  Staff actor  : '.$manifest['staff_actor_public_id']);
            $this->line('  Device ID    : '.$manifest['device_id']);
            $this->line('  Credentials  : '.$result['credentials_path'].' (chmod 0600)');
            $this->newLine();
            $this->line('Self-test complete. Running automatic bounded cleanup of disposable schema...');
        } catch (\Throwable $e) {
            $this->error('Provision failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $harness->cleanup();
            $this->line('Cleanup completed.');
        }

        return self::SUCCESS;
    }

    private function handleRun(string $backendHead, string $androidHead): int
    {
        $this->line('Mobile V2 Harness — Foreground Paired Lifecycle (--run)');

        if ($androidHead === '') {
            $this->error('--run requires --android-head=<40hex>.');

            return self::FAILURE;
        }

        if ($androidHead === DisposableMobileV2BackendHarness::ANDROID_CANDIDATE_KNOWN_BAD) {
            $this->error(
                "Harness start refused: Android candidate '{$androidHead}' is marked CANDIDATE_KNOWN_BAD.".PHP_EOL.
                'Android PR 23 review identified changes required (gateway 15-field durable OfflineSaleV2 direct POST to 7-key online cash).'.PHP_EOL.
                'Zero background servers, TLS sockets, or DB listeners started.'
            );

            return self::FAILURE;
        }

        if (getenv('MOBILE_V2_HARNESS_START_LISTENER') !== '1') {
            $this->error(
                'Harness start refused: MOBILE_V2_HARNESS_START_LISTENER is not set.'.PHP_EOL.
                'Listener startup is held pending Android thread 83e6a721 readiness and Root coordination grant.'.PHP_EOL.
                'Zero background servers, TLS sockets, or DB listeners started.'
            );

            return self::FAILURE;
        }

        if (! function_exists('pcntl_signal')) {
            $this->error('Harness run refused: pcntl extension is required for safe supervised process signal management.');

            return self::FAILURE;
        }

        $harness = new DisposableMobileV2BackendHarness;

        try {
            $provision = $harness->provision(
                expectedBackendHead: $backendHead,
                peerAndroidHead: $androidHead,
            );
            $this->line('  Provision OK — schema: '.$provision['manifest']['schema_name']);

            $transport = $harness->start(
                peerAndroidHead: $androidHead,
                backendHead: $backendHead,
            );

            $this->info('Supervised transport active.');
            $this->line('  Backend loopback : http://127.0.0.1:'.$transport['backend_loopback_port']);
            $this->line('  TLS proxy port   : https://127.0.0.1:'.$transport['tls_proxy_port']);
            $this->line('  Android URL      : https://10.0.2.2:'.$transport['tls_proxy_port'].'/api/mobile/v2/');
            $this->line('  Test CA cert     : '.$transport['ca_cert_path'].' (import into Android trust store)');
            $this->line('  Credentials      : '.$transport['credentials_path'].' (chmod 0600)');
            $this->newLine();
            $this->warn('Press Ctrl+C to stop supervised session and trigger bounded cleanup.');

            $running = true;
            pcntl_signal(SIGINT, function () use (&$running): void {
                $running = false;
            });
            pcntl_signal(SIGTERM, function () use (&$running): void {
                $running = false;
            });

            while ($running) {
                pcntl_signal_dispatch();
                usleep(500_000);
            }
        } catch (\Throwable $e) {
            $this->error('Run failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->newLine();
            $this->line('Shutting down and cleaning up owned processes, files, and schemas...');
            $harness->cleanup();
            $this->line('Cleanup completed.');
        }

        return self::SUCCESS;
    }

    private function handleStart(string $backendHead, string $androidHead): int
    {
        $this->warn('Note: Standalone --start should be used within an active harness lifecycle.');
        $this->line('For full paired integration, use --run which provisions and starts under a single supervised owner.');

        return $this->handleRun($backendHead, $androidHead);
    }
}
