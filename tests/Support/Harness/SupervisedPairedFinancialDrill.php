<?php

declare(strict_types=1);

namespace Tests\Support\Harness;

use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;

/**
 * Supervised Mobile V2 Paired Financial Integration Drill.
 *
 * Enforces strict adversarial validation across all live execution phases:
 * 1. Mandatory prestart Root approval TTL + private permissions (0600/0700/UID/no-symlinks) + identical pub/exe allowed.
 * 2. Mandatory bounded wait for independent external live Root approval post-handoff, pre-JVM (no auto-approval).
 * 3. Hard-gated child alive & remaining deadline checks before and after EVERY subprocess with bounded timeouts.
 * 4. Private unique output directory outside checkouts (0700/0600) retaining all artifacts before cleanup.
 * 5. Prevalidated owned snapshot captured while live proof exists before child kill (schema regex, 3342 port identity, owned temp).
 * 6. Structured exact cleanup requiring valid snapshot before ANY destructive database drop or file removal; skips unproven partial setups; fails non-zero on lingering resources.
 * 7. Mandatory pcntl availability, signal trap with no-reentry cleanup, and non-zero exit on abort.
 * 8. Mandatory live approval TTL, scope, schema, and whole-file pins equality.
 * 9. Manifest SHA override verified against actual file bytes; app tree admission verified via assertAdmittedAppTree.
 * 10. Explicit allowlist for readiness URL (strictly loopback https://127.0.0.1:18443/_harness/readiness).
 * 11. Triple-match of sample, parsed, and raw canonical JSON with payload hash independently verified.
 * 12. Strict Kotlin top-level evidence keys (runId, backendHead, clientPublicationHead, clientExecutableHead, manifestDigest) and 7 scenarios.
 * 13. Canonical intent profile digest semantic normalization (OnlineSaleIntentProfile::computeDigest).
 * 14. Scoped parent/inspector environment created only AFTER both approval records verified; restored in finally.
 */
class SupervisedPairedFinancialDrill
{
    public const DEFAULT_HOLD_SECONDS = 120;
    public const REQUIRED_SCOPE = 'ROOT_AUTHORIZED_PAIRED_EXECUTION';
    public const REQUIRED_ADVANCED_SCOPE = 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION';
    public const ALLOWED_SCOPES = [
        self::REQUIRED_SCOPE,
        self::REQUIRED_ADVANCED_SCOPE,
    ];
    public const REQUIRED_LIFECYCLE_GRANT = 'FINANCIAL_PAIRED_RUN';
    public const ALLOWED_READINESS_URL = 'https://127.0.0.1:18443/_harness/readiness';
    public const SCHEMA_REGEX = '/^accounting_p8_tmp_[a-f0-9]{12}$/D';
    public const HARNESS_DIR_REGEX = '/^v2_harness_[0-9a-f]{16}$/D';
    public const PROOF_PARENT_DIR_REGEX = '/^p8_proof_[0-9a-f]{16}$/D';

    /**
     * Optional negative-only test hook that can force a refusal for fault-path testing.
     * Can NEVER grant valid true or turn an invalid process into a valid one.
     * Signature: ?callable(int $pid, int $expectedPpid, ?string $expectedStartTicks, ?string $expectedPathSubstring): ?string
     */
    public static ?\Closure $negativeRefusalHook = null;

    /**
     * Optional test hook to intercept signal sending without signaling live processes.
     * Signature: ?callable(int $pid, int $signal): bool
     */
    public static ?\Closure $signalDispatcherHook = null;

    /**
     * Validates prestart Root coordination approval file.
     * Enforces mandatory TTL, private permissions (0600 file, 0700 parent, current UID, no symlinks),
     * and asserts exact matches against dynamic HEAD, manifest SHA, and CWD.
     * CLI arguments cannot override approval values. Identical publication and executable HEADs are allowed.
     *
     * @param  string  $filePath  Path to approval file
     * @param  string  $expectedManifestSha  Pinned manifest SHA-256
     * @param  string  $expectedBackendHead  Dynamic git rev-parse HEAD
     * @param  string|null  $cliPubHead  Optional CLI arg for publication HEAD
     * @param  string|null  $cliExeHead  Optional CLI arg for executable HEAD
     * @param  string  $repoRoot  Repository root path
     * @return array{
     *     root_coordination_granted: bool,
     *     manifest_sha256: string,
     *     backend_head: string,
     *     android_publication_head: string,
     *     android_executable_head: string,
     *     cwd: string,
     *     expires_at: string
     * }
     *
     * @throws RuntimeException
     */
    public static function verifyPrestartApproval(
        string $filePath,
        string $expectedManifestSha,
        string $expectedBackendHead,
        ?string $cliPubHead,
        ?string $cliExeHead,
        string $repoRoot,
        string $expectedScope = self::REQUIRED_SCOPE
    ): array {
        self::assertSecureFilePermissions($filePath, 'PRESTART_APPROVAL');

        $raw = (string) file_get_contents($filePath);
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException('PRESTART_APPROVAL_INVALID_JSON');
        }

        if (($data['root_coordination_granted'] ?? null) !== true) {
            throw new RuntimeException('PRESTART_APPROVAL_NOT_GRANTED: root_coordination_granted must be boolean true');
        }

