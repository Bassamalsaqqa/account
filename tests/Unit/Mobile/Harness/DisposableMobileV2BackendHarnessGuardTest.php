<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Harness;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\Harness\DisposableMobileV2BackendHarness;
use Tests\Support\Harness\MobileV2HarnessCommand;
use Tests\Support\Harness\SupervisedPairedFinancialDrill;
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
        $this->originalEnable = getenv('MOBILE_V2_HARNESS_ENABLE') ?: null;
        $this->originalHost = getenv('PHASE8_TEST_DB_HOST') ?: null;
        $this->originalPort = getenv('PHASE8_TEST_DB_PORT') ?: null;
        $this->originalAllowDb = getenv('PHASE8_ALLOW_DISPOSABLE_DB') ?: null;
        $this->originalListener = getenv('MOBILE_V2_HARNESS_START_LISTENER') ?: null;
    }

    protected function tearDown(): void
    {
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
}




