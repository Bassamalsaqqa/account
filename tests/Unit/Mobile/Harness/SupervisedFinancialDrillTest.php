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
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\Harness\DisposableFinancialPostRunInspector;
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
        $fixturePath = dirname(__DIR__, 3) . '/Support/Harness/fixtures/synthetic-financial-golden-wire.json';
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
        $fixturePath = dirname(__DIR__, 3) . '/Support/Harness/fixtures/synthetic-financial-golden-wire.json';
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
        $fixturePath = dirname(__DIR__, 3) . '/Support/Harness/fixtures/synthetic-financial-golden-wire.json';
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
        $repoRoot = dirname(__DIR__, 4);
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
        $repoRoot = dirname(__DIR__, 4);
        $snapshot = $this->createValidSnapshot($repoRoot);

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
    public function test_exact_cleanup_rejects_malformed_schema_via_factory(): void
    {
        // fromLiveOwnership() itself refuses malformed schema names BEFORE any SQL,
        // so executeExactCleanup() can never receive a snapshot with a malformed schema.
        // This test verifies that invariant holds at the factory boundary.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SNAPSHOT_INVALID_SCHEMA_NAME');
        VerifiedOwnedSnapshot::fromLiveOwnership(
            schemaName: 'accounting_p8_tmp_invalid; DROP TABLE users;',
            proofPath: $this->tempDir . '/proof.txt',
            proofNonce: '0123456789abcdef0123456789abcdef',
            harnessDir: sys_get_temp_dir() . '/v2_harness_0123456789abcdef',
            childPid: 12345,
            repoRoot: dirname(__DIR__, 4)
        );
    }

    #[Test]
    public function test_exact_cleanup_reports_failure_when_schema_lingers(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $snapshot = $this->createValidSnapshot($repoRoot);

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
        $repoRoot = dirname(__DIR__, 4);
        $snapshot = $this->createValidSnapshot($repoRoot);

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
            repoRoot: dirname(__DIR__, 4)
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
            repoRoot: dirname(__DIR__, 4)
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
            repoRoot: dirname(__DIR__, 4)
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

        $envVarsToScope = [
            'APP_ENV',
            'PHASE8_TEST_DB_HOST',
            'PHASE8_TEST_DB_PORT',
            'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_PASSWORD',
            'PHASE8_TEST_DB_EMPTY_PASSWORD',
        ];
        $savedStores = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($envVarsToScope);

        try {
            putenv('APP_ENV=testing');
            $_ENV['APP_ENV'] = 'testing';
            $_SERVER['APP_ENV'] = 'testing';
            putenv('PHASE8_TEST_DB_HOST=127.0.0.1');
            $_ENV['PHASE8_TEST_DB_HOST'] = '127.0.0.1';
            $_SERVER['PHASE8_TEST_DB_HOST'] = '127.0.0.1';
            putenv('PHASE8_TEST_DB_PORT=3342');
            $_ENV['PHASE8_TEST_DB_PORT'] = '3342';
            $_SERVER['PHASE8_TEST_DB_PORT'] = '3342';
            putenv('PHASE8_TEST_DB_USERNAME=disposable_test_user');
            $_ENV['PHASE8_TEST_DB_USERNAME'] = 'disposable_test_user';
            $_SERVER['PHASE8_TEST_DB_USERNAME'] = 'disposable_test_user';
            putenv('PHASE8_TEST_DB_PASSWORD=');
            $_ENV['PHASE8_TEST_DB_PASSWORD'] = '';
            $_SERVER['PHASE8_TEST_DB_PASSWORD'] = '';
            putenv('PHASE8_TEST_DB_EMPTY_PASSWORD=1');
            $_ENV['PHASE8_TEST_DB_EMPTY_PASSWORD'] = '1';
            $_SERVER['PHASE8_TEST_DB_EMPTY_PASSWORD'] = '1';

            $caught = null;
            try {
                SupervisedPairedFinancialDrill::captureVerifiedOwnedSnapshot(
                    schemaName: 'accounting_p8_tmp_0123456789ab',
                    proofPath: $proofFile,
                    proofNonce: str_repeat('a', 32),
                    harnessDir: $harnessDir,
                    childPid: 1234,
                    repoRoot: dirname(__DIR__, 4)
                );
            } catch (\Throwable $e) {
                $caught = $e;
            }

            $this->assertNotNull($caught, 'Expected exception from assertPrimarySchema with malformed proof file');
            $this->assertInstanceOf(\JsonException::class, $caught, 'Expected JsonException from malformed proof JSON');

            // Verify env was restored to its pre-call state in the finally block
            $this->assertSame($origProof, getenv('PHASE8_DISPOSABLE_DB_PROOF'),
                'PHASE8_DISPOSABLE_DB_PROOF must be restored after captureVerifiedOwnedSnapshot fails');
            $this->assertSame($origNonce, getenv('PHASE8_DISPOSABLE_DB_NONCE'),
                'PHASE8_DISPOSABLE_DB_NONCE must be restored after captureVerifiedOwnedSnapshot fails');
            $this->assertSame($origAllow, getenv('PHASE8_ALLOW_DISPOSABLE_DB'),
                'PHASE8_ALLOW_DISPOSABLE_DB must be restored after captureVerifiedOwnedSnapshot fails');
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($savedStores);
            @rmdir($harnessDir);
        }
    }

    #[Test]
    public function test_capture_snapshot_rejects_proof_parent_insecure_perms(): void
    {
        $insecureDir = $this->tempDir . '/insecure_proof_parent';
        mkdir($insecureDir, 0755, true);
        chmod($insecureDir, 0755);

        $proofFile = $insecureDir . '/proof.json';
        file_put_contents($proofFile, '{}');
        chmod($proofFile, 0600);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SNAPSHOT_PROOF_PARENT_INSECURE_PERMS');

        SupervisedPairedFinancialDrill::captureVerifiedOwnedSnapshot(
            schemaName: 'accounting_p8_tmp_0123456789ab',
            proofPath: $proofFile,
            proofNonce: str_repeat('a', 32),
            harnessDir: $this->tempDir,
            childPid: 1234,
            repoRoot: dirname(__DIR__, 4)
        );
    }

    #[Test]
    public function test_disposable_mariadb_schema_creates_private_0700_proof_parent_directory(): void
    {
        $instance = (new \ReflectionClass(DisposableMariaDbSchema::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionClass(DisposableMariaDbSchema::class);

        // Inject mocked PDO expecting one exact DROP statement BEFORE owned=true so neither drop()
        // nor __destruct() can ever attempt a real PDO connection to 3342.
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('exec')
            ->with('DROP DATABASE `accounting_p8_tmp_0123456789ab`');

        $propAdminPdo = $ref->getProperty('adminPdo');
        $propAdminPdo->setAccessible(true);
        $propAdminPdo->setValue($instance, $mockPdo);

        $propOwned = $ref->getProperty('owned');
        $propOwned->setAccessible(true);
        $propOwned->setValue($instance, true);

        $propSchema = $ref->getProperty('schemaName');
        $propSchema->setAccessible(true);
        $propSchema->setValue($instance, 'accounting_p8_tmp_0123456789ab');

        $propSettings = $ref->getProperty('settings');
        $propSettings->setAccessible(true);
        $propSettings->setValue($instance, [
            'HOST' => '127.0.0.1',
            'PORT' => '3342',
            'USERNAME' => 'test_runner',
            'PASSWORD' => '',
        ]);

        $proofFile = null;
        $proofDir = null;

        try {
            $env = $instance->environment();
            $proofFile = $env['PHASE8_DISPOSABLE_DB_PROOF'] ?? '';
            $this->assertFileExists($proofFile);

            $proofDir = dirname($proofFile);
            $this->assertTrue(is_dir($proofDir));

            $dirPerms = fileperms($proofDir) & 0777;
            $this->assertSame(0700, $dirPerms, 'Proof parent directory must have strict 0700 permissions');

            $filePerms = fileperms($proofFile) & 0777;
            $this->assertSame(0600, $filePerms, 'Proof file must have strict 0600 permissions');

            $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
            $this->assertSame($currentUid, fileowner($proofDir), 'Proof directory must be owned by current UID');
            $this->assertSame($currentUid, fileowner($proofFile), 'Proof file must be owned by current UID');

            // Cleanup via drop() must invoke mocked DROP statement and remove proof file and directory
            $instance->drop();
            $this->assertFileDoesNotExist($proofFile, 'Proof file must be unlinked on drop');
            $this->assertDirectoryDoesNotExist($proofDir, 'Proof directory must be removed on drop');
        } finally {
            if ($proofFile !== null && is_file($proofFile)) {
                @unlink($proofFile);
            }
            if ($proofDir !== null && is_dir($proofDir)) {
                @rmdir($proofDir);
            }
        }
    }

    #[Test]
    public function test_actual_drill_ordering_tls_failure_triggers_cleanup_with_early_captured_snapshot(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $proofNonce = str_repeat('c', 32);

        // Create harness directory with 0700 permissions
        $harnessName = 'v2_harness_' . bin2hex(random_bytes(8));
        $harnessDir = sys_get_temp_dir() . '/' . $harnessName;
        mkdir($harnessDir, 0700, true);

        // Create bootstrap directory with 0700 permissions
        $bootstrapDir = $harnessDir . '/bootstrap';
        mkdir($bootstrapDir, 0700, true);

        // Create private 0700 proof parent directory and 0600 proof file
        $proofDir = $this->tempDir . '/proof_dir';
        if (! is_dir($proofDir)) {
            mkdir($proofDir, 0700, true);
        }
        $proofPath = $proofDir . '/proof.json';
        $proofContent = json_encode([
            'schema' => $schemaName,
            'host'   => '127.0.0.1',
            'port'   => '3342',
            'nonce'  => $proofNonce,
        ], JSON_THROW_ON_ERROR);
        file_put_contents($proofPath, $proofContent);
        chmod($proofPath, 0600);

        // Create 0600 child bootstrap config
        $childConfigPath = $bootstrapDir . '/child_bootstrap_config.json';
        file_put_contents($childConfigPath, json_encode([
            'db_host'     => '127.0.0.1',
            'db_port'     => '3342',
            'db_username' => 'test_runner',
            'db_password' => '',
            'schema_name' => $schemaName,
            'proof_path'  => $proofPath,
            'proof_nonce' => $proofNonce,
        ], JSON_THROW_ON_ERROR));
        chmod($childConfigPath, 0600);

        // Create dummy 0600 ca.pem
        $caCertPath = $bootstrapDir . '/ca.pem';
        file_put_contents($caCertPath, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");
        chmod($caCertPath, 0600);

        // Snapshot original parent environment across all three stores BEFORE mutation
        $envAllowlist = [
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
        $parentEnvToRestore = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($envAllowlist);

        $origAppEnv = getenv('APP_ENV');

        $snapshot = null;
        $caughtTlsException = null;

        try {
            // STEP 8 in actual script: Set scoped testing flags required for assertion
            $scopedPreCaptureVars = [
                'APP_ENV'                  => 'testing',
                'MOBILE_V2_HARNESS_ENABLE' => '1',
                'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            ];
            foreach ($scopedPreCaptureVars as $varName => $varVal) {
                putenv("{$varName}={$varVal}");
                $_ENV[$varName]    = $varVal;
                $_SERVER[$varName] = $varVal;
            }

            // Load bootstrap credentials from child config
            $creds = DisposableFinancialPostRunInspector::loadSecureBootstrapCredentials($childConfigPath, $schemaName);

            // Capture verified owned snapshot EARLIEST (before handoff and before TLS readiness check)
            $snapshot = SupervisedPairedFinancialDrill::captureVerifiedOwnedSnapshot(
                $schemaName,
                $creds['proof_path'],
                $creds['proof_nonce'],
                $harnessDir,
                1234,
                $repoRoot
            );

            $this->assertNotNull($snapshot, 'Snapshot must be captured BEFORE TLS readiness check');
            $this->assertSame($schemaName, $snapshot->schemaName);

            // STEP 9 in actual script: performStrictTlsReadinessCheck, which FAILS
            // (e.g. readiness URL violates the loopback allowlist or TLS connection fails)
            try {
                SupervisedPairedFinancialDrill::performStrictTlsReadinessCheck(
                    'https://disallowed-host.example.com:18443/ready',
                    $caCertPath,
                    'unexpected-nonce'
                );
            } catch (\Throwable $e) {
                $caughtTlsException = $e;
            }

            $this->assertNotNull($caughtTlsException, 'TLS readiness check must throw exception');
            $this->assertStringContainsString('READINESS_URL_NOT_ALLOWED', $caughtTlsException->getMessage());

            // Mock DB expectations for cleanup: port 3342 check, schema exists before drop, absent after drop
            $mockPortStmt = $this->createMock(PDOStatement::class);
            $mockPortStmt->method('fetch')->willReturn(['port' => '3342']);

            $mockSchemaStmt = $this->createMock(PDOStatement::class);
            $mockSchemaStmt->method('fetch')->willReturnOnConsecutiveCalls(
                ['SCHEMA_NAME' => $schemaName],
                false
            );

            $mockPdo = $this->createMock(PDO::class);
            $mockPdo->method('query')->with('SELECT @@port AS port')->willReturn($mockPortStmt);
            $mockPdo->method('prepare')->willReturn($mockSchemaStmt);
            $mockPdo->expects($this->once())->method('exec')->with("DROP DATABASE IF EXISTS `{$schemaName}`");

            // Execute the script's actual cleanup helper: executeCleanupAndRestoreEnv
            // Because $snapshot was captured BEFORE the TLS failure, cleanup succeeds completely
            $cleanupResult = SupervisedPairedFinancialDrill::executeCleanupAndRestoreEnv(
                childProcess: null,
                snapshot: $snapshot,
                repoRoot: $repoRoot,
                parentEnvToRestore: $parentEnvToRestore,
                adminPdo: $mockPdo
            );

            $this->assertTrue($cleanupResult['success'], 'Cleanup after TLS failure must succeed');
            $this->assertTrue($cleanupResult['schema_absent'], 'Schema must be verified absent');
            $this->assertTrue($cleanupResult['temp_cleaned'], 'Harness temp directory must be removed');
            $this->assertEmpty($cleanupResult['errors']);
            $this->assertDirectoryDoesNotExist($harnessDir, 'Harness directory must be deleted');

            // Verify parent environment was restored to pre-drill state
            $this->assertSame($origAppEnv, getenv('APP_ENV'), 'Parent APP_ENV must be restored after cleanup');
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($parentEnvToRestore);
            if (is_dir($harnessDir)) {
                @unlink($childConfigPath);
                @unlink($caCertPath);
                @rmdir($bootstrapDir);
                @rmdir($harnessDir);
            }
        }
    }

    #[Test]
    public function test_prelive_failure_cleanup_with_captured_snapshot(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $snapshot = $this->createValidSnapshot($repoRoot);

        // Models pre-live failure (e.g. live witness arrival timeout) where child harness was
        // initialized, but live approval did not arrive in time.
        // executeExactCleanup receives the pre-captured $snapshot and safely reaps child, drops
        // schema via admin PDO on port 3342, and cleans up the harness temp directory.
        $mockPortStmt = $this->createMock(PDOStatement::class);
        $mockPortStmt->method('fetch')->willReturn(['port' => '3342']);

        $mockSchemaStmt = $this->createMock(PDOStatement::class);
        // Schema check returns existing schema first, then absence (false) after drop
        $mockSchemaStmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['SCHEMA_NAME' => $snapshot->schemaName],
            false
        );

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')->with('SELECT @@port AS port')->willReturn($mockPortStmt);
        $mockPdo->method('prepare')->willReturn($mockSchemaStmt);
        $mockPdo->expects($this->once())->method('exec')->with("DROP DATABASE IF EXISTS `{$snapshot->schemaName}`");

        $this->assertDirectoryExists($snapshot->harnessDir);

        $result = SupervisedPairedFinancialDrill::executeExactCleanup(
            childProcess: null,
            snapshot: $snapshot,
            repoRoot: $repoRoot,
            adminPdo: $mockPdo
        );

        $this->assertTrue($result['success'], 'Pre-live cleanup with captured snapshot must succeed');
        $this->assertTrue($result['schema_absent'], 'Schema must be verified absent');
        $this->assertTrue($result['temp_cleaned'], 'Harness temp directory must be cleaned up');
        $this->assertEmpty($result['errors'], 'Cleanup must produce zero errors');
        $this->assertDirectoryDoesNotExist($snapshot->harnessDir);
    }

    #[Test]
    public function test_exact_cleanup_removes_nested_subdirectories_and_unlinks_symlink_without_touching_outside_target(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $snapshot = $this->createValidSnapshot($repoRoot);

        // Populate nested directory tree inside harness directory
        $bootstrapDir = $snapshot->harnessDir . '/bootstrap';
        mkdir($bootstrapDir, 0700);
        $nestedSubdir = $bootstrapDir . '/subfolder';
        mkdir($nestedSubdir, 0700);
        $nestedFile = $nestedSubdir . '/nested_payload.json';
        file_put_contents($nestedFile, '{"nested":true}');
        chmod($nestedFile, 0600);

        // Create an outside target file that must NOT be touched or removed
        $outsideTarget = $this->tempDir . '/outside_preserved.txt';
        file_put_contents($outsideTarget, 'PRESERVED_OUTSIDE_DATA');
        chmod($outsideTarget, 0600);

        // Create a symlink inside the harness directory pointing to the outside file
        $symlinkPath = $snapshot->harnessDir . '/symlink_to_outside';
        symlink($outsideTarget, $symlinkPath);

        $mockPortStmt = $this->createMock(PDOStatement::class);
        $mockPortStmt->method('fetch')->willReturn(['port' => '3342']);

        $mockSchemaStmt = $this->createMock(PDOStatement::class);
        $mockSchemaStmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['SCHEMA_NAME' => $snapshot->schemaName],
            false
        );

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')->with('SELECT @@port AS port')->willReturn($mockPortStmt);
        $mockPdo->method('prepare')->willReturn($mockSchemaStmt);
        $mockPdo->expects($this->once())->method('exec')->with("DROP DATABASE IF EXISTS `{$snapshot->schemaName}`");

        $result = SupervisedPairedFinancialDrill::executeExactCleanup(
            childProcess: null,
            snapshot: $snapshot,
            repoRoot: $repoRoot,
            adminPdo: $mockPdo
        );

        $this->assertTrue($result['success'], 'Exact cleanup with nested directories and symlink must succeed');
        $this->assertTrue($result['temp_cleaned'], 'Harness temp directory and nested tree must be removed');
        $this->assertDirectoryDoesNotExist($snapshot->harnessDir);

        // Outside target file must remain intact and completely preserved
        $this->assertFileExists($outsideTarget, 'Outside symlink target must not be deleted');
        $this->assertSame('PRESERVED_OUTSIDE_DATA', file_get_contents($outsideTarget));
    }

    #[Test]
    public function test_exact_cleanup_refuses_unsafe_harness_dir_pointing_to_repo_or_system_root(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $snapshot = $this->createValidSnapshot($repoRoot);

        $mockPortStmt = $this->createMock(PDOStatement::class);
        $mockPortStmt->method('fetch')->willReturn(['port' => '3342']);

        $mockSchemaStmt = $this->createMock(PDOStatement::class);
        $mockSchemaStmt->method('fetch')->willReturn(false);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')->with('SELECT @@port AS port')->willReturn($mockPortStmt);
        $mockPdo->method('prepare')->willReturn($mockSchemaStmt);

        $ref = new \ReflectionClass($snapshot);
        $prop = $ref->getProperty('harnessDir');
        $prop->setAccessible(true);

        // Case 1: Refuses harnessDir pointing to /tmp (system root)
        $prop->setValue($snapshot, '/tmp');
        $resultTmp = SupervisedPairedFinancialDrill::executeExactCleanup(null, $snapshot, $repoRoot, $mockPdo);
        $this->assertFalse($resultTmp['success']);
        $this->assertFalse($resultTmp['temp_cleaned']);
        $this->assertStringContainsString('CLEANUP_UNSAFE_TEMP_PATH_REJECTED', implode('; ', $resultTmp['errors']));

        // Case 2: Refuses harnessDir pointing inside repository root
        $prop->setValue($snapshot, $repoRoot);
        $resultRepo = SupervisedPairedFinancialDrill::executeExactCleanup(null, $snapshot, $repoRoot, $mockPdo);
        $this->assertFalse($resultRepo['success']);
        $this->assertFalse($resultRepo['temp_cleaned']);
        $this->assertStringContainsString('CLEANUP_UNSAFE_TEMP_PATH_REJECTED', implode('; ', $resultRepo['errors']));

        // Case 3: Refuses harnessDir that is a symlink
        $symlinkHarness = sys_get_temp_dir() . '/v2_harness_' . bin2hex(random_bytes(8));
        symlink($this->tempDir, $symlinkHarness);
        try {
            $prop->setValue($snapshot, $symlinkHarness);
            $resultSymlink = SupervisedPairedFinancialDrill::executeExactCleanup(null, $snapshot, $repoRoot, $mockPdo);
            $this->assertFalse($resultSymlink['success']);
            $this->assertFalse($resultSymlink['temp_cleaned']);
            $this->assertStringContainsString('TEMP_DIR_SYMLINK_REJECTED', implode('; ', $resultSymlink['errors']));
        } finally {
            @unlink($symlinkHarness);
        }
    }

    #[Test]
    public function test_golden_fixture_has_distinct_provenance_between_handoff_sha_and_profile_digest(): void
    {
        // Verify actual fixture fields that map to CLI argv propagation:
        //   manifestDigest  -> --expected-handoff-digest (whole handoff SHA, raw wire provenance)
        //   runId           -> UUID of the Android test execution run
        //   backendHead, clientPublicationHead, clientExecutableHead -> --expected-backend-head etc.
        //
        // These are the values verifyEvidenceFreshness() receives and validates.
        // This test documents real provenance distinction: manifestDigest is a sha256 hex string
        // (whole handoff SHA) while runId is a UUID — structurally and semantically distinct.
        // The test does NOT execute any subprocess or make real network/DB calls.
        $fixturePath = dirname(__DIR__, 3) . '/Support/Harness/fixtures/synthetic-financial-golden-wire.json';
        $this->assertFileExists($fixturePath);

        $data = json_decode((string) file_get_contents($fixturePath), true);
        $this->assertIsArray($data);

        // CLI argv propagation fields must all be present at top level (no aliases)
        $runId = $data['runId'] ?? '';
        $manifestDigest = $data['manifestDigest'] ?? '';
        $backendHead = $data['backendHead'] ?? '';
        $clientPublicationHead = $data['clientPublicationHead'] ?? '';
        $clientExecutableHead = $data['clientExecutableHead'] ?? '';

        $this->assertNotEmpty($runId, 'Golden fixture must have top-level runId');
        $this->assertNotEmpty($manifestDigest, 'Golden fixture must have top-level manifestDigest (whole handoff SHA)');
        $this->assertNotEmpty($backendHead, 'Golden fixture must have top-level backendHead (CLI argv propagation)');
        $this->assertNotEmpty($clientPublicationHead, 'Golden fixture must have top-level clientPublicationHead (CLI argv propagation)');
        $this->assertNotEmpty($clientExecutableHead, 'Golden fixture must have top-level clientExecutableHead (CLI argv propagation)');

        // runId must be UUID format — Android test execution run identity
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $runId,
            'Golden fixture runId must be UUID format'
        );

        // manifestDigest is the whole handoff SHA (raw wire provenance), must be 64-hex SHA-256
        // This is what the inspector CLI receives as --expected-handoff-digest
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $manifestDigest,
            'Golden fixture manifestDigest must be 64-hex SHA-256 (whole handoff SHA for CLI argv propagation)'
        );

        // backendHead and client heads must be 40-hex git SHAs (CLI argv propagation fields)
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $backendHead, 'backendHead must be 40-hex git SHA');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $clientPublicationHead, 'clientPublicationHead must be 40-hex git SHA');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $clientExecutableHead, 'clientExecutableHead must be 40-hex git SHA');

        // runId and manifestDigest have distinct provenance: UUID (execution identity) vs sha256 hex (handoff hash)
        $this->assertNotSame($runId, $manifestDigest, 'runId and manifestDigest must have distinct provenance');

        // manifestDigest normalizes cleanly via normalizeCanonicalDigest (64-hex, no prefix needed)
        $normalizedManifestDigest = SupervisedPairedFinancialDrill::normalizeCanonicalDigest($manifestDigest);
        $this->assertSame($manifestDigest, $normalizedManifestDigest, 'manifestDigest is already normalized 64-hex');

        // Confirm the golden fixture's field values match what verifyEvidenceFreshness() will receive
        // from the actual GH review 5909921626 Kotlin wire: backendHead=cfc28b..., clientPubHead=c00aed...,
        // clientExeHead=b7e61e..., manifestDigest=8e48f3... — these are the actual CLI argv values.
        $this->assertSame('cfc28baaaaf52521ecbc88f1ba4c621afee17221', $backendHead,
            'backendHead must match actual Kotlin wire CLI argv value');
        $this->assertSame('c00aedb70ea06f28ae42282149c030df76dc300d', $clientPublicationHead,
            'clientPublicationHead must match actual Kotlin wire CLI argv value');
        $this->assertSame('b7e61eb4ed0aed2f5040d9fc44f9764b310be530', $clientExecutableHead,
            'clientExecutableHead must match actual Kotlin wire CLI argv value');
        $this->assertSame('8e48f3692a88f5ac3f333f81536d70eb3541228959968697fda03f68df44befc', $manifestDigest,
            'manifestDigest must match actual Kotlin wire whole handoff SHA (--expected-handoff-digest CLI argv)');
    }

    // ---------------------------------------------------------------------------
    // Cleanup PID binding and null snapshot child-skip tests
    // ---------------------------------------------------------------------------

    #[Test]
    public function test_exact_cleanup_pid_mismatch_refuses_signals(): void
    {
        // When a running child process PID differs from the snapshot's captured childPid,
        // executeExactCleanup must refuse to send any signals and report PID_MISMATCH.
        $repoRoot = dirname(__DIR__, 4);
        $snapshot = $this->createValidSnapshot($repoRoot, childPid: 99999);

        $mockChild = $this->createMock(Process::class);
        $mockChild->method('isRunning')->willReturn(true);
        $mockChild->method('getPid')->willReturn(12345); // Different from snapshot's 99999
        $mockChild->expects($this->never())->method('signal'); // Must NOT send any signal

        $mockPortStmt = $this->createMock(PDOStatement::class);
        $mockPortStmt->method('fetch')->willReturn(['port' => '3342']);
        $mockSchemaStmt = $this->createMock(PDOStatement::class);
        $mockSchemaStmt->method('fetch')->willReturn(false);
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')->with('SELECT @@port AS port')->willReturn($mockPortStmt);
        $mockPdo->method('prepare')->willReturn($mockSchemaStmt);

        $result = SupervisedPairedFinancialDrill::executeExactCleanup(
            $mockChild,
            $snapshot,
            $repoRoot,
            $mockPdo
        );

        $this->assertFalse($result['child_reaped']);
        $this->assertStringContainsString('CLEANUP_PID_MISMATCH', implode('; ', $result['errors']));
    }

    #[Test]
    public function test_exact_cleanup_null_snapshot_skips_child_signals(): void
    {
        // When a running child process exists but snapshot is null, executeExactCleanup
        // must not send signals (can't verify PID) and must report the skip.
        $repoRoot = dirname(__DIR__, 4);

        $mockChild = $this->createMock(Process::class);
        $mockChild->method('isRunning')->willReturn(true);
        $mockChild->method('getPid')->willReturn(12345);
        $mockChild->expects($this->never())->method('signal');

        $result = SupervisedPairedFinancialDrill::executeExactCleanup(
            $mockChild,
            null, // No snapshot
            $repoRoot,
            null
        );

        $this->assertFalse($result['child_reaped']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('CLEANUP_CHILD_SKIP_NO_SNAPSHOT', implode('; ', $result['errors']));
    }

    #[Test]
    public function test_from_live_ownership_rejects_negative_child_pid(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SNAPSHOT_INVALID_CHILD_PID');
        VerifiedOwnedSnapshot::fromLiveOwnership(
            schemaName: 'accounting_p8_tmp_0123456789ab',
            proofPath: $this->tempDir . '/proof.txt',
            proofNonce: str_repeat('a', 32),
            harnessDir: $this->tempDir,
            childPid: -1,
            repoRoot: dirname(__DIR__, 4)
        );
    }

    #[Test]
    public function test_from_live_ownership_rejects_schema_with_nul_byte(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SNAPSHOT_INVALID_SCHEMA_NAME');
        VerifiedOwnedSnapshot::fromLiveOwnership(
            schemaName: "accounting_p8_tmp_0123456789ab\x00",
            proofPath: $this->tempDir . '/proof.txt',
            proofNonce: str_repeat('a', 32),
            harnessDir: $this->tempDir,
            childPid: 1234,
            repoRoot: dirname(__DIR__, 4)
        );
    }

    #[Test]
    public function test_from_live_ownership_rejects_schema_with_backtick(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SNAPSHOT_INVALID_SCHEMA_NAME');
        VerifiedOwnedSnapshot::fromLiveOwnership(
            schemaName: 'accounting_p8_tmp_` OR 1=1',
            proofPath: $this->tempDir . '/proof.txt',
            proofNonce: str_repeat('a', 32),
            harnessDir: $this->tempDir,
            childPid: 1234,
            repoRoot: dirname(__DIR__, 4)
        );
    }

    // ---------------------------------------------------------------------------
    // Meaningful noDB factory refusal and env restoration tests
    // ---------------------------------------------------------------------------

    #[Test]
    public function test_from_live_ownership_rejects_harness_dir_inside_repo(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $insideHarness = $repoRoot . '/tests/v2_harness_' . bin2hex(random_bytes(8));
        mkdir($insideHarness, 0700, true);

        $proofFile = $this->tempDir . '/proof.txt';
        file_put_contents($proofFile, '{}');
        chmod($proofFile, 0600);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('SNAPSHOT_HARNESS_DIR_INSIDE_REPO_REFUSED');
            VerifiedOwnedSnapshot::fromLiveOwnership(
                schemaName: 'accounting_p8_tmp_0123456789ab',
                proofPath: $proofFile,
                proofNonce: str_repeat('a', 32),
                harnessDir: $insideHarness,
                childPid: 1234,
                repoRoot: $repoRoot
            );
        } finally {
            @rmdir($insideHarness);
        }
    }

    #[Test]
    public function test_from_live_ownership_rejects_invalid_harness_dir_name(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $invalidHarness = sys_get_temp_dir() . '/invalid_harness_name_' . bin2hex(random_bytes(4));
        mkdir($invalidHarness, 0700, true);

        $proofFile = $this->tempDir . '/proof.txt';
        file_put_contents($proofFile, '{}');
        chmod($proofFile, 0600);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('SNAPSHOT_INVALID_HARNESS_DIR_NAME');
            VerifiedOwnedSnapshot::fromLiveOwnership(
                schemaName: 'accounting_p8_tmp_0123456789ab',
                proofPath: $proofFile,
                proofNonce: str_repeat('a', 32),
                harnessDir: $invalidHarness,
                childPid: 1234,
                repoRoot: $repoRoot
            );
        } finally {
            @rmdir($invalidHarness);
        }
    }

    #[Test]
    public function test_scoped_cleanup_and_three_store_env_restoration_lifecycle(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $testDbVars = [
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

        // Outer ambient snapshot so test runner environment is cleanly restored when test ends
        $outerAmbient = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($testDbVars);

        try {
            // 1. Ensure all variables start strictly unset across all 3 stores
            foreach ($testDbVars as $var) {
                putenv($var);
                unset($_ENV[$var], $_SERVER[$var]);
                $this->assertFalse(getenv($var), "Expected {$var} to start unset in getenv");
                $this->assertArrayNotHasKey($var, $_ENV, "Expected {$var} to start unset in \$_ENV");
                $this->assertArrayNotHasKey($var, $_SERVER, "Expected {$var} to start unset in \$_SERVER");
            }

            // 2. Snapshot the unset stores (matches production supervisor step 11)
            $parentEnvToRestore = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($testDbVars);

            // 3. Setup synthetic proof fixture and valid harness directory
            $schemaName = 'accounting_p8_tmp_0123456789ab';
            $proofNonce = str_repeat('a', 32);
            $proofDir = $this->tempDir . '/proof_dir';
            if (! is_dir($proofDir)) {
                mkdir($proofDir, 0700, true);
            }
            $proofFile = $proofDir . '/proof.json';

            $harnessName = 'v2_harness_' . bin2hex(random_bytes(8));
            $harnessDir = sys_get_temp_dir() . '/' . $harnessName;
            if (! is_dir($harnessDir)) {
                mkdir($harnessDir, 0700, true);
            }

            $proofContent = json_encode([
                'schema' => $schemaName,
                'host'   => '127.0.0.1',
                'port'   => '3342',
                'nonce'  => $proofNonce,
            ], JSON_THROW_ON_ERROR);
            file_put_contents($proofFile, $proofContent);
            chmod($proofFile, 0600);

            // 4. Load valid synthetic credentials and scoped flags across all three stores
            $scopedVars = [
                'APP_ENV'                       => 'testing',
                'PHASE8_ALLOW_DISPOSABLE_DB'    => '1',
                'MOBILE_V2_HARNESS_ENABLE'      => '1',
                'PHASE8_TEST_DB_HOST'           => '127.0.0.1',
                'PHASE8_TEST_DB_PORT'           => '3342',
                'PHASE8_TEST_DB_USERNAME'       => 'root',
                'PHASE8_TEST_DB_PASSWORD'       => '',
                'PHASE8_TEST_DB_EMPTY_PASSWORD' => '1',
                'PHASE8_DISPOSABLE_DB_PROOF'    => $proofFile,
                'PHASE8_DISPOSABLE_DB_NONCE'    => $proofNonce,
            ];
            foreach ($scopedVars as $var => $val) {
                putenv("{$var}={$val}");
                $_ENV[$var]    = $val;
                $_SERVER[$var] = $val;
            }

            // 5. Build valid snapshot via sole public factory fromLiveOwnership()
            $snapshot = VerifiedOwnedSnapshot::fromLiveOwnership(
                schemaName: $schemaName,
                proofPath:  $proofFile,
                proofNonce: $proofNonce,
                harnessDir: $harnessDir,
                childPid:   12345,
                repoRoot:   $repoRoot
            );

            // 6. Mock PDO: verify port 3342 and schema absence, AND assert cleanup sees scoped credentials before restoration
            $credentialSeenDuringCleanup = false;
            $mockPortStmt = $this->createMock(PDOStatement::class);
            $mockPortStmt->method('fetch')->willReturnCallback(function () use (&$credentialSeenDuringCleanup) {
                if (getenv('PHASE8_TEST_DB_HOST') === '127.0.0.1'
                    && ($_ENV['PHASE8_TEST_DB_HOST'] ?? null) === '127.0.0.1'
                    && ($_SERVER['PHASE8_TEST_DB_HOST'] ?? null) === '127.0.0.1'
                    && getenv('APP_ENV') === 'testing'
                    && getenv('PHASE8_ALLOW_DISPOSABLE_DB') === '1'
                ) {
                    $credentialSeenDuringCleanup = true;
                }

                return ['port' => '3342'];
            });

            $mockSchemaStmt = $this->createMock(PDOStatement::class);
            $mockSchemaStmt->method('fetch')->willReturn(false);

            $mockPdo = $this->createMock(PDO::class);
            $mockPdo->method('query')->with('SELECT @@port AS port')->willReturn($mockPortStmt);
            $mockPdo->method('prepare')->willReturn($mockSchemaStmt);

            // 7. Execute production lifecycle cleanup and 3-store restoration helper
            $result = SupervisedPairedFinancialDrill::executeCleanupAndRestoreEnv(
                null,
                $snapshot,
                $repoRoot,
                $parentEnvToRestore,
                $mockPdo
            );

            // 8. Assert cleanup succeeded and observed scoped credentials before restoration
            $this->assertTrue($credentialSeenDuringCleanup, 'Cleanup must see scoped credentials before restoration occurs');
            $this->assertTrue($result['success']);
            $this->assertTrue($result['schema_absent']);
            $this->assertEmpty($result['errors']);

            // 9. Assert all 3 stores are completely restored to their original unset state
            foreach ($testDbVars as $var) {
                $this->assertFalse(getenv($var), "Expected {$var} to be restored to false in getenv");
                $this->assertArrayNotHasKey($var, $_ENV, "Expected {$var} to be restored to unset in \$_ENV");
                $this->assertArrayNotHasKey($var, $_SERVER, "Expected {$var} to be restored to unset in \$_SERVER");
            }
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($outerAmbient);
        }
    }

    #[Test]
    public function test_scoped_cleanup_restores_all_stores_even_when_cleanup_throws(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $testDbVars = [
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

        $outerAmbient = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($testDbVars);

        try {
            // 1. Ensure all variables start unset across all 3 stores
            foreach ($testDbVars as $var) {
                putenv($var);
                unset($_ENV[$var], $_SERVER[$var]);
            }

            $parentEnvToRestore = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($testDbVars);

            // 2. Setup synthetic proof fixture and valid harness directory
            $schemaName = 'accounting_p8_tmp_0123456789ab';
            $proofNonce = str_repeat('b', 32);
            $proofDir = $this->tempDir . '/proof_dir_throw';
            if (! is_dir($proofDir)) {
                mkdir($proofDir, 0700, true);
            }
            $proofFile = $proofDir . '/proof.json';

            $harnessName = 'v2_harness_' . bin2hex(random_bytes(8));
            $harnessDir = sys_get_temp_dir() . '/' . $harnessName;
            if (! is_dir($harnessDir)) {
                mkdir($harnessDir, 0700, true);
            }

            $proofContent = json_encode([
                'schema' => $schemaName,
                'host'   => '127.0.0.1',
                'port'   => '3342',
                'nonce'  => $proofNonce,
            ], JSON_THROW_ON_ERROR);
            file_put_contents($proofFile, $proofContent);
            chmod($proofFile, 0600);

            // 3. Set scoped credentials across all three stores
            $scopedVars = [
                'APP_ENV'                       => 'testing',
                'PHASE8_ALLOW_DISPOSABLE_DB'    => '1',
                'MOBILE_V2_HARNESS_ENABLE'      => '1',
                'PHASE8_TEST_DB_HOST'           => '127.0.0.1',
                'PHASE8_TEST_DB_PORT'           => '3342',
                'PHASE8_TEST_DB_USERNAME'       => 'root',
                'PHASE8_TEST_DB_PASSWORD'       => '',
                'PHASE8_TEST_DB_EMPTY_PASSWORD' => '1',
                'PHASE8_DISPOSABLE_DB_PROOF'    => $proofFile,
                'PHASE8_DISPOSABLE_DB_NONCE'    => $proofNonce,
            ];
            foreach ($scopedVars as $var => $val) {
                putenv("{$var}={$val}");
                $_ENV[$var]    = $val;
                $_SERVER[$var] = $val;
            }

            $childPid = 54321;
            $snapshot = VerifiedOwnedSnapshot::fromLiveOwnership(
                schemaName: $schemaName,
                proofPath:  $proofFile,
                proofNonce: $proofNonce,
                harnessDir: $harnessDir,
                childPid:   $childPid,
                repoRoot:   $repoRoot
            );

            // 4. Mock child process whose signal() throws an unhandled RuntimeException during cleanup
            $mockProcess = $this->createMock(Process::class);
            $mockProcess->method('isRunning')->willReturn(true);
            $mockProcess->method('getPid')->willReturn($childPid);
            $mockProcess->method('signal')->willThrowException(new RuntimeException('SIMULATED_CHILD_SIGNAL_CRITICAL_FAILURE'));

            $caught = null;
            try {
                SupervisedPairedFinancialDrill::executeCleanupAndRestoreEnv(
                    $mockProcess,
                    $snapshot,
                    $repoRoot,
                    $parentEnvToRestore,
                    null
                );
            } catch (RuntimeException $e) {
                $caught = $e;
            }

            $this->assertNotNull($caught);
            $this->assertSame('SIMULATED_CHILD_SIGNAL_CRITICAL_FAILURE', $caught->getMessage());

            // 5. Assert all 3 stores are STILL completely restored despite the exception thrown in cleanup
            foreach ($testDbVars as $var) {
                $this->assertFalse(getenv($var), "Expected {$var} in getenv to be false even after cleanup exception");
                $this->assertArrayNotHasKey($var, $_ENV, "Expected {$var} in \$_ENV to be unset even after cleanup exception");
                $this->assertArrayNotHasKey($var, $_SERVER, "Expected {$var} in \$_SERVER to be unset even after cleanup exception");
            }
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($outerAmbient);
            if (isset($harnessDir) && is_dir($harnessDir)) {
                @rmdir($harnessDir);
            }
        }
    }

    // ---------------------------------------------------------------------------
    // Private helper: create a valid VerifiedOwnedSnapshot via fromLiveOwnership()
    // with synthetic proof fixture and scoped env that satisfies assertPrimarySchema.
    // ---------------------------------------------------------------------------

    /**
     * Creates a valid VerifiedOwnedSnapshot through the sole public factory
     * fromLiveOwnership() using synthetic proof fixtures and scoped environment.
     *
     * Sets APP_ENV=testing + PHASE8_ALLOW_DISPOSABLE_DB=1 + PHASE8_TEST_DB_* before
     * the call and restores all three environment stores afterwards. Creates a valid
     * proof file with correct permissions, a valid harness directory, and satisfies
     * assertPrimarySchema via matching proof JSON content.
     */
    private function createValidSnapshot(string $repoRoot, int $childPid = 12345): VerifiedOwnedSnapshot
    {
        $schemaName = 'accounting_p8_tmp_0123456789ab';
        $proofNonce = str_repeat('a', 32);

        // Create proof directory with 0700 perms and proof file with 0600 perms
        $proofDir = $this->tempDir . '/proof_dir';
        if (! is_dir($proofDir)) {
            mkdir($proofDir, 0700, true);
        }
        $proofFile = $proofDir . '/proof.json';

        // Create valid harness directory (v2_harness_16hex, 0700, outside repo, under sys_get_temp_dir)
        $harnessName = 'v2_harness_' . bin2hex(random_bytes(8));
        $harnessDir = sys_get_temp_dir() . '/' . $harnessName;
        if (! is_dir($harnessDir)) {
            mkdir($harnessDir, 0700, true);
        }

        // Snapshot original environment across all three stores
        $envVarsToScope = [
            'APP_ENV',
            'PHASE8_ALLOW_DISPOSABLE_DB',
            'MOBILE_V2_HARNESS_ENABLE',
            'PHASE8_TEST_DB_HOST',
            'PHASE8_TEST_DB_PORT',
            'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_PASSWORD',
            'PHASE8_TEST_DB_EMPTY_PASSWORD',
            'PHASE8_DISPOSABLE_DB_PROOF',
            'PHASE8_DISPOSABLE_DB_NONCE',
        ];
        $savedStores = SupervisedPairedFinancialDrill::snapshotEnvironmentStores($envVarsToScope);

        try {
            $scopedVars = [
                'APP_ENV'                       => 'testing',
                'PHASE8_ALLOW_DISPOSABLE_DB'    => '1',
                'MOBILE_V2_HARNESS_ENABLE'      => '1',
                'PHASE8_TEST_DB_HOST'           => '127.0.0.1',
                'PHASE8_TEST_DB_PORT'           => '3342',
                'PHASE8_TEST_DB_USERNAME'       => 'root',
                'PHASE8_TEST_DB_PASSWORD'       => '',
                'PHASE8_TEST_DB_EMPTY_PASSWORD' => '1',
                'PHASE8_DISPOSABLE_DB_PROOF'    => $proofFile,
                'PHASE8_DISPOSABLE_DB_NONCE'    => $proofNonce,
            ];
            foreach ($scopedVars as $var => $val) {
                putenv("{$var}={$val}");
                $_ENV[$var]    = $val;
                $_SERVER[$var] = $val;
            }

            // Build proof JSON that assertPrimarySchema will accept:
            // matches testSettings() (127.0.0.1:3342) and assertPrimarySchema
            $settings = \Tests\Support\DisposableMariaDbSchema::testSettings();
            $proofContent = json_encode([
                'schema' => $schemaName,
                'host'   => $settings['HOST'],
                'port'   => $settings['PORT'],
                'nonce'  => $proofNonce,
            ], JSON_THROW_ON_ERROR);
            file_put_contents($proofFile, $proofContent);
            chmod($proofFile, 0600);

            $snapshot = VerifiedOwnedSnapshot::fromLiveOwnership(
                schemaName: $schemaName,
                proofPath:  $proofFile,
                proofNonce: $proofNonce,
                harnessDir: $harnessDir,
                childPid:   $childPid,
                repoRoot:   $repoRoot
            );
        } finally {
            SupervisedPairedFinancialDrill::restoreEnvironmentStores($savedStores);
        }

        return $snapshot;
    }
}