        // Mandatory TTL enforcement
        if (empty($data['expires_at']) || ! is_string($data['expires_at'])) {
            throw new RuntimeException('PRESTART_APPROVAL_TTL_MISSING: expires_at timestamp is mandatory');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $data['expires_at']) !== 1) {
            throw new RuntimeException('PRESTART_APPROVAL_TTL_INVALID: expires_at must be valid ISO 8601 timestamp');
        }
        $exp = strtotime($data['expires_at']);
        if ($exp === false || $exp <= time()) {
            throw new RuntimeException('PRESTART_APPROVAL_EXPIRED');
        }

        $appManifestSha = (string) ($data['manifest_sha256'] ?? '');
        if (! hash_equals($expectedManifestSha, $appManifestSha)) {
            throw new RuntimeException("PRESTART_APPROVAL_MANIFEST_MISMATCH: expected {$expectedManifestSha}, got {$appManifestSha}");
        }

        $appBackendHead = (string) ($data['backend_head'] ?? '');
        if (! hash_equals($expectedBackendHead, $appBackendHead)) {
            throw new RuntimeException("PRESTART_APPROVAL_BACKEND_HEAD_MISMATCH: expected {$expectedBackendHead}, got {$appBackendHead}");
        }

        $appPubHead = (string) ($data['android_publication_head'] ?? '');
        if (preg_match('/^[0-9a-f]{40}$/', $appPubHead) !== 1) {
            throw new RuntimeException('PRESTART_APPROVAL_INVALID_PUB_HEAD: must be 40-hex lowercase SHA');
        }
        if ($cliPubHead !== null && $cliPubHead !== '' && ! hash_equals($appPubHead, $cliPubHead)) {
            throw new RuntimeException("PRESTART_APPROVAL_CLI_PUB_OVERRIDE_REFUSED: CLI arg {$cliPubHead} != approval {$appPubHead}");
        }

        $appExeHead = (string) ($data['android_executable_head'] ?? '');
        if (preg_match('/^[0-9a-f]{40}$/', $appExeHead) !== 1) {
            throw new RuntimeException('PRESTART_APPROVAL_INVALID_EXE_HEAD: must be 40-hex lowercase SHA');
        }
        if ($cliExeHead !== null && $cliExeHead !== '' && ! hash_equals($appExeHead, $cliExeHead)) {
            throw new RuntimeException("PRESTART_APPROVAL_CLI_EXE_OVERRIDE_REFUSED: CLI arg {$cliExeHead} != approval {$appExeHead}");
        }

        $realRoot = realpath($repoRoot);
        $appCwd = realpath((string) ($data['cwd'] ?? ''));
        if ($realRoot === false || $appCwd === false || $realRoot !== $appCwd) {
            throw new RuntimeException("PRESTART_APPROVAL_CWD_MISMATCH: expected {$realRoot}, got " . ($data['cwd'] ?? ''));
        }

        $scope = (string) ($data['scope'] ?? self::REQUIRED_SCOPE);
        if (! in_array($scope, self::ALLOWED_SCOPES, true) || $scope !== $expectedScope) {
            throw new RuntimeException("PRESTART_APPROVAL_SCOPE_MISMATCH: expected {$expectedScope}, got '{$scope}'");
        }

        return [
            'root_coordination_granted' => true,
            'manifest_sha256' => $appManifestSha,
            'backend_head' => $appBackendHead,
            'android_publication_head' => $appPubHead,
            'android_executable_head' => $appExeHead,
            'cwd' => $realRoot,
            'expires_at' => $data['expires_at'],
            'scope' => $scope,
        ];
    }

    /**
     * Bounded wait for independent external live Root approval file after handoff creation before JVM.
     * Does NOT auto-create. Polls boundedly while asserting child is alive and deadline is unexpired.
     *
     * @param  string  $approvalPath  Path where Root writes live approval record
     * @param  string  $candidateHandoffPath  Path to candidate handoff file
     * @param  string  $candidateHandoffSha  SHA-256 of candidate handoff file
     * @param  string  $backendHead  Verified backend HEAD
     * @param  string  $pubHead  Verified Android publication HEAD
     * @param  string  $exeHead  Verified Android executable HEAD
     * @param  string  $manifestSha  Verified manifest SHA
     * @param  string  $schemaName  Owned schema name
     * @param  Process  $childProcess  Running child harness process
     * @param  float  $deadline  Child hold deadline timestamp
     * @param  callable  $checkAborted  Callback returning bool if signal aborted
     * @param  float  $maxWaitSeconds  Maximum wait duration
     * @return string Root-approved whole handoff SHA-256
     *
     * @throws RuntimeException
     */
    public static function awaitAndVerifyLiveRootApproval(
        string $approvalPath,
        string $candidateHandoffPath,
        string $candidateHandoffSha,
        string $backendHead,
        string $pubHead,
        string $exeHead,
        string $manifestSha,
        string $schemaName,
        Process $childProcess,
        float $deadline,
        callable $checkAborted,
        float $maxWaitSeconds = 45.0,
        string $expectedScope = self::REQUIRED_SCOPE
    ): string {
        $startWait = microtime(true);
        $effectiveMaxWait = min($maxWaitSeconds, max(1.0, $deadline - microtime(true) - 25.0));

        while (! is_file($approvalPath)) {
            if ($checkAborted()) {
                throw new RuntimeException('ABORTED_WHILE_AWAITING_LIVE_ROOT_APPROVAL');
            }
            if (! $childProcess->isRunning()) {
                throw new RuntimeException('CHILD_HARNESS_TERMINATED_WHILE_AWAITING_LIVE_ROOT_APPROVAL');
            }
            if ((microtime(true) - $startWait) >= $effectiveMaxWait || (microtime(true) + 25.0) >= $deadline) {
                throw new RuntimeException("TIMED_OUT_AWAITING_LIVE_ROOT_APPROVAL: record '{$approvalPath}' not found within {$effectiveMaxWait}s");
            }
            usleep(200000); // 200ms
        }

        return self::verifyLiveRootApproval(
            $approvalPath,
            $candidateHandoffPath,
            $candidateHandoffSha,
            $backendHead,
            $pubHead,
            $exeHead,
            $manifestSha,
            $schemaName,
            $expectedScope
        );
    }

    /**
     * Validates independent external live Root approval record.
     * Enforces mandatory TTL, scope, schema_name, whole file SHA, and pin equality.
     *
     * @param  string  $filePath  Path to live approval file
     * @param  string  $candidateHandoffPath  Path to generated handoff file
     * @param  string  $candidateHandoffSha  SHA-256 of candidate handoff file
     * @param  string  $backendHead  Verified backend HEAD
     * @param  string  $pubHead  Verified Android publication HEAD
     * @param  string  $exeHead  Verified Android executable HEAD
     * @param  string  $manifestSha  Verified manifest SHA
     * @param  string  $schemaName  Owned schema name
     * @param  string  $expectedScope  Expected Root approval scope
     * @return string The Root-approved handoff SHA-256
     *
     * @throws RuntimeException
     */
    public static function verifyLiveRootApproval(
        string $filePath,
        string $candidateHandoffPath,
        string $candidateHandoffSha,
        string $backendHead,
        string $pubHead,
        string $exeHead,
        string $manifestSha,
        string $schemaName,
        string $expectedScope = self::REQUIRED_SCOPE
    ): string {
        self::assertSecureFilePermissions($filePath, 'LIVE_ROOT_APPROVAL');

        $raw = (string) file_get_contents($filePath);
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException('LIVE_ROOT_APPROVAL_INVALID_JSON');
        }

        if (($data['root_live_handoff_approved'] ?? null) !== true) {
            throw new RuntimeException('LIVE_ROOT_APPROVAL_NOT_APPROVED: root_live_handoff_approved must be boolean true');
        }

        // Mandatory TTL enforcement
        if (empty($data['expires_at']) || ! is_string($data['expires_at'])) {
            throw new RuntimeException('LIVE_ROOT_APPROVAL_TTL_MISSING: expires_at timestamp is mandatory');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $data['expires_at']) !== 1) {
            throw new RuntimeException('LIVE_ROOT_APPROVAL_TTL_INVALID: expires_at must be valid ISO 8601 timestamp');
        }
        $exp = strtotime($data['expires_at']);
        if ($exp === false || $exp <= time()) {
            throw new RuntimeException('LIVE_ROOT_APPROVAL_EXPIRED');
        }

        // Scope enforcement
        $scope = (string) ($data['scope'] ?? '');
        if (! in_array($scope, self::ALLOWED_SCOPES, true) || $scope !== $expectedScope) {
            throw new RuntimeException("LIVE_ROOT_APPROVAL_SCOPE_MISMATCH: expected {$expectedScope}, got '{$scope}'");
        }

        // Schema name enforcement
        $appSchema = (string) ($data['schema_name'] ?? '');
        if (! hash_equals($schemaName, $appSchema)) {
            throw new RuntimeException("LIVE_ROOT_APPROVAL_SCHEMA_MISMATCH: expected {$schemaName}, got {$appSchema}");
        }

        $appPath = (string) ($data['handoff_path'] ?? '');
        if (realpath($candidateHandoffPath) !== realpath($appPath)) {
            throw new RuntimeException("LIVE_ROOT_APPROVAL_PATH_MISMATCH: expected {$candidateHandoffPath}, got {$appPath}");
        }

        $appSha = (string) ($data['whole_handoff_sha256'] ?? '');
        if (! hash_equals($candidateHandoffSha, $appSha)) {
            throw new RuntimeException("LIVE_ROOT_APPROVAL_SHA_MISMATCH: candidate {$candidateHandoffSha} != approval {$appSha}");
        }

        if (! hash_equals($backendHead, (string) ($data['backend_head'] ?? ''))) {
            throw new RuntimeException('LIVE_ROOT_APPROVAL_BACKEND_HEAD_MISMATCH');
        }
        if (! hash_equals($pubHead, (string) ($data['android_publication_head'] ?? ''))) {
            throw new RuntimeException('LIVE_ROOT_APPROVAL_PUB_HEAD_MISMATCH');
        }
        if (! hash_equals($exeHead, (string) ($data['android_executable_head'] ?? ''))) {
            throw new RuntimeException('LIVE_ROOT_APPROVAL_EXE_HEAD_MISMATCH');
        }
        if (! hash_equals($manifestSha, (string) ($data['manifest_sha256'] ?? ''))) {
            throw new RuntimeException('LIVE_ROOT_APPROVAL_MANIFEST_SHA_MISMATCH');
        }

        return $appSha;
    }

    /**
     * Resolves internal integer company_id from public ULID via verified child config PDO.
     * NEVER casts ULID to integer. Fails closed if company does not exist.
     *
     * @param  PDO  $pdo  Connected PDO to owned disposable schema
     * @param  string  $publicUlid  26-character company public ULID
     * @return int Internal integer company ID
     *
     * @throws RuntimeException
     */
    public static function resolveCompanyIntegerId(PDO $pdo, string $publicUlid): int
    {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $publicUlid) !== 1) {
            throw new RuntimeException("COMPANY_ULID_INVALID_FORMAT: '{$publicUlid}'");
        }

        $stmt = $pdo->prepare('SELECT id FROM companies WHERE public_id = ? LIMIT 1');
        $stmt->execute([$publicUlid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! is_array($row) || ! isset($row['id'])) {
            throw new RuntimeException("COMPANY_ULID_NOT_FOUND: Company with public_id '{$publicUlid}' does not exist in owned schema.");
        }

        $id = (int) $row['id'];
        if ($id <= 0) {
            throw new RuntimeException("COMPANY_INTEGER_ID_INVALID: Non-positive ID returned for public_id '{$publicUlid}'.");
        }

        return $id;
    }

    /**
     * Thin delegator that calls VerifiedOwnedSnapshot::fromLiveOwnership().
     * All validation (schema regex, proof file, nonce, harness dir, UID, path, assertPrimarySchema,
     * sys_get_temp_dir containment, positive child PID) lives inside the value object factory.
     * The only caller concern retained here is the proof-vars env snapshot/restore around
     * assertPrimarySchema, which is an execution-context responsibility.
     *
     * @param  string  $schemaName  Exact schema name (must match SCHEMA_REGEX)
     * @param  string  $proofPath   Path to disposable DB ownership proof file
     * @param  string  $proofNonce  Disposable DB ownership proof nonce
     * @param  string  $harnessDir  Owned temporary directory path
     * @param  int     $childPid    Child CLI process PID (positive integer)
     * @param  string  $repoRoot    Checkout root directory
     *
     * @throws RuntimeException
     */
    public static function validateProcessFacts(
        array $facts,
        int $expectedPpid = -1,
        ?string $expectedStartTicks = null,
        ?string $expectedPathSubstring = null,
        bool $allowOrphanAdoptedByInit = false
    ): array {
        $pid = (int) ($facts['pid'] ?? 0);

        // 1. Immediate rejection of forbidden PIDs
        if ($pid <= 1) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => "REFUSED_ROOT_OR_INVALID_PID_{$pid}",
            ];
        }
        if ($pid === getmypid()) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => "REFUSED_OWN_SUPERVISOR_PID_{$pid}",
            ];
        }
        if (function_exists('posix_getppid') && $pid === posix_getppid()) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => "REFUSED_PARENT_SUPERVISOR_PID_{$pid}",
            ];
        }

        // 2. Directory and link validation: mandatory facts, no skipped validation
        if (! isset($facts['is_dir']) || ! is_bool($facts['is_dir'])) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_FACTS_IS_DIR_MISSING',
            ];
        }
        if (! $facts['is_dir']) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_DIR_MISSING',
            ];
        }

        if (! isset($facts['is_link']) || ! is_bool($facts['is_link'])) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_FACTS_IS_LINK_MISSING',
            ];
        }
        if ($facts['is_link']) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_DIR_LINK_REFUSED',
            ];
        }

        // 3. Mandatory UID check: must be integer and match current process UID
        if (! isset($facts['uid']) || ! is_int($facts['uid'])) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_FACTS_UID_MISSING',
            ];
        }
        if (! function_exists('posix_getuid')) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'POSIX_GETUID_UNAVAILABLE',
            ];
        }
        $expectedUid = posix_getuid();
        if ($facts['uid'] !== $expectedUid) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => "PROC_UID_MISMATCH: owner {$facts['uid']} vs expected {$expectedUid}",
            ];
        }

        // 4. Valid numeric start ticks check (must be non-empty, all-digits, > 0)
        $actualStartTicks = isset($facts['start_ticks']) ? trim((string) $facts['start_ticks']) : '';
        if ($actualStartTicks === '' || $actualStartTicks === '0' || ! ctype_digit($actualStartTicks)) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => isset($facts['ppid']) ? (int) $facts['ppid'] : null,
                'error' => 'PROC_START_TICKS_INVALID',
            ];
        }

        if ($expectedStartTicks !== null) {
            if ($expectedStartTicks === '' || ! ctype_digit($expectedStartTicks)) {
                return [
                    'valid' => false,
                    'start_ticks' => $actualStartTicks,
                    'ppid' => isset($facts['ppid']) ? (int) $facts['ppid'] : null,
                    'error' => 'PROC_EXPECTED_START_TICKS_INVALID',
                ];
            }
            if ($actualStartTicks !== $expectedStartTicks) {
                return [
                    'valid' => false,
                    'start_ticks' => $actualStartTicks,
                    'ppid' => isset($facts['ppid']) ? (int) $facts['ppid'] : null,
                    'error' => "PROC_START_TICKS_MISMATCH: actual {$actualStartTicks} vs expected {$expectedStartTicks}",
                ];
            }
        }

        // 5. PPID lineage check and orphan handling
        $actualPpid = isset($facts['ppid']) && is_int($facts['ppid']) ? $facts['ppid'] : null;
        if ($expectedPpid > 0) {
            if ($actualPpid === null || $actualPpid <= 0) {
                return [
                    'valid' => false,
                    'start_ticks' => $actualStartTicks,
                    'ppid' => null,
                    'error' => 'PROC_PPID_MISSING',
                ];
            }
            if ($actualPpid !== $expectedPpid) {
                // Orphan adopted by init (PID 1) is ONLY allowed if:
                // 1) $allowOrphanAdoptedByInit is true
                // 2) Previously captured matching start ticks exist
                // 3) Previously captured owned path substring exists and matches
                $isAdoptedOrphan = $allowOrphanAdoptedByInit && $actualPpid === 1;
                if ($isAdoptedOrphan) {
                    if ($expectedStartTicks === null || $expectedStartTicks === '' || ! ctype_digit($expectedStartTicks)) {
                        return [
                            'valid' => false,
                            'start_ticks' => $actualStartTicks,
                            'ppid' => $actualPpid,
                            'error' => 'PROC_ORPHAN_START_TICKS_UNPROVEN',
                        ];
                    }
                    if ($actualStartTicks !== $expectedStartTicks) {
                        return [
                            'valid' => false,
                            'start_ticks' => $actualStartTicks,
                            'ppid' => $actualPpid,
                            'error' => "PROC_START_TICKS_MISMATCH: actual {$actualStartTicks} vs expected {$expectedStartTicks}",
                        ];
                    }
                    if ($expectedPathSubstring === null || $expectedPathSubstring === '') {
                        return [
                            'valid' => false,
                            'start_ticks' => $actualStartTicks,
                            'ppid' => $actualPpid,
                            'error' => 'PROC_ORPHAN_PATH_UNPROVEN',
                        ];
                    }
                    $cmdContent = (string) ($facts['cmdline'] ?? '');
                    if ($cmdContent === '' || ! str_contains($cmdContent, $expectedPathSubstring)) {
                        return [
                            'valid' => false,
                            'start_ticks' => $actualStartTicks,
                            'ppid' => $actualPpid,
                            'error' => 'PROC_CMDLINE_PATH_MISMATCH',
                        ];
                    }
                } else {
                    return [
                        'valid' => false,
                        'start_ticks' => $actualStartTicks,
                        'ppid' => $actualPpid,
                        'error' => "PROC_PPID_LINEAGE_MISMATCH: actual {$actualPpid} vs expected {$expectedPpid}",
                    ];
                }
            }
        }

        // 6. Cmdline substring check
        if ($expectedPathSubstring !== null) {
            $cmdContent = (string) ($facts['cmdline'] ?? '');
            if ($cmdContent === '' || ! str_contains($cmdContent, $expectedPathSubstring)) {
                return [
                    'valid' => false,
                    'start_ticks' => $actualStartTicks,
                    'ppid' => $actualPpid,
                    'error' => 'PROC_CMDLINE_PATH_MISMATCH',
                ];
            }
        }

        return [
            'valid' => true,
            'start_ticks' => $actualStartTicks,
            'ppid' => $actualPpid,
            'error' => null,
        ];
    }

    /**
     * Strictly verifies process ownership and lineage against live Linux /proc.
     * Always inspects live /proc for positive validation; never bypassed by positive hooks.
     * Optional negative-only hook can force refusal for testing fault handling, but can NEVER turn invalid into valid.
     *
     * @return array{valid: bool, start_ticks: ?string, ppid: ?int, error: ?string}
     */
    public static function verifyProcessOwnership(
        int $pid,
        int $expectedPpid = -1,
        ?string $expectedStartTicks = null,
        ?string $expectedPathSubstring = null,
        bool $allowOrphanAdoptedByInit = false
    ): array {
        // Negative-only test hook: can force refusal (e.g. to test error paths), but CANNOT grant valid true.
        if (self::$negativeRefusalHook !== null) {
            $forcedError = (self::$negativeRefusalHook)($pid, $expectedPpid, $expectedStartTicks, $expectedPathSubstring);
            if ($forcedError !== null) {
                return [
                    'valid' => false,
                    'start_ticks' => null,
                    'ppid' => null,
                    'error' => "FORCED_TEST_REFUSAL: {$forcedError}",
                ];
            }
        }

        // Live Linux /proc inspection
        $procDir = "/proc/{$pid}";
        if (! is_dir($procDir)) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_DIR_MISSING',
            ];
        }
        if (is_link($procDir)) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_DIR_LINK_REFUSED',
            ];
        }

        if (! function_exists('posix_getuid')) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'POSIX_GETUID_UNAVAILABLE',
            ];
        }

        $ownerUid = @fileowner($procDir);
        if ($ownerUid === false) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_UID_UNREADABLE',
            ];
        }

        $statPath = "{$procDir}/stat";
        if (! is_file($statPath) || ! is_readable($statPath)) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_STAT_UNREADABLE',
            ];
        }
        $statContent = @file_get_contents($statPath);
        if ($statContent === false || $statContent === '') {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_STAT_EMPTY',
            ];
        }
        $lastParen = strrpos($statContent, ')');
        if ($lastParen === false) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_STAT_MALFORMED',
            ];
        }
        $rest = trim(substr($statContent, $lastParen + 1));
        $fields = explode(' ', $rest);
        if (! isset($fields[1], $fields[19])) {
            return [
                'valid' => false,
                'start_ticks' => null,
                'ppid' => null,
                'error' => 'PROC_STAT_FIELDS_INCOMPLETE',
            ];
        }

        $actualPpid = (int) $fields[1];
        $actualStartTicks = (string) $fields[19];

        $cmdline = null;
        $cmdPath = "{$procDir}/cmdline";
        if (is_file($cmdPath) && is_readable($cmdPath)) {
            $cmdline = (string) @file_get_contents($cmdPath);
        }

        $facts = [
            'pid' => $pid,
            'uid' => (int) $ownerUid,
            'ppid' => $actualPpid,
            'start_ticks' => $actualStartTicks,
            'cmdline' => $cmdline,
            'is_dir' => true,
            'is_link' => false,
        ];

        return self::validateProcessFacts(
            $facts,
            $expectedPpid,
            $expectedStartTicks,
            $expectedPathSubstring,
            $allowOrphanAdoptedByInit
        );
    }

    /**
     * Resolves verified process start identity. Returns null if unverified.
     */
    public static function getProcessStartIdentity(int $pid): ?string
    {
        $res = self::verifyProcessOwnership($pid);

        return ($res['valid'] ?? false) ? $res['start_ticks'] : null;
    }

    /**
     * Checks if a process is alive without throwing.
     */
    public static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_dir("/proc/{$pid}");
    }

    /**
     * Dispatches a signal to a process using test hook or posix_kill.
     * Private dispatcher to prevent generic arbitrary signal injection.
     */
    private static function sendSignal(int $pid, int $signal): bool
    {
        if (self::$signalDispatcherHook !== null) {
            return (bool) (self::$signalDispatcherHook)($pid, $signal);
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, $signal);
        }

        return false;
    }

    /**
     * Double-verifies process ownership and lineage immediately before sending a signal.
     * Strictly private to cleanup; requires exact expected PPID, non-null numeric expected start ticks,
     * and non-null expected path substring. Refuses root PID, supervisor own PID, supervisor parent PID,
     * and mismatched start ticks/path.
     */
    private static function sendVerifiedSignal(
        int $pid,
        int $signal,
        int $expectedPpid,
        string $expectedStartTicks,
        string $expectedPathSubstring,
        bool $allowOrphanAdoptedByInit = false
    ): bool {
        if ($pid <= 1 || $pid === getmypid()) {
            return false;
        }
        if (function_exists('posix_getppid') && $pid === posix_getppid()) {
            return false;
        }

        $check = self::verifyProcessOwnership(
            $pid,
            $expectedPpid,
            $expectedStartTicks,
            $expectedPathSubstring,
            $allowOrphanAdoptedByInit
        );
        if (! ($check['valid'] ?? false)) {
            return false;
        }

        return self::sendSignal($pid, $signal);
    }

    /**
     * Captures and validates an immutable verified owned snapshot of a live paired run.
     * Performs strict DB proof, nonce, schema, directory, PID, and permission assertions.
     *
     * @param  string  $schemaName  Must match disposable pattern
     * @param  string  $proofPath   Path to disposable DB ownership proof file
     * @param  string  $proofNonce  Ownership proof nonce
     * @param  string  $harnessDir  Owned temporary directory path
     * @param  int     $childPid    Child CLI process PID (positive integer)
     * @param  string  $repoRoot    Checkout root directory
     * @param  string|null  $pidsJsonPath  Path to process_pids.json for advanced execution
     *
     * @throws RuntimeException
     */
    public static function captureVerifiedOwnedSnapshot(
        string $schemaName,
        string $proofPath,
        string $proofNonce,
        string $harnessDir,
        int $childPid,
        string $repoRoot,
        ?string $pidsJsonPath = null
    ): VerifiedOwnedSnapshot {
        return VerifiedOwnedSnapshot::fromLiveOwnership(
            schemaName: $schemaName,
            proofPath: $proofPath,
            proofNonce: $proofNonce,
            harnessDir: $harnessDir,
            childPid: $childPid,
            repoRoot: $repoRoot,
            pidsJsonPath: $pidsJsonPath
        );
    }

    /**
     * Verifies triple match between parsed_request, raw_wire_payload_json, raw_wire_payload_sha256,
     * sample_event_id, and intent profile digest.
     *
     * @param  array<string, mixed>  $handoff  Parsed handoff array
     *
     * @throws RuntimeException
     */
    public static function verifyHandoffTripleMatch(array $handoff): void
    {
        $rawWire = $handoff['raw_wire_sample'] ?? null;
        if (! is_array($rawWire)) {
            throw new RuntimeException('HANDOFF_RAW_WIRE_SAMPLE_MISSING');
        }

        $parsedRequest = $rawWire['parsed_request'] ?? null;
        if (! is_array($parsedRequest)) {
            throw new RuntimeException('HANDOFF_PARSED_REQUEST_MISSING');
        }

        $rawWireJson = (string) ($rawWire['raw_wire_payload_json'] ?? '');
        $rawWireSha = (string) ($rawWire['raw_wire_payload_sha256'] ?? '');

        // 1. Serialization match
        $serialized = CanonicalJsonSerializer::serialize($parsedRequest);
        if ($serialized !== $rawWireJson) {
            throw new RuntimeException('HANDOFF_WIRE_SERIALIZATION_MISMATCH');
        }

        // 2. Hash match
        $computedSha = hash('sha256', $rawWireJson);
        if (! hash_equals($computedSha, $rawWireSha)) {
            throw new RuntimeException("HANDOFF_WIRE_SHA_MISMATCH: computed {$computedSha} != {$rawWireSha}");
        }

        // 3. Event ID match
        $sampleEventId = (string) ($handoff['fixture']['sample_event_id'] ?? '');
        $parsedEventId = (string) ($parsedRequest['client_event_id'] ?? '');
        if (! hash_equals($sampleEventId, $parsedEventId)) {
            throw new RuntimeException("HANDOFF_EVENT_ID_TRIPLE_MISMATCH: sample {$sampleEventId} != parsed {$parsedEventId}");
        }

        // 4. Intent profile digest match
        $serverCtx = $handoff['server_synthesized_context'] ?? [];
        if (! is_array($serverCtx)) {
            throw new RuntimeException('HANDOFF_SERVER_CONTEXT_MISSING');
        }
        $profile = OnlineSaleIntentProfile::construct($serverCtx, $parsedRequest);
        $computedDigest = OnlineSaleIntentProfile::computeDigest($profile);
        $handoffDigest = (string) ($handoff['canonical_intent_profile_digest'] ?? '');
        if (! hash_equals($computedDigest, $handoffDigest)) {
            throw new RuntimeException("HANDOFF_PROFILE_DIGEST_MISMATCH: computed {$computedDigest} != handoff {$handoffDigest}");
        }
    }

    /**
     * Normalizes a canonical intent profile digest by stripping the 'sha256:' prefix if present,
     * validating that the result is an exact 64-hex SHA-256 string.
     *
     * @param  string  $digest  Raw digest from handoff or intent profile
     * @return string Normalized 64-hex SHA-256 string
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeCanonicalDigest(string $digest): string
    {
        $normalized = str_starts_with($digest, 'sha256:') ? substr($digest, 7) : $digest;
        if (! preg_match('/^[0-9a-f]{64}$/D', $normalized)) {
            throw new \InvalidArgumentException("Invalid canonical digest format: '{$digest}'. Expected 64-hex SHA-256.");
        }

        return $normalized;
    }

    /**
     * Validates fresh Android test evidence artifact.
     * Enforces strictly the actual Kotlin top-level keys (runId, backendHead, clientPublicationHead,
     * clientExecutableHead, manifestDigest) and 7 scenarios (5 PASS, 2 NOT_RUN with null httpStatusCode).
     * Strictly rejects aliases and fallbacks.
     *
     * @param  string  $evidencePath  Path to evidence JSON
     * @param  int  $preRunTime  Timestamp before Gradle launch
     * @param  string|null  $preRunSha  SHA-256 before Gradle launch (null if file was absent)
     * @param  string  $expectedBackend  Verified backend HEAD
     * @param  string  $expectedPub  Verified publication HEAD
     * @param  string  $expectedExe  Verified executable HEAD
     * @param  string  $expectedHandoffSha  Verified whole live handoff SHA
     * @return array<string, mixed> Parsed evidence array
     *
     * @throws RuntimeException
     * @param  string  $expectedScope  Expected coordination scope (basic vs advanced)
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public static function verifyEvidenceFreshness(
        string $evidencePath,
        int $preRunTime,
        ?string $preRunSha,
        string $expectedBackend,
        string $expectedPub,
        string $expectedExe,
        string $expectedHandoffSha,
        string $expectedScope = self::REQUIRED_SCOPE
    ): array {
        if (! is_file($evidencePath)) {
            throw new RuntimeException("EVIDENCE_FILE_NOT_FOUND: '{$evidencePath}'");
        }

        $mtime = filemtime($evidencePath);
        if ($mtime < $preRunTime) {
            throw new RuntimeException("EVIDENCE_FILE_STALE: mtime {$mtime} is earlier than test launch {$preRunTime}");
        }

        $raw = (string) file_get_contents($evidencePath);
        $freshSha = hash('sha256', $raw);
        if ($preRunSha !== null && hash_equals($preRunSha, $freshSha)) {
            throw new RuntimeException('EVIDENCE_FILE_UNMODIFIED: SHA-256 unchanged after test execution');
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException('EVIDENCE_FILE_INVALID_JSON');
        }

        // Must reject aliases; require exact Kotlin top-level keys
        if (! isset($data['runId']) || ! is_string($data['runId']) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $data['runId']) !== 1) {
            throw new RuntimeException('EVIDENCE_RUN_ID_MISSING_OR_INVALID: runId must be top-level UUID');
        }

        if (! isset($data['backendHead']) || ! is_string($data['backendHead'])) {
            throw new RuntimeException('EVIDENCE_BACKEND_HEAD_MISSING: backendHead must be top-level');
        }
        if (! hash_equals($expectedBackend, $data['backendHead'])) {
            throw new RuntimeException("EVIDENCE_BACKEND_HEAD_MISMATCH: expected {$expectedBackend}, got {$data['backendHead']}");
        }

        if (! isset($data['clientPublicationHead']) || ! is_string($data['clientPublicationHead'])) {
            throw new RuntimeException('EVIDENCE_PUB_HEAD_MISSING: clientPublicationHead must be top-level');
        }
        if (! hash_equals($expectedPub, $data['clientPublicationHead'])) {
            throw new RuntimeException("EVIDENCE_PUB_HEAD_MISMATCH: expected {$expectedPub}, got {$data['clientPublicationHead']}");
        }

        if (! isset($data['clientExecutableHead']) || ! is_string($data['clientExecutableHead'])) {
            throw new RuntimeException('EVIDENCE_EXE_HEAD_MISSING: clientExecutableHead must be top-level');
        }
        if (! hash_equals($expectedExe, $data['clientExecutableHead'])) {
            throw new RuntimeException("EVIDENCE_EXE_HEAD_MISMATCH: expected {$expectedExe}, got {$data['clientExecutableHead']}");
        }

        if (! isset($data['manifestDigest']) || ! is_string($data['manifestDigest'])) {
            throw new RuntimeException('EVIDENCE_MANIFEST_DIGEST_MISSING: manifestDigest must be top-level');
        }
        if (! hash_equals($expectedHandoffSha, $data['manifestDigest'])) {
            throw new RuntimeException("EVIDENCE_HANDOFF_SHA_MISMATCH: expected {$expectedHandoffSha}, got {$data['manifestDigest']}");
        }

        if (($data['overallStatus'] ?? '') !== 'PASS') {
            throw new RuntimeException("EVIDENCE_OVERALL_STATUS_NOT_PASS: got '" . ($data['overallStatus'] ?? '') . "'");
        }

        if (($data['lifecycleGrant'] ?? '') !== self::REQUIRED_LIFECYCLE_GRANT) {
            throw new RuntimeException("EVIDENCE_LIFECYCLE_GRANT_MISMATCH: expected " . self::REQUIRED_LIFECYCLE_GRANT . ", got '" . ($data['lifecycleGrant'] ?? '') . "'");
        }

        // Validate scenarios: sequential array list of exactly 7 items
        $scenarios = $data['scenarios'] ?? null;
        if (! is_array($scenarios) || ! array_is_list($scenarios) || count($scenarios) !== 7) {
            throw new RuntimeException('EVIDENCE_SCENARIOS_INVALID_COUNT: scenarios must be list of exactly 7 items');
        }

        $scenarioMap = [];
        foreach ($scenarios as $s) {
            if (! is_array($s) || ! isset($s['name']) || ! is_string($s['name'])) {
                throw new RuntimeException('EVIDENCE_SCENARIO_MALFORMED');
            }
            $scenarioMap[$s['name']] = $s;
        }

        if ($expectedScope === self::REQUIRED_ADVANCED_SCOPE) {
            $expectedScenarios = [
                'readiness_probe' => 200,
                'transport_lost_ack_retained_status_recovery' => 200,
                'idempotent_replay_integrity' => 200,
                'tamper_rejection_integrity' => 409,
                'rollback_permission_denial' => 403,
                'rollback_insufficient_stock' => 422,
                'rollback_controlled_after_posting' => 500,
            ];

            foreach ($expectedScenarios as $name => $expectedCode) {
                if (! isset($scenarioMap[$name])) {
                    throw new RuntimeException("EVIDENCE_SCENARIO_MISSING: {$name}");
                }
                if (($scenarioMap[$name]['status'] ?? '') !== 'PASS' || ($scenarioMap[$name]['httpStatusCode'] ?? null) !== $expectedCode) {
                    throw new RuntimeException("EVIDENCE_SCENARIO_ACTIVE_FAILED: {$name}");
                }
            }

            // Validate lost_ack_recovery_evidence block
            $lostAck = $data['lost_ack_recovery_evidence'] ?? null;
            if (! is_array($lostAck)) {
                throw new RuntimeException('EVIDENCE_LOST_ACK_BLOCK_MISSING');
            }
            if (($lostAck['suppression_mode'] ?? '') !== 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP') {
                throw new RuntimeException("EVIDENCE_LOST_ACK_INVALID_SUPPRESSION_MODE: got '" . ($lostAck['suppression_mode'] ?? '') . "'");
            }
            if (($lostAck['network_witness_state'] ?? '') !== 'CAPTURED_REAL_TRANSPORT_DROP') {
                throw new RuntimeException("EVIDENCE_LOST_ACK_INVALID_WITNESS_STATE: got '" . ($lostAck['network_witness_state'] ?? '') . "'");
            }
        } else {
            // Frozen basic exact (5 PASS, 2 NOT_RUN)
            $activeScenarios = [
                'readiness_probe' => 200,
                'financial_cash_fresh_commit_201' => 201,
                'financial_cash_idempotent_replay_200' => 200,
                'financial_cash_event_status_posted' => 200,
                'financial_cash_tamper_409' => 409,
            ];
            $notRunScenarios = [
                'transport_lost_ack_recovery' => null,
                'stock_permission_rollback_probes' => null,
            ];

            foreach ($activeScenarios as $name => $expectedCode) {
                if (! isset($scenarioMap[$name])) {
                    throw new RuntimeException("EVIDENCE_SCENARIO_MISSING: {$name}");
                }
                if (($scenarioMap[$name]['status'] ?? '') !== 'PASS' || ($scenarioMap[$name]['httpStatusCode'] ?? null) !== $expectedCode) {
                    throw new RuntimeException("EVIDENCE_SCENARIO_ACTIVE_FAILED: {$name}");
                }
            }

            foreach ($notRunScenarios as $name => $expectedCode) {
                if (! isset($scenarioMap[$name])) {
                    throw new RuntimeException("EVIDENCE_SCENARIO_MISSING: {$name}");
                }
                if (($scenarioMap[$name]['status'] ?? '') !== 'NOT_RUN' || ($scenarioMap[$name]['httpStatusCode'] ?? null) !== null) {
                    throw new RuntimeException("EVIDENCE_SCENARIO_NOT_RUN_FAILED: {$name}");
                }
            }
        }

        return $data;
    }

    /**
     * Parses JUnit XML test report and enforces 0 FAIL, 0 ERROR, 0 SKIP.
     *
     * @param  string  $xmlPath  Path to JUnit XML file
     * @param  int  $preRunTime  Timestamp before Gradle launch
     * @param  int  $minTests    Minimum required tests (default 2 for basic, 1 for advanced)
     * @return array{tests: int, failures: int, errors: int, skipped: int}
     *
     * @throws RuntimeException
     */
    public static function verifyJunitTestResults(string $xmlPath, int $preRunTime, int $minTests = 2): array
    {
        if (! is_file($xmlPath)) {
            throw new RuntimeException("JUNIT_XML_NOT_FOUND: '{$xmlPath}'");
        }

        if (filemtime($xmlPath) < $preRunTime) {
            throw new RuntimeException('JUNIT_XML_STALE: mtime is earlier than test launch');
        }

        $rawXml = (string) file_get_contents($xmlPath);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($rawXml);
        if ($xml === false) {
            throw new RuntimeException('JUNIT_XML_PARSE_ERROR');
        }

        $tests = 0;
        $failures = 0;
        $errors = 0;
        $skipped = 0;

        if ($xml->getName() === 'testsuite') {
            $tests = (int) $xml['tests'];
            $failures = (int) $xml['failures'];
            $errors = (int) $xml['errors'];
            $skipped = (int) ($xml['skipped'] ?? 0);
        } elseif ($xml->getName() === 'testsuites') {
            foreach ($xml->testsuite as $ts) {
                $tests += (int) $ts['tests'];
                $failures += (int) $ts['failures'];
                $errors += (int) $ts['errors'];
                $skipped += (int) ($ts['skipped'] ?? 0);
            }
        }

        if ($tests < $minTests) {
            throw new RuntimeException("JUNIT_XML_INSUFFICIENT_TESTS: expected at least {$minTests}, got {$tests}");
        }
        if ($failures > 0) {
            throw new RuntimeException("JUNIT_XML_FAILURES_DETECTED: {$failures} failed tests");
        }
        if ($errors > 0) {
            throw new RuntimeException("JUNIT_XML_ERRORS_DETECTED: {$errors} test errors");
        }
        if ($skipped > 0) {
            throw new RuntimeException("JUNIT_XML_SKIPS_DETECTED: {$skipped} tests skipped (0 allowed)");
        }

        return [
            'tests' => $tests,
            'failures' => $failures,
            'errors' => $errors,
            'skipped' => $skipped,
        ];
    }

    /**
     * Preserves any available partial client evidence or JUnit XML test reports
     * in a dedicated private partial/ diagnostics directory upon drill completion or failure.
     * All preserved artifacts are explicitly indexed with their source path, sha256, mtime,
     * and labeled 'unvalidated: true'. Qualified canonical filenames are NEVER overwritten.
     *
     * @param  string  $outputDir  Private output directory
     * @param  string|null  $evidenceFile  Path to candidate client evidence JSON
     * @param  string|null  $targetAndroidDir  Path to Android repository checkout
     * @return array{partial_dir: ?string, evidence_preserved: bool, xml_count: int, index_path: ?string}
     */
    public static function preserveAvailablePartialFailureEvidence(
        string $outputDir,
        ?string $evidenceFile = null,
        ?string $targetAndroidDir = null
    ): array {
        $result = [
            'partial_dir' => null,
            'evidence_preserved' => false,
            'xml_count' => 0,
            'index_path' => null,
        ];

        if (! is_dir($outputDir)) {
            return $result;
        }

        $partialDir = $outputDir . '/partial_failure_diagnostics';
        if (! is_dir($partialDir)) {
            if (! @mkdir($partialDir, 0700, true)) {
                return $result;
            }
        }
        @chmod($partialDir, 0700);
        $result['partial_dir'] = $partialDir;

        $index = [
            'contract_name' => 'Mobile V2 Partial Failure Diagnostic Index',
            'retained_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'unvalidated_warning' => 'These artifacts are partial failure diagnostics from an aborted/failed drill. They are NOT qualified canonical deliverables.',
            'artifacts' => [],
        ];

        // 1. Partial client evidence JSON
        if ($evidenceFile !== null && is_file($evidenceFile)) {
            $destEvidence = $partialDir . '/partial_client_evidence.json';
            if (@copy($evidenceFile, $destEvidence)) {
                @chmod($destEvidence, 0600);
                $result['evidence_preserved'] = true;
                $index['artifacts']['partial_client_evidence.json'] = [
                    'source_path' => $evidenceFile,
                    'sha256' => hash_file('sha256', $destEvidence),
                    'bytes' => filesize($destEvidence),
                    'mtime_utc' => gmdate('Y-m-d\TH:i:s\Z', filemtime($evidenceFile)),
                    'unvalidated' => true,
                ];
            }
        }

        // 2. Retain ALL available XML reports under partial/
        if ($targetAndroidDir !== null && is_dir($targetAndroidDir)) {
            $possibleXmlDirs = [
                $targetAndroidDir . '/app/build/test-results/testDebugUnitTest',
                $targetAndroidDir . '/build/test-results/testDebugUnitTest',
            ];
            foreach ($possibleXmlDirs as $d) {
                if (is_dir($d)) {
                    $files = glob($d . '/*.xml');
                    if (! empty($files)) {
                        foreach ($files as $f) {
                            if (is_file($f)) {
                                $destXml = $partialDir . '/' . basename($f);
                                if (@copy($f, $destXml)) {
                                    @chmod($destXml, 0600);
                                    $result['xml_count']++;
                                    $index['artifacts'][basename($f)] = [
                                        'source_path' => $f,
                                        'sha256' => hash_file('sha256', $destXml),
                                        'bytes' => filesize($destXml),
                                        'mtime_utc' => gmdate('Y-m-d\TH:i:s\Z', filemtime($f)),
                                        'unvalidated' => true,
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Write index file
        $indexPath = $partialDir . '/partial_failure_index.json';
        $encoded = json_encode($index, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($indexPath, $encoded) !== false) {
            @chmod($indexPath, 0600);
            $result['index_path'] = $indexPath;
        }

        return $result;
    }

    /**
     * Checks remaining child deadline. Fails closed if expired or below safe minimum.
     *
     * @param  float  $deadline  Deadline timestamp in float seconds
     * @param  float  $minimumRequired  Minimum required remaining seconds
     * @return float Remaining seconds
     *
     * @throws RuntimeException
     */
    public static function computeRemainingDeadline(float $deadline, float $minimumRequired = 10.0): float
    {
        $remaining = $deadline - microtime(true);
        if ($remaining < $minimumRequired) {
            throw new RuntimeException(sprintf('CHILD_HOLD_DEADLINE_EXCEEDED: %.2fs remaining < %.2fs required', $remaining, $minimumRequired));
        }

        return $remaining;
    }

    /**
     * Performs strict TLS GET 200 check with test CA cert to readiness endpoint.
     * Enforces explicit allowlist for readiness URL (loopback 127.0.0.1:18443 only).
     *
     * @param  string  $readinessUrl  Readiness URL on 127.0.0.1
     * @param  string  $caCertPath  Test CA certificate path
     * @param  string  $expectedNonce  Readiness nonce
     *
     * @throws RuntimeException
     */
    public static function performStrictTlsReadinessCheck(
        string $readinessUrl,
        string $caCertPath,
        string $expectedNonce
    ): void {
        if ($readinessUrl !== self::ALLOWED_READINESS_URL) {
            throw new RuntimeException("READINESS_URL_NOT_ALLOWED: expected '" . self::ALLOWED_READINESS_URL . "', got '{$readinessUrl}'");
        }

        $ch = curl_init($readinessUrl);
        if ($ch === false) {
            throw new RuntimeException('CURL_INIT_FAILED');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CAINFO => $caCertPath,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 5,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new RuntimeException("STRICT_TLS_PROBE_ERROR: {$curlError}");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("STRICT_TLS_PROBE_FAILED: HTTP {$httpCode}");
        }

        $decoded = json_decode((string) $body, true);
        if (! is_array($decoded) || ($decoded['nonce'] ?? '') !== $expectedNonce) {
            throw new RuntimeException('STRICT_TLS_NONCE_MISMATCH');
        }
    }

    /**
     * Asserts private 0600 file permissions with 0700 parent and current UID.
     *
     * @param  string  $path  File path
     * @param  string  $context  Context name for error message
     *
     * @throws RuntimeException
     */
    public static function assertSecureFilePermissions(string $path, string $context): void
    {
        if (is_link($path)) {
            throw new RuntimeException("{$context}_SYMLINK_REJECTED: '{$path}'");
        }
        if (! is_file($path)) {
            throw new RuntimeException("{$context}_FILE_NOT_FOUND: '{$path}'");
        }

        $parent = dirname($path);
        if (is_link($parent)) {
            throw new RuntimeException("{$context}_PARENT_SYMLINK_REJECTED: '{$parent}'");
        }

        $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();

        $filePerms = fileperms($path) & 0777;
        if ($filePerms !== 0600) {
            throw new RuntimeException(sprintf("{$context}_INSECURE_PERMS: expected 0600, got %04o", $filePerms));
        }

        $fileOwner = fileowner($path);
        if ($fileOwner !== $currentUid) {
            throw new RuntimeException("{$context}_OWNER_MISMATCH");
        }

        $parentPerms = fileperms($parent) & 0777;
        if ($parentPerms !== 0700) {
            throw new RuntimeException(sprintf("{$context}_PARENT_INSECURE_PERMS: expected 0700, got %04o", $parentPerms));
        }

        $parentOwner = fileowner($parent);
        if ($parentOwner !== $currentUid) {
            throw new RuntimeException("{$context}_PARENT_OWNER_MISMATCH");
        }
    }

    /**
     * Asserts private 0700 directory permissions with current UID.
     *
     * @param  string  $path  Directory path
     * @param  string  $context  Context name
     *
     * @throws RuntimeException
     */
    public static function assertSecureDirectoryPermissions(string $path, string $context): void
    {
        if (is_link($path)) {
            throw new RuntimeException("{$context}_SYMLINK_REJECTED: '{$path}'");
        }
        if (! is_dir($path)) {
            throw new RuntimeException("{$context}_DIR_NOT_FOUND: '{$path}'");
        }

        $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
        $perms = fileperms($path) & 0777;
        if ($perms !== 0700) {
            throw new RuntimeException(sprintf("{$context}_INSECURE_PERMS: expected 0700, got %04o", $perms));
        }
        if (fileowner($path) !== $currentUid) {
            throw new RuntimeException("{$context}_OWNER_MISMATCH");
        }
    }

    /**
     * Asserts that a file path is securely contained directly within an expected owned directory,
     * with exact expected basename, no symlinks anywhere in its path hierarchy, mode 0600 owned by current UID,
     * and parent directory mode 0700 owned by current UID.
     *
     * @param string $filePath Full path to target file
     * @param string $expectedParentDir Expected containing directory path
     * @param string $expectedBasename Expected filename
     * @param string $context Error context identifier
     *
     * @throws RuntimeException
     */
    public static function assertSecureContainedFile(
        string $filePath,
        string $expectedParentDir,
        string $expectedBasename,
        string $context
    ): void {
        if (is_link($filePath)) {
            throw new RuntimeException("{$context}_SYMLINK_REJECTED: '{$filePath}'");
        }
        if (! is_file($filePath)) {
            throw new RuntimeException("{$context}_FILE_NOT_FOUND: '{$filePath}'");
        }
        if (basename($filePath) !== $expectedBasename) {
            throw new RuntimeException("{$context}_BASENAME_MISMATCH: expected '{$expectedBasename}', got '" . basename($filePath) . "'");
        }

        $parent = dirname($filePath);
        if (is_link($parent)) {
            throw new RuntimeException("{$context}_PARENT_SYMLINK_REJECTED: '{$parent}'");
        }
        if (! is_dir($parent)) {
            throw new RuntimeException("{$context}_PARENT_DIR_NOT_FOUND: '{$parent}'");
        }

        $realParent = realpath($parent);
        $realExpected = realpath($expectedParentDir);
        if ($realParent === false || $realExpected === false || $realParent !== $realExpected) {
            throw new RuntimeException("{$context}_CONTAINMENT_VIOLATION: file parent '{$parent}' does not match expected directory '{$expectedParentDir}'");
        }

        // Validate no path components are symlinks
        $normalized = str_replace('\\', '/', $filePath);
        $parts = explode('/', trim($normalized, '/'));
        $accum = (str_starts_with($normalized, '/')) ? '/' : '';
        foreach ($parts as $part) {
            if ($part === '..' || $part === '.') {
                throw new RuntimeException("{$context}_TRAVERSAL_REJECTED: path contains traversal segment '{$part}'");
            }
            $accum = rtrim($accum, '/') . '/' . $part;
            if (is_link($accum)) {
                throw new RuntimeException("{$context}_PATH_COMPONENT_SYMLINK_REJECTED: '{$accum}'");
            }
        }

        $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();

        $filePerms = fileperms($filePath) & 0777;
        if ($filePerms !== 0600) {
            throw new RuntimeException(sprintf("{$context}_INSECURE_PERMS: expected 0600, got %04o", $filePerms));
        }
        if (fileowner($filePath) !== $currentUid) {
            throw new RuntimeException("{$context}_OWNER_MISMATCH");
        }

        $parentPerms = fileperms($parent) & 0777;
        if ($parentPerms !== 0700) {
            throw new RuntimeException(sprintf("{$context}_PARENT_INSECURE_PERMS: expected 0700, got %04o", $parentPerms));
        }
        if (fileowner($parent) !== $currentUid) {
            throw new RuntimeException("{$context}_PARENT_OWNER_MISMATCH");
        }
    }

    /**
     * Asserts that a specified directory path is not an active/protected development worktree lane.
     * Rejects any path matching protected patterns (e.g. t3-7ac8b73d / *7ac8*).
     *
     * @param string $path Target worktree directory path
     * @throws RuntimeException
     */
    public static function assertNotProtectedWorktree(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        if (str_contains($normalized, '7ac8') || str_contains($normalized, 't3-7ac8b73d')) {
            throw new RuntimeException("PROTECTED_WORKTREE_REFUSED: Active development lane '{$path}' must not be targeted for paired drills. An isolated, Root-approved worktree is required.");
        }
        $real = realpath($path);
        if ($real !== false) {
            $realNorm = str_replace('\\', '/', $real);
            if (str_contains($realNorm, '7ac8') || str_contains($realNorm, 't3-7ac8b73d')) {
                throw new RuntimeException("PROTECTED_WORKTREE_REFUSED: Canonical target '{$real}' is a protected active development lane.");
            }
        }
    }

    /**
     * Snapshots environment variables across all three stores: getenv, $_ENV, and $_SERVER.
     *
     * @param  list<string>  $varNames
     * @return array<string, array{getenv: string|false, ENV: string|null, SERVER: string|null}>
     */
    public static function snapshotEnvironmentStores(array $varNames): array
    {
        $snapshot = [];
        foreach ($varNames as $varName) {
            $snapshot[$varName] = [
                'getenv' => getenv($varName),
                'ENV'    => $_ENV[$varName]    ?? null,
                'SERVER' => $_SERVER[$varName] ?? null,
            ];
        }

        return $snapshot;
    }

    /**
     * Restores environment variables across all three stores: getenv/putenv, $_ENV, and $_SERVER.
     *
     * @param  array<string, array{getenv: string|false, ENV: string|null, SERVER: string|null}>  $savedStores
     */
    public static function restoreEnvironmentStores(array $savedStores): void
    {
        foreach ($savedStores as $varName => $stores) {
            $origGetenv = $stores['getenv'] ?? false;
            if ($origGetenv === false) {
                putenv($varName);
            } else {
                putenv("{$varName}={$origGetenv}");
            }

            if (($stores['ENV'] ?? null) === null) {
                unset($_ENV[$varName]);
            } else {
                $_ENV[$varName] = $stores['ENV'];
            }

            if (($stores['SERVER'] ?? null) === null) {
                unset($_SERVER[$varName]);
            } else {
                $_SERVER[$varName] = $stores['SERVER'];
            }
        }
    }

    /**
     * Executes structured cleanup while scoped credentials and testing flags remain in environment,
     * and guarantees unconditional 3-store environment restoration in finally even if cleanup throws.
     *
     * @param  Process|null  $childProcess  Captured child process
     * @param  VerifiedOwnedSnapshot|null  $snapshot  Captured owned execution snapshot
     * @param  string  $repoRoot  Checkout repository root
     * @param  array<string, array{getenv: string|false, ENV: string|null, SERVER: string|null}>  $parentEnvToRestore  Captured 3-store env
     * @param  PDO|null  $adminPdo  Optional pre-connected admin PDO (or mock for unit testing)
     * @return array{
     *     child_reaped: bool,
     *     schema_absent: bool,
     *     listeners_closed: bool,
     *     temp_cleaned: bool,
     *     success: bool,
     *     errors: string[]
     * }
     */
    public static function executeCleanupAndRestoreEnv(
        ?Process $childProcess,
        ?VerifiedOwnedSnapshot $snapshot,
        string $repoRoot,
        array $parentEnvToRestore,
        ?PDO $adminPdo = null
    ): array {
        try {
            return self::executeExactCleanup(
                $childProcess,
                $snapshot,
                $repoRoot,
                $adminPdo
            );
        } finally {
            self::restoreEnvironmentStores($parentEnvToRestore);
        }
    }

    /**
     * Performs exact structured cleanup:
     * - Reaps child CLI process (SIGTERM then SIGKILL with actual wait loop).
     * - Requires prevalidated owned snapshot before executing ANY destructive database drop or file removal.
     * - If snapshot is missing on partial setup, skips destructive actions and reports UNPROVEN.
     * - Validates DB server port strictly 3342 before issuing SQL.
     * - Drops verified schema and queries exact absence afterwards.
     * - Verifies listener absence on 18080, 18081, and 18443.
     * - Recursively removes owned harness temporary directory and asserts absence.
     * Returns structured result array; fails if any check is unsatisfied.
     *
     * @param  Process|null  $childProcess  Child harness process
     * @param  VerifiedOwnedSnapshot|null  $snapshot  Prevalidated owned snapshot (typed; fabricated arrays refused)
     * @param  string  $repoRoot  Repository root path
     * @param  PDO|null  $adminPdo  Optional admin PDO for testing seam (must still pass port 3342 identity)
     * @return array{
     *     child_reaped: bool,
     *     schema_absent: bool,
     *     listeners_closed: bool,
     *     temp_cleaned: bool,
     *     success: bool,
     *     errors: string[]
     * }
     */
    public static function executeExactCleanup(
        ?Process $childProcess,
        ?VerifiedOwnedSnapshot $snapshot,
        string $repoRoot,
        ?PDO $adminPdo = null
    ): array {
        $errors = [];
        $childReaped = true;
        $schemaAbsent = true;
        $listenersClosed = true;
        $tempCleaned = true;

        // 1. Terminate and reap child process
        // PID bind check: verify the live process PID matches the captured snapshot PID BEFORE any signal.
        // This prevents sending SIGTERM/SIGKILL to a recycled PID that was never the captured child.
        if ($childProcess !== null && $childProcess->isRunning()) {
            if ($snapshot !== null) {
                $livePid = $childProcess->getPid();
                if ($livePid !== $snapshot->childPid) {
                    $errors[] = "CLEANUP_PID_MISMATCH: live process PID {$livePid} does not match captured snapshot PID {$snapshot->childPid}. No signals sent.";
                    $childReaped = false;
                } else {
                    $startIdMatches = true;
                    if ($snapshot->childStartIdentity !== null) {
                        // Double verification before SIGTERM: check UID, PPID, start ticks, and harness CLI script path
                        $checkTerm = self::verifyProcessOwnership(
                            pid: $livePid,
                            expectedPpid: getmypid(),
                            expectedStartTicks: $snapshot->childStartIdentity,
                            expectedPathSubstring: 'mobile-v2-harness-cli.php'
                        );
                        if (! ($checkTerm['valid'] ?? false)) {
                            $errors[] = "CLEANUP_PID_START_IDENTITY_MISMATCH: live process PID {$livePid} identity check failed ('" . ($checkTerm['error'] ?? 'unverified') . "'). No signals sent. Manual remediation required.";
                            $childReaped = false;
                            $startIdMatches = false;
                        }
                    }

                    if ($startIdMatches) {
                        fwrite(STDERR, "[Cleanup] Sending SIGTERM to child process (PID {$livePid}, matches snapshot)...\n");
                        self::sendVerifiedSignal(
                            pid: $livePid,
                            signal: SIGTERM,
                            expectedPpid: getmypid(),
                            expectedStartTicks: (string) $snapshot->childStartIdentity,
                            expectedPathSubstring: 'mobile-v2-harness-cli.php'
                        );

                        $waitStart = hrtime(true) / 1e9;
                        while ($childProcess->isRunning() && ((hrtime(true) / 1e9) - $waitStart) < 5.0) {
                            usleep(100000);
                        }

                        if ($childProcess->isRunning()) {
                            // Re-verify before SIGKILL:
                            if ($snapshot->childStartIdentity !== null) {
                                $checkKill = self::verifyProcessOwnership(
                                    pid: $livePid,
                                    expectedPpid: getmypid(),
                                    expectedStartTicks: $snapshot->childStartIdentity,
                                    expectedPathSubstring: 'mobile-v2-harness-cli.php'
                                );
                                if (! ($checkKill['valid'] ?? false)) {
                                    $errors[] = "CLEANUP_PID_START_IDENTITY_MISMATCH: live process PID {$livePid} identity changed before SIGKILL ('" . ($checkKill['error'] ?? 'unverified') . "'). No signals sent. Manual remediation required.";
                                    $startIdMatches = false;
                                }
                            }

                            if ($startIdMatches) {
                                fwrite(STDERR, "[Cleanup] Child process still running after 5s; sending SIGKILL.\n");
                                self::sendVerifiedSignal(
                                    pid: $livePid,
                                    signal: SIGKILL,
                                    expectedPpid: getmypid(),
                                    expectedStartTicks: (string) $snapshot->childStartIdentity,
                                    expectedPathSubstring: 'mobile-v2-harness-cli.php'
                                );
                                $killStart = hrtime(true) / 1e9;
                                while ($childProcess->isRunning() && ((hrtime(true) / 1e9) - $killStart) < 2.0) {
                                    usleep(100000);
                                }
                            }
                        }

                        if ($childProcess->isRunning()) {
                            $childReaped = false;
                            $errors[] = 'CHILD_PROCESS_NOT_REAPED';
                        }
                    }
                }
            } else {
                // No snapshot: cannot verify PID, do not send signals to an unverified process.
                $errors[] = 'CLEANUP_CHILD_SKIP_NO_SNAPSHOT: child process running but no snapshot for PID verification; no signals sent.';
                $childReaped = false;
            }
        } elseif ($childProcess === null && $snapshot !== null && self::isProcessAlive($snapshot->childPid)) {
            // Fail-closed invariant: if an owned child process is still alive but no Process handle
            // was provided to supervise/reap it, refuse child_reaped = false.
            // Destructive schema and directory cleanup MUST be skipped to prevent deleting resources
            // out from underneath an active running process.
            $childReaped = false;
            $errors[] = "CLEANUP_OWNED_CHILD_ALIVE_WITHOUT_PROCESS_HANDLE: captured child PID {$snapshot->childPid} is still alive but no Process handle was provided. Destructive cleanup skipped. Manual remediation required.";
        }

        // 1b. Terminate and reap tracked child subprocesses if still alive (backend, proxy, caddy)
        if ($snapshot !== null && $snapshot->backendPid !== null) {
            $trackedChildren = [
                'proxy' => ['pid' => $snapshot->proxyPid, 'startId' => $snapshot->proxyStartIdentity],
                'backend' => ['pid' => $snapshot->backendPid, 'startId' => $snapshot->backendStartIdentity],
                'caddy' => ['pid' => $snapshot->caddyPid, 'startId' => $snapshot->caddyStartIdentity],
            ];

            foreach ($trackedChildren as $name => $info) {
                $pid = $info['pid'];
                $expectedStartId = $info['startId'];
                if ($pid === null || $pid <= 1 || $expectedStartId === null) {
                    continue;
                }

                if (! self::isProcessAlive($pid)) {
                    continue;
                }

                // Double verification before SIGTERM: UID, start ticks, harness path.
                // allowOrphanAdoptedByInit: true because when child harness CLI terminates,
                // lingering subprocesses may be adopted by init (PID 1).
                $checkTerm = self::verifyProcessOwnership(
                    pid: $pid,
                    expectedPpid: $snapshot->childPid,
                    expectedStartTicks: $expectedStartId,
                    expectedPathSubstring: $snapshot->harnessDir,
                    allowOrphanAdoptedByInit: true
                );
                if (! ($checkTerm['valid'] ?? false)) {
                    $errors[] = "CLEANUP_{$name}_PID_IDENTITY_MISMATCH: PID {$pid} identity check failed ('" . ($checkTerm['error'] ?? 'unverified') . "'). No signals sent. Manual remediation required.";
                    continue;
                }

                fwrite(STDERR, "[Cleanup] Sending SIGTERM to lingering {$name} process (PID {$pid})...\n");
                self::sendVerifiedSignal(
                    pid: $pid,
                    signal: SIGTERM,
                    expectedPpid: $snapshot->childPid,
                    expectedStartTicks: (string) $expectedStartId,
                    expectedPathSubstring: $snapshot->harnessDir,
                    allowOrphanAdoptedByInit: true
                );
                $wStart = hrtime(true) / 1e9;
                while (self::isProcessAlive($pid) && ((hrtime(true) / 1e9) - $wStart) < 2.0) {
                    usleep(50000);
                }

                if (self::isProcessAlive($pid)) {
                    // Re-verify before SIGKILL:
                    $checkKill = self::verifyProcessOwnership(
                        pid: $pid,
                        expectedPpid: $snapshot->childPid,
                        expectedStartTicks: $expectedStartId,
                        expectedPathSubstring: $snapshot->harnessDir,
                        allowOrphanAdoptedByInit: true
                    );
                    if (! ($checkKill['valid'] ?? false)) {
                        $errors[] = "CLEANUP_{$name}_PID_IDENTITY_MISMATCH: PID {$pid} identity changed before SIGKILL ('" . ($checkKill['error'] ?? 'unverified') . "'). No signals sent. Manual remediation required.";
                        continue;
                    }

                    fwrite(STDERR, "[Cleanup] Lingering {$name} process still alive; sending SIGKILL.\n");
                    self::sendVerifiedSignal(
                        pid: $pid,
                        signal: SIGKILL,
                        expectedPpid: $snapshot->childPid,
                        expectedStartTicks: (string) $expectedStartId,
                        expectedPathSubstring: $snapshot->harnessDir,
                        allowOrphanAdoptedByInit: true
                    );
                    $kStart = hrtime(true) / 1e9;
                    while (self::isProcessAlive($pid) && ((hrtime(true) / 1e9) - $kStart) < 1.0) {
                        usleep(50000);
                    }
                }

                if (self::isProcessAlive($pid)) {
                    $errors[] = "CHILD_{$name}_PROCESS_NOT_REAPED: PID {$pid} failed to terminate.";
                }
            }
        }

        // 2. Destructive Cleanup Gate: Mandatory Prevalidated Owned Snapshot and Confirmed Reaped Children
        if ($snapshot === null) {
            $errors[] = 'CLEANUP_UNPROVEN_NO_VALID_SNAPSHOT: Destructive cleanup skipped; no verified owned snapshot exists. External Root operator inspection required.';

            return [
                'child_reaped' => $childReaped,
                'schema_absent' => false,
                'listeners_closed' => false,
                'temp_cleaned' => false,
                'success' => false,
                'errors' => $errors,
            ];
        }

        if (! $childReaped) {
            $errors[] = 'CLEANUP_DESTRUCTIVE_ACTIONS_SKIPPED_CHILD_ALIVE: Destructive DB and directory removal skipped because child process was not confirmed reaped.';

            return [
                'child_reaped' => false,
                'schema_absent' => false,
                'listeners_closed' => false,
                'temp_cleaned' => false,
                'success' => false,
                'errors' => $errors,
            ];
        }

        // Validate snapshot structure strictly before issuing ANY DB or file deletion.
        // VerifiedOwnedSnapshot guarantees schema regex and harness dir at construction; no re-validation needed.
        $schemaName = $snapshot->schemaName;
        if (preg_match(self::SCHEMA_REGEX, $schemaName) !== 1 || str_contains($schemaName, "\0") || str_contains($schemaName, '`')) {
            $errors[] = "CLEANUP_MALFORMED_SCHEMA_REJECTED: Schema '{$schemaName}' rejected. Zero SQL attempts made.";
            $schemaAbsent = false;
        } else {
            try {
                $pdo = $adminPdo;
                if ($pdo === null) {
                    $settings = DisposableMariaDbSchema::testSettings();
                    $pdo = new PDO(
                        'mysql:host=127.0.0.1;port=3342;charset=utf8mb4',
                        $settings['USERNAME'],
                        $settings['PASSWORD'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                }

                // Assert DB server port identity is strictly 3342 before ANY query
                $portCheck = $pdo->query('SELECT @@port AS port')->fetch(PDO::FETCH_ASSOC);
                $actualPort = (string) ($portCheck['port'] ?? '');
                if ($actualPort !== '3342') {
                    $schemaAbsent = false;
                    $errors[] = "CLEANUP_FOREIGN_DB_PORT_REJECTED: Expected port 3342, got '{$actualPort}'. Zero DROP SQL calls made.";
                } else {
                    $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
                    $stmt->execute([$schemaName]);
                    if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
                        fwrite(STDERR, "[Cleanup] Lingering schema '{$schemaName}' detected; executing admin DROP DATABASE...\n");
                        $pdo->exec("DROP DATABASE IF EXISTS `{$schemaName}`");
                    }

                    // Verify exact schema absence after drop
                    $stmt->execute([$schemaName]);
                    if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
                        $schemaAbsent = false;
                        $errors[] = "SCHEMA_LINGERING_AFTER_DROP: '{$schemaName}'";
                    }
                }
            } catch (\Throwable $e) {
                $schemaAbsent = false;
                $errors[] = 'SCHEMA_CLEANUP_QUERY_FAILED: ' . $e->getMessage();
            }
        }

        // 3. Verify listener absence conditionally:
        // 18080 (backend loopback) and 18443 (caddy TLS) are always verified.
        // 18081 (internal backend port) is verified ONLY for advanced probe execution (when proxy was started).
        $portsToCheck = [18080, 18443];
        if ($snapshot !== null && $snapshot->proxyPid !== null) {
            $portsToCheck[] = 18081;
        }

        foreach ($portsToCheck as $port) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            if (is_resource($fp)) {
                fclose($fp);
                $listenersClosed = false;
                $errors[] = "LISTENER_STILL_ACTIVE_PORT_{$port}: Port is occupied by unverified process. Manual remediation required; unverified processes are never killed.";
            }
        }

        // 4. Clean temporary directory and verify absence
        $harnessDir = $snapshot->harnessDir;

        try {
            self::recursiveRemoveOwnedDir($harnessDir, $repoRoot);
            if (is_dir($harnessDir)) {
                $tempCleaned = false;
                $errors[] = "TEMP_DIR_STILL_EXISTS: '{$harnessDir}'";
            }
        } catch (\Throwable $e) {
            $tempCleaned = false;
            $errors[] = 'TEMP_DIR_CLEANUP_FAILED: ' . $e->getMessage();
        }

        // 5. Clean separate owned p8_proof_ parent directory and verify absence
        $proofCleanup = self::cleanOwnedProofParentDir($snapshot, $repoRoot);
        $proofParentAbsent = $proofCleanup['proof_parent_absent'];
        foreach ($proofCleanup['errors'] as $err) {
            $errors[] = $err;
        }

        $success = $childReaped && $schemaAbsent && $listenersClosed && $tempCleaned && $proofParentAbsent && empty($errors);

        return [
            'child_reaped' => $childReaped,
            'schema_absent' => $schemaAbsent,
            'listeners_closed' => $listenersClosed,
            'temp_cleaned' => $tempCleaned,
            'proof_parent_absent' => $proofParentAbsent,
            'success' => $success,
            'errors' => $errors,
        ];
    }

    /**
     * Clean separate owned proof parent directory after child process termination.
     * Enforces that only snapshot-proven owned proof.json + empty owned parent are removed.
     * If directory is already absent (graceful child removal), reports proof_parent_absent=true.
     * If foreign UID, symlink, extra unproven entries, or unsafe path: fails closed and records error.
     *
     * @param  VerifiedOwnedSnapshot|null  $snapshot
     * @param  string  $repoRoot
     * @return array{proof_parent_absent: bool, errors: list<string>}
     */
    public static function cleanOwnedProofParentDir(?VerifiedOwnedSnapshot $snapshot, string $repoRoot): array
    {
        if ($snapshot === null) {
            return [
                'proof_parent_absent' => false,
                'errors' => ['PROOF_CLEANUP_SKIPPED_NO_SNAPSHOT'],
            ];
        }

        $proofParent = $snapshot->proofParentDir;
        $errors = [];

        // Graceful handling: child may have already removed proof dir during clean shutdown
        if (! file_exists($proofParent) && ! is_dir($proofParent) && ! is_link($proofParent)) {
            return [
                'proof_parent_absent' => true,
                'errors' => [],
            ];
        }

        // Fail-closed checks on proof parent directory
        if (is_link($proofParent)) {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_DIR_SYMLINK_REJECTED: '{$proofParent}'"],
            ];
        }

        if (! is_dir($proofParent)) {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_DIR_NOT_DIRECTORY: '{$proofParent}'"],
            ];
        }

        $baseName = basename($proofParent);
        if (preg_match(self::PROOF_PARENT_DIR_REGEX, $baseName) !== 1) {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_DIR_INVALID_NAME: '{$baseName}' does not match " . self::PROOF_PARENT_DIR_REGEX],
            ];
        }

        $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
        if (fileowner($proofParent) !== $currentUid || fileowner($proofParent) !== $snapshot->proofParentUid) {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_DIR_OWNER_MISMATCH: '{$proofParent}'"],
            ];
        }

        $perms = fileperms($proofParent) & 0777;
        if ($perms !== 0700) {
            return [
                'proof_parent_absent' => false,
                'errors' => [sprintf("PROOF_DIR_INSECURE_PERMS: expected 0700, got %04o", $perms)],
            ];
        }

        $realProofParent = realpath($proofParent);
        $realRoot = realpath($repoRoot);
        $realSysTemp = realpath(sys_get_temp_dir());

        if ($realProofParent === false || $realRoot === false || $realSysTemp === false) {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_DIR_RESOLVE_FAILED: '{$proofParent}'"],
            ];
        }

        if ($realProofParent === '/' || $realProofParent === '/tmp' || $realProofParent === '/home') {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_DIR_UNSAFE_ROOT_REJECTED: '{$realProofParent}'"],
            ];
        }

        $realRootWithSep = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($realProofParent === $realRoot || str_starts_with($realProofParent, $realRootWithSep)) {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_DIR_INSIDE_REPO_REJECTED: '{$realProofParent}'"],
            ];
        }

        $sysTempWithSep = rtrim($realSysTemp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (! str_starts_with($realProofParent, $sysTempWithSep)) {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_DIR_NOT_IN_SYS_TEMP: '{$realProofParent}'"],
            ];
        }

        // Verify inode and device match snapshot (refuse replaced/regenerated parent directory)
        $parentStat = stat($realProofParent);
        if ($parentStat === false) {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_DIR_STAT_FAILED: '{$realProofParent}'"],
            ];
        }
        if ((int) $parentStat['dev'] !== $snapshot->proofParentDev || (int) $parentStat['ino'] !== $snapshot->proofParentIno) {
            return [
                'proof_parent_absent' => false,
                'errors' => [sprintf(
                    "PROOF_DIR_REPLACED_INODE_MISMATCH: '%s' dev/ino changed (expected dev=%d, ino=%d; got dev=%d, ino=%d). Manual remediation required.",
                    $realProofParent,
                    $snapshot->proofParentDev,
                    $snapshot->proofParentIno,
                    (int) $parentStat['dev'],
                    (int) $parentStat['ino']
                )],
            ];
        }

        // Verify entries inside proof parent directory:
        // Must contain ONLY proof.json (or be empty). Any extra entries -> FAIL CLOSED!
        $entries = scandir($realProofParent);
        if ($entries === false) {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_DIR_SCAN_FAILED: '{$realProofParent}'"],
            ];
        }

        $filtered = array_values(array_filter($entries, fn($e) => $e !== '.' && $e !== '..'));

        foreach ($filtered as $entry) {
            if ($entry !== 'proof.json') {
                return [
                    'proof_parent_absent' => false,
                    'errors' => ["PROOF_DIR_EXTRA_ENTRIES_REJECTED: unexpected entry '{$entry}' in '{$realProofParent}'. Manual remediation required."],
                ];
            }
        }

        // Verify snapshot canonical proofPath matches exact expected parent/proof.json
        $expectedProofJsonPath = $realProofParent . DIRECTORY_SEPARATOR . 'proof.json';
        if ($snapshot->proofPath !== $expectedProofJsonPath) {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_SNAPSHOT_PATH_MISMATCH: expected '{$expectedProofJsonPath}', snapshot recorded '{$snapshot->proofPath}'"],
            ];
        }

        // Unlink proven proof.json if present
        $proofJsonPath = $expectedProofJsonPath;
        if (file_exists($proofJsonPath) || is_link($proofJsonPath)) {
            if (is_link($proofJsonPath)) {
                return [
                    'proof_parent_absent' => false,
                    'errors' => ["PROOF_FILE_SYMLINK_REJECTED: '{$proofJsonPath}'"],
                ];
            }
            if (! is_file($proofJsonPath)) {
                return [
                    'proof_parent_absent' => false,
                    'errors' => ["PROOF_FILE_NOT_REGULAR: '{$proofJsonPath}'"],
                ];
            }
            $filePerms = fileperms($proofJsonPath) & 0777;
            if ($filePerms !== 0600) {
                return [
                    'proof_parent_absent' => false,
                    'errors' => [sprintf("PROOF_FILE_INSECURE_PERMS: expected 0600, got %04o in '%s'", $filePerms, $proofJsonPath)],
                ];
            }
            if (fileowner($proofJsonPath) !== $currentUid) {
                return [
                    'proof_parent_absent' => false,
                    'errors' => ["PROOF_FILE_OWNER_MISMATCH: '{$proofJsonPath}'"],
                ];
            }
            // Strict content hash verification against snapshot proofSha256
            $actualSha256 = hash_file('sha256', $proofJsonPath);
            if ($actualSha256 === false || ! hash_equals($snapshot->proofSha256, $actualSha256)) {
                return [
                    'proof_parent_absent' => false,
                    'errors' => [sprintf(
                        "PROOF_FILE_CONTENT_TAMPERED: '%s' SHA-256 does not match snapshot (expected %s, got %s). Files preserved for manual remediation.",
                        $proofJsonPath,
                        $snapshot->proofSha256,
                        is_string($actualSha256) ? $actualSha256 : 'hash_failed'
                    )],
                ];
            }
            if (! @unlink($proofJsonPath)) {
                return [
                    'proof_parent_absent' => false,
                    'errors' => ["PROOF_FILE_UNLINK_FAILED: '{$proofJsonPath}'"],
                ];
            }
        }

        // Remove empty parent directory
        if (! @rmdir($realProofParent)) {
            return [
                'proof_parent_absent' => false,
                'errors' => ["PROOF_DIR_RMDIR_FAILED: '{$realProofParent}'"],
            ];
        }

        $isAbsent = ! file_exists($realProofParent) && ! is_dir($realProofParent);

        return [
            'proof_parent_absent' => $isAbsent,
            'errors' => $isAbsent ? [] : ["PROOF_DIR_STILL_EXISTS: '{$realProofParent}'"],
        ];
    }

    /**
     * Persist restricted hash-pinned POST-cleanup final witness record.
     * Records exact execution pins, schema, validated owned paths, UTC timestamps,
     * outcome, and real observed observations (no assumed true).
     * Preserves proof evidence hash (non-secret SHA-256) rather than secret nonce.
     *
     * @param  string  $outputDir  Private 0700 output directory
     * @param  string  $backendHead  Verified backend execution HEAD SHA
     * @param  string  $androidPubHead  Verified Android publication HEAD SHA
     * @param  string  $androidExeHead  Verified Android executable HEAD SHA
     * @param  string  $manifestSha  Verified pairing manifest SHA-256
     * @param  VerifiedOwnedSnapshot|null  $snapshot  Captured pre-cleanup ownership snapshot
     * @param  array   $cleanupResult  Actual return from executeExactCleanup
     * @param  int     $exitCode  Supervisor exit code
     * @return array{witness_path: string, witness_sha256: string, witness_data: array}
     */
    public static function persistPostCleanupFinalWitness(
        string $outputDir,
        string $backendHead,
        string $androidPubHead,
        string $androidExeHead,
        string $manifestSha,
        ?VerifiedOwnedSnapshot $snapshot,
        array $cleanupResult,
        int $exitCode
    ): array {
        $witnessData = [
            'contract_name' => 'Mobile V2 Post-Cleanup Final Witness',
            'recorded_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'execution_pins' => [
                'backend_execution_head_sha' => $backendHead,
                'peer_android_approved_head_sha' => $androidPubHead,
                'peer_android_executable_head_sha' => $androidExeHead,
                'manifest_sha256' => $manifestSha,
            ],
            'ownership_snapshot' => [
                'schema_name' => $snapshot?->schemaName,
                'child_pid' => $snapshot?->childPid,
                'harness_dir' => $snapshot?->harnessDir,
                'proof_parent_dir' => $snapshot?->proofParentDir,
                'proof_evidence_sha256' => $snapshot?->proofSha256,
                'verified_at' => $snapshot?->verifiedAt,
            ],
            'cleanup_observations' => [
                'child_reaped' => (bool) ($cleanupResult['child_reaped'] ?? false),
                'schema_absent' => (bool) ($cleanupResult['schema_absent'] ?? false),
                'listeners_closed' => (bool) ($cleanupResult['listeners_closed'] ?? false),
                'harness_absent' => (bool) ($cleanupResult['temp_cleaned'] ?? false),
                'proof_parent_absent' => (bool) ($cleanupResult['proof_parent_absent'] ?? false),
                'cleanup_success' => (bool) ($cleanupResult['success'] ?? false),
                'cleanup_errors' => array_values((array) ($cleanupResult['errors'] ?? [])),
            ],
            'supervisor_outcome' => [
                'exit_code' => $exitCode,
                'overall_success' => ($exitCode === 0 && ($cleanupResult['success'] ?? false)),
            ],
        ];

        $witnessPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'post_cleanup_final_witness.json';
        $encoded = json_encode($witnessData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $bytes = @file_put_contents($witnessPath, $encoded);
        if ($bytes === false || $bytes !== strlen($encoded)) {
            throw new RuntimeException("WITNESS_WRITE_FAILED: unable to write '{$witnessPath}'");
        }

        if (! @chmod($witnessPath, 0600) || (fileperms($witnessPath) & 0777) !== 0600) {
            throw new RuntimeException("WITNESS_CHMOD_FAILED: failed to set 0600 permissions on '{$witnessPath}'");
        }

        $witnessSha = @hash_file('sha256', $witnessPath);
        if ($witnessSha === false || preg_match('/^[a-f0-9]{64}$/D', $witnessSha) !== 1) {
            throw new RuntimeException("WITNESS_HASH_FAILED: failed to compute valid sha256 for '{$witnessPath}'");
        }

        return [
            'witness_path' => $witnessPath,
            'witness_sha256' => $witnessSha,
            'witness_data' => $witnessData,
        ];
    }

    /**
     * Recursively remove an owned temporary directory.
     * Enforces that root must be an owned temp descendant, not a symlink, owner is current UID,
     * permissions 0700, and strictly rejects arbitrary /tmp, /, /home, checkouts, NUL bytes, or backticks.
     *
     * Validates the top-level harness directory ONCE against HARNESS_DIR_REGEX and canonical root containment.
     * Descendant files and directories are pre-validated to ensure identical UID ownership and containment within
     * root, then unlinked and removed bottom-up without following symlinks.
     *
     * @param  string  $dir  Directory to delete
     * @param  string  $repoRoot  Checkout repository root
     *
     * @throws RuntimeException
     */
    private static function recursiveRemoveOwnedDir(string $dir, string $repoRoot): void
    {
        if ($dir === '' || str_contains($dir, "\0") || str_contains($dir, '`')) {
            throw new RuntimeException("CLEANUP_UNSAFE_TEMP_PATH_REJECTED: '{$dir}'");
        }

        if (is_link($dir)) {
            throw new RuntimeException("TEMP_DIR_SYMLINK_REJECTED: '{$dir}'");
        }

        if (! is_dir($dir)) {
            return; // Already absent
        }

        $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
        if (fileowner($dir) !== $currentUid) {
            throw new RuntimeException("TEMP_DIR_OWNER_MISMATCH: '{$dir}'");
        }

        $perms = fileperms($dir) & 0777;
        if ($perms !== 0700) {
            throw new RuntimeException(sprintf("CLEANUP_UNSAFE_TEMP_PERMS: expected 0700, got %04o", $perms));
        }

        if (preg_match(self::HARNESS_DIR_REGEX, basename($dir)) !== 1) {
            throw new RuntimeException("CLEANUP_UNSAFE_TEMP_PATH_REJECTED: Basename '" . basename($dir) . "' does not match " . self::HARNESS_DIR_REGEX);
        }

        $realDir = realpath($dir);
        $realRoot = realpath($repoRoot);
        if ($realDir === false || $realRoot === false) {
            throw new RuntimeException("TEMP_DIR_RESOLVE_FAILED: '{$dir}'");
        }

        if ($realDir === '/' || $realDir === '/tmp' || $realDir === '/home') {
            throw new RuntimeException("CLEANUP_UNSAFE_TEMP_PATH_REJECTED: Refusing destructive deletion of root or system directory '{$realDir}'.");
        }

        $realRootWithSep = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($realDir === $realRoot || str_starts_with($realDir, $realRootWithSep)) {
            throw new RuntimeException("CLEANUP_UNSAFE_TEMP_PATH_REJECTED: Refusing destructive deletion inside repository root '{$realDir}'.");
        }

        $realSysTemp = realpath(sys_get_temp_dir());
        if ($realSysTemp === false) {
            throw new RuntimeException("TEMP_DIR_RESOLVE_FAILED: System temp directory cannot be resolved.");
        }
        $sysTempWithSep = rtrim($realSysTemp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (! str_starts_with($realDir, $sysTempWithSep)) {
            throw new RuntimeException("CLEANUP_UNSAFE_TEMP_PATH_REJECTED: Directory '{$realDir}' is not descendant of system temp dir '{$realSysTemp}'.");
        }

        $rootPrefix = rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // 1. Pre-validate full tree before any destructive deletion to prevent partial removal on foreign child
        self::validateDescendantTree($realDir, $rootPrefix, $currentUid);

        // 2. Destructive bottom-up deletion walk constrained to validated root
        self::deleteDescendantTree($realDir);
    }

    /**
     * Validates that all descendant entries under rootPrefix are owned by currentUid and stay contained.
     * Symlinks are checked with lstat (uid match) and never traversed.
     *
     * @throws RuntimeException
     */
    private static function validateDescendantTree(string $currentDir, string $rootPrefix, int $currentUid): void
    {
        $handle = @opendir($currentDir);
        if ($handle === false) {
            throw new RuntimeException("CLEANUP_OPENDIR_FAILED: '{$currentDir}'");
        }

        try {
            while (($entryName = readdir($handle)) !== false) {
                if ($entryName === '.' || $entryName === '..') {
                    continue;
                }
                $entryPath = $currentDir . DIRECTORY_SEPARATOR . $entryName;
                if (! str_starts_with($entryPath, $rootPrefix)) {
                    throw new RuntimeException("CLEANUP_DESCENDANT_ESCAPED_ROOT: '{$entryPath}' is not inside '{$rootPrefix}'");
                }

                $lstat = @lstat($entryPath);
                if ($lstat === false) {
                    throw new RuntimeException("CLEANUP_LSTAT_FAILED: '{$entryPath}'");
                }

                if ($lstat['uid'] !== $currentUid) {
                    throw new RuntimeException("CLEANUP_FOREIGN_CHILD_REJECTED: UID mismatch on '{$entryPath}'");
                }

                // If entry is a symlink, do NOT follow or traverse its target
                if (is_link($entryPath)) {
                    continue;
                }

                if (is_dir($entryPath)) {
                    self::validateDescendantTree($entryPath, $rootPrefix, $currentUid);
                }
            }
        } finally {
            closedir($handle);
        }
    }

    /**
     * Deletes all descendant files and subdirectories bottom-up, then deletes currentDir.
     * Symlinks are unlinked directly without touching their targets.
     *
     * @throws RuntimeException
     */
    private static function deleteDescendantTree(string $currentDir): void
    {
        $handle = @opendir($currentDir);
        if ($handle === false) {
            throw new RuntimeException("CLEANUP_OPENDIR_FAILED: '{$currentDir}'");
        }

        try {
            while (($entryName = readdir($handle)) !== false) {
                if ($entryName === '.' || $entryName === '..') {
                    continue;
                }
                $entryPath = $currentDir . DIRECTORY_SEPARATOR . $entryName;

                // Never follow symlinks; unlinking a symlink only removes the link itself, never target
                if (is_link($entryPath) || is_file($entryPath)) {
                    if (! @unlink($entryPath) && (file_exists($entryPath) || is_link($entryPath))) {
                        throw new RuntimeException("CLEANUP_UNLINK_FAILED: '{$entryPath}'");
                    }
                } elseif (is_dir($entryPath)) {
                    self::deleteDescendantTree($entryPath);
                }
            }
        } finally {
            closedir($handle);
        }

        if (! @rmdir($currentDir) && is_dir($currentDir)) {
            throw new RuntimeException("CLEANUP_RMDIR_FAILED: '{$currentDir}'");
        }
    }
}

