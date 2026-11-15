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
 *   php tests/Support/Harness/mobile-v2-harness-cli.php --provision --backend-head=<40hex> --android-head=<40hex> --pairing-manifest-sha=<64hex>
 *   php tests/Support/Harness/mobile-v2-harness-cli.php --run --backend-head=<40hex> --android-head=<40hex> --pairing-manifest-sha=<64hex>
 */
class MobileV2HarnessCommand extends Command
{
    protected $signature = 'mobile:v2-harness
        {--prepare               : Source-only phase: validate invariants and emit TLS config source (no sockets, no framework boot)}
        {--provision             : Self-test provision: provision disposable schema, assert PDO 3342, and clean up}
        {--readiness-only        : Strictly bounded readiness self-test: checks only /_harness/readiness nonce, financial endpoints blocked}
        {--readiness-hold-seconds=0 : Number of seconds (0..120) to hold readiness state before cleanup}
        {--paired-hold-seconds=  : Number of seconds (1..120) for finite automated paired run hold before deadline completion}
        {--start                 : Start transport lifecycle (requires existing live harness owner)}
        {--run                   : Foreground paired lifecycle: provision schema, start supervised transport, wait for shutdown, guaranteed cleanup}
        {--backend-head=         : Full 40-hex SHA of the current backend worktree HEAD (mandatory)}
        {--android-head=         : Full 40-hex SHA of the peer Android integration HEAD}
        {--pairing-manifest=     : Path to integration pairing manifest (defaults to docs/integration/mobile-v2-integration-manifest.json)}
        {--pairing-manifest-sha= : Mandatory SHA-256 pin of the pairing manifest}
        {--expected-scope=       : Expected execution scope (e.g. ROOT_AUTHORIZED_PAIRED_EXECUTION or ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION)}
        {--advanced-probes       : Enable advanced probe execution (ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION)}';

    protected $description = 'Drive the Mobile V2 paired HTTPS integration harness (source-only by default)';

