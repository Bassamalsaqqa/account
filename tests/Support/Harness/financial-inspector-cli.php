<?php

declare(strict_types=1);

use Tests\Support\Harness\DisposableFinancialPostRunInspector;

// CLI entrypoint for Mobile V2 Financial Inspector and Outer Evidence Validator.
// Defaults to NO-EXECUTE; fails closed on missing flags or validation failure.
// Autoload only: does NOT perform full framework/kernel bootstrap.
// Does NOT touch production code, routes, or console commands.

$argvList = $_SERVER['argv'] ?? [];

$isBaseline = false;
$isComputeBaselineHash = false;
$isInspectDelta = false;
$isReconcile = false;
$isComposeTrustedReport = false;
$evidenceFile = null;
$trustedDbReportFile = null;
$outputFile = null;
$baselineFile = null;
$deltaFile = null;
$reconcileFile = null;
$credentialsFile = null;
$schemaName = null;
$companyId = null;
$runId = null;
$expectedEventId = null;
$expectedBackendHead = null;
$expectedAndroidPub = null;
$expectedAndroidExe = null;
$expectedHandoffDigest = null;
$expectedBaselineHash = null;
$expectedDbReportSha = null;
$expectedEvidenceSha = null;
$expectedPayloadDigest = null;
$expectedReportDigest = null;
$expectedScope = null;
$isAdvancedProbes = false;
$isRollbackProbeSnapshot = false;
$isVerifyRollbackZeroDelta = false;
$postProbeFile = null;
$probeReportsManifestFile = null;
$expectedProbeManifestSha = null;
$acpFile = null;
$expectedAcpSha = null;

foreach ($argvList as $arg) {
    if ($arg === '--baseline' || $arg === '--capture-baseline') {
        $isBaseline = true;
    } elseif ($arg === '--compute-baseline-hash') {
        $isComputeBaselineHash = true;
    } elseif ($arg === '--inspect-delta') {
        $isInspectDelta = true;
    } elseif ($arg === '--reconcile') {
        $isReconcile = true;
    } elseif ($arg === '--compose-trusted-report') {
        $isComposeTrustedReport = true;
    } elseif ($arg === '--advanced-probes') {
        $isAdvancedProbes = true;
    } elseif ($arg === '--rollback-probe-snapshot') {
        $isRollbackProbeSnapshot = true;
    } elseif ($arg === '--verify-rollback-zero-delta') {
        $isVerifyRollbackZeroDelta = true;
    } elseif (str_starts_with($arg, '--post-probe-file=')) {
        $postProbeFile = substr($arg, 18);
    } elseif (str_starts_with($arg, '--probe-reports-manifest=')) {
        $probeReportsManifestFile = substr($arg, 25);
    } elseif (str_starts_with($arg, '--expected-probe-manifest-sha=')) {
        $expectedProbeManifestSha = substr($arg, 30);
    } elseif (str_starts_with($arg, '--validate-evidence=')) {
        $evidenceFile = substr($arg, 20);
    } elseif (str_starts_with($arg, '--trusted-db-report=')) {
        $trustedDbReportFile = substr($arg, 20);
    } elseif (str_starts_with($arg, '--schema=')) {
        $schemaName = substr($arg, 9);
    } elseif (str_starts_with($arg, '--company-id=')) {
        $companyId = (int) substr($arg, 13);
    } elseif (str_starts_with($arg, '--run-id=')) {
        $runId = substr($arg, 9);
    } elseif (str_starts_with($arg, '--baseline-file=')) {
        $baselineFile = substr($arg, 16);
    } elseif (str_starts_with($arg, '--delta-file=')) {
        $deltaFile = substr($arg, 13);
    } elseif (str_starts_with($arg, '--reconcile-file=')) {
        $reconcileFile = substr($arg, 17);
    } elseif (str_starts_with($arg, '--credentials-file=')) {
        $credentialsFile = substr($arg, 19);
    } elseif (str_starts_with($arg, '--output=')) {
        $outputFile = substr($arg, 9);
    } elseif (str_starts_with($arg, '--expected-event-id=')) {
        $expectedEventId = substr($arg, 20);
    } elseif (str_starts_with($arg, '--expected-backend-head=')) {
        $expectedBackendHead = substr($arg, 24);
    } elseif (str_starts_with($arg, '--expected-android-pub=')) {
        $expectedAndroidPub = substr($arg, 23);
    } elseif (str_starts_with($arg, '--expected-android-exe=')) {
        $expectedAndroidExe = substr($arg, 23);
    } elseif (str_starts_with($arg, '--expected-handoff-digest=')) {
        $expectedHandoffDigest = substr($arg, 26);
    } elseif (str_starts_with($arg, '--expected-baseline-hash=')) {
        $expectedBaselineHash = substr($arg, 25);
    } elseif (str_starts_with($arg, '--expected-db-report-sha=')) {
        $expectedDbReportSha = substr($arg, 25);
    } elseif (str_starts_with($arg, '--expected-evidence-sha=')) {
        $expectedEvidenceSha = substr($arg, 24);
    } elseif (str_starts_with($arg, '--expected-payload-digest=')) {
        $expectedPayloadDigest = substr($arg, 26);
    } elseif (str_starts_with($arg, '--expected-report-digest=')) {
        $expectedReportDigest = substr($arg, 25);
    } elseif (str_starts_with($arg, '--expected-scope=')) {
        $expectedScope = substr($arg, 17);
    } elseif (str_starts_with($arg, '--acp-file=')) {
        $acpFile = substr($arg, 11);
    } elseif (str_starts_with($arg, '--expected-acp-sha=')) {
        $expectedAcpSha = substr($arg, 19);
    }
}