/**
 * Immutable owned execution snapshot value object.
 *
 * The ONLY way to obtain an instance is via {@see fromLiveOwnership()}, which performs all
 * validation inline: schema regex, proof file permissions, proof nonce format, harness
 * directory permissions and UID, canonical path resolution, sys_get_temp_dir containment,
 * positive child PID, and DisposableMariaDbSchema::assertPrimarySchema.
 *
 * There is exactly ONE constructor (private) and ONE public factory method.  No escape hatch,
 * no @internal bypass, no test-only seam.  Tests that need to exercise executeExactCleanup()
 * defense logic (malformed schema rejection, foreign DB port, schema lingering) must do so via
 * fromLiveOwnership() with a valid synthetic proof fixture and mocked admin PDO, or must assert
 * that fromLiveOwnership() itself refuses invalid inputs before cleanup is ever reached.
 */
final class VerifiedOwnedSnapshot
{
    /**
     * @param  string  $schemaName      Validated schema name (accounting_p8_tmp_12hex)
     * @param  string  $proofPath       Path to disposable DB ownership proof file (0600, uid-owned)
     * @param  string  $proofNonce      Disposable DB ownership proof nonce (32-64 lowercase hex)
     * @param  string  $proofParentDir  Canonical realpath of owned proof parent dir (p8_proof_16hex in sys_get_temp_dir)
     * @param  string  $proofSha256     SHA-256 of proof.json file content (non-secret evidence hash)
     * @param  int     $proofParentUid  Captured UID of proof parent directory
     * @param  int     $proofParentDev  Captured filesystem device ID of proof parent directory
     * @param  int     $proofParentIno  Captured filesystem inode of proof parent directory
     * @param  string  $harnessDir      Canonical realpath of owned harness dir (v2_harness_16hex in sys_get_temp_dir)
     * @param  int     $childPid        Captured child CLI process PID (positive integer, bound before signals)
     * @param  int     $verifiedAt      Unix timestamp when snapshot was captured and assertPrimarySchema passed
     */
    private function __construct(
        public readonly string $schemaName,
        public readonly string $proofPath,
        public readonly string $proofNonce,
        public readonly string $proofParentDir,
        public readonly string $proofSha256,
        public readonly int $proofParentUid,
        public readonly int $proofParentDev,
        public readonly int $proofParentIno,
        public readonly string $harnessDir,
        public readonly int $childPid,
        public readonly int $verifiedAt,
        public readonly ?int $backendPid = null,
        public readonly ?int $proxyPid = null,
        public readonly ?int $caddyPid = null,
        public readonly ?string $childStartIdentity = null,
        public readonly ?string $backendStartIdentity = null,
        public readonly ?string $proxyStartIdentity = null,
        public readonly ?string $caddyStartIdentity = null,
    ) {}

