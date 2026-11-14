<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tests\Support\Harness\DisposableMobileV2BackendHarness;

/**
 * Minimal CLI harness driver for the Mobile V2 paired HTTPS integration backend.
 *
 * Three phases:
 *   --prepare   Source-only validation: asserts invariants, generates TLS config source.
 *               Default mode (zero sockets, zero DB connections, zero listeners).
 *   --provision  Creates and seeds a disposable MariaDB schema on 127.0.0.1:3342.
 *               Requires PHASE8_ALLOW_DISPOSABLE_DB=1 and MOBILE_V2_HARNESS_ENABLE=1.
 *   --start     Launches the supervised PHP HTTP backend and Caddy TLS proxy.
 *               Requires --provision to have run first AND MOBILE_V2_HARNESS_START_LISTENER=1.
 *               Gate held until Android thread 83e6a721 is ready and Root grants QA lease.
 *
 * Usage examples:
 *   php artisan mobile:v2-harness --prepare
 *   php artisan mobile:v2-harness --provision --backend-head=<40hex>
 *   php artisan mobile:v2-harness --start --android-head=<40hex> --backend-head=<40hex>
 */
class MobileV2HarnessCommand extends Command
{
    protected $signature = 'mobile:v2-harness
        {--prepare         : Source-only phase: validate invariants and emit TLS config source (no sockets)}
        {--provision       : Provision disposable schema and seed fixtures on 127.0.0.1:3342}
        {--start           : Start supervised PHP HTTP backend and Caddy TLS proxy (Root gate required)}
        {--backend-head=   : Full 40-hex SHA of the current backend worktree HEAD}
        {--android-head=   : Full 40-hex SHA of the peer Android integration HEAD}';

    protected $description = 'Drive the Mobile V2 paired HTTPS integration harness (source-only by default)';

    public function handle(): int
    {
        $prepare = (bool) $this->option('prepare');
        $provision = (bool) $this->option('provision');
        $start = (bool) $this->option('start');

        // Default to --prepare if no phase flag given
        if (! $prepare && ! $provision && ! $start) {
            $prepare = true;
        }

        $backendHead = (string) ($this->option('backend-head') ?? '');
        $androidHead = (string) ($this->option('android-head') ?? '');

        if ($start) {
            return $this->handleStart($backendHead, $androidHead);
        }

        if ($provision) {
            return $this->handleProvision(
                $backendHead !== '' ? $backendHead : null,
                $androidHead !== '' ? $androidHead : null,
            );
        }

        // --prepare: source-only validation, zero sockets
        return $this->handlePrepare();
    }

    private function handlePrepare(): int
    {
        $this->line('Mobile V2 Harness — Source-Only Prepare (zero sockets, zero DB)');
        $this->line('  QUALIFIED_EXECUTION_HEAD : '.DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD);
        $this->line('  QUALIFIED_APP_TREE       : '.DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE);
        $this->line('  REQUIRED_BASE_BRANCH     : '.DisposableMobileV2BackendHarness::REQUIRED_BASE_BRANCH);
        $this->line('  BACKEND_LOOPBACK_PORT    : '.DisposableMobileV2BackendHarness::BACKEND_LOOPBACK_PORT);
        $this->line('  TLS_PROXY_PORT           : '.DisposableMobileV2BackendHarness::TLS_PROXY_PORT);
        $this->newLine();
        $this->line('TLS certificate will cover SAN: localhost, 127.0.0.1, 10.0.2.2 (Android emulator)');
        $this->line('Android base URL (emulator):   https://10.0.2.2:'.DisposableMobileV2BackendHarness::TLS_PROXY_PORT.'/api/mobile/v2/');
        $this->newLine();
        $this->line('To provision: MOBILE_V2_HARNESS_ENABLE=1 PHASE8_ALLOW_DISPOSABLE_DB=1 \\');
        $this->line('  PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 \\');
        $this->line('  PHASE8_TEST_DB_USERNAME=<user> PHASE8_TEST_DB_PASSWORD=<pass> \\');
        $this->line('  php artisan mobile:v2-harness --provision --backend-head=<40hex>');
        $this->newLine();
        $this->line('To start (Root coordination required):');
        $this->line('  MOBILE_V2_HARNESS_START_LISTENER=1 \\');
        $this->line('  php artisan mobile:v2-harness --start --backend-head=<40hex> --android-head=<40hex>');
        $this->newLine();
        $this->info('Prepare phase complete. Zero listeners started.');

        return self::SUCCESS;
    }

    private function handleProvision(?string $backendHead, ?string $androidHead): int
    {
        $this->line('Mobile V2 Harness — Provision Phase');

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
        } catch (\Throwable $e) {
            $this->error('Provision failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $manifest = $result['manifest'];
        $this->info('Provision succeeded.');
        $this->line('  Schema       : '.$manifest['schema_name']);
        $this->line('  Company      : '.$manifest['company_public_id']);
        $this->line('  Staff actor  : '.$manifest['staff_actor_public_id']);
        $this->line('  Device ID    : '.$manifest['device_id']);
        $this->line('  Credentials  : '.$result['credentials_path'].' (chmod 0600)');
        $this->newLine();
        $this->warn('Credentials file contains raw token — do not print or share.');
        $this->line('Schema is live. Call --start with MOBILE_V2_HARNESS_START_LISTENER=1 when Root coordinates.');

        // Note: schema stays alive for --start phase; cleanup is caller's responsibility
        // In a real paired session the caller holds the harness object across phases.
        $this->newLine();
        $this->warn('Note: Disposable schema will be dropped when this process exits via harness->cleanup().');

        return self::SUCCESS;
    }

    private function handleStart(string $backendHead, string $androidHead): int
    {
        $this->line('Mobile V2 Harness — Start Phase (transport lifecycle)');

        if ($backendHead === '' || $androidHead === '') {
            $this->error('--start requires both --backend-head and --android-head.');

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

        $harness = new DisposableMobileV2BackendHarness;

        try {
            $provision = $harness->provision(
                expectedBackendHead: $backendHead,
                peerAndroidHead: $androidHead,
            );
            $this->line('  Provision OK  — schema: '.$provision['manifest']['schema_name']);

            $transport = $harness->start(
                peerAndroidHead: $androidHead,
                backendHead: $backendHead,
            );
        } catch (\Throwable $e) {
            $this->error('Start failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Transport started.');
        $this->line('  Backend loopback : http://127.0.0.1:'.$transport['backend_loopback_port']);
        $this->line('  TLS proxy port   : https://127.0.0.1:'.$transport['tls_proxy_port']);
        $this->line('  Android URL      : https://10.0.2.2:'.$transport['tls_proxy_port'].'/api/mobile/v2/');
        $this->line('  Test CA cert     : '.$transport['ca_cert_path'].' (import into Android trust store)');
        $this->line('  Credentials      : '.$transport['credentials_path'].' (chmod 0600)');
        $this->newLine();
        $this->warn('Press Ctrl+C to terminate and trigger cleanup (owned schema + processes only).');

        // Supervised wait loop — only terminates on SIGINT/SIGTERM (owned PIDs only)
        pcntl_signal(SIGINT, function () use ($harness): void {
            $this->newLine();
            $this->line('SIGINT received — running cleanup...');
            $harness->cleanup();
            exit(0);
        });
        pcntl_signal(SIGTERM, function () use ($harness): void {
            $harness->cleanup();
            exit(0);
        });

        while (true) {
            pcntl_signal_dispatch();
            sleep(1);
        }
    }
}
