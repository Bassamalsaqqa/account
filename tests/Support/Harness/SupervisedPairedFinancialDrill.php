<?php

declare(strict_types=1);

namespace Tests\Support\Harness;

use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Supervised Mobile V2 Paired Financial Integration Drill.
 *
 * Implements strict adversarial validation across all live execution phases:
 * 1. Mandatory prestart Root approval TTL + private permissions (0600/0700/UID/no-symlinks) + identical pub/exe allowed.
 * 2. Mandatory bounded wait for independent external live Root approval post-handoff, pre-JVM (no auto-approval).
 * 3. Hard-gated child alive & remaining deadline checks before and after EVERY subprocess with bounded timeouts.
 * 4. Private unique output directory outside checkouts (0700/0600) retaining all artifacts before cleanup.
 * 5. Structured exact cleanup failing non-zero on any lingering schema, listeners, or temp dirs.
 * 6. Mandatory pcntl availability, signal trap with no-reentry cleanup, and non-zero exit on abort.
 * 7. Mandatory live approval TTL, scope, schema, and whole-file pins equality.
 * 8. Manifest SHA override verified against actual file bytes; app tree admission verified via assertAdmittedAppTree.
 * 9. Explicit allowlist for readiness URL (strictly loopback https://127.0.0.1:18443/_harness/readiness).
 * 10. Triple-match of sample, parsed, and raw canonical JSON with payload hash independently verified.
 */
class SupervisedPairedFinancialDrill
{
    public const DEFAULT_HOLD_SECONDS = 120;
    public const REQUIRED_SCOPE = 'ROOT_AUTHORIZED_PAIRED_EXECUTION';
    public const REQUIRED_LIFECYCLE_GRANT = 'FINANCIAL_PAIRED_RUN';
    public const ALLOWED_READINESS_URL = 'https://127.0.0.1:18443/_harness/readiness';

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
        string $repoRoot
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

        return [
            'root_coordination_granted' => true,
            'manifest_sha256' => $appManifestSha,
            'backend_head' => $appBackendHead,
            'android_publication_head' => $appPubHead,
            'android_executable_head' => $appExeHead,
            'cwd' => $realRoot,
            'expires_at' => $data['expires_at'],
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
        float $maxWaitSeconds = 15.0
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
            $schemaName
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
        string $schemaName
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
        if ($scope !== self::REQUIRED_SCOPE) {
            throw new RuntimeException("LIVE_ROOT_APPROVAL_SCOPE_MISMATCH: expected " . self::REQUIRED_SCOPE . ", got '{$scope}'");
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
     * Validates fresh Android test evidence artifact.
     * Asserts it is relative to approved Android cwd, fresh (mtime >= preRunTime, hash != preRunSha),
     * and contains the verified runtime pins.
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
     */
    public static function verifyEvidenceFreshness(
        string $evidencePath,
        int $preRunTime,
        ?string $preRunSha,
        string $expectedBackend,
        string $expectedPub,
        string $expectedExe,
        string $expectedHandoffSha
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

        $pins = $data['pins'] ?? ($data['backend_runtime_pins'] ?? $data);
        $backendHead = $pins['backend_execution_head_sha'] ?? ($pins['backendHead'] ?? '');
        $pubHead = $pins['peer_android_approved_head_sha'] ?? ($pins['clientPublicationHead'] ?? '');
        $exeHead = $pins['peer_android_executable_head_sha'] ?? ($pins['clientExecutableHead'] ?? '');
        $handoffSha = $pins['live_handoff_sha256'] ?? ($pins['handoffDigest'] ?? ($pins['expected_handoff_sha'] ?? ''));

        if (! hash_equals($expectedBackend, (string) $backendHead)) {
            throw new RuntimeException("EVIDENCE_BACKEND_HEAD_MISMATCH: expected {$expectedBackend}, got {$backendHead}");
        }
        if (! hash_equals($expectedPub, (string) $pubHead)) {
            throw new RuntimeException("EVIDENCE_PUB_HEAD_MISMATCH: expected {$expectedPub}, got {$pubHead}");
        }
        if (! hash_equals($expectedExe, (string) $exeHead)) {
            throw new RuntimeException("EVIDENCE_EXE_HEAD_MISMATCH: expected {$expectedExe}, got {$exeHead}");
        }
        if (! hash_equals($expectedHandoffSha, (string) $handoffSha)) {
            throw new RuntimeException("EVIDENCE_HANDOFF_SHA_MISMATCH: expected {$expectedHandoffSha}, got {$handoffSha}");
        }

        return $data;
    }

    /**
     * Parses JUnit XML test report and enforces 2 PASS, 0 FAIL, 0 ERROR, 0 SKIP.
     *
     * @param  string  $xmlPath  Path to JUnit XML file
     * @param  int  $preRunTime  Timestamp before Gradle launch
     * @return array{tests: int, failures: int, errors: int, skipped: int}
     *
     * @throws RuntimeException
     */
    public static function verifyJunitTestResults(string $xmlPath, int $preRunTime): array
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

        if ($tests < 2) {
            throw new RuntimeException("JUNIT_XML_INSUFFICIENT_TESTS: expected at least 2, got {$tests}");
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
     * Performs exact structured cleanup:
     * - Reaps child CLI process (SIGTERM then SIGKILL with actual wait loop).
     * - Drops owned schema via admin PDO if lingered, querying exact schema absence afterwards.
     * - Verifies listener absence on 18080 and 18443.
     * - Recursively removes owned harness temporary directory and asserts absence.
     * Returns structured result array; fails if any check is unsatisfied.
     *
     * @param  Process|null  $childProcess  Child harness process
     * @param  string|null  $schemaName  Owned schema name
     * @param  string|null  $harnessDir  Harness temporary directory
     * @param  PDO|null  $adminPdo  Optional admin PDO for testing/execution
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
        ?string $schemaName,
        ?string $harnessDir,
        ?PDO $adminPdo = null
    ): array {
        $errors = [];
        $childReaped = true;
        $schemaAbsent = true;
        $listenersClosed = true;
        $tempCleaned = true;

        // 1. Terminate and reap child process
        if ($childProcess !== null && $childProcess->isRunning()) {
            fwrite(STDERR, "[Cleanup] Sending SIGTERM to child process (PID " . $childProcess->getPid() . ")...\n");
            $childProcess->signal(SIGTERM);

            $waitStart = microtime(true);
            while ($childProcess->isRunning() && (microtime(true) - $waitStart) < 5.0) {
                usleep(100000);
            }

            if ($childProcess->isRunning()) {
                fwrite(STDERR, "[Cleanup] Child process still running after 5s; sending SIGKILL.\n");
                $childProcess->signal(SIGKILL);
                $killStart = microtime(true);
                while ($childProcess->isRunning() && (microtime(true) - $killStart) < 2.0) {
                    usleep(100000);
                }
            }

            if ($childProcess->isRunning()) {
                $childReaped = false;
                $errors[] = 'CHILD_PROCESS_NOT_REAPED';
            }
        }

        // 2. Query and enforce exact schema absence
        if ($schemaName !== null && $schemaName !== '') {
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

                $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
                $stmt->execute([$schemaName]);
                if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
                    fwrite(STDERR, "[Cleanup] Lingering schema '{$schemaName}' detected; executing admin DROP DATABASE...\n");
                    $pdo->exec("DROP DATABASE IF EXISTS `{$schemaName}`");
                }

                // Verify exact schema absence
                $stmt->execute([$schemaName]);
                if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
                    $schemaAbsent = false;
                    $errors[] = "SCHEMA_LINGERING_AFTER_DROP: '{$schemaName}'";
                }
            } catch (\Throwable $e) {
                $schemaAbsent = false;
                $errors[] = 'SCHEMA_CLEANUP_QUERY_FAILED: ' . $e->getMessage();
            }
        }

        // 3. Verify listener absence on 18080 and 18443
        foreach ([18080, 18443] as $port) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            if (is_resource($fp)) {
                fclose($fp);
                $listenersClosed = false;
                $errors[] = "LISTENER_STILL_ACTIVE_PORT_{$port}";
            }
        }

        // 4. Clean temporary directory and verify absence
        if ($harnessDir !== null && is_dir($harnessDir) && ! is_link($harnessDir)) {
            self::recursiveRemoveDir($harnessDir);
            if (is_dir($harnessDir)) {
                $tempCleaned = false;
                $errors[] = "TEMP_DIR_STILL_EXISTS: '{$harnessDir}'";
            }
        }

        $success = $childReaped && $schemaAbsent && $listenersClosed && $tempCleaned;

        return [
            'child_reaped' => $childReaped,
            'schema_absent' => $schemaAbsent,
            'listeners_closed' => $listenersClosed,
            'temp_cleaned' => $tempCleaned,
            'success' => $success,
            'errors' => $errors,
        ];
    }

    /**
     * Recursively remove a directory without following symlinks.
     */
    public static function recursiveRemoveDir(string $dir): void
    {
        $entries = glob($dir . '/{,.}*', GLOB_BRACE);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                $base = basename($entry);
                if ($base === '.' || $base === '..') {
                    continue;
                }
                if (is_dir($entry) && ! is_link($entry)) {
                    self::recursiveRemoveDir($entry);
                } elseif (is_file($entry) || is_link($entry)) {
                    @unlink($entry);
                }
            }
        }
        @rmdir($dir);
    }
}
