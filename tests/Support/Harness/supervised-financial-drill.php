<?php

declare(strict_types=1);

namespace Tests\Support\Harness;

use Symfony\Component\Process\Process;

/**
 * Supervised Mobile V2 Paired Financial Integration Drill Runner.
 *
 * Implements a single parent-supervised lifecycle operator for paired execution:
 * 1. Defaults to NO-EXECUTE (dry-run preflight mode).
 * 2. Enforces explicit Root coordination gate (preflight + verified approval file/callback) before live execution.
 * 3. Uses structured argument arrays exclusively with Symfony Process (zero shell interpolation).
 * 4. Spawns and supervises a SINGLE child process:
 *      php tests/Support/Harness/mobile-v2-harness-cli.php --run --paired-hold-seconds=120
 * 5. Discovers owned directories and files from child stdout and filesystem:
 *      - Reads caCertPath from child stdout
 *      - Derives bootstrapDir = dirname(caCertPath) (mode 0700)
 *      - Derives harnessDir = dirname(bootstrapDir) (mode 0700, matches v2_harness_[0-9a-f]{16})
 *      - Discovers runtime_client_handoff.json (0600) and child_bootstrap_config.json (0600)
 * 6. Validates whole live handoff pins, CA cert, TTL, and ACTIVE_TLS_READY phase before host JVM.
 * 7. Captures pre-run baseline metrics while schema is alive, computing canonical baseline hash
 *    via DisposableFinancialPostRunInspector::computeCanonicalBaselineHash() (NOT file SHA).
 * 8. Executes Android client under host JVM with strictly the 7 approved environment variables
 *    (zero invented run ID; dynamically captures client-generated run_id from witnessed output).
 * 9. Runs post-run delta inspection, 6 reconciliations, trusted report composition, and outer
 *    evidence validation while the child schema is alive.
 * 10. Guaranteed child cleanup on termination or interrupt (SIGINT/SIGTERM trap).
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
$pairedHoldSeconds = 120;
$manifestPath = $repoRoot . '/docs/integration/mobile-v2-integration-manifest.json';
$manifestShaOverride = null;
$rootApprovalFile = null;
$androidRepoDir = null;

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
    } elseif (str_starts_with($arg, '--android-repo-dir=')) {
        $androidRepoDir = substr($arg, 19);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php " . basename(__FILE__) . " [options]\n\n";
        echo "Options:\n";
        echo "  --execute                       Live execution gate (requires --root-approval-file)\n";
        echo "  --root-approval-file=<path>     Mandatory Root coordination approval file for live execution\n";
        echo "  --android-pub-head=<40hex>      Root-approved peer Android publication HEAD SHA\n";
        echo "  --android-exe-head=<40hex>      Root-approved peer Android executable HEAD SHA\n";
        echo "  --paired-hold-seconds=<1..120>  Bounded hold duration in seconds (default: 120)\n";
        echo "  --manifest=<path>               Pairing manifest path\n";
        echo "  --manifest-sha=<64hex>          Manifest SHA256 pin override\n";
        echo "  --android-repo-dir=<path>       Path to Android checkout (for JVM execution)\n";
        echo "  --help, -h                      Show this help message\n";
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

$templateSrcSha = $manifestData['source_fixture_sha256'] ?? '';
$templateGuardSha = $manifestData['guard_test_sha256'] ?? '';
$templateCliSha = $manifestData['cli_command_sha256'] ?? '';
$templateRunnerSha = $manifestData['cli_runner_sha256'] ?? '';

$pinsMatch = ($srcHarnessSha === $templateSrcSha)
    && ($guardTestSha === $templateGuardSha)
    && ($cliCmdSha === $templateCliSha)
    && ($cliRunnerSha === $templateRunnerSha);

if (! $pinsMatch) {
    fwrite(STDERR, "Error: Source pins mismatch vs manifest template.\n");
    fwrite(STDERR, "  Harness:  {$srcHarnessSha} vs {$templateSrcSha}\n");
    fwrite(STDERR, "  Guard:    {$guardTestSha} vs {$templateGuardSha}\n");
    fwrite(STDERR, "  CLI Cmd:  {$cliCmdSha} vs {$templateCliSha}\n");
    fwrite(STDERR, "  CLI Run:  {$cliRunnerSha} vs {$templateRunnerSha}\n");
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

// Verify explicit Root coordination approval file
if ($rootApprovalFile === null || $rootApprovalFile === '' || ! is_file($rootApprovalFile)) {
    fwrite(STDERR, "\nError: FAIL-CLOSED: Live paired financial execution requires an explicit Root coordination approval file.\n");
    fwrite(STDERR, "Pass --root-approval-file=<path> containing verified Root coordination pins.\n");
    exit(1);
}

$approvalRaw = (string) file_get_contents($rootApprovalFile);
$approval = json_decode($approvalRaw, true);
if (! is_array($approval) || empty($approval['root_coordination_granted'])) {
    fwrite(STDERR, "\nError: FAIL-CLOSED: Root approval file is invalid or root_coordination_granted is not true.\n");
    exit(1);
}

if (($approval['backend_head'] ?? '') !== $backendHead) {
    fwrite(STDERR, "\nError: FAIL-CLOSED: Root approval backend_head mismatch: expected '{$backendHead}', got '" . ($approval['backend_head'] ?? '') . "'.\n");
    exit(1);
}

if ($androidPubHead === null || $androidPubHead === '') {
    $androidPubHead = (string) ($approval['android_publication_head'] ?? '');
}
if ($androidExeHead === null || $androidExeHead === '') {
    $androidExeHead = (string) ($approval['android_executable_head'] ?? '');
}

if ($androidPubHead === '' || $androidExeHead === '') {
    fwrite(STDERR, "\nError: FAIL-CLOSED: Peer Android publication and executable HEAD SHAs are required.\n");
    exit(1);
}

echo "  Root Approval Gate   : GRANTED (verified from {$rootApprovalFile})\n";
echo "  Android Pub HEAD     : {$androidPubHead}\n";
echo "  Android Exe HEAD     : {$androidExeHead}\n";
echo "\nProceeding with parent-supervised single child paired lifecycle...\n";

// 4. Setup Signal Handling for Guaranteed Child Cleanup
/** @var Process|null $childProcess */
$childProcess = null;