// SAFE AUTOLOAD ONLY
require __DIR__.'/../../../vendor/autoload.php';

// Safe extraction of private proof credentials if provided (no secret stdout)
if ($credentialsFile !== null) {
    try {
        $creds = DisposableFinancialPostRunInspector::loadSecureBootstrapCredentials($credentialsFile, $schemaName);
        if ($schemaName === null || $schemaName === '') {
            $schemaName = $creds['schema_name'];
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "Error: Credentials file verification failed: {$e->getMessage()}\n");
        exit(1);
    }
}

// Default NO-EXECUTE: If no valid action flag is provided, exit non-zero without touching DB.
if (! $isBaseline && ! $isComputeBaselineHash && ! $isInspectDelta && ! $isReconcile && ! $isComposeTrustedReport && ! $isRollbackProbeSnapshot && ! $isVerifyRollbackZeroDelta && $evidenceFile === null) {
    fwrite(STDERR, "Error: Inspector defaults to NO-EXECUTE. Specify --baseline, --compute-baseline-hash, --inspect-delta, --reconcile, --compose-trusted-report, --rollback-probe-snapshot, --verify-rollback-zero-delta, or --validate-evidence=<file>.\n");
    exit(1);
}

// Action 0: Compute Canonical Baseline Hash (pure computation, zero DB access)
if ($isComputeBaselineHash) {
    if ($baselineFile === null || ! is_file($baselineFile)) {
        fwrite(STDERR, "Error: --compute-baseline-hash requires valid --baseline-file=<path>.\n");
        exit(1);
    }
    if ($schemaName === null || $schemaName === '') {
        fwrite(STDERR, "Error: --compute-baseline-hash requires --schema=<string>.\n");
        exit(1);
    }
    if ($companyId === null || $companyId <= 0) {
        fwrite(STDERR, "Error: --compute-baseline-hash requires --company-id=<int>.\n");
        exit(1);
    }
    $baselineData = json_decode((string) file_get_contents($baselineFile), true);
    if (! is_array($baselineData)) {
        fwrite(STDERR, "Error: Baseline file does not contain valid JSON.\n");
        exit(1);
    }
    $hash = DisposableFinancialPostRunInspector::computeCanonicalBaselineHash($companyId, $schemaName, $baselineData);
    if ($outputFile !== null) {
        file_put_contents($outputFile, $hash."\n");
    } else {
        fwrite(STDOUT, $hash."\n");
    }
    exit(0);
}

