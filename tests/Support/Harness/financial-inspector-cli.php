<?php

declare(strict_types=1);

use Tests\Support\Harness\DisposableFinancialPostRunInspector;

// CLI entrypoint for Mobile V2 Financial Inspector and Outer Evidence Validator.
// Defaults to NO-EXECUTE; fails closed on missing flags or validation failure.
// Does NOT touch production code or console commands.

$argvList = $_SERVER['argv'] ?? [];

$isBaseline = false;
$isInspectDelta = false;
$isReconcile = false;
$evidenceFile = null;
$trustedDbReportFile = null;
$outputFile = null;
$baselineFile = null;
$schemaName = null;
$companyId = null;
$expectedEventId = null;
$expectedBackendHead = null;
$expectedAndroidPub = null;
$expectedAndroidExe = null;
$expectedHandoffDigest = null;
$expectedScope = null;

foreach ($argvList as $arg) {
    if ($arg === '--baseline' || $arg === '--capture-baseline') {
        $isBaseline = true;
    } elseif ($arg === '--inspect-delta') {
        $isInspectDelta = true;
    } elseif ($arg === '--reconcile') {
        $isReconcile = true;
    } elseif (str_starts_with($arg, '--validate-evidence=')) {
        $evidenceFile = substr($arg, 20);
    } elseif (str_starts_with($arg, '--trusted-db-report=')) {
        $trustedDbReportFile = substr($arg, 20);
    } elseif (str_starts_with($arg, '--schema=')) {
        $schemaName = substr($arg, 9);
    } elseif (str_starts_with($arg, '--company-id=')) {
        $companyId = (int) substr($arg, 13);
    } elseif (str_starts_with($arg, '--baseline-file=')) {
        $baselineFile = substr($arg, 16);
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
    } elseif (str_starts_with($arg, '--expected-scope=')) {
        $expectedScope = substr($arg, 17);
    }
}

// SAFE AUTOLOAD ONLY
require __DIR__.'/../../../vendor/autoload.php';

// Default NO-EXECUTE: If no valid action flag is provided, exit non-zero without touching DB.
if (! $isBaseline && ! $isInspectDelta && ! $isReconcile && $evidenceFile === null) {
    fwrite(STDERR, "Error: Inspector defaults to NO-EXECUTE. Specify --baseline, --inspect-delta, --reconcile, or --validate-evidence=<file>.\n");
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
    ];

    if ($schemaName !== null && $schemaName !== '') {
        $expectedPins['expectedSchemaName'] = $schemaName;
    }
    if ($companyId !== null && $companyId > 0) {
        $expectedPins['companyId'] = $companyId;
    }
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

// Database actions require explicit schema and company ID
if ($schemaName === null || $schemaName === '') {
    fwrite(STDERR, "Error: Database inspection requires explicit non-empty --schema=<accounting_p8_tmp_...>.\n");
    exit(1);
}

if ($companyId === null || $companyId <= 0) {
    fwrite(STDERR, "Error: Database inspection requires explicit positive --company-id=<int>.\n");
    exit(1);
}

// Action 2: Capture Baseline
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

// Action 3: Inspect Delta vs Baseline
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

// Action 4: Run 6 Reconciliations (requires minimal console app bootstrap)
if ($isReconcile) {
    try {
        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        // runSixReconciliations binds the verified read-only PDO before resolving Company
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
