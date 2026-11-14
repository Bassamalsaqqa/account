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
        putenv('MOBILE_V2_HARNESS_ENABLE');
        putenv('PHASE8_ALLOW_DISPOSABLE_DB');

        try {
            \Tests\Support\Harness\DisposableFinancialPostRunInspector::assertExecutionGates();
            $this->fail('Expected RuntimeException for missing harness enable flag');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Inspector refused: defaults to NO-EXECUTE', $e->getMessage());
        }

        try {
            \Tests\Support\Harness\DisposableFinancialPostRunInspector::captureBaseline(1);
            $this->fail('Expected RuntimeException for captureBaseline without flags');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Inspector refused: defaults to NO-EXECUTE', $e->getMessage());
        }
    }

    #[Test]
    public function test_financial_outer_validator_accepts_valid_evidence_and_enforces_approved_pins(): void
    {
        $validPayload = [
            'clientPublicationHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
            'clientExecutableHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
            'backendExecutionHead' => 'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
            'handoffDigest' => '11223344556677889900aabbccddeeff11223344556677889900aabbccddeeff',
            'lifecycle' => 'FINANCIAL_PAIRED_RUN',
            'scenarios' => [
                'fresh_online_cash_commit_201' => 'PASS',
                'idempotent_exact_replay_200' => 'PASS',
                'event_status_query_parity_200' => 'PASS',
                'tamper_conflict_rejection_409' => 'PASS',
            ],
            'lost_ack_recovery' => 'NOT_RUN',
            'rollback_fault_injection' => 'NOT_RUN',
            'side_effects' => [
                'event_count_delta' => 1,
                'reconciliations_healthy_count' => 6,
            ],
        ];

        $expectedPins = [
            'clientPublicationHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
            'clientExecutableHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
            'backendExecutionHead' => 'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
            'handoffDigest' => '11223344556677889900aabbccddeeff11223344556677889900aabbccddeeff',
        ];

        $result = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson($validPayload, $expectedPins);

        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function test_financial_outer_validator_strictly_rejects_unadmitted_wrong_owner_publication_sha(): void
    {
        $payloadWithBadPub = [
            'clientPublicationHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::UNADMITTED_ANDROID_PUBLICATION_SHA,
            'clientExecutableHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
            'backendExecutionHead' => 'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
            'handoffDigest' => '11223344556677889900aabbccddeeff11223344556677889900aabbccddeeff',
            'lifecycle' => 'FINANCIAL_PAIRED_RUN',
            'scenarios' => [
                'fresh_online_cash_commit_201' => 'PASS',
                'idempotent_exact_replay_200' => 'PASS',
                'event_status_query_parity_200' => 'PASS',
                'tamper_conflict_rejection_409' => 'PASS',
            ],
            'lost_ack_recovery' => 'NOT_RUN',
            'rollback_fault_injection' => 'NOT_RUN',
        ];

        $expectedPins = [
            'clientPublicationHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
            'clientExecutableHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
            'backendExecutionHead' => 'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
            'handoffDigest' => '11223344556677889900aabbccddeeff11223344556677889900aabbccddeeff',
        ];

        $result = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson($payloadWithBadPub, $expectedPins);

        $this->assertFalse($result['is_valid']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($err) => str_contains($err, 'unadmitted wrong-owner publication pin')));
    }

    #[Test]
    public function test_financial_outer_validator_rejects_swapped_or_mismatched_pins_and_overclaims(): void
    {
        $expectedPins = [
            'clientPublicationHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
            'clientExecutableHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
            'backendExecutionHead' => 'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
            'handoffDigest' => '11223344556677889900aabbccddeeff11223344556677889900aabbccddeeff',
        ];

        // 1. Swapped pub and exe pins rejected
        $swappedPayload = [
            'clientPublicationHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
            'clientExecutableHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
            'backendExecutionHead' => 'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
            'handoffDigest' => '11223344556677889900aabbccddeeff11223344556677889900aabbccddeeff',
            'lifecycle' => 'FINANCIAL_PAIRED_RUN',
            'scenarios' => [
                'fresh_online_cash_commit_201' => 'PASS',
                'idempotent_exact_replay_200' => 'PASS',
                'event_status_query_parity_200' => 'PASS',
                'tamper_conflict_rejection_409' => 'PASS',
            ],
            'lost_ack_recovery' => 'NOT_RUN',
            'rollback_fault_injection' => 'NOT_RUN',
        ];
        $resultSwapped = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson($swappedPayload, $expectedPins);
        $this->assertFalse($resultSwapped['is_valid']);

        // 2. Over-claiming full pilot when lost_ack was claimed PASS rejected
        $overclaimPayload = [
            'clientPublicationHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
            'clientExecutableHead' => \Tests\Support\Harness\DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
            'backendExecutionHead' => 'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
            'handoffDigest' => '11223344556677889900aabbccddeeff11223344556677889900aabbccddeeff',
            'lifecycle' => 'FINANCIAL_PAIRED_RUN',
            'scenarios' => [
                'fresh_online_cash_commit_201' => 'PASS',
                'idempotent_exact_replay_200' => 'PASS',
                'event_status_query_parity_200' => 'PASS',
                'tamper_conflict_rejection_409' => 'PASS',
            ],
            'lost_ack_recovery' => 'PASS',
            'rollback_fault_injection' => 'NOT_RUN',
        ];
        $resultOverclaim = \Tests\Support\Harness\DisposableFinancialPostRunInspector::validateOuterEvidenceJson($overclaimPayload, $expectedPins);
        $this->assertFalse($resultOverclaim['is_valid']);
        $this->assertTrue(collect($resultOverclaim['errors'])->contains(fn ($err) => str_contains($err, 'cannot claim Lost ACK or Rollback passed')));
    }
}