// Action 1: Pure Evidence JSON Validation (zero DB access)
if ($evidenceFile !== null) {
    if (! is_file($evidenceFile)) {
        fwrite(STDERR, "Error: Evidence file '{$evidenceFile}' not found.\n");
        exit(1);
    }

    $rawEvidence = (string) file_get_contents($evidenceFile);
    if ($expectedEvidenceSha === null || $expectedEvidenceSha === '') {
        fwrite(STDERR, "Error: --expected-evidence-sha=<sha256> is mandatory for outer evidence validation.\n");
        exit(1);
    }
    $actualEvidenceSha = hash('sha256', $rawEvidence);
    if (! hash_equals($expectedEvidenceSha, $actualEvidenceSha)) {
        fwrite(STDERR, "Error: Evidence file SHA256 mismatch: expected '{$expectedEvidenceSha}', got '{$actualEvidenceSha}'.\n");
        exit(1);
    }

    if ($runId === null || $runId === '') {
        fwrite(STDERR, "Error: --run-id=<uuid> is mandatory and cannot be omitted for evidence validation.\n");
        exit(1);
    }

    if ($expectedEventId === null || $expectedEventId === '' || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $expectedEventId)) {
        fwrite(STDERR, "Error: --expected-event-id=<uuid> is mandatory and must be a valid UUID for evidence validation.\n");
        exit(1);
    }

    if ($expectedBaselineHash === null || $expectedBaselineHash === '') {
        fwrite(STDERR, "Error: --expected-baseline-hash=<sha256> is mandatory and cannot fall back to trusted DB report.\n");
        exit(1);
    }

    if ($expectedPayloadDigest === null || $expectedPayloadDigest === '') {
        fwrite(STDERR, "Error: --expected-payload-digest=<sha256> is mandatory for outer evidence validation.\n");
        exit(1);
    }

    if ($trustedDbReportFile === null) {
        fwrite(STDERR, "Error: --trusted-db-report=<file> is mandatory for outer evidence validation.\n");
        exit(1);
    }

    if (! is_file($trustedDbReportFile)) {
        fwrite(STDERR, "Error: Trusted DB report file '{$trustedDbReportFile}' not found.\n");
        exit(1);
    }

    $rawDbReportBytes = (string) file_get_contents($trustedDbReportFile);
    $actualDbReportSha = hash('sha256', $rawDbReportBytes);
    if ($expectedDbReportSha === null || $expectedDbReportSha === '') {
        fwrite(STDERR, "Error: --expected-db-report-sha=<sha256> is mandatory when validating against trusted DB report.\n");
        exit(1);
    }
    if (! hash_equals($expectedDbReportSha, $actualDbReportSha)) {
        fwrite(STDERR, "Error: Trusted DB report file SHA256 mismatch: expected '{$expectedDbReportSha}', got '{$actualDbReportSha}'.\n");
        exit(1);
    }

    $trustedDbReport = json_decode($rawDbReportBytes, true);
    if (! is_array($trustedDbReport)) {
        fwrite(STDERR, "Error: Trusted DB report file contains invalid JSON.\n");
        exit(1);
    }

    $expectedPins = [
        'clientPublicationHead' => $expectedAndroidPub ?? DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
        'clientExecutableHead' => $expectedAndroidExe ?? DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
        'backendExecutionHead' => $expectedBackendHead ?? '',
        'handoffDigest' => $expectedHandoffDigest ?? '',
        'expectedScope' => $expectedScope ?? 'ROOT_AUTHORIZED_PAIRED_EXECUTION',
        'expectedSchemaName' => $schemaName ?? '',
        'companyId' => $companyId ?? 0,
        'expectedCompanyId' => $companyId ?? 0,
        'expectedRunId' => $runId,
        'expectedEventId' => $expectedEventId,
        'expectedBaselineHash' => $expectedBaselineHash,
        'expectedPayloadDigest' => $expectedPayloadDigest,
    ];

    $effectiveScope = $expectedScope ?? 'ROOT_AUTHORIZED_PAIRED_EXECUTION';
    if ($isAdvancedProbes || $effectiveScope === 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION') {
        $expectedPins['expectedScope'] = 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION';
        $expectedPins['expectedClientEventId'] = $expectedEventId;
        $expectedPins['expectedHandoffSha'] = $expectedHandoffDigest;
        $expectedPins['expectedDbReportSha'] = $expectedDbReportSha;
        $expectedPins['actualDbReportFileSha'] = $actualDbReportSha;
        $reportDigestPin = $expectedReportDigest ?? (isset($trustedDbReport['report_digest']) ? (string) $trustedDbReport['report_digest'] : (string) ($trustedDbReport['digest'] ?? ''));
        $expectedPins['expectedTrustedReportDigest'] = $reportDigestPin;
        $expectedPins['expectedReportDigest'] = $reportDigestPin;
        $root = dirname(__DIR__, 3);
        $expectedPins['expectedSourcePins'] = [
            'source_fixture_sha256' => (string) hash_file('sha256', $root.'/tests/Support/Harness/DisposableMobileV2BackendHarness.php'),
            'guard_test_sha256' => (string) hash_file('sha256', $root.'/tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php'),
            'cli_command_sha256' => (string) hash_file('sha256', $root.'/tests/Support/Harness/MobileV2HarnessCommand.php'),
            'cli_runner_sha256' => (string) hash_file('sha256', $root.'/tests/Support/Harness/mobile-v2-harness-cli.php'),
        ];
        if ($probeReportsManifestFile === null || $probeReportsManifestFile === '') {
            fwrite(STDERR, "Error: --probe-reports-manifest=<path> is mandatory and must be a valid non-symlink file in advanced scope.\n");
            exit(1);
        }
        if ($expectedProbeManifestSha === null || $expectedProbeManifestSha === '') {
            fwrite(STDERR, "Error: --expected-probe-manifest-sha=<sha256> is mandatory in advanced scope.\n");
            exit(1);
        }
        try {
            $manifestRawBytes = DisposableFinancialPostRunInspector::loadSecureContainedFile(
                $probeReportsManifestFile,
                dirname($probeReportsManifestFile),
                'probe_reports_manifest.json',
                'PROBE_REPORTS_MANIFEST',
                $expectedProbeManifestSha
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, "Error: Probe reports manifest verification failed: {$e->getMessage()}\n");
            exit(1);
        }
        $actualManifestSha = hash('sha256', $manifestRawBytes);
        $expectedPins['actualProbeReportsManifestSha'] = $actualManifestSha;
        $expectedPins['expectedProbeReportsManifestSha'] = $expectedProbeManifestSha;
        $expectedPins['probeReportsManifestPath'] = $probeReportsManifestFile;

        if ($acpFile === null || $acpFile === '') {
            fwrite(STDERR, "Error: --acp-file=<path> is mandatory and must be a valid non-symlink file in advanced scope.\n");
            exit(1);
        }
        if ($expectedAcpSha === null || $expectedAcpSha === '') {
            fwrite(STDERR, "Error: --expected-acp-sha=<sha256> is mandatory in advanced scope.\n");
            exit(1);
        }
        try {
            $acpData = DisposableFinancialPostRunInspector::loadSecureContainedJsonFile(
                $acpFile,
                dirname($acpFile),
                'advanced_control_packet.json',
                'ADVANCED_CONTROL_PACKET',
                $expectedAcpSha
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, "Error: Advanced control packet verification failed: {$e->getMessage()}\n");
            exit(1);
        }
        $expectedPins['advancedControlPacketData'] = $acpData;
        $expectedPins['expectedAdvancedControlPacketSha'] = $expectedAcpSha;
        $expectedPins['advancedControlPacketPath'] = $acpFile;

        $validation = DisposableFinancialPostRunInspector::validateAdvancedProbeEvidenceJson($rawEvidence, $expectedPins, $trustedDbReport);
    } else {
        $validation = DisposableFinancialPostRunInspector::validateOuterEvidenceJson($rawEvidence, $expectedPins, $trustedDbReport);
    }

    $outJson = json_encode($validation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($outputFile !== null) {
        file_put_contents($outputFile, $outJson."\n");
    } else {
        fwrite(STDOUT, $outJson."\n");
    }

    exit($validation['is_valid'] ? 0 : 1);
}

