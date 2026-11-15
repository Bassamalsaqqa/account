<?php

declare(strict_types=1);

namespace Tests\Support\Harness;

use Symfony\Component\Process\Process;

/**
 * Supervised Mobile V2 Paired Financial Integration Drill CLI Entrypoint.
 *
 * Implements single parent-supervised lifecycle operator with:
 * - Structured argument arrays (zero shell interpolation).
 * - Multi-stage fail-closed gates (prestart Root approval, external live Root approval).
 * - Exact schema and handoff validations.
 * - Dynamic company integer ID resolution via verified proof PDO (no int-casting ULID).
 * - Fresh evidence verification relative to approved Android cwd (2 PASS 0 SKIP XML).
 * - Bounded hold execution within child hold deadline (120s).
 * - Exact cleanup with hard kill schema drop and listener absence checks.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: Supervisor must be run from CLI.\n");
    exit(1);
}

$repoRoot = dirname(__DIR__, 3);
require_once $repoRoot . '/vendor/autoload.php';

// Argument parsing
$argvList = $_SERVER['argv'] ?? [];
$isExecute = false;
$androidPubHead = null;
$androidExeHead = null;
$pairedHoldSeconds = SupervisedPairedFinancialDrill::DEFAULT_HOLD_SECONDS;
$manifestPath = $repoRoot . '/docs/integration/mobile-v2-integration-manifest.json';
$manifestShaOverride = null;
$rootApprovalFile = null;
$rootLiveApprovalFile = null;
$androidRepoDir = null;
$outputDir = $repoRoot . '/docs/integration/v2-online-cash-evidence';

foreach (array_slice($argvList, 1) as $arg) {
    if ($arg === '--execute') {
        $isExecute = true;
    } elseif (str_starts_with($arg, '--android-pub-head=')) {
        $androidPubHead = substr($arg, 19);
    } elseif (str_starts_with($arg, '--android-exe-head=')) {
        $androidExeHead = substr($arg, 19);
    } elseif (str_starts_with($arg, '--paired-hold-seconds=')) {
        $pairedHoldSeconds = (int) substr($arg, 22);
    } elseif (str_starts_with($arg, '--manifest=')) {
        $manifestPath = substr($arg, 11);
    } elseif (str_starts_with($arg, '--manifest-sha=')) {
        $manifestShaOverride = substr($arg, 15);
    } elseif (str_starts_with($arg, '--root-approval-file=')) {
        $rootApprovalFile = substr($arg, 21);
    } elseif (str_starts_with($arg, '--root-live-approval-file=')) {
        $rootLiveApprovalFile = substr($arg, 26);
    } elseif (str_starts_with($arg, '--android-repo-dir=')) {
        $androidRepoDir = substr($arg, 19);
    } elseif (str_starts_with($arg, '--output-dir=')) {
        $outputDir = substr($arg, 13);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php " . basename(__FILE__) . " [options]\n\n";
        echo "Options:\n";
        echo "  --execute                          Live execution gate (requires --root-approval-file and --root-live-approval-file)\n";
        echo "  --root-approval-file=<path>        Mandatory Root prestart coordination approval file\n";
        echo "  --root-live-approval-file=<path>   Mandatory Root live witness approval file (post-handoff, pre-JVM)\n";
        echo "  --android-pub-head=<40hex>         Root-approved peer Android publication HEAD SHA\n";
        echo "  --android-exe-head=<40hex>         Root-approved peer Android executable HEAD SHA\n";
        echo "  --paired-hold-seconds=<1..120>     Bounded hold duration in seconds (default: 120)\n";
        echo "  --manifest=<path>                  Pairing manifest path\n";
        echo "  --manifest-sha=<64hex>             Manifest SHA256 pin override\n";
        echo "  --android-repo-dir=<path>          Path to Android checkout (for JVM execution)\n";
        echo "  --output-dir=<path>                Path to save fresh evidence artifacts outside temp\n";
        echo "  --help, -h                         Show this help message\n";
        exit(0);
    } else {
        fwrite(STDERR, "Error: Unknown argument '{$arg}'. Use --help for usage.\n");
        exit(1);
    }
}

// 1. Inspect dynamic git HEAD and app tree
$headProcess = new Process(['git', 'rev-parse', 'HEAD'], $repoRoot);
$headProcess->mustRun();
$backendHead = trim($headProcess->getOutput());

$treeProcess = new Process(['git', 'rev-parse', 'HEAD:app'], $repoRoot);
$treeProcess->mustRun();
$backendAppTree = trim($treeProcess->getOutput());

// Verify app tree admission
$isAdmittedTree = ($backendAppTree === DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_BASE
    || $backendAppTree === DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_G1);

if (! $isAdmittedTree) {
    fwrite(STDERR, "Error: Backend app tree '{$backendAppTree}' is neither BASE nor admitted G1 app tree.\n");
    exit(1);
}

// 2. Verify source fixture and manifest hashes
if (! is_file($manifestPath)) {
    fwrite(STDERR, "Error: Manifest not found at '{$manifestPath}'.\n");
    exit(1);
}

$manifestRaw = (string) file_get_contents($manifestPath);
$manifestData = json_decode($manifestRaw, true);
if (! is_array($manifestData)) {
    fwrite(STDERR, "Error: Manifest contains invalid JSON.\n");
    exit(1);
}

$manifestSha = $manifestShaOverride ?? hash('sha256', $manifestRaw);

$srcHarnessSha = hash_file('sha256', $repoRoot . '/tests/Support/Harness/DisposableMobileV2BackendHarness.php');
$guardTestSha = hash_file('sha256', $repoRoot . '/tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php');
$cliCmdSha = hash_file('sha256', $repoRoot . '/tests/Support/Harness/MobileV2HarnessCommand.php');
$cliRunnerSha = hash_file('sha256', $repoRoot . '/tests/Support/Harness/mobile-v2-harness-cli.php');

$pinsMatch = ($srcHarnessSha === ($manifestData['source_fixture_sha256'] ?? ''))
    && ($guardTestSha === ($manifestData['guard_test_sha256'] ?? ''))
    && ($cliCmdSha === ($manifestData['cli_command_sha256'] ?? ''))
    && ($cliRunnerSha === ($manifestData['cli_runner_sha256'] ?? ''));

if (! $pinsMatch) {
    fwrite(STDERR, "Error: Source pins mismatch vs manifest template.\n");
    exit(1);
}

echo "==============================================================================\n";
echo "Mobile V2 Paired Financial Integration Supervisor\n";
echo "==============================================================================\n";
echo "  Backend Runtime HEAD : {$backendHead}\n";
echo "  Backend App Tree     : {$backendAppTree} (" . ($backendAppTree === DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_G1 ? 'G1 ADMITTED' : 'BASE ADMITTED') . ")\n";
echo "  Source Harness SHA   : {$srcHarnessSha} (MATCH)\n";
echo "  Guard Test SHA       : {$guardTestSha} (MATCH)\n";
echo "  CLI Command SHA      : {$cliCmdSha} (MATCH)\n";
echo "  CLI Runner SHA       : {$cliRunnerSha} (MATCH)\n";
echo "  Manifest SHA-256     : {$manifestSha}\n";
echo "  Paired Hold Seconds  : {$pairedHoldSeconds}s\n";

// 3. Execution Gate: FAIL-CLOSED by default
if (! $isExecute) {
    echo "\nSUPERVISOR STATUS: DRY-RUN OK (defaults to NO-EXECUTE).\n";
    echo "Staged lifecycle supervisor is verified against manifest pins and tree admission.\n";
    echo "To run live execution, Root must grant coordination via --execute and --root-approval-file=<path>.\n";
    exit(0);
}

// 4. Gate 1: Strict Prestart Root Approval Verification
if ($rootApprovalFile === null || $rootApprovalFile === '') {
    fwrite(STDERR, "\nError: FAIL-CLOSED: Live paired financial execution requires an explicit Root prestart coordination approval file.\n");
    fwrite(STDERR, "Pass --root-approval-file=<path> containing verified Root coordination pins.\n");
    exit(1);
}

try {
    $approval = SupervisedPairedFinancialDrill::verifyPrestartApproval(
        $rootApprovalFile,
        $manifestSha,
        $backendHead,
        $androidPubHead,
        $androidExeHead,
        $repoRoot
    );
    $androidPubHead = $approval['android_publication_head'];
    $androidExeHead = $approval['android_executable_head'];
} catch (\Throwable $e) {
    fwrite(STDERR, "\nError: FAIL-CLOSED: Prestart Root approval verification failed: {$e->getMessage()}\n");
    exit(1);
}

echo "  Root Prestart Gate   : GRANTED (verified from {$rootApprovalFile})\n";
echo "  Android Pub HEAD     : {$androidPubHead}\n";
echo "  Android Exe HEAD     : {$androidExeHead}\n";

// 5. Setup Signal Handling (No direct exit in handler to preserve finally block)
$aborted = false;
$abortSignal = 0;

if (function_exists('pcntl_signal')) {
    $handler = function (int $signo) use (&$aborted, &$abortSignal): void {
        $aborted = true;
        $abortSignal = $signo;
    };
    pcntl_signal(SIGINT, $handler);
    pcntl_signal(SIGTERM, $handler);
}

/** @var Process|null $childProcess */
$childProcess = null;
$schemaName = null;
$proofPath = null;
$proofNonce = null;
$harnessDir = null;

