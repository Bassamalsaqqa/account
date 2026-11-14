<?php

declare(strict_types=1);

namespace Tests\Support\Harness;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;

/**
 * Managed harness for Mobile V2 paired HTTPS integration testing.
 *
 * Enforces strict environment invariants, credential protection, and lifecycle boundaries:
 * - Default-deny: refused unless MOBILE_V2_HARNESS_ENABLE=1 and Root explicitly coordinates start.
 * - Listener startup is held until MOBILE_V2_HARNESS_START_LISTENER=1.
 * - Exact loopback host 127.0.0.1 and exact MariaDB port 3342.
 * - Rejects candidate 39fea (marked CANDIDATE_KNOWN_BAD; changes required on Android PR 23).
 * - Enforces mandatory pairing manifest SHA-256 pin and Root-approved peer Android HEAD across ALL execution modes.
 * - Separates QUALIFIED_EXECUTION_HEAD (698) from actual backend execution HEAD.
 * - Pure format regex validation occurs strictly before any external git process execution.
 * - Child process environment isolation with Symfony Process false-unset removals to deny parent DB secrets.
 * - Child verifies DisposableMariaDbSchema::assertPrimarySchema ownership proof BEFORE Laravel or PDO boot.
 * - Additional active PDO assertion (@@port=3342, DATABASE()=schema) executed in app->booted().
 * - Process objects kept as private properties lifetime; bounded stop() cleanup guaranteed.
 * - Active bounded HTTP and TLS readiness probes verify matching harness nonce before return.
 * - Loopback port preflight is executed strictly after all source, gate, and manifest assertions.
 * - Caddy config has admin off, auto_https off, and binds strictly to 127.0.0.1.
 * - Protects credentials: write to private 0600 file outside checkout, never stdout/return.
 */
final class DisposableMobileV2BackendHarness
{
    public const string REQUIRED_BASE_BRANCH = 't3/recover-cash-writer';

    public const string QUALIFIED_EXECUTION_HEAD = '6981511405d7bcd04cd8f8a09e64443ce0aaf47e';

    public const string QUALIFIED_APP_TREE = '34d1da0ae82b182fa2c0adafd785416a86834a20';

    public const string EVIDENCE_PUBLICATION_COMMIT = '717c3da2829792a5ec4f11597905a17dbb8e7cd9';

    /** Known peer Android integration candidate commit; marked CANDIDATE_KNOWN_BAD (changes required, unit 76 PASS but not Root accepted). */
    public const string ANDROID_CANDIDATE_KNOWN_BAD = '39fea3eae32522240caa0db8f40d018694064d8f';

    /** Root-approved Android candidate HEAD (docs qualification). */
    public const string APPROVED_ANDROID_HEAD = '019ea39af09558c2622356cb22235572747e3ddc';

    /** Peer Android implementation qualified executable SHA. */
    public const string APPROVED_ANDROID_EXECUTABLE_HEAD = 'ddfc75536e647c65a6a7e8868c9336cee93769ec';

    /** Historical validated ca5a dual-manifest SHA-256 hash (for historical audit / reference). */
    public const string CA5A_MANIFEST_SHA256 = '3248b9721c3e2a09e84ae478ddb0171c3ea576fd0cbdd70b3606e4cdda4ff3e7';

    /** Validated ca5a canonical intent profile digest. */
    public const string CA5A_CANONICAL_DIGEST = '0847e10d89777e103252ebfa2d27f89a95832a5d70e01af57afe6423f703c630';

    /** Loopback port for supervised PHP HTTP backend (Caddy proxies TLS in front). */
    public const int BACKEND_LOOPBACK_PORT = 18080;

    /** TLS port exposed by Caddy for Android emulator access on 10.0.2.2. */
    public const int TLS_PROXY_PORT = 18443;

    /** Bounded execution scopes. */
    public const string SCOPE_PROVISION_ONLY = 'ROOT_AUTHORIZED_LOCAL_PROVISION_ONLY';

    public const string SCOPE_READINESS_ONLY = 'ROOT_AUTHORIZED_READINESS_ONLY';

    public const string SCOPE_PAIRED_EXECUTION = 'ROOT_AUTHORIZED_PAIRED_EXECUTION';

    private bool $provisioned = false;

    private ?DisposableMariaDbSchema $schema = null;

    private ?string $harnessTempDir = null;

    private ?string $credentialsFilePath = null;

    private ?string $handoffFilePath = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $clientHandoff = null;

    private bool $readinessOnly = false;

    private ?string $harnessNonce = null;

    private ?string $proofPath = null;

    private ?string $proofNonce = null;

    /** Owned PHP HTTP backend process (lifetime property). */
    private ?Process $backendProcess = null;

    /** Owned Caddy TLS proxy process (lifetime property). */
    private ?Process $caddyProcess = null;

    public function __destruct()
    {
        $this->cleanup();
    }

    public function getSchema(): ?DisposableMariaDbSchema
    {
        return $this->schema;
    }

    public function getBackendProcess(): ?Process
    {
        return $this->backendProcess;
    }

    public function getCaddyProcess(): ?Process
    {
        return $this->caddyProcess;
    }

    public function getListenerPid(): ?int
    {
        return $this->backendProcess?->getPid();
    }

    public function getCaddyPid(): ?int
    {
        return $this->caddyProcess?->getPid();
    }

    public function getCredentialsFilePath(): ?string
    {
        return $this->credentialsFilePath;
    }