// Action 2: Compose Trusted DB Report from verified components
if ($isComposeTrustedReport) {
    if ($baselineFile === null || ! is_file($baselineFile)) {
        fwrite(STDERR, "Error: --compose-trusted-report requires --baseline-file=<path>.\n");
        exit(1);
    }
    if ($deltaFile === null || ! is_file($deltaFile)) {
        fwrite(STDERR, "Error: --compose-trusted-report requires --delta-file=<path>.\n");
        exit(1);
    }
    if ($reconcileFile === null || ! is_file($reconcileFile)) {
        fwrite(STDERR, "Error: --compose-trusted-report requires --reconcile-file=<path>.\n");
        exit(1);
    }
    if ($schemaName === null || $schemaName === '') {
        fwrite(STDERR, "Error: --compose-trusted-report requires --schema=<accounting_p8_tmp_...>.\n");
        exit(1);
    }
    if ($companyId === null || $companyId <= 0) {
        fwrite(STDERR, "Error: --compose-trusted-report requires --company-id=<int>.\n");
        exit(1);
    }
    if ($runId === null || $runId === '') {
        fwrite(STDERR, "Error: --compose-trusted-report requires --run-id=<uuid>.\n");
        exit(1);
    }
    if ($expectedBackendHead === null || $expectedBackendHead === '') {
        fwrite(STDERR, "Error: --compose-trusted-report requires --expected-backend-head=<sha>.\n");
        exit(1);
    }
    if ($expectedHandoffDigest === null || $expectedHandoffDigest === '') {
        fwrite(STDERR, "Error: --compose-trusted-report requires --expected-handoff-digest=<sha>.\n");
        exit(1);
    }
    if ($expectedEventId === null || $expectedEventId === '' || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $expectedEventId)) {
        fwrite(STDERR, "Error: --compose-trusted-report requires valid UUID --expected-event-id=<uuid>.\n");
        exit(1);
    }

    $baselineData = json_decode((string) file_get_contents($baselineFile), true);
    $deltaData = json_decode((string) file_get_contents($deltaFile), true);
    $reconcileData = json_decode((string) file_get_contents($reconcileFile), true);

    if (! is_array($baselineData) || ! is_array($deltaData) || ! is_array($reconcileData)) {
        fwrite(STDERR, "Error: Invalid JSON in input report files.\n");
        exit(1);
    }

    try {
        $metadata = [
            'scope' => $expectedScope ?? 'ROOT_AUTHORIZED_PAIRED_EXECUTION',
            'runId' => $runId,
            'eventId' => $expectedEventId,
            'schemaName' => $schemaName,
            'companyId' => $companyId,
            'sourceHead' => $expectedBackendHead,
            'handoffDigest' => $expectedHandoffDigest,
        ];

        $probeReportsManifest = null;
        if ($metadata['scope'] === 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION') {
            if ($probeReportsManifestFile === null || $probeReportsManifestFile === '') {
                fwrite(STDERR, "Error: --compose-trusted-report in advanced scope requires --probe-reports-manifest=<path>.\n");
                exit(1);
            }
            if ($expectedProbeManifestSha === null || $expectedProbeManifestSha === '') {
                fwrite(STDERR, "Error: --compose-trusted-report in advanced scope requires --expected-probe-manifest-sha=<sha256>.\n");
                exit(1);
            }
            if ($acpFile === null || $acpFile === '') {
                fwrite(STDERR, "Error: --compose-trusted-report in advanced scope requires --acp-file=<path>.\n");
                exit(1);
            }
            if ($expectedAcpSha === null || $expectedAcpSha === '') {
                fwrite(STDERR, "Error: --compose-trusted-report in advanced scope requires --expected-acp-sha=<sha256>.\n");
                exit(1);
            }
            try {
                $acpData = DisposableFinancialPostRunInspector::loadSecureContainedJsonFile(
                    $acpFile,
                    dirname($acpFile),
                    'advanced_control_packet.json',
                    'ADVANCED_CONTROL_PACKET',
                    $expectedAcpSha
                );
            } catch (\Throwable $e) {
                fwrite(STDERR, "Error: Advanced control packet verification failed: {$e->getMessage()}\n");
                exit(1);
            }
            $metadata['advancedControlPacketData'] = $acpData;
            $metadata['advancedControlPacketSha256'] = $expectedAcpSha;
            $metadata['advancedControlPacketPath'] = $acpFile;

            try {
                $probeReportsManifest = DisposableFinancialPostRunInspector::loadSecureContainedJsonFile(
                    $probeReportsManifestFile,
                    dirname($probeReportsManifestFile),
                    'probe_reports_manifest.json',
                    'PROBE_REPORTS_MANIFEST',
                    $expectedProbeManifestSha
                );
            } catch (\Throwable $e) {
                fwrite(STDERR, "Error: Probe reports manifest verification failed: {$e->getMessage()}\n");
                exit(1);
            }
        } elseif ($probeReportsManifestFile !== null && $probeReportsManifestFile !== '') {
            try {
                $probeReportsManifest = DisposableFinancialPostRunInspector::loadSecureContainedJsonFile(
                    $probeReportsManifestFile,
                    dirname($probeReportsManifestFile),
                    'probe_reports_manifest.json',
                    'PROBE_REPORTS_MANIFEST',
                    $expectedProbeManifestSha
                );
            } catch (\Throwable $e) {
                fwrite(STDERR, "Error: Probe reports manifest verification failed: {$e->getMessage()}\n");
                exit(1);
            }
        }

        $report = DisposableFinancialPostRunInspector::composeTrustedDbReport(
            $metadata,
            $baselineData,
            $deltaData,
            $reconcileData,
            $probeReportsManifest
        );

        $outJson = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($outputFile !== null) {
            file_put_contents($outputFile, $outJson."\n");
        } else {
            fwrite(STDOUT, $outJson."\n");
        }

        $ok = ($report['is_canonical_delta_valid'] ?? false)
            && ($report['gl_batches_balanced'] ?? false)
            && ($report['all_healthy'] ?? false);

        exit($ok ? 0 : 1);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Report composition failed: {$e->getMessage()}\n");
        exit(1);
    }
}