try {
    echo "\nLaunching single child paired lifecycle (--run)...\n";
    $childStartTime = microtime(true);
    $deadline = $childStartTime + (float) $pairedHoldSeconds;

    $childCmd = [
        PHP_BINARY,
        $repoRoot . '/tests/Support/Harness/mobile-v2-harness-cli.php',
        '--run',
        '--backend-head=' . $backendHead,
        '--android-head=' . $androidPubHead,
        '--pairing-manifest=' . $manifestPath,
        '--pairing-manifest-sha=' . $manifestSha,
        '--paired-hold-seconds=' . $pairedHoldSeconds,
    ];

    $childEnv = array_merge($_ENV, [
        'MOBILE_V2_HARNESS_ENABLE' => '1',
        'MOBILE_V2_HARNESS_START_LISTENER' => '1',
        'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
    ]);

    $childProcess = new Process($childCmd, $repoRoot, $childEnv, null, (float) ($pairedHoldSeconds + 15));

    $childOutput = '';
    $caCertPath = null;
    $transportActive = false;

    $childProcess->start(function ($type, $buffer) use (&$childOutput, &$schemaName, &$caCertPath, &$transportActive): void {
        $childOutput .= $buffer;
        if ($type === Process::ERR) {
            fwrite(STDERR, "[Child ERR] " . $buffer);
        } else {
            if ($schemaName === null && preg_match('/Provision OK — schema:\s+([a-zA-Z0-9_]+)/', $buffer, $m)) {
                $schemaName = trim($m[1]);
            }
            if ($caCertPath === null && preg_match('/Test CA cert\s+:\s+(\S+)/', $buffer, $m)) {
                $caCertPath = trim($m[1]);
            }
            if (! $transportActive && str_contains($buffer, 'Supervised transport active.')) {
                $transportActive = true;
            }
        }
    });

    // Bounded wait for transport readiness
    $startWait = microtime(true);
    while (! $transportActive && (microtime(true) - $startWait) < 30.0) {
        if ($aborted) {
            throw new \RuntimeException("Execution aborted by signal {$abortSignal}.");
        }
        if (! $childProcess->isRunning()) {
            throw new \RuntimeException("Child harness terminated prematurely with code " . $childProcess->getExitCode() . ":\n" . $childOutput);
        }
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        usleep(100000);
    }

    if (! $transportActive || $caCertPath === null || $schemaName === null) {
        throw new \RuntimeException("Timed out awaiting child transport readiness or path discovery.");
    }

    echo "  [Child] Schema       : {$schemaName}\n";
    echo "  [Child] CA Cert      : {$caCertPath}\n";

    // 6. Discover & Verify Owned Directories & Artifacts
    $bootstrapDir = dirname($caCertPath);
    if (! is_dir($bootstrapDir) || basename($bootstrapDir) !== 'bootstrap') {
        throw new \RuntimeException("Invalid bootstrap directory: '{$bootstrapDir}'.");
    }
    SupervisedPairedFinancialDrill::assertSecureFilePermissions($caCertPath, 'CA_CERT');

    $harnessDir = dirname($bootstrapDir);
    if (! is_dir($harnessDir) || ! preg_match('/^v2_harness_[0-9a-f]{16}$/', basename($harnessDir))) {
        throw new \RuntimeException("Invalid harness temporary directory: '{$harnessDir}'.");
    }

    $childConfigPath = $bootstrapDir . '/child_bootstrap_config.json';
    SupervisedPairedFinancialDrill::assertSecureFilePermissions($childConfigPath, 'CHILD_CONFIG');

    $handoffPath = $harnessDir . '/runtime_client_handoff.json';
    SupervisedPairedFinancialDrill::assertSecureFilePermissions($handoffPath, 'CLIENT_HANDOFF');

    // 7. Validate Full Client Handoff Contract
    $handoffRaw = (string) file_get_contents($handoffPath);
    $candidateHandoffSha = hash('sha256', $handoffRaw);
    $handoff = json_decode($handoffRaw, true);
    if (! is_array($handoff)) {
        throw new \RuntimeException("Handoff file contains invalid JSON.");
    }

    DisposableMobileV2BackendHarness::validateClientHandoff($handoff);

    if (($handoff['lifecycle_phase'] ?? '') !== 'ACTIVE_TLS_READY') {
        throw new \RuntimeException("Handoff lifecycle_phase must be ACTIVE_TLS_READY.");
    }

    $readinessUrl = (string) ($handoff['startup_tls']['readiness_url'] ?? '');
    $readinessNonce = (string) ($handoff['startup_tls']['readiness_nonce'] ?? '');
    $caCertSha = (string) ($handoff['startup_tls']['ca_cert_sha256'] ?? '');

    if (! hash_equals(hash_file('sha256', $caCertPath), $caCertSha)) {
        throw new \RuntimeException("CA cert SHA256 mismatch vs handoff startup_tls.");
    }

    // Strict TLS GET 200 before JVM and external Root approval
    echo "  [Probe] Performing strict TLS GET 200 check...\n";
    SupervisedPairedFinancialDrill::performStrictTlsReadinessCheck($readinessUrl, $caCertPath, $readinessNonce);
    echo "  [Probe] Strict TLS probe verified 200 OK with matching nonce.\n";

    // 8. Resolve Company Integer ID via Verified Proof PDO (Never int-cast ULID!)
    $creds = DisposableFinancialPostRunInspector::loadSecureBootstrapCredentials($childConfigPath, $schemaName);
    $proofPath = $creds['proof_path'];
    $proofNonce = $creds['proof_nonce'];

    $pdo = DisposableFinancialPostRunInspector::assertExecutionGates($schemaName);
    $companyUlid = (string) ($handoff['fixture']['company_public_id'] ?? '');
    $companyId = SupervisedPairedFinancialDrill::resolveCompanyIntegerId($pdo, $companyUlid);
    $expectedEventId = (string) ($handoff['fixture']['sample_event_id'] ?? '');
    $canonicalPayloadDigest = (string) ($handoff['raw_wire_sample']['raw_wire_payload_sha256'] ?? '');

    echo "  [DB] Company Public ULID : {$companyUlid}\n";
    echo "  [DB] Company Integer ID  : {$companyId} (resolved via proof PDO)\n";

    // 9. Gate 2: Expose Candidate Handoff & Enforce Independent External Live Root Approval
    echo "\n==============================================================================\n";
    echo "CANDIDATE CLIENT HANDOFF EXPOSED FOR ROOT WITNESS RECORD:\n";
    echo "  Path   : {$handoffPath}\n";
    echo "  SHA256 : {$candidateHandoffSha}\n";
    echo "==============================================================================\n";

    if ($rootLiveApprovalFile === null || $rootLiveApprovalFile === '') {
        throw new \RuntimeException("FAIL-CLOSED: Live execution paused awaiting independent external Root live approval file. Pass --root-live-approval-file=<path>.");
    }

    $rootApprovedHandoffSha = SupervisedPairedFinancialDrill::verifyLiveRootApproval(
        $rootLiveApprovalFile,
        $handoffPath,
        $candidateHandoffSha,
        $backendHead,
        $androidPubHead,
        $androidExeHead,
        $manifestSha
    );
    echo "  Root Live Gate       : GRANTED (witnessed SHA: {$rootApprovedHandoffSha})\n";

    // 10. Capture Pre-Run Baseline Metrics (While Child & Schema are Alive)
    SupervisedPairedFinancialDrill::computeRemainingDeadline($deadline);
    $baselineFile = $harnessDir . '/pre_run_baseline.json';
    $baselineCmd = [
        PHP_BINARY,
        $repoRoot . '/tests/Support/Harness/financial-inspector-cli.php',
        '--baseline',
        '--credentials-file=' . $childConfigPath,
        '--schema=' . $schemaName,
        '--company-id=' . $companyId,
        '--output=' . $baselineFile,
    ];
    $baselineProc = new Process($baselineCmd, $repoRoot);
    $baselineProc->mustRun();

    $baselineData = json_decode((string) file_get_contents($baselineFile), true);
    if (! is_array($baselineData)) {
        throw new \RuntimeException("Baseline output is not valid JSON.");
    }
    $canonicalBaselineHash = DisposableFinancialPostRunInspector::computeCanonicalBaselineHash(
        $companyId,
        $schemaName,
        $baselineData
    );
    echo "  [Baseline] Hash      : {$canonicalBaselineHash} (canonical)\n";

    // 11. Execute Android Test Under Host JVM (Strictly 7 Approved Env Vars)
    $targetAndroidDir = $androidRepoDir ?? $repoRoot . '/../android';
    if (! is_dir($targetAndroidDir)) {
        throw new \RuntimeException("Android repo directory not found at '{$targetAndroidDir}'.");
    }

    // Relative evidence file path per specification
    $evidenceRelPath = 'docs/integration/v2-online-cash-evidence/paired-online-cash-pilot-evidence.json';
    $evidenceFile = is_file($targetAndroidDir . '/' . $evidenceRelPath)
        ? $targetAndroidDir . '/' . $evidenceRelPath
        : $targetAndroidDir . '/app/' . $evidenceRelPath;

    $preRunTime = time();
    $preRunSha = is_file($evidenceFile) ? hash_file('sha256', $evidenceFile) : null;

    $remainingSeconds = SupervisedPairedFinancialDrill::computeRemainingDeadline($deadline, 25.0);
    $gradleTimeout = min($remainingSeconds - 20.0, 90.0);

    $gradleCmd = [
        './gradlew',
        'cleanTestDebugUnitTest',
        'testDebugUnitTest',
        '--no-build-cache',
        '--tests',
        'com.example.data.mobile.readclient.pilot.SmallTraderOnlineCashPilotSmokeTest.executePairedOnlineCashPilotSmoke',
        '--tests',
        'com.example.data.mobile.readclient.pilot.SmallTraderOnlineCashPilotSmokeTest.testCashPilotFinancialAcceptanceEnforcement',
        '--max-workers=1',
        '-Dorg.gradle.workers.max=1',
        '-Dorg.gradle.jvmargs=-XX:ActiveProcessorCount=2 -XX:+UseSerialGC -Xmx1536m',
        '-Pkotlin.compiler.execution.strategy=in-process',
        '--no-daemon',
    ];

    $gradleEnv = [
        'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        'JAVA_HOME' => getenv('JAVA_HOME') ?: '',
        'HOME' => getenv('HOME') ?: '',
        'SMALL_TRADER_CASH_PILOT_HANDOFF' => $handoffPath,
        'SMALL_TRADER_EXPECTED_BACKEND_SHA' => $backendHead,
        'SMALL_TRADER_EXPECTED_CLIENT_SHA' => $androidPubHead,
        'SMALL_TRADER_EXPECTED_CLIENT_EXECUTABLE_SHA' => $androidExeHead,
        'SMALL_TRADER_EXPECTED_HANDOFF_SHA' => $candidateHandoffSha,
        'SMALL_TRADER_EXPECTED_LIFECYCLE' => SupervisedPairedFinancialDrill::REQUIRED_LIFECYCLE_GRANT,
        'SMALL_TRADER_ROOT_APPROVED_HANDOFF_SHA' => $rootApprovedHandoffSha,
    ];

    echo "\nExecuting Android client under host JVM (timeout: {$gradleTimeout}s)...\n";
    $gradleProc = new Process($gradleCmd, $targetAndroidDir, $gradleEnv, null, $gradleTimeout);

    $rawGradleOutput = '';
    $gradleProc->run(function ($type, $buffer) use (&$rawGradleOutput): void {
        $rawGradleOutput .= $buffer;
    });

    if (! is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    file_put_contents($outputDir . '/raw_android_test_output.log', $rawGradleOutput);

    if (! $gradleProc->isSuccessful()) {
        throw new \RuntimeException("Android test execution failed with exit code " . $gradleProc->getExitCode() . ".");
    }

    if (! $childProcess->isRunning()) {
        throw new \RuntimeException("Child harness died during Gradle test execution.");
    }

    // 12. Verify Fresh Evidence and JUnit XML Results
    $freshEvidence = SupervisedPairedFinancialDrill::verifyEvidenceFreshness(
        $evidenceFile,
        $preRunTime,
        $preRunSha,
        $backendHead,
        $androidPubHead,
        $androidExeHead,
        $rootApprovedHandoffSha
    );
    $witnessedRunId = (string) ($freshEvidence['run_id'] ?? ($freshEvidence['metadata']['run_id'] ?? ''));
    if ($witnessedRunId === '' || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $witnessedRunId) !== 1) {
        throw new \RuntimeException("Witnessed client run_id missing or invalid UUID format.");
    }
    $expectedEvidenceSha = hash_file('sha256', $evidenceFile);

    // Locate and verify JUnit XML report
    $junitXml = null;
    $possibleXmlDirs = [
        $targetAndroidDir . '/app/build/test-results/testDebugUnitTest',
        $targetAndroidDir . '/build/test-results/testDebugUnitTest',
    ];
    foreach ($possibleXmlDirs as $d) {
        if (is_dir($d)) {
            $files = glob($d . '/*.xml');
            if (! empty($files)) {
                $junitXml = $files[0];
                break;
            }
        }
    }
    if ($junitXml === null) {
        throw new \RuntimeException("JUnit XML test report not found in Android build directories.");
    }

    $xmlResults = SupervisedPairedFinancialDrill::verifyJunitTestResults($junitXml, $preRunTime);
    echo "  [JUnit] Tests Passed : {$xmlResults['tests']} (0 failures, 0 errors, 0 skipped)\n";

    // Copy fresh artifacts outside harness temp before cleanup
    copy($evidenceFile, $outputDir . '/paired-online-cash-pilot-evidence.json');
    copy($junitXml, $outputDir . '/junit-test-report.xml');

    // 13. In-Schema Inspection & Outer Evidence Validation
    SupervisedPairedFinancialDrill::computeRemainingDeadline($deadline);

    $deltaFile = $harnessDir . '/delta_inspection.json';
    $deltaCmd = [
        PHP_BINARY,
        $repoRoot . '/tests/Support/Harness/financial-inspector-cli.php',
        '--inspect-delta',
        '--credentials-file=' . $childConfigPath,
        '--schema=' . $schemaName,
        '--company-id=' . $companyId,
        '--baseline-file=' . $baselineFile,
        '--expected-event-id=' . $expectedEventId,
        '--expected-payload-digest=' . $canonicalPayloadDigest,
        '--output=' . $deltaFile,
    ];
    $deltaProc = new Process($deltaCmd, $repoRoot);
    $deltaProc->mustRun();

    $reconcileFile = $harnessDir . '/reconciliation_results.json';
    $reconcileCmd = [
        PHP_BINARY,
        $repoRoot . '/tests/Support/Harness/financial-inspector-cli.php',
        '--reconcile',
        '--credentials-file=' . $childConfigPath,
        '--schema=' . $schemaName,
        '--company-id=' . $companyId,
        '--output=' . $reconcileFile,
    ];
    $reconcileProc = new Process($reconcileCmd, $repoRoot);
    $reconcileProc->mustRun();

    $trustedDbReportFile = $harnessDir . '/trusted_db_report.json';
    $composeCmd = [
        PHP_BINARY,
        $repoRoot . '/tests/Support/Harness/financial-inspector-cli.php',
        '--compose-trusted-report',
        '--baseline-file=' . $baselineFile,
        '--delta-file=' . $deltaFile,
        '--reconcile-file=' . $reconcileFile,
        '--schema=' . $schemaName,
        '--company-id=' . $companyId,
        '--run-id=' . $witnessedRunId,
        '--expected-backend-head=' . $backendHead,
        '--expected-handoff-digest=' . $candidateHandoffSha,
        '--expected-event-id=' . $expectedEventId,
        '--expected-scope=' . SupervisedPairedFinancialDrill::REQUIRED_SCOPE,
        '--output=' . $trustedDbReportFile,
    ];
    $composeProc = new Process($composeCmd, $repoRoot);
    $composeProc->mustRun();
    $expectedDbReportSha = hash_file('sha256', $trustedDbReportFile);
    copy($trustedDbReportFile, $outputDir . '/trusted_db_report.json');

    $validationFile = $outputDir . '/outer_evidence_validation.json';
    $validateCmd = [
        PHP_BINARY,
        $repoRoot . '/tests/Support/Harness/financial-inspector-cli.php',
        '--validate-evidence=' . $evidenceFile,
        '--trusted-db-report=' . $trustedDbReportFile,
        '--expected-evidence-sha=' . $expectedEvidenceSha,
        '--expected-db-report-sha=' . $expectedDbReportSha,
        '--expected-backend-head=' . $backendHead,
        '--expected-android-pub=' . $androidPubHead,
        '--expected-android-exe=' . $androidExeHead,
        '--expected-handoff-digest=' . $candidateHandoffSha,
        '--expected-baseline-hash=' . $canonicalBaselineHash,
        '--expected-payload-digest=' . $canonicalPayloadDigest,
        '--expected-scope=' . SupervisedPairedFinancialDrill::REQUIRED_SCOPE,
        '--schema=' . $schemaName,
        '--company-id=' . $companyId,
        '--run-id=' . $witnessedRunId,
        '--expected-event-id=' . $expectedEventId,
        '--output=' . $validationFile,
    ];
    $validateProc = new Process($validateCmd, $repoRoot);
    $validateProc->mustRun();

    echo "\n==============================================================================\n";
    echo "PAIRED FINANCIAL INTEGRATION DRILL COMPLETED SUCCESSFULLY\n";
    echo "==============================================================================\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "\nSupervisor Error: {$e->getMessage()}\n");
    $exitCode = 1;
} finally {
    SupervisedPairedFinancialDrill::executeExactCleanup(
        $childProcess,
        $schemaName,
        $proofPath,
        $proofNonce,
        $harnessDir
    );
    if ($aborted) {
        $exitCode = ($abortSignal === SIGINT) ? 130 : 143;
    }
}

exit($exitCode ?? 0);