    public function getHandoffFilePath(): ?string
    {
        return $this->handoffFilePath;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getClientHandoff(): ?array
    {
        return $this->clientHandoff;
    }

    public function isReadinessOnly(): bool
    {
        return $this->readinessOnly;
    }

    public function setReadinessOnly(bool $readinessOnly): void
    {
        $this->readinessOnly = $readinessOnly;
    }

    public function getHarnessNonce(): ?string
    {
        return $this->harnessNonce;
    }

    public function getProofPath(): ?string
    {
        return $this->proofPath;
    }

    public function getProofNonce(): ?string
    {
        return $this->proofNonce;
    }

    public function isProvisioned(): bool
    {
        return $this->provisioned;
    }

    /**
     * Tracked synthetic fixture state (sanitized public IDs).
     *
     * @var array<string, mixed>|null
     */
    private ?array $fixtureManifest = null;

    /**
     * @return array<string, mixed>|null
     */
    public function getFixtureManifest(): ?array
    {
        return $this->fixtureManifest;
    }

    /**
     * Test seam: inject simulated provisioned state and ownership proof for unit testing gate logic.
     * Pure in-memory state; zero sockets, zero DB connections.
     */
    public function injectTestProvisionedState(
        string $nonce = '0123456789abcdef0123456789abcdef',
        string $proofPath = '/tmp/test_proof.json',
        string $proofNonce = '0123456789abcdef0123456789abcdef'
    ): void {
        $this->provisioned = true;
        $this->harnessNonce = $nonce;
        $this->proofPath = $proofPath;
        $this->proofNonce = $proofNonce;
        $this->schema = (new \ReflectionClass(DisposableMariaDbSchema::class))->newInstanceWithoutConstructor();
    }

    /**
     * Preflight fixed loopback ports to refuse occupied ports before process spawning.
     * Never kills or reuses foreign processes.
     */
    public static function assertPortsAvailable(int ...$ports): void
    {
        foreach ($ports as $port) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($conn)) {
                fclose($conn);
                throw new RuntimeException("Harness start refused: port {$port} on 127.0.0.1 is already in use. Refusing to reuse or kill foreign processes.");
            }
        }
    }

    /**
     * Build secure Caddyfile configuration string.
     * Guarantees admin off, auto_https off, explicit 127.0.0.1 bind, and SAN host matchers.
     */
    public static function buildCaddyConfig(
        string $serverCertPath,
        string $serverKeyPath,
        int $backendPort = self::BACKEND_LOOPBACK_PORT,
        int $tlsPort = self::TLS_PROXY_PORT
    ): string {
        return implode("\n", [
            '{',
            '    admin off',
            '    auto_https off',
            '}',
            '',
            "https://127.0.0.1:{$tlsPort}, https://localhost:{$tlsPort}, https://10.0.2.2:{$tlsPort} {",
            '    bind 127.0.0.1',
            "    tls {$serverCertPath} {$serverKeyPath}",
            "    reverse_proxy 127.0.0.1:{$backendPort}",
            '}',
            '',
        ]);
    }

    /**
     * Validate argument formats for transport lifecycle (pure regex test seam).
     */
    public static function validateStartArgs(string $peerAndroidHead, string $backendHead): void
    {
        if (! preg_match('/^[0-9a-fA-F]{40}$/', $peerAndroidHead)) {
            throw new RuntimeException("Harness refused: start requires valid 40-hex peerAndroidHead SHA, got '{$peerAndroidHead}'.");
        }

        if (! preg_match('/^[0-9a-fA-F]{40}$/', $backendHead)) {
            throw new RuntimeException("Harness refused: start requires valid 40-hex backendHead SHA, got '{$backendHead}'.");
        }
    }

    /**
     * Assert pre-socket execution environment invariants.
     *
     * Pure format validation (regex) occurs strictly before any external git process execution:
     *
     *  1. MOBILE_V2_HARNESS_ENABLE — default deny guard
     *  2. PHASE8_TEST_DB_HOST — must be exactly 127.0.0.1
     *  3. PHASE8_TEST_DB_PORT — must be exactly 3342
     *  4. expectedBackendHead format — 40-hex regex check (pure format, no process)
     *  5. peerAndroidHead format — 40-hex regex check if provided (pure format, no process)
     *  6. expectedBackendHead — git rev-parse HEAD comparison (git process)
     *  7. APP_ENV — must be testing
     *  8. booted app environment — must be testing
     *  9. PHASE8_ALLOW_DISPOSABLE_DB — must be 1
     * 10. DisposableMariaDbSchema::testSettings() — full settings validation
     * 11. git status porcelain — clean app/routes/config/composer.lock
     * 12. qualified execution commit object — must exist in repo
     * 13. app tree hash — must match QUALIFIED_APP_TREE
     *
     * @param  string  $expectedBackendHead  Full 40-hex commit SHA of current worktree HEAD (mandatory).
     * @param  string|null  $peerAndroidHead  Full 40-hex commit SHA of peer Android integration HEAD.
     */
    public static function assertPreSocketInvariants(string $expectedBackendHead, ?string $peerAndroidHead = null): void
    {
        // 1. Default-deny: harness is off unless Root explicitly enables it
        if (getenv('MOBILE_V2_HARNESS_ENABLE') !== '1') {
            throw new RuntimeException('Harness refused: defaults to no-execute. Set MOBILE_V2_HARNESS_ENABLE=1 when Root explicitly coordinates start.');
        }

        // 2. Enforce exact loopback host (env var, before any DB connection)
        $host = (string) getenv('PHASE8_TEST_DB_HOST');
        if ($host !== '127.0.0.1') {
            throw new RuntimeException("Harness refused: DB host must be explicitly 127.0.0.1, got '{$host}'.");
        }

        // 3. Enforce exact owned port (env var, before any DB connection)
        $port = (string) getenv('PHASE8_TEST_DB_PORT');
        if ($port !== '3342') {
            throw new RuntimeException("Harness refused: DB port must be explicitly 3342, got '{$port}'.");
        }

        // 4. Validate backend HEAD format (pure regex check, before any external git process)
        if (! preg_match('/^[0-9a-fA-F]{40}$/', $expectedBackendHead)) {
            throw new RuntimeException("Harness refused: expectedBackendHead must be a full 40-hex SHA, got '{$expectedBackendHead}'.");
        }

        // 5. Validate peer Android HEAD format (pure regex check, before any external git process)
        if ($peerAndroidHead !== null) {
            if (! preg_match('/^[0-9a-fA-F]{40}$/', $peerAndroidHead)) {
                throw new RuntimeException("Harness refused: peerAndroidHead must be a valid 40-hex SHA, got '{$peerAndroidHead}'.");
            }
        }

        // 6. Verify expected backend HEAD against actual git HEAD (git process)
        $root = dirname(__DIR__, 3);
        $currentHead = new Process(['git', 'rev-parse', 'HEAD'], $root);
        $currentHead->run();
        if (! $currentHead->isSuccessful() || trim($currentHead->getOutput()) !== $expectedBackendHead) {
            throw new RuntimeException("Harness refused: current git HEAD does not match expected backend HEAD '{$expectedBackendHead}'.");
        }

        // 7. Enforce testing environment (pre-boot env check)
        if (getenv('APP_ENV') !== 'testing') {
            throw new RuntimeException("Harness refused: APP_ENV must be testing, got '".(string) getenv('APP_ENV')."'.");
        }

        // 8. Enforce booted app environment if container is booted
        if (function_exists('app') && app()->bound('env')) {
            if (! app()->environment('testing')) {
                throw new RuntimeException("Harness refused: booted application environment is not testing, got '".app()->environment()."'.");
            }
        }

        // 9. Enforce disposable DB opt-in flag
        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1') {
            throw new RuntimeException('Harness refused: PHASE8_ALLOW_DISPOSABLE_DB opt-in flag is missing or not 1.');
        }

        // 10. Validate full disposable MariaDB settings
        DisposableMariaDbSchema::testSettings();

        // 11. Check that app/, routes/, config/, and composer.lock have zero uncommitted changes
        $statusProcess = new Process(['git', 'status', '--porcelain', 'app/', 'routes/', 'config/', 'composer.lock'], $root);
        $statusProcess->run();
        if (! $statusProcess->isSuccessful() || trim($statusProcess->getOutput()) !== '') {
            throw new RuntimeException('Harness refused: core paths app/, routes/, config/, composer.lock must be clean.');
        }

        // 12. Verify qualified execution commit exists in repository
        $catFile = new Process(['git', 'cat-file', '-e', self::QUALIFIED_EXECUTION_HEAD], $root);
        $catFile->run();
        if (! $catFile->isSuccessful()) {
            throw new RuntimeException('Harness refused: qualified execution commit '.self::QUALIFIED_EXECUTION_HEAD.' not found in repository.');
        }

        // 13. Enforce qualified app tree match (app/ must be 100% byte-identical to 34d1da0a...)
        $revParseApp = new Process(['git', 'rev-parse', 'HEAD:app'], $root);
        $revParseApp->run();
        if (! $revParseApp->isSuccessful() || trim($revParseApp->getOutput()) !== self::QUALIFIED_APP_TREE) {
            throw new RuntimeException('Harness refused: app tree does not match qualified tree '.self::QUALIFIED_APP_TREE);
        }
    }

    /**
     * Assert dual manifest pin before ANY socket, DB schema creation, or process execution.
     * Requires mandatory external pairing manifest SHA-256 pin to prevent circularity.
     * Separates QUALIFIED_EXECUTION_HEAD (698) from the actual runtime backend execution HEAD.
     * Enforces that peer Android HEAD matches Root-approved HEAD in manifest (rejects UNSET_).
     * Enforces that backend HEAD matches manifest backend HEAD (rejects UNSET_ in source template).
     * Enforces source fixture, CLI command, and CLI runner SHA-256 hashes.
     * Enforces qualified app tree and clean core files.
     *
     * @param  string  $backendHead  Full 40-hex commit SHA of current worktree HEAD.
     * @param  string  $peerAndroidHead  Full 40-hex commit SHA of peer Android HEAD.
     * @param  string|null  $manifestPath  Path to pairing manifest (defaults to docs/integration/mobile-v2-integration-manifest.json).
     * @param  string|null  $manifestShaPin  Mandatory 64-hex SHA-256 hash pin of the pairing manifest.
     * @return array<string, mixed>
     */
    public static function assertDualManifestPin(
        string $backendHead,
        string $peerAndroidHead,
        ?string $manifestPath = null,
        ?string $manifestShaPin = null
    ): array {
        // Reject known bad Android candidate immediately
        if ($peerAndroidHead === self::ANDROID_CANDIDATE_KNOWN_BAD) {
            throw new RuntimeException(
                "Harness start refused: Android candidate '{$peerAndroidHead}' is marked CANDIDATE_KNOWN_BAD. ".
                'Android PR 23 review identified changes required (gateway 15-field durable OfflineSaleV2 direct POST to 7-key online cash). '.
                'No HTTPS pair or start until client is corrected, reviewed, and approved by Root.'
            );
        }

        if (! preg_match('/^[0-9a-fA-F]{40}$/', $backendHead)) {
            throw new RuntimeException("Harness refused: backendHead must be a valid 40-hex SHA, got '{$backendHead}'.");
        }

        if (! preg_match('/^[0-9a-fA-F]{40}$/', $peerAndroidHead)) {
            throw new RuntimeException("Harness refused: peerAndroidHead must be a valid 40-hex SHA, got '{$peerAndroidHead}'.");
        }

        if ($manifestShaPin === null || ! preg_match('/^[0-9a-fA-F]{64}$/', $manifestShaPin)) {
            throw new RuntimeException('Harness refused: mandatory external pairing manifest SHA-256 pin is required.');
        }

        $root = dirname(__DIR__, 3);
        $manifestPath = $manifestPath ?? $root.'/docs/integration/mobile-v2-integration-manifest.json';
        if (! file_exists($manifestPath)) {
            throw new RuntimeException('Harness refused: integration manifest file missing at '.$manifestPath);
        }

        $actualManifestSha = (string) hash_file('sha256', $manifestPath);
        if (! hash_equals($manifestShaPin, $actualManifestSha)) {
            throw new RuntimeException("Harness refused: integration manifest SHA mismatch. Expected '{$manifestShaPin}', got '{$actualManifestSha}'.");
        }

        $rawManifest = file_get_contents($manifestPath);
        $manifestData = json_decode($rawManifest ?: '', true);
        if (! is_array($manifestData)) {
            throw new RuntimeException('Harness refused: integration manifest is malformed JSON.');
        }

        // 1. Verify qualified core head (698) matches QUALIFIED_EXECUTION_HEAD constant
        $qualifiedCoreHead = $manifestData['qualified_core_head_sha'] ?? $manifestData['base_execution_head_sha'] ?? null;
        if ($qualifiedCoreHead !== self::QUALIFIED_EXECUTION_HEAD) {
            throw new RuntimeException("Harness refused: qualified core head '{$qualifiedCoreHead}' does not match qualified base constant ".self::QUALIFIED_EXECUTION_HEAD);
        }

        // 2. Verify backend execution HEAD matches provided args AND is set (not template UNSET_)
        $manifestBackendHead = $manifestData['backend_execution_head_sha'] ?? null;
        if (! is_string($manifestBackendHead) || str_starts_with($manifestBackendHead, 'UNSET_') || strlen($manifestBackendHead) !== 40) {
            throw new RuntimeException(
                "Harness refused: pairing manifest has unset runtime backend execution HEAD ('".((string) $manifestBackendHead)."'). ".
                'The tracked manifest is a source template. Root-approved private runtime pairing manifest must specify the actual backend execution HEAD.'
            );
        }
        if ($manifestBackendHead !== $backendHead) {
            throw new RuntimeException("Harness refused: backend HEAD '{$backendHead}' does not match manifest backend HEAD '{$manifestBackendHead}'.");
        }

        // 3. Verify peer Android approved HEAD
        $approvedHead = $manifestData['peer_android_approved_head_sha'] ?? $manifestData['peer_android_pr_head_sha'] ?? null;
        if (! is_string($approvedHead) || str_starts_with($approvedHead, 'UNSET_') || strlen($approvedHead) !== 40) {
            throw new RuntimeException(
                "Harness refused: pairing manifest has unapproved peer Android HEAD ('".((string) $approvedHead)."'). ".
                'Pairing is held until client corrections are reviewed and approved by Root.'
            );
        }
        if ($approvedHead !== $peerAndroidHead) {
            throw new RuntimeException("Harness refused: peer Android HEAD '{$peerAndroidHead}' does not match manifest approved HEAD '{$approvedHead}'.");
        }

        // 4. Check source fixture SHA if present in manifest
        if (isset($manifestData['source_fixture_sha256'])) {
            $fixturePath = $root.'/tests/Support/Harness/DisposableMobileV2BackendHarness.php';
            if (file_exists($fixturePath)) {
                $actualFixtureSha = (string) hash_file('sha256', $fixturePath);
                if (! hash_equals($manifestData['source_fixture_sha256'], $actualFixtureSha)) {
                    throw new RuntimeException('Harness refused: source fixture SHA mismatch vs manifest.');
                }
            }
        }

        // 5. Check CLI command SHA if present in manifest
        if (isset($manifestData['cli_command_sha256'])) {
            $commandPath = $root.'/tests/Support/Harness/MobileV2HarnessCommand.php';
            if (file_exists($commandPath)) {
                $actualCommandSha = (string) hash_file('sha256', $commandPath);
                if (! hash_equals($manifestData['cli_command_sha256'], $actualCommandSha)) {
                    throw new RuntimeException('Harness refused: CLI command SHA mismatch vs manifest.');
                }
            }
        }

        // 6. Check CLI runner SHA if present in manifest
        if (isset($manifestData['cli_runner_sha256'])) {
            $runnerPath = $root.'/tests/Support/Harness/mobile-v2-harness-cli.php';
            if (file_exists($runnerPath)) {
                $actualRunnerSha = (string) hash_file('sha256', $runnerPath);
                if (! hash_equals($manifestData['cli_runner_sha256'], $actualRunnerSha)) {
                    throw new RuntimeException('Harness refused: CLI runner SHA mismatch vs manifest.');
                }
            }
        }

        $currentHead = new Process(['git', 'rev-parse', 'HEAD'], $root);
        $currentHead->run();
        if (! $currentHead->isSuccessful() || trim($currentHead->getOutput()) !== $backendHead) {
            throw new RuntimeException("Harness refused: current git HEAD does not match backend HEAD '{$backendHead}'.");
        }

        $revParseApp = new Process(['git', 'rev-parse', 'HEAD:app'], $root);
        $revParseApp->run();
        if (! $revParseApp->isSuccessful() || trim($revParseApp->getOutput()) !== self::QUALIFIED_APP_TREE) {
            throw new RuntimeException('Harness refused: app tree does not match qualified tree '.self::QUALIFIED_APP_TREE);
        }

        // Verify git status of core paths is clean
        $statusProcess = new Process(['git', 'status', '--porcelain', 'app/', 'routes/', 'config/', 'composer.lock'], $root);
        $statusProcess->run();
        if (! $statusProcess->isSuccessful() || trim($statusProcess->getOutput()) !== '') {
            throw new RuntimeException('Harness refused: core paths app/, routes/, config/, composer.lock must be clean.');
        }

        return [
            'qualified_core_head' => self::QUALIFIED_EXECUTION_HEAD,
            'backend_head' => $backendHead,
            'peer_android_head' => $peerAndroidHead,
            'qualified_tree' => self::QUALIFIED_APP_TREE,
            'ca5a_manifest_sha256' => self::CA5A_MANIFEST_SHA256,
            'ca5a_canonical_digest' => self::CA5A_CANONICAL_DIGEST,
            'manifest_sha256' => $actualManifestSha,
        ];
    }

    /**
     * Validates that runtime pairing manifest authorizes readiness probing.
     * Fails closed if manifest scope is provision-only or unrecognized.
     *
     * @return array<string, mixed>
     */
    public static function assertReadinessScope(?string $manifestPath, ?string $manifestShaPin): array
    {
        if ($manifestPath === null || $manifestPath === '' || ! file_exists($manifestPath)) {
            throw new RuntimeException('Harness readiness scope refused: pairing manifest missing.');
        }

        if ($manifestShaPin === null || $manifestShaPin === '') {
            throw new RuntimeException('Harness readiness scope refused: mandatory manifest SHA-256 pin required.');
        }

        $actualSha = (string) hash_file('sha256', $manifestPath);
        if (! hash_equals($manifestShaPin, $actualSha)) {
            throw new RuntimeException("Harness readiness scope refused: manifest SHA mismatch. Expected '{$manifestShaPin}', got '{$actualSha}'.");
        }

        $manifestData = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifestData)) {
            throw new RuntimeException('Harness readiness scope refused: manifest is malformed JSON.');
        }

        $status = (string) ($manifestData['status'] ?? '');
        if ($status === self::SCOPE_PROVISION_ONLY || str_contains($status, 'PROVISION_ONLY')) {
            throw new RuntimeException("Harness readiness scope refused: pairing manifest status is '{$status}'. Readiness probe requires explicit '".self::SCOPE_READINESS_ONLY."' scope.");
        }

        if ($status !== self::SCOPE_READINESS_ONLY && $status !== self::SCOPE_PAIRED_EXECUTION) {
            throw new RuntimeException("Harness readiness scope refused: unapproved manifest status '{$status}'. Readiness probe requires explicit '".self::SCOPE_READINESS_ONLY."' or '".self::SCOPE_PAIRED_EXECUTION."' scope.");
        }

        return $manifestData;
    }

    /**
     * Validates readiness probe response:
     * 1. Must have valid HTTP response headers with status code 200.
     * 2. Body must be non-false and valid JSON object with {"status": "ready", "nonce": "<matching_nonce>"}.
     *
     * @param  array<int, string>|null  $httpHeaders
     */
    public static function validateReadinessResponse(?array $httpHeaders, string|false $responseBody, string $expectedNonce): bool
    {
        if ($responseBody === false || $httpHeaders === null || $httpHeaders === [] || ! isset($httpHeaders[0])) {
            return false;
        }

        if (! preg_match('#^HTTP/\S+\s+200\b#', $httpHeaders[0])) {
            return false;
        }

        $data = json_decode($responseBody, true);
        if (! is_array($data)) {
            return false;
        }

        if (($data['status'] ?? null) !== 'ready') {
            return false;
        }

        return hash_equals($expectedNonce, (string) ($data['nonce'] ?? ''));
    }

    /**
     * Builds client handoff manifest structure conforming to mobile-v2-client-handoff-schema.json.
     *
     * @param  array<string, mixed>  $runtimePins
     * @param  array<string, mixed>  $fixture
     * @param  array<string, mixed>  $sampleRequest
     * @param  array<string, mixed>  $serverContext
     * @param  array<string, mixed>|null  $startupTls
     * @return array<string, mixed>
     */
    public static function buildClientHandoff(
        array $runtimePins,
        array $fixture,
        string $credentialsPath,
        array $sampleRequest,
        array $serverContext,
        string $canonicalDigest,
        ?array $startupTls = null
    ): array {
        return [
            'schema_version' => '1.0.0',
            'handoff_scope' => 'mobile_v2_online_cash_sale',
            'backend_runtime_pins' => [
                'qualified_core_head_sha' => (string) ($runtimePins['qualified_core_head_sha'] ?? self::QUALIFIED_EXECUTION_HEAD),
                'backend_execution_head_sha' => (string) ($runtimePins['backend_execution_head_sha'] ?? ''),
                'qualified_app_tree_sha' => (string) ($runtimePins['qualified_app_tree_sha'] ?? self::QUALIFIED_APP_TREE),
                'peer_android_approved_head_sha' => (string) ($runtimePins['peer_android_approved_head_sha'] ?? ''),
                'peer_android_executable_head_sha' => (string) ($runtimePins['peer_android_executable_head_sha'] ?? ''),
            ],
            'endpoints' => [
                'base_url' => 'https://10.0.2.2:'.self::TLS_PROXY_PORT.'/api/mobile/v2',
                'loopback_base_url' => 'https://127.0.0.1:'.self::TLS_PROXY_PORT.'/api/mobile/v2',
                'routes' => [
                    'online_cash_sale' => '/companies/{companyId}/sales/online-cash',
                    'offline_event_status' => '/companies/{companyId}/offline-events/{clientEventId}/status',
                    'readiness_probe' => '/_harness/readiness',
                ],
            ],
            'fixture' => $fixture,
            'credentials_path' => $credentialsPath,
            'sample_raw_wire_request' => $sampleRequest,
            'server_synthesized_context' => $serverContext,
            'canonical_intent_profile_digest' => $canonicalDigest,
            'startup_tls' => $startupTls ?? [
                'status' => 'UNAVAILABLE_AWAITING_TLS_START',
                'ca_cert_path' => null,
                'ca_cert_sha256' => null,
                'readiness_url' => null,
                'readiness_nonce' => null,
            ],
        ];
    }

    /**
     * Phase 1: Provision disposable schema and seed synthetic qualification fixtures.
     *
     * Mandatory pins enforced across ALL execution modes (including provision) before Schema::create.
     * Entire schema lifecycle (create, migrate, seed) is enclosed in try/catch to ensure
     * zero database schema or file leaks upon any failure.
     *
     * @param  string  $expectedBackendHead  Full 40-hex commit SHA of current worktree HEAD (mandatory).
     * @param  string  $peerAndroidHead  Full 40-hex commit SHA of peer Android integration HEAD (mandatory).
     * @param  string|null  $manifestPath  Path to pairing manifest.
     * @param  string|null  $manifestShaPin  Mandatory 64-hex SHA-256 hash pin of pairing manifest.
     * @return array{
     *     manifest: array<string, mixed>,
     *     credentials_path: string,
     *     handoff_path: string,
     *     handoff: array<string, mixed>,
     *     sample_request: array<string, mixed>,
     *     canonical_digest: string
     * }
     */
    public function provision(
        string $expectedBackendHead,
        string $peerAndroidHead,
        ?string $manifestPath = null,
        ?string $manifestShaPin = null
    ): array {
        if ($manifestShaPin === null || $manifestShaPin === '') {
            throw new RuntimeException('Harness provision refused: mandatory external pairing manifest SHA-256 pin is required.');
        }

        // Enforce pre-socket invariants and dual manifest pin BEFORE creating any database schema
        self::assertPreSocketInvariants($expectedBackendHead, $peerAndroidHead);
        self::assertDualManifestPin($expectedBackendHead, $peerAndroidHead, $manifestPath, $manifestShaPin);

        $connectionSwitched = false;

        try {
            // Establish private harness temporary directory (chmod 0700) outside checkout
            $this->harnessTempDir = sys_get_temp_dir().'/v2_harness_'.bin2hex(random_bytes(8));
            if (! is_dir($this->harnessTempDir)) {
                mkdir($this->harnessTempDir, 0700, true);
            }
            $this->harnessNonce = bin2hex(random_bytes(16));

            // Create and migrate owned disposable schema inside try block
            $this->schema = DisposableMariaDbSchema::create();
            $this->schema->migrate();

            $schemaEnv = $this->schema->environment();
            $this->proofPath = $schemaEnv['PHASE8_DISPOSABLE_DB_PROOF'] ?? null;
            $this->proofNonce = $schemaEnv['PHASE8_DISPOSABLE_DB_NONCE'] ?? null;
            if ($this->proofPath === null || $this->proofNonce === null) {
                throw new RuntimeException('Unable to establish disposable MariaDB schema ownership proof.');
            }

            // Explicitly switch Laravel DB connection manager to the fresh owned schema BEFORE models
            $this->schema->switchLaravelConnection();
            $connectionSwitched = true;

            // Assert actual selected database matches fresh owned schema name AND port is 3342
            /** @var object{port: string|int, db: string} $dbIdentity */
            $dbIdentity = DB::selectOne('SELECT @@port AS port, DATABASE() AS db');
            $currentDb = (string) $dbIdentity->db;
            $currentPort = (int) $dbIdentity->port;

            if ($currentDb !== $this->schema->schemaName()) {
                throw new RuntimeException("Connection verification failed: expected database '{$this->schema->schemaName()}', got '{$currentDb}'.");
            }
            if ($currentPort !== 3342) {
                throw new RuntimeException("Connection verification failed: expected port 3342, got '{$currentPort}'.");
            }

            // Seed domain fixtures using standard actions and services under owned schema
            app(CompanyContext::class)->clear();

            $owner = User::factory()->create([
                'email' => 'owner_'.Str::random(8).'@example.com',
                'password' => Hash::make('HarnessOwnerPass123!'),
            ]);

            $staff = User::factory()->create([
                'email' => 'staff_'.Str::random(8).'@example.com',
                'password' => Hash::make('HarnessStaffPass123!'),
            ]);

            /** @var Company $company */
            $company = app(CreateCompanyAction::class)->execute($owner, [
                'name_ar' => 'شركة مؤهلة للتكامل',
                'name_en' => 'Qualified Integration Co',
                'base_currency_code' => 'ILS',
            ]);

            // Set explicit owner authentication context and tenant context
            auth()->setUser($owner);
            app(CompanyContext::class)->setCompany($company, $owner);

            // Seed roles & permissions
            app(CompanyRoleService::class)->ensurePermissionsExist();
            app(CompanyRoleService::class)->seedCompanyRoles($company);
            setPermissionsTeamId($company->id);

            $owner->assignRole('Owner');

            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $staff->id,
                'status' => 'active',
                'is_owner' => false,
            ]);

            $staff->assignRole('Sales');
            $staff->givePermissionTo([
                'sales.invoice.create',
                'sales.invoice.post',
                'sales.invoice.view',
                'money.receipt.create',
                'money.receipt.allocate',
                'money.receipt.view',
            ]);

            $deviceId = (string) Str::uuid();
            $device = MobileDevice::create([
                'device_id' => $deviceId,
                'user_id' => $staff->id,
                'device_name' => 'Android Client Integration Device',
                'platform' => 'android',
                'app_version' => '2.0.0',
                'os_version' => '14.0',
                'status' => 'active',
            ]);

            $rawToken = 'st_m_harness_'.bin2hex(random_bytes(32));
            MobileAccessToken::create([
                'token_hash' => hash('sha256', $rawToken),
                'device_id' => $deviceId,
                'user_id' => $staff->id,
                'company_id' => $company->id,
                'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $staff->password), 0, 16),
                'expires_at' => now()->addDays(7),
            ]);

            $customer = Customer::create([
                'company_id' => $company->id,
                'name_ar' => 'عميل نقدي للتكامل',
                'name_en' => 'Integration Cash Customer',
                'default_currency_code' => 'ILS',
                'preferred_locale' => 'ar',
                'status' => 'active',
                'created_by' => $owner->id,
            ]);

            $warehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->firstOrFail();

            $baseUnit = Unit::where('company_id', $company->id)->where('code', 'piece')->firstOrFail();

            $product = Product::create([
                'company_id' => $company->id,
                'base_unit_id' => $baseUnit->id,
                'name_ar' => 'منتج تكامل مؤهل',
                'name_en' => 'Qualified Integration Product',
                'sku' => 'QUAL-PROD-001',
                'product_type' => 'stock',
                'track_stock' => true,
                'active' => true,
                'created_by' => $owner->id,
            ]);

            ProductUnit::create([
                'company_id' => $company->id,
                'product_id' => $product->id,
                'unit_id' => $baseUnit->id,
                'conversion_to_base' => '1.000000',
                'is_base' => true,
                'is_default_sale' => true,
                'is_default_purchase' => true,
                'active' => true,
                'default_sale_price_base' => '50.000000',
            ]);

            $taxRate = TaxRate::create([
                'company_id' => $company->id,
                'code' => 'VAT16',
                'name_ar' => 'ضريبة القيمة المضافة 16%',
                'name_en' => 'VAT 16%',
                'rate' => '16.000000',
                'calculation' => 'exclusive',
                'active' => true,
            ]);

            // Post opening stock under explicit owner authentication context
            app(PostOpeningStockAction::class)->execute(
                company: $company,
                product: $product,
                warehouse: $warehouse,
                quantity: Quantity::of('100.000000'),
                unitCostBase: '20.000000',
                user: $owner,
                idempotencyKey: 'harness-opening-'.Str::ulid(),
            );

            /** @var MoneyAccount $cashAccount */
            $cashAccount = MoneyAccount::where('company_id', $company->id)
                ->where('account_type', MoneyAccount::TYPE_CASH)
                ->where('currency_code', $company->base_currency_code)
                ->firstOrFail();

            // Clear authentication and sessionless context so subsequent mobile requests rely solely on token
            auth()->forgetUser();
            app(CompanyContext::class)->clearSessionless();

            $sampleEventId = (string) Str::uuid();
            $sampleRequest = [
                'client_event_id' => $sampleEventId,
                'device_id' => $device->device_id,
                'customer_id' => $customer->public_id,
                'warehouse_id' => $warehouse->public_id,
                'currency' => 'ILS',
                'lines' => [
                    [
                        'product_id' => $product->public_id,
                        'product_unit_id' => $baseUnit->public_id,
                        'conversion_to_base' => '1.000000',
                        'quantity' => '1.000000',
                        'unit_price' => '50.000000',
                        'tax_rate_id' => $taxRate->public_id,
                        'tax_rate' => '16.000000',
                        'tax_inclusive' => false,
                    ],
                ],
                'payment' => [
                    'settlement_mode' => 'full',
                    'payment_method' => 'cash',
                    'cash_account_id' => $cashAccount->public_id,
                    'amount' => '58.000000',
                ],
            ];

            $this->fixtureManifest = [
                'schema_name' => $this->schema->schemaName(),
                'company_public_id' => (string) $company->public_id,
                'company_base_currency' => (string) $company->base_currency_code,
                'staff_actor_public_id' => (string) $staff->public_id,
                'device_id' => (string) $device->device_id,
                'customer_public_id' => (string) $customer->public_id,
                'warehouse_public_id' => (string) $warehouse->public_id,
                'product_public_id' => (string) $product->public_id,
                'product_unit_public_id' => (string) $baseUnit->public_id,
                'tax_rate_public_id' => (string) $taxRate->public_id,
                'cash_account_public_id' => (string) $cashAccount->public_id,
                'sample_event_id' => $sampleEventId,
            ];

            // Write private runtime credentials artifact to harnessTempDir (chmod 0600)
            // Credentials are NEVER returned in public arrays or printed to stdout.
            $this->credentialsFilePath = $this->harnessTempDir.'/runtime_credentials.json';
            file_put_contents(
                $this->credentialsFilePath,
                json_encode([
                    'raw_token' => $rawToken,
                    'token_hash' => hash('sha256', $rawToken),
                    'device_id' => $device->device_id,
                    'staff_email' => $staff->email,
                    'schema' => $this->schema->schemaName(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            chmod($this->credentialsFilePath, 0600);

            $serverContext = [
                'company_id' => (string) $company->public_id,
                'actor_id' => (string) $staff->public_id,
                'currency_minor_units' => 2,
            ];

            $profile = OnlineSaleIntentProfile::construct($serverContext, $sampleRequest);
            $canonicalDigest = OnlineSaleIntentProfile::computeDigest($profile);

            $runtimePins = [
                'qualified_core_head_sha' => self::QUALIFIED_EXECUTION_HEAD,
                'backend_execution_head_sha' => $expectedBackendHead,
                'qualified_app_tree_sha' => self::QUALIFIED_APP_TREE,
                'peer_android_approved_head_sha' => self::APPROVED_ANDROID_HEAD,
                'peer_android_executable_head_sha' => $peerAndroidHead,
            ];

            $this->clientHandoff = self::buildClientHandoff(
                $runtimePins,
                $this->fixtureManifest,
                $this->credentialsFilePath,
                $sampleRequest,
                $serverContext,
                $canonicalDigest
            );

            $this->handoffFilePath = $this->harnessTempDir.'/runtime_client_handoff.json';
            file_put_contents(
                $this->handoffFilePath,
                json_encode($this->clientHandoff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            chmod($this->handoffFilePath, 0600);

            $this->provisioned = true;

            return [
                'manifest' => $this->fixtureManifest,
                'credentials_path' => $this->credentialsFilePath,
                'handoff_path' => $this->handoffFilePath,
                'handoff' => $this->clientHandoff,
                'sample_request' => $sampleRequest,
                'canonical_digest' => $canonicalDigest,
            ];
        } catch (\Throwable $e) {
            // Guarantee full cleanup on any provisioning failure
            $this->cleanup();
            throw $e;
        } finally {
            if ($connectionSwitched && $this->schema !== null) {
                $this->schema->restoreLaravelConnection();
            }
        }
    }

    /**
     * Phase 2: Start the supervised loopback PHP HTTP backend behind a test TLS Caddy proxy.
     *
     * Implements controlled HTTPS transport lifecycle for paired Android integration:
     * - Enforces pre-socket invariants and dual manifest pins BEFORE ANY socket is opened.
     * - Preflights loopback ports strictly after all gates and manifest checks pass.
     * - Nonce fallback throws if unset (no random fallback).
     * - Child process environment unsets parent DB secrets using Symfony Process false removal.
     * - Child verifies DisposableMariaDbSchema::assertPrimarySchema ownership proof BEFORE framework or PDO.
     * - Additional active PDO assertion (@@port=3342, DATABASE()=schema) executed in app->booted().
     * - Starts Symfony Process objects stored as lifetime private properties; guaranteed stop() cleanup.
     * - Active bounded HTTP and TLS readiness probes verify matching harness nonce before return.
     *
     * @param  string  $peerAndroidHead  Full 40-hex commit SHA of peer Android integration HEAD.
     * @param  string  $backendHead  Full 40-hex commit SHA of current backend worktree HEAD.
     * @param  string|null  $manifestPath  Path to pairing manifest.
     * @param  string|null  $manifestShaPin  Mandatory 64-hex SHA-256 hash pin of the pairing manifest.
     * @return array{backend_loopback_port: int, tls_proxy_port: int, ca_cert_path: string, credentials_path: string, handoff_path: string}
     */
    public function start(
        string $peerAndroidHead,
        string $backendHead,
        ?string $manifestPath = null,
        ?string $manifestShaPin = null
    ): array {
        if (! $this->provisioned || $this->schema === null) {
            throw new RuntimeException('Harness refused: must be provisioned before start.');
        }

        if ($this->harnessNonce === null || $this->harnessNonce === '') {
            throw new RuntimeException('Harness start refused: harness nonce is unset. Harness must be provisioned before start.');
        }
        $nonce = $this->harnessNonce;

        if ($this->proofPath === null || $this->proofNonce === null) {
            throw new RuntimeException('Harness start refused: schema ownership proof is unset.');
        }

        self::validateStartArgs($peerAndroidHead, $backendHead);

        // Reject known bad Android candidate
        if ($peerAndroidHead === self::ANDROID_CANDIDATE_KNOWN_BAD) {
            throw new RuntimeException(
                "Harness start refused: Android candidate '{$peerAndroidHead}' is marked CANDIDATE_KNOWN_BAD. ".
                'Android PR 23 review identified changes required (gateway 15-field durable OfflineSaleV2 direct POST to 7-key online cash). '.
                'No HTTPS pair or start until client is corrected, reviewed, and approved by Root.'
            );
        }

        // Runtime execution gate: held until Root explicitly coordinates with Android thread
        if (getenv('MOBILE_V2_HARNESS_START_LISTENER') !== '1') {
            throw new RuntimeException(
                'Harness start refused: MOBILE_V2_HARNESS_START_LISTENER is not set. '.
                'Listener startup is held pending Android thread 83e6a721 readiness and explicit Root coordination grant. '.
                'Zero background servers, TLS sockets, or DB listeners started.'
            );
        }

        // Dual manifest pin assertion before ANY socket or process creation
        self::assertDualManifestPin($backendHead, $peerAndroidHead, $manifestPath, $manifestShaPin);

        if ($this->readinessOnly) {
            self::assertReadinessScope($manifestPath, $manifestShaPin);
        }

        // Preflight loopback ports ONLY AFTER ALL gates and manifest assertions have passed
        self::assertPortsAvailable(self::BACKEND_LOOPBACK_PORT, self::TLS_PROXY_PORT);

        $root = dirname(__DIR__, 3);

        // Write private bootstrap config dir (0700) outside the checkout
        $bootstrapDir = $this->harnessTempDir.'/bootstrap';
        if (! is_dir($bootstrapDir)) {
            mkdir($bootstrapDir, 0700, true);
        }

        $dbSettings = DisposableMariaDbSchema::testSettings();
        $testEnvPath = $bootstrapDir.'/.env.harness';
        $schemaName = $this->schema->schemaName();
        $cacheDir = $bootstrapDir.'/cache';
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0700, true);
        }

        // Write private 0600 child JSON config containing verified DB parameters and ownership nonce
        $childConfigPath = $bootstrapDir.'/child_bootstrap_config.json';
        file_put_contents($childConfigPath, json_encode([
            'expected_backend_head' => $backendHead,
            'schema_name' => $schemaName,
            'proof_path' => $this->proofPath,
            'proof_nonce' => $this->proofNonce,
            'db_host' => '127.0.0.1',
            'db_port' => 3342,
            'db_username' => $dbSettings['USERNAME'],
            'db_password' => $dbSettings['PASSWORD'],
            'harness_nonce' => $nonce,
            'cache_dir' => $cacheDir,
            'env_file' => $testEnvPath,
            'readiness_only' => $this->readinessOnly,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($childConfigPath, 0600);

        // Write isolated test-only .env.harness (0600)
        $testEnvContent = implode("\n", [
            'APP_ENV=testing',
            'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG=false',
            'LOG_CHANNEL=stderr',
            'DB_CONNECTION=mysql',
            'DB_HOST=127.0.0.1',
            'DB_PORT=3342',
            'DB_DATABASE='.$schemaName,
            'DB_USERNAME='.$dbSettings['USERNAME'],
            'DB_PASSWORD='.$dbSettings['PASSWORD'],
            'CACHE_STORE=array',
            'SESSION_DRIVER=array',
            'QUEUE_CONNECTION=sync',
            'PHASE8_ALLOW_DISPOSABLE_DB=1',
            'PHASE8_TEST_DB_HOST=127.0.0.1',
            'PHASE8_TEST_DB_PORT=3342',
            'PHASE8_TEST_DB_USERNAME='.$dbSettings['USERNAME'],
            'PHASE8_TEST_DB_PASSWORD='.$dbSettings['PASSWORD'],
            'PHASE8_DISPOSABLE_DB_PROOF='.$this->proofPath,
            'PHASE8_DISPOSABLE_DB_NONCE='.$this->proofNonce,
            'MOBILE_V2_HARNESS_ENABLE=1',
            'MOBILE_V2_CASH_SLICE_ENABLED=true',
            'MOBILE_API_ENABLED=true',
            'MOBILE_V2_API_ENABLED=true',
            'APP_ROUTES_CACHE='.$cacheDir.'/routes-v7.php',
            'APP_CONFIG_CACHE='.$cacheDir.'/config.php',
            'APP_SERVICES_CACHE='.$cacheDir.'/services.php',
            'APP_PACKAGES_CACHE='.$cacheDir.'/packages.php',
            'APP_EVENTS_CACHE='.$cacheDir.'/events.php',
            'HARNESS_OWNED_NONCE='.$nonce,
        ]);
        file_put_contents($testEnvPath, $testEnvContent);
        chmod($testEnvPath, 0600);

        // Write test-only HTTP bootstrap: validates private config file modes/uid, sets putenv/$_ENV,
        // asserts schema ownership proof via assertPrimarySchema BEFORE framework or PDO,
        // and asserts active PDO database @@port=3342 and DATABASE()=schema in app->booted()
        $httpBootstrapPath = $bootstrapDir.'/http-bootstrap.php';
        $httpBootstrapContent = <<<PHP
<?php

declare(strict_types=1);

// Test-only HTTP bootstrap for paired Mobile V2 HTTPS integration
// Cash-only harness: zero static file passthrough before guards

\$configPath = '{$childConfigPath}';
\$bootstrapDir = '{$bootstrapDir}';
\$root = '{$root}';

if (! file_exists(\$configPath)) {
    http_response_code(500);
    echo "Harness bootstrap refused: missing child bootstrap config.\\n";
    exit(1);
}

// Validate file permissions (0600) and parent dir (0700)
\$filePerms = fileperms(\$configPath) & 0777;
\$dirPerms = fileperms(\$bootstrapDir) & 0777;
if (\$filePerms !== 0600 || \$dirPerms !== 0700) {
    http_response_code(500);
    echo "Harness bootstrap refused: invalid config file or directory permissions.\\n";
    exit(1);
}

if (function_exists('posix_getuid') && fileowner(\$configPath) !== posix_getuid()) {
    http_response_code(500);
    echo "Harness bootstrap refused: file owner UID mismatch.\\n";
    exit(1);
}

\$rawConfig = file_get_contents(\$configPath);
\$config = json_decode(\$rawConfig ?: '', true);
if (! is_array(\$config) || (\$config['harness_nonce'] ?? null) !== '{$nonce}') {
    http_response_code(500);
    echo "Harness bootstrap refused: invalid config or nonce mismatch.\\n";
    exit(1);
}

// Populate environment safely into fresh PHP process
\$envVars = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3342',
    'DB_DATABASE' => (string) \$config['schema_name'],
    'DB_USERNAME' => (string) \$config['db_username'],
    'DB_PASSWORD' => (string) \$config['db_password'],
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
    'PHASE8_TEST_DB_HOST' => '127.0.0.1',
    'PHASE8_TEST_DB_PORT' => '3342',
    'PHASE8_TEST_DB_USERNAME' => (string) \$config['db_username'],
    'PHASE8_TEST_DB_PASSWORD' => (string) \$config['db_password'],
    'PHASE8_DISPOSABLE_DB_PROOF' => (string) \$config['proof_path'],
    'PHASE8_DISPOSABLE_DB_NONCE' => (string) \$config['proof_nonce'],
    'MOBILE_V2_HARNESS_ENABLE' => '1',
    'MOBILE_V2_CASH_SLICE_ENABLED' => 'true',
    'MOBILE_API_ENABLED' => 'true',
    'MOBILE_V2_API_ENABLED' => 'true',
    'APP_ROUTES_CACHE' => \$config['cache_dir'].'/routes-v7.php',
    'APP_CONFIG_CACHE' => \$config['cache_dir'].'/config.php',
    'APP_SERVICES_CACHE' => \$config['cache_dir'].'/services.php',
    'APP_PACKAGES_CACHE' => \$config['cache_dir'].'/packages.php',
    'APP_EVENTS_CACHE' => \$config['cache_dir'].'/events.php',
    'HARNESS_OWNED_NONCE' => (string) \$config['harness_nonce'],
];

foreach (\$envVars as \$k => \$v) {
    putenv("{\$k}={\$v}");
    \$_ENV[\$k] = \$v;
    \$_SERVER[\$k] = \$v;
}

// Harness readiness probe route
\$uri = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (\$uri === '/_harness/readiness') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ready', 'nonce' => \$config['harness_nonce']]);
    exit(0);
}

if (! empty(\$config['readiness_only'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'forbidden',
        'message' => 'Harness is running in READINESS-ONLY mode. Financial endpoints and paired requests are strictly blocked.',
    ]);
    exit(0);
}

require_once \$root.'/vendor/autoload.php';

// Assert real disposable MariaDB schema ownership proof BEFORE framework or PDO!
\\Tests\\Support\\DisposableMariaDbSchema::assertPrimarySchema((string) \$config['schema_name']);

/** @var \\Illuminate\\Foundation\\Application \$app */
\$app = require \$root.'/bootstrap/app.php';
\$app->useEnvironmentPath(\$bootstrapDir);
\$app->loadEnvironmentFrom(basename('{$testEnvPath}'));

// Bind QualifiedCashFinancialExecutionAdapter strictly after verifying active PDO database and port
\$app->booted(function (\$app) use (\$config): void {
    if (getenv('APP_ENV') !== 'testing'
        || getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1'
        || getenv('MOBILE_V2_HARNESS_ENABLE') !== '1'
        || (string) getenv('DB_HOST') !== '127.0.0.1'
        || (string) getenv('DB_PORT') !== '3342'
        || (string) getenv('DB_DATABASE') !== \$config['schema_name']
        || (string) getenv('HARNESS_OWNED_NONCE') !== \$config['harness_nonce']
    ) {
        throw new \\RuntimeException('Child bootstrap invariant failure: environment mismatch.');
    }

    /** @var object{port: string|int, db: string} \$dbIdentity */
    \$dbIdentity = \\Illuminate\\Support\\Facades\\DB::selectOne('SELECT @@port AS port, DATABASE() AS db');
    if ((string) \$dbIdentity->db !== \$config['schema_name']) {
        throw new \\RuntimeException("Child bootstrap DB mismatch: expected '{\$config['schema_name']}', got '{\$dbIdentity->db}'.");
    }
    if ((int) \$dbIdentity->port !== 3342) {
        throw new \\RuntimeException("Child bootstrap DB port mismatch: expected 3342, got '{\$dbIdentity->port}'.");
    }

    \$app->bind(
        \\App\\Support\\Mobile\\Receipt\\FinancialExecutionAdapterInterface::class,
        \\Tests\\Support\\QualifiedCashFinancialExecutionAdapter::class
    );
});

\$kernel = \$app->make(\\Illuminate\\Contracts\\Http\\Kernel::class);
\$response = \$kernel->handle(
    \$request = \\Illuminate\\Http\\Request::capture()
);
\$response->send();
\$kernel->terminate(\$request, \$response);
PHP;
        file_put_contents($httpBootstrapPath, $httpBootstrapContent);
        chmod($httpBootstrapPath, 0600);

        // Generate private test TLS CA and server cert with explicit SAN
        $caCertPath = $bootstrapDir.'/test-ca.pem';
        $caKeyPath = $bootstrapDir.'/test-ca-key.pem';
        $serverCertPath = $bootstrapDir.'/server-cert.pem';
        $serverKeyPath = $bootstrapDir.'/server-key.pem';

        $caKeyGen = new Process([
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048',
            '-keyout', $caKeyPath,
            '-out', $caCertPath,
            '-days', '1',
            '-nodes',
            '-subj', '/CN=HarnessTestCA/O=Integration/C=IL',
        ]);
        $caKeyGen->run();
        if (! $caKeyGen->isSuccessful()) {
            throw new RuntimeException('Failed to generate test CA certificate: '.$caKeyGen->getErrorOutput());
        }
        chmod($caKeyPath, 0600);
        chmod($caCertPath, 0644);

        $serverKeyGen = new Process([
            'openssl', 'req', '-newkey', 'rsa:2048',
            '-keyout', $serverKeyPath,
            '-out', $bootstrapDir.'/server.csr',
            '-nodes',
            '-subj', '/CN=localhost/O=Integration/C=IL',
        ]);
        $serverKeyGen->run();
        if (! $serverKeyGen->isSuccessful()) {
            throw new RuntimeException('Failed to generate server key/CSR: '.$serverKeyGen->getErrorOutput());
        }
        chmod($serverKeyPath, 0600);

        $sanExtPath = $bootstrapDir.'/san.ext';
        file_put_contents($sanExtPath, implode("\n", [
            '[SAN]',
            'subjectAltName=DNS:localhost,IP:127.0.0.1,IP:10.0.2.2',
        ]));

        $certSign = new Process([
            'openssl', 'x509', '-req',
            '-in', $bootstrapDir.'/server.csr',
            '-CA', $caCertPath,
            '-CAkey', $caKeyPath,
            '-CAcreateserial',
            '-out', $serverCertPath,
            '-days', '1',
            '-extensions', 'SAN',
            '-extfile', $sanExtPath,
        ]);
        $certSign->run();
        if (! $certSign->isSuccessful()) {
            throw new RuntimeException('Failed to sign server certificate: '.$certSign->getErrorOutput());
        }
        chmod($serverCertPath, 0644);

        // Write secure Caddyfile with admin off and auto_https off
        $caddyConfigPath = $bootstrapDir.'/Caddyfile';
        file_put_contents(
            $caddyConfigPath,
            self::buildCaddyConfig($serverCertPath, $serverKeyPath, self::BACKEND_LOOPBACK_PORT, self::TLS_PROXY_PORT)
        );

        // Explicit minimal environment map with false-unset removals to deny parent DB secrets
        $serveEnv = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'APP_BASE_PATH' => $root,
            'APP_ENV' => 'testing',
            'PHP_CLI_SERVER_WORKERS' => '1',
            'HOME' => $this->harnessTempDir,
            'TMPDIR' => $this->harnessTempDir,

            'DB_CONNECTION' => false,
            'DB_HOST' => false,
            'DB_PORT' => false,
            'DB_DATABASE' => false,
            'DB_USERNAME' => false,
            'DB_PASSWORD' => false,
            'DB_URL' => false,
            'PHASE8_TEST_DB_HOST' => false,
            'PHASE8_TEST_DB_PORT' => false,
            'PHASE8_TEST_DB_USERNAME' => false,
            'PHASE8_TEST_DB_PASSWORD' => false,
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => false,
            'PHASE8_DISPOSABLE_DB_PROOF' => false,
            'PHASE8_DISPOSABLE_DB_NONCE' => false,
            'APP_KEY' => false,
            'CACHE_STORE' => false,
            'SESSION_DRIVER' => false,
            'QUEUE_CONNECTION' => false,
        ];

        try {
            // Launch supervised PHP HTTP backend on loopback
            $this->backendProcess = new Process(
                [PHP_BINARY, '-S', '127.0.0.1:'.self::BACKEND_LOOPBACK_PORT, $httpBootstrapPath],
                $root,
                $serveEnv,
            );
            $this->backendProcess->start();

            // Verify backend readiness via active bounded HTTP probe
            $this->verifyBackendReadiness($nonce);

            // Launch supervised Caddy TLS proxy
            $this->caddyProcess = new Process(
                ['caddy', 'run', '--config', $caddyConfigPath, '--adapter', 'caddyfile'],
                $bootstrapDir,
                $serveEnv,
            );
            $this->caddyProcess->start();

            // Verify TLS readiness via active bounded HTTPS probe
            $this->verifyTlsReadiness($nonce, $caCertPath);
        } catch (\Throwable $e) {
            $this->cleanup();
            throw $e;
        }

        if ($this->clientHandoff !== null) {
            $this->clientHandoff['startup_tls'] = [
                'status' => 'ACTIVE_TLS_READY',
                'ca_cert_path' => $caCertPath,
                'ca_cert_sha256' => (string) hash_file('sha256', $caCertPath),
                'readiness_url' => 'https://127.0.0.1:'.self::TLS_PROXY_PORT.'/_harness/readiness',
                'readiness_nonce' => $nonce,
            ];
            if ($this->handoffFilePath !== null) {
                file_put_contents(
                    $this->handoffFilePath,
                    json_encode($this->clientHandoff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
                chmod($this->handoffFilePath, 0600);
            }
        }

        return [
            'backend_loopback_port' => self::BACKEND_LOOPBACK_PORT,
            'tls_proxy_port' => self::TLS_PROXY_PORT,
            'ca_cert_path' => $caCertPath,
            'credentials_path' => $this->credentialsFilePath ?? '',
            'handoff_path' => $this->handoffFilePath ?? '',
        ];
    }

    /**
     * Bounded HTTP readiness probe to supervised loopback backend.
     * Verifies HTTP 200 response and exact matching harness nonce.
     */
    private function verifyBackendReadiness(string $expectedNonce, float $timeoutSeconds = 5.0): void
    {
        $start = microtime(true);
        $url = 'http://127.0.0.1:'.self::BACKEND_LOOPBACK_PORT.'/_harness/readiness';
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 0.5,
                'ignore_errors' => true,
            ],
        ]);

        while (microtime(true) - $start < $timeoutSeconds) {
            if ($this->backendProcess !== null && ! $this->backendProcess->isRunning()) {
                throw new RuntimeException(
                    'Harness start failed: PHP backend process exited prematurely. '.
                    'Output: '.$this->backendProcess->getOutput().
                    ' Error: '.$this->backendProcess->getErrorOutput()
                );
            }

            $http_response_header = null;
            $response = @file_get_contents($url, false, $ctx);
            if (self::validateReadinessResponse($http_response_header, $response, $expectedNonce)) {
                return;
            }
            usleep(50_000);
        }

        throw new RuntimeException(
            "Harness start failed: PHP backend readiness probe timed out after {$timeoutSeconds}s on port ".self::BACKEND_LOOPBACK_PORT
        );
    }

    /**
     * Bounded TLS readiness probe through supervised Caddy proxy.
     * Verifies HTTPS 200 response, CA certificate trust, SAN/SNI verification, and exact matching harness nonce.
     */
    private function verifyTlsReadiness(string $expectedNonce, string $caCertPath, float $timeoutSeconds = 5.0): void
    {
        $start = microtime(true);
        $url = 'https://127.0.0.1:'.self::TLS_PROXY_PORT.'/_harness/readiness';
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 0.5,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'cafile' => $caCertPath,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => '127.0.0.1',
            ],
        ]);

        while (microtime(true) - $start < $timeoutSeconds) {
            if ($this->caddyProcess !== null && ! $this->caddyProcess->isRunning()) {
                throw new RuntimeException(
                    'Harness start failed: Caddy TLS proxy process exited prematurely. '.
                    'Output: '.$this->caddyProcess->getOutput().
                    ' Error: '.$this->caddyProcess->getErrorOutput()
                );
            }

            $http_response_header = null;
            $response = @file_get_contents($url, false, $ctx);
            if (self::validateReadinessResponse($http_response_header, $response, $expectedNonce)) {
                return;
            }
            usleep(50_000);
        }

        throw new RuntimeException(
            "Harness start failed: Caddy TLS readiness probe timed out after {$timeoutSeconds}s on port ".self::TLS_PROXY_PORT
        );
    }

    /**
     * Phase 3: Explicit cleanup of owned schemas, files, temporary directories, and processes.
     *
     * Only terminates processes tracked by this harness instance ($caddyProcess, $backendProcess).
     * Never kills foreign PIDs or ports not owned by this instance.
     */
    public function cleanup(): void
    {
        if ($this->caddyProcess !== null && $this->caddyProcess->isRunning()) {
            $this->caddyProcess->stop(3.0);
            $this->caddyProcess = null;
        }

        if ($this->backendProcess !== null && $this->backendProcess->isRunning()) {
            $this->backendProcess->stop(3.0);
            $this->backendProcess = null;
        }

        if ($this->harnessTempDir !== null && is_dir($this->harnessTempDir)) {
            $this->removeDir($this->harnessTempDir);
            $this->harnessTempDir = null;
        }

        $this->credentialsFilePath = null;
        $this->handoffFilePath = null;
        $this->clientHandoff = null;
        $this->readinessOnly = false;
        $this->harnessNonce = null;
        $this->proofPath = null;
        $this->proofNonce = null;

        if ($this->schema !== null) {
            try {
                $this->schema->restoreLaravelConnection();
            } catch (\Throwable) {
                // Ignore if connection already restored
            }
            $this->schema->drop();
            $this->schema = null;
        }

        $this->provisioned = false;
        $this->fixtureManifest = null;
    }

    /**
     * Recursively remove an owned temporary directory.
     * Never traverses symlinks; only removes regular files and directories.
     */
    private function removeDir(string $dir): void
    {
        $entries = glob($dir.'/{,.}*', GLOB_BRACE);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                $base = basename($entry);
                if ($base === '.' || $base === '..') {
                    continue;
                }
                if (is_dir($entry) && ! is_link($entry)) {
                    $this->removeDir($entry);
                } elseif (is_file($entry) || is_link($entry)) {
                    @unlink($entry);
                }
            }
        }
        @rmdir($dir);
    }
}