// Database actions require explicit schema and company ID
if ($schemaName === null || $schemaName === '') {
    fwrite(STDERR, "Error: Database inspection requires explicit non-empty --schema=<accounting_p8_tmp_...>.\n");
    exit(1);
}

if ($companyId === null || $companyId <= 0) {
    fwrite(STDERR, "Error: Database inspection requires explicit positive --company-id=<int>.\n");
    exit(1);
}

// Action 3: Capture Baseline
if ($isBaseline) {
    try {
        $baseline = DisposableFinancialPostRunInspector::captureBaseline($companyId, $schemaName);
        $outJson = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($outputFile !== null) {
            file_put_contents($outputFile, $outJson."\n");
        } else {
            fwrite(STDOUT, $outJson."\n");
        }
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Baseline capture failed: {$e->getMessage()}\n");
        exit(1);
    }
}

// Action 4: Inspect Delta vs Baseline
if ($isInspectDelta) {
    if ($baselineFile === null || ! is_file($baselineFile)) {
        fwrite(STDERR, "Error: --inspect-delta requires valid --baseline-file=<path>.\n");
        exit(1);
    }

    if ($expectedEventId === null || $expectedEventId === '' || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $expectedEventId)) {
        fwrite(STDERR, "Error: --inspect-delta requires valid UUID --expected-event-id=<uuid>.\n");
        exit(1);
    }

    if ($expectedPayloadDigest === null || $expectedPayloadDigest === '') {
        fwrite(STDERR, "Error: --inspect-delta requires --expected-payload-digest=<sha256>.\n");
        exit(1);
    }

    $baselineData = json_decode((string) file_get_contents($baselineFile), true);
    if (! is_array($baselineData)) {
        fwrite(STDERR, "Error: Baseline file does not contain valid JSON.\n");
        exit(1);
    }

    try {
        $deltaResult = DisposableFinancialPostRunInspector::inspectDelta(
            $companyId,
            $baselineData,
            $expectedEventId,
            $schemaName,
            $expectedPayloadDigest
        );

        $outJson = json_encode($deltaResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($outputFile !== null) {
            file_put_contents($outputFile, $outJson."\n");
        } else {
            fwrite(STDOUT, $outJson."\n");
        }

        exit($deltaResult['is_canonical_delta_valid'] ? 0 : 1);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Delta inspection failed: {$e->getMessage()}\n");
        exit(1);
    }
}

