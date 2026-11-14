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
 * Verifies pre-socket failure invariants, fail-closed assertions, and cleanup
 * semantics with zero database sockets, zero background processes, and zero network calls.
 */
final class DisposableMobileV2BackendHarnessGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE');
        putenv('MOBILE_V2_HARNESS_START_LISTENER');
        parent::tearDown();
    }

    #[Test]
    public function test_assert_pre_socket_invariants_fails_closed_when_harness_not_enabled(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE'); // Unset

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

        $invalidSha = '0000000000000000000000000000000000000000';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Harness refused: current git HEAD does not match expected backend HEAD '{$invalidSha}'");

        DisposableMobileV2BackendHarness::assertPreSocketInvariants(expectedBackendHead: $invalidSha);
    }

    #[Test]
    public function test_assert_pre_socket_invariants_fails_closed_on_invalid_peer_android_head(): void
    {
        putenv('MOBILE_V2_HARNESS_ENABLE=1');
        putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
        putenv('PHASE8_TEST_DB_PORT=3342');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness refused: peerAndroidHead must be a valid 40-hex SHA');

        DisposableMobileV2BackendHarness::assertPreSocketInvariants(peerAndroidHead: 'not-a-40-hex-sha');
    }

    #[Test]
    public function test_start_fails_closed_when_not_provisioned(): void
    {
        $harness = new DisposableMobileV2BackendHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Harness refused: must be provisioned before start');

        $harness->start(peerAndroidHead: '0000000000000000000000000000000000000000');
    }
}
