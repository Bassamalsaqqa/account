<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Harness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\Harness\DisposableMobileV2BackendHarness;
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
            $this->expectExceptionMessage("Harness readiness scope refused: pairing manifest status is '".DisposableMobileV2BackendHarness::SCOPE_PROVISION_ONLY."'. Readiness probe requires explicit '".DisposableMobileV2BackendHarness::SCOPE_READINESS_ONLY."' scope.");
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
}
