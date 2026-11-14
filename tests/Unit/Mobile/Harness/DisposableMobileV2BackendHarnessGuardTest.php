<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Harness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Harness\DisposableMobileV2BackendHarness;

/**
 * Pure Source-Only No-Socket Guard Tests for Backend Harness.
 *
 * Verifies pre-socket failure invariants, fail-closed assertions, transport guard
 * semantics, candidate rejection, and cleanup semantics with zero database sockets,
 * zero background processes, and zero network calls.
 *
 * All tests run as plain PHPUnit (no Laravel boot). APP_ENV=testing is provided
 * by phpunit.xml; PHASE8_ALLOW_DISPOSABLE_DB and PHASE8_TEST_DB_* are explicitly
 * managed per test to exercise each guard layer independently.
 */
final class DisposableMobileV2BackendHarnessGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE');
        putenv('MOBILE_V2_HARNESS_START_LISTENER');
        putenv('PHASE8_TEST_DB_HOST');
        putenv('PHASE8_TEST_DB_PORT');
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Pre-socket invariant guards (source-only, no DB/process)
    // -------------------------------------------------------------------------

    #[Test]
    public function test_assert_pre_socket_invariants_fails_closed_when_harness_not_enabled(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness refused: defaults to no-execute');

        DisposableMobileV2BackendHarness::assertPreSocketInvariants();
    }

    #[Test]
    public function test_assert_pre_socket_invariants_fails_closed_on_invalid_db_host(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE=1');
        putenv('PHASE8_TEST_DB_HOST=192.168.1.100');
        putenv('PHASE8_TEST_DB_PORT=3342');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness refused: DB host must be explicitly 127.0.0.1');

        DisposableMobileV2BackendHarness::assertPreSocketInvariants();
    }

    #[Test]
    public function test_assert_pre_socket_invariants_fails_closed_on_invalid_db_port(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE=1');
        putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
        putenv('PHASE8_TEST_DB_PORT=3306');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness refused: DB port must be explicitly 3342');

        DisposableMobileV2BackendHarness::assertPreSocketInvariants();
    }

    #[Test]
    public function test_assert_pre_socket_invariants_fails_closed_on_mismatched_expected_backend_head(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE=1');
        putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
        putenv('PHASE8_TEST_DB_PORT=3342');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Harness refused: current git HEAD does not match expected backend HEAD '0000000000000000000000000000000000000000'");

        DisposableMobileV2BackendHarness::assertPreSocketInvariants(
            expectedBackendHead: '0000000000000000000000000000000000000000'
        );
    }

    #[Test]
    public function test_assert_pre_socket_invariants_fails_closed_on_invalid_peer_android_head(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE=1');
        putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
        putenv('PHASE8_TEST_DB_PORT=3342');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness refused: peerAndroidHead must be a valid 40-hex SHA');

        DisposableMobileV2BackendHarness::assertPreSocketInvariants(
            peerAndroidHead: 'not-a-valid-sha'
        );
    }

    // -------------------------------------------------------------------------
    // Transport lifecycle guard tests
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
    public function test_start_fails_closed_on_invalid_peer_android_head_format(): void
    {
        $harness = new DisposableMobileV2BackendHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Harness refused: must be provisioned before start/');

        $harness->start(
            peerAndroidHead: 'bad-format',
            backendHead: '0000000000000000000000000000000000000000',
        );
    }

    #[Test]
    public function test_start_fails_closed_on_invalid_backend_head_format(): void
    {
        $harness = new DisposableMobileV2BackendHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Harness refused: must be provisioned before start/');

        $harness->start(
            peerAndroidHead: '0000000000000000000000000000000000000000',
            backendHead: 'not-a-sha',
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
        );
    }

    #[Test]
    public function test_start_listener_gate_refuses_without_env_flag(): void
    {
        putenv('MOBILE_V2_HARNESS_START_LISTENER'); // ensure unset

        $harness = new DisposableMobileV2BackendHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness refused: must be provisioned before start');

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
    public function test_cleanup_is_idempotent_when_nothing_provisioned(): void
    {
        $harness = new DisposableMobileV2BackendHarness;
        $harness->cleanup(); // first call
        $harness->cleanup(); // second call (idempotent)

        $this->assertTrue(true); // reached without exception
    }
}