$cleanup = function () use (&$childProcess): void {
    if ($childProcess !== null && $childProcess->isRunning()) {
        fwrite(STDERR, "\n[Supervisor] Stopping child harness process (PID " . $childProcess->getPid() . ")...\n");
        $childProcess->signal(SIGTERM);
        $startWait = microtime(true);
        while ($childProcess->isRunning() && (microtime(true) - $startWait) < 5.0) {
            usleep(100000);
        }
        if ($childProcess->isRunning()) {
            fwrite(STDERR, "[Supervisor] Child process did not exit within 5s; sending SIGKILL.\n");
            $childProcess->signal(SIGKILL);
        }
        fwrite(STDERR, "[Supervisor] Child process reaped.\n");
    }
};

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () use ($cleanup): void {
        $cleanup();
        exit(130);
    });
    pcntl_signal(SIGTERM, function () use ($cleanup): void {
        $cleanup();
        exit(143);
    });
}

try {
    // 5. Launch SINGLE child process with structured argument array
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

    $childProcess = new Process($childCmd, $repoRoot, $childEnv, null, (float) ($pairedHoldSeconds + 30));

    $childOutput = '';
    $schemaName = null;
    $caCertPath = null;
    $credentialsPath = null;
    $transportActive = false;

    $childProcess->start(function ($type, $buffer) use (&$childOutput, &$schemaName, &$caCertPath, &$credentialsPath, &$transportActive): void {
        $childOutput .= $buffer;
        if ($type === Process::ERR) {
            fwrite(STDERR, "[Child ERR] " . $buffer);
        } else {
            // Check for key outputs
            if ($schemaName === null && preg_match('/Provision OK — schema:\s+([a-zA-Z0-9_]+)/', $buffer, $m)) {
                $schemaName = trim($m[1]);
            }
            if ($caCertPath === null && preg_match('/Test CA cert\s+:\s+(\S+)/', $buffer, $m)) {
                $caCertPath = trim($m[1]);
            }
            if ($credentialsPath === null && preg_match('/Credentials\s+:\s+(\S+)/', $buffer, $m)) {
                $credentialsPath = trim($m[1]);
            }
            if (! $transportActive && str_contains($buffer, 'Supervised transport active.')) {
                $transportActive = true;
            }
        }
    });

    // Wait bounded time for child to start transport and emit paths
    $startWait = microtime(true);
    while (! $transportActive && (microtime(true) - $startWait) < 30.0) {
        if (! $childProcess->isRunning()) {
            throw new \RuntimeException("Child harness process terminated prematurely with code " . $childProcess->getExitCode() . ":\n" . $childOutput);
        }
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        usleep(100000);
    }

    if (! $transportActive || $caCertPath === null || $schemaName === null) {
        throw new \RuntimeException("Timed out awaiting child transport readiness or path discovery. Child output:\n" . $childOutput);
    }

    echo "  [Child] Schema       : {$schemaName}\n";
    echo "  [Child] CA Cert      : {$caCertPath}\n";

    // 6. Discover & Verify Owned Directories & Artifacts per Root Specification
    $bootstrapDir = dirname($caCertPath);
    if (! is_dir($bootstrapDir) || basename($bootstrapDir) !== 'bootstrap') {
        throw new \RuntimeException("Invalid bootstrap directory derived from CA cert path: '{$bootstrapDir}'.");
    }
    $bootstrapPerms = decoct(fileperms($bootstrapDir) & 0777);
    if ($bootstrapPerms !== '700') {
        throw new \RuntimeException("Bootstrap directory permissions must be 0700, got 0{$bootstrapPerms}.");
    }

    $harnessDir = dirname($bootstrapDir);
    if (! is_dir($harnessDir) || ! preg_match('/^v2_harness_[0-9a-f]{16}$/', basename($harnessDir))) {
        throw new \RuntimeException("Invalid harness temporary directory: '{$harnessDir}'. Must match /^v2_harness_[0-9a-f]{16}$/.");
    }
    $harnessPerms = decoct(fileperms($harnessDir) & 0777);
    if ($harnessPerms !== '700') {
        throw new \RuntimeException("Harness temporary directory permissions must be 0700, got 0{$harnessPerms}.");
    }

    $childConfigPath = $bootstrapDir . '/child_bootstrap_config.json';
    if (! is_file($childConfigPath)) {
        throw new \RuntimeException("child_bootstrap_config.json not found in bootstrap directory: '{$childConfigPath}'.");
    }
    $configPerms = decoct(fileperms($childConfigPath) & 0777);
    if ($configPerms !== '600') {
        throw new \RuntimeException("child_bootstrap_config.json permissions must be 0600, got 0{$configPerms}.");
    }

    $handoffPath = $harnessDir . '/runtime_client_handoff.json';
    if (! is_file($handoffPath)) {
        throw new \RuntimeException("runtime_client_handoff.json not found in harness directory: '{$handoffPath}'.");
    }
    $handoffPerms = decoct(fileperms($handoffPath) & 0777);
    if ($handoffPerms !== '600') {
        throw new \RuntimeException("runtime_client_handoff.json permissions must be 0600, got 0{$handoffPerms}.");
    }

    // 7. Verify Whole Live Handoff Content & Invariants Root Before JVM
    $wholeLiveHandoffSha = hash_file('sha256', $handoffPath);
    $handoffRaw = (string) file_get_contents($handoffPath);
    $handoff = json_decode($handoffRaw, true);
    if (! is_array($handoff)) {
        throw new \RuntimeException("runtime_client_handoff.json contains invalid JSON.");
    }

    // Verify lifecycle phase and endpoints
    if (($handoff['lifecycle_phase'] ?? '') !== 'ACTIVE_TLS_READY') {
        throw new \RuntimeException("Handoff lifecycle_phase must be ACTIVE_TLS_READY, got '" . ($handoff['lifecycle_phase'] ?? '') . "'.");
    }
    $loopbackUrl = $handoff['endpoints']['loopback_base_url'] ?? '';
    if ($loopbackUrl !== 'https://127.0.0.1:' . DisposableMobileV2BackendHarness::TLS_PROXY_PORT . '/api/mobile/v2') {
        throw new \RuntimeException("Handoff loopback_base_url must point to host 127.0.0.1, got '{$loopbackUrl}'.");
    }

    // Verify TTL unexpired
    $expiresAt = strtotime((string) ($handoff['expires_at'] ?? ''));
    if ($expiresAt === false || $expiresAt <= time()) {
        throw new \RuntimeException("Handoff has already expired or has invalid TTL: " . ($handoff['expires_at'] ?? ''));
    }

    $companyId = (int) ($handoff['fixture']['company_public_id'] ?? 0);
    $expectedEventId = (string) ($handoff['fixture']['sample_event_id'] ?? '');
    $canonicalPayloadDigest = (string) ($handoff['raw_wire_sample']['raw_wire_payload_sha256'] ?? '');

    echo "  [Handoff] Live SHA   : {$wholeLiveHandoffSha}\n";
    echo "  [Handoff] Company ID : {$companyId}\n";
    echo "  [Handoff] Event ID   : {$expectedEventId}\n";

    // 8. Capture Pre-Run Baseline Metrics (While Child & Schema are Alive)
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
        throw new \RuntimeException("Captured baseline file does not contain valid JSON.");
    }

    // Compute canonical baseline hash using authoritative helper
    $canonicalBaselineHash = DisposableFinancialPostRunInspector::computeCanonicalBaselineHash(
        $companyId,
        $schemaName,
        $baselineData
    );
    echo "  [Baseline] Hash      : {$canonicalBaselineHash} (canonical)\n";

    // 9. Execute Android Test Under Host JVM (Strictly 7 Env Vars, No Invented Run ID)
    $targetAndroidDir = $androidRepoDir ?? $repoRoot . '/../android';
    if (! is_dir($targetAndroidDir)) {
        throw new \RuntimeException("Android repository directory not found at '{$targetAndroidDir}'. Pass --android-repo-dir=<path>.");
    }

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
        'SMALL_TRADER_EXPECTED_HANDOFF_SHA' => $wholeLiveHandoffSha,
        'SMALL_TRADER_EXPECTED_LIFECYCLE' => 'FINANCIAL_PAIRED_RUN',
        'SMALL_TRADER_ROOT_APPROVED_HANDOFF_SHA' => $wholeLiveHandoffSha,
    ];

    echo "\nLaunching Android client tests under host JVM...\n";
    $gradleProc = new Process($gradleCmd, $targetAndroidDir, $gradleEnv, null, 180.0);
    $gradleProc->mustRun();
    $gradleOutput = $gradleProc->getOutput();

    // Dynamically witness client-generated run_id from test output
    $witnessedRunId = null;
    if (preg_match('/(?:run_id|runId|RUN_ID)[:=\s]+([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $gradleOutput, $m)) {
        $witnessedRunId = trim($m[1]);
    }

    // Locate client evidence report artifact
    $evidenceReportFile = null;
    $possibleEvidencePaths = [
        $targetAndroidDir . '/app/build/reports/mobile-evidence/pr102-ooug5prl-readiness-success-report.json',
        $targetAndroidDir . '/build/reports/mobile-evidence/paired-drill-evidence.json',
        $harnessDir . '/paired-drill-evidence.json',
    ];
    foreach ($possibleEvidencePaths as $pep) {
        if (is_file($pep)) {
            $evidenceReportFile = $pep;
            break;
        }
    }

    if ($witnessedRunId === null && $evidenceReportFile !== null) {
        $evData = json_decode((string) file_get_contents($evidenceReportFile), true);
        $witnessedRunId = $evData['run_id'] ?? ($evData['metadata']['run_id'] ?? null);
    }

    if ($witnessedRunId === null) {
        throw new \RuntimeException("Unable to dynamically capture client-generated run_id from witnessed Android test output.");
    }
    if ($evidenceReportFile === null) {
        throw new \RuntimeException("Unable to locate client-generated outer evidence report file.");
    }

    $expectedEvidenceSha = hash_file('sha256', $evidenceReportFile);
    echo "  [Client] Witnessed Run ID: {$witnessedRunId}\n";
    echo "  [Client] Evidence SHA    : {$expectedEvidenceSha}\n";

    // 10. Inspect Delta & 6 Reconciliations (While Child & Schema are Alive)
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
    echo "  [Inspect] Delta OK\n";

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
    echo "  [Inspect] 6 Reconciliations OK\n";

    // 11. Compose Trusted DB Report
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
        '--expected-handoff-digest=' . $wholeLiveHandoffSha,
        '--expected-event-id=' . $expectedEventId,
        '--expected-scope=ROOT_AUTHORIZED_PAIRED_EXECUTION',
        '--output=' . $trustedDbReportFile,
    ];
    $composeProc = new Process($composeCmd, $repoRoot);
    $composeProc->mustRun();
    $expectedDbReportSha = hash_file('sha256', $trustedDbReportFile);
    echo "  [Report] Trusted DB Report composed (SHA: {$expectedDbReportSha})\n";

    // 12. Outer Evidence Validation
    $validationFile = $harnessDir . '/outer_evidence_validation.json';
    $validateCmd = [
        PHP_BINARY,
        $repoRoot . '/tests/Support/Harness/financial-inspector-cli.php',
        '--validate-evidence=' . $evidenceReportFile,
        '--trusted-db-report=' . $trustedDbReportFile,
        '--expected-evidence-sha=' . $expectedEvidenceSha,
        '--expected-db-report-sha=' . $expectedDbReportSha,
        '--expected-backend-head=' . $backendHead,
        '--expected-android-pub=' . $androidPubHead,
        '--expected-android-exe=' . $androidExeHead,
        '--expected-handoff-digest=' . $wholeLiveHandoffSha,
        '--expected-baseline-hash=' . $canonicalBaselineHash,
        '--expected-payload-digest=' . $canonicalPayloadDigest,
        '--expected-scope=ROOT_AUTHORIZED_PAIRED_EXECUTION',
        '--schema=' . $schemaName,
        '--company-id=' . $companyId,
        '--run-id=' . $witnessedRunId,
        '--expected-event-id=' . $expectedEventId,
        '--output=' . $validationFile,
    ];
    $validateProc = new Process($validateCmd, $repoRoot);
    $validateProc->mustRun();
    echo "  [Validate] Outer Evidence Validated OK\n";

    echo "\n==============================================================================\n";
    echo "PAIRED FINANCIAL INTEGRATION DRILL COMPLETED SUCCESSFULLY\n";
    echo "==============================================================================\n";
} finally {
    $cleanup();
}

exit(0);
