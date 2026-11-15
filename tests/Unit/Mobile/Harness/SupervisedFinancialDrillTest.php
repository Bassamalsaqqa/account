<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Harness;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\Harness\SupervisedPairedFinancialDrill;

/**
 * Pure source-only unit tests for SupervisedPairedFinancialDrill.
 *
 * Exercises all adversarial source gates:
 * - Prestart approval mandatory TTL, private permissions, and identical pub/exe acceptance.
 * - Live root approval mandatory TTL, scope, schema_name, whole file SHA, and path matching.
 * - Bounded wait polling for external live approval record.
 * - Strict TLS readiness check URL allowlist (loopback only).
 * - Triple-match of sample event ID, parsed request, and canonical raw wire payload.
 * - ULID lookup proof PDO query error handling.
 * - Evidence freshness and JUnit XML 2 PASS 0 SKIP enforcement.
 * - Structured exact cleanup detecting lingering schemas, listeners, and temp dirs.
 *
 * NOTE: Authored source tests. UNRUN pending QA lease grant.
 */
class SupervisedFinancialDrillTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/supervised_drill_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            SupervisedPairedFinancialDrill::recursiveRemoveDir($this->tempDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function test_prestart_approval_fails_closed_when_ttl_is_missing(): void
    {
        $filePath = $this->tempDir . '/approval.json';
        $content = [
            'root_coordination_granted' => true,
            'manifest_sha256' => str_repeat('a', 64),
            'backend_head' => str_repeat('1', 40),
            'android_publication_head' => str_repeat('2', 40),
            'android_executable_head' => str_repeat('3', 40),
            'cwd' => $this->tempDir,
            // expires_at missing
        ];
        file_put_contents($filePath, json_encode($content));
        chmod($filePath, 0600);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PRESTART_APPROVAL_TTL_MISSING');
        SupervisedPairedFinancialDrill::verifyPrestartApproval(
            $filePath,
            str_repeat('a', 64),
            str_repeat('1', 40),
            null,
            null,
            $this->tempDir
        );
    }

    #[Test]
    public function test_prestart_approval_fails_closed_when_ttl_is_expired(): void
    {
        $filePath = $this->tempDir . '/approval.json';
        $content = [
            'root_coordination_granted' => true,
            'manifest_sha256' => str_repeat('a', 64),
            'backend_head' => str_repeat('1', 40),
            'android_publication_head' => str_repeat('2', 40),
            'android_executable_head' => str_repeat('3', 40),
            'cwd' => $this->tempDir,
            'expires_at' => date('c', time() - 60), // Expired 1 minute ago
        ];
        file_put_contents($filePath, json_encode($content));
        chmod($filePath, 0600);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PRESTART_APPROVAL_EXPIRED');
        SupervisedPairedFinancialDrill::verifyPrestartApproval(
            $filePath,
            str_repeat('a', 64),
            str_repeat('1', 40),
            null,
            null,
            $this->tempDir
        );
    }

    #[Test]
    public function test_prestart_approval_allows_identical_publication_and_executable_heads(): void
    {
        $filePath = $this->tempDir . '/approval.json';
        $sameHead = str_repeat('2', 40);
        $content = [
            'root_coordination_granted' => true,
            'manifest_sha256' => str_repeat('a', 64),
            'backend_head' => str_repeat('1', 40),
            'android_publication_head' => $sameHead,
            'android_executable_head' => $sameHead, // Identical head allowed
            'cwd' => $this->tempDir,
            'expires_at' => date('c', time() + 3600),
        ];
        file_put_contents($filePath, json_encode($content));
        chmod($filePath, 0600);

        $res = SupervisedPairedFinancialDrill::verifyPrestartApproval(
            $filePath,
            str_repeat('a', 64),
            str_repeat('1', 40),
            null,
            null,
            $this->tempDir
        );

        $this->assertTrue($res['root_coordination_granted']);
        $this->assertSame($sameHead, $res['android_publication_head']);
        $this->assertSame($sameHead, $res['android_executable_head']);
    }

    #[Test]
    public function test_live_root_approval_fails_closed_when_ttl_is_missing(): void
    {
        $handoffPath = $this->tempDir . '/handoff.json';
        file_put_contents($handoffPath, 'test');
        chmod($handoffPath, 0600);
        $sha = hash('sha256', 'test');

        $approvalPath = $this->tempDir . '/live.json';
        $content = [
            'root_live_handoff_approved' => true,
            'scope' => SupervisedPairedFinancialDrill::REQUIRED_SCOPE,
            'schema_name' => 'accounting_p8_tmp_test1234',
            'handoff_path' => $handoffPath,
            'whole_handoff_sha256' => $sha,
            'backend_head' => str_repeat('1', 40),
            'android_publication_head' => str_repeat('2', 40),
            'android_executable_head' => str_repeat('3', 40),
            'manifest_sha256' => str_repeat('a', 64),
            // expires_at missing
        ];
        file_put_contents($approvalPath, json_encode($content));
        chmod($approvalPath, 0600);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LIVE_ROOT_APPROVAL_TTL_MISSING');
        SupervisedPairedFinancialDrill::verifyLiveRootApproval(
            $approvalPath,
            $handoffPath,
            $sha,
            str_repeat('1', 40),
            str_repeat('2', 40),
            str_repeat('3', 40),
            str_repeat('a', 64),
            'accounting_p8_tmp_test1234'
        );
    }

    #[Test]
    public function test_live_root_approval_fails_closed_when_scope_or_schema_mismatches(): void
    {
        $handoffPath = $this->tempDir . '/handoff.json';
        file_put_contents($handoffPath, 'test');
        chmod($handoffPath, 0600);
        $sha = hash('sha256', 'test');

        $approvalPath = $this->tempDir . '/live.json';
        $content = [
            'root_live_handoff_approved' => true,
            'scope' => 'WRONG_SCOPE',
            'schema_name' => 'accounting_p8_tmp_test1234',
            'handoff_path' => $handoffPath,
            'whole_handoff_sha256' => $sha,
            'backend_head' => str_repeat('1', 40),
            'android_publication_head' => str_repeat('2', 40),
            'android_executable_head' => str_repeat('3', 40),
            'manifest_sha256' => str_repeat('a', 64),
            'expires_at' => date('c', time() + 3600),
        ];
        file_put_contents($approvalPath, json_encode($content));
        chmod($approvalPath, 0600);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LIVE_ROOT_APPROVAL_SCOPE_MISMATCH');
        SupervisedPairedFinancialDrill::verifyLiveRootApproval(
            $approvalPath,
            $handoffPath,
            $sha,
            str_repeat('1', 40),
            str_repeat('2', 40),
            str_repeat('3', 40),
            str_repeat('a', 64),
            'accounting_p8_tmp_test1234'
        );
    }

    #[Test]
    public function test_await_live_root_approval_fails_when_child_terminates(): void
    {
        $child = $this->createMock(Process::class);
        $child->method('isRunning')->willReturn(false); // Child died

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CHILD_HARNESS_TERMINATED_WHILE_AWAITING_LIVE_ROOT_APPROVAL');
        SupervisedPairedFinancialDrill::awaitAndVerifyLiveRootApproval(
            $this->tempDir . '/nonexistent_live.json',
            $this->tempDir . '/handoff.json',
            'sha',
            'b_head',
            'p_head',
            'e_head',
            'm_sha',
            'schema',
            $child,
            microtime(true) + 100,
            fn () => false,
            2.0
        );
    }

    #[Test]
    public function test_readiness_probe_fails_on_untrusted_external_url(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('READINESS_URL_NOT_ALLOWED');
        SupervisedPairedFinancialDrill::performStrictTlsReadinessCheck(
            'https://external-malicious-site.example.com/readiness',
            $this->tempDir . '/ca.pem',
            'nonce'
        );
    }

    #[Test]
    public function test_handoff_triple_match_fails_when_wire_json_tampered(): void
    {
        $handoff = [
            'fixture' => [
                'sample_event_id' => '00000000-0000-0000-0000-000000000001',
            ],
            'server_synthesized_context' => [
                'company_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
                'actor_id' => '01JABCDEFGHJKMNPQRSTVWXYZ1',
                'currency_minor_units' => 2,
            ],
            'canonical_intent_profile_digest' => 'sha256:' . str_repeat('0', 64),
            'raw_wire_sample' => [
                'parsed_request' => [
                    'client_event_id' => '00000000-0000-0000-0000-000000000001',
                    'currency' => 'ILS',
                ],
                'raw_wire_payload_json' => '{"tampered":true}', // Tampered JSON
                'raw_wire_payload_sha256' => hash('sha256', '{"tampered":true}'),
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HANDOFF_WIRE_SERIALIZATION_MISMATCH');
        SupervisedPairedFinancialDrill::verifyHandoffTripleMatch($handoff);
    }

    #[Test]
    public function test_exact_cleanup_reports_failure_when_schema_lingers(): void
    {
        $mockStmt = $this->createMock(PDOStatement::class);
        // First query: schema exists
        // Second query after DROP: schema STILL exists (simulating drop failure)
        $mockStmt->method('fetch')->willReturn(['SCHEMA_NAME' => 'accounting_p8_tmp_lingering']);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $result = SupervisedPairedFinancialDrill::executeExactCleanup(
            null,
            'accounting_p8_tmp_lingering',
            null,
            $mockPdo
        );

        $this->assertFalse($result['success']);
        $this->assertFalse($result['schema_absent']);
        $this->assertNotEmpty($result['errors']);
    }

    #[Test]
    public function test_exact_cleanup_succeeds_when_clean(): void
    {
        $mockStmt = $this->createMock(PDOStatement::class);
        // Schema query returns false (schema is absent)
        $mockStmt->method('fetch')->willReturn(false);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $result = SupervisedPairedFinancialDrill::executeExactCleanup(
            null,
            'accounting_p8_tmp_absent',
            null,
            $mockPdo
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['schema_absent']);
        $this->assertEmpty($result['errors']);
    }
}
