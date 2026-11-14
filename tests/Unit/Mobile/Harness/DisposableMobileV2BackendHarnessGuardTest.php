<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Harness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\Harness\DisposableMobileV2BackendHarness;

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
    public function test_assert_pre_socket_invariants_rejects_empty_or_invalid_backend_head(): void
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
    public function test_assert_pre_socket_invariants_rejects_invalid_peer_android_head_format(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE=1');
        putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
        putenv('PHASE8_TEST_DB_PORT=3342');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Harness refused: peerAndroidHead must be a valid 40-hex SHA, got 'not-a-valid-sha'.");

        DisposableMobileV2BackendHarness::assertPreSocketInvariants(
            expectedBackendHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            peerAndroidHead: 'not-a-valid-sha'
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
    public function test_dual_manifest_pin_refuses_unset_peer_android_head_in_manifest(): void
    {
        $manifestPath = dirname(__DIR__, 4).'/docs/integration/mobile-v2-integration-manifest.json';
        $actualSha = hash_file('sha256', $manifestPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pairing manifest has unapproved peer Android HEAD/');

        DisposableMobileV2BackendHarness::assertDualManifestPin(
            backendHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
            peerAndroidHead: '1111111111111111111111111111111111111111',
            manifestPath: $manifestPath,
            manifestShaPin: (string) $actualSha,
        );
    }

    #[Test]
    public function test_start_fails_closed_without_start_listener_env_flag(): void
    {
        putenv('MOBILE_V2_HARNESS_START_LISTENER');

        $harness = new DisposableMobileV2BackendHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness refused: must be provisioned before start');

        $harness->start(
            peerAndroidHead: '0000000000000000000000000000000000000000',
            backendHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
        );
    }

    #[Test]
    public function test_start_refuses_when_harness_nonce_is_unset(): void
    {
        $harness = new DisposableMobileV2BackendHarness;

        // Inject provisioned state via reflection while leaving harnessNonce null
        $ref = new ReflectionClass($harness);
        $propProv = $ref->getProperty('provisioned');
        $propProv->setValue($harness, true);

        // Dummy non-null schema to reach nonce check
        $propSchema = $ref->getProperty('schema');
        $schemaMock = $this->createMock(\Tests\Support\DisposableMariaDbSchema::class);
        $propSchema->setValue($harness, $schemaMock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness start refused: harness nonce is unset. Harness must be provisioned before start.');

        $harness->start(
            peerAndroidHead: '0000000000000000000000000000000000000000',
            backendHead: DisposableMobileV2BackendHarness::QUALIFIED_EXECUTION_HEAD,
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
        $this->assertTrue(true);
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
}
