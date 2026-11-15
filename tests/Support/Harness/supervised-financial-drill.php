<?php

declare(strict_types=1);

namespace Tests\Support\Harness;

use Symfony\Component\Process\Process;

/**
 * Supervised Mobile V2 Paired Financial Integration Drill CLI Driver.
 *
 * Enforces strict adversarial execution invariants across all lifecycle stages:
 * - pcntl signal handling and process supervision.
 * - Mandatory prestart Root approval with mandatory TTL (0600/0700/UID/no-symlinks).
 * - App tree admission validation via assertAdmittedAppTree.
 * - Manifest SHA override verified against actual file bytes.
 * - Candidate handoff exposed, followed by bounded wait for independent external live Root approval.
 * - Strict TLS GET 200 with loopback URL allowlist.
 * - Triple-match of sample, parsed, and raw canonical JSON.
 * - Company integer ID resolved via proof PDO (never int-cast ULID).
 * - Hard-gated child alive and hold deadline bounds before and after every phase.
 * - Private unique output directory outside checkouts (0700/0600) retaining all artifacts before cleanup.
 * - Structured exact cleanup verifying schema absence, listener absence, and temp cleanup.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: Supervisor must be run from CLI.\n");
    exit(1);
}

// 0. pcntl extension availability check
if (! function_exists('pcntl_signal') || ! function_exists('pcntl_signal_dispatch')) {
    fwrite(STDERR, "Error: pcntl extension is required for safe supervised process execution.\n");
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
$customOutputDir = null;

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
        $customOutputDir = substr($arg, 13);
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
        echo "  --output-dir=<path>                Private unique directory for retained artifacts (0700/0600)\n";
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

// Verify app tree admission via actual method
try {
    $admittedTree = DisposableMobileV2BackendHarness::assertAdmittedAppTree($backendAppTree, $repoRoot);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: App tree admission failed: {$e->getMessage()}\n");
    exit(1);
}

// 2. Verify source fixture and manifest hashes
if (! is_file($manifestPath)) {
    fwrite(STDERR, "Error: Manifest not found at '{$manifestPath}'.\n");
    exit(1);
}

$manifestFileSha = hash_file('sha256', $manifestPath);
if ($manifestShaOverride !== null && ! hash_equals($manifestFileSha, $manifestShaOverride)) {
    fwrite(STDERR, "Error: Manifest SHA override does not match manifest file bytes: expected '{$manifestFileSha}', got '{$manifestShaOverride}'.\n");
    exit(1);
}
$manifestSha = $manifestFileSha;

$manifestRaw = (string) file_get_contents($manifestPath);
$manifestData = json_decode($manifestRaw, true);
if (! is_array($manifestData)) {
    fwrite(STDERR, "Error: Manifest contains invalid JSON.\n");
    exit(1);
}

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

// 3. Private Unique Output Directory Setup (0700 outside checkouts)
if ($customOutputDir !== null) {
    $outputDir = $customOutputDir;
    if (! is_dir($outputDir)) {
        mkdir($outputDir, 0700, true);
    }
} else {
    $outputDir = sys_get_temp_dir() . '/supervised_drill_out_' . bin2hex(random_bytes(8));
    mkdir($outputDir, 0700, true);
}
SupervisedPairedFinancialDrill::assertSecureDirectoryPermissions($outputDir, 'OUTPUT');

echo "==============================================================================\n";
echo "Mobile V2 Paired Financial Integration Supervisor\n";
echo "==============================================================================\n";
echo "  Backend Runtime HEAD : {$backendHead}\n";
echo "  Backend App Tree     : {$backendAppTree} (" . ($admittedTree === DisposableMobileV2BackendHarness::QUALIFIED_APP_TREE_G1 ? 'G1 ADMITTED' : 'BASE ADMITTED') . ")\n";
echo "  Source Harness SHA   : {$srcHarnessSha} (MATCH)\n";
echo "  Guard Test SHA       : {$guardTestSha} (MATCH)\n";
echo "  CLI Command SHA      : {$cliCmdSha} (MATCH)\n";
echo "  CLI Runner SHA       : {$cliRunnerSha} (MATCH)\n";
echo "  Manifest SHA-256     : {$manifestSha}\n";
echo "  Paired Hold Seconds  : {$pairedHoldSeconds}s\n";
echo "  Private Output Dir   : {$outputDir} (0700)\n";

// 4. Execution Gate: FAIL-CLOSED by default
if (! $isExecute) {
    echo "\nSUPERVISOR STATUS: DRY-RUN OK (defaults to NO-EXECUTE).\n";
    echo "Staged lifecycle supervisor is verified against manifest pins and tree admission.\n";
    echo "To run live execution, Root must grant coordination via --execute and --root-approval-file=<path>.\n";
    exit(0);
}

// 5. Gate 1: Strict Prestart Root Approval Verification (Mandatory TTL, 0600/0700/UID)
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

// 6. Setup Signal Handling (No direct exit in handler to preserve finally block)
$aborted = false;
$abortSignal = 0;
$checkAborted = function () use (&$aborted): bool {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    return $aborted;
};

$sigHandler = function (int $signo) use (&$aborted, &$abortSignal): void {
    $aborted = true;
    $abortSignal = $signo;
};
pcntl_signal(SIGINT, $sigHandler);
pcntl_signal(SIGTERM, $sigHandler);

/** @var Process|null $childProcess */
$childProcess = null;
$schemaName = null;
$harnessDir = null;
$snapshot = null;
$parentEnvToRestore = [];
$cleanupExecuted = false;
$exitCode = 0;

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
        if ($checkAborted()) {
            throw new \RuntimeException("Execution aborted by signal {$abortSignal}.");
        }
        if (! $childProcess->isRunning()) {
            throw new \RuntimeException("Child harness terminated prematurely with code " . $childProcess->getExitCode() . ":\n" . $childOutput);
        }
        usleep(100000);
    }

    if (! $transportActive || $caCertPath === null || $schemaName === null) {
        throw new \RuntimeException("Timed out awaiting child transport readiness or path discovery.");
    }

    echo "  [Child] Schema       : {$schemaName}\n";
    echo "  [Child] CA Cert      : {$caCertPath}\n";

    // 7. Discover & Verify Owned Directories & Artifacts (0700 dirs, 0600 files, no symlinks)
    $bootstrapDir = dirname($caCertPath);
    SupervisedPairedFinancialDrill::assertSecureDirectoryPermissions($bootstrapDir, 'BOOTSTRAP');
    if (basename($bootstrapDir) !== 'bootstrap') {
        throw new \RuntimeException("Invalid bootstrap directory name: '{$bootstrapDir}'.");
    }
    SupervisedPairedFinancialDrill::assertSecureFilePermissions($caCertPath, 'CA_CERT');

    $harnessDir = dirname($bootstrapDir);
    SupervisedPairedFinancialDrill::assertSecureDirectoryPermissions($harnessDir, 'HARNESS_TEMP');
    if (! preg_match('/^v2_harness_[0-9a-f]{16}$/', basename($harnessDir))) {
        throw new \RuntimeException("Invalid harness temporary directory naming: '{$harnessDir}'.");
    }

    $childConfigPath = $bootstrapDir . '/child_bootstrap_config.json';
    SupervisedPairedFinancialDrill::assertSecureFilePermissions($childConfigPath, 'CHILD_CONFIG');

    $handoffPath = $harnessDir . '/runtime_client_handoff.json';
    SupervisedPairedFinancialDrill::assertSecureFilePermissions($handoffPath, 'CLIENT_HANDOFF');

    // 8. Validate Full Client Handoff Contract & Triple Match
    $handoffRaw = (string) file_get_contents($handoffPath);
    $candidateHandoffSha = hash('sha256', $handoffRaw);
    $handoff = json_decode($handoffRaw, true);
    if (! is_array($handoff)) {
        throw new \RuntimeException("Handoff file contains invalid JSON.");
    }

    DisposableMobileV2BackendHarness::validateClientHandoff($handoff);
    SupervisedPairedFinancialDrill::verifyHandoffTripleMatch($handoff);

    if (($handoff['lifecycle_phase'] ?? '') !== 'ACTIVE_TLS_READY') {
        throw new \RuntimeException("Handoff lifecycle_phase must be ACTIVE_TLS_READY.");
    }

    $readinessUrl = (string) ($handoff['startup_tls']['readiness_url'] ?? '');
    $readinessNonce = (string) ($handoff['startup_tls']['readiness_nonce'] ?? '');
    $caCertSha = (string) ($handoff['startup_tls']['ca_cert_sha256'] ?? '');

    if (! hash_equals(hash_file('sha256', $caCertPath), $caCertSha)) {
        throw new \RuntimeException("CA cert SHA256 mismatch vs handoff startup_tls.");
    }

    // Strict TLS GET 200 with URL allowlist before JVM and external Root approval
    echo "  [Probe] Performing strict TLS GET 200 check to allowlisted loopback URL...\n";
    SupervisedPairedFinancialDrill::performStrictTlsReadinessCheck($readinessUrl, $caCertPath, $readinessNonce);
    echo "  [Probe] Strict TLS probe verified 200 OK with matching nonce.\n";

    // 9. Gate 2: Expose Candidate Handoff & Bounded Wait for Independent External Live Root Approval
    echo "\n==============================================================================\n";
    echo "CANDIDATE CLIENT HANDOFF EXPOSED FOR ROOT WITNESS RECORD:\n";
    echo "  Path   : {$handoffPath}\n";
    echo "  SHA256 : {$candidateHandoffSha}\n";
    echo "==============================================================================\n";

    $liveApprovalTarget = $rootLiveApprovalFile ?? $outputDir . '/live_root_approval.json';
    echo "Awaiting independent external Root live approval at '{$liveApprovalTarget}'...\n";

    $rootApprovedHandoffSha = SupervisedPairedFinancialDrill::awaitAndVerifyLiveRootApproval(
        $liveApprovalTarget,
        $handoffPath,
        $candidateHandoffSha,
        $backendHead,
        $androidPubHead,
        $androidExeHead,
        $manifestSha,
        $schemaName,
        $childProcess,
        $deadline,
        $checkAborted,
        15.0
    );
    echo "  Root Live Gate       : GRANTED (witnessed SHA: {$rootApprovedHandoffSha})\n";

    // 10. Extract Payloads & Verified Canonical Digest (Receipt Semantics: profile digest != raw wire SHA)
    // rawWirePayloadSha and canonicalPayloadDigest have distinct semantic provenance (raw transport hash
    // vs normalized intent profile digest).  They are captured here for downstream inspector CLI args
    // and the approval hashes manifest; equality between them is not a correctness constraint.
    $rawWirePayloadSha = (string) ($handoff['raw_wire_sample']['raw_wire_payload_sha256'] ?? '');
    $rawProfileDigest = (string) ($handoff['canonical_intent_profile_digest'] ?? '');
    $canonicalPayloadDigest = SupervisedPairedFinancialDrill::normalizeCanonicalDigest($rawProfileDigest);
    $companyUlid = (string) ($handoff['fixture']['company_public_id'] ?? '');
    $expectedEventId = (string) ($handoff['fixture']['sample_event_id'] ?? '');

    // 11. AFTER BOTH approvals verified: snapshot parent env allowlist from ALL THREE stores
    //     (getenv + $_ENV + $_SERVER) BEFORE any mutation, including before the loader and before
    //     the scoped setters below.  The allowlist covers every var the loader may set plus the
    //     scoped testing flags.  Capturing here ensures the finally block restores the exact
    //     pre-entry ambient state on any exception path, preventing ambient production DSN leakage.
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
    foreach ($envAllowlist as $varName) {
        // Capture from all three env stores; getenv() is canonical for putenv-set vars.
        $parentEnvToRestore[$varName] = [
            'getenv' => getenv($varName),
            'ENV'    => $_ENV[$varName]    ?? null,
            'SERVER' => $_SERVER[$varName] ?? null,
        ];
    }

    // Set scoped testing flags AFTER originals are captured and AFTER both approvals are verified,
    // but BEFORE captureVerifiedOwnedSnapshot (which calls fromLiveOwnership -> assertPrimarySchema).
    // assertPrimarySchema refuses if APP_ENV != 'testing' or PHASE8_ALLOW_DISPOSABLE_DB != '1'.
    // These putenvs target only the supervisor parent process environment; they are restored in finally.
    $scopedPreCaptureVars = [
        'APP_ENV'                 => 'testing',
        'MOBILE_V2_HARNESS_ENABLE' => '1',
        'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
    ];
    foreach ($scopedPreCaptureVars as $varName => $varVal) {
        putenv("{$varName}={$varVal}");
        $_ENV[$varName]    = $varVal;
        $_SERVER[$varName] = $varVal;
    }

    // Load bootstrap credentials (this calls putenv for host/port/user/password/proof internally;
    // originals are already captured above, so the finally block will restore them unconditionally).
    $creds = DisposableFinancialPostRunInspector::loadSecureBootstrapCredentials($childConfigPath, $schemaName);
    $proofPath = $creds['proof_path'];
    $proofNonce = $creds['proof_nonce'];

    // Capture immutable owned snapshot: 6-arg signature (schema, proof, nonce, harness, childPid, repoRoot).
    // fromLiveOwnership inside performs ALL validation (schema/proof/nonce/harness/uid/path/assertPrimarySchema).
    // APP_ENV=testing and PHASE8_ALLOW_DISPOSABLE_DB=1 are now set; assertPrimarySchema will pass.
    $snapshot = SupervisedPairedFinancialDrill::captureVerifiedOwnedSnapshot(
        $schemaName,
        $proofPath,
        $proofNonce,
        $harnessDir,
        $childProcess->getPid(),
        $repoRoot
    );

    // Construct explicit inspectorEnv array with testing flags and testing DB creds (subprocess env)
    $inspectorEnv = array_merge($_ENV, [
        'APP_ENV'                    => 'testing',
        'MOBILE_V2_HARNESS_ENABLE'   => '1',
        'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
        'PHASE8_TEST_DB_HOST'        => $creds['host'],
        'PHASE8_TEST_DB_PORT'        => $creds['port'],
        'PHASE8_TEST_DB_USERNAME'    => $creds['username'],
        'PHASE8_TEST_DB_PASSWORD'    => $creds['password'],
        'PHASE8_DISPOSABLE_DB_PROOF' => (string) $proofPath,
        'PHASE8_DISPOSABLE_DB_NONCE' => (string) $proofNonce,
    ]);
    if ($creds['password'] === '') {
        $inspectorEnv['PHASE8_TEST_DB_EMPTY_PASSWORD'] = '1';
    }

    // Apply remaining scoped putenv mutations (host/port/user/password/proof) for parent process
    $scopedPostCaptureVars = [
        'PHASE8_TEST_DB_HOST'        => $creds['host'],
        'PHASE8_TEST_DB_PORT'        => $creds['port'],
        'PHASE8_TEST_DB_USERNAME'    => $creds['username'],
        'PHASE8_TEST_DB_PASSWORD'    => $creds['password'],
        'PHASE8_DISPOSABLE_DB_PROOF' => (string) $proofPath,
        'PHASE8_DISPOSABLE_DB_NONCE' => (string) $proofNonce,
    ];
    if ($creds['password'] === '') {
        $scopedPostCaptureVars['PHASE8_TEST_DB_EMPTY_PASSWORD'] = '1';
    }
    foreach ($scopedPostCaptureVars as $varName => $varVal) {
        putenv("{$varName}={$varVal}");
        $_ENV[$varName]    = $varVal;
        $_SERVER[$varName] = $varVal;
    }


    // Connect proof PDO and resolve integer company_id (DB inspection strictly AFTER approvals)
    $pdo = DisposableFinancialPostRunInspector::assertExecutionGates($schemaName);
    $companyId = SupervisedPairedFinancialDrill::resolveCompanyIntegerId($pdo, $companyUlid);

    echo "  [DB] Company Public ULID : {$companyUlid}\n";
    echo "  [DB] Company Integer ID  : {$companyId} (resolved via proof PDO)\n";

    // 12. Pre-Run Baseline Capture (Hard-gated before/after & bounded timeout)
    if (! $childProcess->isRunning()) {
        throw new \RuntimeException('CHILD_HARNESS_TERMINATED_BEFORE_BASELINE');
    }
    $remBaseline = SupervisedPairedFinancialDrill::computeRemainingDeadline($deadline, 30.0);
    $baselineTimeout = min($remBaseline - 25.0, 15.0);

    $baselineFile = $outputDir . '/pre_run_baseline.json';
    $baselineCmd = [
        PHP_BINARY,
        $repoRoot . '/tests/Support/Harness/financial-inspector-cli.php',
        '--baseline',
        '--credentials-file=' . $childConfigPath,
        '--schema=' . $schemaName,
        '--company-id=' . $companyId,
        '--output=' . $baselineFile,
    ];
    $baselineProc = new Process($baselineCmd, $repoRoot, $inspectorEnv, null, $baselineTimeout);
    $baselineProc->mustRun();
    chmod($baselineFile, 0600);

    if (! $childProcess->isRunning()) {
        throw new \RuntimeException('CHILD_HARNESS_TERMINATED_AFTER_BASELINE');
    }

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

    // 12. Execute Android Test Under Host JVM (Strictly 7 Approved Env Vars, Bounded Timeout)
    $targetAndroidDir = $androidRepoDir ?? $repoRoot . '/../android';
    if (! is_dir($targetAndroidDir)) {
        throw new \RuntimeException("Android repo directory not found at '{$targetAndroidDir}'.");
    }

    $evidenceRelPath = 'docs/integration/v2-online-cash-evidence/paired-online-cash-pilot-evidence.json';
    $evidenceFile = is_file($targetAndroidDir . '/' . $evidenceRelPath)
        ? $targetAndroidDir . '/' . $evidenceRelPath
        : $targetAndroidDir . '/app/' . $evidenceRelPath;

    $preRunTime = time();
    $preRunSha = is_file($evidenceFile) ? hash_file('sha256', $evidenceFile) : null;

    if (! $childProcess->isRunning()) {
        throw new \RuntimeException('CHILD_HARNESS_TERMINATED_BEFORE_GRADLE');
    }
    $remGradle = SupervisedPairedFinancialDrill::computeRemainingDeadline($deadline, 25.0);
    $gradleTimeout = min($remGradle - 20.0, 90.0);

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

    $rawLogPath = $outputDir . '/raw_android_test_output.log';
    file_put_contents($rawLogPath, $rawGradleOutput);
    chmod($rawLogPath, 0600);

    if (! $gradleProc->isSuccessful()) {
        throw new \RuntimeException("Android test execution failed with exit code " . $gradleProc->getExitCode() . ".");
    }

    if (! $childProcess->isRunning()) {
        throw new \RuntimeException("Child harness terminated after Gradle test execution.");
    }

    // 13. Verify Fresh Evidence and JUnit XML Results
    $freshEvidence = SupervisedPairedFinancialDrill::verifyEvidenceFreshness(
        $evidenceFile,
        $preRunTime,
        $preRunSha,
        $backendHead,
        $androidPubHead,
        $androidExeHead,
        $rootApprovedHandoffSha
    );
    $witnessedRunId = (string) ($freshEvidence['runId'] ?? '');
    if ($witnessedRunId === '' || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $witnessedRunId) !== 1) {
        throw new \RuntimeException("Witnessed client runId missing or invalid UUID format.");
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

    // Retain fresh artifacts in private output dir with 0600 permissions
    $retainedEvidencePath = $outputDir . '/paired-online-cash-pilot-evidence.json';
    copy($evidenceFile, $retainedEvidencePath);
    chmod($retainedEvidencePath, 0600);

    $retainedXmlPath = $outputDir . '/junit-test-report.xml';
    copy($junitXml, $retainedXmlPath);
    chmod($retainedXmlPath, 0600);

    // 14. In-Schema Inspection & Outer Evidence Validation (Hard-gated before/after)
    if (! $childProcess->isRunning()) {
        throw new \RuntimeException('CHILD_HARNESS_TERMINATED_BEFORE_DELTA_INSPECTION');
    }
    $remDelta = SupervisedPairedFinancialDrill::computeRemainingDeadline($deadline, 20.0);
    $deltaTimeout = min($remDelta - 15.0, 15.0);

    $deltaFile = $outputDir . '/delta_inspection.json';
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
    $deltaProc = new Process($deltaCmd, $repoRoot, $inspectorEnv, null, $deltaTimeout);
    $deltaProc->mustRun();
    chmod($deltaFile, 0600);

    if (! $childProcess->isRunning()) {
        throw new \RuntimeException('CHILD_HARNESS_TERMINATED_BEFORE_RECONCILIATION');
    }
    $remRecon = SupervisedPairedFinancialDrill::computeRemainingDeadline($deadline, 15.0);
    $reconTimeout = min($remRecon - 10.0, 15.0);

    $reconcileFile = $outputDir . '/reconciliation_results.json';
    $reconcileCmd = [
        PHP_BINARY,
        $repoRoot . '/tests/Support/Harness/financial-inspector-cli.php',
        '--reconcile',
        '--credentials-file=' . $childConfigPath,
        '--schema=' . $schemaName,
        '--company-id=' . $companyId,
        '--output=' . $reconcileFile,
    ];
    $reconcileProc = new Process($reconcileCmd, $repoRoot, $inspectorEnv, null, $reconTimeout);
    $reconcileProc->mustRun();
    chmod($reconcileFile, 0600);

    if (! $childProcess->isRunning()) {
        throw new \RuntimeException('CHILD_HARNESS_TERMINATED_BEFORE_REPORT_COMPOSE');
    }
    $remReport = SupervisedPairedFinancialDrill::computeRemainingDeadline($deadline, 10.0);
    $reportTimeout = min($remReport - 5.0, 10.0);

    $trustedDbReportFile = $outputDir . '/trusted_db_report.json';
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
    $composeProc = new Process($composeCmd, $repoRoot, $inspectorEnv, null, $reportTimeout);
    $composeProc->mustRun();
    chmod($trustedDbReportFile, 0600);
    $expectedDbReportSha = hash_file('sha256', $trustedDbReportFile);

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
    $validateProc = new Process($validateCmd, $repoRoot, $inspectorEnv, null, 10.0);
    $validateProc->mustRun();
    chmod($validationFile, 0600);

    // Write approval and artifact hashes manifest before cleanup
    $hashesManifest = [
        'backend_execution_head_sha' => $backendHead,
        'peer_android_approved_head_sha' => $androidPubHead,
        'peer_android_executable_head_sha' => $androidExeHead,
        'prestart_approval_sha256' => hash_file('sha256', $rootApprovalFile),
        'live_root_approval_sha256' => hash_file('sha256', $liveApprovalTarget),
        'candidate_handoff_sha256' => $candidateHandoffSha,
        'raw_wire_payload_sha256' => $rawWirePayloadSha,
        'canonical_payload_digest' => $canonicalPayloadDigest,
        'canonical_baseline_hash' => $canonicalBaselineHash,
        'trusted_db_report_sha256' => $expectedDbReportSha,
        'outer_evidence_sha256' => $expectedEvidenceSha,
        'created_at' => date('c'),
    ];
    $manifestOut = $outputDir . '/approval_hashes_manifest.json';
    file_put_contents($manifestOut, json_encode($hashesManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    chmod($manifestOut, 0600);

    echo "\n==============================================================================\n";
    echo "PAIRED FINANCIAL INTEGRATION DRILL COMPLETED SUCCESSFULLY\n";
    echo "Retained Artifacts Manifest: {$manifestOut}\n";
    echo "==============================================================================\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "\nSupervisor Error: {$e->getMessage()}\n");
    $exitCode = 1;
} finally {
    try {
        if (! $cleanupExecuted) {
            $cleanupExecuted = true;
            $cleanupResult = SupervisedPairedFinancialDrill::executeExactCleanup(
                $childProcess,
                $snapshot,
                $repoRoot
            );
            if (! $cleanupResult['success']) {
                fwrite(STDERR, "\n[Cleanup Failure] Structured cleanup check failed: " . implode('; ', $cleanupResult['errors']) . "\n");
                $exitCode = 1;
            }
        }
    } finally {
        // Restore all three env stores to their pre-entry originals AFTER cleanup even on exceptions.
        // $parentEnvToRestore[$varName] is ['getenv' => string|false, 'ENV' => string|null, 'SERVER' => string|null].
        foreach ($parentEnvToRestore as $varName => $stores) {
            // Restore getenv/putenv store
            $origGetenv = $stores['getenv'];
            if ($origGetenv === false) {
                putenv($varName);
            } else {
                putenv("{$varName}={$origGetenv}");
            }
            // Restore $_ENV
            if ($stores['ENV'] === null) {
                unset($_ENV[$varName]);
            } else {
                $_ENV[$varName] = $stores['ENV'];
            }
            // Restore $_SERVER
            if ($stores['SERVER'] === null) {
                unset($_SERVER[$varName]);
            } else {
                $_SERVER[$varName] = $stores['SERVER'];
            }
        }
    }
    if ($aborted) {
        $exitCode = ($abortSignal === SIGINT) ? 130 : 143;
    }
}

exit($exitCode);
