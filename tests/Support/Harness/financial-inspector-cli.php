<?php

declare(strict_types=1);

use Tests\Support\Harness\DisposableFinancialPostRunInspector;

// CLI entrypoint for Mobile V2 Financial Inspector and Outer Evidence Validator.
// Defaults to NO-EXECUTE; fails closed on missing flags or validation failure.
// Autoload only: does NOT perform full framework/kernel bootstrap.
// Does NOT touch production code, routes, or console commands.

$argvList = $_SERVER['argv'] ?? [];

$isBaseline = false;
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
$expectedScope = null;

foreach ($argvList as $arg) {
    if ($arg === '--baseline' || $arg === '--capture-baseline') {
        $isBaseline = true;
    } elseif ($arg === '--inspect-delta') {
        $isInspectDelta = true;
    } elseif ($arg === '--reconcile') {
        $isReconcile = true;
    } elseif ($arg === '--compose-trusted-report') {
        $isComposeTrustedReport = true;
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
    } elseif (str_starts_with($arg, '--expected-scope=')) {
        $expectedScope = substr($arg, 17);
    }
}

// SAFE AUTOLOAD ONLY
require __DIR__.'/../../../vendor/autoload.php';

// Safe extraction of private proof credentials if provided (no secret stdout)
if ($credentialsFile !== null && is_file($credentialsFile)) {
    $content = (string) file_get_contents($credentialsFile);
    $parsed = json_decode($content, true);
    if (! is_array($parsed)) {
        $parsed = [];
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $parsed[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
            }
        }
    }
    foreach ($parsed as $k => $v) {
        if (in_array($k, ['HOST', 'PORT', 'USERNAME', 'PASSWORD', 'EMPTY_PASSWORD'], true)) {
            putenv("PHASE8_TEST_DB_{$k}={$v}");
        } elseif (str_starts_with($k, 'PHASE8_TEST_DB_') || in_array($k, ['PHASE8_ALLOW_DISPOSABLE_DB', 'MOBILE_V2_HARNESS_ENABLE', 'APP_ENV'], true)) {
            putenv("{$k}={$v}");
        }
    }
}

// Default NO-EXECUTE: If no valid action flag is provided, exit non-zero without touching DB.
if (! $isBaseline && ! $isInspectDelta && ! $isReconcile && ! $isComposeTrustedReport && $evidenceFile === null) {
    fwrite(STDERR, "Error: Inspector defaults to NO-EXECUTE. Specify --baseline, --inspect-delta, --reconcile, --compose-trusted-report, or --validate-evidence=<file>.\n");
    exit(1);
}

// Action 1: Pure Evidence JSON Validation (zero DB access)
if ($evidenceFile !== null) {
    if (! is_file($evidenceFile)) {
        fwrite(STDERR, "Error: Evidence file '{$evidenceFile}' not found.\n");
        exit(1);
    }

    $rawEvidence = file_get_contents($evidenceFile);
    if ($rawEvidence === false) {
        fwrite(STDERR, "Error: Unable to read evidence file '{$evidenceFile}'.\n");
        exit(1);
    }

    $trustedDbReport = null;
    if ($trustedDbReportFile !== null) {
        if (! is_file($trustedDbReportFile)) {
            fwrite(STDERR, "Error: Trusted DB report file '{$trustedDbReportFile}' not found.\n");
            exit(1);
        }
        $trustedDbReport = json_decode((string) file_get_contents($trustedDbReportFile), true);
        if (! is_array($trustedDbReport)) {
            fwrite(STDERR, "Error: Trusted DB report file contains invalid JSON.\n");
            exit(1);
        }
    }

    $expectedPins = [
        'clientPublicationHead' => $expectedAndroidPub ?? DisposableFinancialPostRunInspector::APPROVED_ANDROID_PUBLICATION_SHA,
        'clientExecutableHead' => $expectedAndroidExe ?? DisposableFinancialPostRunInspector::APPROVED_ANDROID_EXECUTABLE_SHA,
        'backendExecutionHead' => $expectedBackendHead ?? '',
        'handoffDigest' => $expectedHandoffDigest ?? '',
        'expectedScope' => $expectedScope ?? 'ROOT_AUTHORIZED_PAIRED_EXECUTION',
        'expectedSchemaName' => $schemaName ?? '',
        'companyId' => $companyId ?? 0,
        'expectedRunId' => $runId ?? '',
        'expectedBaselineHash' => $expectedBaselineHash ?? ($trustedDbReport['baseline_hash'] ?? ''),
    ];

    if ($expectedEventId !== null && $expectedEventId !== '') {
        $expectedPins['expectedEventId'] = $expectedEventId;
    }

    $validation = DisposableFinancialPostRunInspector::validateOuterEvidenceJson($rawEvidence, $expectedPins, $trustedDbReport);

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
            'schemaName' => $schemaName,
            'companyId' => $companyId,
            'sourceHead' => $expectedBackendHead,
            'handoffDigest' => $expectedHandoffDigest,
        ];

        $report = DisposableFinancialPostRunInspector::composeTrustedDbReport(
            $metadata,
            $baselineData,
            $deltaData,
            $reconcileData
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
            $schemaName
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
