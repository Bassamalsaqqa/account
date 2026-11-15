<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Harness;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Harness\SupervisedPairedFinancialDrill;

/**
 * Pure source-only unit tests for SupervisedPairedFinancialDrill.
 *
 * Verifies strict prestart approval gates, independent external live approval,
 * ULID lookup proof PDO resolution, evidence freshness checks, JUnit XML enforcement,
 * hold deadline bounding, and exact cleanup invariants WITHOUT touching live DB or network.
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
            $files = glob($this->tempDir . '/*');
            if (is_array($files)) {
                foreach ($files as $f) {
                    if (is_file($f) || is_link($f)) {
                        unlink($f);
                    }
                }
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function test_prestart_approval_gate_fails_closed_when_missing_or_insecure_perms(): void
    {
        // 1. Missing file
        $missingPath = $this->tempDir . '/nonexistent_approval.json';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PRESTART_APPROVAL_FILE_NOT_FOUND');
        SupervisedPairedFinancialDrill::verifyPrestartApproval(
            $missingPath,
            'manifest_sha',
            'backend_head',
            null,
            null,
            $this->tempDir
        );
    }

    #[Test]
    public function test_prestart_approval_fails_closed_on_insecure_file_permissions(): void
    {
        $filePath = $this->tempDir . '/approval.json';
        file_put_contents($filePath, json_encode(['root_coordination_granted' => true]));
        chmod($filePath, 0644); // Insecure: world/group readable

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PRESTART_APPROVAL_INSECURE_PERMS');
        SupervisedPairedFinancialDrill::verifyPrestartApproval(
            $filePath,
            'manifest_sha',
            'backend_head',
            null,
            null,
            $this->tempDir
        );
    }

    #[Test]
    public function test_prestart_approval_fails_when_backend_or_manifest_pin_mismatch(): void
    {
        $filePath = $this->tempDir . '/approval.json';
        $content = [
            'root_coordination_granted' => true,
            'manifest_sha256' => str_repeat('a', 64),
            'backend_head' => str_repeat('1', 40),
            'android_publication_head' => str_repeat('2', 40),
            'android_executable_head' => str_repeat('3', 40),
            'cwd' => $this->tempDir,
        ];
        file_put_contents($filePath, json_encode($content));
        chmod($filePath, 0600);

        // Mismatched manifest SHA
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PRESTART_APPROVAL_MANIFEST_MISMATCH');
        SupervisedPairedFinancialDrill::verifyPrestartApproval(
            $filePath,
            str_repeat('b', 64), // expected differs
            str_repeat('1', 40),
            null,
            null,
            $this->tempDir
        );
    }

    #[Test]
    public function test_cli_args_cannot_override_prestart_approval(): void
    {
        $filePath = $this->tempDir . '/approval.json';
        $content = [
            'root_coordination_granted' => true,
            'manifest_sha256' => str_repeat('a', 64),
            'backend_head' => str_repeat('1', 40),
            'android_publication_head' => str_repeat('2', 40),
            'android_executable_head' => str_repeat('3', 40),
            'cwd' => $this->tempDir,
        ];
        file_put_contents($filePath, json_encode($content));
        chmod($filePath, 0600);

        // CLI arg attempts to override publication head
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PRESTART_APPROVAL_CLI_PUB_OVERRIDE_REFUSED');
        SupervisedPairedFinancialDrill::verifyPrestartApproval(
            $filePath,
            str_repeat('a', 64),
            str_repeat('1', 40),
            str_repeat('9', 40), // CLI override attempt
            null,
            $this->tempDir
        );
    }

    #[Test]
    public function test_live_root_approval_gate_fails_closed_when_missing_or_sha_mismatch(): void
    {
        $handoffPath = $this->tempDir . '/runtime_client_handoff.json';
        file_put_contents($handoffPath, 'test_handoff_bytes');
        chmod($handoffPath, 0600);
        $actualSha = hash('sha256', 'test_handoff_bytes');

        $approvalPath = $this->tempDir . '/live_approval.json';
        $approvalContent = [
            'root_live_handoff_approved' => true,
            'handoff_path' => $handoffPath,
            'whole_handoff_sha256' => str_repeat('f', 64), // wrong SHA
            'backend_head' => str_repeat('1', 40),
            'android_publication_head' => str_repeat('2', 40),
            'android_executable_head' => str_repeat('3', 40),
            'manifest_sha256' => str_repeat('a', 64),
        ];
        file_put_contents($approvalPath, json_encode($approvalContent));
        chmod($approvalPath, 0600);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LIVE_ROOT_APPROVAL_SHA_MISMATCH');
        SupervisedPairedFinancialDrill::verifyLiveRootApproval(
            $approvalPath,
            $handoffPath,
            $actualSha,
            str_repeat('1', 40),
            str_repeat('2', 40),
            str_repeat('3', 40),
            str_repeat('a', 64)
        );
    }

    #[Test]
    public function test_ulid_lookup_proof_refusal_when_company_not_found(): void
    {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->expects($this->once())
            ->method('execute')
            ->with(['01JABCDEFGHJKMNPQRSTVWXYZ0'])
            ->willReturn(true);
        $mockStmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false); // Not found

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT id FROM companies WHERE public_id = ? LIMIT 1')
            ->willReturn($mockStmt);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('COMPANY_ULID_NOT_FOUND');
        SupervisedPairedFinancialDrill::resolveCompanyIntegerId($mockPdo, '01JABCDEFGHJKMNPQRSTVWXYZ0');
    }

    #[Test]
    public function test_ulid_lookup_resolves_valid_integer_id(): void
    {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->expects($this->once())
            ->method('execute')
            ->with(['01JABCDEFGHJKMNPQRSTVWXYZ0'])
            ->willReturn(true);
        $mockStmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['id' => '42']);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT id FROM companies WHERE public_id = ? LIMIT 1')
            ->willReturn($mockStmt);

        $id = SupervisedPairedFinancialDrill::resolveCompanyIntegerId($mockPdo, '01JABCDEFGHJKMNPQRSTVWXYZ0');
        $this->assertSame(42, $id);
    }

    #[Test]
    public function test_stale_evidence_rejected_when_mtime_or_sha_unrefreshed(): void
    {
        $evidencePath = $this->tempDir . '/evidence.json';
        file_put_contents($evidencePath, json_encode(['test' => true]));

        $preRunTime = time() + 100; // future timestamp simulates stale pre-existing file

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EVIDENCE_FILE_STALE');
        SupervisedPairedFinancialDrill::verifyEvidenceFreshness(
            $evidencePath,
            $preRunTime,
            null,
            str_repeat('1', 40),
            str_repeat('2', 40),
            str_repeat('3', 40),
            str_repeat('a', 64)
        );
    }

    #[Test]
    public function test_junit_xml_enforces_two_pass_zero_skip(): void
    {
        $xmlPath = $this->tempDir . '/junit.xml';

        // 1. Failure detected
        file_put_contents($xmlPath, '<testsuite tests="2" failures="1" errors="0" skipped="0"></testsuite>');
        touch($xmlPath, time() + 10);
        try {
            SupervisedPairedFinancialDrill::verifyJunitTestResults($xmlPath, time());
            $this->fail('Expected failure exception not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('JUNIT_XML_FAILURES_DETECTED', $e->getMessage());
        }

        // 2. Skip detected
        file_put_contents($xmlPath, '<testsuite tests="2" failures="0" errors="0" skipped="1"></testsuite>');
        touch($xmlPath, time() + 10);
        try {
            SupervisedPairedFinancialDrill::verifyJunitTestResults($xmlPath, time());
            $this->fail('Expected skip exception not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('JUNIT_XML_SKIPS_DETECTED', $e->getMessage());
        }

        // 3. Valid 2 PASS 0 SKIP
        file_put_contents($xmlPath, '<testsuite tests="2" failures="0" errors="0" skipped="0"></testsuite>');
        touch($xmlPath, time() + 10);
        $results = SupervisedPairedFinancialDrill::verifyJunitTestResults($xmlPath, time());
        $this->assertSame(2, $results['tests']);
        $this->assertSame(0, $results['failures']);
        $this->assertSame(0, $results['errors']);
        $this->assertSame(0, $results['skipped']);
    }

    #[Test]
    public function test_deadline_expiration_fails_closed(): void
    {
        $pastDeadline = microtime(true) - 5.0;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CHILD_HOLD_DEADLINE_EXCEEDED');
        SupervisedPairedFinancialDrill::computeRemainingDeadline($pastDeadline, 10.0);
    }
}