// Action 5: Run 6 Reconciliations (Safe isolated container, autoload only, NO Kernel bootstrap)
if ($isReconcile) {
    try {
        $reconcileResult = DisposableFinancialPostRunInspector::runSixReconciliations($companyId, $schemaName);

        $outJson = json_encode($reconcileResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($outputFile !== null) {
            file_put_contents($outputFile, $outJson."\n");
        } else {
            fwrite(STDOUT, $outJson."\n");
        }

        exit($reconcileResult['all_healthy'] ? 0 : 1);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Reconciliation failed: {$e->getMessage()}\n");
        exit(1);
    }
}

// Action 6: Capture Rollback Probe Snapshot
if ($isRollbackProbeSnapshot) {
    try {
        $pdo = DisposableFinancialPostRunInspector::assertExecutionGates($schemaName);
        $snapshot = DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot($pdo, $companyId, $schemaName);
        $outJson = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($outputFile !== null) {
            file_put_contents($outputFile, $outJson."\n");
        } else {
            fwrite(STDOUT, $outJson."\n");
        }
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Rollback probe snapshot failed: {$e->getMessage()}\n");
        exit(1);
    }
}

// Action 7: Verify Rollback Zero Delta
if ($isVerifyRollbackZeroDelta) {
    if ($baselineFile === null || ! is_file($baselineFile)) {
        fwrite(STDERR, "Error: --verify-rollback-zero-delta requires valid --baseline-file=<path>.\n");
        exit(1);
    }
    if ($postProbeFile === null || ! is_file($postProbeFile)) {
        fwrite(STDERR, "Error: --verify-rollback-zero-delta requires valid --post-probe-file=<path>.\n");
        exit(1);
    }
    $baseData = json_decode((string) file_get_contents($baselineFile), true);
    $postData = json_decode((string) file_get_contents($postProbeFile), true);
    if (! is_array($baseData) || ! is_array($postData)) {
        fwrite(STDERR, "Error: Invalid JSON in baseline or post-probe file.\n");
        exit(1);
    }
    try {
        $res = DisposableFinancialPostRunInspector::assertRollbackZeroDelta($baseData, $postData);
        $outJson = json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($outputFile !== null) {
            file_put_contents($outputFile, $outJson."\n");
        } else {
            fwrite(STDOUT, $outJson."\n");
        }
        exit($res['is_zero_delta'] ? 0 : 1);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Rollback zero delta assertion failed: {$e->getMessage()}\n");
        exit(1);
    }
}

