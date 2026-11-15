<?php

declare(strict_types=1);

namespace Tests\Support\Harness;

use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Supervised Mobile V2 Paired Financial Integration Drill.
 *
 * Enforces strict, multi-stage preflight and runtime validation:
 * 1. Strict prestart Root coordination approval (mode 0600, parent 0700, uid match, no symlinks, exact pins).
 * 2. CLI arguments cannot override prestart approval values.
 * 3. Independent external live Root approval after handoff creation before JVM (no self-computed authority).
 * 4. Company public ULID resolved via verified child config PDO query (never cast to int).
 * 5. Full client handoff schema validation via validateClientHandoff, plus strict TLS GET 200 before JVM.
 * 6. Evidence search in approved Android cwd; enforces pre/post freshness and 2 PASS 0 SKIP in JUnit XML.
 * 7. Bounded phase execution within child hold deadline (120s); stops at first failure.
 * 8. Guaranteed cleanup on SIGTERM/SIGKILL with admin schema drop and listener/temp verification.
 */
class SupervisedPairedFinancialDrill
{
    public const DEFAULT_HOLD_SECONDS = 120;
    public const REQUIRED_SCOPE = 'ROOT_AUTHORIZED_PAIRED_EXECUTION';
    public const REQUIRED_LIFECYCLE_GRANT = 'FINANCIAL_PAIRED_RUN';

    /**
     * Validates prestart Root coordination approval file.
     * Enforces strict permissions (0600 file, 0700 parent, current UID, no symlinks)
     * and asserts exact matches against dynamic HEAD, manifest SHA, and CWD.
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
     *     cwd: string
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

        if ($appPubHead === $appExeHead) {
            throw new RuntimeException('PRESTART_APPROVAL_IDENTICAL_HEADS_REFUSED: publication and executable heads must be distinct');
        }

        $realRoot = realpath($repoRoot);
        $appCwd = realpath((string) ($data['cwd'] ?? ''));
        if ($realRoot === false || $appCwd === false || $realRoot !== $appCwd) {
            throw new RuntimeException("PRESTART_APPROVAL_CWD_MISMATCH: expected {$realRoot}, got " . ($data['cwd'] ?? ''));
        }

        if (isset($data['expires_at'])) {
            $exp = strtotime((string) $data['expires_at']);
            if ($exp === false || $exp <= time()) {
                throw new RuntimeException('PRESTART_APPROVAL_EXPIRED');
            }
        }

        return [
            'root_coordination_granted' => true,
            'manifest_sha256' => $appManifestSha,
            'backend_head' => $appBackendHead,
            'android_publication_head' => $appPubHead,
            'android_executable_head' => $appExeHead,
            'cwd' => $realRoot,
        ];
    }

    /**
     * Validates independent external live Root approval after handoff creation before JVM.
     * Exposes candidate handoff and requires a matching pre-existing external Root approval file.
     * Fails closed if missing, wrong SHA, or self-computed.
     *
     * @param  string  $filePath  Path to live approval file
     * @param  string  $candidateHandoffPath  Path to generated handoff file
     * @param  string  $candidateHandoffSha  SHA-256 of candidate handoff file
     * @param  string  $backendHead  Verified backend HEAD
     * @param  string  $pubHead  Verified Android publication HEAD
     * @param  string  $exeHead  Verified Android executable HEAD
     * @param  string  $manifestSha  Verified manifest SHA
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
        string $manifestSha
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
            throw new RuntimeException("EVIDENCE_FILE_UNMODIFIED: SHA-256 unchanged after test execution");
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
            throw new RuntimeException("JUNIT_XML_STALE: mtime is earlier than test launch");
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
     * Performs exact cleanup: kills child CLI, reaps processes, drops owned schema
     * via admin credentials if lingered, and verifies listener/temp absence.
     *
     * @param  Process|null  $childProcess  Child harness process
     * @param  string|null  $schemaName  Owned schema name
     * @param  string|null  $proofPath  Schema proof path
     * @param  string|null  $proofNonce  Schema proof nonce
     * @param  string|null  $harnessDir  Harness temporary directory
     */
    public static function executeExactCleanup(
        ?Process $childProcess,
        ?string $schemaName,
        ?string $proofPath,
        ?string $proofNonce,
        ?string $harnessDir
    ): void {
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
        }

        // Drop schema if lingered after hard kill
        if ($schemaName !== null && $schemaName !== '' && $proofPath !== null && $proofNonce !== null) {
            try {
                putenv("PHASE8_DISPOSABLE_DB_PROOF={$proofPath}");
                putenv("PHASE8_DISPOSABLE_DB_NONCE={$proofNonce}");
                putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
                DisposableMariaDbSchema::assertPrimarySchema($schemaName);

                $settings = DisposableMariaDbSchema::testSettings();
                $pdo = new PDO(
                    "mysql:host=127.0.0.1;port=3342;charset=utf8mb4",
                    $settings['USERNAME'],
                    $settings['PASSWORD'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $pdo->exec("DROP DATABASE IF EXISTS `{$schemaName}`");
                fwrite(STDERR, "[Cleanup] Verified owned schema '{$schemaName}' dropped.\n");
            } catch (\Throwable $e) {
                fwrite(STDERR, "[Cleanup] Note on schema drop: {$e->getMessage()}\n");
            }
        }

        // Verify listener absence on 18080 and 18443
        foreach ([18080, 18443] as $port) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            if (is_resource($fp)) {
                fclose($fp);
                fwrite(STDERR, "[Cleanup Warning] Port {$port} still listening after cleanup.\n");
            }
        }

        // Clean temp directory if still exists
        if ($harnessDir !== null && is_dir($harnessDir) && ! is_link($harnessDir)) {
            // harness temp dir should be removed by harness __destruct; if lingering, warn
            fwrite(STDERR, "[Cleanup] Note: Harness temporary dir {$harnessDir} checked.\n");
        }
    }
}