    public function handle(): int
    {
        $prepare = (bool) $this->option('prepare');
        $provision = (bool) $this->option('provision');
        $readinessOnly = (bool) $this->option('readiness-only');
        $start = (bool) $this->option('start');
        $run = (bool) $this->option('run');

        $rawHoldSeconds = $this->option('readiness-hold-seconds');
        try {
            $holdSeconds = DisposableMobileV2BackendHarness::assertReadinessHoldBounds($rawHoldSeconds ?? 0);
            DisposableMobileV2BackendHarness::assertPcntlAvailableForHold($holdSeconds);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->error('Invalid --readiness-hold-seconds: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($holdSeconds > 0 && ! $readinessOnly) {
            $this->error('Option --readiness-hold-seconds is strictly restricted to --readiness-only mode.');

            return self::FAILURE;
        }

        $rawPairedHoldSeconds = $this->option('paired-hold-seconds');
        $pairedHoldSeconds = 0;
        if ($rawPairedHoldSeconds !== null) {
            try {
                $pairedHoldSeconds = DisposableMobileV2BackendHarness::assertPairedHoldBounds($rawPairedHoldSeconds);
                DisposableMobileV2BackendHarness::assertPcntlAvailableForPairedHold($pairedHoldSeconds);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $this->error('Invalid --paired-hold-seconds: '.$e->getMessage());

                return self::FAILURE;
            }

            if (! $run) {
                $this->error('Option --paired-hold-seconds is strictly restricted to --run mode.');

                return self::FAILURE;
            }
        }

        if (! $prepare && ! $provision && ! $readinessOnly && ! $start && ! $run) {
            $prepare = true;
        }

        $backendHead = (string) ($this->option('backend-head') ?? '');
        $androidHead = (string) ($this->option('android-head') ?? '');
        $pairingManifest = $this->option('pairing-manifest') ? (string) $this->option('pairing-manifest') : null;
        $pairingManifestSha = $this->option('pairing-manifest-sha') ? (string) $this->option('pairing-manifest-sha') : null;

        if ($prepare) {
            return $this->handlePrepare();
        }

        if ($backendHead === '') {
            $this->error('Mandatory option --backend-head=<40hex> is required.');

            return self::FAILURE;
        }

        $advancedProbes = (bool) $this->option('advanced-probes');
        $expectedScope = (string) ($this->option('expected-scope') ?? '');
        if ($advancedProbes && $expectedScope === '') {
            $expectedScope = DisposableMobileV2BackendHarness::SCOPE_ADVANCED_EXECUTION;
        }

        if ($readinessOnly) {
            return $this->handleReadinessOnly($backendHead, $androidHead, $pairingManifest, $pairingManifestSha, $holdSeconds);
        }

        if ($run) {
            return $this->handleRun($backendHead, $androidHead, $pairingManifest, $pairingManifestSha, $pairedHoldSeconds, $expectedScope ?: null, $advancedProbes);
        }

        if ($start) {
            return $this->handleStart($backendHead, $androidHead, $pairingManifest, $pairingManifestSha, $pairedHoldSeconds);
        }

        if ($provision) {
            return $this->handleProvision($backendHead, $androidHead, $pairingManifest, $pairingManifestSha);
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
        $this->line('    php tests/Support/Harness/mobile-v2-harness-cli.php --run --backend-head=<40hex> --android-head=<40hex> \\');
        $this->line('      --pairing-manifest-sha=<64hex>');
        $this->newLine();
        $this->info('Prepare phase complete. Zero listeners started.');

        return self::SUCCESS;
    }

    private function handleProvision(
        string $backendHead,
        string $androidHead,
        ?string $pairingManifest = null,
        ?string $pairingManifestSha = null
    ): int {
        $this->line('Mobile V2 Harness — Provision Self-Test');

        if (getenv('MOBILE_V2_HARNESS_ENABLE') !== '1') {
            $this->error('Refused: MOBILE_V2_HARNESS_ENABLE must be 1.');

            return self::FAILURE;
        }

        if ($androidHead === '') {
            $this->error('--provision requires --android-head=<40hex>.');

            return self::FAILURE;
        }

        if ($pairingManifestSha === null || $pairingManifestSha === '') {
            $this->error('--provision requires --pairing-manifest-sha=<64hex>.');

            return self::FAILURE;
        }

        $harness = new DisposableMobileV2BackendHarness;

        try {
            $result = $harness->provision(
                expectedBackendHead: $backendHead,
                peerAndroidHead: $androidHead,
                manifestPath: $pairingManifest,
                manifestShaPin: $pairingManifestSha,
            );

            $manifest = $result['manifest'];
            $this->info('Provision self-test succeeded.');
            $this->line('  Schema           : '.$manifest['schema_name']);
            $this->line('  Company          : '.$manifest['company_public_id']);
            $this->line('  Staff actor      : '.$manifest['staff_actor_public_id']);
            $this->line('  Device ID        : '.$manifest['device_id']);
            $this->line('  Credentials      : '.$result['credentials_path'].' (chmod 0600)');
            $this->line('  Client handoff   : '.$result['handoff_path'].' (chmod 0600)');
            $this->line('  Canonical digest : '.$result['canonical_digest']);
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

    private function handleReadinessOnly(
        string $backendHead,
        string $androidHead,
        ?string $pairingManifest = null,
        ?string $pairingManifestSha = null,
        int $holdSeconds = 0
    ): int {
        $this->line('Mobile V2 Harness — Readiness-Only Probe Test (--readiness-only)');

        if (getenv('MOBILE_V2_HARNESS_ENABLE') !== '1') {
            $this->error('Refused: MOBILE_V2_HARNESS_ENABLE must be 1.');

            return self::FAILURE;
        }

        if (getenv('MOBILE_V2_HARNESS_START_LISTENER') !== '1') {
            $this->error(
                'Harness readiness probe refused: MOBILE_V2_HARNESS_START_LISTENER is not set.'.PHP_EOL.
                'Readiness listener startup requires explicit coordination grant.'.PHP_EOL.
                'Zero background servers or TLS sockets started.'
            );

            return self::FAILURE;
        }

        if ($androidHead === '') {
            $this->error('--readiness-only requires --android-head=<40hex>.');

            return self::FAILURE;
        }

        if ($pairingManifestSha === null || $pairingManifestSha === '') {
            $this->error('--readiness-only requires --pairing-manifest-sha=<64hex>.');

            return self::FAILURE;
        }

        $aborted = false;
        $signalReceived = null;
        if ($holdSeconds > 0) {
            DisposableMobileV2BackendHarness::assertPcntlAvailableForHold($holdSeconds);
            $handler = function (int $signo) use (&$aborted, &$signalReceived) {
                $aborted = true;
                $signalReceived = $signo;
            };
            if (! DisposableMobileV2BackendHarness::registerEarlyHoldSignalHandlers($handler)) {
                $this->error('Harness refused: failed to register early signal handlers for SIGINT/SIGTERM.');

                return self::FAILURE;
            }
        }

        $harness = new DisposableMobileV2BackendHarness;
        $harness->setReadinessOnly(true);

        try {
            $provision = $harness->provision(
                expectedBackendHead: $backendHead,
                peerAndroidHead: $androidHead,
                manifestPath: $pairingManifest,
                manifestShaPin: $pairingManifestSha,
            );
            $this->line('  Provision OK — schema: '.$provision['manifest']['schema_name']);

            $transport = $harness->start(
                peerAndroidHead: $androidHead,
                backendHead: $backendHead,
                manifestPath: $pairingManifest,
                manifestShaPin: $pairingManifestSha,
            );

            $this->info('Readiness probe test succeeded.');
            $this->line('  TLS proxy port   : https://127.0.0.1:'.$transport['tls_proxy_port']);
            $this->line('  Readiness probe  : verified HTTP 200 and matching nonce via CA-trusted TLS.');
            $this->line('  Client handoff   : '.$transport['handoff_path']);
            $this->line('  Financial routes : strictly blocked (HTTP 403) in child router.');

            if ($holdSeconds > 0) {
                $this->line("Holding readiness state for {$holdSeconds}s (PID ".getmypid().'). Press Ctrl+C to terminate early.');
                $elapsedMs = 0;
                $totalMs = $holdSeconds * 1000;
                while ($elapsedMs < $totalMs && ! $aborted) {
                    pcntl_signal_dispatch();
                    if ($aborted) {
                        break;
                    }
                    usleep(100000); // 100ms
                    $elapsedMs += 100;
                }
            }

            if ($aborted) {
                $sigName = $signalReceived === SIGINT ? 'SIGINT' : ($signalReceived === SIGTERM ? 'SIGTERM' : (string) $signalReceived);
                $this->error("Readiness hold ABORTED by interrupt signal {$sigName}.");

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Readiness test failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->newLine();
            $this->line('Shutting down and cleaning up owned processes, files, and schemas...');
            $harness->cleanup();
            $this->line('Cleanup completed.');
        }

        return self::SUCCESS;
    }

    private function handleRun(
        string $backendHead,
        string $androidHead,
        ?string $pairingManifest = null,
        ?string $pairingManifestSha = null,
        int $pairedHoldSeconds = 0,
        ?string $expectedScope = null,
        bool $advancedProbes = false
    ): int {
        $this->line('Mobile V2 Harness — Foreground Paired Lifecycle (--run)');
        if ($advancedProbes) {
            $this->line('  Advanced probe execution enabled (scope: '.($expectedScope ?: DisposableMobileV2BackendHarness::SCOPE_ADVANCED_EXECUTION).')');
        }

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

        if ($pairingManifestSha === null || $pairingManifestSha === '') {
            $this->error('--run requires --pairing-manifest-sha=<64hex>.');

            return self::FAILURE;
        }

        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_signal_dispatch')) {
            $this->error('Harness run refused: pcntl extension is required for safe supervised process signal management.');

            return self::FAILURE;
        }

        // Handler REGISTRATION before kernel DB, sockets, provision, or transport start
        $aborted = false;
        $signalReceived = null;
        $handler = function (int $signo) use (&$aborted, &$signalReceived): void {
            $aborted = true;
            $signalReceived = $signo;
        };

        if (! DisposableMobileV2BackendHarness::registerEarlyHoldSignalHandlers($handler)) {
            $this->error('Harness refused: failed to register early signal handlers for SIGINT/SIGTERM.');

            return self::FAILURE;
        }

        $harness = new DisposableMobileV2BackendHarness;

        try {
            // Safe interrupted setup check 1: before provision
            pcntl_signal_dispatch();
            if ($aborted) {
                $sigName = $signalReceived === SIGINT ? 'SIGINT' : ($signalReceived === SIGTERM ? 'SIGTERM' : (string) $signalReceived);
                $this->error("Paired run ABORTED by interrupt signal {$sigName}.");

                return self::FAILURE;
            }

            $provision = $harness->provision(
                expectedBackendHead: $backendHead,
                peerAndroidHead: $androidHead,
                manifestPath: $pairingManifest,
                manifestShaPin: $pairingManifestSha,
            );
            $this->line('  Provision OK — schema: '.$provision['manifest']['schema_name']);

            // Safe interrupted setup check 2: between provision and start
            pcntl_signal_dispatch();
            if ($aborted) {
                $sigName = $signalReceived === SIGINT ? 'SIGINT' : ($signalReceived === SIGTERM ? 'SIGTERM' : (string) $signalReceived);
                $this->error("Paired run ABORTED by interrupt signal {$sigName}.");

                return self::FAILURE;
            }

            $transport = $harness->start(
                peerAndroidHead: $androidHead,
                backendHead: $backendHead,
                manifestPath: $pairingManifest,
                manifestShaPin: $pairingManifestSha,
            );

            $this->info('Supervised transport active.');
            $this->line('  Backend loopback : http://127.0.0.1:'.$transport['backend_loopback_port']);
            $this->line('  TLS proxy port   : https://127.0.0.1:'.$transport['tls_proxy_port']);
            $this->line('  Android URL      : https://10.0.2.2:'.$transport['tls_proxy_port'].'/api/mobile/v2/');
            $this->line('  Test CA cert     : '.$transport['ca_cert_path'].' (import into Android trust store)');
            $this->line('  Credentials      : '.$transport['credentials_path'].' (chmod 0600)');
            $this->newLine();

            // Safe interrupted setup check 3: between start and hold loop
            pcntl_signal_dispatch();
            if ($aborted) {
                $sigName = $signalReceived === SIGINT ? 'SIGINT' : ($signalReceived === SIGTERM ? 'SIGTERM' : (string) $signalReceived);
                $this->error("Paired run ABORTED by interrupt signal {$sigName}.");

                return self::FAILURE;
            }

            if ($pairedHoldSeconds > 0) {
                $this->line("Holding paired state for {$pairedHoldSeconds}s (PID ".getmypid().'). Press Ctrl+C to terminate early.');
                $elapsedMs = 0;
                $totalMs = $pairedHoldSeconds * 1000;
                while ($elapsedMs < $totalMs && ! $aborted) {
                    pcntl_signal_dispatch();
                    if ($aborted) {
                        break;
                    }
                    usleep(100000); // 100ms
                    $elapsedMs += 100;
                }
            } else {
                $this->warn('Press Ctrl+C to stop supervised session and trigger bounded cleanup.');
                while (! $aborted) {
                    pcntl_signal_dispatch();
                    if ($aborted) {
                        break;
                    }
                    usleep(500000); // 500ms
                }
            }

            if ($aborted) {
                $sigName = $signalReceived === SIGINT ? 'SIGINT' : ($signalReceived === SIGTERM ? 'SIGTERM' : (string) $signalReceived);
                $this->error("Paired run ABORTED by interrupt signal {$sigName}.");

                return self::FAILURE;
            }

            if ($pairedHoldSeconds > 0) {
                $this->info("Paired run deadline completed ({$pairedHoldSeconds}s hold expired).");

                return self::SUCCESS;
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

    private function handleStart(
        string $backendHead,
        string $androidHead,
        ?string $pairingManifest = null,
        ?string $pairingManifestSha = null,
        int $pairedHoldSeconds = 0
    ): int {
        $this->warn('Note: Standalone --start should be used within an active harness lifecycle.');
        $this->line('For full paired integration, use --run which provisions and starts under a single supervised owner.');

        return $this->handleRun($backendHead, $androidHead, $pairingManifest, $pairingManifestSha, $pairedHoldSeconds);
    }
}
