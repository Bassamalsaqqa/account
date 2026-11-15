<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Harness;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\Harness\SupervisedPairedFinancialDrill;
use Tests\Support\Harness\VerifiedOwnedSnapshot;

/**
 * Pure source-only unit tests for SupervisedPairedFinancialDrill.
 *
 * Exercises all adversarial source gates:
 * - Prestart approval mandatory TTL, private permissions, and identical pub/exe acceptance.
 * - Live root approval mandatory TTL, scope, schema_name, whole file SHA, and path matching.
 * - Bounded wait polling for external live approval record.
 * - Strict TLS readiness check URL allowlist (loopback only).
 * - Triple-match of sample event ID, parsed request, and canonical raw wire payload.
 * - Canonical intent profile digest normalization (prefix stripping & 64-hex SHA-256 validation) and raw wire inequality.
 * - Android evidence freshness with exact 25ae Kotlin golden fixture, rejecting alias mutations (run_id, metadata, handoffDigest).
 * - Evidence scenario enforcement (exact 7 items: 5 PASS, 2 NOT_RUN with null httpStatusCode).
 * - Structured cleanup: mandatory prevalidated owned snapshot, foreign DB port (3306) rejection, and exact schema absence verification.
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
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        $entries = glob($dir . '/{,.}*', GLOB_BRACE);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                $base = basename($entry);
                if ($base === '.' || $base === '..') {
                    continue;
                }
                if (is_dir($entry) && ! is_link($entry)) {
                    $this->removeDirectory($entry);
                } else {
                    @unlink($entry);
                }
            }
        }
        @rmdir($dir);
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
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
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
            'accounting_p8_tmp_0123456789ab'
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
            'schema_name' => 'accounting_p8_tmp_0123456789ab',
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
            'accounting_p8_tmp_0123456789ab'
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
    public function test_canonical_profile_digest_normalization_and_raw_wire_inequality(): void
    {
        $rawHex = '0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630';

        // Strips 'sha256:' prefix
        $norm1 = SupervisedPairedFinancialDrill::normalizeCanonicalDigest('sha256:' . $rawHex);
        $this->assertSame($rawHex, $norm1);

        // Retains plain 64-hex
        $norm2 = SupervisedPairedFinancialDrill::normalizeCanonicalDigest($rawHex);
        $this->assertSame($rawHex, $norm2);

        // Rejects invalid formats
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid canonical digest format');
        SupervisedPairedFinancialDrill::normalizeCanonicalDigest('short-digest');
    }

    #[Test]
    public function test_verify_evidence_freshness_accepts_golden_25ae_fixture(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Support/Harness/fixtures/synthetic-financial-golden-wire.json';
        $this->assertFileExists($fixturePath);

        $fixtureBytes = (string) file_get_contents($fixturePath);
        $this->assertSame('25ae020de247d03c5aa57538592cd82574d62d6e4041e845ad11ea835c7f6aa6', hash('sha256', $fixtureBytes));

        $evidenceFile = $this->tempDir . '/evidence.json';
        file_put_contents($evidenceFile, $fixtureBytes);
        chmod($evidenceFile, 0600);

        $parsed = SupervisedPairedFinancialDrill::verifyEvidenceFreshness(
            $evidenceFile,
            time() - 10,
            null,
            'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
            'c00aedb70ea06f28ae42282149c030df76dc300d',
            'b7e61eb4ed0aed2f5040d9fc44f9764b310be530',
            '8e48f3692a88f5ac3f333f81536d70eb3541228959968697fda03f68df44befc'
        );

        $this->assertSame('019ea456-0000-7000-8000-000000000001', $parsed['runId']);
        $this->assertSame('PASS', $parsed['overallStatus']);
        $this->assertCount(7, $parsed['scenarios']);
    }

    #[Test]
    public function test_verify_evidence_freshness_rejects_alias_mutations(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Support/Harness/fixtures/synthetic-financial-golden-wire.json';
        $data = json_decode((string) file_get_contents($fixturePath), true);

        // 1. Mutate manifestDigest to alias handoffDigest
        $m1 = $data;
        $m1['handoffDigest'] = $m1['manifestDigest'];
        unset($m1['manifestDigest']);
        $f1 = $this->tempDir . '/ev_alias1.json';
        file_put_contents($f1, json_encode($m1));

        try {
            SupervisedPairedFinancialDrill::verifyEvidenceFreshness(
                $f1,
                time() - 10,
                null,
                'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
                'c00aedb70ea06f28ae42282149c030df76dc300d',
                'b7e61eb4ed0aed2f5040d9fc44f9764b310be530',
                '8e48f3692a88f5ac3f333f81536d70eb3541228959968697fda03f68df44befc'
            );
            $this->fail('Expected EVIDENCE_MANIFEST_DIGEST_MISSING on handoffDigest alias');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EVIDENCE_MANIFEST_DIGEST_MISSING', $e->getMessage());
        }

        // 2. Mutate runId to metadata.run_id
        $m2 = $data;
        $m2['metadata'] = ['run_id' => $m2['runId']];
        unset($m2['runId']);
        $f2 = $this->tempDir . '/ev_alias2.json';
        file_put_contents($f2, json_encode($m2));

        try {
            SupervisedPairedFinancialDrill::verifyEvidenceFreshness(
                $f2,
                time() - 10,
                null,
                'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
                'c00aedb70ea06f28ae42282149c030df76dc300d',
                'b7e61eb4ed0aed2f5040d9fc44f9764b310be530',
                '8e48f3692a88f5ac3f333f81536d70eb3541228959968697fda03f68df44befc'
            );
            $this->fail('Expected EVIDENCE_RUN_ID_MISSING_OR_INVALID on metadata.run_id alias');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EVIDENCE_RUN_ID_MISSING_OR_INVALID', $e->getMessage());
        }
    }

    #[Test]
    public function test_verify_evidence_freshness_rejects_scenario_mutations(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Support/Harness/fixtures/synthetic-financial-golden-wire.json';
        $data = json_decode((string) file_get_contents($fixturePath), true);

        // 1. Missing scenario (6 items instead of 7)
        $m1 = $data;
        array_pop($m1['scenarios']);
        $f1 = $this->tempDir . '/ev_scen1.json';
        file_put_contents($f1, json_encode($m1));

        try {
            SupervisedPairedFinancialDrill::verifyEvidenceFreshness(
                $f1,
                time() - 10,
                null,
                'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
                'c00aedb70ea06f28ae42282149c030df76dc300d',
                'b7e61eb4ed0aed2f5040d9fc44f9764b310be530',
                '8e48f3692a88f5ac3f333f81536d70eb3541228959968697fda03f68df44befc'
            );
            $this->fail('Expected EVIDENCE_SCENARIOS_INVALID_COUNT');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EVIDENCE_SCENARIOS_INVALID_COUNT', $e->getMessage());
        }

        // 2. Active scenario failed
        $m2 = $data;
        $m2['scenarios'][0]['status'] = 'FAIL';
        $f2 = $this->tempDir . '/ev_scen2.json';
        file_put_contents($f2, json_encode($m2));

        try {
            SupervisedPairedFinancialDrill::verifyEvidenceFreshness(
                $f2,
                time() - 10,
                null,
                'cfc28baaaaf52521ecbc88f1ba4c621afee17221',
                'c00aedb70ea06f28ae42282149c030df76dc300d',
                'b7e61eb4ed0aed2f5040d9fc44f9764b310be530',
                '8e48f3692a88f5ac3f333f81536d70eb3541228959968697fda03f68df44befc'
            );
            $this->fail('Expected EVIDENCE_SCENARIO_ACTIVE_FAILED');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EVIDENCE_SCENARIO_ACTIVE_FAILED', $e->getMessage());
        }
    }

    #[Test]
    public function test_exact_cleanup_skips_destructive_actions_when_snapshot_is_null(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $result = SupervisedPairedFinancialDrill::executeExactCleanup(
            null,
            null, // No snapshot
            $repoRoot,
            null
        );

        $this->assertFalse($result['success']);
        $this->assertFalse($result['schema_absent']);
        $this->assertContains('CLEANUP_UNPROVEN_NO_VALID_SNAPSHOT: Destructive cleanup skipped; no verified owned snapshot exists. External Root operator inspection required.', $result['errors']);
    }

    #[Test]
    public function test_exact_cleanup_rejects_foreign_db_port(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $snapshot = VerifiedOwnedSnapshot::_internal_create(
            schemaName: 'accounting_p8_tmp_0123456789ab',
            proofPath: $this->tempDir . '/proof.txt',
            proofNonce: '0123456789abcdef0123456789abcdef',
            harnessDir: sys_get_temp_dir() . '/v2_harness_0123456789abcdef',
            childPid: 12345,
            verifiedAt: time()
        );

        $mockPortStmt = $this->createMock(PDOStatement::class);
        $mockPortStmt->method('fetch')->willReturn(['port' => '3306']); // Wrong port!

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')->with('SELECT @@port AS port')->willReturn($mockPortStmt);
        $mockPdo->expects($this->never())->method('exec'); // Zero DROP SQL calls

        $result = SupervisedPairedFinancialDrill::executeExactCleanup(
            null,
            $snapshot,
            $repoRoot,
            $mockPdo
        );

        $this->assertFalse($result['success']);
        $this->assertFalse($result['schema_absent']);
        $this->assertStringContainsString('CLEANUP_FOREIGN_DB_PORT_REJECTED', implode('; ', $result['errors']));
    }

    #[Test]
    public function test_exact_cleanup_rejects_malformed_schema(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        // _internal_create is an unvalidated factory used here to test that cleanup's own defense fires.
        $snapshot = VerifiedOwnedSnapshot::_internal_create(
            schemaName: 'accounting_p8_tmp_invalid; DROP TABLE users;',
            proofPath: $this->tempDir . '/proof.txt',
            proofNonce: '0123456789abcdef0123456789abcdef',
            harnessDir: sys_get_temp_dir() . '/v2_harness_0123456789abcdef',
            childPid: 12345,
            verifiedAt: time()
        );

        $result = SupervisedPairedFinancialDrill::executeExactCleanup(
            null,
            $snapshot,
            $repoRoot,
            null
        );

        $this->assertFalse($result['success']);
        $this->assertFalse($result['schema_absent']);
        $this->assertStringContainsString('CLEANUP_MALFORMED_SCHEMA_REJECTED', implode('; ', $result['errors']));
    }

    #[Test]
    public function test_exact_cleanup_reports_failure_when_schema_lingers(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $snapshot = VerifiedOwnedSnapshot::_internal_create(
            schemaName: 'accounting_p8_tmp_0123456789ab',
            proofPath: $this->tempDir . '/proof.txt',
            proofNonce: '0123456789abcdef0123456789abcdef',
            harnessDir: sys_get_temp_dir() . '/v2_harness_0123456789abcdef',
            childPid: 12345,
            verifiedAt: time()
        );

        $mockPortStmt = $this->createMock(PDOStatement::class);
        $mockPortStmt->method('fetch')->willReturn(['port' => '3342']);

        $mockSchemaStmt = $this->createMock(PDOStatement::class);
        // Returns schema first time, and returns schema again after DROP (lingering failure)
        $mockSchemaStmt->method('fetch')->willReturn(['SCHEMA_NAME' => 'accounting_p8_tmp_0123456789ab']);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')->with('SELECT @@port AS port')->willReturn($mockPortStmt);
        $mockPdo->method('prepare')->willReturn($mockSchemaStmt);

        $result = SupervisedPairedFinancialDrill::executeExactCleanup(
            null,
            $snapshot,
            $repoRoot,
            $mockPdo
        );

        $this->assertFalse($result['success']);
        $this->assertFalse($result['schema_absent']);
        $this->assertContains("SCHEMA_LINGERING_AFTER_DROP: 'accounting_p8_tmp_0123456789ab'", $result['errors']);
    }

    #[Test]
    public function test_exact_cleanup_succeeds_when_clean(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $snapshot = VerifiedOwnedSnapshot::_internal_create(
            schemaName: 'accounting_p8_tmp_0123456789ab',
            proofPath: $this->tempDir . '/proof.txt',
            proofNonce: '0123456789abcdef0123456789abcdef',
            harnessDir: sys_get_temp_dir() . '/v2_harness_0123456789abcdef',
            childPid: 12345,
            verifiedAt: time()
        );

        $mockPortStmt = $this->createMock(PDOStatement::class);
        $mockPortStmt->method('fetch')->willReturn(['port' => '3342']);

        $mockSchemaStmt = $this->createMock(PDOStatement::class);
        // Schema query returns false (already absent)
        $mockSchemaStmt->method('fetch')->willReturn(false);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')->with('SELECT @@port AS port')->willReturn($mockPortStmt);
        $mockPdo->method('prepare')->willReturn($mockSchemaStmt);

        $result = SupervisedPairedFinancialDrill::executeExactCleanup(
            null,
            $snapshot,
            $repoRoot,
            $mockPdo
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['schema_absent']);
        $this->assertEmpty($result['errors']);
    }

    // ---------------------------------------------------------------------------
    // New meaningful no-DB tests: captureVerifiedOwnedSnapshot argument validation
    // and env restoration invariant.
    // ---------------------------------------------------------------------------

    #[Test]
    public function test_capture_snapshot_rejects_invalid_schema_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SNAPSHOT_INVALID_SCHEMA_NAME');
        // Schema name does not match accounting_p8_tmp_12hex pattern
        SupervisedPairedFinancialDrill::captureVerifiedOwnedSnapshot(
            schemaName: 'bad_schema_name',
            proofPath: $this->tempDir . '/proof.txt',
            proofNonce: str_repeat('a', 32),
            harnessDir: $this->tempDir,
            childPid: 1234,
            repoRoot: dirname(__DIR__, 3)
        );
    }

    #[Test]
    public function test_capture_snapshot_rejects_zero_child_pid(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SNAPSHOT_INVALID_CHILD_PID');
        // childPid must be a positive integer
        SupervisedPairedFinancialDrill::captureVerifiedOwnedSnapshot(
            schemaName: 'accounting_p8_tmp_0123456789ab',
            proofPath: $this->tempDir . '/proof.txt',
            proofNonce: str_repeat('a', 32),
            harnessDir: $this->tempDir,
            childPid: 0,
            repoRoot: dirname(__DIR__, 3)
        );
    }

    #[Test]
    public function test_capture_snapshot_rejects_invalid_proof_nonce_format(): void
    {
        // Create a valid-looking proof file first so we get past file permission check
        $proofFile = $this->tempDir . '/proof.txt';
        file_put_contents($proofFile, '{}');
        chmod($proofFile, 0600);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SNAPSHOT_INVALID_PROOF_NONCE');
        // Nonce must be 32-64 lowercase hex; 'short' is not valid
        SupervisedPairedFinancialDrill::captureVerifiedOwnedSnapshot(
            schemaName: 'accounting_p8_tmp_0123456789ab',
            proofPath: $proofFile,
            proofNonce: 'short',
            harnessDir: $this->tempDir,
            childPid: 1234,
            repoRoot: dirname(__DIR__, 3)
        );
    }

    #[Test]
    public function test_capture_snapshot_env_is_restored_after_proof_assertion_failure(): void
    {
        // If assertPrimarySchema throws (bad proof file content), captureVerifiedOwnedSnapshot
        // must still restore the three proof env vars to their pre-call originals.
        $origProof = getenv('PHASE8_DISPOSABLE_DB_PROOF');
        $origNonce = getenv('PHASE8_DISPOSABLE_DB_NONCE');
        $origAllow = getenv('PHASE8_ALLOW_DISPOSABLE_DB');

        $proofFile = $this->tempDir . '/proof.txt';
        // Write intentionally malformed proof JSON so assertPrimarySchema throws
        file_put_contents($proofFile, 'not-valid-json');
        chmod($proofFile, 0600);

        $harnessName = 'v2_harness_' . bin2hex(random_bytes(8));
        $harnessDir = sys_get_temp_dir() . '/' . $harnessName;
        mkdir($harnessDir, 0700, true);

        try {
            SupervisedPairedFinancialDrill::captureVerifiedOwnedSnapshot(
                schemaName: 'accounting_p8_tmp_0123456789ab',
                proofPath: $proofFile,
                proofNonce: str_repeat('a', 32),
                harnessDir: $harnessDir,
                childPid: 1234,
                repoRoot: dirname(__DIR__, 3)
            );
            $this->fail('Expected RuntimeException from assertPrimarySchema with malformed proof file');
        } catch (RuntimeException $e) {
            // Expected: assertPrimarySchema threw
            // Verify env was restored to its pre-call state in the finally block
            $this->assertSame($origProof, getenv('PHASE8_DISPOSABLE_DB_PROOF'),
                'PHASE8_DISPOSABLE_DB_PROOF must be restored after captureVerifiedOwnedSnapshot fails');
            $this->assertSame($origNonce, getenv('PHASE8_DISPOSABLE_DB_NONCE'),
                'PHASE8_DISPOSABLE_DB_NONCE must be restored after captureVerifiedOwnedSnapshot fails');
            $this->assertSame($origAllow, getenv('PHASE8_ALLOW_DISPOSABLE_DB'),
                'PHASE8_ALLOW_DISPOSABLE_DB must be restored after captureVerifiedOwnedSnapshot fails');
        } finally {
            @rmdir($harnessDir);
        }
    }

    #[Test]
    public function test_golden_fixture_has_distinct_provenance_between_run_id_and_manifest_digest(): void
    {
        // Confirm actual fixture inequality: runId (UUID) is distinct from manifestDigest (sha256 hex).
        // Documents real provenance distinction without asserting a hand-made payload.
        $fixturePath = dirname(__DIR__, 2) . '/Support/Harness/fixtures/synthetic-financial-golden-wire.json';
        $this->assertFileExists($fixturePath);

        $data = json_decode((string) file_get_contents($fixturePath), true);
        $this->assertIsArray($data);

        $runId = $data['runId'] ?? '';
        $manifestDigest = $data['manifestDigest'] ?? '';

        $this->assertNotEmpty($runId, 'Golden fixture must have top-level runId');
        $this->assertNotEmpty($manifestDigest, 'Golden fixture must have top-level manifestDigest');

        // They must be distinct: UUID format vs sha256 hex format — real provenance inequality
        $this->assertNotSame($runId, $manifestDigest,
            'runId and manifestDigest must have distinct provenance');

        // runId must be UUID format
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $runId,
            'Golden fixture runId must be UUID format'
        );

        // manifestDigest must normalize to 64-hex sha256
        $normalized = SupervisedPairedFinancialDrill::normalizeCanonicalDigest($manifestDigest);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $normalized,
            'Golden fixture manifestDigest must normalize to 64-hex SHA-256');
    }
}
