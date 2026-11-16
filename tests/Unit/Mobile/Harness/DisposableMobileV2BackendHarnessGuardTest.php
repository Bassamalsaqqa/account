<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Harness;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\Harness\DisposableFinancialPostRunInspector;
use Tests\Support\Harness\DisposableMobileV2BackendHarness;
use Tests\Support\Harness\MobileV2HarnessCommand;
use Tests\Support\Harness\SupervisedPairedFinancialDrill;
use Tests\Support\Harness\TestTransportProxy;
use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;

/**
 * Pure source-only guard tests for DisposableMobileV2BackendHarness.
 *
 * Exercises execution environment invariants, default-deny boundaries, port assertions,
 * and dual manifest validation WITHOUT touching any database, listening sockets, or background processes.
 */
class DisposableMobileV2BackendHarnessGuardTest extends TestCase
{
    private ?string $originalEnable;

    private ?string $originalHost;

    private ?string $originalPort;

    private ?string $originalAllowDb;

    private ?string $originalListener;

    private static ?string $currentGitHead = null;

    public static function getCurrentGitHead(): string
    {
        if (self::$currentGitHead === null) {
            $root = dirname(__DIR__, 4);
            $proc = new Process(['git', 'rev-parse', 'HEAD'], $root);
            $proc->mustRun();
            self::$currentGitHead = trim($proc->getOutput());
        }

        return self::$currentGitHead;
    }

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(SupervisedPairedFinancialDrill::class);
        class_exists(DisposableFinancialPostRunInspector::class);
        class_exists(TestTransportProxy::class);
        $this->originalEnable = getenv('MOBILE_V2_HARNESS_ENABLE') ?: null;
        $this->originalHost = getenv('PHASE8_TEST_DB_HOST') ?: null;
        $this->originalPort = getenv('PHASE8_TEST_DB_PORT') ?: null;
        $this->originalAllowDb = getenv('PHASE8_ALLOW_DISPOSABLE_DB') ?: null;
        $this->originalListener = getenv('MOBILE_V2_HARNESS_START_LISTENER') ?: null;
    }

    protected function tearDown(): void
    {
        SupervisedPairedFinancialDrill::$negativeRefusalHook = null;
        SupervisedPairedFinancialDrill::$signalDispatcherHook = null;
        DisposableFinancialPostRunInspector::$negativeReadGuard = null;
        $this->restoreEnv('MOBILE_V2_HARNESS_ENABLE', $this->originalEnable);
        $this->restoreEnv('PHASE8_TEST_DB_HOST', $this->originalHost);
        $this->restoreEnv('PHASE8_TEST_DB_PORT', $this->originalPort);
        $this->restoreEnv('PHASE8_ALLOW_DISPOSABLE_DB', $this->originalAllowDb);
        $this->restoreEnv('MOBILE_V2_HARNESS_START_LISTENER', $this->originalListener);
        parent::tearDown();
    }

    private function restoreEnv(string $key, ?string $value): void
    {
        if ($value !== null) {
            putenv("{$key}={$value}");
        } else {
            putenv($key);
        }
    }

    #[Test]
    public function test_default_deny_when_env_flag_missing(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness refused: defaults to no-execute');

        DisposableMobileV2BackendHarness::assertPreSocketInvariants(
            expectedBackendHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD
        );
    }

    #[Test]
    public function test_assert_pre_socket_invariants_rejects_non_loopback_db_host(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE=1');
        putenv('PHASE8_TEST_DB_HOST=192.168.1.100');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Harness refused: DB host must be explicitly 127.0.0.1, got '192.168.1.100'.");

        DisposableMobileV2BackendHarness::assertPreSocketInvariants(
            expectedBackendHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD
        );
    }

    #[Test]
    public function test_assert_pre_socket_invariants_rejects_wrong_db_port(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE=1');
        putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
        putenv('PHASE8_TEST_DB_PORT=3306');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Harness refused: DB port must be explicitly 3342, got '3306'.");

        DisposableMobileV2BackendHarness::assertPreSocketInvariants(
            expectedBackendHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD
        );
    }

    #[Test]
    public function test_assert_pre_socket_invariants_rejects_empty_or_invalid_backend_head_pure_format(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE=1');
        putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
        putenv('PHASE8_TEST_DB_PORT=3342');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Harness refused: expectedBackendHead must be a full 40-hex SHA, got 'invalid-head'.");

        DisposableMobileV2BackendHarness::assertPreSocketInvariants(
            expectedBackendHead: 'invalid-head'
        );
    }

    #[Test]
    public function test_assert_pre_socket_invariants_rejects_invalid_peer_android_head_format_pure_format(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE=1');
        putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
        putenv('PHASE8_TEST_DB_PORT=3342');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Harness refused: peerAndroidHead must be a valid 40-hex SHA, got 'not-a-valid-sha'.");

        // Fails pure format check before any git rev-parse execution
        DisposableMobileV2BackendHarness::assertPreSocketInvariants(
            expectedBackendHead: str_repeat('0', 40),
            peerAndroidHead: 'not-a-valid-sha'
        );
    }

    #[Test]
    public function test_assert_pre_socket_invariants_rejects_mismatched_current_git_head(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE=1');
        putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
        putenv('PHASE8_TEST_DB_PORT=3342');

        $nonMatchingValidSha = str_repeat('0', 40);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Harness refused: current git HEAD does not match expected backend HEAD '{$nonMatchingValidSha}'.");

        DisposableMobileV2BackendHarness::assertPreSocketInvariants(
            expectedBackendHead: $nonMatchingValidSha,
            peerAndroidHead: str_repeat('1', 40),
        );
    }

    // -------------------------------------------------------------------------
    // Transport lifecycle & dual manifest guard tests
    // -------------------------------------------------------------------------

    #[Test]
    public function test_start_fails_closed_when_not_provisioned(): void
    {
        $harness = new DisposableMobileV2BackendHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness refused: must be provisioned before start');

        $harness->start(
            peerAndroidHead: '0000000000000000000000000000000000000000',
            backendHead: '0000000000000000000000000000000000000000',
        );
    }

    #[Test]
    public function test_start_args_rejects_invalid_peer_android_head_format(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Harness refused: start requires valid 40-hex peerAndroidHead SHA, got 'bad-format'.");

        DisposableMobileV2BackendHarness::validateStartArgs(
            peerAndroidHead: 'bad-format',
            backendHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD
        );
    }

    #[Test]
    public function test_start_args_rejects_invalid_backend_head_format(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Harness refused: start requires valid 40-hex backendHead SHA, got 'not-a-sha'.");

        DisposableMobileV2BackendHarness::validateStartArgs(
            peerAndroidHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            backendHead: 'not-a-sha'
        );
    }

    #[Test]
    public function test_dual_manifest_pin_refuses_candidate_known_bad(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CANDIDATE_KNOWN_BAD/');

        DisposableMobileV2BackendHarness::assertDualManifestPin(
            backendHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            peerAndroidHead: DisposableMobileV2BackendHarness::ANDROID_CANDIDATE_KNOWN_BAD,
            manifestShaPin: str_repeat('a', 64),
        );
    }

    #[Test]
    public function test_dual_manifest_pin_requires_mandatory_manifest_sha_pin(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness refused: mandatory external pairing manifest SHA-256 pin is required.');

        DisposableMobileV2BackendHarness::assertDualManifestPin(
            backendHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            peerAndroidHead: '1111111111111111111111111111111111111111',
            manifestShaPin: null,
        );
    }

    #[Test]
    public function test_dual_manifest_pin_refuses_manifest_sha_mismatch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/integration manifest SHA mismatch/');

        DisposableMobileV2BackendHarness::assertDualManifestPin(
            backendHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            peerAndroidHead: '1111111111111111111111111111111111111111',
            manifestShaPin: str_repeat('f', 64),
        );
    }

    #[Test]
    public function test_dual_manifest_pin_refuses_unset_runtime_backend_execution_head_in_template(): void
    {
        $manifestPath = dirname(__DIR__, 4).'/docs/integration/mobile-v2-integration-manifest.json';
        $actualSha = hash_file('sha256', $manifestPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pairing manifest has unset runtime backend execution HEAD/');

        DisposableMobileV2BackendHarness::assertDualManifestPin(
            backendHead: self::getCurrentGitHead(),
            peerAndroidHead: '1111111111111111111111111111111111111111',
            manifestPath: $manifestPath,
            manifestShaPin: (string) $actualSha,
        );
    }

    #[Test]
    public function test_dual_manifest_pin_accepts_valid_runtime_pairing_manifest_no_sql(): void
    {
        $root = dirname(__DIR__, 4);
        $actualHead = self::getCurrentGitHead();
        $approvedPeer = '1111111111111111111111111111111111111111';

        $runtimeManifestData = [
            'contract_name' => 'Mobile V2 Online Cash Sale Integration Dual-Manifest',
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => $actualHead,
            'peer_android_approved_head_sha' => $approvedPeer,
            'peer_android_executable_head_sha' => '2222222222222222222222222222222222222222',
            'status' => DisposableMobileV2BackendHarness::SCOPE_PROVISION_ONLY,
            'source_fixture_sha256' => hash_file('sha256', $root.'/tests/Support/Harness/DisposableMobileV2BackendHarness.php'),
            'cli_command_sha256' => hash_file('sha256', $root.'/tests/Support/Harness/MobileV2HarnessCommand.php'),
            'cli_runner_sha256' => hash_file('sha256', $root.'/tests/Support/Harness/mobile-v2-harness-cli.php'),
        ];

        $tempManifestPath = tempnam(sys_get_temp_dir(), 'test_runtime_manifest_');
        $this->assertNotFalse($tempManifestPath);
        file_put_contents($tempManifestPath, json_encode($runtimeManifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $manifestShaPin = hash_file('sha256', $tempManifestPath);

        try {
            $result = DisposableMobileV2BackendHarness::assertDualManifestPin(
                backendHead: $actualHead,
                peerAndroidHead: $approvedPeer,
                manifestPath: $tempManifestPath,
                manifestShaPin: (string) $manifestShaPin,
            );

            $this->assertSame(DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD, $result['qualified_core_head']);
            $this->assertSame($actualHead, $result['backend_head']);
            $this->assertSame($approvedPeer, $result['peer_android_head']);
            $this->assertSame('2222222222222222222222222222222222222222', $result['peer_android_executable_head_sha']);
            $this->assertSame(DisposableMobileV2BackendHarness::SCOPE_PROVISION_ONLY, $result['status']);
        } finally {
            @unlink($tempManifestPath);
        }
    }

    #[Test]
    public function test_provision_refuses_null_manifest_sha_pin_without_db(): void
    {
        $harness = new DisposableMobileV2BackendHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness provision refused: mandatory external pairing manifest SHA-256 pin is required.');

        $harness->provision(
            expectedBackendHead: self::getCurrentGitHead(),
            peerAndroidHead: '1111111111111111111111111111111111111111',
            manifestShaPin: null
        );

        $this->assertFalse($harness->isProvisioned());
        $this->assertNull($harness->getSchema());
    }

    #[Test]
    public function test_start_fails_closed_without_start_listener_env_flag_using_injected_provisioned_state(): void
    {
        putenv('MOBILE_V2_HARNESS_START_LISTENER');

        $harness = new DisposableMobileV2BackendHarness;
        $harness->injectTestProvisionedState();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness start refused: MOBILE_V2_HARNESS_START_LISTENER is not set.');

        $harness->start(
            peerAndroidHead: '1111111111111111111111111111111111111111',
            backendHead: '2222222222222222222222222222222222222222',
        );
    }

    #[Test]
    public function test_start_refuses_when_harness_nonce_is_unset(): void
    {
        $harness = new DisposableMobileV2BackendHarness;
        $harness->injectTestProvisionedState(nonce: '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness start refused: harness nonce is unset. Harness must be provisioned before start.');

        $harness->start(
            peerAndroidHead: '1111111111111111111111111111111111111111',
            backendHead: '2222222222222222222222222222222222222222',
        );
    }

    #[Test]
    public function test_harness_constants_match_qualified_sources(): void
    {
        $this->assertSame(40, strlen(DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD);

        $this->assertSame(40, strlen(DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE);

        $this->assertSame(40, strlen(DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_BASE));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_BASE);
        $this->assertSame('34d1da0ae82b182fa2c0adafd785416a86834a20', DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_BASE);
        $this->assertSame(DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE, DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_BASE);

        $this->assertSame(40, strlen(DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_G1));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_G1);
        $this->assertSame('d584067cf8f8a3588f4031a4cdb2107e0fa80b4d', DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_G1);

        $this->assertSame(40, strlen(DisposableMobileV2BackendHarness::QUALIFIED_G1_BOOTSTRAP_CONTROLLER_BLOB));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', DisposableMobileV2BackendHarness::QUALIFIED_G1_BOOTSTRAP_CONTROLLER_BLOB);
        $this->assertSame('9df528b0ef80b514a2472c81be2a9203b8c1830a', DisposableMobileV2BackendHarness::QUALIFIED_G1_BOOTSTRAP_CONTROLLER_BLOB);

        $this->assertSame(40, strlen(DisposableMobileV2BackendHarness::EVIDENCE_PUBLICATION_COMMIT));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', DisposableMobileV2BackendHarness::EVIDENCE_PUBLICATION_COMMIT);

        $this->assertSame(40, strlen(DisposableMobileV2BackendHarness::ANDROID_CANDIDATE_KNOWN_BAD));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', DisposableMobileV2BackendHarness::ANDROID_CANDIDATE_KNOWN_BAD);

        $this->assertSame(40, strlen(DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD);

        $this->assertSame(40, strlen(DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD);

        $this->assertSame('t3/recover-cash-writer', DisposableMobileV2BackendHarness::REQUIRED_BASE_BRANCH);
    }

    #[Test]
    public function test_assert_admitted_app_tree_admits_qualified_base_tree(): void
    {
        $admitted = DisposableMobileV2BackendHarness::assertAdmittedAppTree(
            DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_BASE
        );

        $this->assertSame(DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_BASE, $admitted);
    }

    #[Test]
    public function test_assert_admitted_app_tree_admits_qualified_g1_tree(): void
    {
        $admitted = DisposableMobileV2BackendHarness::assertAdmittedAppTree(
            DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_G1
        );

        $this->assertSame(DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_G1, $admitted);
    }

    #[Test]
    public function test_assert_admitted_app_tree_rejects_unadmitted_third_tree(): void
    {
        $thirdTree = str_repeat('f', 40);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Harness refused: app tree '{$thirdTree}' does not match qualified base tree (".DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_BASE.') or qualified G1 read-metadata tree ('.DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_G1.').'
        );

        DisposableMobileV2BackendHarness::assertAdmittedAppTree($thirdTree);
    }

    #[Test]
    public function test_ca5a_manifest_and_canonical_digest_constants(): void
    {
        $this->assertSame(64, strlen(DisposableMobileV2BackendHarness::CA5A_MANIFEST_SHA256));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', DisposableMobileV2BackendHarness::CA5A_MANIFEST_SHA256);

        $this->assertSame(64, strlen(DisposableMobileV2BackendHarness::CA5A_CANONICAL_DIGEST));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', DisposableMobileV2BackendHarness::CA5A_CANONICAL_DIGEST);
    }

    #[Test]
    public function test_harness_transport_ports_are_non_privileged_loopback(): void
    {
        $this->assertGreaterThan(1024, DisposableMobileV2BackendHarness::BACKEND_LOOPBACK_PORT);
        $this->assertGreaterThan(1024, DisposableMobileV2BackendHarness::TLS_PROXY_PORT);

        $this->assertNotSame(3342, DisposableMobileV2BackendHarness::BACKEND_LOOPBACK_PORT);
        $this->assertNotSame(3342, DisposableMobileV2BackendHarness::TLS_PROXY_PORT);

        $this->assertNotSame(
            DisposableMobileV2BackendHarness::BACKEND_LOOPBACK_PORT,
            DisposableMobileV2BackendHarness::TLS_PROXY_PORT,
        );
    }

    #[Test]
    public function test_caddy_config_generation_includes_admin_off_and_auto_https_off(): void
    {
        $caddyfile = DisposableMobileV2BackendHarness::buildCaddyConfig(
            serverCertPath: '/test/cert.pem',
            serverKeyPath: '/test/key.pem',
            backendPort: 18080,
            tlsPort: 18443
        );

        $this->assertStringContainsString('admin off', $caddyfile);
        $this->assertStringContainsString('auto_https off', $caddyfile);
        $this->assertStringContainsString('bind 127.0.0.1', $caddyfile);
        $this->assertStringContainsString('10.0.2.2:18443', $caddyfile);
        $this->assertStringContainsString('reverse_proxy 127.0.0.1:18080', $caddyfile);
        $this->assertStringNotContainsString('0.0.0.0', $caddyfile);
    }

    #[Test]
    public function test_assert_ports_available_passes_when_ports_unbound(): void
    {
        // Port 59876 is an unassigned dynamic port; calling assertPortsAvailable on it should succeed
        DisposableMobileV2BackendHarness::assertPortsAvailable(59876);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function test_cleanup_is_idempotent_and_destructor_cleans_up(): void
    {
        $harness = new DisposableMobileV2BackendHarness;
        $harness->cleanup();
        $harness->cleanup();

        $this->assertNull($harness->getSchema());
        $this->assertNull($harness->getBackendProcess());
        $this->assertNull($harness->getCaddyProcess());
        $this->assertFalse($harness->isProvisioned());
    }

    #[Test]
    public function test_validate_readiness_response_accepts_valid_200_and_matching_nonce(): void
    {
        $headers = ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
        $body = json_encode(['status' => 'ready', 'nonce' => 'test-nonce-12345']);

        $result = DisposableMobileV2BackendHarness::validateReadinessResponse($headers, $body, 'test-nonce-12345');
        $this->assertTrue($result);
    }

    #[Test]
    public function test_validate_readiness_response_rejects_non_200_status(): void
    {
        $headers = ['HTTP/1.1 500 Internal Server Error', 'Content-Type: application/json'];
        $body = json_encode(['status' => 'ready', 'nonce' => 'test-nonce-12345']);

        $result = DisposableMobileV2BackendHarness::validateReadinessResponse($headers, $body, 'test-nonce-12345');
        $this->assertFalse($result);

        $headers403 = ['HTTP/1.1 403 Forbidden', 'Content-Type: application/json'];
        $this->assertFalse(DisposableMobileV2BackendHarness::validateReadinessResponse($headers403, $body, 'test-nonce-12345'));
    }

    #[Test]
    public function test_validate_readiness_response_rejects_mismatched_nonce_or_bad_json(): void
    {
        $headers = ['HTTP/1.1 200 OK'];

        // Mismatched nonce
        $body = json_encode(['status' => 'ready', 'nonce' => 'wrong-nonce']);
        $this->assertFalse(DisposableMobileV2BackendHarness::validateReadinessResponse($headers, $body, 'expected-nonce'));

        // Wrong status
        $bodyWrongStatus = json_encode(['status' => 'error', 'nonce' => 'expected-nonce']);
        $this->assertFalse(DisposableMobileV2BackendHarness::validateReadinessResponse($headers, $bodyWrongStatus, 'expected-nonce'));

        // Bad JSON
        $this->assertFalse(DisposableMobileV2BackendHarness::validateReadinessResponse($headers, '{malformed', 'expected-nonce'));

        // False body
        $this->assertFalse(DisposableMobileV2BackendHarness::validateReadinessResponse($headers, false, 'expected-nonce'));

        // Empty/null headers
        $this->assertFalse(DisposableMobileV2BackendHarness::validateReadinessResponse(null, $body, 'expected-nonce'));
        $this->assertFalse(DisposableMobileV2BackendHarness::validateReadinessResponse([], $body, 'expected-nonce'));
    }

    #[Test]
    public function test_assert_readiness_scope_rejects_missing_file_or_empty_pin(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness readiness scope refused: pairing manifest missing.');
        DisposableMobileV2BackendHarness::assertReadinessScope('/nonexistent/manifest.json', 'pin');
    }

    #[Test]
    public function test_assert_readiness_scope_rejects_mismatched_manifest_sha(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'manifest_');
        file_put_contents($tmp, json_encode(['status' => DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY]));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Harness readiness scope refused: manifest SHA mismatch');
            DisposableMobileV2BackendHarness::assertReadinessScope($tmp, str_repeat('a', 64));
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function test_assert_readiness_scope_fails_closed_on_provision_only_status(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'manifest_');
        file_put_contents($tmp, json_encode(['status' => DisposableMobileV2BackendHarness::SCOPE_PROVISION_ONLY]));
        $sha = hash_file('sha256', $tmp);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("Harness readiness scope refused: pairing manifest status is '".DisposableMobileV2BackendHarness::SCOPE_PROVISION_ONLY."'. Provision-only manifests are strictly forbidden from starting listeners or TLS proxies.");
            DisposableMobileV2BackendHarness::assertReadinessScope($tmp, $sha);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function test_assert_readiness_scope_accepts_readiness_only_status(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'manifest_');
        file_put_contents($tmp, json_encode([
            'status' => DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
            'scope' => 'readiness_probe',
        ]));
        $sha = hash_file('sha256', $tmp);

        try {
            $data = DisposableMobileV2BackendHarness::assertReadinessScope($tmp, $sha);
            $this->assertSame(DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY, $data['status']);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function test_build_client_handoff_structure_and_unavailable_tls_initial_state(): void
    {
        $runtimePins = [
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => str_repeat('b', 40),
            'qualified_app_tree_sha' => DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE,
            'peer_android_approved_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD,
            'peer_android_executable_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD,
            'client_handoff_schema_sha256' => str_repeat('6', 64),
            'source_fixture_sha256' => str_repeat('1', 64),
            'guard_test_sha256' => str_repeat('2', 64),
            'cli_command_sha256' => str_repeat('3', 64),
            'cli_runner_sha256' => str_repeat('4', 64),
            'pairing_manifest_sha256' => str_repeat('5', 64),
        ];

        $fixture = [
            'schema_name' => 'harness_test_db',
            'company_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'company_base_currency' => 'ILS',
            'currency_minor_units' => 2,
            'staff_actor_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'product_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
            'product_unit_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
            'tax_rate_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
            'cash_account_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
            'sample_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
        ];

        $sampleRequest = [
            'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
                    'product_unit_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
                    'conversion_to_base' => '1.000000',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                    'tax_rate_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => false,
                ],
            ],
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
                'amount' => '58.000000',
            ],
        ];

        $rawWireJson = CanonicalJsonSerializer::serialize($sampleRequest);
        $rawWireSample = [
            'raw_wire_payload_json' => $rawWireJson,
            'raw_wire_payload_sha256' => hash('sha256', $rawWireJson),
            'parsed_request' => $sampleRequest,
        ];

        $serverContext = [
            'company_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'actor_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'currency_minor_units' => 2,
        ];

        $handoff = DisposableMobileV2BackendHarness::buildClientHandoff(
            runtimePins: $runtimePins,
            fixture: $fixture,
            credentialsPath: '/tmp/test/runtime_credentials.json',
            rawWireSample: $rawWireSample,
            serverContext: $serverContext,
            canonicalDigest: 'sha256:0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630',
            handoffScope: DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
            lifecyclePhase: 'PROVISIONED_AWAITING_TLS_START',
            createdAt: '2026-11-14T17:00:00+02:00',
            expiresAt: '2026-11-14T18:00:00+02:00',
        );

        $this->assertSame('1.0.0', $handoff['schema_version']);
        $this->assertSame(DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY, $handoff['handoff_scope']);
        $this->assertSame('PROVISIONED_AWAITING_TLS_START', $handoff['lifecycle_phase']);
        $this->assertSame('/tmp/test/runtime_credentials.json', $handoff['credentials_path']);
        $this->assertSame('sha256:0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630', $handoff['canonical_intent_profile_digest']);
        $this->assertSame('UNAVAILABLE_AWAITING_TLS_START', $handoff['startup_tls']['status']);
        $this->assertNull($handoff['startup_tls']['ca_cert_path']);
        $this->assertNull($handoff['startup_tls']['ca_cert_sha256']);
        $this->assertNull($handoff['startup_tls']['readiness_url']);
        $this->assertNull($handoff['startup_tls']['android_readiness_url']);
        $this->assertNull($handoff['startup_tls']['readiness_nonce']);

        // Assert valid handoff passes strict validateClientHandoff
        DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
    }

    #[Test]
    public function test_validate_client_handoff_fails_closed_on_missing_pin(): void
    {
        $handoff = [
            'schema_version' => '1.0.0',
            'handoff_scope' => DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
            'lifecycle_phase' => 'PROVISIONED_AWAITING_TLS_START',
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addHours(1)->toIso8601String(),
            'backend_runtime_pins' => ['qualified_core_head_sha' => str_repeat('a', 40)], // Missing remaining 10 pins
            'endpoints' => [],
            'fixture' => [],
            'credentials_path' => '/tmp/credentials.json',
            'raw_wire_sample' => [],
            'server_synthesized_context' => [],
            'canonical_intent_profile_digest' => 'sha256:'.str_repeat('a', 64),
            'historical_ca5a_reference' => [],
            'startup_tls' => ['status' => 'UNAVAILABLE_AWAITING_TLS_START'],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Malformed client handoff artifact: pin 'backend_execution_head_sha' must be a valid lowercase 40-hex SHA.");
        DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
    }

    #[Test]
    public function test_validate_client_handoff_fails_closed_on_fabricated_tls_during_awaiting(): void
    {
        $sampleRequest = [
            'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
                    'product_unit_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
                    'conversion_to_base' => '1.000000',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                    'tax_rate_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => false,
                ],
            ],
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
                'amount' => '58.000000',
            ],
        ];

        $rawWireJson = CanonicalJsonSerializer::serialize($sampleRequest);
        $rawWireSample = [
            'raw_wire_payload_json' => $rawWireJson,
            'raw_wire_payload_sha256' => hash('sha256', $rawWireJson),
            'parsed_request' => $sampleRequest,
        ];

        $serverContext = [
            'company_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'actor_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'currency_minor_units' => 2,
        ];

        $runtimePins = [
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => str_repeat('b', 40),
            'qualified_app_tree_sha' => DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE,
            'peer_android_approved_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD,
            'peer_android_executable_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD,
            'client_handoff_schema_sha256' => str_repeat('6', 64),
            'source_fixture_sha256' => str_repeat('1', 64),
            'guard_test_sha256' => str_repeat('2', 64),
            'cli_command_sha256' => str_repeat('3', 64),
            'cli_runner_sha256' => str_repeat('4', 64),
            'pairing_manifest_sha256' => str_repeat('5', 64),
        ];

        $handoff = DisposableMobileV2BackendHarness::buildClientHandoff(
            runtimePins: $runtimePins,
            fixture: ['schema_name' => 'test'],
            credentialsPath: '/tmp/test/credentials.json',
            rawWireSample: $rawWireSample,
            serverContext: $serverContext,
            canonicalDigest: 'sha256:0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630',
            handoffScope: DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
            lifecyclePhase: 'PROVISIONED_AWAITING_TLS_START',
            createdAt: '2026-11-14T17:00:00+02:00',
            expiresAt: '2026-11-14T18:00:00+02:00',
        );

        $handoff['startup_tls']['ca_cert_path'] = '/fake/ca.pem'; // Fabricated before start!

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed client handoff artifact: startup_tls fields must be null when awaiting start.');
        DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
    }

    #[Test]
    public function test_validate_client_handoff_fails_closed_on_invalid_pin_regex(): void
    {
        $sampleRequest = [
            'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
                    'product_unit_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
                    'conversion_to_base' => '1.000000',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                    'tax_rate_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => false,
                ],
            ],
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
                'amount' => '58.000000',
            ],
        ];

        $rawWireJson = CanonicalJsonSerializer::serialize($sampleRequest);
        $rawWireSample = [
            'raw_wire_payload_json' => $rawWireJson,
            'raw_wire_payload_sha256' => hash('sha256', $rawWireJson),
            'parsed_request' => $sampleRequest,
        ];

        $serverContext = [
            'company_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'actor_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'currency_minor_units' => 2,
        ];

        $runtimePins = [
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => 'NOT_VALID_HEX_39_CHARS_SHORT',
            'qualified_app_tree_sha' => DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE,
            'peer_android_approved_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD,
            'peer_android_executable_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD,
            'client_handoff_schema_sha256' => str_repeat('6', 64),
            'source_fixture_sha256' => str_repeat('1', 64),
            'guard_test_sha256' => str_repeat('2', 64),
            'cli_command_sha256' => str_repeat('3', 64),
            'cli_runner_sha256' => str_repeat('4', 64),
            'pairing_manifest_sha256' => str_repeat('5', 64),
        ];

        $handoff = DisposableMobileV2BackendHarness::buildClientHandoff(
            runtimePins: $runtimePins,
            fixture: ['schema_name' => 'test'],
            credentialsPath: '/tmp/test/credentials.json',
            rawWireSample: $rawWireSample,
            serverContext: $serverContext,
            canonicalDigest: 'sha256:0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630',
            handoffScope: DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
            lifecyclePhase: 'PROVISIONED_AWAITING_TLS_START',
            createdAt: '2026-11-14T17:00:00+02:00',
            expiresAt: '2026-11-14T18:00:00+02:00',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Malformed client handoff artifact: pin 'backend_execution_head_sha' must be a valid lowercase 40-hex SHA.");
        DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
    }

    #[Test]
    public function test_validate_client_handoff_fails_closed_when_expiry_not_after_created(): void
    {
        $sampleRequest = [
            'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
                    'product_unit_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
                    'conversion_to_base' => '1.000000',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                    'tax_rate_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => false,
                ],
            ],
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
                'amount' => '58.000000',
            ],
        ];

        $rawWireJson = CanonicalJsonSerializer::serialize($sampleRequest);
        $rawWireSample = [
            'raw_wire_payload_json' => $rawWireJson,
            'raw_wire_payload_sha256' => hash('sha256', $rawWireJson),
            'parsed_request' => $sampleRequest,
        ];

        $serverContext = [
            'company_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'actor_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'currency_minor_units' => 2,
        ];

        $runtimePins = [
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => str_repeat('b', 40),
            'qualified_app_tree_sha' => DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE,
            'peer_android_approved_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD,
            'peer_android_executable_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD,
            'client_handoff_schema_sha256' => str_repeat('6', 64),
            'source_fixture_sha256' => str_repeat('1', 64),
            'guard_test_sha256' => str_repeat('2', 64),
            'cli_command_sha256' => str_repeat('3', 64),
            'cli_runner_sha256' => str_repeat('4', 64),
            'pairing_manifest_sha256' => str_repeat('5', 64),
        ];

        $handoff = DisposableMobileV2BackendHarness::buildClientHandoff(
            runtimePins: $runtimePins,
            fixture: ['schema_name' => 'test'],
            credentialsPath: '/tmp/test/credentials.json',
            rawWireSample: $rawWireSample,
            serverContext: $serverContext,
            canonicalDigest: 'sha256:0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630',
            handoffScope: DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
            lifecyclePhase: 'PROVISIONED_AWAITING_TLS_START',
            createdAt: '2026-11-14T15:00:00Z',
            expiresAt: '2026-11-14T14:00:00Z', // Expires BEFORE created!
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed client handoff artifact: expires_at must be strictly after created_at.');
        DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
    }

    #[Test]
    public function test_validate_client_handoff_fails_closed_on_non_canonical_jcs_wire_payload(): void
    {
        $sampleRequest = [
            'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
                    'product_unit_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
                    'conversion_to_base' => '1.000000',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                    'tax_rate_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => false,
                ],
            ],
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
                'amount' => '58.000000',
            ],
        ];

        // Pretty-printed non-canonical JCS serialization
        $nonCanonicalWire = json_encode($sampleRequest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $rawWireSample = [
            'raw_wire_payload_json' => (string) $nonCanonicalWire,
            'raw_wire_payload_sha256' => hash('sha256', (string) $nonCanonicalWire),
            'parsed_request' => $sampleRequest,
        ];

        $serverContext = [
            'company_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'actor_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'currency_minor_units' => 2,
        ];

        $runtimePins = [
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => str_repeat('b', 40),
            'qualified_app_tree_sha' => DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE,
            'peer_android_approved_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD,
            'peer_android_executable_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD,
            'client_handoff_schema_sha256' => str_repeat('6', 64),
            'source_fixture_sha256' => str_repeat('1', 64),
            'guard_test_sha256' => str_repeat('2', 64),
            'cli_command_sha256' => str_repeat('3', 64),
            'cli_runner_sha256' => str_repeat('4', 64),
            'pairing_manifest_sha256' => str_repeat('5', 64),
        ];

        $handoff = DisposableMobileV2BackendHarness::buildClientHandoff(
            runtimePins: $runtimePins,
            fixture: ['schema_name' => 'test'],
            credentialsPath: '/tmp/test/credentials.json',
            rawWireSample: $rawWireSample,
            serverContext: $serverContext,
            canonicalDigest: 'sha256:0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630',
            handoffScope: DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
            lifecyclePhase: 'PROVISIONED_AWAITING_TLS_START',
            createdAt: '2026-11-14T17:00:00+02:00',
            expiresAt: '2026-11-14T18:00:00+02:00',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed client handoff artifact: raw_wire_payload_json must be strictly serialized in authoritative RFC 8785 JCS canonical JSON.');
        DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
    }

    #[Test]
    public function test_validate_client_handoff_fails_closed_on_recomputed_profile_digest_mismatch(): void
    {
        $sampleRequest = [
            'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
                    'product_unit_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
                    'conversion_to_base' => '1.000000',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                    'tax_rate_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => false,
                ],
            ],
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
                'amount' => '58.000000',
            ],
        ];

        $rawWireJson = CanonicalJsonSerializer::serialize($sampleRequest);
        $rawWireSample = [
            'raw_wire_payload_json' => $rawWireJson,
            'raw_wire_payload_sha256' => hash('sha256', $rawWireJson),
            'parsed_request' => $sampleRequest,
        ];

        $serverContext = [
            'company_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'actor_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'currency_minor_units' => 2,
        ];

        $runtimePins = [
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => str_repeat('b', 40),
            'qualified_app_tree_sha' => DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE,
            'peer_android_approved_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD,
            'peer_android_executable_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD,
            'client_handoff_schema_sha256' => str_repeat('6', 64),
            'source_fixture_sha256' => str_repeat('1', 64),
            'guard_test_sha256' => str_repeat('2', 64),
            'cli_command_sha256' => str_repeat('3', 64),
            'cli_runner_sha256' => str_repeat('4', 64),
            'pairing_manifest_sha256' => str_repeat('5', 64),
        ];

        $handoff = DisposableMobileV2BackendHarness::buildClientHandoff(
            runtimePins: $runtimePins,
            fixture: ['schema_name' => 'test'],
            credentialsPath: '/tmp/test/credentials.json',
            rawWireSample: $rawWireSample,
            serverContext: $serverContext,
            canonicalDigest: 'sha256:'.str_repeat('f', 64), // Deliberate mismatch
            handoffScope: DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
            lifecyclePhase: 'PROVISIONED_AWAITING_TLS_START',
            createdAt: '2026-11-14T17:00:00+02:00',
            expiresAt: '2026-11-14T18:00:00+02:00',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed client handoff artifact: canonical_intent_profile_digest mismatch vs recomputed intent profile digest.');
        DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
    }

    #[Test]
    public function test_validate_client_handoff_fails_closed_on_unknown_tls_status(): void
    {
        $sampleRequest = [
            'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
                    'product_unit_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
                    'conversion_to_base' => '1.000000',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                    'tax_rate_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => false,
                ],
            ],
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
                'amount' => '58.000000',
            ],
        ];

        $rawWireJson = CanonicalJsonSerializer::serialize($sampleRequest);
        $rawWireSample = [
            'raw_wire_payload_json' => $rawWireJson,
            'raw_wire_payload_sha256' => hash('sha256', $rawWireJson),
            'parsed_request' => $sampleRequest,
        ];

        $serverContext = [
            'company_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'actor_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'currency_minor_units' => 2,
        ];

        $runtimePins = [
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => str_repeat('b', 40),
            'qualified_app_tree_sha' => DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE,
            'peer_android_approved_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD,
            'peer_android_executable_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD,
            'client_handoff_schema_sha256' => str_repeat('6', 64),
            'source_fixture_sha256' => str_repeat('1', 64),
            'guard_test_sha256' => str_repeat('2', 64),
            'cli_command_sha256' => str_repeat('3', 64),
            'cli_runner_sha256' => str_repeat('4', 64),
            'pairing_manifest_sha256' => str_repeat('5', 64),
        ];

        $handoff = DisposableMobileV2BackendHarness::buildClientHandoff(
            runtimePins: $runtimePins,
            fixture: ['schema_name' => 'test'],
            credentialsPath: '/tmp/test/credentials.json',
            rawWireSample: $rawWireSample,
            serverContext: $serverContext,
            canonicalDigest: 'sha256:0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630',
            handoffScope: DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
            lifecyclePhase: 'PROVISIONED_AWAITING_TLS_START',
            createdAt: '2026-11-14T17:00:00+02:00',
            expiresAt: '2026-11-14T18:00:00+02:00',
        );

        $handoff['startup_tls']['status'] = 'UNKNOWN_TLS_STATUS';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Malformed client handoff artifact: unknown or invalid startup_tls status 'UNKNOWN_TLS_STATUS'.");
        DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
    }

    #[Test]
    public function test_start_fails_closed_on_provision_only_manifest_status(): void
    {
        $harness = new DisposableMobileV2BackendHarness;
        $ref = new \ReflectionClass($harness);
        $provProp = $ref->getProperty('provisioned');
        $provProp->setValue($harness, true);
        $nonceProp = $ref->getProperty('harnessNonce');
        $nonceProp->setValue($harness, 'test-nonce-1234');
        $schemaProp = $ref->getProperty('schema');
        $schemaProp->setValue($harness, (new \ReflectionClass(\Tests\Support\DisposableMariaDbSchema::class))->newInstanceWithoutConstructor());
        $proofPathProp = $ref->getProperty('proofPath');
        $proofPathProp->setValue($harness, '/tmp/proof');
        $proofNonceProp = $ref->getProperty('proofNonce');
        $proofNonceProp->setValue($harness, 'test-nonce');

        putenv('MOBILE_V2_HARNESS_START_LISTENER=1');

        $root = dirname(__DIR__, 4);
        $actualHead = self::getCurrentGitHead();
        $approvedPeer = '1111111111111111111111111111111111111111';

        $provisionOnlyManifest = [
            'contract_name' => 'Mobile V2 Online Cash Sale Integration Dual-Manifest',
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => $actualHead,
            'peer_android_approved_head_sha' => $approvedPeer,
            'peer_android_executable_head_sha' => '2222222222222222222222222222222222222222',
            'status' => DisposableMobileV2BackendHarness::SCOPE_PROVISION_ONLY,
            'source_fixture_sha256' => hash_file('sha256', $root.'/tests/Support/Harness/DisposableMobileV2BackendHarness.php'),
            'cli_command_sha256' => hash_file('sha256', $root.'/tests/Support/Harness/MobileV2HarnessCommand.php'),
            'cli_runner_sha256' => hash_file('sha256', $root.'/tests/Support/Harness/mobile-v2-harness-cli.php'),
        ];

        $tempPath = tempnam(sys_get_temp_dir(), 'test_prov_manifest_');
        file_put_contents($tempPath, json_encode($provisionOnlyManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $manifestSha = hash_file('sha256', $tempPath);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("Harness start refused: pairing manifest status is 'ROOT_AUTHORIZED_LOCAL_PROVISION_ONLY'.");
            $harness->start(
                backendHead: $actualHead,
                peerAndroidHead: $approvedPeer,
                manifestPath: $tempPath,
                manifestShaPin: (string) $manifestSha,
            );
        } finally {
            putenv('MOBILE_V2_HARNESS_START_LISTENER');
            @unlink($tempPath);
        }
    }

    #[Test]
    public function test_assert_lifecycle_scope_table_matrix_negative_combinations(): void
    {
        $root = dirname(__DIR__, 4);
        $actualHead = self::getCurrentGitHead();
        $approvedPeer = '1111111111111111111111111111111111111111';

        $matrix = [
            [
                'mode' => 'start',
                'status' => DisposableMobileV2BackendHarness::SCOPE_PROVISION_ONLY,
                'expectedExceptionMessage' => "Harness start refused: pairing manifest status is 'ROOT_AUTHORIZED_LOCAL_PROVISION_ONLY'. Provision-only manifests are strictly forbidden from starting listeners or TLS proxies.",
            ],
            [
                'mode' => 'start',
                'status' => DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
                'expectedExceptionMessage' => "Harness start refused: pairing manifest status is 'ROOT_AUTHORIZED_READINESS_ONLY'. Non-readiness start requires exact 'ROOT_AUTHORIZED_PAIRED_EXECUTION' scope.",
            ],
            [
                'mode' => 'start',
                'status' => 'MALFORMED_UNAPPROVED_SCOPE',
                'expectedExceptionMessage' => "Harness start refused: unapproved manifest status 'MALFORMED_UNAPPROVED_SCOPE'. Non-readiness start requires exact 'ROOT_AUTHORIZED_PAIRED_EXECUTION' scope.",
            ],
            [
                'mode' => 'readiness',
                'status' => DisposableMobileV2BackendHarness::SCOPE_PROVISION_ONLY,
                'expectedExceptionMessage' => "Harness readiness scope refused: pairing manifest status is 'ROOT_AUTHORIZED_LOCAL_PROVISION_ONLY'. Provision-only manifests are strictly forbidden from starting listeners or TLS proxies.",
            ],
            [
                'mode' => 'readiness',
                'status' => 'MALFORMED_UNAPPROVED_SCOPE',
                'expectedExceptionMessage' => "Harness readiness scope refused: unapproved manifest status 'MALFORMED_UNAPPROVED_SCOPE'. Readiness start requires exact 'ROOT_AUTHORIZED_READINESS_ONLY' or 'ROOT_AUTHORIZED_PAIRED_EXECUTION' scope.",
            ],
            [
                'mode' => 'provision',
                'status' => 'MALFORMED_UNAPPROVED_SCOPE',
                'expectedExceptionMessage' => "Harness provision scope refused: unapproved manifest status 'MALFORMED_UNAPPROVED_SCOPE'.",
            ],
        ];

        foreach ($matrix as $case) {
            $manifest = [
                'contract_name' => 'Mobile V2 Online Cash Sale Integration Dual-Manifest',
                'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
                'backend_execution_head_sha' => $actualHead,
                'peer_android_approved_head_sha' => $approvedPeer,
                'peer_android_executable_head_sha' => '2222222222222222222222222222222222222222',
                'status' => $case['status'],
                'source_fixture_sha256' => hash_file('sha256', $root.'/tests/Support/Harness/DisposableMobileV2BackendHarness.php'),
                'cli_command_sha256' => hash_file('sha256', $root.'/tests/Support/Harness/MobileV2HarnessCommand.php'),
                'cli_runner_sha256' => hash_file('sha256', $root.'/tests/Support/Harness/mobile-v2-harness-cli.php'),
            ];

            $tempPath = tempnam(sys_get_temp_dir(), 'test_matrix_manifest_');
            file_put_contents($tempPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $manifestSha = (string) hash_file('sha256', $tempPath);

            try {
                DisposableMobileV2BackendHarness::assertLifecycleScope($tempPath, $manifestSha, $case['mode']);
                $this->fail("Expected assertLifecycleScope to fail for mode '{$case['mode']}' and status '{$case['status']}'.");
            } catch (RuntimeException $e) {
                $this->assertSame($case['expectedExceptionMessage'], $e->getMessage());
            } finally {
                @unlink($tempPath);
            }
        }
    }

    #[Test]
    public function test_validate_client_handoff_fails_closed_on_invalid_payment_settlement_or_method(): void
    {
        $runtimePins = [
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => str_repeat('b', 40),
            'qualified_app_tree_sha' => DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE,
            'peer_android_approved_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD,
            'peer_android_executable_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD,
            'client_handoff_schema_sha256' => str_repeat('6', 64),
            'source_fixture_sha256' => str_repeat('1', 64),
            'guard_test_sha256' => str_repeat('2', 64),
            'cli_command_sha256' => str_repeat('3', 64),
            'cli_runner_sha256' => str_repeat('4', 64),
            'pairing_manifest_sha256' => str_repeat('5', 64),
        ];

        $fixture = [
            'schema_name' => 'harness_test_db',
            'company_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'company_base_currency' => 'ILS',
            'currency_minor_units' => 2,
            'staff_actor_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'product_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
            'product_unit_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
            'tax_rate_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
            'cash_account_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
            'sample_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
        ];

        $sampleRequest = [
            'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
                    'product_unit_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
                    'conversion_to_base' => '1.000000',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                    'tax_rate_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => false,
                ],
            ],
            'payment' => [
                'settlement_mode' => 'split', // Invalid: must be full
                'payment_method' => 'cash',
                'cash_account_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
                'amount' => '58.000000',
            ],
        ];

        $rawWireJson = CanonicalJsonSerializer::serialize($sampleRequest);
        $rawWireSample = [
            'raw_wire_payload_json' => $rawWireJson,
            'raw_wire_payload_sha256' => hash('sha256', $rawWireJson),
            'parsed_request' => $sampleRequest,
        ];

        $serverContext = [
            'company_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'actor_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'currency_minor_units' => 2,
        ];

        $handoff = DisposableMobileV2BackendHarness::buildClientHandoff(
            runtimePins: $runtimePins,
            fixture: $fixture,
            credentialsPath: '/tmp/test/credentials.json',
            rawWireSample: $rawWireSample,
            serverContext: $serverContext,
            canonicalDigest: 'sha256:0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630',
            handoffScope: DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
            lifecyclePhase: 'PROVISIONED_AWAITING_TLS_START',
            createdAt: '2026-11-14T17:00:00+02:00',
            expiresAt: '2026-11-14T18:00:00+02:00',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed client handoff artifact: payment must restrict to settlement_mode=full, payment_method=cash, non-null cash_account_id ULID, and valid amount.');
        DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
    }

    #[Test]
    public function test_handle_child_readiness_route_enforces_get_only_and_readiness_boundary(): void
    {
        $nonce = 'test-owned-nonce-1234567890abcdef';

        // 1. Valid GET /_harness/readiness returns 200 with nonce
        $getRes = DisposableMobileV2BackendHarness::handleChildReadinessRoute(
            uri: '/_harness/readiness',
            method: 'GET',
            nonce: $nonce,
            readinessOnly: false
        );
        $this->assertNotNull($getRes);
        $this->assertSame(200, $getRes['status_code']);
        $this->assertSame(['Content-Type: application/json'], $getRes['headers']);
        $this->assertSame(['status' => 'ready', 'nonce' => $nonce], $getRes['body']);

        // 2. Non-GET methods return 405 Method Not Allowed with Allow: GET header
        foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $badMethod) {
            $badRes = DisposableMobileV2BackendHarness::handleChildReadinessRoute(
                uri: '/_harness/readiness',
                method: $badMethod,
                nonce: $nonce,
                readinessOnly: false
            );
            $this->assertNotNull($badRes);
            $this->assertSame(405, $badRes['status_code']);
            $this->assertSame(['Allow: GET', 'Content-Type: application/json'], $badRes['headers']);
            $this->assertSame('method_not_allowed', $badRes['body']['error']);
        }

        // 3. Readiness-only mode returns 403 Forbidden for non-readiness paths
        $forbiddenRes = DisposableMobileV2BackendHarness::handleChildReadinessRoute(
            uri: '/api/mobile/v2/companies/01M7CZPF6TP283F3ZRXF5P8GT2/sales/online-cash',
            method: 'POST',
            nonce: $nonce,
            readinessOnly: true
        );
        $this->assertNotNull($forbiddenRes);
        $this->assertSame(403, $forbiddenRes['status_code']);
        $this->assertSame(['Content-Type: application/json'], $forbiddenRes['headers']);
        $this->assertSame('forbidden', $forbiddenRes['body']['error']);

        // 4. Non-readiness-only mode returns null to delegate to application framework
        $delegatedRes = DisposableMobileV2BackendHarness::handleChildReadinessRoute(
            uri: '/api/mobile/v2/companies/01M7CZPF6TP283F3ZRXF5P8GT2/sales/online-cash',
            method: 'POST',
            nonce: $nonce,
            readinessOnly: false
        );
        $this->assertNull($delegatedRes);
    }

    #[Test]
    public function test_validate_client_handoff_executes_pure_canonical_serializer_and_profile_digest(): void
    {
        $runtimePins = [
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => str_repeat('b', 40),
            'qualified_app_tree_sha' => DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE,
            'peer_android_approved_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD,
            'peer_android_executable_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD,
            'client_handoff_schema_sha256' => str_repeat('6', 64),
            'source_fixture_sha256' => str_repeat('1', 64),
            'guard_test_sha256' => str_repeat('2', 64),
            'cli_command_sha256' => str_repeat('3', 64),
            'cli_runner_sha256' => str_repeat('4', 64),
            'pairing_manifest_sha256' => str_repeat('5', 64),
        ];

        $fixture = [
            'schema_name' => 'harness_test_db',
            'company_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'company_base_currency' => 'ILS',
            'currency_minor_units' => 2,
            'staff_actor_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'product_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
            'product_unit_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
            'tax_rate_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
            'cash_account_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
            'sample_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
        ];

        $sampleRequest = [
            'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
                    'product_unit_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
                    'conversion_to_base' => '1.000000',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                    'tax_rate_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => false,
                ],
            ],
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
                'amount' => '58.000000',
            ],
        ];

        $serverContext = [
            'company_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'actor_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'currency_minor_units' => 2,
        ];

        // Authoritatively serialize wire JSON using CanonicalJsonSerializer
        $rawWireJson = CanonicalJsonSerializer::serialize($sampleRequest);
        $rawWireSample = [
            'raw_wire_payload_json' => $rawWireJson,
            'raw_wire_payload_sha256' => hash('sha256', $rawWireJson),
            'parsed_request' => $sampleRequest,
        ];

        // Authoritatively compute canonical intent profile digest
        $profile = \App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile::construct($serverContext, $sampleRequest);
        $canonicalDigest = \App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile::computeDigest($profile);

        $handoff = DisposableMobileV2BackendHarness::buildClientHandoff(
            runtimePins: $runtimePins,
            fixture: $fixture,
            credentialsPath: '/tmp/test/runtime_credentials.json',
            rawWireSample: $rawWireSample,
            serverContext: $serverContext,
            canonicalDigest: $canonicalDigest,
            handoffScope: DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY,
            lifecyclePhase: 'PROVISIONED_AWAITING_TLS_START',
            createdAt: '2026-11-14T17:00:00+02:00',
            expiresAt: '2026-11-14T18:00:00+02:00',
        );

        // This call must complete with ZERO exceptions, verifying CanonicalJsonSerializer and OnlineSaleIntentProfile runtime resolution
        DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
        $this->assertSame('1.0.0', $handoff['schema_version']);
    }

    #[Test]
    public function test_validate_client_handoff_admits_g1_tree_and_rejects_unadmitted_tree(): void
    {
        $runtimePins = [
            'qualified_core_head_sha' => DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            'backend_execution_head_sha' => str_repeat('b', 40),
            'qualified_app_tree_sha' => DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_G1,
            'peer_android_approved_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_HEAD,
            'peer_android_executable_head_sha' => DisposableMobileV2BackendHarness::APPROVED_ANDROID_EXECUTABLE_HEAD,
            'client_handoff_schema_sha256' => str_repeat('6', 64),
            'source_fixture_sha256' => str_repeat('1', 64),
            'guard_test_sha256' => str_repeat('2', 64),
            'cli_command_sha256' => str_repeat('3', 64),
            'cli_runner_sha256' => str_repeat('4', 64),
            'pairing_manifest_sha256' => str_repeat('5', 64),
        ];

        $fixture = [
            'schema_name' => 'harness_test_db',
            'company_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'company_base_currency' => 'ILS',
            'currency_minor_units' => 2,
            'staff_actor_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'product_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
            'product_unit_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
            'tax_rate_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
            'cash_account_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
            'sample_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
        ];

        $sampleRequest = [
            'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'device_id' => 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'customer_id' => '01M7CZPF6TP283F3ZRXF5P8GT3',
            'warehouse_id' => '01M7CZPF6TP283F3ZRXF5P8GT4',
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => '01M7CZPF6TP283F3ZRXF5P8GT5',
                    'product_unit_id' => '01M7CZPF6TP283F3ZRXF5P8GT6',
                    'conversion_to_base' => '1.000000',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                    'tax_rate_id' => '01M7CZPF6TP283F3ZRXF5P8GT7',
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => false,
                ],
            ],
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01M7CZPF6TP283F3ZRXF5P8GT8',
                'amount' => '58.000000',
            ],
        ];

        $serverContext = [
            'company_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'actor_id' => '01M7CZPF6TP283F3ZRXF5P8GT1',
            'currency_minor_units' => 2,
        ];

        // Authoritatively serialize wire JSON using CanonicalJsonSerializer
        $rawWireJson = CanonicalJsonSerializer::serialize($sampleRequest);
        $rawWireSample = [
            'raw_wire_payload_json' => $rawWireJson,
            'raw_wire_payload_sha256' => hash('sha256', $rawWireJson),
            'parsed_request' => $sampleRequest,
        ];

        // Authoritatively compute canonical intent profile digest
        $profile = OnlineSaleIntentProfile::construct($serverContext, $sampleRequest);
        $canonicalDigest = OnlineSaleIntentProfile::computeDigest($profile);

        $handoff = DisposableMobileV2BackendHarness::buildClientHandoff(
            runtimePins: $runtimePins,
            fixture: $fixture,
            credentialsPath: '/tmp/test/runtime_credentials.json',
            rawWireSample: $rawWireSample,
            serverContext: $serverContext,
            canonicalDigest: $canonicalDigest,
            handoffScope: DisposableMobileV2BackendHarness::SCOPE_PROVISION_ONLY,
            lifecyclePhase: 'PROVISIONED_AWAITING_TLS_START',
            createdAt: '2026-11-14T17:00:00+02:00',
            expiresAt: '2026-11-14T18:00:00+02:00',
        );

        // Assert G1 tree is admitted
        DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
        $this->assertSame(DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_G1, $handoff['backend_runtime_pins']['qualified_app_tree_sha']);

        // Assert unadmitted tree fails closed
        $badHandoff = $handoff;
        $badHandoff['backend_runtime_pins']['qualified_app_tree_sha'] = str_repeat('9', 40);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed client handoff artifact: qualified_app_tree_sha mismatch vs admitted constants.');

        DisposableMobileV2BackendHarness::validateClientHandoff($badHandoff);
    }

    #[Test]
    public function test_child_bootstrap_template_execution_in_fresh_subprocess(): void
    {
        $root = dirname(__DIR__, 4);
        $tempDir = sys_get_temp_dir().'/harness_subproc_test_'.bin2hex(random_bytes(6));
        mkdir($tempDir, 0700, true);

        $nonce = 'test-subproc-nonce-'.bin2hex(random_bytes(16));
        $configPath = $tempDir.'/child_bootstrap_config.json';
        $cacheDir = $tempDir.'/cache';
        mkdir($cacheDir, 0700, true);
        $envPath = $tempDir.'/.env.harness';

        $configData = [
            'expected_backend_head' => str_repeat('a', 40),
            'schema_name' => 'subproc_test_db',
            'proof_path' => $tempDir.'/proof.txt',
            'proof_nonce' => 'proof-nonce-123',
            'db_host' => '127.0.0.1',
            'db_port' => 3342,
            'db_username' => 'test_user',
            'db_password' => 'test_pass',
            'harness_nonce' => $nonce,
            'cache_dir' => $cacheDir,
            'env_file' => $envPath,
            'readiness_only' => false,
        ];
        file_put_contents($configPath, json_encode($configData));
        chmod($configPath, 0600);

        $scriptContent = DisposableMobileV2BackendHarness::generateChildBootstrapScript(
            configPath: $configPath,
            bootstrapDir: $tempDir,
            nonce: $nonce,
            root: $root,
            testEnvPath: $envPath,
        );

        $scriptPath = $tempDir.'/http-bootstrap.php';
        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0600);

        try {
            // 1. GET /_harness/readiness -> HTTP 200 with JSON {"status":"ready","nonce":"..."}
            $procGet = new Process([PHP_BINARY, $scriptPath], $root, [
                'REQUEST_URI' => '/_harness/readiness',
                'REQUEST_METHOD' => 'GET',
            ]);
            $procGet->run();
            $this->assertSame(0, $procGet->getExitCode(), 'GET readiness failed: '.$procGet->getErrorOutput());
            $getBody = json_decode($procGet->getOutput(), true);
            $this->assertIsArray($getBody);
            $this->assertSame('ready', $getBody['status'] ?? null);
            $this->assertSame($nonce, $getBody['nonce'] ?? null);

            // 2. POST /_harness/readiness -> HTTP 405 Method Not Allowed
            $procPost = new Process([PHP_BINARY, $scriptPath], $root, [
                'REQUEST_URI' => '/_harness/readiness',
                'REQUEST_METHOD' => 'POST',
            ]);
            $procPost->run();
            $this->assertSame(0, $procPost->getExitCode(), 'POST readiness failed: '.$procPost->getErrorOutput());
            $postBody = json_decode($procPost->getOutput(), true);
            $this->assertIsArray($postBody);
            $this->assertSame('method_not_allowed', $postBody['error'] ?? null);
            $this->assertSame('Readiness probe strictly requires GET', $postBody['message'] ?? null);

            // 3. Non-readiness route with readiness_only => true -> HTTP 403 Forbidden
            $configData['readiness_only'] = true;
            file_put_contents($configPath, json_encode($configData));
            $procForbidden = new Process([PHP_BINARY, $scriptPath], $root, [
                'REQUEST_URI' => '/api/v2/mobile/receipt/sync',
                'REQUEST_METHOD' => 'GET',
            ]);
            $procForbidden->run();
            $this->assertSame(0, $procForbidden->getExitCode(), 'Readiness-only guard failed: '.$procForbidden->getErrorOutput());
            $forbidBody = json_decode($procForbidden->getOutput(), true);
            $this->assertIsArray($forbidBody);
            $this->assertSame('forbidden', $forbidBody['error'] ?? null);
            $this->assertStringContainsString('READINESS-ONLY', $forbidBody['message'] ?? '');
        } finally {
            @unlink($scriptPath);
            @unlink($configPath);
            @rmdir($cacheDir);
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function test_assert_readiness_hold_bounds_accepts_valid_integers_and_strings(): void
    {
        $validCases = [0, 1, 5, 30, 60, 120];
        foreach ($validCases as $val) {
            $this->assertSame($val, DisposableMobileV2BackendHarness::assertReadinessHoldBounds($val));
            $this->assertSame($val, DisposableMobileV2BackendHarness::assertReadinessHoldBounds((string) $val));
        }
    }

    #[Test]
    public function test_assert_readiness_hold_bounds_rejects_out_of_bounds_values(): void
    {
        $invalidCases = [-1, -50, 121, 300, '-1', '121', '999'];
        foreach ($invalidCases as $val) {
            try {
                DisposableMobileV2BackendHarness::assertReadinessHoldBounds($val);
                $this->fail("Expected InvalidArgumentException for value: {$val}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Readiness hold seconds must be between 0 and 120', $e->getMessage());
            }
        }
    }

    #[Test]
    public function test_assert_readiness_hold_bounds_rejects_non_integers_and_malformed_values(): void
    {
        $malformedCases = ['abc', '1.5', '', ' 10 ', '12a', null, 1.5, [], true, false];
        foreach ($malformedCases as $val) {
            try {
                DisposableMobileV2BackendHarness::assertReadinessHoldBounds($val);
                $this->fail('Expected InvalidArgumentException for malformed value: '.var_export($val, true));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(
                    str_contains($e->getMessage(), 'Readiness hold seconds must be an integer string')
                    || str_contains($e->getMessage(), 'Readiness hold seconds must be an integer or integer string')
                );
            }
        }
    }

    #[Test]
    public function test_cli_runner_rejects_readiness_hold_without_readiness_only_mode(): void
    {
        $root = dirname(__DIR__, 4);
        $cliScript = $root.'/tests/Support/Harness/mobile-v2-harness-cli.php';

        $proc = new Process([PHP_BINARY, $cliScript, '--prepare', '--readiness-hold-seconds=10'], $root);
        $proc->run();

        $this->assertSame(1, $proc->getExitCode());
        $this->assertStringContainsString(
            'Error: --readiness-hold-seconds is strictly restricted to --readiness-only mode.',
            $proc->getErrorOutput()
        );
    }

    #[Test]
    public function test_cli_runner_rejects_out_of_bounds_readiness_hold_seconds(): void
    {
        $root = dirname(__DIR__, 4);
        $cliScript = $root.'/tests/Support/Harness/mobile-v2-harness-cli.php';

        $proc = new Process([PHP_BINARY, $cliScript, '--readiness-only', '--readiness-hold-seconds=999'], $root);
        $proc->run();

        $this->assertSame(1, $proc->getExitCode());
        $this->assertStringContainsString(
            'Readiness hold seconds must be between 0 and 120',
            $proc->getErrorOutput()
        );
    }

    #[Test]
    public function test_harness_command_signature_contains_readiness_hold_seconds_option(): void
    {
        $command = new MobileV2HarnessCommand;
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('readiness-hold-seconds'));
        $option = $definition->getOption('readiness-hold-seconds');
        $this->assertSame('0', $option->getDefault());
        $this->assertStringContainsString('0..120', $option->getDescription());
    }

    #[Test]
    public function test_generate_test_tls_certificates_creates_valid_x509_v3_extensions(): void
    {
        $tempDir = sys_get_temp_dir().'/pure_cert_ext_test_'.bin2hex(random_bytes(6));
        mkdir($tempDir, 0700, true);

        try {
            $certs = DisposableMobileV2BackendHarness::generateTestTlsCertificates($tempDir);

            $this->assertFileExists($certs['ca_cert_path']);
            $this->assertFileExists($certs['ca_key_path']);
            $this->assertFileExists($certs['server_cert_path']);
            $this->assertFileExists($certs['server_key_path']);

            $this->assertSame('0700', substr(sprintf('%o', fileperms($tempDir)), -4));
            $this->assertSame('0600', substr(sprintf('%o', fileperms($certs['ca_key_path'])), -4));
            $this->assertSame('0600', substr(sprintf('%o', fileperms($certs['ca_cert_path'])), -4));
            $this->assertSame('0600', substr(sprintf('%o', fileperms($certs['server_key_path'])), -4));
            $this->assertSame('0600', substr(sprintf('%o', fileperms($certs['server_cert_path'])), -4));

            // Inspect CA cert extensions
            $procCaText = new Process(['openssl', 'x509', '-in', $certs['ca_cert_path'], '-text', '-noout']);
            $procCaText->mustRun();
            $caOutput = $procCaText->getOutput();
            $this->assertStringContainsString('X509v3 Basic Constraints: critical', $caOutput);
            $this->assertStringContainsString('CA:TRUE', $caOutput);
            $this->assertStringContainsString('X509v3 Key Usage: critical', $caOutput);
            $this->assertStringContainsString('Certificate Sign, CRL Sign', $caOutput);
            $this->assertStringContainsString('X509v3 Subject Key Identifier:', $caOutput);
            $this->assertStringContainsString('X509v3 Authority Key Identifier:', $caOutput);

            // Inspect Server cert extensions
            $procServerText = new Process(['openssl', 'x509', '-in', $certs['server_cert_path'], '-text', '-noout']);
            $procServerText->mustRun();
            $serverOutput = $procServerText->getOutput();
            $this->assertStringContainsString('X509v3 Basic Constraints: critical', $serverOutput);
            $this->assertStringContainsString('CA:FALSE', $serverOutput);
            $this->assertStringContainsString('X509v3 Key Usage: critical', $serverOutput);
            $this->assertStringContainsString('Digital Signature, Key Encipherment', $serverOutput);
            $this->assertStringContainsString('X509v3 Extended Key Usage:', $serverOutput);
            $this->assertStringContainsString('TLS Web Server Authentication', $serverOutput);
            $this->assertStringContainsString('X509v3 Subject Alternative Name:', $serverOutput);
            $this->assertStringContainsString('DNS:localhost', $serverOutput);
            $this->assertStringContainsString('IP Address:127.0.0.1', $serverOutput);
            $this->assertStringContainsString('IP Address:10.0.2.2', $serverOutput);
        } finally {
            $files = glob($tempDir.'/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function test_generate_test_tls_certificates_passes_openssl_verify_strict(): void
    {
        $tempDir = sys_get_temp_dir().'/pure_cert_verify_test_'.bin2hex(random_bytes(6));
        mkdir($tempDir, 0700, true);

        try {
            $certs = DisposableMobileV2BackendHarness::generateTestTlsCertificates($tempDir);

            $procVerify = new Process([
                'openssl', 'verify', '-x509_strict',
                '-CAfile', $certs['ca_cert_path'],
                $certs['server_cert_path'],
            ]);
            $procVerify->run();

            $this->assertSame(0, $procVerify->getExitCode(), 'openssl verify -x509_strict failed: '.$procVerify->getErrorOutput());
            $this->assertStringContainsString('OK', $procVerify->getOutput());
        } finally {
            $files = glob($tempDir.'/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function test_generate_test_tls_certificates_passes_python3_ssl_strict_trust(): void
    {
        $tempDir = sys_get_temp_dir().'/pure_python_ssl_test_'.bin2hex(random_bytes(6));
        mkdir($tempDir, 0700, true);

        try {
            $certs = DisposableMobileV2BackendHarness::generateTestTlsCertificates($tempDir);

            $pyScript = implode("\n", [
                'import sys, ssl, socket, threading',
                'ca_cert = sys.argv[1]',
                'server_cert = sys.argv[2]',
                'server_key = sys.argv[3]',
                '',
                'srv_ctx = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)',
                'srv_ctx.load_cert_chain(certfile=server_cert, keyfile=server_key)',
                '',
                'client_ctx = ssl.create_default_context(cafile=ca_cert)',
                'client_ctx.check_hostname = True',
                'client_ctx.verify_mode = ssl.CERT_REQUIRED',
                '',
                "for host in ['127.0.0.1', 'localhost', '10.0.2.2']:",
                '    s1, s2 = socket.socketpair()',
                '    def srv():',
                '        conn = srv_ctx.wrap_socket(s1, server_side=True)',
                '        conn.sendall(b"PONG")',
                '        conn.close()',
                '    t = threading.Thread(target=srv)',
                '    t.start()',
                '    c = client_ctx.wrap_socket(s2, server_hostname=host)',
                '    msg = c.recv(1024)',
                '    assert msg == b"PONG"',
                '    c.close()',
                '    t.join()',
                '',
                '# Test negative hostname mismatch rejects handshake with CertificateError',
                's1, s2 = socket.socketpair()',
                'def srv_bad():',
                '    try:',
                '        conn = srv_ctx.wrap_socket(s1, server_side=True)',
                '        conn.close()',
                '    except Exception:',
                '        pass',
                't_bad = threading.Thread(target=srv_bad)',
                't_bad.start()',
                'try:',
                '    c = client_ctx.wrap_socket(s2, server_hostname="unauthorized.domain.invalid")',
                '    raise AssertionError("Expected SSL CertificateError for mismatched hostname")',
                'except (ssl.CertificateError, ssl.SSLError):',
                '    pass',
                'finally:',
                '    t_bad.join()',
                '',
                'print("PYTHON_STRICT_SSL_OK")',
            ]);

            $procPy = new Process(['python3', '-c', $pyScript, $certs['ca_cert_path'], $certs['server_cert_path'], $certs['server_key_path']]);
            $procPy->run();

            $this->assertSame(0, $procPy->getExitCode(), 'Python 3 strict SSL verification failed: '.$procPy->getErrorOutput().' '.$procPy->getOutput());
            $this->assertStringContainsString('PYTHON_STRICT_SSL_OK', $procPy->getOutput());
        } finally {
            $files = glob($tempDir.'/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function test_assert_pcntl_available_for_hold_allows_zero_and_rejects_missing_functions(): void
    {
        // 1. Hold seconds 0 requires no pcntl
        DisposableMobileV2BackendHarness::assertPcntlAvailableForHold(0, fn (string $fn) => false);
        $this->assertTrue(true);

        // 2. Missing pcntl_signal throws
        try {
            DisposableMobileV2BackendHarness::assertPcntlAvailableForHold(10, fn (string $fn) => $fn !== 'pcntl_signal');
            $this->fail('Expected RuntimeException when pcntl_signal is missing');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("requires pcntl extension function 'pcntl_signal'", $e->getMessage());
        }

        // 3. Missing pcntl_signal_dispatch throws
        try {
            DisposableMobileV2BackendHarness::assertPcntlAvailableForHold(10, fn (string $fn) => $fn !== 'pcntl_signal_dispatch');
            $this->fail('Expected RuntimeException when pcntl_signal_dispatch is missing');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("requires pcntl extension function 'pcntl_signal_dispatch'", $e->getMessage());
        }

        // 4. Default checker passes on current runtime
        DisposableMobileV2BackendHarness::assertPcntlAvailableForHold(10);
        $this->assertTrue(true);
    }

    #[Test]
    public function test_cli_runner_pre_socket_refuses_when_pcntl_unavailable_subprocess(): void
    {
        $root = dirname(__DIR__, 4);
        $cliScript = $root.'/tests/Support/Harness/mobile-v2-harness-cli.php';

        // Disabling pcntl_signal at CLI invocation via -d disable_functions
        $proc = new Process([PHP_BINARY, '-d', 'disable_functions=pcntl_signal', $cliScript, '--readiness-only', '--readiness-hold-seconds=10'], $root);
        $proc->run();

        $this->assertSame(1, $proc->getExitCode(), 'CLI runner should exit 1 when pcntl is disabled: '.$proc->getErrorOutput().' '.$proc->getOutput());
        $this->assertStringContainsString(
            "Harness refused: --readiness-hold-seconds requires pcntl extension function 'pcntl_signal'",
            $proc->getErrorOutput()
        );
    }

    #[Test]
    public function test_register_early_hold_signal_handlers_seam_and_live(): void
    {
        // Seam failure returns false
        $this->assertFalse(DisposableMobileV2BackendHarness::registerEarlyHoldSignalHandlers(fn () => null, fn () => false));

        // Seam success returns true
        $this->assertTrue(DisposableMobileV2BackendHarness::registerEarlyHoldSignalHandlers(fn () => null, fn () => true));

        // Live registration returns true on standard runtime
        $this->assertTrue(DisposableMobileV2BackendHarness::registerEarlyHoldSignalHandlers(fn () => null));
    }

    #[Test]
    public function test_readiness_hold_signal_aborted_marks_failure_and_cleans_up_in_subprocess(): void
    {
        $root = dirname(__DIR__, 4);
        $tempDir = sys_get_temp_dir().'/pure_signal_abort_'.bin2hex(random_bytes(6));
        mkdir($tempDir, 0700, true);
        $markerPath = $tempDir.'/cleaned_up.txt';

        $subScript = implode("\n", [
            '<?php',
            'declare(strict_types=1);',
            'require "'.addslashes($root.'/vendor/autoload.php').'";',
            'use Tests\Support\Harness\DisposableMobileV2BackendHarness;',
            '',
            'function runHarnessMock(): int {',
            '    $aborted = false;',
            '    $signalReceived = null;',
            '    $handler = function (int $signo) use (&$aborted, &$signalReceived) {',
            '        $aborted = true;',
            '        $signalReceived = $signo;',
            '    };',
            '    $reg = DisposableMobileV2BackendHarness::registerEarlyHoldSignalHandlers($handler);',
            '    if (! $reg) {',
            '        echo "REG_FAILED\n";',
            '        return 2;',
            '    }',
            '',
            '    try {',
            '        // Trigger SIGTERM to self to simulate external signal arrival during hold',
            '        posix_kill(getmypid(), SIGTERM);',
            '        pcntl_signal_dispatch();',
            '        if ($aborted) {',
            '            $sigName = $signalReceived === SIGINT ? "SIGINT" : ($signalReceived === SIGTERM ? "SIGTERM" : (string) $signalReceived);',
            '            echo "Readiness hold ABORTED by interrupt signal {$sigName}.\n";',
            '            return 1;',
            '        }',
            '        return 0;',
            '    } finally {',
            '        file_put_contents("'.$markerPath.'", "CLEANUP_CALLED");',
            '    }',
            '}',
            '',
            'exit(runHarnessMock());',
        ]);

        $scriptPath = $tempDir.'/test_abort_flow.php';
        file_put_contents($scriptPath, $subScript);
        chmod($scriptPath, 0600);

        try {
            $proc = new Process([PHP_BINARY, $scriptPath], $root);
            $proc->run();

            $this->assertSame(1, $proc->getExitCode(), 'Subprocess should exit with code 1 upon signal abort: '.$proc->getErrorOutput().' '.$proc->getOutput());
            $this->assertStringContainsString('Readiness hold ABORTED by interrupt signal SIGTERM.', $proc->getOutput());
            $this->assertFileExists($markerPath, 'Finally cleanup block must execute and create marker file');
            $this->assertSame('CLEANUP_CALLED', file_get_contents($markerPath));
        } finally {
            @unlink($markerPath);
            @unlink($scriptPath);
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function test_assert_paired_hold_bounds_validates_range_and_rejects_malformed_and_out_of_bounds(): void
    {
        // 1. Valid bounds (1..120) accepted
        $this->assertSame(1, DisposableMobileV2BackendHarness::assertPairedHoldBounds(1));
        $this->assertSame(60, DisposableMobileV2BackendHarness::assertPairedHoldBounds(60));
        $this->assertSame(120, DisposableMobileV2BackendHarness::assertPairedHoldBounds(120));
        $this->assertSame(1, DisposableMobileV2BackendHarness::assertPairedHoldBounds('1'));
        $this->assertSame(60, DisposableMobileV2BackendHarness::assertPairedHoldBounds('60'));
        $this->assertSame(120, DisposableMobileV2BackendHarness::assertPairedHoldBounds('120'));

        // 2. 0 rejected (automated paired run strictly requires positive 1..120 duration)
        try {
            DisposableMobileV2BackendHarness::assertPairedHoldBounds(0);
            $this->fail('Expected InvalidArgumentException for paired hold 0');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Paired hold seconds must be between 1 and 120, got 0', $e->getMessage());
        }

        try {
            DisposableMobileV2BackendHarness::assertPairedHoldBounds('0');
            $this->fail('Expected InvalidArgumentException for paired hold string "0"');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Paired hold seconds must be between 1 and 120, got 0', $e->getMessage());
        }

        // 3. Negative rejected
        try {
            DisposableMobileV2BackendHarness::assertPairedHoldBounds(-1);
            $this->fail('Expected InvalidArgumentException for paired hold -1');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Paired hold seconds must be between 1 and 120, got -1', $e->getMessage());
        }

        try {
            DisposableMobileV2BackendHarness::assertPairedHoldBounds('-5');
            $this->fail('Expected InvalidArgumentException for paired hold "-5"');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Paired hold seconds must be between 1 and 120, got -5', $e->getMessage());
        }

        // 4. Over 120 max rejected
        try {
            DisposableMobileV2BackendHarness::assertPairedHoldBounds(121);
            $this->fail('Expected InvalidArgumentException for paired hold 121');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Paired hold seconds must be between 1 and 120, got 121', $e->getMessage());
        }

        try {
            DisposableMobileV2BackendHarness::assertPairedHoldBounds('999');
            $this->fail('Expected InvalidArgumentException for paired hold "999"');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Paired hold seconds must be between 1 and 120, got 999', $e->getMessage());
        }

        // 5. Malformed non-integer string rejected
        try {
            DisposableMobileV2BackendHarness::assertPairedHoldBounds('abc');
            $this->fail('Expected InvalidArgumentException for paired hold "abc"');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Paired hold seconds must be an integer string, got 'abc'", $e->getMessage());
        }

        try {
            DisposableMobileV2BackendHarness::assertPairedHoldBounds('12.5');
            $this->fail('Expected InvalidArgumentException for paired hold "12.5"');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Paired hold seconds must be an integer string, got '12.5'", $e->getMessage());
        }

        try {
            DisposableMobileV2BackendHarness::assertPairedHoldBounds('');
            $this->fail('Expected InvalidArgumentException for paired hold empty string');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Paired hold seconds must be an integer string, got ''", $e->getMessage());
        }

        // 6. Non-scalar rejected
        try {
            DisposableMobileV2BackendHarness::assertPairedHoldBounds(['10']);
            $this->fail('Expected InvalidArgumentException for paired hold array');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Paired hold seconds must be an integer or integer string', $e->getMessage());
        }
    }

    #[Test]
    public function test_assert_pcntl_available_for_paired_hold_allows_zero_and_rejects_missing_functions(): void
    {
        // 1. Hold seconds 0 requires no pcntl
        DisposableMobileV2BackendHarness::assertPcntlAvailableForPairedHold(0, fn (string $fn) => false);
        $this->assertTrue(true);

        // 2. Missing pcntl_signal throws
        try {
            DisposableMobileV2BackendHarness::assertPcntlAvailableForPairedHold(10, fn (string $fn) => $fn !== 'pcntl_signal');
            $this->fail('Expected RuntimeException when pcntl_signal is missing');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Harness refused: --paired-hold-seconds requires pcntl extension function 'pcntl_signal'", $e->getMessage());
        }

        // 3. Missing pcntl_signal_dispatch throws
        try {
            DisposableMobileV2BackendHarness::assertPcntlAvailableForPairedHold(10, fn (string $fn) => $fn !== 'pcntl_signal_dispatch');
            $this->fail('Expected RuntimeException when pcntl_signal_dispatch is missing');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Harness refused: --paired-hold-seconds requires pcntl extension function 'pcntl_signal_dispatch'", $e->getMessage());
        }

        // 4. Default checker passes on current runtime
        DisposableMobileV2BackendHarness::assertPcntlAvailableForPairedHold(10);
        $this->assertTrue(true);
    }

    #[Test]
    public function test_cli_runner_pre_framework_paired_hold_mode_combinations_and_bounds(): void
    {
        $root = dirname(__DIR__, 4);
        $cliScript = $root.'/tests/Support/Harness/mobile-v2-harness-cli.php';

        // 1. Restricted to --run mode: rejected with --prepare
        $procPrep = new Process([PHP_BINARY, $cliScript, '--prepare', '--paired-hold-seconds=10'], $root);
        $procPrep->run();
        $this->assertSame(1, $procPrep->getExitCode());
        $this->assertStringContainsString('Error: --paired-hold-seconds is strictly restricted to --run mode.', $procPrep->getErrorOutput());

        // 2. Restricted to --run mode: rejected with --readiness-only
        $procReadiness = new Process([PHP_BINARY, $cliScript, '--readiness-only', '--paired-hold-seconds=10'], $root);
        $procReadiness->run();
        $this->assertSame(1, $procReadiness->getExitCode());
        $this->assertStringContainsString('Error: --paired-hold-seconds is strictly restricted to --run mode.', $procReadiness->getErrorOutput());

        // 3. Restricted to --run mode: rejected with --provision
        $procProv = new Process([PHP_BINARY, $cliScript, '--provision', '--paired-hold-seconds=10'], $root);
        $procProv->run();
        $this->assertSame(1, $procProv->getExitCode());
        $this->assertStringContainsString('Error: --paired-hold-seconds is strictly restricted to --run mode.', $procProv->getErrorOutput());

        // 4. Out of bounds 0 rejected with --run
        $procZero = new Process([PHP_BINARY, $cliScript, '--run', '--paired-hold-seconds=0'], $root);
        $procZero->run();
        $this->assertSame(1, $procZero->getExitCode());
        $this->assertStringContainsString('Error: Paired hold seconds must be between 1 and 120, got 0.', $procZero->getErrorOutput());

        // 5. Out of bounds negative rejected with --run
        $procNeg = new Process([PHP_BINARY, $cliScript, '--run', '--paired-hold-seconds=-5'], $root);
        $procNeg->run();
        $this->assertSame(1, $procNeg->getExitCode());
        $this->assertStringContainsString('Error: Paired hold seconds must be between 1 and 120, got -5.', $procNeg->getErrorOutput());

        // 6. Out of bounds > 120 rejected with --run
        $procOver = new Process([PHP_BINARY, $cliScript, '--run', '--paired-hold-seconds=121'], $root);
        $procOver->run();
        $this->assertSame(1, $procOver->getExitCode());
        $this->assertStringContainsString('Error: Paired hold seconds must be between 1 and 120, got 121.', $procOver->getErrorOutput());

        // 7. Malformed non-numeric string rejected with --run
        $procMalformed = new Process([PHP_BINARY, $cliScript, '--run', '--paired-hold-seconds=abc'], $root);
        $procMalformed->run();
        $this->assertSame(1, $procMalformed->getExitCode());
        $this->assertStringContainsString("Error: Paired hold seconds must be an integer string, got 'abc'.", $procMalformed->getErrorOutput());
    }

    #[Test]
    public function test_cli_runner_pre_socket_refuses_when_pcntl_unavailable_for_paired_hold_subprocess(): void
    {
        $root = dirname(__DIR__, 4);
        $cliScript = $root.'/tests/Support/Harness/mobile-v2-harness-cli.php';

        // Disabling pcntl_signal at CLI invocation via -d disable_functions with --run --paired-hold-seconds=10
        $proc = new Process([PHP_BINARY, '-d', 'disable_functions=pcntl_signal', $cliScript, '--run', '--paired-hold-seconds=10'], $root);
        $proc->run();

        $this->assertSame(1, $proc->getExitCode(), 'CLI runner should exit 1 when pcntl is disabled: '.$proc->getErrorOutput().' '.$proc->getOutput());
        $this->assertStringContainsString(
            "Harness refused: --paired-hold-seconds requires pcntl extension function 'pcntl_signal'",
            $proc->getErrorOutput()
        );
    }

    #[Test]
    public function test_cli_runner_pre_socket_refuses_when_pcntl_unavailable_for_plain_run_subprocess(): void
    {
        $root = dirname(__DIR__, 4);
        $cliScript = $root.'/tests/Support/Harness/mobile-v2-harness-cli.php';

        // Disabling pcntl_signal at CLI invocation via -d disable_functions with --run (without paired-hold-seconds)
        $proc = new Process([PHP_BINARY, '-d', 'disable_functions=pcntl_signal', $cliScript, '--run'], $root);
        $proc->run();

        $this->assertSame(1, $proc->getExitCode(), 'CLI runner should exit 1 when pcntl is disabled: '.$proc->getErrorOutput().' '.$proc->getOutput());
        $this->assertStringContainsString(
            'Harness run refused: pcntl extension is required for safe supervised process signal management.',
            $proc->getErrorOutput()
        );
    }

    #[Test]
    public function test_actual_used_signal_guard_path_aborts_non_zero_with_truthful_log_and_invokes_real_cleanup(): void
    {
        $root = dirname(__DIR__, 4);
        $tempDir = sys_get_temp_dir().'/pure_paired_guard_'.bin2hex(random_bytes(6));
        mkdir($tempDir, 0700, true);

        // Subprocess script exercises the actual used signal guard path from handleRun:
        // Registers early handlers, dispatches SIGTERM to self, catches before provision/hold,
        // logs truthful abort message, executes real DisposableMobileV2BackendHarness::cleanup() in finally,
        // and returns exit code 1. Zero mocking of finally markers.
        $subScript = implode("\n", [
            '<?php',
            'declare(strict_types=1);',
            'require "'.addslashes($root.'/vendor/autoload.php').'";',
            'use Tests\Support\Harness\DisposableMobileV2BackendHarness;',
            '',
            'function runActualPairedGuard(): int {',
            '    $aborted = false;',
            '    $signalReceived = null;',
            '    $handler = function (int $signo) use (&$aborted, &$signalReceived) {',
            '        $aborted = true;',
            '        $signalReceived = $signo;',
            '    };',
            '    $reg = DisposableMobileV2BackendHarness::registerEarlyHoldSignalHandlers($handler);',
            '    if (! $reg) {',
            '        fwrite(STDERR, "Harness refused: failed to register early signal handlers for SIGINT/SIGTERM.\n");',
            '        return 1;',
            '    }',
            '',
            '    $harness = new DisposableMobileV2BackendHarness;',
            '',
            '    try {',
            '        // Safe interrupted setup check 1 (simulating signal arrival before provision)',
            '        posix_kill(getmypid(), SIGTERM);',
            '        pcntl_signal_dispatch();',
            '        if ($aborted) {',
            '            $sigName = $signalReceived === SIGINT ? "SIGINT" : ($signalReceived === SIGTERM ? "SIGTERM" : (string) $signalReceived);',
            '            fwrite(STDERR, "Paired run ABORTED by interrupt signal {$sigName}.\n");',
            '            return 1;',
            '        }',
            '        return 0;',
            '    } catch (\Throwable $e) {',
            '        fwrite(STDERR, "Run failed: {$e->getMessage()}\n");',
            '        return 1;',
            '    } finally {',
            '        // Actual real harness cleanup call — not a mock marker',
            '        $harness->cleanup();',
            '    }',
            '}',
            '',
            'exit(runActualPairedGuard());',
        ]);

        $scriptPath = $tempDir.'/test_actual_guard.php';
        file_put_contents($scriptPath, $subScript);
        chmod($scriptPath, 0600);

        try {
            $proc = new Process([PHP_BINARY, $scriptPath], $root);
            $proc->run();

            $this->assertSame(1, $proc->getExitCode(), 'Actual guard path must exit with code 1 upon signal abort: '.$proc->getErrorOutput().' '.$proc->getOutput());
            $this->assertStringContainsString('Paired run ABORTED by interrupt signal SIGTERM.', $proc->getErrorOutput());
        } finally {
            @unlink($scriptPath);
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function test_paired_hold_truthful_deadline_completed_message_format(): void
    {
        $root = dirname(__DIR__, 4);
        $tempDir = sys_get_temp_dir().'/pure_paired_deadline_'.bin2hex(random_bytes(6));
        mkdir($tempDir, 0700, true);

        // Subprocess script exercises the finite hold loop completing its deadline:
        // Verifies the truthful progress message 'Paired run deadline completed (1s hold expired).'
        // and asserts it does NOT emit any false financial pilot completion claims.
        $subScript = implode("\n", [
            '<?php',
            'declare(strict_types=1);',
            'require "'.addslashes($root.'/vendor/autoload.php').'";',
            'use Tests\Support\Harness\DisposableMobileV2BackendHarness;',
            '',
            'function runDeadlineLoop(int $pairedHoldSeconds): int {',
            '    $aborted = false;',
            '    $signalReceived = null;',
            '    $handler = function (int $signo) use (&$aborted, &$signalReceived) {',
            '        $aborted = true;',
            '        $signalReceived = $signo;',
            '    };',
            '    if (! DisposableMobileV2BackendHarness::registerEarlyHoldSignalHandlers($handler)) {',
            '        return 1;',
            '    }',
            '',
            '    $harness = new DisposableMobileV2BackendHarness;',
            '',
            '    try {',
            '        $elapsedMs = 0;',
            '        $totalMs = $pairedHoldSeconds * 1000;',
            '        while ($elapsedMs < $totalMs && ! $aborted) {',
            '            pcntl_signal_dispatch();',
            '            if ($aborted) {',
            '                break;',
            '            }',
            '            usleep(50000); // 50ms ticks',
            '            $elapsedMs += 50;',
            '        }',
            '',
            '        if ($aborted) {',
            '            return 1;',
            '        }',
            '',
            '        if ($pairedHoldSeconds > 0) {',
            '            echo "Paired run deadline completed ({$pairedHoldSeconds}s hold expired).\n";',
            '            return 0;',
            '        }',
            '        return 0;',
            '    } finally {',
            '        $harness->cleanup();',
            '    }',
            '}',
            '',
            'exit(runDeadlineLoop(1));',
        ]);

        $scriptPath = $tempDir.'/test_deadline_loop.php';
        file_put_contents($scriptPath, $subScript);
        chmod($scriptPath, 0600);

        try {
            $proc = new Process([PHP_BINARY, $scriptPath], $root);
            $proc->run();

            $this->assertSame(0, $proc->getExitCode(), 'Deadline loop should exit 0 on completion: '.$proc->getErrorOutput().' '.$proc->getOutput());
            $this->assertStringContainsString('Paired run deadline completed (1s hold expired).', $proc->getOutput());
            $this->assertStringNotContainsString('FINANCIAL_PILOT_PASSED', $proc->getOutput());
        } finally {
            @unlink($scriptPath);
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function test_financial_post_run_inspector_defaults_to_no_execute_when_flags_missing(): void
    {
        $envVars = ['MOBILE_V2_HARNESS_ENABLE', 'PHASE8_ALLOW_DISPOSABLE_DB'];
        $savedStores = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($envVars);

        try {
            putenv('MOBILE_V2_HARNESS_ENABLE');
            unset($_ENV['MOBILE_V2_HARNESS_ENABLE'], $_SERVER['MOBILE_V2_HARNESS_ENABLE']);
            putenv('PHASE8_ALLOW_DISPOSABLE_DB');
            unset($_ENV['PHASE8_ALLOW_DISPOSABLE_DB'], $_SERVER['PHASE8_ALLOW_DISPOSABLE_DB']);

            $sampleSchema = 'accounting_p8_tmp_0123456789ab';

            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertExecutionGates($sampleSchema);
                $this->fail('Expected RuntimeException for missing harness enable flag');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Inspector refused: defaults to NO-EXECUTE', $e->getMessage());
            }

            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::captureBaseline(1, $sampleSchema);
                $this->fail('Expected RuntimeException for captureBaseline without flags');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Inspector refused: defaults to NO-EXECUTE', $e->getMessage());
            }
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($savedStores);
        }
    }

    #[Test]
    public function test_financial_post_run_inspector_refuses_when_app_env_not_testing(): void
    {
        $envVars = [
            'APP_ENV',
            'MOBILE_V2_HARNESS_ENABLE',
            'PHASE8_ALLOW_DISPOSABLE_DB',
            'PHASE8_TEST_DB_HOST',
            'PHASE8_TEST_DB_PORT',
            'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_PASSWORD',
        ];
        $savedStores = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($envVars);

        try {
            putenv('MOBILE_V2_HARNESS_ENABLE=1');
            $_ENV['MOBILE_V2_HARNESS_ENABLE'] = '1';
            $_SERVER['MOBILE_V2_HARNESS_ENABLE'] = '1';

            putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
            $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';
            $_SERVER['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';

            // 1. Refuse when APP_ENV is unset
            putenv('APP_ENV');
            unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);

            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertExecutionGates('accounting_p8_tmp_0123456789ab');
                $this->fail('Expected RuntimeException when APP_ENV is unset');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('DisposableMariaDbSchema refused: APP_ENV is not testing.', $e->getMessage());
            }

            // 2. Refuse when APP_ENV is explicitly 'production'
            putenv('APP_ENV=production');
            $_ENV['APP_ENV'] = 'production';
            $_SERVER['APP_ENV'] = 'production';

            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertExecutionGates('accounting_p8_tmp_0123456789ab');
                $this->fail('Expected RuntimeException when APP_ENV is production');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('DisposableMariaDbSchema refused: APP_ENV is not testing.', $e->getMessage());
            }
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($savedStores);
        }
    }

    #[Test]
    public function test_financial_post_run_inspector_refuses_malformed_or_unproven_disposable_schema(): void
    {
        $envVars = [
            'APP_ENV',
            'MOBILE_V2_HARNESS_ENABLE',
            'PHASE8_ALLOW_DISPOSABLE_DB',
            'PHASE8_TEST_DB_HOST',
            'PHASE8_TEST_DB_PORT',
            'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_PASSWORD',
            'PHASE8_TEST_DB_EMPTY_PASSWORD',
            'PHASE8_DISPOSABLE_DB_PROOF',
            'PHASE8_DISPOSABLE_DB_NONCE',
        ];
        $savedStores = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($envVars);

        try {
            putenv('APP_ENV=testing');
            $_ENV['APP_ENV'] = 'testing';
            $_SERVER['APP_ENV'] = 'testing';

            putenv('MOBILE_V2_HARNESS_ENABLE=1');
            $_ENV['MOBILE_V2_HARNESS_ENABLE'] = '1';
            $_SERVER['MOBILE_V2_HARNESS_ENABLE'] = '1';

            putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
            $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';
            $_SERVER['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';

            // 1. Refuse malformed schema name
            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertExecutionGates('production_db');
                $this->fail('Expected RuntimeException for non-disposable schema name');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Inspector refused: invalid disposable schema name', $e->getMessage());
            }

            // 2. Refuse when ownership proof is missing - provide local dummy test admin settings to reach ownership proof branch
            putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
            $_ENV['PHASE8_TEST_DB_HOST'] = '127.0.0.1';
            $_SERVER['PHASE8_TEST_DB_HOST'] = '127.0.0.1';

            putenv('PHASE8_TEST_DB_PORT=3342');
            $_ENV['PHASE8_TEST_DB_PORT'] = '3342';
            $_SERVER['PHASE8_TEST_DB_PORT'] = '3342';

            putenv('PHASE8_TEST_DB_USERNAME=disposable_test_user');
            $_ENV['PHASE8_TEST_DB_USERNAME'] = 'disposable_test_user';
            $_SERVER['PHASE8_TEST_DB_USERNAME'] = 'disposable_test_user';

            putenv('PHASE8_TEST_DB_PASSWORD=disposable_test_pass');
            $_ENV['PHASE8_TEST_DB_PASSWORD'] = 'disposable_test_pass';
            $_SERVER['PHASE8_TEST_DB_PASSWORD'] = 'disposable_test_pass';

            putenv('PHASE8_DISPOSABLE_DB_PROOF');
            unset($_ENV['PHASE8_DISPOSABLE_DB_PROOF'], $_SERVER['PHASE8_DISPOSABLE_DB_PROOF']);

            putenv('PHASE8_DISPOSABLE_DB_NONCE');
            unset($_ENV['PHASE8_DISPOSABLE_DB_NONCE'], $_SERVER['PHASE8_DISPOSABLE_DB_NONCE']);

            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertExecutionGates('accounting_p8_tmp_deadbeef0000');
                $this->fail('Expected RuntimeException for missing proof');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Disposable schema ownership proof is required', $e->getMessage());
            }
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($savedStores);
        }
    }

    #[Test]
    public function test_financial_post_run_inspector_fails_closed_when_test_admin_credentials_missing(): void
    {
        $envVars = [
            'APP_ENV',
            'MOBILE_V2_HARNESS_ENABLE',
            'PHASE8_ALLOW_DISPOSABLE_DB',
            'PHASE8_TEST_DB_HOST',
            'PHASE8_TEST_DB_PORT',
            'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_PASSWORD',
        ];
        $savedStores = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($envVars);

        try {
            putenv('APP_ENV=testing');
            $_ENV['APP_ENV'] = 'testing';
            $_SERVER['APP_ENV'] = 'testing';

            putenv('MOBILE_V2_HARNESS_ENABLE=1');
            $_ENV['MOBILE_V2_HARNESS_ENABLE'] = '1';
            $_SERVER['MOBILE_V2_HARNESS_ENABLE'] = '1';

            putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
            $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';
            $_SERVER['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';

            putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
            $_ENV['PHASE8_TEST_DB_HOST'] = '127.0.0.1';
            $_SERVER['PHASE8_TEST_DB_HOST'] = '127.0.0.1';

            putenv('PHASE8_TEST_DB_PORT=3342');
            $_ENV['PHASE8_TEST_DB_PORT'] = '3342';
            $_SERVER['PHASE8_TEST_DB_PORT'] = '3342';

            putenv('PHASE8_TEST_DB_USERNAME');
            unset($_ENV['PHASE8_TEST_DB_USERNAME'], $_SERVER['PHASE8_TEST_DB_USERNAME']);

            putenv('PHASE8_TEST_DB_PASSWORD');
            unset($_ENV['PHASE8_TEST_DB_PASSWORD'], $_SERVER['PHASE8_TEST_DB_PASSWORD']);

            \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertExecutionGates('accounting_p8_tmp_deadbeef0000');
            $this->fail('Expected RuntimeException for missing test administrative connection credentials');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Explicit disposable test administrative connection is required', $e->getMessage());
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($savedStores);
        }
    }

    /**
     * Builds a canonical, fully valid synthetic Trusted DB Report and matching expected pins
     * using the actual DisposableFinancialPostRunInspector::composeTrustedDbReport() method.
     * Labeled SYNTHETIC_NOT_EXECUTION for test fixture purposes only.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{report: array<string, mixed>, expectedPins: array<string, mixed>}
     */
    private function createSyntheticTrustedDbReportFixture(array $overrides = []): array
    {
        $metadata = array_merge([
            'scope' => 'ROOT_AUTHORIZED_PAIRED_EXECUTION',
            'runId' => '019ea456-0000-7000-8000-000000000001',
            'eventId' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'schemaName' => 'accounting_p8_tmp_0123456789ab',
            'companyId' => 1,
            'sourceHead' => 'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
            'handoffDigest' => '8e48f3692a88f5ac3f333f81536d70eb3541228959968697fda03f68df44befc',
        ], $overrides['metadata'] ?? []);

        $baseline = array_merge([
            'company_id' => $metadata['companyId'],
            'schema_name' => $metadata['schemaName'],
            'sales_invoices' => 10,
            'sales_invoices_max_id' => 10,
            'sales_invoice_lines' => 20,
            'sales_invoice_lines_max_id' => 20,
            'sales_invoice_lot_allocations' => 5,
            'sales_invoice_lot_allocations_max_id' => 5,
            'customer_payments' => 5,
            'customer_payments_max_id' => 5,
            'customer_payment_allocations' => 5,
            'customer_payment_allocations_max_id' => 5,
            'mobile_event_receipts' => 10,
            'mobile_event_receipts_max_id' => 10,
            'stock_movements' => 20,
            'stock_movements_max_id' => 20,
            'stock_movements_qty_sum' => '100.000000',
            'posting_batches' => 10,
            'posting_batches_max_id' => 10,
            'posting_lines' => 20,
            'posting_lines_max_id' => 20,
            'document_sequences' => ['sales_invoice:2026' => 10, 'customer_payment:2026' => 5],
        ], $overrides['baseline'] ?? []);

        $defaultDeltas = [
            'sales_invoices' => 1,
            'sales_invoice_lines' => 1,
            'sales_invoice_lot_allocations' => 1,
            'customer_payments' => 1,
            'customer_payment_allocations' => 1,
            'mobile_event_receipts' => 1,
            'stock_movements' => 1,
            'posting_batches' => 2,
            'posting_lines' => 4,
        ];

        $invId = 11;
        $invPubId = '01INVTEST0001';
        $payId = 6;
        $payPubId = '01PAYTEST0001';
        $lineId = 21;
        $smId = 21;
        $lotAllocId = 6;
        $payAllocId = 6;
        $receiptId = 11;
        $invBatchId = 11;
        $payBatchId = 12;
        $payloadDigest = '0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630';

        $defaultCanonicalGraph = [
            'client_event_id' => $metadata['eventId'],
            'sales_invoice_id' => $invId,
            'sales_invoice_public_id' => $invPubId,
            'customer_payment_id' => $payId,
            'customer_payment_public_id' => $payPubId,
            'stock_movement_quantity_delta' => '-1.000000',
            'customer_payment_allocation_amount' => '58.000000',
            'customer_payment_allocation_payment_currency_amount' => '58.000000',
            'receipt_status' => 'completed',
            'sales_invoice' => [
                'id' => $invId,
                'public_id' => $invPubId,
                'company_id' => (int) $metadata['companyId'],
                'status' => 'posted',
                'grand_total_base' => '58.000000',
                'posting_batch_id' => $invBatchId,
            ],
            'sales_invoice_line' => [
                'id' => $lineId,
                'public_id' => '01LINETEST0001',
                'company_id' => (int) $metadata['companyId'],
                'sales_invoice_id' => $invId,
                'line_number' => 1,
                'quantity' => '1.000000',
                'unit_price' => '50.000000',
                'line_total' => '58.000000',
            ],
            'sales_invoice_lot_allocation' => [
                'id' => $lotAllocId,
                'company_id' => (int) $metadata['companyId'],
                'sales_invoice_id' => $invId,
                'sales_invoice_line_id' => $lineId,
                'stock_movement_id' => $smId,
                'quantity_allocated_base' => '1.000000',
            ],
            'customer_payment' => [
                'id' => $payId,
                'public_id' => $payPubId,
                'company_id' => (int) $metadata['companyId'],
                'payment_method' => 'cash',
                'money_account_id' => 1,
                'is_reversed' => 0,
                'reversed_at' => null,
                'amount' => '58.000000',
                'amount_base' => '58.000000',
                'posting_batch_id' => $payBatchId,
            ],
            'customer_payment_allocation' => [
                'id' => $payAllocId,
                'company_id' => (int) $metadata['companyId'],
                'customer_payment_id' => $payId,
                'sales_invoice_id' => $invId,
                'allocated_amount' => '58.000000',
                'payment_currency_amount' => '58.000000',
            ],
            'stock_movement' => [
                'id' => $smId,
                'company_id' => (int) $metadata['companyId'],
                'source_type' => 'sales_invoice',
                'source_id' => $invId,
                'quantity_delta_base' => '-1.000000',
            ],
            'posting_batches' => [
                [
                    'id' => $invBatchId,
                    'company_id' => (int) $metadata['companyId'],
                    'source_type' => 'sales_invoice',
                    'source_id' => $invId,
                    'status' => 'posted',
                    'lines' => [
                        ['id' => 21, 'company_id' => (int) $metadata['companyId'], 'posting_batch_id' => $invBatchId, 'ledger_account_id' => 101, 'debit_base' => '58.000000', 'credit_base' => '0.000000'],
                        ['id' => 22, 'company_id' => (int) $metadata['companyId'], 'posting_batch_id' => $invBatchId, 'ledger_account_id' => 102, 'debit_base' => '0.000000', 'credit_base' => '58.000000'],
                    ],
                ],
                [
                    'id' => $payBatchId,
                    'company_id' => (int) $metadata['companyId'],
                    'source_type' => 'customer_payment',
                    'source_id' => $payId,
                    'status' => 'posted',
                    'lines' => [
                        ['id' => 23, 'company_id' => (int) $metadata['companyId'], 'posting_batch_id' => $payBatchId, 'ledger_account_id' => 201, 'debit_base' => '58.000000', 'credit_base' => '0.000000'],
                        ['id' => 24, 'company_id' => (int) $metadata['companyId'], 'posting_batch_id' => $payBatchId, 'ledger_account_id' => 202, 'debit_base' => '0.000000', 'credit_base' => '58.000000'],
                    ],
                ],
            ],
            'document_sequences' => [
                'before' => ['sales_invoice:2026' => 10, 'customer_payment:2026' => 5],
                'after' => ['sales_invoice:2026' => 11, 'customer_payment:2026' => 6],
            ],
            'mobile_event_receipt' => [
                'id' => $receiptId,
                'company_id' => (int) $metadata['companyId'],
                'client_event_id' => $metadata['eventId'],
                'payload_digest' => $payloadDigest,
                'sales_invoice_id' => $invId,
                'sales_invoice_public_id' => $invPubId,
                'customer_payment_id' => $payId,
                'customer_payment_public_id' => $payPubId,
                'status' => 'completed',
            ],
        ];

        $canonicalGraph = $defaultCanonicalGraph;
        if (isset($overrides['delta']['canonical_graph'])) {
            foreach ($overrides['delta']['canonical_graph'] as $k => $v) {
                $canonicalGraph[$k] = $v;
            }
        }

        $deltaResult = array_merge([
            'is_canonical_delta_valid' => true,
            'gl_batches_balanced' => true,
            'deltas' => $defaultDeltas,
        ], $overrides['delta'] ?? [], [
            'canonical_graph' => $canonicalGraph,
        ]);

        $reconcileResult = array_merge([
            'all_healthy' => true,
            'reconciler_results' => [
                'AccountingReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'InventoryReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'SalesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'PayablesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'MoneyReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'Phase7ReconciliationService' => ['healthy' => true, 'issue_count' => 0],
            ],
        ], $overrides['reconcile'] ?? []);

        $report = \Tests\Support\Harness\DisposableFinancialPostRunInspector::composeTrustedDbReport(
            $metadata,
            $baseline,
            $deltaResult,
            $reconcileResult
        );

        $expectedPins = [
            'clientPublicationHead' => $overrides['clientPublicationHead'] ?? 'c00aedb70ea06f28ae42282149c030df76dc300d',
            'clientExecutableHead' => $overrides['clientExecutableHead'] ?? 'b7e61eb4ed0aed2f5040d9fc44f9764b310be530',
            'backendExecutionHead' => $metadata['sourceHead'],
            'handoffDigest' => $metadata['handoffDigest'],
            'expectedSchemaName' => $metadata['schemaName'],
            'companyId' => $metadata['companyId'],
            'expectedRunId' => $metadata['runId'],
            'expectedEventId' => $metadata['eventId'],
            'expectedPayloadDigest' => $canonicalGraph['mobile_event_receipt']['payload_digest'] ?? $payloadDigest,
            'expectedBaselineHash' => $report['baseline_hash'],
            'expectedScope' => $metadata['scope'],
            'expectedLifecycle' => 'FINANCIAL_PAIRED_RUN',
        ];
        if (isset($overrides['expectedPins'])) {
            $expectedPins = array_merge($expectedPins, $overrides['expectedPins']);
        }

        return [
            'report' => $report,
            'expectedPins' => $expectedPins,
        ];
    }

    /**
     * Loads the byte-identical actual Kotlin golden wire export fixture (SHA: 25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6).
     *
     * @return array<string, mixed>
     */
    private function getGoldenWireFixtureData(): array
    {
        $fixturePath = dirname(__DIR__, 3).'/Support/Harness/fixtures/synthetic-financial-golden-wire.json';
        $this->assertFileExists($fixturePath);

        $rawJson = (string) file_get_contents($fixturePath);
        $goldenData = json_decode($rawJson, true);
        $this->assertIsArray($goldenData);

        return $goldenData;
    }

    #[Test]
    public function test_financial_outer_validator_accepts_valid_golden_android_evidence_and_trusted_db_report(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();
        $fixture = $this->createSyntheticTrustedDbReportFixture();

        $result = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $fixture['expectedPins'],
            $fixture['report']
        );

        $this->assertTrue($result['is_valid'], 'Validation failed with errors: '.implode('; ', $result['errors']));
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function test_financial_outer_validator_strictly_rejects_unadmitted_wrong_owner_publication_sha(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();
        $fixture = $this->createSyntheticTrustedDbReportFixture();

        $payloadWithBadPub = $goldenData;
        $payloadWithBadPub['clientPublicationHead'] = \Tests\Support\Harness\DisposableFinancialPostRunInspector::UNADMITTED_ANDROID_PUBLICATION_SHA;

        $result = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $payloadWithBadPub,
            $fixture['expectedPins'],
            $fixture['report']
        );

        $this->assertFalse($result['is_valid']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($err) => str_contains($err, 'unadmitted wrong-owner publication pin')));
    }

    #[Test]
    public function test_financial_outer_validator_rejects_swapped_or_mismatched_pins_and_overclaims(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();
        $fixture = $this->createSyntheticTrustedDbReportFixture();

        // 1. Swapped pub and exe pins rejected
        $swappedPayload = $goldenData;
        $swappedPayload['clientPublicationHead'] = $fixture['expectedPins']['clientExecutableHead'];
        $swappedPayload['clientExecutableHead'] = $fixture['expectedPins']['clientPublicationHead'];
        $resultSwapped = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $swappedPayload,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertFalse($resultSwapped['is_valid']);
        $this->assertTrue(collect($resultSwapped['errors'])->contains(fn ($err) => str_contains($err, 'Client publication pin mismatch')));

        // 2. Over-claiming full pilot when lost_ack was claimed PASS rejected
        $overclaimPayload = $goldenData;
        $overclaimPayload['scenarios'][5]['status'] = 'PASS';
        $overclaimPayload['scenarios'][5]['httpStatusCode'] = 200;
        $resultOverclaim = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $overclaimPayload,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertFalse($resultOverclaim['is_valid']);
        $this->assertTrue(collect($resultOverclaim['errors'])->contains(fn ($err) => str_contains($err, 'cannot claim \'PASS\' in basic financial run')));

        // 3. Rejects missing separate trusted DB report (MANDATORY)
        $resultNoDb = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $fixture['expectedPins'],
            null
        );
        $this->assertFalse($resultNoDb['is_valid']);
        $this->assertTrue(collect($resultNoDb['errors'])->contains(fn ($err) => str_contains($err, 'Separate REQUIRED trusted owned DB inspection report is mandatory')));

        // 4. Rejects associative map in scenarios
        $mapPayload = $goldenData;
        $mapPayload['scenarios'] = [
            'readiness_probe' => ['status' => 'PASS', 'httpStatusCode' => 200],
        ];
        $resultMap = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $mapPayload,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertFalse($resultMap['is_valid']);
        $this->assertTrue(collect($resultMap['errors'])->contains(fn ($err) => str_contains($err, 'Scenarios must be a sequential array list')));

        // 5. Rejects non-strict integer httpStatusCode
        $stringCodePayload = $goldenData;
        $stringCodePayload['scenarios'][0]['httpStatusCode'] = '200';
        $resultStringCode = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $stringCodePayload,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertFalse($resultStringCode['is_valid']);
        $this->assertTrue(collect($resultStringCode['errors'])->contains(fn ($err) => str_contains($err, 'httpStatusCode must be a strict integer')));
    }

    #[Test]
    public function test_financial_inspector_cli_defaults_to_no_execute(): void
    {
        $cliPath = dirname(__DIR__, 3).'/Support/Harness/financial-inspector-cli.php';
        $this->assertFileExists($cliPath);

        $process = new \Symfony\Component\Process\Process([PHP_BINARY, $cliPath]);
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Inspector defaults to NO-EXECUTE', $process->getErrorOutput());
    }

    #[Test]
    public function test_cross_repo_kotlin_golden_fixture_parity_and_mutation_gates(): void
    {
        $fixturePath = dirname(__DIR__, 3).'/Support/Harness/fixtures/synthetic-financial-golden-wire.json';
        $this->assertFileExists($fixturePath);

        // 1. Assert exact byte hash of the actual Kotlin golden export
        $expectedHash = '25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6';
        $actualHash = hash_file('sha256', $fixturePath);
        $this->assertSame($expectedHash, $actualHash, 'Synthetic golden wire fixture SHA mismatch');

        $goldenData = json_decode((string) file_get_contents($fixturePath), true);
        $this->assertIsArray($goldenData);

        // Canonical synthetic trusted DB fixture matching actual Kotlin golden pins
        $fixture = $this->createSyntheticTrustedDbReportFixture();

        // 2. Pure parity acceptance on unaltered golden wire JSON
        $result = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertTrue($result['is_valid'], 'Golden fixture failed validation: '.implode('; ', $result['errors']));
        $this->assertSame([], $result['errors']);

        // 3. Mutation negative: mutated httpStatusCode in scenario (201 -> 200)
        $mutatedCode = $goldenData;
        $mutatedCode['scenarios'][1]['httpStatusCode'] = 200;
        $resMutatedCode = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $mutatedCode,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertFalse($resMutatedCode['is_valid']);
        $this->assertTrue(collect($resMutatedCode['errors'])->contains(fn ($e) => str_contains($e, 'HTTP status code mismatch')));

        // 4. Mutation negative: corrupted trusted DB report (issue_count = 1)
        $corruptFixture = $this->createSyntheticTrustedDbReportFixture([
            'reconcile' => [
                'all_healthy' => false,
                'reconciler_results' => [
                    'AccountingReconciliationService' => ['healthy' => false, 'issue_count' => 1],
                    'InventoryReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                    'SalesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                    'PayablesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                    'MoneyReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                    'Phase7ReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                ],
            ],
        ]);
        $resCorruptDb = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $corruptFixture['expectedPins'],
            $corruptFixture['report']
        );
        $this->assertFalse($resCorruptDb['is_valid']);
        $this->assertTrue(collect($resCorruptDb['errors'])->contains(fn ($e) => str_contains($e, 'issue_count must be exactly 0')));

        // 5. Mutation negative: missing trusted DB report
        $resNoDb = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $fixture['expectedPins'],
            null
        );
        $this->assertFalse($resNoDb['is_valid']);
        $this->assertTrue(collect($resNoDb['errors'])->contains(fn ($e) => str_contains($e, 'Separate REQUIRED trusted owned DB inspection report is mandatory')));
    }

    #[Test]
    public function test_compose_trusted_db_report_generates_deterministic_pinnable_digest(): void
    {
        $metadata = [
            'scope' => 'ROOT_AUTHORIZED_PAIRED_EXECUTION',
            'runId' => '019ea456-0000-7000-8000-000000000001',
            'eventId' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'schemaName' => 'accounting_p8_tmp_0123456789ab',
            'companyId' => 1,
            'sourceHead' => 'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
            'handoffDigest' => '8e48f3692a88f5ac3f333f81536d70eb3541228959968697fda03f68df44befc',
        ];

        $baseline = [
            'company_id' => 1,
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
            'sales_invoices' => 10,
            'sales_invoices_max_id' => 10,
            'sales_invoice_lines' => 10,
            'sales_invoice_lines_max_id' => 10,
            'sales_invoice_lot_allocations' => 0,
            'sales_invoice_lot_allocations_max_id' => 0,
            'customer_payments' => 5,
            'customer_payments_max_id' => 5,
            'customer_payment_allocations' => 5,
            'customer_payment_allocations_max_id' => 5,
            'mobile_event_receipts' => 0,
            'mobile_event_receipts_max_id' => 0,
            'stock_movements' => 20,
            'stock_movements_max_id' => 20,
            'stock_movements_qty_sum' => '100.000000',
            'posting_batches' => 15,
            'posting_batches_max_id' => 15,
            'posting_lines' => 30,
            'posting_lines_max_id' => 30,
            'document_sequences' => ['sales_invoice:2026' => 11, 'customer_payment:2026' => 6],
        ];

        $deltaResult = [
            'is_canonical_delta_valid' => true,
            'expected_delta_one_event' => true,
            'deltas' => [
                'sales_invoices' => 1,
                'sales_invoice_lines' => 1,
                'sales_invoice_lot_allocations' => 1,
                'customer_payments' => 1,
                'customer_payment_allocations' => 1,
                'mobile_event_receipts' => 1,
                'stock_movements' => 1,
                'posting_batches' => 2,
                'posting_lines' => 4,
            ],
            'errors' => [],
            'gl_batches_balanced' => true,
            'canonical_graph' => [
                'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                'sales_invoice_id' => 11,
                'sales_invoice_public_id' => '01INVTEST0001',
                'customer_payment_id' => 6,
                'customer_payment_public_id' => '01PAYTEST0001',
                'stock_movement_quantity_delta' => '-1.000000',
                'customer_payment_allocation_amount' => '58.000000',
                'customer_payment_allocation_payment_currency_amount' => '58.000000',
                'receipt_status' => 'completed',
            ],
        ];

        $reconcileResult = [
            'all_healthy' => true,
            'reconciler_results' => [
                'AccountingReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'InventoryReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'SalesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'PayablesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'MoneyReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'Phase7ReconciliationService' => ['healthy' => true, 'issue_count' => 0],
            ],
        ];

        $report = \Tests\Support\Harness\DisposableFinancialPostRunInspector::composeTrustedDbReport(
            $metadata,
            $baseline,
            $deltaResult,
            $reconcileResult
        );

        $this->assertSame('ROOT_AUTHORIZED_PAIRED_EXECUTION', $report['scope']);
        $this->assertSame('019ea456-0000-7000-8000-000000000001', $report['run_id']);
        $this->assertSame('c0686be5-888f-4890-90bf-1f05f416a19a', $report['event_id']);
        $this->assertSame('accounting_p8_tmp_0123456789ab', $report['schema_name']);
        $this->assertSame(1, $report['company_id']);
        $this->assertTrue($report['is_canonical_delta_valid']);
        $this->assertTrue($report['gl_batches_balanced']);
        $this->assertTrue($report['all_healthy']);
        $this->assertNotEmpty($report['baseline_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $report['report_digest']);

        // Verify deterministic report digest stability
        $report2 = \Tests\Support\Harness\DisposableFinancialPostRunInspector::composeTrustedDbReport(
            $metadata,
            $baseline,
            $deltaResult,
            $reconcileResult
        );
        $this->assertSame($report['report_digest'], $report2['report_digest']);
        $this->assertSame($report['baseline_hash'], $report2['baseline_hash']);
    }

    #[Test]
    public function test_secure_bootstrap_credentials_loader_enforces_permissions_and_schema_match(): void
    {
        $envVars = [
            'PHASE8_TEST_DB_HOST',
            'PHASE8_TEST_DB_PORT',
            'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_PASSWORD',
            'PHASE8_TEST_DB_EMPTY_PASSWORD',
            'PHASE8_DISPOSABLE_DB_PROOF',
            'PHASE8_DISPOSABLE_DB_NONCE',
        ];
        $savedStores = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($envVars);

        $tmpDir = sys_get_temp_dir().'/guard_test_creds_'.bin2hex(random_bytes(6));
        mkdir($tmpDir, 0700, true);
        chmod($tmpDir, 0700);

        $validConfig = [
            'db_host' => '127.0.0.1',
            'db_port' => '3342',
            'db_username' => 'test_user',
            'db_password' => 'test_pass',
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
            'proof_path' => '/tmp/proof.txt',
            'proof_nonce' => 'nonce123',
        ];

        $configFile = $tmpDir.'/child_bootstrap_config.json';
        file_put_contents($configFile, json_encode($validConfig, JSON_PRETTY_PRINT));
        chmod($configFile, 0600);

        try {
            // 1. Valid config parses successfully
            $creds = \Tests\Support\Harness\DisposableFinancialPostRunInspector::loadSecureBootstrapCredentials(
                $configFile,
                'accounting_p8_tmp_0123456789ab'
            );
            $this->assertSame('127.0.0.1', $creds['host']);
            $this->assertSame('3342', $creds['port']);
            $this->assertSame('test_user', $creds['username']);
            $this->assertSame('test_pass', $creds['password']);
            $this->assertSame('accounting_p8_tmp_0123456789ab', $creds['schema_name']);
            $this->assertSame('127.0.0.1', getenv('PHASE8_TEST_DB_HOST'));
            $this->assertSame('3342', getenv('PHASE8_TEST_DB_PORT'));

            // 2. Insecure file permissions rejected
            chmod($configFile, 0666);
            $insecureException = null;
            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::loadSecureBootstrapCredentials(
                    $configFile,
                    'accounting_p8_tmp_0123456789ab'
                );
            } catch (\RuntimeException $e) {
                $insecureException = $e;
            }
            $this->assertNotNull($insecureException);
            $this->assertStringContainsString('BOOTSTRAP_CONFIG_INSECURE_PERMS', $insecureException->getMessage());
            chmod($configFile, 0600);

            // 3. Schema mismatch throws
            $mismatchException = null;
            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::loadSecureBootstrapCredentials(
                    $configFile,
                    'accounting_p8_tmp_ffffffffffff'
                );
            } catch (\RuntimeException $e) {
                $mismatchException = $e;
            }
            $this->assertNotNull($mismatchException);
            $this->assertStringContainsString('BOOTSTRAP_CONFIG_SCHEMA_MISMATCH', $mismatchException->getMessage());
        } finally {
            @unlink($configFile);
            @rmdir($tmpDir);
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($savedStores);
        }
    }

    #[Test]
    public function test_outer_validator_recomputes_report_digest_and_rejects_corrupted_digest_or_missing_run_id(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();
        $fixture = $this->createSyntheticTrustedDbReportFixture();

        // 1. Valid composed report passes
        $validResult = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertTrue($validResult['is_valid'], 'Expected valid result: '.implode('; ', $validResult['errors']));

        // 2. Corrupted report_digest is rejected
        $corruptDigestReport = $fixture['report'];
        $corruptDigestReport['report_digest'] = str_repeat('f', 64);
        $badDigestResult = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $fixture['expectedPins'],
            $corruptDigestReport
        );
        $this->assertFalse($badDigestResult['is_valid']);
        $this->assertTrue(collect($badDigestResult['errors'])->contains(fn ($e) => str_contains($e, 'report_digest mismatch')));

        // 3. Missing expectedRunId is rejected
        $missingRunIdPins = $fixture['expectedPins'];
        unset($missingRunIdPins['expectedRunId']);
        $badRunIdResult = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $missingRunIdPins,
            $fixture['report']
        );
        $this->assertFalse($badRunIdResult['is_valid']);
        $this->assertTrue(collect($badRunIdResult['errors'])->contains(fn ($e) => str_contains($e, "'expectedRunId' is mandatory")));
    }

    #[Test]
    public function test_outer_validator_rejects_missing_or_invalid_or_mismatched_expected_event_id(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();
        $fixture = $this->createSyntheticTrustedDbReportFixture();

        // 1. Missing expectedEventId rejected
        $missingEventIdPins = $fixture['expectedPins'];
        unset($missingEventIdPins['expectedEventId']);
        $resMissing = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $missingEventIdPins,
            $fixture['report']
        );
        $this->assertFalse($resMissing['is_valid']);
        $this->assertTrue(collect($resMissing['errors'])->contains(fn ($e) => str_contains($e, "'expectedEventId' is mandatory")));

        // 2. Malformed expectedEventId format rejected
        $badFormatPins = $fixture['expectedPins'];
        $badFormatPins['expectedEventId'] = 'not-a-valid-uuid';
        $resBadFormat = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $badFormatPins,
            $fixture['report']
        );
        $this->assertFalse($resBadFormat['is_valid']);
        $this->assertTrue(collect($resBadFormat['errors'])->contains(fn ($e) => str_contains($e, 'must be a valid UUID')));

        // 3. Mismatched expectedEventId vs report event_id rejected
        $mismatchPins = $fixture['expectedPins'];
        $mismatchPins['expectedEventId'] = '00000000-0000-0000-0000-000000000000';
        $resMismatch = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $mismatchPins,
            $fixture['report']
        );
        $this->assertFalse($resMismatch['is_valid']);
        $this->assertTrue(collect($resMismatch['errors'])->contains(fn ($e) => str_contains($e, 'event_id mismatch')));

        // 4. Mutated external expectedRunId vs unchanged evidence/report fails for actual run_id mismatch
        $mismatchedRunPins = $fixture['expectedPins'];
        $mismatchedRunPins['expectedRunId'] = '00000000-0000-0000-0000-000000000001';
        $resMismatchRun = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $mismatchedRunPins,
            $fixture['report']
        );
        $this->assertFalse($resMismatchRun['is_valid']);
        $this->assertTrue(collect($resMismatchRun['errors'])->contains(fn ($e) => str_contains($e, 'run_id mismatch') || str_contains($e, 'runId mismatch')));

        // 5. Legitimate equal run/event UUID regression: when BOTH externally expected pins + evidence runId + composer metadata consistently match, validation succeeds
        $sharedUuid = 'c0686be5-888f-4890-90bf-1f05f416a19a';
        $equalUuidEvidence = $goldenData;
        $equalUuidEvidence['runId'] = $sharedUuid;
        $equalUuidFixture = $this->createSyntheticTrustedDbReportFixture([
            'metadata' => [
                'runId' => $sharedUuid,
                'eventId' => $sharedUuid,
            ],
        ]);
        $resEqualUuid = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $equalUuidEvidence,
            $equalUuidFixture['expectedPins'],
            $equalUuidFixture['report']
        );
        $this->assertTrue($resEqualUuid['is_valid'], 'Legitimate equal run/event UUIDs must be accepted when consistently bound: '.implode('; ', $resEqualUuid['errors']));
        $this->assertSame([], $resEqualUuid['errors']);
    }

    #[Test]
    public function test_outer_validator_rejects_empty_or_invalid_deltas(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // 1. Empty deltas rejected
        $emptyDeltasFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => ['deltas' => []],
        ]);
        $resEmpty = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $emptyDeltasFixture['expectedPins'],
            $emptyDeltasFixture['report']
        );
        $this->assertFalse($resEmpty['is_valid']);
        $this->assertTrue(collect($resEmpty['errors'])->contains(fn ($e) => str_contains($e, 'deltas array is missing or empty')));

        // 2. Incorrect delta count (sales_invoices = 0)
        $badCountFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'deltas' => [
                    'sales_invoices' => 0,
                    'sales_invoice_lines' => 1,
                    'sales_invoice_lot_allocations' => 1,
                    'customer_payments' => 1,
                    'customer_payment_allocations' => 1,
                    'mobile_event_receipts' => 1,
                    'stock_movements' => 1,
                    'posting_batches' => 2,
                    'posting_lines' => 4,
                ],
            ],
        ]);
        $resBadCount = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $badCountFixture['expectedPins'],
            $badCountFixture['report']
        );
        $this->assertFalse($resBadCount['is_valid']);
        $this->assertTrue(collect($resBadCount['errors'])->contains(fn ($e) => str_contains($e, "delta 'sales_invoices' mismatch")));

        // 3. Insufficient posting lines (< 4)
        $lowLinesFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'deltas' => [
                    'sales_invoices' => 1,
                    'sales_invoice_lines' => 1,
                    'sales_invoice_lot_allocations' => 1,
                    'customer_payments' => 1,
                    'customer_payment_allocations' => 1,
                    'mobile_event_receipts' => 1,
                    'stock_movements' => 1,
                    'posting_batches' => 2,
                    'posting_lines' => 2,
                ],
            ],
        ]);
        $resLowLines = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $lowLinesFixture['expectedPins'],
            $lowLinesFixture['report']
        );
        $this->assertFalse($resLowLines['is_valid']);
        $this->assertTrue(collect($resLowLines['errors'])->contains(fn ($e) => str_contains($e, "delta 'posting_lines' must be >= 4")));
    }

    #[Test]
    public function test_outer_validator_accepts_equal_cross_table_primary_key_ids_with_correctly_bound_graph(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // Cross-table primary keys may legitimately both be 1 in independent auto-increment tables.
        // Domain distinction is enforced through distinct public ULIDs and strictly validated FK pointers.
        $equalIdFixture = $this->createSyntheticTrustedDbReportFixture([
            'baseline' => [
                'sales_invoices' => 0,
                'sales_invoices_max_id' => 0,
                'sales_invoice_lines' => 0,
                'sales_invoice_lines_max_id' => 0,
                'sales_invoice_lot_allocations' => 0,
                'sales_invoice_lot_allocations_max_id' => 0,
                'customer_payments' => 0,
                'customer_payments_max_id' => 0,
                'customer_payment_allocations' => 0,
                'customer_payment_allocations_max_id' => 0,
                'mobile_event_receipts' => 0,
                'mobile_event_receipts_max_id' => 0,
                'stock_movements' => 0,
                'stock_movements_max_id' => 0,
                'posting_batches' => 0,
                'posting_batches_max_id' => 0,
                'posting_lines' => 0,
                'posting_lines_max_id' => 0,
                'document_sequences' => ['sales_invoice:2026' => 1, 'customer_payment:2026' => 1],
            ],
            'delta' => [
                'canonical_graph' => [
                    'sales_invoice_id' => 1,
                    'sales_invoice_public_id' => '01INVTEST0001',
                    'customer_payment_id' => 1,
                    'customer_payment_public_id' => '01PAYTEST0001',
                    'document_sequences' => [
                        'before' => ['sales_invoice:2026' => 1, 'customer_payment:2026' => 1],
                        'after' => ['sales_invoice:2026' => 2, 'customer_payment:2026' => 2],
                    ],
                    'sales_invoice' => [
                        'id' => 1,
                        'public_id' => '01INVTEST0001',
                        'company_id' => 1,
                        'status' => 'posted',
                        'grand_total_base' => '58.000000',
                        'posting_batch_id' => 1,
                    ],
                    'sales_invoice_line' => [
                        'id' => 1,
                        'public_id' => '01LINETEST0001',
                        'company_id' => 1,
                        'sales_invoice_id' => 1,
                        'line_number' => 1,
                        'quantity' => '1.000000',
                        'unit_price' => '50.000000',
                        'line_total' => '58.000000',
                    ],
                    'sales_invoice_lot_allocation' => [
                        'id' => 1,
                        'company_id' => 1,
                        'sales_invoice_id' => 1,
                        'sales_invoice_line_id' => 1,
                        'stock_movement_id' => 1,
                        'quantity_allocated_base' => '1.000000',
                    ],
                    'customer_payment' => [
                        'id' => 1,
                        'public_id' => '01PAYTEST0001',
                        'company_id' => 1,
                        'payment_method' => 'cash',
                        'money_account_id' => 1,
                        'is_reversed' => 0,
                        'reversed_at' => null,
                        'amount' => '58.000000',
                        'amount_base' => '58.000000',
                        'posting_batch_id' => 2,
                    ],
                    'customer_payment_allocation' => [
                        'id' => 1,
                        'company_id' => 1,
                        'customer_payment_id' => 1,
                        'sales_invoice_id' => 1,
                        'allocated_amount' => '58.000000',
                        'payment_currency_amount' => '58.000000',
                    ],
                    'stock_movement' => [
                        'id' => 1,
                        'company_id' => 1,
                        'source_type' => 'sales_invoice',
                        'source_id' => 1,
                        'quantity_delta_base' => '-1.000000',
                    ],
                    'posting_batches' => [
                        [
                            'id' => 1,
                            'company_id' => 1,
                            'source_type' => 'sales_invoice',
                            'source_id' => 1,
                            'status' => 'posted',
                            'lines' => [
                                ['id' => 1, 'company_id' => 1, 'posting_batch_id' => 1, 'ledger_account_id' => 101, 'debit_base' => '58.000000', 'credit_base' => '0.000000'],
                                ['id' => 2, 'company_id' => 1, 'posting_batch_id' => 1, 'ledger_account_id' => 102, 'debit_base' => '0.000000', 'credit_base' => '58.000000'],
                            ],
                        ],
                        [
                            'id' => 2,
                            'company_id' => 1,
                            'source_type' => 'customer_payment',
                            'source_id' => 1,
                            'status' => 'posted',
                            'lines' => [
                                ['id' => 3, 'company_id' => 1, 'posting_batch_id' => 2, 'ledger_account_id' => 201, 'debit_base' => '58.000000', 'credit_base' => '0.000000'],
                                ['id' => 4, 'company_id' => 1, 'posting_batch_id' => 2, 'ledger_account_id' => 202, 'debit_base' => '0.000000', 'credit_base' => '58.000000'],
                            ],
                        ],
                    ],
                    'mobile_event_receipt' => [
                        'id' => 1,
                        'company_id' => 1,
                        'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                        'payload_digest' => '0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630',
                        'sales_invoice_id' => 1,
                        'sales_invoice_public_id' => '01INVTEST0001',
                        'customer_payment_id' => 1,
                        'customer_payment_public_id' => '01PAYTEST0001',
                        'status' => 'completed',
                    ],
                ],
            ],
        ]);

        $res = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $equalIdFixture['expectedPins'],
            $equalIdFixture['report']
        );
        $this->assertTrue($res['is_valid'], 'Equal cross-table IDs must be accepted: '.implode('; ', $res['errors']));
        $this->assertSame([], $res['errors']);
    }

    #[Test]
    public function test_outer_validator_rejects_identical_public_ulids_between_invoice_and_payment(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        $identicalUlidFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'sales_invoice_public_id' => '01DUPLICATEULID01',
                    'customer_payment_public_id' => '01DUPLICATEULID01',
                ],
            ],
        ]);

        $res = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $identicalUlidFixture['expectedPins'],
            $identicalUlidFixture['report']
        );
        $this->assertFalse($res['is_valid']);
        $this->assertTrue(collect($res['errors'])->contains(fn ($e) => str_contains($e, 'must be distinct')));
    }

    #[Test]
    public function test_outer_validator_rejects_swapped_fk_pair_in_allocation(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // In allocation: swap customer_payment_id (set to invoice ID 11) and sales_invoice_id (set to payment ID 6)
        $swappedFkFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'customer_payment_allocation' => [
                        'id' => 6,
                        'company_id' => 1,
                        'customer_payment_id' => 11, // Swapped! Should be 6
                        'sales_invoice_id' => 6,     // Swapped! Should be 11
                        'allocated_amount' => '58.000000',
                        'payment_currency_amount' => '58.000000',
                    ],
                ],
            ],
        ]);

        $res = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $swappedFkFixture['expectedPins'],
            $swappedFkFixture['report']
        );
        $this->assertFalse($res['is_valid']);
        $this->assertTrue(collect($res['errors'])->contains(fn ($e) => str_contains($e, 'customer_payment_id mismatch') || str_contains($e, 'sales_invoice_id mismatch')));
    }

    #[Test]
    public function test_outer_validator_fails_closed_on_absent_allocation_stock_or_gl_entries(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // 1. Absent customer_payment_allocation
        $missingAllocFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'customer_payment_allocation' => null,
                ],
            ],
        ]);
        $resAlloc = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $missingAllocFixture['expectedPins'],
            $missingAllocFixture['report']
        );
        $this->assertFalse($resAlloc['is_valid']);
        $this->assertTrue(collect($resAlloc['errors'])->contains(fn ($e) => str_contains($e, 'customer_payment_allocation entry is missing')));

        // 2. Absent stock_movement
        $missingSmFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'stock_movement' => null,
                ],
            ],
        ]);
        $resSm = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $missingSmFixture['expectedPins'],
            $missingSmFixture['report']
        );
        $this->assertFalse($resSm['is_valid']);
        $this->assertTrue(collect($resSm['errors'])->contains(fn ($e) => str_contains($e, 'stock_movement entry is missing')));

        // 3. Absent posting_batches
        $missingBatchesFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'posting_batches' => [],
                ],
            ],
        ]);
        $resBatches = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $missingBatchesFixture['expectedPins'],
            $missingBatchesFixture['report']
        );
        $this->assertFalse($resBatches['is_valid']);
        $this->assertTrue(collect($resBatches['errors'])->contains(fn ($e) => str_contains($e, 'posting_batches must contain exactly 2 batches')));
    }

    #[Test]
    public function test_outer_validator_rejects_wrong_receipt_payload_digest_or_company(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // 1. Wrong receipt payload_digest
        $wrongDigestFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'mobile_event_receipt' => [
                        'id' => 11,
                        'company_id' => 1,
                        'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                        'payload_digest' => '1111111111111111111111111111111111111111111111111111111111111111',
                        'sales_invoice_id' => 11,
                        'sales_invoice_public_id' => '01INVTEST0001',
                        'customer_payment_id' => 6,
                        'customer_payment_public_id' => '01PAYTEST0001',
                        'status' => 'completed',
                    ],
                ],
            ],
            'expectedPins' => [
                'expectedPayloadDigest' => '0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630',
            ],
        ]);
        $resDigest = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $wrongDigestFixture['expectedPins'],
            $wrongDigestFixture['report']
        );
        $this->assertFalse($resDigest['is_valid']);
        $this->assertTrue(collect($resDigest['errors'])->contains(fn ($e) => str_contains($e, 'payload_digest mismatch')));

        // 2. Wrong receipt company_id
        $wrongCompanyFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'mobile_event_receipt' => [
                        'id' => 11,
                        'company_id' => 999, // Wrong company
                        'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                        'payload_digest' => '0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630',
                        'sales_invoice_id' => 11,
                        'sales_invoice_public_id' => '01INVTEST0001',
                        'customer_payment_id' => 6,
                        'customer_payment_public_id' => '01PAYTEST0001',
                        'status' => 'completed',
                    ],
                ],
            ],
        ]);
        $resCompany = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $wrongCompanyFixture['expectedPins'],
            $wrongCompanyFixture['report']
        );
        $this->assertFalse($resCompany['is_valid']);
        $this->assertTrue(collect($resCompany['errors'])->contains(fn ($e) => str_contains($e, 'company_id mismatch')));
    }

    #[Test]
    public function test_outer_validator_rejects_altered_selected_row_amounts(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // 1. Altered sales_invoice grand_total_base
        $alteredInvFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'sales_invoice' => [
                        'id' => 11,
                        'public_id' => '01INVTEST0001',
                        'company_id' => 1,
                        'status' => 'posted',
                        'grand_total_base' => '50.000000', // Altered amount
                        'posting_batch_id' => 11,
                    ],
                ],
            ],
        ]);
        $resInv = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $alteredInvFixture['expectedPins'],
            $alteredInvFixture['report']
        );
        $this->assertFalse($resInv['is_valid']);
        $this->assertTrue(collect($resInv['errors'])->contains(fn ($e) => str_contains($e, "sales_invoice.grand_total_base must be '58.000000'")));

        // 2. Altered customer_payment amount
        $alteredPayFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'customer_payment' => [
                        'id' => 6,
                        'public_id' => '01PAYTEST0001',
                        'company_id' => 1,
                        'payment_method' => 'cash',
                        'money_account_id' => 1,
                        'is_reversed' => 0,
                        'reversed_at' => null,
                        'amount' => '50.000000', // Altered amount
                        'amount_base' => '58.000000',
                        'posting_batch_id' => 12,
                    ],
                ],
            ],
        ]);
        $resPay = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $alteredPayFixture['expectedPins'],
            $alteredPayFixture['report']
        );
        $this->assertFalse($resPay['is_valid']);
        $this->assertTrue(collect($resPay['errors'])->contains(fn ($e) => str_contains($e, "customer_payment.amount must be '58.000000'")));

        // 3. Altered stock_movement quantity_delta_base
        $alteredSmFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'stock_movement' => [
                        'id' => 21,
                        'company_id' => 1,
                        'source_type' => 'sales_invoice',
                        'source_id' => 11,
                        'quantity_delta_base' => '-2.000000', // Altered delta
                    ],
                ],
            ],
        ]);
        $resSm = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $alteredSmFixture['expectedPins'],
            $alteredSmFixture['report']
        );
        $this->assertFalse($resSm['is_valid']);
        $this->assertTrue(collect($resSm['errors'])->contains(fn ($e) => str_contains($e, "stock_movement.quantity_delta_base must be '-1.000000'")));
    }

    #[Test]
    public function test_outer_validator_enforces_sales_invoice_line_net_unit_price_and_gross_line_total(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // 1. Valid fixture with net unit_price 50.000000, gross line_total 58.000000, and payment 58.000000 is accepted
        $validFixture = $this->createSyntheticTrustedDbReportFixture();
        $resValid = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $validFixture['expectedPins'],
            $validFixture['report']
        );
        $this->assertTrue($resValid['is_valid'], 'Valid canonical graph with net unit_price 50 and gross total 58 must pass: '.implode('; ', $resValid['errors']));
        $this->assertSame([], $resValid['errors']);

        // 2. Erroneously gross unit_price 58.000000 is rejected
        $grossUnitPriceFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'sales_invoice_line' => [
                        'id' => 21,
                        'public_id' => '01LINETEST0001',
                        'company_id' => 1,
                        'sales_invoice_id' => 11,
                        'line_number' => 1,
                        'quantity' => '1.000000',
                        'unit_price' => '58.000000', // Incorrectly conflated with line_total
                        'line_total' => '58.000000',
                    ],
                ],
            ],
        ]);
        $resGross = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $grossUnitPriceFixture['expectedPins'],
            $grossUnitPriceFixture['report']
        );
        $this->assertFalse($resGross['is_valid']);
        $this->assertTrue(collect($resGross['errors'])->contains(fn ($e) => str_contains($e, "sales_invoice_line.unit_price must be '50.000000', got '58.000000'")));
    }

    #[Test]
    public function test_outer_validator_rejects_mutated_canonical_graph_due_to_report_digest_mismatch(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();
        $fixture = $this->createSyntheticTrustedDbReportFixture();

        // Tamper with canonical graph in report without recomputing report_digest
        $tamperedReport = $fixture['report'];
        $tamperedReport['canonical_graph']['sales_invoice_id'] = 999;

        $res = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $fixture['expectedPins'],
            $tamperedReport
        );
        $this->assertFalse($res['is_valid']);
        $this->assertTrue(collect($res['errors'])->contains(fn ($e) => str_contains($e, 'report_digest mismatch')));
    }

    #[Test]
    public function test_outer_validator_rejects_not_run_scenario_with_non_null_or_missing_status_code(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();
        $fixture = $this->createSyntheticTrustedDbReportFixture();

        // 1. httpStatusCode = 0 rejected (must be exact JSON null)
        $payloadWithZero = $goldenData;
        $payloadWithZero['scenarios'][5]['httpStatusCode'] = 0;
        $resZero = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $payloadWithZero,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertFalse($resZero['is_valid']);
        $this->assertTrue(collect($resZero['errors'])->contains(fn ($e) => str_contains($e, 'httpStatusCode must be exact JSON null')));

        // 2. httpStatusCode = "0" rejected
        $payloadWithString = $goldenData;
        $payloadWithString['scenarios'][5]['httpStatusCode'] = '0';
        $resString = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $payloadWithString,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertFalse($resString['is_valid']);
        $this->assertTrue(collect($resString['errors'])->contains(fn ($e) => str_contains($e, 'httpStatusCode must be exact JSON null')));

        // 3. httpStatusCode = 200 rejected
        $payloadWith200 = $goldenData;
        $payloadWith200['scenarios'][5]['httpStatusCode'] = 200;
        $res200 = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $payloadWith200,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertFalse($res200['is_valid']);
        $this->assertTrue(collect($res200['errors'])->contains(fn ($e) => str_contains($e, 'httpStatusCode must be exact JSON null')));

        // 4. Missing httpStatusCode rejected
        $payloadMissing = $goldenData;
        unset($payloadMissing['scenarios'][5]['httpStatusCode']);
        $resMissing = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $payloadMissing,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertFalse($resMissing['is_valid']);
        $this->assertTrue(collect($resMissing['errors'])->contains(fn ($e) => str_contains($e, 'httpStatusCode must be exact JSON null')));
    }

    #[Test]
    public function test_inspect_delta_enforces_mandatory_uuid_expected_event_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expectedClientEventId must be a valid non-empty UUID');

        \Tests\Support\Harness\DisposableFinancialPostRunInspector::inspectDelta(
            1,
            [],
            'bad-uuid',
            'accounting_p8_tmp_0123456789ab',
            '0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630'
        );
    }

    #[Test]
    public function test_inspect_delta_enforces_mandatory_expected_payload_digest_pure_format(): void
    {
        // 1. Empty expectedPayloadDigest throws InvalidArgumentException before PDO
        try {
            \Tests\Support\Harness\DisposableFinancialPostRunInspector::inspectDelta(
                1,
                [],
                'c0686be5-888f-4890-90bf-1f05f416a19a',
                'accounting_p8_tmp_0123456789ab',
                ''
            );
            $this->fail('Expected InvalidArgumentException on empty expectedPayloadDigest.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('expectedPayloadDigest must be a non-empty 64-hex SHA-256 hash', $e->getMessage());
        }

        // 2. Invalid hex length/format throws InvalidArgumentException before PDO
        try {
            \Tests\Support\Harness\DisposableFinancialPostRunInspector::inspectDelta(
                1,
                [],
                'c0686be5-888f-4890-90bf-1f05f416a19a',
                'accounting_p8_tmp_0123456789ab',
                'not-a-valid-sha'
            );
            $this->fail('Expected InvalidArgumentException on invalid expectedPayloadDigest format.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('expectedPayloadDigest must be a valid 64-hex SHA-256 hash', $e->getMessage());
        }
    }

    #[Test]
    public function test_outer_validator_rejects_empty_or_unchanged_document_sequences(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // 1. Empty document_sequences before/after
        $emptySeqFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'document_sequences' => [
                        'before' => [],
                        'after' => [],
                    ],
                ],
            ],
        ]);
        $resEmpty = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $emptySeqFixture['expectedPins'],
            $emptySeqFixture['report']
        );
        $this->assertFalse($resEmpty['is_valid']);
        $this->assertTrue(collect($resEmpty['errors'])->contains(fn ($e) => str_contains($e, 'must be non-empty')));

        // 2. Unchanged document sequences (no increment for sales_invoice and customer_payment)
        $unchangedSeqFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'document_sequences' => [
                        'before' => ['sales_invoice:2026' => 10, 'customer_payment:2026' => 5],
                        'after' => ['sales_invoice:2026' => 10, 'customer_payment:2026' => 5],
                    ],
                ],
            ],
        ]);
        $resUnchanged = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $unchangedSeqFixture['expectedPins'],
            $unchangedSeqFixture['report']
        );
        $this->assertFalse($resUnchanged['is_valid']);
        $this->assertTrue(collect($resUnchanged['errors'])->contains(fn ($e) => str_contains($e, 'missing exact +1 increment for sales_invoice')));
        $this->assertTrue(collect($resUnchanged['errors'])->contains(fn ($e) => str_contains($e, 'missing exact +1 increment for customer_payment')));

        // 3. Mutated unrelated document sequence
        $mutatedSeqFixture = $this->createSyntheticTrustedDbReportFixture([
            'baseline' => [
                'document_sequences' => ['sales_invoice:2026' => 10, 'customer_payment:2026' => 5, 'quote:2026' => 1],
            ],
            'delta' => [
                'canonical_graph' => [
                    'document_sequences' => [
                        'before' => ['sales_invoice:2026' => 10, 'customer_payment:2026' => 5, 'quote:2026' => 1],
                        'after' => ['sales_invoice:2026' => 11, 'customer_payment:2026' => 6, 'quote:2026' => 999],
                    ],
                ],
            ],
        ]);
        $resMutated = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $mutatedSeqFixture['expectedPins'],
            $mutatedSeqFixture['report']
        );
        $this->assertFalse($resMutated['is_valid']);
        $this->assertTrue(collect($resMutated['errors'])->contains(fn ($e) => str_contains($e, 'Unrelated document sequence')));
    }

    #[Test]
    public function test_outer_validator_rejects_baseline_old_entity_ids(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // 1. sales_invoice.id <= baseline_max_id (10 <= 10)
        $oldInvFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'sales_invoice_id' => 10,
                    'sales_invoice' => [
                        'id' => 10,
                        'public_id' => '01INVTEST0001',
                        'company_id' => 1,
                        'status' => 'posted',
                        'grand_total_base' => '58.000000',
                        'posting_batch_id' => 11,
                    ],
                ],
            ],
        ]);
        $resInv = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $oldInvFixture['expectedPins'],
            $oldInvFixture['report']
        );
        $this->assertFalse($resInv['is_valid']);
        $this->assertTrue(collect($resInv['errors'])->contains(fn ($e) => str_contains($e, 'newer than baseline max')));

        // 2. customer_payment.id <= baseline_max_id (5 <= 5)
        $oldPayFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'customer_payment_id' => 5,
                    'customer_payment' => [
                        'id' => 5,
                        'public_id' => '01PAYTEST0001',
                        'company_id' => 1,
                        'payment_method' => 'cash',
                        'money_account_id' => 1,
                        'is_reversed' => 0,
                        'reversed_at' => null,
                        'amount' => '58.000000',
                        'amount_base' => '58.000000',
                        'posting_batch_id' => 12,
                    ],
                ],
            ],
        ]);
        $resPay = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $oldPayFixture['expectedPins'],
            $oldPayFixture['report']
        );
        $this->assertFalse($resPay['is_valid']);
        $this->assertTrue(collect($resPay['errors'])->contains(fn ($e) => str_contains($e, 'newer than baseline max')));
    }

    #[Test]
    public function test_outer_validator_rejects_duplicate_gl_lines_or_wrong_parent_batch_fk(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // 1. Duplicate line ID across batches (line 21 in batch 11 and also in batch 12)
        $dupLineFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'posting_batches' => [
                        [
                            'id' => 11,
                            'company_id' => 1,
                            'source_type' => 'sales_invoice',
                            'source_id' => 11,
                            'status' => 'posted',
                            'lines' => [
                                ['id' => 21, 'company_id' => 1, 'posting_batch_id' => 11, 'ledger_account_id' => 101, 'debit_base' => '58.000000', 'credit_base' => '0.000000'],
                                ['id' => 22, 'company_id' => 1, 'posting_batch_id' => 11, 'ledger_account_id' => 102, 'debit_base' => '0.000000', 'credit_base' => '58.000000'],
                            ],
                        ],
                        [
                            'id' => 12,
                            'company_id' => 1,
                            'source_type' => 'customer_payment',
                            'source_id' => 6,
                            'status' => 'posted',
                            'lines' => [
                                ['id' => 21, 'company_id' => 1, 'posting_batch_id' => 12, 'ledger_account_id' => 201, 'debit_base' => '58.000000', 'credit_base' => '0.000000'], // Duplicate ID 21!
                                ['id' => 24, 'company_id' => 1, 'posting_batch_id' => 12, 'ledger_account_id' => 202, 'debit_base' => '0.000000', 'credit_base' => '58.000000'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $resDup = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $dupLineFixture['expectedPins'],
            $dupLineFixture['report']
        );
        $this->assertFalse($resDup['is_valid']);
        $this->assertTrue(collect($resDup['errors'])->contains(fn ($e) => str_contains($e, 'Duplicate posting line ID')));

        // 2. Line posting_batch_id mismatch with parent batch id
        $fkMismatchFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'posting_batches' => [
                        [
                            'id' => 11,
                            'company_id' => 1,
                            'source_type' => 'sales_invoice',
                            'source_id' => 11,
                            'status' => 'posted',
                            'lines' => [
                                ['id' => 21, 'company_id' => 1, 'posting_batch_id' => 999, 'ledger_account_id' => 101, 'debit_base' => '58.000000', 'credit_base' => '0.000000'], // Wrong parent FK 999!
                                ['id' => 22, 'company_id' => 1, 'posting_batch_id' => 11, 'ledger_account_id' => 102, 'debit_base' => '0.000000', 'credit_base' => '58.000000'],
                            ],
                        ],
                        [
                            'id' => 12,
                            'company_id' => 1,
                            'source_type' => 'customer_payment',
                            'source_id' => 6,
                            'status' => 'posted',
                            'lines' => [
                                ['id' => 23, 'company_id' => 1, 'posting_batch_id' => 12, 'ledger_account_id' => 201, 'debit_base' => '58.000000', 'credit_base' => '0.000000'],
                                ['id' => 24, 'company_id' => 1, 'posting_batch_id' => 12, 'ledger_account_id' => 202, 'debit_base' => '0.000000', 'credit_base' => '58.000000'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $resFk = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $fkMismatchFixture['expectedPins'],
            $fkMismatchFixture['report']
        );
        $this->assertFalse($resFk['is_valid']);
        $this->assertTrue(collect($resFk['errors'])->contains(fn ($e) => str_contains($e, 'posting_batch_id mismatch')));
    }

    #[Test]
    public function test_outer_validator_rejects_reversed_payment_or_missing_money_account(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // 1. is_reversed = 1
        $reversedFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'customer_payment' => [
                        'id' => 6,
                        'public_id' => '01PAYTEST0001',
                        'company_id' => 1,
                        'payment_method' => 'cash',
                        'money_account_id' => 1,
                        'is_reversed' => 1, // Reversed!
                        'reversed_at' => null,
                        'amount' => '58.000000',
                        'amount_base' => '58.000000',
                        'posting_batch_id' => 12,
                    ],
                ],
            ],
        ]);
        $resRev = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $reversedFixture['expectedPins'],
            $reversedFixture['report']
        );
        $this->assertFalse($resRev['is_valid']);
        $this->assertTrue(collect($resRev['errors'])->contains(fn ($e) => str_contains($e, 'customer_payment.is_reversed must be 0')));

        // 2. reversed_at non-null
        $reversedAtFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'customer_payment' => [
                        'id' => 6,
                        'public_id' => '01PAYTEST0001',
                        'company_id' => 1,
                        'payment_method' => 'cash',
                        'money_account_id' => 1,
                        'is_reversed' => 0,
                        'reversed_at' => '2026-11-15 00:00:00', // Non-null!
                        'amount' => '58.000000',
                        'amount_base' => '58.000000',
                        'posting_batch_id' => 12,
                    ],
                ],
            ],
        ]);
        $resRevAt = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $reversedAtFixture['expectedPins'],
            $reversedAtFixture['report']
        );
        $this->assertFalse($resRevAt['is_valid']);
        $this->assertTrue(collect($resRevAt['errors'])->contains(fn ($e) => str_contains($e, 'customer_payment.reversed_at must be null')));

        // 3. money_account_id missing or <= 0
        $badMoneyFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'customer_payment' => [
                        'id' => 6,
                        'public_id' => '01PAYTEST0001',
                        'company_id' => 1,
                        'payment_method' => 'cash',
                        'money_account_id' => 0, // Invalid money account!
                        'is_reversed' => 0,
                        'reversed_at' => null,
                        'amount' => '58.000000',
                        'amount_base' => '58.000000',
                        'posting_batch_id' => 12,
                    ],
                ],
            ],
        ]);
        $resMoney = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $badMoneyFixture['expectedPins'],
            $badMoneyFixture['report']
        );
        $this->assertFalse($resMoney['is_valid']);
        $this->assertTrue(collect($resMoney['errors'])->contains(fn ($e) => str_contains($e, 'customer_payment.money_account_id must be a positive integer')));
    }

    #[Test]
    public function test_outer_validator_rejects_missing_or_null_lot_stock_movement_fk(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        $nullLotSmFixture = $this->createSyntheticTrustedDbReportFixture([
            'delta' => [
                'canonical_graph' => [
                    'sales_invoice_lot_allocation' => [
                        'id' => 6,
                        'company_id' => 1,
                        'sales_invoice_id' => 11,
                        'sales_invoice_line_id' => 21,
                        'stock_movement_id' => null, // Missing / null lot movement FK!
                        'quantity_allocated_base' => '1.000000',
                    ],
                ],
            ],
        ]);
        $resLot = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $goldenData,
            $nullLotSmFixture['expectedPins'],
            $nullLotSmFixture['report']
        );
        $this->assertFalse($resLot['is_valid']);
        $this->assertTrue(collect($resLot['errors'])->contains(fn ($e) => str_contains($e, 'sales_invoice_lot_allocation.stock_movement_id must be non-null')));
    }

    #[Test]
    public function test_standalone_cli_bootstrap_dependency_closure_constructs_real_container_without_db(): void
    {
        $prevContainer = (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue();
        $prevFacadeApp = (new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app'))->getValue();

        try {
            // Simulate bare CLI context: container and facades cleared
            \Illuminate\Container\Container::setInstance(null);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication(null);

            \Tests\Support\Harness\DisposableFinancialPostRunInspector::ensureStandaloneBootstrapDependencyClosure();

            $container = \Illuminate\Container\Container::getInstance();
            $this->assertInstanceOf(\Illuminate\Foundation\Application::class, $container);
            $this->assertTrue($container->bound('cache'));
            $this->assertTrue($container->bound(\Illuminate\Cache\CacheManager::class));
            $this->assertTrue($container->bound(\Spatie\Permission\PermissionRegistrar::class));

            // Verify safe config defaults: isolation invariants enforced
            $this->assertSame('array', $container->make('config')->get('cache.default'));
            $this->assertSame('disposable_inspector', $container->make('config')->get('database.default'));

            // Verify Spatie getPermissionsTeamId() resolves without container/CacheManager exception
            $this->assertNull(getPermissionsTeamId());
            $this->assertNull($container->make(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId());

            // Idempotency: calling again preserves the exact container instance
            \Tests\Support\Harness\DisposableFinancialPostRunInspector::ensureStandaloneBootstrapDependencyClosure();
            $this->assertSame($container, \Illuminate\Container\Container::getInstance());
        } finally {
            \Illuminate\Container\Container::setInstance($prevContainer);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($prevFacadeApp);
        }
    }

    #[Test]
    public function test_run_six_reconciliations_restores_bare_context_on_denied_execution_gates(): void
    {
        $prevContainer = (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue();
        $prevFacadeApp = (new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app'))->getValue();
        $savedEnvStores = SupervisedPairedFinancialDrill::snapshotEnvironmentStores([
            'MOBILE_V2_HARNESS_ENABLE',
            'PHASE8_ALLOW_DISPOSABLE_DB',
        ]);

        try {
            // Explicitly scope flags OFF: defaults to NO-EXECUTE
            putenv('MOBILE_V2_HARNESS_ENABLE=0');
            $_ENV['MOBILE_V2_HARNESS_ENABLE'] = '0';
            $_SERVER['MOBILE_V2_HARNESS_ENABLE'] = '0';

            putenv('PHASE8_ALLOW_DISPOSABLE_DB=0');
            $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '0';
            $_SERVER['PHASE8_ALLOW_DISPOSABLE_DB'] = '0';

            // 1. Establish bare context: container and facades cleared
            \Illuminate\Container\Container::setInstance(null);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication(null);

            $this->assertNull((new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue());
            $this->assertNull((new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app'))->getValue());

            // 2. Invoke actual runSixReconciliations with denied execution gates (flags OFF + invalid schema name)
            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::runSixReconciliations(
                    1,
                    'denied_invalid_schema_name'
                );
                $this->fail('Expected runSixReconciliations to fail closed on denied execution gates.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString(
                    'Inspector refused: defaults to NO-EXECUTE. Set MOBILE_V2_HARNESS_ENABLE=1 under explicit Root coordination.',
                    $e->getMessage()
                );
            }

            // 3. Assert bare context is fully restored in finally
            $this->assertNull((new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue());
            $this->assertNull((new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app'))->getValue());
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($savedEnvStores);
            \Illuminate\Container\Container::setInstance($prevContainer);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($prevFacadeApp);
        }
    }

    #[Test]
    public function test_run_six_reconciliations_preserves_partially_configured_caller_application_on_failure(): void
    {
        $prevContainer = (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue();
        $prevFacadeApp = (new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app'))->getValue();
        $savedEnvStores = SupervisedPairedFinancialDrill::snapshotEnvironmentStores([
            'MOBILE_V2_HARNESS_ENABLE',
            'PHASE8_ALLOW_DISPOSABLE_DB',
        ]);

        $appDir = dirname(__DIR__, 4);
        $callerApp = new \Illuminate\Foundation\Application($appDir);
        $callerApp['env'] = 'testing';

        // Configure caller config
        $configRepo = new \Illuminate\Config\Repository([
            'caller_custom_key' => 'caller_custom_val_123',
            'cache' => [
                'default' => 'array',
                'stores' => ['array' => ['driver' => 'array', 'serialize' => false]],
            ],
            'permission' => require $appDir.'/config/permission.php',
        ]);
        $callerApp->instance('config', $configRepo);

        // Configure caller auth
        $customAuth = new \stdClass();
        $callerApp->instance('auth', $customAuth);

        // Configure caller cache
        $cacheManager = new \Illuminate\Cache\CacheManager($callerApp);
        $callerApp->instance('cache', $cacheManager);
        $callerApp->instance(\Illuminate\Contracts\Cache\Factory::class, $cacheManager);
        $callerApp->instance(\Illuminate\Cache\CacheManager::class, $cacheManager);

        // Bind PermissionRegistrar and set non-null team
        $callerApp->singleton(\Spatie\Permission\PermissionRegistrar::class);
        $callerApp->make(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(777);

        try {
            // Explicitly scope flags OFF: defaults to NO-EXECUTE
            putenv('MOBILE_V2_HARNESS_ENABLE=0');
            $_ENV['MOBILE_V2_HARNESS_ENABLE'] = '0';
            $_SERVER['MOBILE_V2_HARNESS_ENABLE'] = '0';

            putenv('PHASE8_ALLOW_DISPOSABLE_DB=0');
            $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '0';
            $_SERVER['PHASE8_ALLOW_DISPOSABLE_DB'] = '0';

            \Illuminate\Container\Container::setInstance($callerApp);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($callerApp);

            // Resolve a facade to cache resolved instance
            $this->assertSame('caller_custom_val_123', \Illuminate\Support\Facades\Config::get('caller_custom_key'));
            $this->assertSame(777, getPermissionsTeamId());

            $facadeResolvedRef = new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'resolvedInstance');
            $this->assertArrayHasKey('config', $facadeResolvedRef->getValue());

            // Invoke actual runSixReconciliations with denied execution gates
            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::runSixReconciliations(
                    1,
                    'denied_invalid_schema_name'
                );
                $this->fail('Expected runSixReconciliations to fail closed on denied execution gates.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString(
                    'Inspector refused: defaults to NO-EXECUTE. Set MOBILE_V2_HARNESS_ENABLE=1 under explicit Root coordination.',
                    $e->getMessage()
                );
            }

            // Assert caller context is completely preserved and unchanged
            $this->assertSame($callerApp, (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue());
            $this->assertSame($callerApp, (new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app'))->getValue());
            $this->assertSame('caller_custom_val_123', $callerApp->make('config')->get('caller_custom_key'));
            $this->assertSame($customAuth, $callerApp->make('auth'));
            $this->assertSame($cacheManager, $callerApp->make('cache'));
            $this->assertSame(777, getPermissionsTeamId());
            $this->assertArrayHasKey('config', $facadeResolvedRef->getValue());
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($savedEnvStores);
            \Illuminate\Container\Container::setInstance($prevContainer);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($prevFacadeApp);
        }
    }

    #[Test]
    public function test_run_six_reconciliations_preserves_complete_caller_context_on_denied_execution_gates(): void
    {
        $prevContainer = (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue();
        $prevFacadeApp = (new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app'))->getValue();
        $savedEnvStores = SupervisedPairedFinancialDrill::snapshotEnvironmentStores([
            'MOBILE_V2_HARNESS_ENABLE',
            'PHASE8_ALLOW_DISPOSABLE_DB',
        ]);

        $appDir = dirname(__DIR__, 4);
        $completeApp = new \Illuminate\Foundation\Application($appDir);
        $completeApp['env'] = 'testing';
        \Tests\Support\Harness\DisposableFinancialPostRunInspector::bindIsolatedContainerServices($completeApp);
        $completeApp->make(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(888);

        try {
            // Explicitly scope flags OFF: defaults to NO-EXECUTE
            putenv('MOBILE_V2_HARNESS_ENABLE=0');
            $_ENV['MOBILE_V2_HARNESS_ENABLE'] = '0';
            $_SERVER['MOBILE_V2_HARNESS_ENABLE'] = '0';

            putenv('PHASE8_ALLOW_DISPOSABLE_DB=0');
            $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '0';
            $_SERVER['PHASE8_ALLOW_DISPOSABLE_DB'] = '0';

            \Illuminate\Container\Container::setInstance($completeApp);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($completeApp);

            $this->assertSame(888, getPermissionsTeamId());

            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::runSixReconciliations(
                    1,
                    'denied_invalid_schema_name'
                );
                $this->fail('Expected runSixReconciliations to fail closed on denied execution gates.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString(
                    'Inspector refused: defaults to NO-EXECUTE. Set MOBILE_V2_HARNESS_ENABLE=1 under explicit Root coordination.',
                    $e->getMessage()
                );
            }

            $this->assertSame($completeApp, (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue());
            $this->assertSame($completeApp, (new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app'))->getValue());
            $this->assertSame(888, getPermissionsTeamId());
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($savedEnvStores);
            \Illuminate\Container\Container::setInstance($prevContainer);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($prevFacadeApp);
        }
    }

    #[Test]
    public function test_create_isolated_bootstrap_application_preserves_ambient_container_identity(): void
    {
        $prevContainer = (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue();
        $prevFacadeApp = (new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app'))->getValue();

        try {
            // (a) Null sentinel: ambient Container::$instance must remain null
            \Illuminate\Container\Container::setInstance(null);
            $this->assertNull((new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue());

            $isolatedApp1 = \Tests\Support\Harness\DisposableFinancialPostRunInspector::createIsolatedBootstrapApplication();
            $this->assertNull(
                (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue(),
                'Raw global Container::$instance must remain null immediately after createIsolatedBootstrapApplication.'
            );
            $this->assertInstanceOf(\Illuminate\Foundation\Application::class, $isolatedApp1);
            $isolatedApp1->flush();

            // (b) Generic Container sentinel: ambient Container::$instance must remain generic sentinel
            $genericSentinel = new \Illuminate\Container\Container();
            \Illuminate\Container\Container::setInstance($genericSentinel);
            $this->assertSame($genericSentinel, (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue());

            $isolatedApp2 = \Tests\Support\Harness\DisposableFinancialPostRunInspector::createIsolatedBootstrapApplication();
            $this->assertSame(
                $genericSentinel,
                (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue(),
                'Raw global Container::$instance must remain generic sentinel immediately after createIsolatedBootstrapApplication.'
            );
            $isolatedApp2->flush();

            // (c) Application sentinel: ambient Container::$instance must remain Application sentinel
            $appDir = dirname(__DIR__, 4);
            $appSentinel = new \Illuminate\Foundation\Application($appDir);
            \Illuminate\Container\Container::setInstance($appSentinel);
            $this->assertSame($appSentinel, (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue());

            $isolatedApp3 = \Tests\Support\Harness\DisposableFinancialPostRunInspector::createIsolatedBootstrapApplication();
            $this->assertSame(
                $appSentinel,
                (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue(),
                'Raw global Container::$instance must remain Application sentinel immediately after createIsolatedBootstrapApplication.'
            );
            $isolatedApp3->flush();
            $appSentinel->flush();
        } finally {
            \Illuminate\Container\Container::setInstance($prevContainer);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($prevFacadeApp);
        }
    }

    #[Test]
    public function test_run_six_reconciliations_fails_closed_and_preserves_caller_on_deliberately_failing_registrar(): void
    {
        $prevContainer = (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue();
        $prevFacadeApp = (new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app'))->getValue();
        $prevResolver = \Illuminate\Database\Eloquent\Model::getConnectionResolver();
        $savedEnvStores = SupervisedPairedFinancialDrill::snapshotEnvironmentStores([
            'MOBILE_V2_HARNESS_ENABLE',
            'PHASE8_ALLOW_DISPOSABLE_DB',
        ]);

        $appDir = dirname(__DIR__, 4);
        $callerApp = new \Illuminate\Foundation\Application($appDir);
        $callerApp['env'] = 'testing';

        $configRepo = new \Illuminate\Config\Repository([
            'failing_registrar_key' => 'failing_registrar_val_456',
        ]);
        $callerApp->instance('config', $configRepo);

        $customAuth = new \stdClass();
        $callerApp->instance('auth', $customAuth);

        $customResolver = new class implements \Illuminate\Database\ConnectionResolverInterface {
            public function connection($name = null): mixed { throw new \RuntimeException('Resolver connection not used.'); }
            public function getDefaultConnection(): string { return 'dummy'; }
            public function setDefaultConnection($name): void {}
        };
        \Illuminate\Database\Eloquent\Model::setConnectionResolver($customResolver);

        // Bind deliberately failing registrar factory
        $callerApp->bind(\Spatie\Permission\PermissionRegistrar::class, function () {
            throw new \RuntimeException('Deliberate registrar factory resolution failure.');
        });

        try {
            // Explicitly scope flags OFF: defaults to NO-EXECUTE
            putenv('MOBILE_V2_HARNESS_ENABLE=0');
            $_ENV['MOBILE_V2_HARNESS_ENABLE'] = '0';
            $_SERVER['MOBILE_V2_HARNESS_ENABLE'] = '0';

            putenv('PHASE8_ALLOW_DISPOSABLE_DB=0');
            $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '0';
            $_SERVER['PHASE8_ALLOW_DISPOSABLE_DB'] = '0';

            \Illuminate\Container\Container::setInstance($callerApp);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($callerApp);

            // Resolve config facade to cache resolved instance
            $this->assertSame('failing_registrar_val_456', \Illuminate\Support\Facades\Config::get('failing_registrar_key'));
            $facadeResolvedRef = new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'resolvedInstance');
            $this->assertArrayHasKey('config', $facadeResolvedRef->getValue());

            // Invoke runSixReconciliations: must fail closed without masking the error
            try {
                \Tests\Support\Harness\DisposableFinancialPostRunInspector::runSixReconciliations(
                    1,
                    'denied_invalid_schema_name'
                );
                $this->fail('Expected runSixReconciliations to fail closed on failing registrar factory.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Failed to snapshot caller permission team ID: Deliberate registrar factory resolution failure.', $e->getMessage());
            }

            // Assert exact caller context is preserved despite early failure
            $this->assertSame($callerApp, (new \ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue());
            $this->assertSame($callerApp, (new \ReflectionProperty(\Illuminate\Support\Facades\Facade::class, 'app'))->getValue());
            $this->assertSame('failing_registrar_val_456', $callerApp->make('config')->get('failing_registrar_key'));
            $this->assertSame($customAuth, $callerApp->make('auth'));
            $this->assertSame($customResolver, \Illuminate\Database\Eloquent\Model::getConnectionResolver());
            $this->assertArrayHasKey('config', $facadeResolvedRef->getValue());
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($savedEnvStores);
            if ($prevResolver !== null) {
                \Illuminate\Database\Eloquent\Model::setConnectionResolver($prevResolver);
            } else {
                \Illuminate\Database\Eloquent\Model::unsetConnectionResolver();
            }
            \Illuminate\Container\Container::setInstance($prevContainer);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($prevFacadeApp);
        }
    }

    #[Test]
    public function test_harness_lost_ack_suppression_configuration_defaults_to_null_and_enforces_strict_types(): void
    {
        $harness = new \Tests\Support\Harness\DisposableMobileV2BackendHarness();
        $this->assertNull($harness->getLostAckSuppression(), 'Lost ACK suppression must default to null (default-deny)');

        // 1. null config leaves it null
        $harness->configureLostAckSuppression(null);
        $this->assertNull($harness->getLostAckSuppression());

        // 2. enabled = false leaves it null
        $harness->configureLostAckSuppression(['enabled' => false]);
        $this->assertNull($harness->getLostAckSuppression());

        // 3. Wrong scope throws
        try {
            $harness->configureLostAckSuppression([
                'enabled' => true,
                'scope' => 'WRONG_SCOPE',
                'root_grant' => 'ADVANCED_LOST_ACK_PROBE',
                'target_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                'target_schema' => 'accounting_p8_tmp_0123456789ab',
                'target_nonce' => str_repeat('a', 64),
                'target_uid' => function_exists('posix_getuid') ? posix_getuid() : getmyuid(),
            ]);
            $this->fail('Expected InvalidArgumentException for invalid scope');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("Lost ACK suppression requires scope 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION'", $e->getMessage());
        }

        // 4. Wrong root_grant throws
        try {
            $harness->configureLostAckSuppression([
                'enabled' => true,
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'root_grant' => 'WRONG_GRANT',
                'target_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                'target_schema' => 'accounting_p8_tmp_0123456789ab',
                'target_nonce' => str_repeat('a', 64),
                'target_uid' => function_exists('posix_getuid') ? posix_getuid() : getmyuid(),
            ]);
            $this->fail('Expected InvalidArgumentException for invalid root_grant');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("Lost ACK suppression requires root_grant 'ADVANCED_LOST_ACK_PROBE'", $e->getMessage());
        }

        // 5. Invalid UUID throws
        try {
            $harness->configureLostAckSuppression([
                'enabled' => true,
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'root_grant' => 'ADVANCED_LOST_ACK_PROBE',
                'target_event_id' => 'not-a-uuid',
                'target_schema' => 'accounting_p8_tmp_0123456789ab',
                'target_nonce' => str_repeat('a', 64),
                'target_uid' => function_exists('posix_getuid') ? posix_getuid() : getmyuid(),
            ]);
            $this->fail('Expected InvalidArgumentException for non-UUID target_event_id');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('target_event_id must be valid UUID', $e->getMessage());
        }

        // 6. Invalid schema regex throws
        try {
            $harness->configureLostAckSuppression([
                'enabled' => true,
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'root_grant' => 'ADVANCED_LOST_ACK_PROBE',
                'target_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                'target_schema' => 'invalid_schema_name',
                'target_nonce' => str_repeat('a', 64),
                'target_uid' => function_exists('posix_getuid') ? posix_getuid() : getmyuid(),
            ]);
            $this->fail('Expected InvalidArgumentException for invalid target_schema regex');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('target_schema must match disposable schema regex', $e->getMessage());
        }

        // 7. Non-hex or invalid-length nonce throws
        try {
            $harness->configureLostAckSuppression([
                'enabled' => true,
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'root_grant' => 'ADVANCED_LOST_ACK_PROBE',
                'target_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                'target_schema' => 'accounting_p8_tmp_0123456789ab',
                'target_nonce' => 'short-nonce',
                'target_uid' => function_exists('posix_getuid') ? posix_getuid() : getmyuid(),
            ]);
            $this->fail('Expected InvalidArgumentException for invalid target_nonce');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('target_nonce must be 32 to 64 hex characters', $e->getMessage());
        }

        // 8. Mismatched UID throws
        try {
            $harness->configureLostAckSuppression([
                'enabled' => true,
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'root_grant' => 'ADVANCED_LOST_ACK_PROBE',
                'target_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                'target_schema' => 'accounting_p8_tmp_0123456789ab',
                'target_nonce' => str_repeat('a', 64),
                'target_uid' => 9999999,
            ]);
            $this->fail('Expected InvalidArgumentException for mismatched target_uid');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('target_uid mismatch', $e->getMessage());
        }

        // 9. Valid 64-hex config accepted
        $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
        $validConfig64 = [
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'root_grant' => 'ADVANCED_LOST_ACK_PROBE',
            'enabled' => true,
            'target_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'target_schema' => 'accounting_p8_tmp_0123456789ab',
            'target_nonce' => str_repeat('a', 64),
            'target_uid' => $currentUid,
        ];
        $harness->configureLostAckSuppression($validConfig64);
        $this->assertSame($validConfig64, $harness->getLostAckSuppression());

        // 10. Valid 32-hex config accepted (matches bin2hex(random_bytes(16)) harness nonce)
        $validConfig32 = [
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'root_grant' => 'ADVANCED_LOST_ACK_PROBE',
            'enabled' => true,
            'target_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'target_schema' => 'accounting_p8_tmp_0123456789ab',
            'target_nonce' => str_repeat('b', 32),
            'target_uid' => $currentUid,
        ];
        $harness->configureLostAckSuppression($validConfig32);
        $this->assertSame($validConfig32, $harness->getLostAckSuppression());
    }

    #[Test]
    public function test_harness_simulated_fault_stage_configuration_and_validation(): void
    {
        $harness = new \Tests\Support\Harness\DisposableMobileV2BackendHarness();
        $this->assertNull($harness->getSimulatedFaultStage());

        // Valid stages
        foreach (['after_draft', 'after_posting', 'after_payment', 'after_composite_completion'] as $stage) {
            $harness->configureSimulatedFaultStage($stage);
            $this->assertSame($stage, $harness->getSimulatedFaultStage());
        }

        // Reset to null
        $harness->configureSimulatedFaultStage(null);
        $this->assertNull($harness->getSimulatedFaultStage());

        // Invalid stage throws
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported simulated fault stage 'invalid_stage'.");
        $harness->configureSimulatedFaultStage('invalid_stage');
    }

    #[Test]
    public function test_advanced_probe_outer_validator_enforces_contract_version_and_scope(): void
    {
        $expectedPins = [
            'backendExecutionHead' => '15fe4f388aeaa41e51aff780287d484d51101f4c',
            'clientPublicationHead' => 'd43d2c200b4ccce1d7075aed759f51529375d307',
            'clientExecutableHead' => '82dc65a2d7431c59baa71e7c94c30ad6fd8a9ffd',
            'expectedPayloadDigest' => '11a4e579dc54cde0b7e0c8b2368299f85918d0b00619800ead6956e31762a5ac',
            'expectedSourcePins' => [
                'source_fixture_sha256' => str_repeat('1', 64),
                'guard_test_sha256' => str_repeat('2', 64),
                'cli_command_sha256' => str_repeat('3', 64),
                'cli_runner_sha256' => str_repeat('4', 64),
            ],
        ];

        // 1. Rejects null or empty expectedPins
        $resEmptyPins = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            ['contract_version' => 'v2_advanced_probe_evidence_v1'],
            []
        );
        $this->assertFalse($resEmptyPins['is_valid']);
        $this->assertTrue(collect($resEmptyPins['errors'])->contains(fn ($e) => str_contains($e, 'expectedPins array is required')));

        // 2. Missing / wrong contract version rejected
        $badVersion = [
            'contract_version' => 'wrong_version',
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
        ];
        $resVersion = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            $badVersion,
            $expectedPins
        );
        $this->assertFalse($resVersion['is_valid']);
        $this->assertTrue(collect($resVersion['errors'])->contains(fn ($e) => str_contains($e, "contract_version must be 'v2_advanced_probe_evidence_v1'")));

        // 3. Wrong scope rejected
        $badScope = [
            'contract_version' => 'v2_advanced_probe_evidence_v1',
            'scope' => 'ROOT_AUTHORIZED_PAIRED_EXECUTION',
        ];
        $resScope = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            $badScope,
            $expectedPins
        );
        $this->assertFalse($resScope['is_valid']);
        $this->assertTrue(collect($resScope['errors'])->contains(fn ($e) => str_contains($e, "scope must be 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION'")));
    }

    #[Test]
    public function test_advanced_probe_outer_validator_rejects_unverified_commit_or_truthy_strings(): void
    {
        $expectedPins = [
            'backendExecutionHead' => '15fe4f388aeaa41e51aff780287d484d51101f4c',
            'clientPublicationHead' => 'd43d2c200b4ccce1d7075aed759f51529375d307',
            'clientExecutableHead' => '82dc65a2d7431c59baa71e7c94c30ad6fd8a9ffd',
            'expectedPayloadDigest' => '11a4e579dc54cde0b7e0c8b2368299f85918d0b00619800ead6956e31762a5ac',
            'expectedSourcePins' => [
                'source_fixture_sha256' => str_repeat('1', 64),
                'guard_test_sha256' => str_repeat('2', 64),
                'cli_command_sha256' => str_repeat('3', 64),
                'cli_runner_sha256' => str_repeat('4', 64),
            ],
        ];

        $reconciliationsHealthy = [
            'stock_movement_matches_lines' => true,
            'invoice_balance_matches_payments' => true,
            'gl_batches_balanced' => true,
            'receipt_matches_intent' => true,
            'zero_stock_drift' => true,
            'document_sequence_continuous' => true,
        ];

        $makeValidProbes = fn () => [
            [
                'probe_name' => 'permission_denial',
                'client_event_id' => 'a1234567-888f-4890-90bf-1f05f416a19a',
                'expected_http_status' => 403,
                'actual_http_status' => 403,
                'economic_delta_zero' => true,
                'reconciliations' => $reconciliationsHealthy,
            ],
            [
                'probe_name' => 'insufficient_stock',
                'client_event_id' => 'b1234567-888f-4890-90bf-1f05f416a19a',
                'expected_http_status' => 422,
                'actual_http_status' => 422,
                'economic_delta_zero' => true,
                'reconciliations' => $reconciliationsHealthy,
            ],
            [
                'probe_name' => 'controlled_fault_after_posting',
                'client_event_id' => 'c1234567-888f-4890-90bf-1f05f416a19a',
                'expected_http_status' => 500,
                'actual_http_status' => 500,
                'economic_delta_zero' => true,
                'reconciliations' => $reconciliationsHealthy,
            ],
        ];

        $payload = [
            'contract_version' => 'v2_advanced_probe_evidence_v1',
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'backend_head' => '15fe4f388aeaa41e51aff780287d484d51101f4c',
            'android_publication_head' => 'd43d2c200b4ccce1d7075aed759f51529375d307',
            'android_executable_head' => '82dc65a2d7431c59baa71e7c94c30ad6fd8a9ffd',
            'source_pins' => [
                'source_fixture_sha256' => str_repeat('1', 64),
                'guard_test_sha256' => str_repeat('2', 64),
                'cli_command_sha256' => str_repeat('3', 64),
                'cli_runner_sha256' => str_repeat('4', 64),
            ],
            'scenarios' => [
                ['name' => 'readiness_probe', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'financial_cash_fresh_commit_201', 'status' => 'PASS', 'httpStatusCode' => 201],
                ['name' => 'financial_cash_idempotent_replay_200', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'financial_cash_event_status_posted', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'financial_cash_tamper_409', 'status' => 'PASS', 'httpStatusCode' => 409],
                ['name' => 'transport_lost_ack_recovery', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'stock_permission_rollback_probes', 'status' => 'PASS', 'httpStatusCode' => 403],
            ],
            'lost_ack_recovery_evidence' => [
                'suppression_mode' => 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP',
                'network_witness_state' => 'UNRUN_AWAITING_REAL_NETWORK_WITNESS',
                'target_client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                'server_commit_verified_before_suppression' => false, // UNVERIFIED! Must fail closed!
                'suppression_witness_file' => '/tmp/lost_ack_witness.json',
                'recovery_method' => 'RETAINED_EVENT_GET_STATUS',
                'recovery_http_status' => 200,
                'receipt_status' => 'posted',
                'matching_payload_digest' => '11a4e579dc54cde0b7e0c8b2368299f85918d0b00619800ead6956e31762a5ac',
                'sales_invoice_public_id' => '01M7GJCCHWVFF30PTHQYZDRYYC',
                'customer_payment_public_id' => '01M7GJCCPQTT2RXJGA499E66D3',
            ],
            'rollback_probes_evidence' => [
                'probes_count' => 3,
                'probes' => $makeValidProbes(),
            ],
        ];

        // 1. Unverified commit rejected
        $res = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            $payload,
            $expectedPins
        );
        $this->assertFalse($res['is_valid']);
        $this->assertTrue(collect($res['errors'])->contains(fn ($e) => str_contains($e, 'server_commit_verified_before_suppression must be boolean true')));

        // 2. Truthy string "true" in server_commit_verified_before_suppression strictly rejected
        $payloadTruthy = $payload;
        $payloadTruthy['lost_ack_recovery_evidence']['server_commit_verified_before_suppression'] = 'true';
        $resTruthy = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            $payloadTruthy,
            $expectedPins
        );
        $this->assertFalse($resTruthy['is_valid']);
        $this->assertTrue(collect($resTruthy['errors'])->contains(fn ($e) => str_contains($e, 'server_commit_verified_before_suppression must be boolean true')));

        // 3. Truthy string "true" in probe economic_delta_zero strictly rejected
        $payloadProbeTruthy = $payload;
        $payloadProbeTruthy['lost_ack_recovery_evidence']['server_commit_verified_before_suppression'] = true;
        $probesTruthy = $makeValidProbes();
        $probesTruthy[0]['economic_delta_zero'] = 'true'; // string "true"!
        $payloadProbeTruthy['rollback_probes_evidence']['probes'] = $probesTruthy;
        $resProbeTruthy = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            $payloadProbeTruthy,
            $expectedPins
        );
        $this->assertFalse($resProbeTruthy['is_valid']);
        $this->assertTrue(collect($resProbeTruthy['errors'])->contains(fn ($e) => str_contains($e, 'economic_delta_zero must be boolean true')));
    }

    #[Test]
    public function test_advanced_probe_outer_validator_rejects_rollback_probes_with_nonzero_delta_or_unhealthy_recon(): void
    {
        $expectedPins = [
            'backendExecutionHead' => '15fe4f388aeaa41e51aff780287d484d51101f4c',
            'clientPublicationHead' => 'd43d2c200b4ccce1d7075aed759f51529375d307',
            'clientExecutableHead' => '82dc65a2d7431c59baa71e7c94c30ad6fd8a9ffd',
            'expectedPayloadDigest' => '11a4e579dc54cde0b7e0c8b2368299f85918d0b00619800ead6956e31762a5ac',
            'expectedSourcePins' => [
                'source_fixture_sha256' => str_repeat('1', 64),
                'guard_test_sha256' => str_repeat('2', 64),
                'cli_command_sha256' => str_repeat('3', 64),
                'cli_runner_sha256' => str_repeat('4', 64),
            ],
        ];

        $reconciliationsHealthy = [
            'stock_movement_matches_lines' => true,
            'invoice_balance_matches_payments' => true,
            'gl_batches_balanced' => true,
            'receipt_matches_intent' => true,
            'zero_stock_drift' => true,
            'document_sequence_continuous' => true,
        ];

        // 1. Non-zero delta rejected
        $payloadBadDelta = [
            'contract_version' => 'v2_advanced_probe_evidence_v1',
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'backend_head' => '15fe4f388aeaa41e51aff780287d484d51101f4c',
            'android_publication_head' => 'd43d2c200b4ccce1d7075aed759f51529375d307',
            'android_executable_head' => '82dc65a2d7431c59baa71e7c94c30ad6fd8a9ffd',
            'source_pins' => [
                'source_fixture_sha256' => str_repeat('1', 64),
                'guard_test_sha256' => str_repeat('2', 64),
                'cli_command_sha256' => str_repeat('3', 64),
                'cli_runner_sha256' => str_repeat('4', 64),
            ],
            'scenarios' => [
                ['name' => 'readiness_probe', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'financial_cash_fresh_commit_201', 'status' => 'PASS', 'httpStatusCode' => 201],
                ['name' => 'financial_cash_idempotent_replay_200', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'financial_cash_event_status_posted', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'financial_cash_tamper_409', 'status' => 'PASS', 'httpStatusCode' => 409],
                ['name' => 'transport_lost_ack_recovery', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'stock_permission_rollback_probes', 'status' => 'PASS', 'httpStatusCode' => 403],
            ],
            'lost_ack_recovery_evidence' => [
                'suppression_mode' => 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP',
                'network_witness_state' => 'UNRUN_AWAITING_REAL_NETWORK_WITNESS',
                'target_client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                'server_commit_verified_before_suppression' => true,
                'suppression_witness_file' => '/tmp/lost_ack_witness.json',
                'recovery_method' => 'RETAINED_EVENT_GET_STATUS',
                'recovery_http_status' => 200,
                'receipt_status' => 'posted',
                'matching_payload_digest' => '11a4e579dc54cde0b7e0c8b2368299f85918d0b00619800ead6956e31762a5ac',
                'sales_invoice_public_id' => '01M7GJCCHWVFF30PTHQYZDRYYC',
                'customer_payment_public_id' => '01M7GJCCPQTT2RXJGA499E66D3',
            ],
            'rollback_probes_evidence' => [
                'probes_count' => 3,
                'probes' => [
                    [
                        'probe_name' => 'permission_denial',
                        'client_event_id' => 'a1234567-888f-4890-90bf-1f05f416a19a',
                        'expected_http_status' => 403,
                        'actual_http_status' => 403,
                        'economic_delta_zero' => true,
                        'reconciliations' => $reconciliationsHealthy,
                    ],
                    [
                        'probe_name' => 'insufficient_stock',
                        'client_event_id' => 'b1234567-888f-4890-90bf-1f05f416a19a',
                        'expected_http_status' => 422,
                        'actual_http_status' => 422,
                        'economic_delta_zero' => false, // FAILED: lingering leaked entities!
                        'reconciliations' => $reconciliationsHealthy,
                    ],
                    [
                        'probe_name' => 'controlled_fault_after_posting',
                        'client_event_id' => 'c1234567-888f-4890-90bf-1f05f416a19a',
                        'expected_http_status' => 500,
                        'actual_http_status' => 500,
                        'economic_delta_zero' => true,
                        'reconciliations' => $reconciliationsHealthy,
                    ],
                ],
            ],
        ];

        $resBadDelta = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            $payloadBadDelta,
            $expectedPins
        );
        $this->assertFalse($resBadDelta['is_valid']);
        $this->assertTrue(collect($resBadDelta['errors'])->contains(fn ($e) => str_contains($e, "Rollback probe 'insufficient_stock' economic_delta_zero must be boolean true")));

        // 2. Unhealthy reconciler rejected
        $payloadUnhealthy = $payloadBadDelta;
        $payloadUnhealthy['rollback_probes_evidence']['probes'][1]['economic_delta_zero'] = true;
        $payloadUnhealthy['rollback_probes_evidence']['probes'][1]['reconciliations']['zero_stock_drift'] = false; // Stock drift!

        $resUnhealthy = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            $payloadUnhealthy,
            $expectedPins
        );
        $this->assertFalse($resUnhealthy['is_valid']);
        $this->assertTrue(collect($resUnhealthy['errors'])->contains(fn ($e) => str_contains($e, "reconciliations 'zero_stock_drift' must be boolean true")));
    }

    #[Test]
    public function test_assert_rollback_zero_delta_detects_any_leaked_row_and_fails_closed(): void
    {
        // 1. Fails closed on empty array
        $resEmpty = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta([], []);
        $this->assertFalse($resEmpty['is_zero_delta']);
        $this->assertTrue(collect($resEmpty['errors'])->contains(fn ($e) => str_contains($e, 'metrics array cannot be empty')));

        $baseline = [
            'sales_invoices' => 10,
            'sales_invoice_lines' => 15,
            'sales_invoice_lot_allocations' => 15,
            'customer_payments' => 8,
            'customer_payment_allocations' => 8,
            'mobile_event_receipts' => 5,
            'stock_movements' => 20,
            'posting_batches' => 12,
            'posting_lines' => 36,
            'document_sequence_next_number' => 101,
            'inventory_balance_quantity_base' => '500.000000',
            'row_fingerprint_sha256' => str_repeat('c', 64),
        ];

        // 2. Identical counts -> zero delta PASS
        $resZero = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($baseline, $baseline);
        $this->assertTrue($resZero['is_zero_delta']);
        $this->assertSame([], $resZero['errors']);

        // 3. Leaked invoice -> FAIL
        $leakedInv = $baseline;
        $leakedInv['sales_invoices'] = 11;
        $resInv = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($baseline, $leakedInv);
        $this->assertFalse($resInv['is_zero_delta']);
        $this->assertTrue(collect($resInv['errors'])->contains(fn ($e) => str_contains($e, "Rollback entity 'sales_invoices' delta must be 0, got 1")));

        // 4. Leaked stock movement -> FAIL
        $leakedSm = $baseline;
        $leakedSm['stock_movements'] = 21;
        $resSm = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($baseline, $leakedSm);
        $this->assertFalse($resSm['is_zero_delta']);
        $this->assertTrue(collect($resSm['errors'])->contains(fn ($e) => str_contains($e, "Rollback entity 'stock_movements' delta must be 0, got 1")));

        // 5. Sequence leak -> FAIL
        $leakedSeq = $baseline;
        $leakedSeq['document_sequence_next_number'] = 102;
        $resSeq = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($baseline, $leakedSeq);
        $this->assertFalse($resSeq['is_zero_delta']);
        $this->assertTrue(collect($resSeq['errors'])->contains(fn ($e) => str_contains($e, 'Document sequence leaked')));

        // 6. Stock quantity mutation -> FAIL
        $mutatedStock = $baseline;
        $mutatedStock['inventory_balance_quantity_base'] = '499.000000';
        $resStock = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($baseline, $mutatedStock);
        $this->assertFalse($resStock['is_zero_delta']);
        $this->assertTrue(collect($resStock['errors'])->contains(fn ($e) => str_contains($e, 'Inventory stock quantity mutated')));

        // 7. Row fingerprint mismatch -> FAIL
        $mismatchedFp = $baseline;
        $mismatchedFp['row_fingerprint_sha256'] = str_repeat('d', 64);
        $resFp = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($baseline, $mismatchedFp);
        $this->assertFalse($resFp['is_zero_delta']);
        $this->assertTrue(collect($resFp['errors'])->contains(fn ($e) => str_contains($e, 'Row fingerprint mismatch')));

        // 8. Non-integer metric type -> FAIL
        $badType = $baseline;
        $badType['sales_invoices'] = '10'; // string instead of int
        $resType = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($baseline, $badType);
        $this->assertFalse($resType['is_zero_delta']);
        $this->assertTrue(collect($resType['errors'])->contains(fn ($e) => str_contains($e, "Post-probe metric 'sales_invoices' must be integer, got string.")));

        // 9. Unknown metric key -> FAIL
        $unknownKey = $baseline;
        $unknownKey['unauthorized_entity_counter'] = 0;
        $resUnknown = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($unknownKey, $baseline);
        $this->assertFalse($resUnknown['is_zero_delta']);
        $this->assertTrue(collect($resUnknown['errors'])->contains(fn ($e) => str_contains($e, "Unauthorized metric key 'unauthorized_entity_counter' in pre-probe baseline.")));

        // 10. Non-lowercase-64-hex row fingerprint -> FAIL
        $badFingerprint = $baseline;
        $badFingerprint['row_fingerprint_sha256'] = 'INVALID_FINGERPRINT_NOT_64_HEX';
        $resFpFormat = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($badFingerprint, $badFingerprint);
        $this->assertFalse($resFpFormat['is_zero_delta']);
        $this->assertTrue(collect($resFpFormat['errors'])->contains(fn ($e) => str_contains($e, "Pre-probe row_fingerprint_sha256 must be a 64-character lowercase hex string")));

        // 11. Negative metric value -> FAIL
        $negativeCount = $baseline;
        $negativeCount['sales_invoices'] = -1;
        $resNegative = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($negativeCount, $baseline);
        $this->assertFalse($resNegative['is_zero_delta']);
        $this->assertTrue(collect($resNegative['errors'])->contains(fn ($e) => str_contains($e, "Pre-probe metric 'sales_invoices' must be a non-negative integer, got -1.")));
    }

    #[Test]
    public function test_advanced_probe_outer_validator_strictly_refuses_unrun_witness_on_pass_and_enforces_probe_constraints(): void
    {
        $expectedPins = [
            'backendExecutionHead' => '15fe4f388aeaa41e51aff780287d484d51101f4c',
            'clientPublicationHead' => 'd43d2c200b4ccce1d7075aed759f51529375d307',
            'clientExecutableHead' => '82dc65a2d7431c59baa71e7c94c30ad6fd8a9ffd',
            'expectedPayloadDigest' => '11a4e579dc54cde0b7e0c8b2368299f85918d0b00619800ead6956e31762a5ac',
            'expectedSourcePins' => [
                'source_fixture_sha256' => str_repeat('1', 64),
                'guard_test_sha256' => str_repeat('2', 64),
                'cli_command_sha256' => str_repeat('3', 64),
                'cli_runner_sha256' => str_repeat('4', 64),
            ],
        ];

        $reconciliationsHealthy = [
            'stock_movement_matches_lines' => true,
            'invoice_balance_matches_payments' => true,
            'gl_batches_balanced' => true,
            'receipt_matches_intent' => true,
            'zero_stock_drift' => true,
            'document_sequence_continuous' => true,
        ];

        $makeProbes = fn () => [
            [
                'probe_name' => 'permission_denial',
                'client_event_id' => 'a1234567-888f-4890-90bf-1f05f416a19a',
                'expected_http_status' => 403,
                'actual_http_status' => 403,
                'economic_delta_zero' => true,
                'reconciliations' => $reconciliationsHealthy,
            ],
            [
                'probe_name' => 'insufficient_stock',
                'client_event_id' => 'b1234567-888f-4890-90bf-1f05f416a19a',
                'expected_http_status' => 422,
                'actual_http_status' => 422,
                'economic_delta_zero' => true,
                'reconciliations' => $reconciliationsHealthy,
            ],
            [
                'probe_name' => 'controlled_fault_after_posting',
                'client_event_id' => 'c1234567-888f-4890-90bf-1f05f416a19a',
                'expected_http_status' => 500,
                'actual_http_status' => 500,
                'economic_delta_zero' => true,
                'reconciliations' => $reconciliationsHealthy,
            ],
        ];

        $basePayload = [
            'contract_version' => 'v2_advanced_probe_evidence_v1',
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'backend_head' => '15fe4f388aeaa41e51aff780287d484d51101f4c',
            'android_publication_head' => 'd43d2c200b4ccce1d7075aed759f51529375d307',
            'android_executable_head' => '82dc65a2d7431c59baa71e7c94c30ad6fd8a9ffd',
            'source_pins' => [
                'source_fixture_sha256' => str_repeat('1', 64),
                'guard_test_sha256' => str_repeat('2', 64),
                'cli_command_sha256' => str_repeat('3', 64),
                'cli_runner_sha256' => str_repeat('4', 64),
            ],
            'scenarios' => [
                ['name' => 'readiness_probe', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'financial_cash_fresh_commit_201', 'status' => 'PASS', 'httpStatusCode' => 201],
                ['name' => 'financial_cash_idempotent_replay_200', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'financial_cash_event_status_posted', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'financial_cash_tamper_409', 'status' => 'PASS', 'httpStatusCode' => 409],
                ['name' => 'transport_lost_ack_recovery', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'stock_permission_rollback_probes', 'status' => 'PASS', 'httpStatusCode' => 403],
            ],
            'lost_ack_recovery_evidence' => [
                'suppression_mode' => 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP',
                'network_witness_state' => 'UNRUN_AWAITING_REAL_NETWORK_WITNESS',
                'target_client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                'server_commit_verified_before_suppression' => true,
                'suppression_witness_file' => '/tmp/lost_ack_witness.json',
                'recovery_method' => 'RETAINED_EVENT_GET_STATUS',
                'recovery_http_status' => 200,
                'receipt_status' => 'posted',
                'matching_payload_digest' => '11a4e579dc54cde0b7e0c8b2368299f85918d0b00619800ead6956e31762a5ac',
                'sales_invoice_public_id' => '01M7GJCCHWVFF30PTHQYZDRYYC',
                'customer_payment_public_id' => '01M7GJCCPQTT2RXJGA499E66D3',
            ],
            'rollback_probes_evidence' => [
                'probes_count' => 3,
                'probes' => $makeProbes(),
            ],
        ];

        // 1. Refuses UNRUN_AWAITING_REAL_NETWORK_WITNESS when transport_lost_ack_recovery is PASS
        $resUnrun = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            $basePayload,
            $expectedPins
        );
        $this->assertFalse($resUnrun['is_valid']);
        $this->assertTrue(collect($resUnrun['errors'])->contains(
            fn ($e) => str_contains($e, "transport_lost_ack_recovery claims 'PASS' but network_witness_state is not 'CAPTURED_REAL_TRANSPORT_DROP'")
        ));

        // 2. Reject duplicate probe client_event_id matching main target event id
        $payloadDupTarget = $basePayload;
        $probesDupTarget = $makeProbes();
        $probesDupTarget[0]['client_event_id'] = 'c0686be5-888f-4890-90bf-1f05f416a19a'; // Matches lost ACK target!
        $payloadDupTarget['rollback_probes_evidence']['probes'] = $probesDupTarget;
        $resDupTarget = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            $payloadDupTarget,
            $expectedPins
        );
        $this->assertFalse($resDupTarget['is_valid']);
        $this->assertTrue(collect($resDupTarget['errors'])->contains(
            fn ($e) => str_contains($e, "matches primary lost ACK target_client_event_id")
        ));

        // 3. Reject duplicate probe names
        $payloadDupName = $basePayload;
        $probesDupName = $makeProbes();
        $probesDupName[1]['probe_name'] = 'permission_denial'; // Duplicate probe name!
        $payloadDupName['rollback_probes_evidence']['probes'] = $probesDupName;
        $resDupName = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            $payloadDupName,
            $expectedPins
        );
        $this->assertFalse($resDupName['is_valid']);
        $this->assertTrue(collect($resDupName['errors'])->contains(
            fn ($e) => str_contains($e, "Duplicate rollback probe_name 'permission_denial'")
        ));
    }

    #[Test]
    public function test_basic_outer_validator_strictly_rejects_advanced_probes_claiming_pass(): void
    {
        $goldenData = $this->getGoldenWireFixtureData();

        // Alter probe 6 to claim PASS in basic fixture
        $tamperedData = $goldenData;
        $tamperedData['scenarios'][5]['status'] = 'PASS';
        $tamperedData['scenarios'][5]['httpStatusCode'] = 200;

        $fixture = $this->createSyntheticTrustedDbReportFixture();
        $res = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson(
            $tamperedData,
            $fixture['expectedPins'],
            $fixture['report']
        );
        $this->assertFalse($res['is_valid']);
        $this->assertTrue(collect($res['errors'])->contains(fn ($e) => str_contains($e, "cannot claim 'PASS' in basic financial run; must be exactly 'NOT_RUN'")));
    }

    #[Test]
    public function test_test_transport_proxy_is_target_request_matches_exact_ulid_and_rejects_digit_or_unanchored_path(): void
    {
        $targetEventId = 'c0686be5-888f-4890-90bf-1f05f416a19a';
        $targetCompanyUlid = '01M7CZPF6TP283F3ZRXF5P8GT2';

        $proxy = new \Tests\Support\Harness\TestTransportProxy([
            'target_event_id' => $targetEventId,
            'target_schema' => 'accounting_p8_tmp_0123456789ab',
            'target_company_id' => 1,
            'target_company_public_id' => $targetCompanyUlid,
            'expected_digest' => str_repeat('a', 64),
            'target_nonce_hash' => str_repeat('b', 64),
            'witness_path' => '/tmp/lost_ack_suppression_witness.json',
            'owner_pid' => getmypid(),
            'ttl_expires_at' => (new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ]);

        $validBody = json_encode(['client_event_id' => $targetEventId, 'amount' => '58.000000']);
        $validPath = "/api/mobile/v2/companies/{$targetCompanyUlid}/sales/online-cash";

        // 1. Exact match PASS
        $this->assertTrue($proxy->isTargetRequest('POST', $validPath, $validBody));

        // 2. Digit-only unanchored path FAILS
        $digitPath = "/api/mobile/v2/companies/1/sales/online-cash";
        $this->assertFalse($proxy->isTargetRequest('POST', $digitPath, $validBody));

        // 3. Unanchored trailing slash or prefix FAILS
        $prefixPath = "/v1{$validPath}";
        $this->assertFalse($proxy->isTargetRequest('POST', $prefixPath, $validBody));
        $suffixPath = "{$validPath}/extra";
        $this->assertFalse($proxy->isTargetRequest('POST', $suffixPath, $validBody));

        // 4. Mismatched company ULID FAILS
        $wrongCompanyPath = "/api/mobile/v2/companies/01M7CZPF6TP283F3ZRXF5P8GT9/sales/online-cash";
        $this->assertFalse($proxy->isTargetRequest('POST', $wrongCompanyPath, $validBody));

        // 5. Non-POST method FAILS
        $this->assertFalse($proxy->isTargetRequest('GET', $validPath, $validBody));
        $this->assertFalse($proxy->isTargetRequest('PUT', $validPath, $validBody));

        // 6. Mismatched client_event_id FAILS
        $wrongEventBody = json_encode(['client_event_id' => 'd0686be5-888f-4890-90bf-1f05f416a19a']);
        $this->assertFalse($proxy->isTargetRequest('POST', $validPath, $wrongEventBody));

        // 7. Malformed JSON body FAILS
        $this->assertFalse($proxy->isTargetRequest('POST', $validPath, 'not-json'));
    }

    #[Test]
    public function test_test_transport_proxy_constructor_refuses_arbitrary_ports_and_hosts(): void
    {
        $baseConfig = [
            'target_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'target_schema' => 'accounting_p8_tmp_0123456789ab',
            'target_company_id' => 1,
            'target_company_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'expected_digest' => str_repeat('a', 64),
            'target_nonce_hash' => str_repeat('b', 64),
            'witness_path' => '/tmp/lost_ack_suppression_witness.json',
            'owner_pid' => getmypid(),
            'ttl_expires_at' => (new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ];

        // Arbitrary listen port rejected
        try {
            new \Tests\Support\Harness\TestTransportProxy(array_merge($baseConfig, ['listen_port' => 8080]));
            $this->fail('Expected InvalidArgumentException for non-fixed listen_port');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('listen_port must be fixed to 18080', $e->getMessage());
        }

        // Arbitrary upstream port rejected
        try {
            new \Tests\Support\Harness\TestTransportProxy(array_merge($baseConfig, ['upstream_port' => 8081]));
            $this->fail('Expected InvalidArgumentException for non-fixed upstream_port');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('upstream_port must be fixed to 18081', $e->getMessage());
        }

        // Arbitrary db host rejected
        try {
            new \Tests\Support\Harness\TestTransportProxy(array_merge($baseConfig, ['db_host' => '10.0.0.1']));
            $this->fail('Expected InvalidArgumentException for non-fixed db_host');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("db_host must be fixed to '127.0.0.1'", $e->getMessage());
        }

        // Arbitrary db port rejected
        try {
            new \Tests\Support\Harness\TestTransportProxy(array_merge($baseConfig, ['db_port' => 3306]));
            $this->fail('Expected InvalidArgumentException for non-fixed db_port');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('db_port must be fixed to 3342', $e->getMessage());
        }
    }

    #[Test]
    public function test_test_transport_proxy_constructor_refuses_missing_or_dead_owner_pid(): void
    {
        $baseConfig = [
            'target_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'target_schema' => 'accounting_p8_tmp_0123456789ab',
            'target_company_id' => 1,
            'target_company_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'expected_digest' => str_repeat('a', 64),
            'target_nonce_hash' => str_repeat('b', 64),
            'witness_path' => '/tmp/lost_ack_suppression_witness.json',
            'ttl_expires_at' => (new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ];

        // Missing owner_pid rejected
        try {
            new \Tests\Support\Harness\TestTransportProxy($baseConfig);
            $this->fail('Expected InvalidArgumentException for missing owner_pid');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('owner_pid is mandatory and must be a positive integer', $e->getMessage());
        }

        // Negative owner_pid rejected
        try {
            new \Tests\Support\Harness\TestTransportProxy(array_merge($baseConfig, ['owner_pid' => -1]));
            $this->fail('Expected InvalidArgumentException for negative owner_pid');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('owner_pid is mandatory and must be a positive integer', $e->getMessage());
        }
    }

    #[Test]
    public function test_test_transport_proxy_constructor_refuses_missing_or_invalid_digest_and_nonce_hash(): void
    {
        $baseConfig = [
            'target_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'target_schema' => 'accounting_p8_tmp_0123456789ab',
            'target_company_id' => 1,
            'target_company_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'witness_path' => '/tmp/lost_ack_suppression_witness.json',
            'owner_pid' => getmypid(),
            'ttl_expires_at' => (new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ];

        // Missing expected_digest rejected
        try {
            new \Tests\Support\Harness\TestTransportProxy(array_merge($baseConfig, [
                'target_nonce_hash' => str_repeat('b', 64),
            ]));
            $this->fail('Expected InvalidArgumentException for missing expected_digest');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('expected_digest is mandatory', $e->getMessage());
        }

        // Short expected_digest rejected
        try {
            new \Tests\Support\Harness\TestTransportProxy(array_merge($baseConfig, [
                'expected_digest' => 'not64hex',
                'target_nonce_hash' => str_repeat('b', 64),
            ]));
            $this->fail('Expected InvalidArgumentException for short expected_digest');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('expected_digest is mandatory and must be a 64-hex lowercase SHA-256 string', $e->getMessage());
        }

        // Missing target_nonce_hash rejected
        try {
            new \Tests\Support\Harness\TestTransportProxy(array_merge($baseConfig, [
                'expected_digest' => str_repeat('a', 64),
            ]));
            $this->fail('Expected InvalidArgumentException for missing target_nonce_hash');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('target_nonce_hash is mandatory', $e->getMessage());
        }
    }

    #[Test]
    public function test_test_transport_proxy_cli_refuses_invocation_without_config(): void
    {
        $cliScript = dirname(__DIR__, 3) . '/tests/Support/Harness/test-transport-proxy-cli.php';
        $proc = new \Symfony\Component\Process\Process([PHP_BINARY, $cliScript]);
        $proc->run();

        $this->assertFalse($proc->isSuccessful());
        $this->assertSame(1, $proc->getExitCode());
        $this->assertStringContainsString('Missing mandatory --config=<path>', $proc->getErrorOutput());
    }

    #[Test]
    public function test_trusted_db_report_canonical_digest_matches_actual_app_serializer(): void
    {
        $payload = [
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'run_id' => '019ea456-0000-7000-8000-000000000001',
            'event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
            'company_id' => 1,
            'source_head' => '15fe4f388aeaa41e51aff780287d484d51101f4c',
            'handoff_digest' => str_repeat('a', 64),
            'baseline_hash' => str_repeat('b', 64),
            'deltas' => ['sales_invoices' => 1],
            'canonical_graph' => ['sales_invoice' => ['id' => 1, 'unit_price' => '50.000000']],
            'is_canonical_delta_valid' => true,
            'gl_batches_balanced' => true,
            'all_healthy' => true,
            'reconciler_results' => ['gl_batches_balanced' => true],
        ];

        $digest = \Tests\Support\Harness\DisposableFinancialPostRunInspector::computeReportDigest($payload);
        $expectedCanonical = \App\Support\Mobile\Contract\CanonicalJsonSerializer::serialize($payload);
        $expectedHash = hash('sha256', $expectedCanonical);

        $this->assertSame($expectedHash, $digest);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
    }

    #[Test]
    public function test_witness_artifact_contract_alignment_and_strict_validation(): void
    {
        $tmpDir = sys_get_temp_dir() . '/guard_witness_test_' . bin2hex(random_bytes(6));
        mkdir($tmpDir, 0700, true);
        $witnessPath = $tmpDir . '/lost_ack_suppression_witness.json';

        $witnessData = [
            'contract_version' => 'lost_ack_witness_v1',
            'suppression_mode' => 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP',
            'network_witness_state' => 'CAPTURED_REAL_TRANSPORT_DROP',
            'timestamp_iso8601' => gmdate('Y-m-d\TH:i:s\Z'),
            'event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
            'company_id' => 1,
            'company_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'target_nonce_hash' => str_repeat('b', 64),
            'response_status_code_before_suppression' => 201,
            'db_commit_verified' => true,
            'proxy_socket_closed_without_response' => true,
            'client_socket_dropped' => true,
            'bytes_forwarded' => 0,
            'sales_invoice_id' => 10,
            'customer_payment_id' => 20,
            'payload_digest' => str_repeat('c', 64),
        ];

        file_put_contents($witnessPath, json_encode($witnessData, JSON_PRETTY_PRINT));
        chmod($witnessPath, 0600);

        try {
            $evidenceJson = [
                'contract_version' => 'v2_advanced_probe_evidence_v1',
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'run_id' => '019ea456-0000-7000-8000-000000000001',
                'client_event_id' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                'schema_name' => 'accounting_p8_tmp_0123456789ab',
                'handoff_sha256' => str_repeat('a', 64),
                'backend_head' => '15fe4f388aeaa41e51aff780287d484d51101f4c',
                'android_publication_head' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
                'android_executable_head' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
                'source_pins' => [
                    'source_fixture_sha256' => str_repeat('d', 64),
                    'guard_test_sha256' => str_repeat('e', 64),
                    'cli_command_sha256' => str_repeat('f', 64),
                    'cli_runner_sha256' => str_repeat('0', 64),
                ],
                'scenarios' => [
                    ['name' => 'readiness_probe', 'status' => 'PASS', 'httpStatusCode' => 200],
                    ['name' => 'financial_cash_fresh_commit_201', 'status' => 'PASS', 'httpStatusCode' => 201],
                    ['name' => 'financial_cash_idempotent_replay_200', 'status' => 'PASS', 'httpStatusCode' => 200],
                    ['name' => 'financial_cash_event_status_posted', 'status' => 'PASS', 'httpStatusCode' => 200],
                    ['name' => 'financial_cash_tamper_409', 'status' => 'PASS', 'httpStatusCode' => 409],
                    ['name' => 'transport_lost_ack_recovery', 'status' => 'PASS', 'httpStatusCode' => 200],
                    ['name' => 'stock_permission_rollback_probes', 'status' => 'PASS', 'httpStatusCode' => 403],
                ],
                'lost_ack_recovery_evidence' => [
                    'suppression_mode' => 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP',
                    'network_witness_state' => 'CAPTURED_REAL_TRANSPORT_DROP',
                    'suppression_witness_file' => $witnessPath,
                    'server_commit_verified_before_suppression' => true,
                    'recovery_method' => 'RETAINED_EVENT_GET_STATUS',
                    'recovery_http_status' => 200,
                    'receipt_status' => 'posted',
                    'matching_payload_digest' => str_repeat('c', 64),
                ],
                'rollback_probes_evidence' => [
                    'probes' => [
                        [
                            'probe_name' => 'permission_denial',
                            'client_event_id' => '00000000-0000-0000-0000-000000000001',
                            'actual_http_status' => 403,
                            'expected_http_status' => 403,
                            'economic_delta_zero' => true,
                            'reconciliations_healthy' => true,
                            'issue_count' => 0,
                        ],
                        [
                            'probe_name' => 'insufficient_stock',
                            'client_event_id' => '00000000-0000-0000-0000-000000000002',
                            'actual_http_status' => 422,
                            'expected_http_status' => 422,
                            'economic_delta_zero' => true,
                            'reconciliations_healthy' => true,
                            'issue_count' => 0,
                        ],
                        [
                            'probe_name' => 'controlled_fault_after_posting',
                            'client_event_id' => '00000000-0000-0000-0000-000000000003',
                            'actual_http_status' => 500,
                            'expected_http_status' => 500,
                            'economic_delta_zero' => true,
                            'reconciliations_healthy' => true,
                            'issue_count' => 0,
                        ],
                    ],
                ],
            ];

            $expectedPins = [
                'expectedRunId' => '019ea456-0000-7000-8000-000000000001',
                'expectedClientEventId' => 'c0686be5-888f-4890-90bf-1f05f416a19a',
                'expectedSchemaName' => 'accounting_p8_tmp_0123456789ab',
                'expectedHandoffSha' => str_repeat('a', 64),
                'backendExecutionHead' => '15fe4f388aeaa41e51aff780287d484d51101f4c',
                'clientPublicationHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
                'clientExecutableHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
                'expectedPayloadDigest' => str_repeat('c', 64),
                'expectedSourcePins' => [
                    'source_fixture_sha256' => str_repeat('d', 64),
                    'guard_test_sha256' => str_repeat('e', 64),
                    'cli_command_sha256' => str_repeat('f', 64),
                    'cli_runner_sha256' => str_repeat('0', 64),
                ],
            ];

            $res = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson($evidenceJson, $expectedPins);
            $this->assertTrue($res['is_valid'], 'Expected valid evidence with aligned witness file. Errors: ' . implode('; ', $res['errors']));
        } finally {
            @unlink($witnessPath);
            @rmdir($tmpDir);
        }
    }

    /**
     * Executes a callback within a scoped mock disposable DB proof environment.
     *
     * @template T
     * @param  string  $schemaName
     * @param  callable(string $proofFile, string $nonce): T  $fn
     * @return T
     */
    private function withScopedDisposableDbProof(string $schemaName, callable $fn): mixed
    {
        $proofDir = sys_get_temp_dir() . '/p8_proof_' . bin2hex(random_bytes(8));
        mkdir($proofDir, 0700, true);
        $proofFile = $proofDir . '/proof.json';
        $nonce = str_repeat('a', 32);

        $proofData = [
            'schema' => $schemaName,
            'host' => '127.0.0.1',
            'port' => '3342',
            'nonce' => $nonce,
        ];
        file_put_contents($proofFile, json_encode($proofData, JSON_THROW_ON_ERROR));
        chmod($proofFile, 0600);

        $savedEnv = [
            'APP_ENV' => getenv('APP_ENV'),
            'PHASE8_ALLOW_DISPOSABLE_DB' => getenv('PHASE8_ALLOW_DISPOSABLE_DB'),
            'PHASE8_TEST_DB_HOST' => getenv('PHASE8_TEST_DB_HOST'),
            'PHASE8_TEST_DB_PORT' => getenv('PHASE8_TEST_DB_PORT'),
            'PHASE8_TEST_DB_USERNAME' => getenv('PHASE8_TEST_DB_USERNAME'),
            'PHASE8_TEST_DB_PASSWORD' => getenv('PHASE8_TEST_DB_PASSWORD'),
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => getenv('PHASE8_TEST_DB_EMPTY_PASSWORD'),
            'PHASE8_DISPOSABLE_DB_PROOF' => getenv('PHASE8_DISPOSABLE_DB_PROOF'),
            'PHASE8_DISPOSABLE_DB_NONCE' => getenv('PHASE8_DISPOSABLE_DB_NONCE'),
        ];

        try {
            putenv('APP_ENV=testing');
            putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
            putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
            putenv('PHASE8_TEST_DB_PORT=3342');
            putenv('PHASE8_TEST_DB_USERNAME=root');
            putenv('PHASE8_TEST_DB_PASSWORD=');
            putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');
            putenv("PHASE8_DISPOSABLE_DB_PROOF={$proofFile}");
            putenv("PHASE8_DISPOSABLE_DB_NONCE={$nonce}");

            return $fn($proofFile, $nonce);
        } finally {
            if (file_exists($proofFile)) {
                @unlink($proofFile);
            }
            if (is_dir($proofDir)) {
                @rmdir($proofDir);
            }
            foreach ($savedEnv as $k => $v) {
                if ($v === false) {
                    putenv($k);
                } else {
                    putenv("{$k}={$v}");
                }
            }
        }
    }

    #[Test]
    public function test_capture_rollback_probe_snapshot_rejects_non_positive_company_id(): void
    {
        $pdo = (new \ReflectionClass(\PDO::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('captureRollbackProbeSnapshot requires positive companyId');

        \Tests\Support\Harness\DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot($pdo, 0, 'accounting_p8_tmp_0123456789ab');
    }

    #[Test]
    public function test_capture_rollback_probe_snapshot_rejects_non_disposable_schema(): void
    {
        $pdo = (new \ReflectionClass(\PDO::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('captureRollbackProbeSnapshot requires valid disposable expectedSchemaName');

        \Tests\Support\Harness\DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot($pdo, 1, 'production_main');
    }

    #[Test]
    public function test_capture_rollback_probe_snapshot_rejects_already_in_transaction(): void
    {
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $this->withScopedDisposableDbProof($schemaName, function () use ($schemaName) {
            $pdo = $this->createMock(\PDO::class);
            $pdo->method('inTransaction')->willReturn(true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('PDO connection is already in transaction before captureRollbackProbeSnapshot');

            \Tests\Support\Harness\DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot($pdo, 1, $schemaName);
        });
    }

    #[Test]
    public function test_capture_rollback_probe_snapshot_propagates_rollback_failure(): void
    {
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $this->withScopedDisposableDbProof($schemaName, function () use ($schemaName) {
            $gateStmt = $this->createMock(\PDOStatement::class);
            $gateStmt->method('fetch')->willReturn(['db' => $schemaName, 'port' => '3342']);

            $pdo = $this->createMock(\PDO::class);
            $pdo->method('inTransaction')->willReturn(false);
            $pdo->method('query')->willReturn($gateStmt);
            $pdo->method('exec')->willReturnCallback(function (string $sql) {
                if ($sql === 'ROLLBACK') {
                    throw new \PDOException('ROLLBACK failed on database connection');
                }

                return 1;
            });
            $pdo->method('prepare')->willThrowException(new \RuntimeException('Query failed in try'));

            $this->expectException(\PDOException::class);
            $this->expectExceptionMessage('ROLLBACK failed on database connection');

            \Tests\Support\Harness\DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot($pdo, 1, $schemaName);
        });
    }

    #[Test]
    public function test_capture_rollback_probe_snapshot_rejects_wrong_port(): void
    {
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $this->withScopedDisposableDbProof($schemaName, function () use ($schemaName) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(['db' => $schemaName, 'port' => '3306']);

            $pdo = $this->createMock(\PDO::class);
            $pdo->method('inTransaction')->willReturn(false);
            $pdo->method('query')->willReturn($stmt);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('PDO port mismatch: expected port 3342');

            \Tests\Support\Harness\DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot($pdo, 1, $schemaName);
        });
    }

    #[Test]
    public function test_capture_rollback_probe_snapshot_propagates_sql_failures(): void
    {
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $this->withScopedDisposableDbProof($schemaName, function () use ($schemaName) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(['db' => $schemaName, 'port' => '3342']);

            $pdo = $this->createMock(\PDO::class);
            $pdo->method('inTransaction')->willReturn(false);
            $pdo->method('query')->willReturn($stmt);
            $pdo->method('exec')->willReturn(1);
            $pdo->method('prepare')->willThrowException(new \PDOException("Table 'sales_invoices' does not exist", 1146));

            $this->expectException(\PDOException::class);
            $this->expectExceptionMessage("Table 'sales_invoices' does not exist");

            \Tests\Support\Harness\DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot($pdo, 1, $schemaName);
        });
    }

    #[Test]
    public function test_assert_rollback_zero_delta_detects_changed_row_with_equal_counts_and_max_id(): void
    {
        $tables = [
            'sales_invoices',
            'sales_invoice_lines',
            'sales_invoice_lot_allocations',
            'customer_payments',
            'customer_payment_allocations',
            'mobile_event_receipts',
            'stock_movements',
            'posting_batches',
            'posting_lines',
        ];

        $baseCounts = [];
        $baseFingerprints = [];
        foreach ($tables as $t) {
            $baseCounts[$t] = ($t === 'sales_invoices') ? 1 : 0;
            $baseFingerprints[$t] = hash('sha256', "original_{$t}");
        }

        $baseline = array_merge($baseCounts, [
            'document_sequences' => ['sales_invoice:2026' => 1],
            'table_fingerprints' => $baseFingerprints,
            'stock_movements_qty_delta_base' => '10.000000',
            'lot_allocations_qty_allocated_base' => '0.000000',
            'row_fingerprint_sha256' => hash('sha256', 'baseline_fingerprint_data'),
        ]);

        $postFingerprints = $baseFingerprints;
        // Mutate one row in sales_invoices table fingerprint while count remains identical (1)
        $postFingerprints['sales_invoices'] = hash('sha256', 'mutated_sales_invoices');

        $postProbe = array_merge($baseCounts, [
            'document_sequences' => ['sales_invoice:2026' => 1],
            'table_fingerprints' => $postFingerprints,
            'stock_movements_qty_delta_base' => '10.000000',
            'lot_allocations_qty_allocated_base' => '0.000000',
            'row_fingerprint_sha256' => hash('sha256', 'post_fingerprint_data'),
        ]);

        $res = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($baseline, $postProbe);

        $this->assertFalse($res['is_zero_delta']);
        $errorStr = implode('; ', $res['errors']);
        $this->assertStringContainsString("Table 'sales_invoices' row fingerprint mismatch", $errorStr);
        $this->assertStringContainsString('Row fingerprint mismatch', $errorStr);
    }

    #[Test]
    public function test_assert_rollback_zero_delta_detects_sequence_map_mutation(): void
    {
        $tables = [
            'sales_invoices',
            'sales_invoice_lines',
            'sales_invoice_lot_allocations',
            'customer_payments',
            'customer_payment_allocations',
            'mobile_event_receipts',
            'stock_movements',
            'posting_batches',
            'posting_lines',
        ];

        $counts = array_fill_keys($tables, 0);
        $fps = array_fill_keys($tables, hash('sha256', 'empty'));

        $baseline = array_merge($counts, [
            'document_sequences' => ['sales_invoice:2026' => 1],
            'table_fingerprints' => $fps,
            'stock_movements_qty_delta_base' => '0.000000',
            'lot_allocations_qty_allocated_base' => '0.000000',
            'row_fingerprint_sha256' => hash('sha256', 'seq_test'),
        ]);

        $postProbe = $baseline;
        $postProbe['document_sequences'] = ['sales_invoice:2026' => 2];

        $res = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($baseline, $postProbe);
        $this->assertFalse($res['is_zero_delta']);
        $this->assertStringContainsString('Document sequences map mutated', implode('; ', $res['errors']));
    }

    #[Test]
    public function test_assert_rollback_zero_delta_detects_stock_decimal_string_mutation(): void
    {
        $tables = [
            'sales_invoices',
            'sales_invoice_lines',
            'sales_invoice_lot_allocations',
            'customer_payments',
            'customer_payment_allocations',
            'mobile_event_receipts',
            'stock_movements',
            'posting_batches',
            'posting_lines',
        ];

        $counts = array_fill_keys($tables, 0);
        $fps = array_fill_keys($tables, hash('sha256', 'empty'));

        $baseline = array_merge($counts, [
            'document_sequences' => [],
            'table_fingerprints' => $fps,
            'stock_movements_qty_delta_base' => '50.000000',
            'lot_allocations_qty_allocated_base' => '0.000000',
            'row_fingerprint_sha256' => hash('sha256', 'stock_test'),
        ]);

        $postProbe = $baseline;
        $postProbe['stock_movements_qty_delta_base'] = '50.000001';

        $res = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($baseline, $postProbe);
        $this->assertFalse($res['is_zero_delta']);
        $this->assertStringContainsString('Stock movements quantity delta sum mutated', implode('; ', $res['errors']));
    }

    #[Test]
    public function test_proxy_cli_entry_refuses_when_root_prestart_path_missing(): void
    {
        $tmpDir = sys_get_temp_dir() . '/guard_proxy_entry_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);
        $configFile = $tmpDir . '/proxy_config.json';
        $credsFile = $tmpDir . '/creds.json';

        try {
            file_put_contents($credsFile, json_encode(['db_user' => 'u', 'db_password' => 'p']));
            chmod($credsFile, 0600);

            file_put_contents($configFile, json_encode([
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'owner_pid' => getmypid(),
                'ttl_expires_at' => (new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'credentials_file' => $credsFile,
            ]));
            chmod($configFile, 0600);

            $cliPath = dirname(__DIR__, 3) . '/Support/Harness/test-transport-proxy-cli.php';
            $proc = new Process([PHP_BINARY, $cliPath, '--config=' . $configFile]);
            $proc->run();

            $this->assertSame(1, $proc->getExitCode());
            $this->assertStringContainsString('Config missing mandatory root_prestart_path', $proc->getErrorOutput());
        } finally {
            @unlink($configFile);
            @unlink($credsFile);
            @rmdir($tmpDir);
        }
    }

    #[Test]
    public function test_proxy_cli_entry_refuses_when_root_prestart_not_granted(): void
    {
        $tmpDir = sys_get_temp_dir() . '/guard_proxy_grant_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);
        $configFile = $tmpDir . '/proxy_config.json';
        $credsFile = $tmpDir . '/creds.json';
        $approvalFile = $tmpDir . '/approval.json';

        try {
            file_put_contents($credsFile, json_encode(['db_user' => 'u', 'db_password' => 'p']));
            chmod($credsFile, 0600);

            file_put_contents($approvalFile, json_encode([
                'root_coordination_granted' => false,
                'expires_at' => (new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ]));
            chmod($approvalFile, 0600);

            file_put_contents($configFile, json_encode([
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'owner_pid' => getmypid(),
                'ttl_expires_at' => (new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'credentials_file' => $credsFile,
                'root_prestart_path' => $approvalFile,
                'manifest_sha256' => str_repeat('a', 64),
                'backend_head' => str_repeat('b', 40),
            ]));
            chmod($configFile, 0600);

            $cliPath = dirname(__DIR__, 3) . '/Support/Harness/test-transport-proxy-cli.php';
            $proc = new Process([PHP_BINARY, $cliPath, '--config=' . $configFile]);
            $proc->run();

            $this->assertSame(1, $proc->getExitCode());
            $this->assertStringContainsString('Root prestart approval verification failed', $proc->getErrorOutput());
        } finally {
            @unlink($approvalFile);
            @unlink($configFile);
            @unlink($credsFile);
            @rmdir($tmpDir);
        }
    }

    #[Test]
    public function test_capture_rollback_probe_snapshot_rejects_expected_schema_mismatch(): void
    {
        $expectedSchema = 'accounting_p8_tmp_fedcba987654';
        $this->withScopedDisposableDbProof($expectedSchema, function () use ($expectedSchema) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(['db' => 'accounting_p8_tmp_0123456789ab', 'port' => '3342']);

            $pdo = $this->createMock(\PDO::class);
            $pdo->method('inTransaction')->willReturn(false);
            $pdo->method('query')->willReturn($stmt);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("PDO schema mismatch: expected '{$expectedSchema}', got 'accounting_p8_tmp_0123456789ab'");

            \Tests\Support\Harness\DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot($pdo, 1, $expectedSchema);
        });
    }

    #[Test]
    public function test_capture_rollback_probe_snapshot_rejects_lossy_float_in_row(): void
    {
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $this->withScopedDisposableDbProof($schemaName, function () use ($schemaName) {
            $gateStmt = $this->createMock(\PDOStatement::class);
            $gateStmt->method('fetch')->willReturn(['db' => $schemaName, 'port' => '3342']);

            $tableStmt = $this->createMock(\PDOStatement::class);
            $tableStmt->method('fetch')->willReturnOnConsecutiveCalls(
                ['id' => 1, 'amount' => 58.00],
                false
            );

            $pdo = $this->createMock(\PDO::class);
            $pdo->method('inTransaction')->willReturn(false);
            $pdo->method('query')->willReturn($gateStmt);
            $pdo->method('prepare')->willReturn($tableStmt);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Lossy native float detected in column sales_invoices.amount: decimal strings strictly required from PDO.');

            \Tests\Support\Harness\DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot($pdo, 1, $schemaName);
        });
    }

    #[Test]
    public function test_capture_rollback_probe_snapshot_positive_full_row_scan_and_zero_delta(): void
    {
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $this->withScopedDisposableDbProof($schemaName, function () use ($schemaName) {
            $gateStmt = $this->createMock(\PDOStatement::class);
            $gateStmt->method('fetch')->willReturn(['db' => $schemaName, 'port' => '3342']);

            $tableStmt = $this->createMock(\PDOStatement::class);
            $tableStmt->method('fetch')->willReturnOnConsecutiveCalls(
                ['id' => 1, 'document_number' => 'INV-001', 'total_amount' => '58.000000'],
                false
            );

            $seqStmt = $this->createMock(\PDOStatement::class);
            $seqStmt->method('fetch')->willReturnOnConsecutiveCalls(
                ['document_type' => 'invoice', 'year' => 2026, 'next_number' => 2],
                false
            );

            $sumStmt = $this->createMock(\PDOStatement::class);
            $sumStmt->method('fetchColumn')->willReturn('10.000000');

            $pdo = $this->createMock(\PDO::class);
            $pdo->method('inTransaction')->willReturn(false);
            $pdo->method('query')->willReturn($gateStmt);
            $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($tableStmt, $seqStmt, $sumStmt) {
                if (str_contains($sql, 'document_sequences')) {
                    return $seqStmt;
                }
                if (str_contains($sql, 'SUM(`quantity')) {
                    return $sumStmt;
                }

                return $tableStmt;
            });

            $snapshot = \Tests\Support\Harness\DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot($pdo, 1, $schemaName);

            $this->assertIsArray($snapshot);
            $this->assertSame(1, $snapshot['sales_invoices']);
            $this->assertSame('10.000000', $snapshot['stock_movements_qty_delta_base']);
            $this->assertSame(['invoice:2026' => 2], $snapshot['document_sequences']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $snapshot['row_fingerprint_sha256']);

            $zeroDelta = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($snapshot, $snapshot);
            $this->assertTrue($zeroDelta['is_zero_delta']);
            $this->assertEmpty($zeroDelta['errors']);

            $mutatedSnapshot = $snapshot;
            $mutatedSnapshot['row_fingerprint_sha256'] = hash('sha256', 'mutated_row_values');
            $deltaCheck = \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertRollbackZeroDelta($snapshot, $mutatedSnapshot);
            $this->assertFalse($deltaCheck['is_zero_delta']);
            $this->assertStringContainsString('Aggregate canonical row fingerprint mutated', implode('; ', $deltaCheck['errors']));
        });
    }

    #[Test]
    public function test_cli_command_defines_and_binds_root_approval_options(): void
    {
        $cmd = new \Tests\Support\Harness\MobileV2HarnessCommand();
        $definition = $cmd->getDefinition();

        $this->assertTrue($definition->hasOption('root-approval-file'));
        $this->assertTrue($definition->hasOption('root-live-approval-file'));
        $this->assertTrue($definition->hasOption('advanced-probes'));
        $this->assertTrue($definition->hasOption('expected-scope'));
    }

    #[Test]
    public function test_validate_process_facts_refuses_forbidden_pids(): void
    {
        $myPid = getmypid();
        $parentPid = function_exists('posix_getppid') ? posix_getppid() : 999999;

        // Root PID 1
        $res1 = SupervisedPairedFinancialDrill::validateProcessFacts(['pid' => 1]);
        $this->assertFalse($res1['valid']);
        $this->assertStringContainsString('REFUSED_ROOT_OR_INVALID_PID_1', (string) $res1['error']);

        // Negative PID
        $resNeg = SupervisedPairedFinancialDrill::validateProcessFacts(['pid' => -5]);
        $this->assertFalse($resNeg['valid']);
        $this->assertStringContainsString('REFUSED_ROOT_OR_INVALID_PID_-5', (string) $resNeg['error']);

        // Supervisor own PID
        $resSelf = SupervisedPairedFinancialDrill::validateProcessFacts(['pid' => $myPid]);
        $this->assertFalse($resSelf['valid']);
        $this->assertStringContainsString('REFUSED_OWN_SUPERVISOR_PID', (string) $resSelf['error']);

        // Supervisor parent PID
        if (function_exists('posix_getppid')) {
            $resParent = SupervisedPairedFinancialDrill::validateProcessFacts(['pid' => $parentPid]);
            $this->assertFalse($resParent['valid']);
            $this->assertStringContainsString('REFUSED_PARENT_SUPERVISOR_PID', (string) $resParent['error']);
        }
    }

    #[Test]
    public function test_validate_process_facts_refuses_missing_mandatory_facts(): void
    {
        $baseFacts = [
            'pid' => 8888,
            'uid' => function_exists('posix_getuid') ? posix_getuid() : 1000,
            'is_dir' => true,
            'is_link' => false,
            'ppid' => 5555,
            'start_ticks' => '12345',
            'cmdline' => 'php harness',
        ];

        // Missing is_dir
        $fNoDir = $baseFacts;
        unset($fNoDir['is_dir']);
        $rNoDir = SupervisedPairedFinancialDrill::validateProcessFacts($fNoDir);
        $this->assertFalse($rNoDir['valid']);
        $this->assertSame('PROC_FACTS_IS_DIR_MISSING', $rNoDir['error']);

        // Missing is_link
        $fNoLink = $baseFacts;
        unset($fNoLink['is_link']);
        $rNoLink = SupervisedPairedFinancialDrill::validateProcessFacts($fNoLink);
        $this->assertFalse($rNoLink['valid']);
        $this->assertSame('PROC_FACTS_IS_LINK_MISSING', $rNoLink['error']);

        // Missing uid
        $fNoUid = $baseFacts;
        unset($fNoUid['uid']);
        $rNoUid = SupervisedPairedFinancialDrill::validateProcessFacts($fNoUid);
        $this->assertFalse($rNoUid['valid']);
        $this->assertSame('PROC_FACTS_UID_MISSING', $rNoUid['error']);

        // Non-directory or symlink
        $fNotDir = $baseFacts;
        $fNotDir['is_dir'] = false;
        $rNotDir = SupervisedPairedFinancialDrill::validateProcessFacts($fNotDir);
        $this->assertFalse($rNotDir['valid']);
        $this->assertSame('PROC_DIR_MISSING', $rNotDir['error']);

        $fLink = $baseFacts;
        $fLink['is_link'] = true;
        $rLink = SupervisedPairedFinancialDrill::validateProcessFacts($fLink);
        $this->assertFalse($rLink['valid']);
        $this->assertSame('PROC_DIR_LINK_REFUSED', $rLink['error']);
    }

    #[Test]
    public function test_validate_process_facts_refuses_uid_mismatch(): void
    {
        if (! function_exists('posix_getuid')) {
            $this->markTestSkipped('posix_getuid unavailable');
        }

        $facts = [
            'pid' => 8888,
            'uid' => posix_getuid() + 100, // foreign UID
            'is_dir' => true,
            'is_link' => false,
            'ppid' => 5555,
            'start_ticks' => '12345',
            'cmdline' => 'php harness',
        ];

        $res = SupervisedPairedFinancialDrill::validateProcessFacts($facts, expectedPpid: 5555, expectedStartTicks: '12345');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('PROC_UID_MISMATCH', (string) $res['error']);
    }

    #[Test]
    public function test_validate_process_facts_refuses_foreign_ppid_ancestry(): void
    {
        $facts = [
            'pid' => 8888,
            'uid' => function_exists('posix_getuid') ? posix_getuid() : 1000,
            'is_dir' => true,
            'is_link' => false,
            'ppid' => 9999, // foreign parent
            'start_ticks' => '12345',
            'cmdline' => 'php harness',
        ];

        $res = SupervisedPairedFinancialDrill::validateProcessFacts($facts, expectedPpid: 5555, expectedStartTicks: '12345');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('PROC_PPID_LINEAGE_MISMATCH', (string) $res['error']);
    }

    #[Test]
    public function test_validate_process_facts_allows_orphan_adopted_by_init_when_flagged(): void
    {
        $facts = [
            'pid' => 8888,
            'uid' => function_exists('posix_getuid') ? posix_getuid() : 1000,
            'is_dir' => true,
            'is_link' => false,
            'ppid' => 1, // adopted by init after leader exited
            'start_ticks' => '12345',
            'cmdline' => 'php /tmp/v2_harness_test/proxy.php',
        ];

        // Without allowOrphanAdoptedByInit -> rejected
        $resStrict = SupervisedPairedFinancialDrill::validateProcessFacts(
            $facts,
            expectedPpid: 5555,
            expectedStartTicks: '12345',
            expectedPathSubstring: 'v2_harness_test',
            allowOrphanAdoptedByInit: false
        );
        $this->assertFalse($resStrict['valid']);
        $this->assertStringContainsString('PROC_PPID_LINEAGE_MISMATCH', (string) $resStrict['error']);

        // With allowOrphanAdoptedByInit -> accepted
        $resOrphan = SupervisedPairedFinancialDrill::validateProcessFacts(
            $facts,
            expectedPpid: 5555,
            expectedStartTicks: '12345',
            expectedPathSubstring: 'v2_harness_test',
            allowOrphanAdoptedByInit: true
        );
        $this->assertTrue($resOrphan['valid']);
        $this->assertSame('12345', $resOrphan['start_ticks']);
    }

    #[Test]
    public function test_validate_process_facts_refuses_orphan_when_start_ticks_or_path_unproven(): void
    {
        $facts = [
            'pid' => 8888,
            'uid' => function_exists('posix_getuid') ? posix_getuid() : 1000,
            'is_dir' => true,
            'is_link' => false,
            'ppid' => 1,
            'start_ticks' => '12345',
            'cmdline' => 'php /tmp/v2_harness_test/proxy.php',
        ];

        // Missing expected start ticks -> unproven
        $rNoStart = SupervisedPairedFinancialDrill::validateProcessFacts(
            $facts,
            expectedPpid: 5555,
            expectedStartTicks: null,
            expectedPathSubstring: 'v2_harness_test',
            allowOrphanAdoptedByInit: true
        );
        $this->assertFalse($rNoStart['valid']);
        $this->assertSame('PROC_ORPHAN_START_TICKS_UNPROVEN', $rNoStart['error']);

        // Missing expected path substring -> unproven
        $rNoPath = SupervisedPairedFinancialDrill::validateProcessFacts(
            $facts,
            expectedPpid: 5555,
            expectedStartTicks: '12345',
            expectedPathSubstring: null,
            allowOrphanAdoptedByInit: true
        );
        $this->assertFalse($rNoPath['valid']);
        $this->assertSame('PROC_ORPHAN_PATH_UNPROVEN', $rNoPath['error']);

        // Mismatched expected path substring -> refused
        $rWrongPath = SupervisedPairedFinancialDrill::validateProcessFacts(
            $facts,
            expectedPpid: 5555,
            expectedStartTicks: '12345',
            expectedPathSubstring: 'foreign_harness_dir',
            allowOrphanAdoptedByInit: true
        );
        $this->assertFalse($rWrongPath['valid']);
        $this->assertSame('PROC_CMDLINE_PATH_MISMATCH', $rWrongPath['error']);
    }

    #[Test]
    public function test_validate_process_facts_refuses_missing_or_changed_start_ticks(): void
    {
        $baseFacts = [
            'pid' => 8888,
            'uid' => function_exists('posix_getuid') ? posix_getuid() : 1000,
            'is_dir' => true,
            'is_link' => false,
            'ppid' => 5555,
            'cmdline' => 'php harness',
        ];

        // Missing start ticks
        $factsMissing = $baseFacts;
        $factsMissing['start_ticks'] = '';
        $resMissing = SupervisedPairedFinancialDrill::validateProcessFacts($factsMissing, expectedPpid: 5555, expectedStartTicks: '12345');
        $this->assertFalse($resMissing['valid']);
        $this->assertStringContainsString('PROC_START_TICKS_INVALID', (string) $resMissing['error']);

        // Non-numeric start ticks
        $factsAlpha = $baseFacts;
        $factsAlpha['start_ticks'] = 'abc123';
        $resAlpha = SupervisedPairedFinancialDrill::validateProcessFacts($factsAlpha, expectedPpid: 5555, expectedStartTicks: '12345');
        $this->assertFalse($resAlpha['valid']);
        $this->assertStringContainsString('PROC_START_TICKS_INVALID', (string) $resAlpha['error']);

        // Changed start ticks (recycled PID)
        $factsChanged = $baseFacts;
        $factsChanged['start_ticks'] = '99999';
        $resChanged = SupervisedPairedFinancialDrill::validateProcessFacts($factsChanged, expectedPpid: 5555, expectedStartTicks: '12345');
        $this->assertFalse($resChanged['valid']);
        $this->assertStringContainsString('PROC_START_TICKS_MISMATCH', (string) $resChanged['error']);
    }

    #[Test]
    public function test_validate_process_facts_refuses_cmdline_path_mismatch(): void
    {
        $facts = [
            'pid' => 8888,
            'uid' => function_exists('posix_getuid') ? posix_getuid() : 1000,
            'is_dir' => true,
            'is_link' => false,
            'ppid' => 5555,
            'start_ticks' => '12345',
            'cmdline' => 'php /var/other/service.php',
        ];

        $res = SupervisedPairedFinancialDrill::validateProcessFacts(
            $facts,
            expectedPpid: 5555,
            expectedStartTicks: '12345',
            expectedPathSubstring: 'mobile-v2-harness-cli.php'
        );
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('PROC_CMDLINE_PATH_MISMATCH', (string) $res['error']);
    }

    #[Test]
    public function test_verify_process_ownership_negative_hook_can_only_refuse_never_grant_positive(): void
    {
        // Negative hook forces refusal
        SupervisedPairedFinancialDrill::$negativeRefusalHook = function (int $pid, int $expectedPpid, ?string $expectedStartTicks, ?string $expectedPathSubstring): ?string {
            return 'SIMULATED_TEST_FAULT';
        };

        $res = SupervisedPairedFinancialDrill::verifyProcessOwnership(8888);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('FORCED_TEST_REFUSAL: SIMULATED_TEST_FAULT', (string) $res['error']);

        // Without hook, verifyProcessOwnership checks live /proc directly and rejects non-existent PID
        SupervisedPairedFinancialDrill::$negativeRefusalHook = null;
        $resLive = SupervisedPairedFinancialDrill::verifyProcessOwnership(9999999);
        $this->assertFalse($resLive['valid']);
        $this->assertStringContainsString('PROC_DIR_MISSING', (string) $resLive['error']);
    }

    #[Test]
    public function test_verified_owned_snapshot_from_live_ownership_rejects_supervisor_pid_in_child_pid(): void
    {
        class_exists(SupervisedPairedFinancialDrill::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SNAPSHOT_REFUSED_SUPERVISOR_PID');

        \Tests\Support\Harness\VerifiedOwnedSnapshot::fromLiveOwnership(
            schemaName: 'accounting_p8_tmp_0123456789ab',
            proofPath: sys_get_temp_dir() . '/dummy_proof.json',
            proofNonce: str_repeat('a', 32),
            harnessDir: sys_get_temp_dir() . '/v2_harness_0123456789abcdef',
            childPid: getmypid(),
            repoRoot: dirname(__DIR__, 4)
        );
    }

    #[Test]
    public function test_verified_owned_snapshot_from_live_ownership_rejects_missing_child_proc(): void
    {
        class_exists(SupervisedPairedFinancialDrill::class);
        // Valid proof and harness paths, but non-existent child PID
        $proofDir = sys_get_temp_dir() . '/p8_proof_' . bin2hex(random_bytes(8));
        mkdir($proofDir, 0700, true);
        $proofFile = $proofDir . '/proof.json';

        $harnessDir = sys_get_temp_dir() . '/v2_harness_' . bin2hex(random_bytes(8));
        mkdir($harnessDir, 0700, true);

        $proofData = [
            'schema' => 'accounting_p8_tmp_0123456789ab',
            'host' => '127.0.0.1',
            'port' => '3342',
            'nonce' => str_repeat('a', 32),
        ];
        file_put_contents($proofFile, json_encode($proofData));
        chmod($proofFile, 0600);

        $savedEnv = [
            'APP_ENV' => getenv('APP_ENV'),
            'PHASE8_ALLOW_DISPOSABLE_DB' => getenv('PHASE8_ALLOW_DISPOSABLE_DB'),
            'PHASE8_TEST_DB_HOST' => getenv('PHASE8_TEST_DB_HOST'),
            'PHASE8_TEST_DB_PORT' => getenv('PHASE8_TEST_DB_PORT'),
            'PHASE8_TEST_DB_USERNAME' => getenv('PHASE8_TEST_DB_USERNAME'),
            'PHASE8_TEST_DB_PASSWORD' => getenv('PHASE8_TEST_DB_PASSWORD'),
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => getenv('PHASE8_TEST_DB_EMPTY_PASSWORD'),
            'PHASE8_DISPOSABLE_DB_PROOF' => getenv('PHASE8_DISPOSABLE_DB_PROOF'),
            'PHASE8_DISPOSABLE_DB_NONCE' => getenv('PHASE8_DISPOSABLE_DB_NONCE'),
        ];

        try {
            putenv('APP_ENV=testing');
            putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
            putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
            putenv('PHASE8_TEST_DB_PORT=3342');
            putenv('PHASE8_TEST_DB_USERNAME=root');
            putenv('PHASE8_TEST_DB_PASSWORD=');
            putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');
            putenv("PHASE8_DISPOSABLE_DB_PROOF={$proofFile}");
            putenv('PHASE8_DISPOSABLE_DB_NONCE=' . str_repeat('a', 32));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('SNAPSHOT_CHILD_PROCESS_NOT_PROVEN: PROC_DIR_MISSING');

            \Tests\Support\Harness\VerifiedOwnedSnapshot::fromLiveOwnership(
                schemaName: 'accounting_p8_tmp_0123456789ab',
                proofPath: $proofFile,
                proofNonce: str_repeat('a', 32),
                harnessDir: $harnessDir,
                childPid: 9999999, // Non-existent PID
                repoRoot: dirname(__DIR__, 4)
            );
        } finally {
            if (file_exists($proofFile)) {
                @unlink($proofFile);
            }
            if (is_dir($proofDir)) {
                @rmdir($proofDir);
            }
            if (is_dir($harnessDir)) {
                @rmdir($harnessDir);
            }
            foreach ($savedEnv as $k => $v) {
                if ($v === false) {
                    putenv($k);
                } else {
                    putenv("{$k}={$v}");
                }
            }
        }
    }

    #[Test]
    public function test_bounded_throwaway_owned_child_lifecycle_via_real_public_factory_and_mock_pdo(): void
    {
        // Guarded: requires explicit Root process-only execution grant.
        // Under current SOURCE-ONLY review, no OS child processes may be spawned.
        if (getenv('ROOT_PROCESS_LIFECYCLE_GRANT') !== '1') {
            $this->markTestSkipped('Requires explicit ROOT_PROCESS_LIFECYCLE_GRANT=1 (no child process execution during source-only qualification).');
        }

        class_exists(SupervisedPairedFinancialDrill::class);

        $repoRoot = dirname(__DIR__, 4);
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $proofNonce = str_repeat('a', 32);

        $proofDir = sys_get_temp_dir() . '/p8_proof_' . bin2hex(random_bytes(8));
        mkdir($proofDir, 0700, true);
        $proofFile = $proofDir . '/proof.json';

        $harnessName = 'v2_harness_' . bin2hex(random_bytes(8));
        $harnessDir = sys_get_temp_dir() . '/' . $harnessName;
        mkdir($harnessDir, 0700, true);

        // Throwaway script matching harness script path with bounded TTL <= 10s (2s sleep)
        $throwawayScript = $harnessDir . '/mobile-v2-harness-cli.php';
        file_put_contents($throwawayScript, "<?php usleep(2000000);\n");
        chmod($throwawayScript, 0700);

        // Symfony Process with bounded 10s timeout
        $process = new Process([PHP_BINARY, $throwawayScript]);
        $process->setTimeout(10.0);
        $process->start();

        // Wait up to 1.0s for process to be running and PID available
        $waitStart = hrtime(true) / 1e9;
        while (! $process->isRunning() && ((hrtime(true) / 1e9) - $waitStart) < 1.0) {
            usleep(20000);
        }
        $childPid = $process->getPid();
        $this->assertNotNull($childPid, 'Child process PID must be available');

        $savedEnv = [
            'APP_ENV' => getenv('APP_ENV'),
            'PHASE8_ALLOW_DISPOSABLE_DB' => getenv('PHASE8_ALLOW_DISPOSABLE_DB'),
            'PHASE8_TEST_DB_HOST' => getenv('PHASE8_TEST_DB_HOST'),
            'PHASE8_TEST_DB_PORT' => getenv('PHASE8_TEST_DB_PORT'),
            'PHASE8_TEST_DB_USERNAME' => getenv('PHASE8_TEST_DB_USERNAME'),
            'PHASE8_TEST_DB_PASSWORD' => getenv('PHASE8_TEST_DB_PASSWORD'),
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => getenv('PHASE8_TEST_DB_EMPTY_PASSWORD'),
            'PHASE8_DISPOSABLE_DB_PROOF' => getenv('PHASE8_DISPOSABLE_DB_PROOF'),
            'PHASE8_DISPOSABLE_DB_NONCE' => getenv('PHASE8_DISPOSABLE_DB_NONCE'),
        ];

        try {
            putenv('APP_ENV=testing');
            putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
            putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
            putenv('PHASE8_TEST_DB_PORT=3342');
            putenv('PHASE8_TEST_DB_USERNAME=root');
            putenv('PHASE8_TEST_DB_PASSWORD=');
            putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');
            putenv("PHASE8_DISPOSABLE_DB_PROOF={$proofFile}");
            putenv("PHASE8_DISPOSABLE_DB_NONCE={$proofNonce}");

            $proofData = [
                'schema' => $schemaName,
                'host' => '127.0.0.1',
                'port' => '3342',
                'nonce' => $proofNonce,
            ];
            file_put_contents($proofFile, json_encode($proofData, JSON_THROW_ON_ERROR));
            chmod($proofFile, 0600);

            // Construct snapshot via REAL public factory from live /proc
            $snapshot = \Tests\Support\Harness\VerifiedOwnedSnapshot::fromLiveOwnership(
                schemaName: $schemaName,
                proofPath: $proofFile,
                proofNonce: $proofNonce,
                harnessDir: $harnessDir,
                childPid: $childPid,
                repoRoot: $repoRoot
            );

            $this->assertSame($childPid, $snapshot->childPid);
            $this->assertNotNull($snapshot->childStartIdentity);

            // Mock PDO expects exact port check, schema presence, DROP, and schema absence
            $mockPortStmt = $this->createMock(\PDOStatement::class);
            $mockPortStmt->method('fetch')->willReturn(['port' => '3342']);

            $mockSchemaStmt = $this->createMock(\PDOStatement::class);
            $mockSchemaStmt->expects($this->exactly(2))
                ->method('execute')
                ->with([$schemaName]);
            $mockSchemaStmt->method('fetch')
                ->willReturnOnConsecutiveCalls(['SCHEMA_NAME' => $schemaName], false);

            $mockPdo = $this->createMock(\PDO::class);
            $mockPdo->expects($this->once())
                ->method('query')
                ->with('SELECT @@port AS port')
                ->willReturn($mockPortStmt);
            $mockPdo->expects($this->once())
                ->method('prepare')
                ->with('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1')
                ->willReturn($mockSchemaStmt);
            $mockPdo->expects($this->once())
                ->method('exec')
                ->with("DROP DATABASE IF EXISTS `{$schemaName}`")
                ->willReturn(1);

            // Pass actual Process handle to executeExactCleanup
            $cleanResult = SupervisedPairedFinancialDrill::executeExactCleanup(
                childProcess: $process,
                snapshot: $snapshot,
                repoRoot: $repoRoot,
                adminPdo: $mockPdo
            );

            // Assert process is confirmed reaped and cleanup succeeded before finally
            $this->assertFalse($process->isRunning(), 'Child process must be reaped and not running before finally');
            $this->assertTrue($cleanResult['child_reaped'], 'child_reaped must be true');
            $this->assertTrue($cleanResult['schema_absent'], 'schema_absent must be true');
            $this->assertTrue($cleanResult['temp_cleaned'], 'temp_cleaned must be true');
            $this->assertTrue($cleanResult['success'], 'overall cleanup success must be true');
            $this->assertEmpty($cleanResult['errors'], 'cleanup errors must be empty');

            // Assert temp directories and proof removed
            $this->assertDirectoryDoesNotExist($harnessDir, 'Harness temp directory must be removed by cleanup');
            $this->assertDirectoryDoesNotExist($proofDir, 'Proof parent directory must be removed by cleanup');
        } finally {
            if ($process->isRunning()) {
                $process->stop(1.0);
            }
            if (file_exists($proofFile)) {
                @unlink($proofFile);
            }
            if (is_dir($proofDir)) {
                @rmdir($proofDir);
            }
            if (file_exists($throwawayScript)) {
                @unlink($throwawayScript);
            }
            if (is_dir($harnessDir)) {
                @rmdir($harnessDir);
            }
            foreach ($savedEnv as $k => $v) {
                if ($v === false) {
                    putenv($k);
                } else {
                    putenv("{$k}={$v}");
                }
            }
        }
    }

    #[Test]
    public function test_bounded_throwaway_owned_child_refuses_cleanup_when_process_handle_omitted(): void
    {
        // Guarded: requires explicit Root process-only execution grant.
        // Under current SOURCE-ONLY review, no OS child processes may be spawned.
        if (getenv('ROOT_PROCESS_LIFECYCLE_GRANT') !== '1') {
            $this->markTestSkipped('Requires explicit ROOT_PROCESS_LIFECYCLE_GRANT=1 (no child process execution during source-only qualification).');
        }

        class_exists(SupervisedPairedFinancialDrill::class);

        $repoRoot = dirname(__DIR__, 4);
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $proofNonce = str_repeat('a', 32);

        $proofDir = sys_get_temp_dir() . '/p8_proof_' . bin2hex(random_bytes(8));
        mkdir($proofDir, 0700, true);
        $proofFile = $proofDir . '/proof.json';

        $harnessName = 'v2_harness_' . bin2hex(random_bytes(8));
        $harnessDir = sys_get_temp_dir() . '/' . $harnessName;
        mkdir($harnessDir, 0700, true);

        // Throwaway script running bounded sleep (5s)
        $throwawayScript = $harnessDir . '/mobile-v2-harness-cli.php';
        file_put_contents($throwawayScript, "<?php usleep(5000000);\n");
        chmod($throwawayScript, 0700);

        // Spawn actual owned child process
        $process = new Process([PHP_BINARY, $throwawayScript]);
        $process->setTimeout(10.0);
        $process->start();

        $waitStart = hrtime(true) / 1e9;
        while (! $process->isRunning() && ((hrtime(true) / 1e9) - $waitStart) < 1.0) {
            usleep(20000);
        }
        $childPid = $process->getPid();
        $this->assertNotNull($childPid, 'Child process PID must be available');

        $savedEnv = [
            'APP_ENV' => getenv('APP_ENV'),
            'PHASE8_ALLOW_DISPOSABLE_DB' => getenv('PHASE8_ALLOW_DISPOSABLE_DB'),
            'PHASE8_TEST_DB_HOST' => getenv('PHASE8_TEST_DB_HOST'),
            'PHASE8_TEST_DB_PORT' => getenv('PHASE8_TEST_DB_PORT'),
            'PHASE8_TEST_DB_USERNAME' => getenv('PHASE8_TEST_DB_USERNAME'),
            'PHASE8_TEST_DB_PASSWORD' => getenv('PHASE8_TEST_DB_PASSWORD'),
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => getenv('PHASE8_TEST_DB_EMPTY_PASSWORD'),
            'PHASE8_DISPOSABLE_DB_PROOF' => getenv('PHASE8_DISPOSABLE_DB_PROOF'),
            'PHASE8_DISPOSABLE_DB_NONCE' => getenv('PHASE8_DISPOSABLE_DB_NONCE'),
        ];

        try {
            putenv('APP_ENV=testing');
            putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
            putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
            putenv('PHASE8_TEST_DB_PORT=3342');
            putenv('PHASE8_TEST_DB_USERNAME=root');
            putenv('PHASE8_TEST_DB_PASSWORD=');
            putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');
            putenv("PHASE8_DISPOSABLE_DB_PROOF={$proofFile}");
            putenv("PHASE8_DISPOSABLE_DB_NONCE={$proofNonce}");

            $proofData = [
                'schema' => $schemaName,
                'host' => '127.0.0.1',
                'port' => '3342',
                'nonce' => $proofNonce,
            ];
            file_put_contents($proofFile, json_encode($proofData, JSON_THROW_ON_ERROR));
            chmod($proofFile, 0600);

            // Construct snapshot via REAL public factory
            $snapshot = \Tests\Support\Harness\VerifiedOwnedSnapshot::fromLiveOwnership(
                schemaName: $schemaName,
                proofPath: $proofFile,
                proofNonce: $proofNonce,
                harnessDir: $harnessDir,
                childPid: $childPid,
                repoRoot: $repoRoot
            );

            // Mock PDO expects ZERO DROP calls! Destructive cleanup must be skipped!
            $mockPdo = $this->createMock(\PDO::class);
            $mockPdo->expects($this->never())->method('exec');
            $mockPdo->expects($this->never())->method('query');
            $mockPdo->expects($this->never())->method('prepare');

            // Pass childProcess: null while child is confirmed alive
            $cleanResult = SupervisedPairedFinancialDrill::executeExactCleanup(
                childProcess: null,
                snapshot: $snapshot,
                repoRoot: $repoRoot,
                adminPdo: $mockPdo
            );

            // Fail-closed assertions: child_reaped is false, overall success is false
            $this->assertFalse($cleanResult['child_reaped'], 'child_reaped must be false when child alive without handle');
            $this->assertFalse($cleanResult['success'], 'success must be false');
            $this->assertNotEmpty($cleanResult['errors'], 'errors must be non-empty');

            $errorStr = implode('; ', $cleanResult['errors']);
            $this->assertStringContainsString('CLEANUP_OWNED_CHILD_ALIVE_WITHOUT_PROCESS_HANDLE', $errorStr);
            $this->assertStringContainsString('CLEANUP_DESTRUCTIVE_ACTIONS_SKIPPED_CHILD_ALIVE', $errorStr);

            // Child process must still be running (not killed arbitrarily without handle)
            $this->assertTrue($process->isRunning(), 'Child process must remain running without handle');

            // Temp directories must NOT have been removed while child was alive
            $this->assertDirectoryExists($harnessDir, 'Harness temp directory must not be removed while child alive');
            $this->assertDirectoryExists($proofDir, 'Proof directory must not be removed while child alive');
        } finally {
            // Stop own process safely in finally
            if ($process->isRunning()) {
                $process->stop(1.0);
            }
            if (file_exists($proofFile)) {
                @unlink($proofFile);
            }
            if (is_dir($proofDir)) {
                @rmdir($proofDir);
            }
            if (file_exists($throwawayScript)) {
                @unlink($throwawayScript);
            }
            if (is_dir($harnessDir)) {
                @rmdir($harnessDir);
            }
            foreach ($savedEnv as $k => $v) {
                if ($v === false) {
                    putenv($k);
                } else {
                    putenv("{$k}={$v}");
                }
            }
        }
    }

    #[Test]
    public function test_harness_configure_stage_allowlist_validates_and_stores_mapping(): void
    {
        $harness = new \Tests\Support\Harness\DisposableMobileV2BackendHarness();
        $this->assertSame([], $harness->getStageAllowlist());

        $validUuid = 'c0686be5-888f-4890-90bf-1f05f416a19a';
        $harness->configureStageAllowlist([
            $validUuid => 'after_posting',
        ]);
        $this->assertSame([$validUuid => 'after_posting'], $harness->getStageAllowlist());

        // Rejects invalid UUID
        try {
            $harness->configureStageAllowlist(['not-a-uuid' => 'after_posting']);
            $this->fail('Expected InvalidArgumentException for invalid event UUID');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid event UUID in stage allowlist', $e->getMessage());
        }

        // Rejects unsupported stage
        try {
            $harness->configureStageAllowlist([$validUuid => 'unsupported_stage']);
            $this->fail('Expected InvalidArgumentException for unsupported stage');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported fault stage in allowlist', $e->getMessage());
        }
    }

    #[Test]
    public function test_verify_evidence_freshness_accepts_advanced_scope_seven_pass_scenarios(): void
    {
        class_exists(SupervisedPairedFinancialDrill::class);

        $tmpDir = sys_get_temp_dir() . '/adv_ev_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);
        $evidenceFile = $tmpDir . '/evidence.json';

        $backendHead = str_repeat('a', 40);
        $pubHead = str_repeat('b', 40);
        $exeHead = str_repeat('c', 40);
        $handoffSha = str_repeat('d', 64);
        $runId = '01234567-89ab-cdef-0123-456789abcdef';

        $advancedEvidence = [
            'runId' => $runId,
            'backendHead' => $backendHead,
            'clientPublicationHead' => $pubHead,
            'clientExecutableHead' => $exeHead,
            'manifestDigest' => $handoffSha,
            'overallStatus' => 'PASS',
            'lifecycleGrant' => SupervisedPairedFinancialDrill::REQUIRED_LIFECYCLE_GRANT,
            'scenarios' => [
                ['name' => 'readiness_probe', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'transport_lost_ack_retained_status_recovery', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'idempotent_replay_integrity', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'tamper_rejection_integrity', 'status' => 'PASS', 'httpStatusCode' => 409],
                ['name' => 'rollback_permission_denial', 'status' => 'PASS', 'httpStatusCode' => 403],
                ['name' => 'rollback_insufficient_stock', 'status' => 'PASS', 'httpStatusCode' => 422],
                ['name' => 'rollback_controlled_after_posting', 'status' => 'PASS', 'httpStatusCode' => 500],
            ],
            'lost_ack_recovery_evidence' => [
                'suppression_mode' => 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP',
                'network_witness_state' => 'CAPTURED_REAL_TRANSPORT_DROP',
            ],
        ];

        try {
            file_put_contents($evidenceFile, json_encode($advancedEvidence, JSON_THROW_ON_ERROR));
            chmod($evidenceFile, 0600);

            $preRunTime = time() - 5;
            $parsed = SupervisedPairedFinancialDrill::verifyEvidenceFreshness(
                evidencePath: $evidenceFile,
                preRunTime: $preRunTime,
                preRunSha: null,
                expectedBackend: $backendHead,
                expectedPub: $pubHead,
                expectedExe: $exeHead,
                expectedHandoffSha: $handoffSha,
                expectedScope: SupervisedPairedFinancialDrill::REQUIRED_ADVANCED_SCOPE
            );

            $this->assertSame($runId, $parsed['runId']);
            $this->assertSame('PASS', $parsed['overallStatus']);
            $this->assertCount(7, $parsed['scenarios']);
        } finally {
            @unlink($evidenceFile);
            @rmdir($tmpDir);
        }
    }

    #[Test]
    public function test_bounded_throwaway_advanced_three_child_lineage_and_lifecycle(): void
    {
        // Guarded: requires explicit Root process-only execution grant.
        // Under current SOURCE-ONLY review, no OS child processes may be spawned.
        if (getenv('ROOT_PROCESS_LIFECYCLE_GRANT') !== '1') {
            $this->markTestSkipped('Requires explicit ROOT_PROCESS_LIFECYCLE_GRANT=1 (no child process execution during source-only qualification).');
        }

        class_exists(SupervisedPairedFinancialDrill::class);

        $repoRoot = dirname(__DIR__, 4);
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $proofNonce = str_repeat('a', 32);

        $proofDir = sys_get_temp_dir() . '/p8_proof_' . bin2hex(random_bytes(8));
        mkdir($proofDir, 0700, true);
        $proofFile = $proofDir . '/proof.json';

        $harnessName = 'v2_harness_' . bin2hex(random_bytes(8));
        $harnessDir = sys_get_temp_dir() . '/' . $harnessName;
        mkdir($harnessDir, 0700, true);

        // Scripts for 3-child lineage:
        // Child 1 (CLI) spawns Backend, Proxy, Caddy with bounded 3s TTL,
        // registers parent shutdown and SIGTERM handlers to reap its own children,
        // and writes process_pids.json (0600).
        $backendScript = $harnessDir . '/backend.php';
        file_put_contents($backendScript, "<?php usleep(3000000);\n");
        chmod($backendScript, 0700);

        $proxyScript = $harnessDir . '/proxy.php';
        file_put_contents($proxyScript, "<?php usleep(3000000);\n");
        chmod($proxyScript, 0700);

        $caddyScript = $harnessDir . '/caddy.php';
        file_put_contents($caddyScript, "<?php usleep(3000000);\n");
        chmod($caddyScript, 0700);

        $pidsJsonPath = $harnessDir . '/process_pids.json';

        $cliScript = $harnessDir . '/mobile-v2-harness-cli.php';
        $cliCode = <<<PHP
<?php
\$backend = proc_open([PHP_BINARY, '{$backendScript}'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], \$bPipes);
\$proxy = proc_open([PHP_BINARY, '{$proxyScript}'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], \$pPipes);
\$caddy = proc_open([PHP_BINARY, '{$caddyScript}'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], \$cPipes);

\$bStatus = proc_get_status(\$backend);
\$pStatus = proc_get_status(\$proxy);
\$cStatus = proc_get_status(\$caddy);

\$pidsData = [
    'backend_pid' => (int) \$bStatus['pid'],
    'proxy_pid' => (int) \$pStatus['pid'],
    'caddy_pid' => (int) \$cStatus['pid'],
];
file_put_contents('{$pidsJsonPath}', json_encode(\$pidsData, JSON_THROW_ON_ERROR));
chmod('{$pidsJsonPath}', 0600);

\$reapChildren = function () use (&\$backend, &\$proxy, &\$caddy) {
    foreach ([\$backend, \$proxy, \$caddy] as \$p) {
        if (is_resource(\$p)) {
            \$st = @proc_get_status(\$p);
            if (\$st && \$st['running']) {
                @proc_terminate(\$p, 15);
            }
            @proc_close(\$p);
        }
    }
};
register_shutdown_function(\$reapChildren);
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (\$reapChildren) {
        \$reapChildren();
        exit(0);
    });
}

\$waitStart = hrtime(true) / 1e9;
while ((hrtime(true) / 1e9 - \$waitStart) < 3.0) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    usleep(50000);
}

\$reapChildren();
PHP;
        file_put_contents($cliScript, $cliCode);
        chmod($cliScript, 0700);

        $process = new Process([PHP_BINARY, $cliScript]);
        $process->setTimeout(10.0);

        $childPid = null;
        $bPid = null;
        $pPid = null;
        $cPid = null;
        $bStartTicks = null;
        $pStartTicks = null;
        $cStartTicks = null;

        $savedEnv = [
            'APP_ENV' => getenv('APP_ENV'),
            'PHASE8_ALLOW_DISPOSABLE_DB' => getenv('PHASE8_ALLOW_DISPOSABLE_DB'),
            'PHASE8_TEST_DB_HOST' => getenv('PHASE8_TEST_DB_HOST'),
            'PHASE8_TEST_DB_PORT' => getenv('PHASE8_TEST_DB_PORT'),
            'PHASE8_TEST_DB_USERNAME' => getenv('PHASE8_TEST_DB_USERNAME'),
            'PHASE8_TEST_DB_PASSWORD' => getenv('PHASE8_TEST_DB_PASSWORD'),
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => getenv('PHASE8_TEST_DB_EMPTY_PASSWORD'),
            'PHASE8_DISPOSABLE_DB_PROOF' => getenv('PHASE8_DISPOSABLE_DB_PROOF'),
            'PHASE8_DISPOSABLE_DB_NONCE' => getenv('PHASE8_DISPOSABLE_DB_NONCE'),
        ];

        try {
            putenv('APP_ENV=testing');
            putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
            putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
            putenv('PHASE8_TEST_DB_PORT=3342');
            putenv('PHASE8_TEST_DB_USERNAME=root');
            putenv('PHASE8_TEST_DB_PASSWORD=');
            putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');
            putenv("PHASE8_DISPOSABLE_DB_PROOF={$proofFile}");
            putenv("PHASE8_DISPOSABLE_DB_NONCE={$proofNonce}");

            $proofData = [
                'schema' => $schemaName,
                'host' => '127.0.0.1',
                'port' => '3342',
                'nonce' => $proofNonce,
            ];
            file_put_contents($proofFile, json_encode($proofData, JSON_THROW_ON_ERROR));
            chmod($proofFile, 0600);

            // Spawn Child 1 (CLI harness) inside try-registration
            $process->start();

            // Wait up to 1.5s for process_pids.json to be written
            $wStart = hrtime(true) / 1e9;
            while (! file_exists($pidsJsonPath) && ((hrtime(true) / 1e9) - $wStart) < 1.5) {
                usleep(25000);
            }
            $childPid = $process->getPid();
            $this->assertNotNull($childPid, 'Child 1 PID must be available');
            $this->assertFileExists($pidsJsonPath, 'process_pids.json must be created');

            $pidsRaw = json_decode((string) file_get_contents($pidsJsonPath), true);
            $this->assertIsArray($pidsRaw);
            $bPid = (int) $pidsRaw['backend_pid'];
            $pPid = (int) $pidsRaw['proxy_pid'];
            $cPid = (int) $pidsRaw['caddy_pid'];

            // Immediately capture and verify process ownership facts before factory or drill execution
            $bFacts = SupervisedPairedFinancialDrill::verifyProcessOwnership(
                pid: $bPid,
                expectedPpid: $childPid,
                expectedPathSubstring: $harnessDir,
                allowOrphanAdoptedByInit: true
            );
            $bStartTicks = $bFacts['valid'] ? $bFacts['start_ticks'] : null;

            $pFacts = SupervisedPairedFinancialDrill::verifyProcessOwnership(
                pid: $pPid,
                expectedPpid: $childPid,
                expectedPathSubstring: $harnessDir,
                allowOrphanAdoptedByInit: true
            );
            $pStartTicks = $pFacts['valid'] ? $pFacts['start_ticks'] : null;

            $cFacts = SupervisedPairedFinancialDrill::verifyProcessOwnership(
                pid: $cPid,
                expectedPpid: $childPid,
                expectedPathSubstring: $harnessDir,
                allowOrphanAdoptedByInit: true
            );
            $cStartTicks = $cFacts['valid'] ? $cFacts['start_ticks'] : null;

            // Construct advanced snapshot via REAL public factory with process_pids.json
            $snapshot = \Tests\Support\Harness\VerifiedOwnedSnapshot::fromLiveOwnership(
                schemaName: $schemaName,
                proofPath: $proofFile,
                proofNonce: $proofNonce,
                harnessDir: $harnessDir,
                childPid: $childPid,
                repoRoot: $repoRoot,
                pidsJsonPath: $pidsJsonPath
            );

            // Assert all 4 processes verified in snapshot
            $this->assertSame($childPid, $snapshot->childPid);
            $this->assertSame($bPid, $snapshot->backendPid);
            $this->assertSame($pPid, $snapshot->proxyPid);
            $this->assertSame($cPid, $snapshot->caddyPid);
            $this->assertNotNull($snapshot->childStartIdentity);
            $this->assertNotNull($snapshot->backendStartIdentity);
            $this->assertNotNull($snapshot->proxyStartIdentity);
            $this->assertNotNull($snapshot->caddyStartIdentity);

            // Mock PDO expects exact port check, schema presence, DROP, and schema absence
            $mockPortStmt = $this->createMock(\PDOStatement::class);
            $mockPortStmt->method('fetch')->willReturn(['port' => '3342']);

            $mockSchemaStmt = $this->createMock(\PDOStatement::class);
            $mockSchemaStmt->expects($this->exactly(2))
                ->method('execute')
                ->with([$schemaName]);
            $mockSchemaStmt->method('fetch')
                ->willReturnOnConsecutiveCalls(['SCHEMA_NAME' => $schemaName], false);

            $mockPdo = $this->createMock(\PDO::class);
            $mockPdo->expects($this->once())
                ->method('query')
                ->with('SELECT @@port AS port')
                ->willReturn($mockPortStmt);
            $mockPdo->expects($this->once())
                ->method('prepare')
                ->with('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1')
                ->willReturn($mockSchemaStmt);
            $mockPdo->expects($this->once())
                ->method('exec')
                ->with("DROP DATABASE IF EXISTS `{$schemaName}`")
                ->willReturn(1);

            // Execute exact cleanup across all 4 verified processes
            $cleanResult = SupervisedPairedFinancialDrill::executeExactCleanup(
                childProcess: $process,
                snapshot: $snapshot,
                repoRoot: $repoRoot,
                adminPdo: $mockPdo
            );

            // Assert all 4 processes reaped and cleanup fully successful
            $this->assertFalse($process->isRunning(), 'Child 1 must be reaped');
            $this->assertFalse(SupervisedPairedFinancialDrill::isProcessAlive($bPid), 'Backend child must be reaped');
            $this->assertFalse(SupervisedPairedFinancialDrill::isProcessAlive($pPid), 'Proxy child must be reaped');
            $this->assertFalse(SupervisedPairedFinancialDrill::isProcessAlive($cPid), 'Caddy child must be reaped');

            $this->assertTrue($cleanResult['child_reaped'], 'child_reaped must be true');
            $this->assertTrue($cleanResult['schema_absent'], 'schema_absent must be true');
            $this->assertTrue($cleanResult['temp_cleaned'], 'temp_cleaned must be true');
            $this->assertTrue($cleanResult['success'], 'cleanup success must be true');
            $this->assertEmpty($cleanResult['errors'], 'cleanup errors must be empty');

            $this->assertDirectoryDoesNotExist($harnessDir, 'Harness temp directory must be removed by cleanup');
            $this->assertDirectoryDoesNotExist($proofDir, 'Proof directory must be removed by cleanup');
        } finally {
            if ($process->isRunning()) {
                $process->stop(1.0);
            }

            // Fallback signal helper: strictly verifies UID, start ticks, and cmdline lineage before EVERY signal!
            // Never dispatches unverified signals; never kills unknown or reused PIDs.
            $safeVerifiedFallbackSignal = function (?int $pid, ?string $startTicks, int $expectedPpid, string $expectedPath) {
                if ($pid === null || $pid <= 0 || ! SupervisedPairedFinancialDrill::isProcessAlive($pid)) {
                    return;
                }
                $check = SupervisedPairedFinancialDrill::verifyProcessOwnership(
                    pid: $pid,
                    expectedPpid: $expectedPpid,
                    expectedStartTicks: $startTicks,
                    expectedPathSubstring: $expectedPath,
                    allowOrphanAdoptedByInit: true
                );
                if ($check['valid']) {
                    @posix_kill($pid, SIGTERM);
                    usleep(50000);
                    if (SupervisedPairedFinancialDrill::isProcessAlive($pid)) {
                        $recheck = SupervisedPairedFinancialDrill::verifyProcessOwnership(
                            pid: $pid,
                            expectedPpid: $expectedPpid,
                            expectedStartTicks: $startTicks,
                            expectedPathSubstring: $expectedPath,
                            allowOrphanAdoptedByInit: true
                        );
                        if ($recheck['valid']) {
                            @posix_kill($pid, SIGKILL);
                        }
                    }
                }
            };

            $safeVerifiedFallbackSignal($bPid, $bStartTicks, $childPid ?? -1, $harnessDir);
            $safeVerifiedFallbackSignal($pPid, $pStartTicks, $childPid ?? -1, $harnessDir);
            $safeVerifiedFallbackSignal($cPid, $cStartTicks, $childPid ?? -1, $harnessDir);

            if (file_exists($proofFile)) {
                @unlink($proofFile);
            }
            if (is_dir($proofDir)) {
                @rmdir($proofDir);
            }
            if (file_exists($pidsJsonPath)) {
                @unlink($pidsJsonPath);
            }
            if (file_exists($cliScript)) {
                @unlink($cliScript);
            }
            if (file_exists($backendScript)) {
                @unlink($backendScript);
            }
            if (file_exists($proxyScript)) {
                @unlink($proxyScript);
            }
            if (file_exists($caddyScript)) {
                @unlink($caddyScript);
            }
            if (is_dir($harnessDir)) {
                @rmdir($harnessDir);
            }
            foreach ($savedEnv as $k => $v) {
                if ($v === false) {
                    putenv($k);
                } else {
                    putenv("{$k}={$v}");
                }
            }
        }
    }

    /**
     * Constructs a genuine canonical rollback probe snapshot fixture matching the strict
     * contract of DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot and assertRollbackZeroDelta:
     * - Exactly the 9 financial table counts
     * - Document sequences map
     * - Table fingerprints mapping all 9 tables to 64-hex SHA-256 strings
     * - Exact decimal strings for stock_movements_qty_delta_base and lot_allocations_qty_allocated_base
     * - Authoritative aggregate row_fingerprint_sha256 computed via CanonicalJsonSerializer
     */
    public static function createCanonicalRollbackSnapshotFixture(array $overrides = []): array
    {
        $tableCounts = [
            'sales_invoices' => 10,
            'sales_invoice_lines' => 20,
            'sales_invoice_lot_allocations' => 0,
            'customer_payments' => 5,
            'customer_payment_allocations' => 5,
            'mobile_event_receipts' => 10,
            'stock_movements' => 20,
            'posting_batches' => 15,
            'posting_lines' => 30,
        ];

        $tableFingerprints = [];
        foreach (array_keys($tableCounts) as $t) {
            $tableFingerprints[$t] = hash('sha256', "canonical_fixture_{$t}");
        }

        $sequences = [
            'sales_invoice:2026' => 11,
        ];

        $stockQtySum = '100.000000';
        $lotQtySum = '0.000000';

        $fingerprintPayload = [
            'table_fingerprints' => $tableFingerprints,
            'document_sequences' => $sequences,
            'stock_movements_qty_delta_base' => $stockQtySum,
            'lot_allocations_qty_allocated_base' => $lotQtySum,
        ];
        $aggregateFingerprint = hash('sha256', CanonicalJsonSerializer::serialize($fingerprintPayload));

        $snapshot = [
            'sales_invoices' => $tableCounts['sales_invoices'],
            'sales_invoice_lines' => $tableCounts['sales_invoice_lines'],
            'sales_invoice_lot_allocations' => $tableCounts['sales_invoice_lot_allocations'],
            'customer_payments' => $tableCounts['customer_payments'],
            'customer_payment_allocations' => $tableCounts['customer_payment_allocations'],
            'mobile_event_receipts' => $tableCounts['mobile_event_receipts'],
            'stock_movements' => $tableCounts['stock_movements'],
            'posting_batches' => $tableCounts['posting_batches'],
            'posting_lines' => $tableCounts['posting_lines'],
            'document_sequences' => $sequences,
            'table_fingerprints' => $tableFingerprints,
            'stock_movements_qty_delta_base' => $stockQtySum,
            'lot_allocations_qty_allocated_base' => $lotQtySum,
            'row_fingerprint_sha256' => $aggregateFingerprint,
        ];

        foreach ($overrides as $k => $v) {
            $snapshot[$k] = $v;
        }

        return $snapshot;
    }

    /**
     * Build a complete, authoritative, self-consistent advanced flow fixture in the given directory.
     * Produces all 3 on-disk reports, reconciliations, manifest, ACP, trusted DB report,
     * expectedPins, and evidence that completely satisfy validateAdvancedProbeEvidenceJson.
     *
     * @return array<string, mixed>
     */
    public static function createCanonicalAdvancedFlowFixture(string $tmpDir): array
    {
        $primaryEventId = '99999999-9999-4999-8999-999999999999';
        $runId = '88888888-8888-4888-8888-888888888888';
        $backendHead = '4815546d4d00ffe82d428fb490981f96b60cf5d9';
        $handoffSha = str_repeat('a', 64);
        $payloadDigest = str_repeat('c', 64);
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $companyId = 1;

        $rawReconData = [
            'all_healthy' => true,
            'total_issue_count' => 0,
            'reconciler_results' => [
                'AccountingReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'InventoryReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'SalesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'PayablesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'MoneyReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'Phase7ReconciliationService' => ['healthy' => true, 'issue_count' => 0],
            ],
        ];
        $rawReconContent = json_encode($rawReconData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $probeConfigs = [
            'rollback_permission_denial' => [
                'id' => '11111111-1111-4111-8111-111111111111',
                'status' => 403,
                'repFile' => 'probe_rollback_permission_denial_report.json',
                'recFile' => 'probe_rollback_permission_denial_reconciliation.json',
            ],
            'rollback_insufficient_stock' => [
                'id' => '22222222-2222-4222-8222-222222222222',
                'status' => 422,
                'repFile' => 'probe_rollback_insufficient_stock_report.json',
                'recFile' => 'probe_rollback_insufficient_stock_reconciliation.json',
            ],
            'rollback_controlled_after_posting' => [
                'id' => '33333333-3333-4333-8333-333333333333',
                'status' => 500,
                'repFile' => 'probe_rollback_controlled_after_posting_report.json',
                'recFile' => 'probe_rollback_controlled_after_posting_reconciliation.json',
            ],
        ];

        $manifest = [];
        $probesEvidence = [];

        foreach ($probeConfigs as $pName => $cfg) {
            $repData = DisposableFinancialPostRunInspector::buildProbeReportPayload([
                'probe_name' => $pName,
                'client_event_id' => $cfg['id'],
                'expected_http_status' => $cfg['status'],
                'actual_http_status' => $cfg['status'],
                'schema_name' => $schemaName,
                'company_id' => $companyId,
                'company_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
                'backend_head' => $backendHead,
                'handoff_sha256' => $handoffSha,
                'economic_delta_zero' => true,
                'zero_delta_verified' => true,
                'raw_reconciliation_data' => $rawReconData,
                'reconciliation_exit_code' => 0,
                'reconciliation_stderr' => '',
                'baseline_metrics' => self::createCanonicalRollbackSnapshotFixture(),
                'post_metrics' => self::createCanonicalRollbackSnapshotFixture(),
            ]);

            $repBytes = json_encode($repData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            $repFilePath = $tmpDir . '/' . $cfg['repFile'];
            $recFilePath = $tmpDir . '/' . $cfg['recFile'];

            file_put_contents($repFilePath, $repBytes);
            file_put_contents($recFilePath, $rawReconContent);
            chmod($repFilePath, 0600);
            chmod($recFilePath, 0600);

            $manifest[$pName] = [
                'probe_name' => $pName,
                'client_event_id' => $cfg['id'],
                'report_file' => $cfg['repFile'],
                'report_sha256' => hash('sha256', $repBytes),
                'reconciliation_file' => $cfg['recFile'],
                'reconciliation_sha256' => hash('sha256', $rawReconContent),
                'expected_http_status' => $cfg['status'],
                'actual_http_status' => $cfg['status'],
                'zero_delta_verified' => true,
                'reconciliations_healthy' => true,
            ];

            $probesEvidence[] = [
                'probe_name' => $pName,
                'client_event_id' => $cfg['id'],
                'actual_http_status' => $cfg['status'],
                'expected_http_status' => $cfg['status'],
                'economic_delta_zero' => true,
                'reconciliations_healthy' => true,
                'issue_count' => 0,
                'schema_name' => $schemaName,
                'run_id' => $runId,
                'company_id' => $companyId,
                'baseline_metrics' => self::createCanonicalRollbackSnapshotFixture(),
                'post_metrics' => self::createCanonicalRollbackSnapshotFixture(),
            ];
        }

        $manifestBytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $manifestFile = $tmpDir . '/probe_reports_manifest.json';
        file_put_contents($manifestFile, $manifestBytes);
        chmod($manifestFile, 0600);
        $manifestSha = hash('sha256', $manifestBytes);

        $acpData = [
            'probe_event_ids' => [
                'permission_denial' => '11111111-1111-4111-8111-111111111111',
                'insufficient_stock' => '22222222-2222-4222-8222-222222222222',
                'controlled_fault_after_posting' => '33333333-3333-4333-8333-333333333333',
            ],
        ];
        $acpFile = $tmpDir . '/advanced_control_packet.json';
        $acpBytes = json_encode($acpData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($acpFile, $acpBytes);
        chmod($acpFile, 0600);
        $acpSha = hash('sha256', $acpBytes);

        $metadata = [
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'runId' => $runId,
            'eventId' => $primaryEventId,
            'schemaName' => $schemaName,
            'companyId' => $companyId,
            'sourceHead' => $backendHead,
            'handoffDigest' => $handoffSha,
            'advancedControlPacketData' => $acpData,
            'advancedControlPacketSha256' => $acpSha,
            'advancedControlPacketPath' => $acpFile,
        ];
        $deltaResult = [
            'is_canonical_delta_valid' => true,
            'gl_batches_balanced' => true,
            'deltas' => ['sales_invoices' => 1],
            'canonical_graph' => ['invoice_id' => 11],
        ];
        $trustedDbReport = DisposableFinancialPostRunInspector::composeTrustedDbReport(
            $metadata,
            self::createCanonicalRollbackSnapshotFixture(),
            $deltaResult,
            $rawReconData,
            $manifest
        );

        $root = dirname(__DIR__, 4);
        $expectedPins = [
            'expectedScope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'expectedRunId' => $runId,
            'expectedClientEventId' => $primaryEventId,
            'expectedSchemaName' => $schemaName,
            'expectedCompanyId' => $companyId,
            'companyId' => $companyId,
            'expectedHandoffSha' => $handoffSha,
            'expectedPayloadDigest' => $payloadDigest,
            'backendExecutionHead' => $backendHead,
            'clientPublicationHead' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
            'clientExecutableHead' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
            'expectedReportDigest' => $trustedDbReport['report_digest'],
            'expectedProbeReportsManifestSha' => $manifestSha,
            'actualProbeReportsManifestSha' => $manifestSha,
            'probeReportsManifestPath' => $manifestFile,
            'advancedControlPacketPath' => $acpFile,
            'expectedAdvancedControlPacketSha' => $acpSha,
            'expectedSourcePins' => [
                'source_fixture_sha256' => (string) hash_file('sha256', $root.'/tests/Support/Harness/DisposableMobileV2BackendHarness.php'),
                'guard_test_sha256' => (string) hash_file('sha256', __FILE__),
                'cli_command_sha256' => (string) hash_file('sha256', $root.'/tests/Support/Harness/MobileV2HarnessCommand.php'),
                'cli_runner_sha256' => (string) hash_file('sha256', $root.'/tests/Support/Harness/mobile-v2-harness-cli.php'),
            ],
        ];

        $evidence = [
            'contract_version' => 'v2_advanced_probe_evidence_v1',
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'run_id' => $runId,
            'client_event_id' => $primaryEventId,
            'schema_name' => $schemaName,
            'handoff_sha256' => $handoffSha,
            'backend_head' => $backendHead,
            'android_publication_head' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
            'android_executable_head' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
            'source_pins' => $expectedPins['expectedSourcePins'],
            'scenarios' => [
                ['name' => 'readiness_probe', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'transport_lost_ack_retained_status_recovery', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'idempotent_replay_integrity', 'status' => 'PASS', 'httpStatusCode' => 200],
                ['name' => 'tamper_rejection_integrity', 'status' => 'PASS', 'httpStatusCode' => 409],
                ['name' => 'rollback_permission_denial', 'status' => 'PASS', 'httpStatusCode' => 403],
                ['name' => 'rollback_insufficient_stock', 'status' => 'PASS', 'httpStatusCode' => 422],
                ['name' => 'rollback_controlled_after_posting', 'status' => 'PASS', 'httpStatusCode' => 500],
            ],
            'lost_ack_recovery_evidence' => [
                'suppression_mode' => 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP',
                'network_witness_state' => 'CAPTURED_REAL_TRANSPORT_DROP',
                'server_commit_verified_before_suppression' => true,
                'recovery_method' => 'RETAINED_EVENT_GET_STATUS',
                'recovery_http_status' => 200,
                'receipt_status' => 'posted',
                'matching_payload_digest' => $payloadDigest,
            ],
            'rollback_probes_evidence' => [
                'contract_version' => 'rollback_probes_evidence_v1',
                'probes' => $probesEvidence,
                'baseline_metrics' => self::createCanonicalRollbackSnapshotFixture(),
                'post_metrics' => self::createCanonicalRollbackSnapshotFixture(),
            ],
        ];

        return [
            'evidence' => $evidence,
            'expectedPins' => $expectedPins,
            'trustedDbReport' => $trustedDbReport,
            'acpFile' => $acpFile,
            'acpSha' => $acpSha,
            'manifestFile' => $manifestFile,
            'manifestSha' => $manifestSha,
            'manifest' => $manifest,
            'metadata' => $metadata,
            'tmpDir' => $tmpDir,
            'primaryEventId' => $primaryEventId,
            'runId' => $runId,
        ];
    }

    #[Test]
    public function test_genuine_producer_to_supervisor_to_trusted_compose_to_advanced_validator_flow(): void
    {
        // 1. Synthetic pre/post zero-delta metrics using canonical scanner contract
        $baselineMetrics = self::createCanonicalRollbackSnapshotFixture();
        $postMetrics = $baselineMetrics; // exact zero delta

        // 2. Build genuine raw recon artifact
        $rawReconData = [
            'all_healthy' => true,
            'total_issue_count' => 0,
            'reconciler_results' => [
                'AccountingReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'InventoryReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'SalesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'PayablesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'MoneyReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'Phase7ReconciliationService' => ['healthy' => true, 'issue_count' => 0],
            ],
        ];
        $rawReconContent = json_encode($rawReconData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $rawReconSha = hash('sha256', $rawReconContent);

        $clientEventId = '11111111-1111-4111-8111-111111111111';
        $reportParams = [
            'probe_name' => 'rollback_permission_denial',
            'client_event_id' => $clientEventId,
            'expected_http_status' => 403,
            'actual_http_status' => 403,
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
            'company_id' => 1,
            'company_public_id' => '01M7CZPF6TP283F3ZRXF5P8GT2',
            'backend_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
            'handoff_sha256' => str_repeat('a', 64),
            'economic_delta_zero' => true,
            'zero_delta_verified' => true,
            'raw_reconciliation_data' => $rawReconData,
            'reconciliation_exit_code' => 0,
            'reconciliation_stderr' => '',
            'reconciliation_artifact_sha256' => $rawReconSha,
            'baseline_metrics' => $baselineMetrics,
            'post_metrics' => $postMetrics,
        ];

        // 3. Test genuine report builder
        $report = DisposableFinancialPostRunInspector::buildProbeReportPayload($reportParams);
        $this->assertSame('rollback_permission_denial', $report['probe_name']);
        $this->assertTrue($report['reconciliations_healthy']);
        $this->assertSame(0, $report['total_issue_count']);
        $this->assertCount(6, $report['reconciler_results']);

        // 4. Test genuine report verifier (Supervisor verification)
        $expectedContext = [
            'probe_name' => 'rollback_permission_denial',
            'client_event_id' => $clientEventId,
            'expected_http_status' => 403,
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
            'company_id' => 1,
            'backend_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
            'handoff_sha256' => str_repeat('a', 64),
        ];
        $verification = DisposableFinancialPostRunInspector::verifyProbeReportPayload($report, $rawReconContent, $expectedContext);
        $this->assertTrue($verification['is_valid'], 'Report verification must pass for genuine report: '.implode('; ', $verification['errors']));

        // 5. Build full 3-probe manifest and persist artifacts to temp directory
        $tmpDir = sys_get_temp_dir() . '/test_flow_' . bin2hex(random_bytes(6));
        mkdir($tmpDir, 0700, true);

        try {
            $rep1 = $report; // 403, 11111111-1111-4111-8111-111111111111
            $rep2 = DisposableFinancialPostRunInspector::buildProbeReportPayload(array_merge($reportParams, [
                'probe_name' => 'rollback_insufficient_stock',
                'client_event_id' => '22222222-2222-4222-8222-222222222222',
                'expected_http_status' => 422,
                'actual_http_status' => 422,
            ]));
            $rep3 = DisposableFinancialPostRunInspector::buildProbeReportPayload(array_merge($reportParams, [
                'probe_name' => 'rollback_controlled_after_posting',
                'client_event_id' => '33333333-3333-4333-8333-333333333333',
                'expected_http_status' => 500,
                'actual_http_status' => 500,
            ]));

            $repFile1 = $tmpDir . '/probe_rollback_permission_denial_report.json';
            $recFile1 = $tmpDir . '/probe_rollback_permission_denial_reconciliation.json';
            $repFile2 = $tmpDir . '/probe_rollback_insufficient_stock_report.json';
            $recFile2 = $tmpDir . '/probe_rollback_insufficient_stock_reconciliation.json';
            $repFile3 = $tmpDir . '/probe_rollback_controlled_after_posting_report.json';
            $recFile3 = $tmpDir . '/probe_rollback_controlled_after_posting_reconciliation.json';

            file_put_contents($repFile1, json_encode($rep1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            file_put_contents($recFile1, $rawReconContent);
            file_put_contents($repFile2, json_encode($rep2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            file_put_contents($recFile2, $rawReconContent);
            file_put_contents($repFile3, json_encode($rep3, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            file_put_contents($recFile3, $rawReconContent);

            foreach ([$repFile1, $recFile1, $repFile2, $recFile2, $repFile3, $recFile3] as $f) {
                chmod($f, 0600);
            }

            $manifest = [
                'rollback_permission_denial' => [
                    'probe_name' => 'rollback_permission_denial',
                    'client_event_id' => '11111111-1111-4111-8111-111111111111',
                    'report_file' => 'probe_rollback_permission_denial_report.json',
                    'report_sha256' => hash_file('sha256', $repFile1),
                    'reconciliation_file' => 'probe_rollback_permission_denial_reconciliation.json',
                    'reconciliation_sha256' => hash_file('sha256', $recFile1),
                    'expected_http_status' => 403,
                    'actual_http_status' => 403,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ],
                'rollback_insufficient_stock' => [
                    'probe_name' => 'rollback_insufficient_stock',
                    'client_event_id' => '22222222-2222-4222-8222-222222222222',
                    'report_file' => 'probe_rollback_insufficient_stock_report.json',
                    'report_sha256' => hash_file('sha256', $repFile2),
                    'reconciliation_file' => 'probe_rollback_insufficient_stock_reconciliation.json',
                    'reconciliation_sha256' => hash_file('sha256', $recFile2),
                    'expected_http_status' => 422,
                    'actual_http_status' => 422,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ],
                'rollback_controlled_after_posting' => [
                    'probe_name' => 'rollback_controlled_after_posting',
                    'client_event_id' => '33333333-3333-4333-8333-333333333333',
                    'report_file' => 'probe_rollback_controlled_after_posting_report.json',
                    'report_sha256' => hash_file('sha256', $repFile3),
                    'reconciliation_file' => 'probe_rollback_controlled_after_posting_reconciliation.json',
                    'reconciliation_sha256' => hash_file('sha256', $recFile3),
                    'expected_http_status' => 500,
                    'actual_http_status' => 500,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ],
            ];
            $manifestBytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            $manifestFile = $tmpDir . '/probe_reports_manifest.json';
            file_put_contents($manifestFile, $manifestBytes);
            chmod($manifestFile, 0600);
            $manifestSha = hash('sha256', $manifestBytes);

            // 6. Test genuine composeTrustedDbReport with 3-probe manifest and mandatory ACP
            $primaryEventId = '99999999-9999-4999-8999-999999999999';
            $runId = '88888888-8888-4888-8888-888888888888';

            $acpData = [
                'probe_event_ids' => [
                    'permission_denial' => '11111111-1111-4111-8111-111111111111',
                    'insufficient_stock' => '22222222-2222-4222-8222-222222222222',
                    'controlled_fault_after_posting' => '33333333-3333-4333-8333-333333333333',
                ],
            ];
            $acpFile = $tmpDir . '/advanced_control_packet.json';
            $acpBytes = json_encode($acpData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($acpFile, $acpBytes);
            chmod($acpFile, 0600);
            $acpSha = hash('sha256', $acpBytes);

            $metadata = [
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'runId' => $runId,
                'eventId' => $primaryEventId,
                'schemaName' => 'accounting_p8_tmp_0123456789ab',
                'companyId' => 1,
                'sourceHead' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
                'handoffDigest' => str_repeat('a', 64),
                'advancedControlPacketData' => $acpData,
                'advancedControlPacketSha256' => $acpSha,
                'advancedControlPacketPath' => $acpFile,
            ];
            $deltaResult = [
                'is_canonical_delta_valid' => true,
                'gl_batches_balanced' => true,
                'deltas' => ['sales_invoices' => 1],
                'canonical_graph' => ['invoice_id' => 11],
            ];
            $trustedDbReport = DisposableFinancialPostRunInspector::composeTrustedDbReport(
                $metadata,
                $baselineMetrics,
                $deltaResult,
                $rawReconData,
                $manifest
            );

            $this->assertSame('ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION', $trustedDbReport['scope']);
            $this->assertArrayHasKey('rollback_probes_manifest', $trustedDbReport);
            $this->assertArrayHasKey('report_digest', $trustedDbReport);
            $this->assertSame($acpSha, $trustedDbReport['advanced_control_packet_digest']);

            // 7. Test genuine validateAdvancedProbeEvidenceJson with mandatory manifest file and SHA pin
            $payloadDigest = str_repeat('c', 64);
            $root = dirname(__DIR__, 4);
            $expectedPins = [
                'expectedScope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'expectedRunId' => $runId,
                'expectedClientEventId' => $primaryEventId,
                'expectedSchemaName' => 'accounting_p8_tmp_0123456789ab',
                'expectedCompanyId' => 1,
                'companyId' => 1,
                'expectedHandoffSha' => str_repeat('a', 64),
                'expectedPayloadDigest' => $payloadDigest,
                'backendExecutionHead' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
                'clientPublicationHead' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
                'clientExecutableHead' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
                'expectedReportDigest' => $trustedDbReport['report_digest'],
                'expectedProbeReportsManifestSha' => $manifestSha,
                'actualProbeReportsManifestSha' => $manifestSha,
                'probeReportsManifestPath' => $manifestFile,
                'advancedControlPacketPath' => $acpFile,
                'expectedAdvancedControlPacketSha' => $acpSha,
                'expectedSourcePins' => [
                    'source_fixture_sha256' => (string) hash_file('sha256', $root.'/tests/Support/Harness/DisposableMobileV2BackendHarness.php'),
                    'guard_test_sha256' => (string) hash_file('sha256', __FILE__),
                    'cli_command_sha256' => (string) hash_file('sha256', $root.'/tests/Support/Harness/MobileV2HarnessCommand.php'),
                    'cli_runner_sha256' => (string) hash_file('sha256', $root.'/tests/Support/Harness/mobile-v2-harness-cli.php'),
                ],
            ];
            $evidence = [
                'contract_version' => 'v2_advanced_probe_evidence_v1',
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'run_id' => $runId,
                'client_event_id' => $primaryEventId,
                'schema_name' => 'accounting_p8_tmp_0123456789ab',
                'handoff_sha256' => str_repeat('a', 64),
                'backend_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
                'android_publication_head' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
                'android_executable_head' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
                'source_pins' => $expectedPins['expectedSourcePins'],
                'scenarios' => [
                    ['name' => 'readiness_probe', 'status' => 'PASS', 'httpStatusCode' => 200],
                    ['name' => 'transport_lost_ack_retained_status_recovery', 'status' => 'PASS', 'httpStatusCode' => 200],
                    ['name' => 'idempotent_replay_integrity', 'status' => 'PASS', 'httpStatusCode' => 200],
                    ['name' => 'tamper_rejection_integrity', 'status' => 'PASS', 'httpStatusCode' => 409],
                    ['name' => 'rollback_permission_denial', 'status' => 'PASS', 'httpStatusCode' => 403],
                    ['name' => 'rollback_insufficient_stock', 'status' => 'PASS', 'httpStatusCode' => 422],
                    ['name' => 'rollback_controlled_after_posting', 'status' => 'PASS', 'httpStatusCode' => 500],
                ],
                'lost_ack_recovery_evidence' => [
                    'suppression_mode' => 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP',
                    'network_witness_state' => 'CAPTURED_REAL_TRANSPORT_DROP',
                    'server_commit_verified_before_suppression' => true,
                    'recovery_method' => 'RETAINED_EVENT_GET_STATUS',
                    'recovery_http_status' => 200,
                    'receipt_status' => 'posted',
                    'matching_payload_digest' => $payloadDigest,
                ],
                'rollback_probes_evidence' => [
                    'probes' => [
                        [
                            'probe_name' => 'rollback_permission_denial',
                            'actual_http_status' => 403,
                            'client_event_id' => '11111111-1111-4111-8111-111111111111',
                            'economic_delta_zero' => true,
                            'reconciliations_healthy' => true,
                            'reconciliations' => ['accounting' => true, 'inventory' => true, 'sales' => true, 'payables' => true, 'money' => true, 'phase7' => true],
                            'baseline_metrics' => $baselineMetrics,
                            'post_metrics' => $postMetrics,
                        ],
                        [
                            'probe_name' => 'rollback_insufficient_stock',
                            'actual_http_status' => 422,
                            'client_event_id' => '22222222-2222-4222-8222-222222222222',
                            'economic_delta_zero' => true,
                            'reconciliations_healthy' => true,
                            'reconciliations' => ['accounting' => true, 'inventory' => true, 'sales' => true, 'payables' => true, 'money' => true, 'phase7' => true],
                            'baseline_metrics' => $baselineMetrics,
                            'post_metrics' => $postMetrics,
                        ],
                        [
                            'probe_name' => 'rollback_controlled_after_posting',
                            'actual_http_status' => 500,
                            'client_event_id' => '33333333-3333-4333-8333-333333333333',
                            'economic_delta_zero' => true,
                            'reconciliations_healthy' => true,
                            'reconciliations' => ['accounting' => true, 'inventory' => true, 'sales' => true, 'payables' => true, 'money' => true, 'phase7' => true],
                            'baseline_metrics' => $baselineMetrics,
                            'post_metrics' => $postMetrics,
                        ],
                    ],
                ],
            ];

            $evidenceValidation = DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson($evidence, $expectedPins, $trustedDbReport);
            $this->assertTrue($evidenceValidation['is_valid'], 'Evidence validation must pass with genuine trustedDbReport: '.implode('; ', $evidenceValidation['errors']));
        } finally {
            foreach (glob($tmpDir . '/*') as $f) {
                @unlink($f);
            }
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }
    }

    #[Test]
    public function test_verify_probe_report_payload_refuses_mismatched_keys_or_tampered_payload(): void
    {
        $rawReconData = [
            'all_healthy' => true,
            'total_issue_count' => 0,
            'reconciler_results' => [
                'AccountingReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'InventoryReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'SalesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'PayablesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'MoneyReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'Phase7ReconciliationService' => ['healthy' => true, 'issue_count' => 0],
            ],
        ];
        $rawReconContent = json_encode($rawReconData);

        $report = [
            'probe_name' => 'rollback_permission_denial',
            'client_event_id' => '11111111-1111-4111-8111-111111111111',
            'expected_http_status' => 403,
            'actual_http_status' => 403,
            'status_matched' => true,
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
            'company_id' => 1,
            'backend_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
            'handoff_sha256' => str_repeat('a', 64),
            'economic_delta_zero' => true,
            'zero_delta_verified' => true,
            'reconciliations_healthy' => true,
            'total_issue_count' => 0,
            'baseline_metrics' => self::createCanonicalRollbackSnapshotFixture(),
            'post_metrics' => self::createCanonicalRollbackSnapshotFixture(),
            'reconciliation_artifact_sha256' => hash('sha256', $rawReconContent),
            'reconciler_results' => $rawReconData['reconciler_results'],
        ];

        // 1. Refuses wrong event ID
        $res = DisposableFinancialPostRunInspector::verifyProbeReportPayload(
            $report,
            $rawReconContent,
            ['probe_name' => 'rollback_permission_denial', 'client_event_id' => '00000000-0000-4000-8000-000000000000', 'expected_http_status' => 403]
        );
        $this->assertFalse($res['is_valid']);
        $this->assertStringContainsString('client_event_id mismatch', implode(' ', $res['errors']));

        // 2. Refuses wrong HTTP status
        $res2 = DisposableFinancialPostRunInspector::verifyProbeReportPayload(
            $report,
            $rawReconContent,
            ['probe_name' => 'rollback_permission_denial', 'client_event_id' => '11111111-1111-4111-8111-111111111111', 'expected_http_status' => 500]
        );
        $this->assertFalse($res2['is_valid']);
        $this->assertStringContainsString('actual_http_status mismatch', implode(' ', $res2['errors']));

        // 3. Refuses tampered raw recon content
        $tamperedRawContent = json_encode(['all_healthy' => true, 'reconciler_results' => []]);
        $res3 = DisposableFinancialPostRunInspector::verifyProbeReportPayload(
            $report,
            $tamperedRawContent,
            ['probe_name' => 'rollback_permission_denial', 'client_event_id' => '11111111-1111-4111-8111-111111111111', 'expected_http_status' => 403]
        );
        $this->assertFalse($res3['is_valid']);
        $this->assertStringContainsString('Reconciliation artifact SHA mismatch', implode(' ', $res3['errors']));
    }

    #[Test]
    public function test_verify_probe_report_payload_refuses_unhealthy_service_or_non_integer_issue_count(): void
    {
        $rawReconData = [
            'all_healthy' => true,
            'total_issue_count' => 0,
            'reconciler_results' => [
                'AccountingReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'InventoryReconciliationService' => ['healthy' => false, 'issue_count' => 1], // Unhealthy!
                'SalesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'PayablesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'MoneyReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                'Phase7ReconciliationService' => ['healthy' => true, 'issue_count' => 0],
            ],
        ];
        $rawReconContent = json_encode($rawReconData);

        $report = [
            'probe_name' => 'rollback_permission_denial',
            'client_event_id' => '11111111-1111-4111-8111-111111111111',
            'expected_http_status' => 403,
            'actual_http_status' => 403,
            'status_matched' => true,
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
            'company_id' => 1,
            'backend_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
            'handoff_sha256' => str_repeat('a', 64),
            'economic_delta_zero' => true,
            'zero_delta_verified' => true,
            'reconciliations_healthy' => true,
            'total_issue_count' => 0,
            'baseline_metrics' => self::createCanonicalRollbackSnapshotFixture(),
            'post_metrics' => self::createCanonicalRollbackSnapshotFixture(),
            'reconciliation_artifact_sha256' => hash('sha256', $rawReconContent),
            'reconciler_results' => $rawReconData['reconciler_results'],
        ];

        $res = DisposableFinancialPostRunInspector::verifyProbeReportPayload(
            $report,
            $rawReconContent,
            ['probe_name' => 'rollback_permission_denial', 'client_event_id' => '11111111-1111-4111-8111-111111111111', 'expected_http_status' => 403]
        );
        $this->assertFalse($res['is_valid']);
        $this->assertStringContainsString('InventoryReconciliationService', implode(' ', $res['errors']));
    }

    #[Test]
    public function test_compose_trusted_db_report_in_advanced_scope_refuses_missing_or_extra_manifest_keys(): void
    {
        $metadata = [
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'runId' => '88888888-8888-4888-8888-888888888888',
            'eventId' => '99999999-9999-4999-8999-999999999999',
            'schemaName' => 'accounting_p8_tmp_0123456789ab',
            'companyId' => 1,
            'sourceHead' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
            'handoffDigest' => str_repeat('a', 64),
        ];

        // 1. Missing manifest throws InvalidArgumentException
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing mandatory rollback_probes_manifest');
        DisposableFinancialPostRunInspector::composeTrustedDbReport(
            $metadata,
            ['company_id' => 1],
            ['is_canonical_delta_valid' => true],
            ['all_healthy' => true],
            null
        );
    }

    #[Test]
    public function test_validate_advanced_control_packet_production_helper(): void
    {
        $primaryTargetEventId = '99999999-9999-4999-8999-999999999999';

        // 1. Valid ACP
        $validAcp = [
            'probe_event_ids' => [
                'permission_denial' => '11111111-1111-4111-8111-111111111111',
                'insufficient_stock' => '22222222-2222-4222-8222-222222222222',
                'controlled_fault_after_posting' => '33333333-3333-4333-8333-333333333333',
            ],
        ];
        $validated = DisposableFinancialPostRunInspector::validateAdvancedControlPacket($validAcp, $primaryTargetEventId);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $validated['permission_denial']);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $validated['insufficient_stock']);
        $this->assertSame('33333333-3333-4333-8333-333333333333', $validated['controlled_fault_after_posting']);

        // 2. Reject duplicate UUIDs
        $duplicateAcp = [
            'probe_event_ids' => [
                'permission_denial' => '11111111-1111-4111-8111-111111111111',
                'insufficient_stock' => '11111111-1111-4111-8111-111111111111',
                'controlled_fault_after_posting' => '33333333-3333-4333-8333-333333333333',
            ],
        ];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('probe UUIDs must be mutually distinct');
        DisposableFinancialPostRunInspector::validateAdvancedControlPacket($duplicateAcp, $primaryTargetEventId);
    }

    #[Test]
    public function test_proxy_matcher_normalizes_declared_acp_keys_to_canonical_rollback_names(): void
    {
        $reflector = new \ReflectionClass(\Tests\Support\Harness\TestTransportProxy::class);
        $proxy = $reflector->newInstanceWithoutConstructor();

        $companyUlid = '01M7CZPF6TP283F3ZRXF5P8GT2';
        $targetConfigProp = $reflector->getProperty('targetConfig');
        $targetConfigProp->setValue($proxy, [
            'target_company_public_id' => $companyUlid,
            'probe_event_ids' => [
                'permission_denial' => '11111111-1111-4111-8111-111111111111',
                'insufficient_stock' => '22222222-2222-4222-8222-222222222222',
                'controlled_fault_after_posting' => '33333333-3333-4333-8333-333333333333',
            ],
        ]);

        $route = "/api/mobile/v2/companies/{$companyUlid}/sales/online-cash";

        // 1. All 3 declared probe event IDs match through actual proxy matcher
        $p1 = $proxy->getProbeNameIfMatching('POST', $route, json_encode(['client_event_id' => '11111111-1111-4111-8111-111111111111']));
        $this->assertSame('permission_denial', $p1);
        $canon1 = DisposableFinancialPostRunInspector::normalizeRollbackProbeName($p1);
        $this->assertSame('rollback_permission_denial', $canon1);
        $this->assertSame(403, DisposableFinancialPostRunInspector::expectedRollbackProbeHttpStatus($canon1));

        $p2 = $proxy->getProbeNameIfMatching('POST', $route, json_encode(['client_event_id' => '22222222-2222-4222-8222-222222222222']));
        $this->assertSame('insufficient_stock', $p2);
        $canon2 = DisposableFinancialPostRunInspector::normalizeRollbackProbeName($p2);
        $this->assertSame('rollback_insufficient_stock', $canon2);
        $this->assertSame(422, DisposableFinancialPostRunInspector::expectedRollbackProbeHttpStatus($canon2));

        $p3 = $proxy->getProbeNameIfMatching('POST', $route, json_encode(['client_event_id' => '33333333-3333-4333-8333-333333333333']));
        $this->assertSame('controlled_fault_after_posting', $p3);
        $canon3 = DisposableFinancialPostRunInspector::normalizeRollbackProbeName($p3);
        $this->assertSame('rollback_controlled_after_posting', $canon3);
        $this->assertSame(500, DisposableFinancialPostRunInspector::expectedRollbackProbeHttpStatus($canon3));

        // 2. Unrecognized event ID returns null
        $pUnk = $proxy->getProbeNameIfMatching('POST', $route, json_encode(['client_event_id' => '99999999-9999-4999-8999-999999999999']));
        $this->assertNull($pUnk);

        // 3. Unrecognized probe name refuses normalization
        $this->assertNull(DisposableFinancialPostRunInspector::normalizeRollbackProbeName('arbitrary_malicious_probe'));
        $this->assertNull(DisposableFinancialPostRunInspector::expectedRollbackProbeHttpStatus('arbitrary_malicious_probe'));
    }

    #[Test]
    public function test_advanced_validator_refuses_missing_manifest_pin_or_missing_manifest_file(): void
    {
        $trustedDbReport = [
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'run_id' => '88888888-8888-4888-8888-888888888888',
            'event_id' => '99999999-9999-4999-8999-999999999999',
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
            'company_id' => 1,
            'source_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
            'handoff_digest' => str_repeat('a', 64),
            'baseline_hash' => str_repeat('b', 64),
            'is_canonical_delta_valid' => true,
            'gl_batches_balanced' => true,
            'all_healthy' => true,
            'rollback_probes_manifest' => [
                'rollback_permission_denial' => [
                    'probe_name' => 'rollback_permission_denial',
                    'report_file' => 'probe_rollback_permission_denial_report.json',
                    'report_sha256' => str_repeat('c', 64),
                    'reconciliation_file' => 'probe_rollback_permission_denial_reconciliation.json',
                    'reconciliation_sha256' => str_repeat('d', 64),
                    'expected_http_status' => 403,
                    'actual_http_status' => 403,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ],
                'rollback_insufficient_stock' => [
                    'probe_name' => 'rollback_insufficient_stock',
                    'report_file' => 'probe_rollback_insufficient_stock_report.json',
                    'report_sha256' => str_repeat('c', 64),
                    'reconciliation_file' => 'probe_rollback_insufficient_stock_reconciliation.json',
                    'reconciliation_sha256' => str_repeat('d', 64),
                    'expected_http_status' => 422,
                    'actual_http_status' => 422,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ],
                'rollback_controlled_after_posting' => [
                    'probe_name' => 'rollback_controlled_after_posting',
                    'report_file' => 'probe_rollback_controlled_after_posting_report.json',
                    'report_sha256' => str_repeat('c', 64),
                    'reconciliation_file' => 'probe_rollback_controlled_after_posting_reconciliation.json',
                    'reconciliation_sha256' => str_repeat('d', 64),
                    'expected_http_status' => 500,
                    'actual_http_status' => 500,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ],
            ],
        ];

        $evidence = [
            'contract_version' => 'v2_advanced_probe_evidence_v1',
            'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'run_id' => '88888888-8888-4888-8888-888888888888',
            'client_event_id' => '99999999-9999-4999-8999-999999999999',
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
            'handoff_sha256' => str_repeat('a', 64),
            'backend_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
            'android_publication_head' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
            'android_executable_head' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
            'scenarios' => [],
        ];

        // 1. Missing expectedProbeReportsManifestSha is strictly refused
        $pinsWithoutManifestSha = [
            'expectedScope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
            'backendExecutionHead' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
            'clientPublicationHead' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
            'clientExecutableHead' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
        ];
        $res1 = DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson($evidence, $pinsWithoutManifestSha, $trustedDbReport);
        $this->assertFalse($res1['is_valid']);
        $this->assertStringContainsString('Mandatory expectedProbeReportsManifestSha pin is missing', implode('; ', $res1['errors']));

        // 2. Missing manifest file when pin is provided is strictly refused (even if matching actualProbeReportsManifestSha is supplied)
        $tmpMissing = sys_get_temp_dir() . '/test_m_' . bin2hex(random_bytes(6));
        mkdir($tmpMissing, 0700, true);
        try {
            $pinsWithPinOnly = array_merge($pinsWithoutManifestSha, [
                'expectedProbeReportsManifestSha' => str_repeat('e', 64),
                'actualProbeReportsManifestSha' => str_repeat('e', 64), // Provided matching actual hash MUST NOT bypass missing file
                'probeReportsManifestPath' => $tmpMissing . '/probe_reports_manifest.json',
            ]);
            $res2 = DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson($evidence, $pinsWithPinOnly, $trustedDbReport);
            $this->assertFalse($res2['is_valid']);
            $this->assertStringContainsString('PROBE_REPORTS_MANIFEST_FILE_NOT_FOUND', implode('; ', $res2['errors']));
        } finally {
            if (is_dir($tmpMissing)) {
                @rmdir($tmpMissing);
            }
        }
    }

    #[Test]
    public function test_advanced_validator_refuses_path_traversal_and_semantic_failures(): void
    {
        $tmpDir = sys_get_temp_dir() . '/test_traversal_' . bin2hex(random_bytes(6));
        mkdir($tmpDir, 0700, true);

        try {
            // 1. Path traversal in report_file basename is rejected by assertSecureContainedFile
            $traversalManifest = [
                'rollback_permission_denial' => [
                    'probe_name' => 'rollback_permission_denial',
                    'report_file' => '../traversal_report.json',
                    'report_sha256' => str_repeat('1', 64),
                    'reconciliation_file' => 'probe_rollback_permission_denial_reconciliation.json',
                    'reconciliation_sha256' => str_repeat('2', 64),
                    'expected_http_status' => 403,
                    'actual_http_status' => 403,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ],
                'rollback_insufficient_stock' => [
                    'probe_name' => 'rollback_insufficient_stock',
                    'report_file' => 'probe_rollback_insufficient_stock_report.json',
                    'report_sha256' => str_repeat('1', 64),
                    'reconciliation_file' => 'probe_rollback_insufficient_stock_reconciliation.json',
                    'reconciliation_sha256' => str_repeat('2', 64),
                    'expected_http_status' => 422,
                    'actual_http_status' => 422,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ],
                'rollback_controlled_after_posting' => [
                    'probe_name' => 'rollback_controlled_after_posting',
                    'report_file' => 'probe_rollback_controlled_after_posting_report.json',
                    'report_sha256' => str_repeat('1', 64),
                    'reconciliation_file' => 'probe_rollback_controlled_after_posting_reconciliation.json',
                    'reconciliation_sha256' => str_repeat('2', 64),
                    'expected_http_status' => 500,
                    'actual_http_status' => 500,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ],
            ];

            $traversalBytes = json_encode($traversalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            $manifestFile = $tmpDir . '/probe_reports_manifest.json';
            file_put_contents($manifestFile, $traversalBytes);
            chmod($manifestFile, 0600);
            $traversalSha = hash('sha256', $traversalBytes);

            $acpData = [
                'probe_event_ids' => [
                    'permission_denial' => '11111111-1111-4111-8111-111111111111',
                    'insufficient_stock' => '22222222-2222-4222-8222-222222222222',
                    'controlled_fault_after_posting' => '33333333-3333-4333-8333-333333333333',
                ],
            ];
            $acpFile = $tmpDir . '/advanced_control_packet.json';
            $acpBytes = json_encode($acpData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($acpFile, $acpBytes);
            chmod($acpFile, 0600);
            $acpSha = hash('sha256', $acpBytes);

            $trustedDbReport = [
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'run_id' => '88888888-8888-4888-8888-888888888888',
                'event_id' => '99999999-9999-4999-8999-999999999999',
                'schema_name' => 'accounting_p8_tmp_0123456789ab',
                'company_id' => 1,
                'source_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
                'handoff_digest' => str_repeat('a', 64),
                'baseline_hash' => str_repeat('b', 64),
                'is_canonical_delta_valid' => true,
                'gl_batches_balanced' => true,
                'all_healthy' => true,
                'rollback_probes_manifest' => $traversalManifest,
                'advanced_control_packet_digest' => $acpSha,
                'probe_event_ids' => $acpData['probe_event_ids'],
            ];

            $evidence = [
                'contract_version' => 'v2_advanced_probe_evidence_v1',
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'run_id' => '88888888-8888-4888-8888-888888888888',
                'client_event_id' => '99999999-9999-4999-8999-999999999999',
                'schema_name' => 'accounting_p8_tmp_0123456789ab',
                'handoff_sha256' => str_repeat('a', 64),
                'backend_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
                'android_publication_head' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
                'android_executable_head' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
                'scenarios' => [],
            ];

            $expectedPins = [
                'expectedScope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'backendExecutionHead' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
                'clientPublicationHead' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
                'clientExecutableHead' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
                'expectedProbeReportsManifestSha' => $traversalSha,
                'probeReportsManifestPath' => $manifestFile,
                'advancedControlPacketPath' => $acpFile,
                'expectedAdvancedControlPacketSha' => $acpSha,
            ];

            $travRes = DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson($evidence, $expectedPins, $trustedDbReport);
            $this->assertFalse($travRes['is_valid']);
            $this->assertStringContainsString('containment check failed', implode('; ', $travRes['errors']));

            // 2. Semantic rejection: valid file hashes and pins, but report contains an unhealthy reconciler
            $rawReconData = [
                'all_healthy' => false, // UNHEALTHY
                'total_issue_count' => 1,
                'reconciler_results' => [
                    'AccountingReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                    'InventoryReconciliationService' => ['healthy' => false, 'issue_count' => 1],
                    'SalesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                    'PayablesReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                    'MoneyReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                    'Phase7ReconciliationService' => ['healthy' => true, 'issue_count' => 0],
                ],
            ];
            $rawReconBytes = json_encode($rawReconData) . "\n";
            $rawReconSha = hash('sha256', $rawReconBytes);

            $badReport = [
                'probe_name' => 'rollback_permission_denial',
                'client_event_id' => '11111111-1111-4111-8111-111111111111',
                'expected_http_status' => 403,
                'actual_http_status' => 403,
                'status_matched' => true,
                'schema_name' => 'accounting_p8_tmp_0123456789ab',
                'company_id' => 1,
                'backend_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
                'handoff_sha256' => str_repeat('a', 64),
                'economic_delta_zero' => true,
                'zero_delta_verified' => true,
                'reconciliations_healthy' => false,
                'total_issue_count' => 1,
                'reconciliation_artifact_sha256' => $rawReconSha,
                'baseline_metrics' => self::createCanonicalRollbackSnapshotFixture(),
                'post_metrics' => self::createCanonicalRollbackSnapshotFixture(),
            ];
            $badReportBytes = json_encode($badReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            $badReportSha = hash('sha256', $badReportBytes);

            $repFile1 = $tmpDir . '/probe_rollback_permission_denial_report.json';
            $recFile1 = $tmpDir . '/probe_rollback_permission_denial_reconciliation.json';
            file_put_contents($repFile1, $badReportBytes);
            file_put_contents($recFile1, $rawReconBytes);
            chmod($repFile1, 0600);
            chmod($recFile1, 0600);

            $semanticManifest = [
                'rollback_permission_denial' => [
                    'probe_name' => 'rollback_permission_denial',
                    'client_event_id' => '11111111-1111-4111-8111-111111111111',
                    'report_file' => 'probe_rollback_permission_denial_report.json',
                    'report_sha256' => $badReportSha,
                    'reconciliation_file' => 'probe_rollback_permission_denial_reconciliation.json',
                    'reconciliation_sha256' => $rawReconSha,
                    'expected_http_status' => 403,
                    'actual_http_status' => 403,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true, // Liar boolean in manifest
                ],
                'rollback_insufficient_stock' => [
                    'probe_name' => 'rollback_insufficient_stock',
                    'client_event_id' => '22222222-2222-4222-8222-222222222222',
                    'report_file' => 'probe_rollback_insufficient_stock_report.json',
                    'report_sha256' => str_repeat('3', 64),
                    'reconciliation_file' => 'probe_rollback_insufficient_stock_reconciliation.json',
                    'reconciliation_sha256' => str_repeat('4', 64),
                    'expected_http_status' => 422,
                    'actual_http_status' => 422,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ],
                'rollback_controlled_after_posting' => [
                    'probe_name' => 'rollback_controlled_after_posting',
                    'client_event_id' => '33333333-3333-4333-8333-333333333333',
                    'report_file' => 'probe_rollback_controlled_after_posting_report.json',
                    'report_sha256' => str_repeat('5', 64),
                    'reconciliation_file' => 'probe_rollback_controlled_after_posting_reconciliation.json',
                    'reconciliation_sha256' => str_repeat('6', 64),
                    'expected_http_status' => 500,
                    'actual_http_status' => 500,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ],
            ];
            $semanticBytes = json_encode($semanticManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($manifestFile, $semanticBytes);
            $semanticSha = hash('sha256', $semanticBytes);

            $trustedDbReport['rollback_probes_manifest'] = $semanticManifest;
            $expectedPins['expectedProbeReportsManifestSha'] = $semanticSha;

            $semRes = DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson($evidence, $expectedPins, $trustedDbReport);
            $this->assertFalse($semRes['is_valid']);
            $this->assertStringContainsString('semantic verification failed for \'rollback_permission_denial\'', implode('; ', $semRes['errors']));
        } finally {
            foreach (glob($tmpDir . '/*') as $f) {
                @unlink($f);
            }
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }
    }

    #[Test]
    public function test_advanced_scope_requires_mandatory_acp_in_compose_and_validation(): void
    {
        $tmpDir = sys_get_temp_dir() . '/test_acp_req_' . bin2hex(random_bytes(6));
        mkdir($tmpDir, 0700, true);

        try {
            $base = self::createCanonicalAdvancedFlowFixture($tmpDir);
            $evidence = $base['evidence'];
            $trustedReport = $base['trustedDbReport'];
            $pins = $base['expectedPins'];
            $manifest = $base['manifest'];
            $metadataWithoutAcp = $base['metadata'];
            unset($metadataWithoutAcp['advancedControlPacketData'], $metadataWithoutAcp['advancedControlPacketSha256'], $metadataWithoutAcp['advancedControlPacketPath']);

            // 1. composeTrustedDbReport in advanced scope throws without ACP
            $threw = false;
            try {
                DisposableFinancialPostRunInspector::composeTrustedDbReport(
                    $metadataWithoutAcp,
                    self::createCanonicalRollbackSnapshotFixture(),
                    ['is_canonical_delta_valid' => true, 'gl_batches_balanced' => true, 'deltas' => [], 'canonical_graph' => []],
                    ['all_healthy' => true, 'reconciler_results' => []],
                    $manifest
                );
            } catch (\InvalidArgumentException $e) {
                $threw = true;
                $this->assertStringContainsString('Missing mandatory advancedControlPacketData', $e->getMessage());
            }
            $this->assertTrue($threw, 'composeTrustedDbReport did not throw for missing ACP');

            // 2. validateAdvancedProbeEvidenceJson refuses when ACP pins omitted
            $pinsWithoutAcp = $pins;
            unset($pinsWithoutAcp['advancedControlPacketPath'], $pinsWithoutAcp['expectedAdvancedControlPacketSha']);
            $resNoAcp = DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson($evidence, $pinsWithoutAcp, $trustedReport);
            $this->assertFalse($resNoAcp['is_valid']);
            $this->assertStringContainsString('Mandatory advancedControlPacketPath is missing in advanced scope', implode('; ', $resNoAcp['errors']));

            // 3. Insecure ACP file (symlink) is rejected
            $symlinkDir = sys_get_temp_dir() . '/test_acp_symlink_' . bin2hex(random_bytes(6));
            mkdir($symlinkDir, 0700, true);
            $acpSymlink = $symlinkDir . '/advanced_control_packet.json';
            symlink($base['acpFile'], $acpSymlink);

            try {
                $pinsWithSymlinkAcp = array_merge($pins, [
                    'advancedControlPacketPath' => $acpSymlink,
                    'expectedAdvancedControlPacketSha' => $base['acpSha'],
                    'expectedAcpParentDir' => $symlinkDir,
                ]);
                $resSymlink = DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson($evidence, $pinsWithSymlinkAcp, $trustedReport);
                $this->assertFalse($resSymlink['is_valid']);
                $this->assertStringContainsString('SYMLINK_REJECTED', implode('; ', $resSymlink['errors']));
            } finally {
                @unlink($acpSymlink);
                @rmdir($symlinkDir);
            }

            // 4. ACP SHA mismatch is rejected on canonical basename
            $pinsWithBadSha = array_merge($pins, [
                'expectedAdvancedControlPacketSha' => str_repeat('0', 64),
            ]);
            $resBadSha = DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson($evidence, $pinsWithBadSha, $trustedReport);
            $this->assertFalse($resBadSha['is_valid']);
            $this->assertStringContainsString('SHA256_MISMATCH', implode('; ', $resBadSha['errors']));
        } finally {
            foreach (glob($tmpDir . '/*') as $f) {
                @unlink($f);
            }
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }
    }

    #[Test]
    public function test_advanced_validator_refuses_self_consistent_wrong_event_reports_and_index(): void
    {
        $tmpDir = sys_get_temp_dir() . '/test_wrong_event_' . bin2hex(random_bytes(6));
        mkdir($tmpDir, 0700, true);

        try {
            $base = self::createCanonicalAdvancedFlowFixture($tmpDir);
            $evidence = $base['evidence'];
            $expectedPins = $base['expectedPins'];
            $trustedReport = $base['trustedDbReport'];
            $manifest = $base['manifest'];

            // Craft self-consistent attacker report and manifest for probe 1 with mismatched UUID
            $wrongEventId1 = '44444444-4444-4444-8444-444444444444';
            $repFile1 = $tmpDir . '/probe_rollback_permission_denial_report.json';
            $rawReconFile1 = $tmpDir . '/probe_rollback_permission_denial_reconciliation.json';
            $rawReconContent = (string) file_get_contents($rawReconFile1);

            $repData1 = [
                'probe_name' => 'rollback_permission_denial',
                'client_event_id' => $wrongEventId1, // WRONG event ID
                'expected_http_status' => 403,
                'actual_http_status' => 403,
                'status_matched' => true,
                'schema_name' => 'accounting_p8_tmp_0123456789ab',
                'company_id' => 1,
                'backend_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
                'handoff_sha256' => str_repeat('a', 64),
                'economic_delta_zero' => true,
                'zero_delta_verified' => true,
                'reconciliations_healthy' => true,
                'total_issue_count' => 0,
                'reconciliation_artifact_sha256' => hash_file('sha256', $rawReconFile1),
                'baseline_metrics' => self::createCanonicalRollbackSnapshotFixture(),
                'post_metrics' => self::createCanonicalRollbackSnapshotFixture(),
            ];
            $repBytes1 = json_encode($repData1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($repFile1, $repBytes1);
            chmod($repFile1, 0600);

            $manifest['rollback_permission_denial']['client_event_id'] = $wrongEventId1;
            $manifest['rollback_permission_denial']['report_sha256'] = hash('sha256', $repBytes1);

            $manifestBytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            $manifestFile = $base['manifestFile'];
            file_put_contents($manifestFile, $manifestBytes);
            chmod($manifestFile, 0600);
            $manifestSha = hash('sha256', $manifestBytes);

            $expectedPins['expectedProbeReportsManifestSha'] = $manifestSha;
            $expectedPins['actualProbeReportsManifestSha'] = $manifestSha;
            $trustedReport['rollback_probes_manifest'] = $manifest;

            // Recompute report_digest so that report_digest is valid and does not mask the ACP check
            $core = [
                'scope' => $trustedReport['scope'],
                'run_id' => $trustedReport['run_id'],
                'event_id' => $trustedReport['event_id'],
                'schema_name' => $trustedReport['schema_name'],
                'company_id' => $trustedReport['company_id'],
                'source_head' => $trustedReport['source_head'],
                'handoff_digest' => $trustedReport['handoff_digest'],
                'baseline_hash' => $trustedReport['baseline_hash'],
                'baseline_metrics' => $trustedReport['baseline_metrics'],
                'deltas' => $trustedReport['deltas'],
                'canonical_graph' => $trustedReport['canonical_graph'],
                'is_canonical_delta_valid' => $trustedReport['is_canonical_delta_valid'],
                'gl_batches_balanced' => $trustedReport['gl_batches_balanced'],
                'all_healthy' => $trustedReport['all_healthy'],
                'reconciler_results' => $trustedReport['reconciler_results'],
                'rollback_probes_manifest' => $manifest,
                'advanced_control_packet_digest' => $trustedReport['advanced_control_packet_digest'],
                'probe_event_ids' => $trustedReport['probe_event_ids'],
            ];
            $trustedReport['report_digest'] = DisposableFinancialPostRunInspector::computeReportDigest($core);
            $expectedPins['expectedReportDigest'] = $trustedReport['report_digest'];

            $res = DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson($evidence, $expectedPins, $trustedReport);
            $this->assertFalse($res['is_valid']);
            $errString = implode('; ', $res['errors']);
            $this->assertStringContainsString("Manifest entry client_event_id for 'rollback_permission_denial' mismatch vs verified ACP", $errString);
            $this->assertStringContainsString("Referenced probe report client_event_id for 'rollback_permission_denial' mismatch vs verified ACP", $errString);
        } finally {
            foreach (glob($tmpDir . '/*') as $f) {
                @unlink($f);
            }
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }
    }

    #[Test]
    public function test_validate_advanced_control_packet_refuses_primary_event_id_collision(): void
    {
        $primaryEventId = '99999999-9999-4999-8999-999999999999';

        $collidingAcp = [
            'probe_event_ids' => [
                'permission_denial' => '99999999-9999-4999-8999-999999999999', // Collides with primary target
                'insufficient_stock' => '22222222-2222-4222-8222-222222222222',
                'controlled_fault_after_posting' => '33333333-3333-4333-8333-333333333333',
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("collides with primary target event ID '{$primaryEventId}'");
        DisposableFinancialPostRunInspector::validateAdvancedControlPacket($collidingAcp, $primaryEventId);
    }

    #[Test]
    public function test_containment_refusal_stops_without_external_reads_on_sentinel(): void
    {
        $tmpDir = sys_get_temp_dir() . '/test_containment_' . bin2hex(random_bytes(6));
        $sentinelDir = sys_get_temp_dir() . '/test_sentinel_' . bin2hex(random_bytes(6));
        mkdir($tmpDir, 0700, true);
        mkdir($sentinelDir, 0700, true);

        $sentinelFile = $sentinelDir . '/probe_rollback_permission_denial_report.json';
        file_put_contents($sentinelFile, '{"sensitive_secret":"sentinel_canary"}');
        chmod($sentinelFile, 0600);

        $sentinelReadAttempted = false;
        DisposableFinancialPostRunInspector::$negativeReadGuard = function (string $path, string $context) use (&$sentinelReadAttempted, $sentinelFile): void {
            if (realpath($path) === realpath($sentinelFile)) {
                $sentinelReadAttempted = true;
            }
        };

        try {
            // Attempt to load sentinel file through containment check pointing to $tmpDir
            $threw = false;
            try {
                DisposableFinancialPostRunInspector::loadSecureContainedFile(
                    $sentinelFile,
                    $tmpDir,
                    'probe_rollback_permission_denial_report.json',
                    'SENTINEL_PROBE'
                );
            } catch (\RuntimeException $e) {
                $threw = true;
                $this->assertStringContainsString('CONTAINMENT_VIOLATION', $e->getMessage());
            }
            $this->assertTrue($threw, 'Containment check did not throw for out-of-root file');
            $this->assertFalse($sentinelReadAttempted, 'Negative read guard was triggered; out-of-root file was read despite containment failure!');
        } finally {
            DisposableFinancialPostRunInspector::$negativeReadGuard = null;
            @unlink($sentinelFile);
            @rmdir($sentinelDir);
            foreach (glob($tmpDir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    #[Test]
    public function test_compose_trusted_db_report_in_basic_scope_preserves_canonical_keys_without_aliases(): void
    {
        $metadata = [
            'scope' => 'ROOT_AUTHORIZED_PAIRED_EXECUTION',
            'runId' => '88888888-8888-4888-8888-888888888888',
            'eventId' => '99999999-9999-4999-8999-999999999999',
            'schemaName' => 'accounting_p8_tmp_0123456789ab',
            'companyId' => 1,
            'sourceHead' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
            'handoffDigest' => str_repeat('a', 64),
        ];

        $report = DisposableFinancialPostRunInspector::composeTrustedDbReport(
            $metadata,
            self::createCanonicalRollbackSnapshotFixture(),
            ['is_canonical_delta_valid' => true, 'gl_batches_balanced' => true, 'deltas' => [], 'canonical_graph' => []],
            ['all_healthy' => true, 'reconciler_results' => []]
        );

        $expectedKeys = [
            'scope',
            'run_id',
            'event_id',
            'schema_name',
            'company_id',
            'source_head',
            'handoff_digest',
            'baseline_hash',
            'baseline_metrics',
            'deltas',
            'canonical_graph',
            'is_canonical_delta_valid',
            'gl_batches_balanced',
            'all_healthy',
            'reconciler_results',
            'report_digest',
            'generated_at',
        ];
        $actualKeys = array_keys($report);
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys, 'Basic composeTrustedDbReport must return exact canonical keys.');

        $this->assertArrayNotHasKey('client_event_id', $report);
        $this->assertArrayNotHasKey('backend_execution_head_sha', $report);
        $this->assertArrayNotHasKey('candidate_handoff_digest', $report);

        // Conflicting duplicate aliases in advanced validator are strictly refused
        $trustedWithConflict = $report;
        $trustedWithConflict['backend_execution_head_sha'] = 'conflicting_head_sha';
        $res = DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson(
            ['contract_version' => 'v2_advanced_probe_evidence_v1', 'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION'],
            ['backendExecutionHead' => $metadata['sourceHead']],
            $trustedWithConflict
        );
        $this->assertFalse($res['is_valid']);
        $this->assertStringContainsString('conflicting backend_execution_head_sha and source_head', implode('; ', $res['errors']));
    }

    #[Test]
    public function test_supervisor_and_cli_command_builders_wire_mandatory_acp_flags(): void
    {
        $cliPath = dirname(__DIR__, 3) . '/Support/Harness/financial-inspector-cli.php';
        $this->assertFileExists($cliPath);

        $tmpDir = sys_get_temp_dir() . '/test_cli_flags_' . bin2hex(random_bytes(6));
        mkdir($tmpDir, 0700, true);

        try {
            $baselineFile = $tmpDir . '/baseline.json';
            $deltaFile = $tmpDir . '/delta.json';
            $reconcileFile = $tmpDir . '/reconcile.json';
            $manifestFile = $tmpDir . '/probe_reports_manifest.json';
            $dbReportFile = $tmpDir . '/trusted_db_report.json';
            $evidenceFile = $tmpDir . '/evidence.json';

            $baselineData = self::createCanonicalRollbackSnapshotFixture();
            $deltaData = ['is_canonical_delta_valid' => true, 'gl_batches_balanced' => true, 'deltas' => [], 'canonical_graph' => []];
            $reconcileData = ['all_healthy' => true, 'reconciler_results' => []];
            $manifestData = [];
            $dbReportData = [
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'run_id' => '88888888-8888-4888-8888-888888888888',
                'event_id' => '99999999-9999-4999-8999-999999999999',
                'schema_name' => 'accounting_p8_tmp_0123456789ab',
                'company_id' => 1,
                'source_head' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
                'handoff_digest' => str_repeat('a', 64),
                'report_digest' => str_repeat('d', 64),
            ];
            $evidenceData = [
                'contract_version' => 'v2_advanced_probe_evidence_v1',
                'scope' => 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                'run_id' => '88888888-8888-4888-8888-888888888888',
                'client_event_id' => '99999999-9999-4999-8999-999999999999',
                'clientPublicationHead' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
                'clientExecutableHead' => DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
                'backendHead' => '4815546d4d00ffe82d428fb490981f96b60cf5d9',
                'manifestDigest' => str_repeat('a', 64),
                'lifecycleGrant' => 'FINANCIAL_PAIRED_RUN',
                'overallStatus' => 'PASS',
                'baseUrl' => 'http://127.0.0.1:8000',
                'ttlExpiresAt' => '2026-11-16T12:00:00Z',
                'timestamp' => '2026-11-16T08:00:00Z',
                'scenarios' => [],
            ];

            file_put_contents($baselineFile, json_encode($baselineData) . "\n");
            file_put_contents($deltaFile, json_encode($deltaData) . "\n");
            file_put_contents($reconcileFile, json_encode($reconcileData) . "\n");
            file_put_contents($manifestFile, json_encode($manifestData) . "\n");
            file_put_contents($dbReportFile, json_encode($dbReportData) . "\n");
            file_put_contents($evidenceFile, json_encode($evidenceData) . "\n");

            foreach ([$baselineFile, $deltaFile, $reconcileFile, $manifestFile, $dbReportFile, $evidenceFile] as $f) {
                chmod($f, 0600);
            }

            // 1. CLI --compose-trusted-report in advanced scope without --acp-file exits 1
            $proc1 = new \Symfony\Component\Process\Process([
                PHP_BINARY,
                $cliPath,
                '--compose-trusted-report',
                '--baseline-file=' . $baselineFile,
                '--delta-file=' . $deltaFile,
                '--reconcile-file=' . $reconcileFile,
                '--schema=accounting_p8_tmp_0123456789ab',
                '--company-id=1',
                '--run-id=88888888-8888-4888-8888-888888888888',
                '--expected-backend-head=4815546d4d00ffe82d428fb490981f96b60cf5d9',
                '--expected-handoff-digest=' . str_repeat('a', 64),
                '--expected-event-id=99999999-9999-4999-8999-999999999999',
                '--expected-scope=ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                '--probe-reports-manifest=' . $manifestFile,
                '--expected-probe-manifest-sha=' . hash_file('sha256', $manifestFile),
            ]);
            $proc1->run();
            $this->assertSame(1, $proc1->getExitCode());
            $this->assertStringContainsString('requires --acp-file', $proc1->getErrorOutput());

            // 2. CLI --validate-evidence with --advanced-probes without --acp-file exits 1
            $proc2 = new \Symfony\Component\Process\Process([
                PHP_BINARY,
                $cliPath,
                '--validate-evidence=' . $evidenceFile,
                '--trusted-db-report=' . $dbReportFile,
                '--expected-evidence-sha=' . hash_file('sha256', $evidenceFile),
                '--expected-db-report-sha=' . hash_file('sha256', $dbReportFile),
                '--expected-backend-head=4815546d4d00ffe82d428fb490981f96b60cf5d9',
                '--expected-android-pub=' . DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
                '--expected-android-exe=' . DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
                '--expected-handoff-digest=' . str_repeat('a', 64),
                '--expected-baseline-hash=' . str_repeat('b', 64),
                '--expected-payload-digest=' . str_repeat('c', 64),
                '--expected-scope=ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION',
                '--schema=accounting_p8_tmp_0123456789ab',
                '--company-id=1',
                '--run-id=88888888-8888-4888-8888-888888888888',
                '--expected-event-id=99999999-9999-4999-8999-999999999999',
                '--advanced-probes',
                '--probe-reports-manifest=' . $manifestFile,
                '--expected-probe-manifest-sha=' . hash_file('sha256', $manifestFile),
            ]);
            $proc2->run();
            $this->assertSame(1, $proc2->getExitCode());
            $this->assertStringContainsString('--acp-file=<path> is mandatory', $proc2->getErrorOutput());
        } finally {
            foreach (glob($tmpDir . '/*') as $f) {
                @unlink($f);
            }
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }
    }
}