    /**
     * Sole public validated factory. Performs ALL pre-condition checks inline; there is no other
     * constructor path. Callers must supply an APP_ENV=testing + PHASE8_ALLOW_DISPOSABLE_DB=1
     * environment (via prior putenv) so that assertPrimarySchema does not refuse.
     *
     * Validations performed (in order):
     * 1. Schema name matches /^accounting_p8_tmp_[a-f0-9]{12}$/D and contains no NUL/backtick.
     * 2. childPid is an integer > 1 (PID 1, supervisor own PID, and supervisor parent PID refused).
     * 3. proofPath is a non-symlink 0600 file owned by the current UID under a 0700 parent.
     * 4. proofNonce matches /^[0-9a-f]{32,64}$/.
     * 5. harnessDir is a non-symlink 0700 directory owned by the current UID.
     * 6. basename(harnessDir) matches /^v2_harness_[0-9a-f]{16}$/D and contains no NUL/backtick.
     * 7. realpath(harnessDir) resolves and is not /, /tmp, or /home.
     * 8. realpath(harnessDir) is not inside repoRoot.
     * 9. realpath(harnessDir) is a descendant of sys_get_temp_dir().
     * 10. PHASE8_DISPOSABLE_DB_PROOF/NONCE/ALLOW env vars are saved, set for assertPrimarySchema,
     *     then restored unconditionally in finally (zero net env side-effect).
     * 11. DisposableMariaDbSchema::assertPrimarySchema($schemaName) passes.
     * 12. Proof parent directory name matches /^p8_proof_[0-9a-f]{16}$/D and is a descendant of sys_get_temp_dir().
     * 13. Process ownership and lineage verification against live Linux /proc (or test hook).
     *
     * @param  string  $schemaName  Must match /^accounting_p8_tmp_[a-f0-9]{12}$/D
     * @param  string  $proofPath   Path to disposable DB ownership proof file
     * @param  string  $proofNonce  Ownership proof nonce (32-64 lowercase hex)
     * @param  string  $harnessDir  Owned harness directory path
     * @param  int     $childPid    Positive integer child CLI process PID (> 1)
     * @param  string  $repoRoot    Checkout root directory (for containment check)
     * @param  string|null  $pidsJsonPath  Path to process_pids.json for advanced execution
     *
     * @throws RuntimeException
     */
    public static function fromLiveOwnership(
        string $schemaName,
        string $proofPath,
        string $proofNonce,
        string $harnessDir,
        int $childPid,
        string $repoRoot,
        ?string $pidsJsonPath = null
    ): self {
        // 1. Schema name
        if (preg_match(SupervisedPairedFinancialDrill::SCHEMA_REGEX, $schemaName) !== 1
            || str_contains($schemaName, "\0")
            || str_contains($schemaName, '`')
        ) {
            throw new RuntimeException(
                "SNAPSHOT_INVALID_SCHEMA_NAME: '{$schemaName}' does not match required pattern "
                . SupervisedPairedFinancialDrill::SCHEMA_REGEX
            );
        }

        // 2. Child PID
        if ($childPid <= 1) {
            throw new RuntimeException("SNAPSHOT_INVALID_CHILD_PID: must be positive integer, got {$childPid}");
        }
        if ($childPid === getmypid()) {
            throw new RuntimeException("SNAPSHOT_REFUSED_SUPERVISOR_PID: childPid cannot be current supervisor PID {$childPid}");
        }
        if (function_exists('posix_getppid') && $childPid === posix_getppid()) {
            throw new RuntimeException("SNAPSHOT_REFUSED_PARENT_PID: childPid cannot be parent supervisor PID {$childPid}");
        }

        // 3. Proof file permissions (0600, uid-owned, no symlink, 0700 parent)
        SupervisedPairedFinancialDrill::assertSecureFilePermissions($proofPath, 'SNAPSHOT_PROOF');

        // 4. Proof nonce format
        if (preg_match('/^[0-9a-f]{32,64}$/', $proofNonce) !== 1) {
            throw new RuntimeException('SNAPSHOT_INVALID_PROOF_NONCE');
        }

        // 5. Harness directory permissions (0700, uid-owned, no symlink)
        SupervisedPairedFinancialDrill::assertSecureDirectoryPermissions($harnessDir, 'SNAPSHOT_HARNESS_DIR');

        // 6. Harness directory basename
        $baseName = basename($harnessDir);
        if (preg_match(SupervisedPairedFinancialDrill::HARNESS_DIR_REGEX, $baseName) !== 1
            || str_contains($harnessDir, "\0")
            || str_contains($harnessDir, '`')
        ) {
            throw new RuntimeException(
                "SNAPSHOT_INVALID_HARNESS_DIR_NAME: '{$baseName}' does not match "
                . SupervisedPairedFinancialDrill::HARNESS_DIR_REGEX
            );
        }

        // 7. Canonical path resolution and unsafe root rejection
        $realHarness = realpath($harnessDir);
        $realRoot = realpath($repoRoot);
        if ($realHarness === false || $realRoot === false) {
            throw new RuntimeException('SNAPSHOT_CANONICAL_PATH_RESOLVE_FAILED');
        }
        if ($realHarness === '/' || $realHarness === '/tmp' || $realHarness === '/home') {
            throw new RuntimeException("SNAPSHOT_UNSAFE_HARNESS_DIR_REFUSED: '{$realHarness}'");
        }

        // 8. Harness dir must not be inside repo
        if ($realHarness === $realRoot || str_starts_with($realHarness, $realRoot . '/')) {
            throw new RuntimeException("SNAPSHOT_HARNESS_DIR_INSIDE_REPO_REFUSED: '{$realHarness}'");
        }

        // 9. Harness dir must be under sys_get_temp_dir
        $realSysTemp = realpath(sys_get_temp_dir());
        if ($realSysTemp !== false && ! str_starts_with($realHarness, $realSysTemp)) {
            throw new RuntimeException("SNAPSHOT_HARNESS_DIR_NOT_IN_SYS_TEMP: '{$realHarness}'");
        }

        // 10 + 11. Proof-vars env snapshot, assertPrimarySchema, restore
        // Caller must have set APP_ENV=testing and PHASE8_ALLOW_DISPOSABLE_DB=1 before this call.
        $proofVarNames = ['PHASE8_DISPOSABLE_DB_PROOF', 'PHASE8_DISPOSABLE_DB_NONCE', 'PHASE8_ALLOW_DISPOSABLE_DB'];
        $savedEnv = [];
        foreach ($proofVarNames as $varName) {
            $savedEnv[$varName] = getenv($varName);
        }
        try {
            putenv("PHASE8_DISPOSABLE_DB_PROOF={$proofPath}");
            putenv("PHASE8_DISPOSABLE_DB_NONCE={$proofNonce}");
            putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
            DisposableMariaDbSchema::assertPrimarySchema($schemaName);
        } finally {
            foreach ($savedEnv as $varName => $origVal) {
                if ($origVal === false) {
                    putenv($varName);
                } else {
                    putenv("{$varName}={$origVal}");
                }
            }
        }

        // 12. Validate proof parent directory (canonical path, UID, 0700, p8_proof_16hex regex, sys temp containment)
        $proofParent = dirname($proofPath);
        $realProofParent = realpath($proofParent);
        if ($realProofParent === false) {
            throw new RuntimeException('SNAPSHOT_PROOF_PARENT_RESOLVE_FAILED');
        }

        $proofParentBase = basename($realProofParent);
        if (preg_match(SupervisedPairedFinancialDrill::PROOF_PARENT_DIR_REGEX, $proofParentBase) !== 1
            || str_contains($proofParent, "\0")
            || str_contains($proofParent, '`')
        ) {
            throw new RuntimeException(
                "SNAPSHOT_INVALID_PROOF_PARENT_NAME: '{$proofParentBase}' does not match "
                . SupervisedPairedFinancialDrill::PROOF_PARENT_DIR_REGEX
            );
        }

        if ($realProofParent === '/' || $realProofParent === '/tmp' || $realProofParent === '/home') {
            throw new RuntimeException("SNAPSHOT_UNSAFE_PROOF_PARENT_REFUSED: '{$realProofParent}'");
        }

        $realRootWithSep = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($realProofParent === $realRoot || str_starts_with($realProofParent, $realRootWithSep)) {
            throw new RuntimeException("SNAPSHOT_PROOF_PARENT_INSIDE_REPO_REFUSED: '{$realProofParent}'");
        }

        if ($realSysTemp !== false) {
            $sysTempWithSep = rtrim($realSysTemp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (! str_starts_with($realProofParent, $sysTempWithSep)) {
                throw new RuntimeException("SNAPSHOT_PROOF_PARENT_NOT_IN_SYS_TEMP: '{$realProofParent}'");
            }
        }

        if (basename($proofPath) !== 'proof.json') {
            throw new RuntimeException("SNAPSHOT_INVALID_PROOF_FILE_NAME: expected 'proof.json', got '" . basename($proofPath) . "'");
        }

        $realProofPath = realpath($proofPath);
        if ($realProofPath === false) {
            throw new RuntimeException('SNAPSHOT_PROOF_PATH_RESOLVE_FAILED');
        }

        $expectedProofPath = $realProofParent . DIRECTORY_SEPARATOR . 'proof.json';
        if ($realProofPath !== $expectedProofPath) {
            throw new RuntimeException("SNAPSHOT_PROOF_PATH_MISMATCH: expected '{$expectedProofPath}', got '{$realProofPath}'");
        }

        $proofSha256 = hash_file('sha256', $realProofPath);
        if ($proofSha256 === false) {
            throw new RuntimeException('SNAPSHOT_PROOF_HASH_FAILED');
        }

        $proofParentUid = fileowner($realProofParent);
        $parentStat = stat($realProofParent);
        if ($parentStat === false) {
            throw new RuntimeException('SNAPSHOT_PROOF_PARENT_STAT_FAILED');
        }
        $proofParentDev = (int) $parentStat['dev'];
        $proofParentIno = (int) $parentStat['ino'];

        // 13. Process ownership and lineage verification
        $backendPid = null;
        $proxyPid = null;
        $caddyPid = null;
        $childStartIdentity = null;
        $backendStartIdentity = null;
        $proxyStartIdentity = null;
        $caddyStartIdentity = null;

        if ($pidsJsonPath === null) {
            // Basic snapshot mode: verify child process ownership and lineage against live /proc.
            // If child process is missing from /proc, UID is unknown/mismatched, or identity is invalid:
            // FAIL CLOSED: do not accept snapshot with childStart null. Absent child cannot capture
            // a snapshot for cleanup; explicit manual owned DB cleanup is required if process cannot be proven.
            $childCheck = SupervisedPairedFinancialDrill::verifyProcessOwnership(
                pid: $childPid,
                expectedPpid: getmypid(),
                expectedStartTicks: null,
                expectedPathSubstring: 'mobile-v2-harness-cli.php'
            );
            if (! ($childCheck['valid'] ?? false)) {
                throw new RuntimeException('SNAPSHOT_CHILD_PROCESS_NOT_PROVEN: ' . ($childCheck['error'] ?? 'unverified'));
            }
            $childStartIdentity = $childCheck['start_ticks'];
            if ($childStartIdentity === null || $childStartIdentity === '') {
                throw new RuntimeException('SNAPSHOT_CHILD_PROCESS_START_TICKS_EMPTY');
            }
        } else {
            // Advanced execution mode: process_pids.json is mandatory and strictly verified
            SupervisedPairedFinancialDrill::assertSecureFilePermissions($pidsJsonPath, 'SNAPSHOT_PROCESS_PIDS');
            $realPids = realpath($pidsJsonPath);
            if ($realPids === false || ! str_starts_with($realPids, $realHarness . '/')) {
                throw new RuntimeException("SNAPSHOT_PIDS_PATH_NOT_IN_HARNESS_DIR: '{$pidsJsonPath}'");
            }

            $pidsRaw = json_decode((string) file_get_contents($realPids), true);
            if (! is_array($pidsRaw)) {
                throw new RuntimeException('SNAPSHOT_INVALID_PROCESS_PIDS_JSON');
            }

            foreach (['backend_pid', 'proxy_pid', 'caddy_pid'] as $requiredKey) {
                if (! isset($pidsRaw[$requiredKey]) || ! is_int($pidsRaw[$requiredKey]) || $pidsRaw[$requiredKey] <= 1) {
                    throw new RuntimeException("SNAPSHOT_MISSING_OR_INVALID_REQUIRED_PID: key '{$requiredKey}' must be integer > 1");
                }
            }

            $backendPid = (int) $pidsRaw['backend_pid'];
            $proxyPid = (int) $pidsRaw['proxy_pid'];
            $caddyPid = (int) $pidsRaw['caddy_pid'];

            $allPids = [$childPid, $backendPid, $proxyPid, $caddyPid];
            if (count(array_unique($allPids)) !== 4) {
                throw new RuntimeException('SNAPSHOT_DUPLICATE_PROCESS_PIDS');
            }

            $myPid = getmypid();
            $myParentPid = function_exists('posix_getppid') ? posix_getppid() : -1;
            foreach (['backend' => $backendPid, 'proxy' => $proxyPid, 'caddy' => $caddyPid] as $role => $pid) {
                if ($pid === $myPid) {
                    throw new RuntimeException("SNAPSHOT_REFUSED_SUPERVISOR_PID: {$role} PID {$pid} cannot be supervisor PID");
                }
                if ($pid === $myParentPid) {
                    throw new RuntimeException("SNAPSHOT_REFUSED_PARENT_PID: {$role} PID {$pid} cannot be supervisor parent PID");
                }
            }

            // Verify child process identity and lineage (parent must be supervisor, cmdline must contain script path)
            $childCheck = SupervisedPairedFinancialDrill::verifyProcessOwnership(
                pid: $childPid,
                expectedPpid: getmypid(),
                expectedStartTicks: null,
                expectedPathSubstring: 'mobile-v2-harness-cli.php'
            );
            if (! ($childCheck['valid'] ?? false)) {
                throw new RuntimeException('SNAPSHOT_CHILD_PROCESS_NOT_PROVEN: ' . ($childCheck['error'] ?? 'unverified'));
            }
            $childStartIdentity = $childCheck['start_ticks'];
            if ($childStartIdentity === null || $childStartIdentity === '') {
                throw new RuntimeException('SNAPSHOT_CHILD_PROCESS_START_TICKS_EMPTY');
            }

            // Verify backend process identity and lineage (parent must be child CLI harness)
            $bCheck = SupervisedPairedFinancialDrill::verifyProcessOwnership(
                pid: $backendPid,
                expectedPpid: $childPid,
                expectedStartTicks: null,
                expectedPathSubstring: $realHarness
            );
            if (! ($bCheck['valid'] ?? false)) {
                throw new RuntimeException('SNAPSHOT_BACKEND_PROCESS_NOT_PROVEN: ' . ($bCheck['error'] ?? 'unverified'));
            }
            $backendStartIdentity = $bCheck['start_ticks'];
            if ($backendStartIdentity === null || $backendStartIdentity === '') {
                throw new RuntimeException('SNAPSHOT_BACKEND_PROCESS_START_TICKS_EMPTY');
            }

            // Verify proxy process identity and lineage (parent must be child CLI harness)
            $pCheck = SupervisedPairedFinancialDrill::verifyProcessOwnership(
                pid: $proxyPid,
                expectedPpid: $childPid,
                expectedStartTicks: null,
                expectedPathSubstring: $realHarness
            );
            if (! ($pCheck['valid'] ?? false)) {
                throw new RuntimeException('SNAPSHOT_PROXY_PROCESS_NOT_PROVEN: ' . ($pCheck['error'] ?? 'unverified'));
            }
            $proxyStartIdentity = $pCheck['start_ticks'];
            if ($proxyStartIdentity === null || $proxyStartIdentity === '') {
                throw new RuntimeException('SNAPSHOT_PROXY_PROCESS_START_TICKS_EMPTY');
            }

            // Verify caddy process identity and lineage (parent must be child CLI harness)
            $cCheck = SupervisedPairedFinancialDrill::verifyProcessOwnership(
                pid: $caddyPid,
                expectedPpid: $childPid,
                expectedStartTicks: null,
                expectedPathSubstring: $realHarness
            );
            if (! ($cCheck['valid'] ?? false)) {
                throw new RuntimeException('SNAPSHOT_CADDY_PROCESS_NOT_PROVEN: ' . ($cCheck['error'] ?? 'unverified'));
            }
            $caddyStartIdentity = $cCheck['start_ticks'];
            if ($caddyStartIdentity === null || $caddyStartIdentity === '') {
                throw new RuntimeException('SNAPSHOT_CADDY_PROCESS_START_TICKS_EMPTY');
            }
        }

        return new self(
            schemaName: $schemaName,
            proofPath: $expectedProofPath,
            proofNonce: $proofNonce,
            proofParentDir: $realProofParent,
            proofSha256: $proofSha256,
            proofParentUid: $proofParentUid,
            proofParentDev: $proofParentDev,
            proofParentIno: $proofParentIno,
            harnessDir: $realHarness,
            childPid: $childPid,
            verifiedAt: time(),
            backendPid: $backendPid,
            proxyPid: $proxyPid,
            caddyPid: $caddyPid,
            childStartIdentity: $childStartIdentity,
            backendStartIdentity: $backendStartIdentity,
            proxyStartIdentity: $proxyStartIdentity,
            caddyStartIdentity: $caddyStartIdentity,
        );
    }
}
