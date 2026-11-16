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
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use App\Support\Tenancy\CompanyContext;
use InvalidArgumentException;
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

    public const string QUALIFIED_APP_TREE_BASE = '34d1da0ae82b182fa2c0adafd785416a86834a20';

    public const string QUALIFIED_APP_TREE_G1 = 'd584067cf8f8a3588f4031a4cdb2107e0fa80b4d';

    public const string QUALIFIED_G1_BOOTSTRAP_CONTROLLER_BLOB = '9df528b0ef80b514a2472c81be2a9203b8c1830a';

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

    /** Internal backend loopback port when test transport proxy is mediating on BACKEND_LOOPBACK_PORT. */
    public const int BACKEND_INTERNAL_PORT = 18081;

    /** TLS port exposed by Caddy for Android emulator access on 10.0.2.2. */
    public const int TLS_PROXY_PORT = 18443;

    /** Bounded execution scopes. */
    public const string SCOPE_PROVISION_ONLY = 'ROOT_AUTHORIZED_LOCAL_PROVISION_ONLY';

    public const string SCOPE_READINESS_ONLY = 'ROOT_AUTHORIZED_READINESS_ONLY';

    public const string SCOPE_PAIRED_EXECUTION = 'ROOT_AUTHORIZED_PAIRED_EXECUTION';

    public const string SCOPE_ADVANCED_EXECUTION = 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION';

    public const int DEFAULT_READINESS_HOLD_SECONDS = 0;

    public const int MAX_READINESS_HOLD_SECONDS = 120;

    public const int DEFAULT_PAIRED_HOLD_SECONDS = 0;

    public const int MIN_PAIRED_HOLD_SECONDS = 1;

    public const int MAX_PAIRED_HOLD_SECONDS = 120;

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

    private ?string $rootPrestartApprovalPath = null;

    private ?string $rootLiveApprovalPath = null;

    /** Owned PHP HTTP backend process (lifetime property). */
    private ?Process $backendProcess = null;

    /** Owned test transport proxy process (lifetime property). */
    private ?Process $proxyProcess = null;

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

    public function getProxyProcess(): ?Process
    {
        return $this->proxyProcess;
    }

    public function getProxyPid(): ?int
    {
        return $this->proxyProcess?->getPid();
    }

    public function getBackendPid(): ?int
    {
        return $this->backendProcess?->getPid();
    }

    public function setRootPrestartApprovalPath(?string $path): void
    {
        $this->rootPrestartApprovalPath = $path;
    }

    public function getRootPrestartApprovalPath(): ?string
    {
        return $this->rootPrestartApprovalPath;
    }

    public function setRootLiveApprovalPath(?string $path): void
    {
        $this->rootLiveApprovalPath = $path;
    }

    public function getRootLiveApprovalPath(): ?string
    {
        return $this->rootLiveApprovalPath;
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

    private ?int $companyId = null;

    private ?string $companyPublicId = null;

    /**
     * @return array<string, mixed>|null
     */
    public function getFixtureManifest(): ?array
    {
        return $this->fixtureManifest;
    }

    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }

    public function getCompanyPublicId(): ?string
    {
        return $this->companyPublicId;
    }

    /**
     * Test transport single-response suppression configuration for Lost ACK testing.
     * Defaults to null (default-deny).
     *
     * @var array{
     *     enabled: bool,
     *     target_event_id: string,
     *     target_schema: string,
     *     target_nonce: string,
     *     scope: string,
     *     root_grant: string,
     *     enabled: bool,
     *     target_event_id: string,
     *     target_schema: string,
     *     target_nonce: string,
     *     target_uid: int
     * }|null
     */
    private ?array $lostAckSuppression = null;

    /**
     * Optional simulated fault stage for controlled rollback probes.
     */
    private ?string $simulatedFaultStage = null;

    /**
     * Configures single-response test-transport suppression for advanced lost-ACK testing.
     * Defaults to null (default-deny).
     *
     * @param array{
     *     scope: string,
     *     root_grant: string,
     *     enabled: bool,
     *     target_event_id: string,
     *     target_schema: string,
     *     target_nonce: string,
     *     target_uid: int
     * }|null $config
     */
    public function configureLostAckSuppression(?array $config): void
    {
        if ($config === null || empty($config['enabled'])) {
            $this->lostAckSuppression = null;

            return;
        }

        if (($config['scope'] ?? '') !== 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION') {
            throw new \InvalidArgumentException("Lost ACK suppression requires scope 'ROOT_AUTHORIZED_ADVANCED_PROBE_EXECUTION'.");
        }
        if (($config['root_grant'] ?? '') !== 'ADVANCED_LOST_ACK_PROBE') {
            throw new \InvalidArgumentException("Lost ACK suppression requires root_grant 'ADVANCED_LOST_ACK_PROBE'.");
        }
        if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', (string) ($config['target_event_id'] ?? ''))) {
            throw new \InvalidArgumentException("Lost ACK target_event_id must be valid UUID, got '".($config['target_event_id'] ?? 'null')."'.");
        }
        if (! preg_match('/^accounting_p8_tmp_[0-9a-f]{12}$/', (string) ($config['target_schema'] ?? ''))) {
            throw new \InvalidArgumentException("Lost ACK target_schema must match disposable schema regex, got '".($config['target_schema'] ?? 'null')."'.");
        }
        if (! preg_match('/^[0-9a-fA-F]{32,64}$/', (string) ($config['target_nonce'] ?? ''))) {
            throw new \InvalidArgumentException('Lost ACK target_nonce must be 32 to 64 hex characters.');
        }
        $expectedUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
        if ((int) ($config['target_uid'] ?? -1) !== $expectedUid) {
            throw new \InvalidArgumentException("Lost ACK target_uid mismatch: expected current process UID {$expectedUid}.");
        }
        if (empty($config['target_company_id']) || (int) $config['target_company_id'] <= 0) {
            throw new \InvalidArgumentException('Lost ACK target_company_id must be a positive integer.');
        }
        if (empty($config['target_company_public_id']) || ! preg_match('/^01[0-9A-HJ-KM-NP-TV-Z]{24}$/', (string) $config['target_company_public_id'])) {
            throw new \InvalidArgumentException("Lost ACK target_company_public_id must be a valid ULID, got '".($config['target_company_public_id'] ?? 'null')."'.");
        }
        if (empty($config['expected_digest']) || ! preg_match('/^[0-9a-f]{64}$/', (string) $config['expected_digest'])) {
            throw new \InvalidArgumentException('Lost ACK expected_digest must be a 64-hex lowercase SHA-256 string.');
        }
        if (empty($config['target_nonce_hash'])) {
            $config['target_nonce_hash'] = hash('sha256', (string) $config['target_nonce']);
        } elseif (! preg_match('/^[0-9a-f]{64}$/', (string) $config['target_nonce_hash'])) {
            throw new \InvalidArgumentException('Lost ACK target_nonce_hash must be a 64-hex lowercase SHA-256 string.');
        }

        $this->lostAckSuppression = $config;
    }

    /**
     * Configures test-only simulated fault stage for controlled rollback probes.
     */
    public function configureSimulatedFaultStage(?string $stage): void
    {
        if ($stage === null) {
            $this->simulatedFaultStage = null;

            return;
        }
        $allowed = ['after_draft', 'after_posting', 'after_payment', 'after_composite_completion'];
        if (! in_array($stage, $allowed, true)) {
            throw new \InvalidArgumentException("Unsupported simulated fault stage '{$stage}'.");
        }
        $this->simulatedFaultStage = $stage;
    }

    public function getSimulatedFaultStage(): ?string
    {
        return $this->simulatedFaultStage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLostAckSuppression(): ?array
    {
        return $this->lostAckSuppression;
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
     * Generates a private test CA and server TLS certificate with standard X.509 v3 extensions.
     * Enforces strict CA key usage (keyCertSign, cRLSign, basicConstraints CA:TRUE) and
     * server extended key usage (serverAuth, digitalSignature, keyEncipherment, SAN, basicConstraints CA:FALSE).
     *
     * @param  string  $outputDir  Directory where certificates and keys will be written
     * @return array{ca_cert_path: string, ca_key_path: string, server_cert_path: string, server_key_path: string}
     */
    public static function generateTestTlsCertificates(string $outputDir): array
    {
        $caCertPath = $outputDir.'/test-ca.pem';
        $caKeyPath = $outputDir.'/test-ca-key.pem';
        $serverCertPath = $outputDir.'/server-cert.pem';
        $serverKeyPath = $outputDir.'/server-key.pem';
        $serverCsrPath = $outputDir.'/server.csr';
        $serverExtPath = $outputDir.'/server.ext';

        $caKeyGen = new Process([
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048',
            '-keyout', $caKeyPath,
            '-out', $caCertPath,
            '-days', '1',
            '-nodes',
            '-subj', '/CN=HarnessTestCA/O=Integration/C=IL',
            '-addext', 'basicConstraints = critical, CA:TRUE',
            '-addext', 'keyUsage = critical, keyCertSign, cRLSign',
            '-addext', 'subjectKeyIdentifier = hash',
            '-addext', 'authorityKeyIdentifier = keyid:always,issuer',
        ]);
        $caKeyGen->run();
        if (! $caKeyGen->isSuccessful()) {
            throw new RuntimeException('Failed to generate test CA certificate: '.$caKeyGen->getErrorOutput());
        }
        chmod($caKeyPath, 0600);
        chmod($caCertPath, 0600);

        $serverKeyGen = new Process([
            'openssl', 'req', '-newkey', 'rsa:2048',
            '-keyout', $serverKeyPath,
            '-out', $serverCsrPath,
            '-nodes',
            '-subj', '/CN=localhost/O=Integration/C=IL',
        ]);
        $serverKeyGen->run();
        if (! $serverKeyGen->isSuccessful()) {
            throw new RuntimeException('Failed to generate server key/CSR: '.$serverKeyGen->getErrorOutput());
        }
        chmod($serverKeyPath, 0600);

        file_put_contents($serverExtPath, implode("\n", [
            '[server_ext]',
            'basicConstraints = critical, CA:FALSE',
            'keyUsage = critical, digitalSignature, keyEncipherment',
            'extendedKeyUsage = serverAuth',
            'subjectAltName = DNS:localhost,IP:127.0.0.1,IP:10.0.2.2',
            'subjectKeyIdentifier = hash',
            'authorityKeyIdentifier = keyid,issuer',
            '',
        ]));

        $certSign = new Process([
            'openssl', 'x509', '-req',
            '-in', $serverCsrPath,
            '-CA', $caCertPath,
            '-CAkey', $caKeyPath,
            '-CAcreateserial',
            '-out', $serverCertPath,
            '-days', '1',
            '-extensions', 'server_ext',
            '-extfile', $serverExtPath,
        ]);
        $certSign->run();
        if (! $certSign->isSuccessful()) {
            throw new RuntimeException('Failed to sign server certificate: '.$certSign->getErrorOutput());
        }
        chmod($serverCertPath, 0600);

        return [
            'ca_cert_path' => $caCertPath,
            'ca_key_path' => $caKeyPath,
            'server_cert_path' => $serverCertPath,
            'server_key_path' => $serverKeyPath,
        ];
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
     * 13. app tree hash — must match admitted QUALIFIED_APP_TREE_BASE or QUALIFIED_APP_TREE_G1
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

        // 13. Enforce qualified app tree match (must match admitted base tree or qualified G1 read-metadata tree)
        $revParseApp = new Process(['git', 'rev-parse', 'HEAD:app'], $root);
        $revParseApp->run();
        if (! $revParseApp->isSuccessful()) {
            throw new RuntimeException('Harness refused: failed to inspect HEAD:app tree.');
        }
        self::assertAdmittedAppTree(trim($revParseApp->getOutput()), $root);
    }

    /**
     * Asserts that an app tree hash matches either the qualified base tree (34d1...)
     * or the qualified G1 read-metadata tree (d584...).
     *
     * Invariants enforced:
     * - Only exact immutable SHAs QUALIFIED_APP_TREE_BASE or QUALIFIED_APP_TREE_G1 are admitted.
     * - If QUALIFIED_APP_TREE_G1 is presented, git diff vs QUALIFIED_APP_TREE_BASE must be strictly
     *   the single file 'Http/Controllers/Api/Mobile/V1/BootstrapController.php'.
     * - The blob hash of BootstrapController.php in that tree must match QUALIFIED_G1_BOOTSTRAP_CONTROLLER_BLOB.
     * - Any other tree SHA fails closed immediately; never admits arbitrary diffs or unknown trees.
     *
     * @param  string  $appTreeSha
     * @param  string|null  $root
     * @return string The admitted tree SHA
     *
     * @throws RuntimeException
     */
    public static function assertAdmittedAppTree(string $appTreeSha, ?string $root = null): string
    {
        if ($appTreeSha === self::QUALIFIED_APP_TREE_BASE) {
            return self::QUALIFIED_APP_TREE_BASE;
        }

        if ($appTreeSha === self::QUALIFIED_APP_TREE_G1) {
            $repoRoot = $root ?? dirname(__DIR__, 3);
            // Verify diff vs base tree is solely the G1 BootstrapController
            $diffProcess = new Process(
                ['git', 'diff', '--name-only', self::QUALIFIED_APP_TREE_BASE, self::QUALIFIED_APP_TREE_G1],
                $repoRoot
            );
            $diffProcess->run();
            if (! $diffProcess->isSuccessful()) {
                throw new RuntimeException('Harness refused: failed to verify git diff between qualified app trees.');
            }

            $diffOutput = trim($diffProcess->getOutput());
            if ($diffOutput !== 'Http/Controllers/Api/Mobile/V1/BootstrapController.php') {
                throw new RuntimeException("Harness refused: qualified G1 app tree diff contains unauthorized files: '{$diffOutput}'.");
            }

            // Verify blob hash of BootstrapController in the G1 tree matches immutable constant
            $revParseProcess = new Process(
                ['git', 'rev-parse', self::QUALIFIED_APP_TREE_G1.':Http/Controllers/Api/Mobile/V1/BootstrapController.php'],
                $repoRoot
            );
            $revParseProcess->run();
            if (! $revParseProcess->isSuccessful() || trim($revParseProcess->getOutput()) !== self::QUALIFIED_G1_BOOTSTRAP_CONTROLLER_BLOB) {
                throw new RuntimeException('Harness refused: G1 BootstrapController blob hash does not match qualified blob '.self::QUALIFIED_G1_BOOTSTRAP_CONTROLLER_BLOB);
            }

            return self::QUALIFIED_APP_TREE_G1;
        }

        throw new RuntimeException(
            "Harness refused: app tree '{$appTreeSha}' does not match qualified base tree (".self::QUALIFIED_APP_TREE_BASE.') or qualified G1 read-metadata tree ('.self::QUALIFIED_APP_TREE_G1.').'
        );
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

        // 3. Verify peer Android approved HEAD and peer Android executable HEAD
        $approvedHead = $manifestData['peer_android_approved_head_sha'] ?? $manifestData['peer_android_pr_head_sha'] ?? null;
        if (! is_string($approvedHead) || str_starts_with($approvedHead, 'UNSET_') || preg_match('/^[0-9a-fA-F]{40}$/', $approvedHead) !== 1) {
            throw new RuntimeException(
                "Harness refused: pairing manifest has unapproved peer Android HEAD ('".((string) $approvedHead)."'). ".
                'Pairing is held until client corrections are reviewed and approved by Root.'
            );
        }
        if ($approvedHead !== $peerAndroidHead) {
            throw new RuntimeException("Harness refused: peer Android HEAD '{$peerAndroidHead}' does not match manifest approved HEAD '{$approvedHead}'.");
        }
        $executableHead = $manifestData['peer_android_executable_head_sha'] ?? null;
        if (! is_string($executableHead) || str_starts_with($executableHead, 'UNSET_') || preg_match('/^[0-9a-fA-F]{40}$/', $executableHead) !== 1) {
            throw new RuntimeException(
                "Harness refused: pairing manifest has unset or invalid peer Android executable HEAD ('".((string) $executableHead)."'). ".
                'The tracked manifest is a source template. Root-approved private runtime pairing manifest must specify the actual peer Android executable HEAD.'
            );
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
        if (! $revParseApp->isSuccessful()) {
            throw new RuntimeException('Harness refused: failed to inspect HEAD:app tree.');
        }
        $admittedTree = self::assertAdmittedAppTree(trim($revParseApp->getOutput()), $root);

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
            'peer_android_approved_head_sha' => $approvedHead,
            'peer_android_executable_head_sha' => $executableHead,
            'qualified_tree' => $admittedTree,
            'ca5a_manifest_sha256' => self::CA5A_MANIFEST_SHA256,
            'ca5a_canonical_digest' => self::CA5A_CANONICAL_DIGEST,
            'manifest_sha256' => $actualManifestSha,
            'status' => (string) ($manifestData['status'] ?? ''),
        ];
    }

    /**
     * Validates that runtime pairing manifest authorizes readiness probing.
     * Fails closed if manifest scope is provision-only or unrecognized.
     *
     * @return array<string, mixed>
     */
    /**
     * Validates requested lifecycle execution mode against pairing manifest status.
     * Fails closed before kernel boot, schema migration, or socket startup.
     *
     * @param  string  $requestedMode  'provision' | 'readiness' | 'start'
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public static function assertLifecycleScope(
        ?string $manifestPath,
        ?string $manifestShaPin,
        string $requestedMode
    ): array {
        if ($manifestPath === null || $manifestPath === '' || ! file_exists($manifestPath)) {
            throw new RuntimeException("Harness {$requestedMode} scope refused: pairing manifest missing.");
        }

        if ($manifestShaPin === null || $manifestShaPin === '') {
            throw new RuntimeException("Harness {$requestedMode} scope refused: mandatory manifest SHA-256 pin required.");
        }

        $actualSha = (string) hash_file('sha256', $manifestPath);
        if (! hash_equals($manifestShaPin, $actualSha)) {
            throw new RuntimeException("Harness {$requestedMode} scope refused: manifest SHA mismatch. Expected '{$manifestShaPin}', got '{$actualSha}'.");
        }

        $manifestData = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifestData)) {
            throw new RuntimeException("Harness {$requestedMode} scope refused: manifest is malformed JSON.");
        }

        $status = (string) ($manifestData['status'] ?? '');

        if ($requestedMode === 'start') {
            if ($status === self::SCOPE_PROVISION_ONLY || str_contains($status, 'PROVISION_ONLY')) {
                throw new RuntimeException("Harness start refused: pairing manifest status is '{$status}'. Provision-only manifests are strictly forbidden from starting listeners or TLS proxies.");
            }
            if ($status === self::SCOPE_READINESS_ONLY) {
                throw new RuntimeException("Harness start refused: pairing manifest status is '{$status}'. Non-readiness start requires exact '".self::SCOPE_PAIRED_EXECUTION."' or '".self::SCOPE_ADVANCED_EXECUTION."' scope.");
            }
            if ($status !== self::SCOPE_PAIRED_EXECUTION && $status !== self::SCOPE_ADVANCED_EXECUTION) {
                throw new RuntimeException("Harness start refused: unapproved manifest status '{$status}'. Non-readiness start requires exact '".self::SCOPE_PAIRED_EXECUTION."' or '".self::SCOPE_ADVANCED_EXECUTION."' scope.");
            }
        } elseif ($requestedMode === 'readiness') {
            if ($status === self::SCOPE_PROVISION_ONLY || str_contains($status, 'PROVISION_ONLY')) {
                throw new RuntimeException("Harness readiness scope refused: pairing manifest status is '{$status}'. Provision-only manifests are strictly forbidden from starting listeners or TLS proxies.");
            }
            if ($status !== self::SCOPE_READINESS_ONLY && $status !== self::SCOPE_PAIRED_EXECUTION && $status !== self::SCOPE_ADVANCED_EXECUTION) {
                throw new RuntimeException("Harness readiness scope refused: unapproved manifest status '{$status}'. Readiness start requires exact '".self::SCOPE_READINESS_ONLY."', '".self::SCOPE_PAIRED_EXECUTION."', or '".self::SCOPE_ADVANCED_EXECUTION."' scope.");
            }
        } elseif ($requestedMode === 'provision') {
            if ($status !== self::SCOPE_PROVISION_ONLY && $status !== self::SCOPE_READINESS_ONLY && $status !== self::SCOPE_PAIRED_EXECUTION && $status !== self::SCOPE_ADVANCED_EXECUTION) {
                throw new RuntimeException("Harness provision scope refused: unapproved manifest status '{$status}'.");
            }
        } else {
            throw new InvalidArgumentException("Unknown requested lifecycle mode '{$requestedMode}'.");
        }

        return $manifestData;
    }

    /**
     * Validates that runtime pairing manifest authorizes readiness probing.
     * Fails closed if manifest scope is provision-only or unrecognized.
     *
     * @return array<string, mixed>
     */
    public static function assertReadinessScope(?string $manifestPath, ?string $manifestShaPin): array
    {
        return self::assertLifecycleScope($manifestPath, $manifestShaPin, 'readiness');
    }

    /**
     * Asserts that readiness hold duration is an integer within bounds (0..120 seconds).
     *
     * @param  mixed  $seconds
     * @return int
     *
     * @throws InvalidArgumentException
     */
    public static function assertReadinessHoldBounds(mixed $seconds): int
    {
        if (is_int($seconds)) {
            if ($seconds < 0 || $seconds > self::MAX_READINESS_HOLD_SECONDS) {
                throw new InvalidArgumentException("Readiness hold seconds must be between 0 and ".self::MAX_READINESS_HOLD_SECONDS.", got {$seconds}.");
            }

            return $seconds;
        }

        if (is_string($seconds)) {
            $isIntegerString = $seconds !== '' && (ctype_digit($seconds) || (str_starts_with($seconds, '-') && ctype_digit(substr($seconds, 1))));
            if (! $isIntegerString) {
                throw new InvalidArgumentException("Readiness hold seconds must be an integer string, got '{$seconds}'.");
            }
            $val = (int) $seconds;
            if ($val < 0 || $val > self::MAX_READINESS_HOLD_SECONDS) {
                throw new InvalidArgumentException("Readiness hold seconds must be between 0 and ".self::MAX_READINESS_HOLD_SECONDS.", got {$val}.");
            }

            return $val;
        }

        throw new InvalidArgumentException('Readiness hold seconds must be an integer or integer string.');
    }

    /**
     * Asserts that paired hold duration is an integer within bounds (1..120 seconds).
     *
     * @param  mixed  $seconds
     * @return int
     *
     * @throws InvalidArgumentException
     */
    public static function assertPairedHoldBounds(mixed $seconds): int
    {
        if (is_int($seconds)) {
            if ($seconds < self::MIN_PAIRED_HOLD_SECONDS || $seconds > self::MAX_PAIRED_HOLD_SECONDS) {
                throw new InvalidArgumentException("Paired hold seconds must be between ".self::MIN_PAIRED_HOLD_SECONDS." and ".self::MAX_PAIRED_HOLD_SECONDS.", got {$seconds}.");
            }

            return $seconds;
        }

        if (is_string($seconds)) {
            $isIntegerString = $seconds !== '' && (ctype_digit($seconds) || (str_starts_with($seconds, '-') && ctype_digit(substr($seconds, 1))));
            if (! $isIntegerString) {
                throw new InvalidArgumentException("Paired hold seconds must be an integer string, got '{$seconds}'.");
            }
            $val = (int) $seconds;
            if ($val < self::MIN_PAIRED_HOLD_SECONDS || $val > self::MAX_PAIRED_HOLD_SECONDS) {
                throw new InvalidArgumentException("Paired hold seconds must be between ".self::MIN_PAIRED_HOLD_SECONDS." and ".self::MAX_PAIRED_HOLD_SECONDS.", got {$val}.");
            }

            return $val;
        }

        throw new InvalidArgumentException('Paired hold seconds must be an integer or integer string.');
    }

    /**
     * Asserts that pcntl signal handling functions are available for readiness hold.
     * Fails closed before framework boot, database connection, or socket opening.
     *
     * @param  int  $holdSeconds
     * @param  (callable(string): bool)|null  $functionExistsChecker  Optional seam for testing
     *
     * @throws RuntimeException
     */
    public static function assertPcntlAvailableForHold(int $holdSeconds, ?callable $functionExistsChecker = null): void
    {
        if ($holdSeconds <= 0) {
            return;
        }

        $checker = $functionExistsChecker ?? 'function_exists';
        $required = ['pcntl_signal', 'pcntl_signal_dispatch'];
        foreach ($required as $fn) {
            if (! $checker($fn)) {
                throw new RuntimeException("Harness refused: --readiness-hold-seconds requires pcntl extension function '{$fn}'.");
            }
        }
    }

    /**
     * Asserts that pcntl signal handling functions are available for paired hold.
     * Fails closed before framework boot, database connection, or socket opening.
     *
     * @param  int  $holdSeconds
     * @param  (callable(string): bool)|null  $functionExistsChecker  Optional seam for testing
     *
     * @throws RuntimeException
     */
    public static function assertPcntlAvailableForPairedHold(int $holdSeconds, ?callable $functionExistsChecker = null): void
    {
        if ($holdSeconds <= 0) {
            return;
        }

        $checker = $functionExistsChecker ?? 'function_exists';
        $required = ['pcntl_signal', 'pcntl_signal_dispatch'];
        foreach ($required as $fn) {
            if (! $checker($fn)) {
                throw new RuntimeException("Harness refused: --paired-hold-seconds requires pcntl extension function '{$fn}'.");
            }
        }
    }

    /**
     * Registers early signal handlers for SIGINT and SIGTERM before starting transport or provision.
     *
     * @param  callable(int): void  $handler
     * @param  (callable(callable(int): void): bool)|null  $registrationSeam  Optional seam for testing
     * @return bool True if both signals registered successfully
     */
    public static function registerEarlyHoldSignalHandlers(callable $handler, ?callable $registrationSeam = null): bool
    {
        if ($registrationSeam !== null) {
            return (bool) $registrationSeam($handler);
        }

        if (! function_exists('pcntl_signal')) {
            return false;
        }

        $intOk = @pcntl_signal(SIGINT, $handler);
        $termOk = @pcntl_signal(SIGTERM, $handler);

        return $intOk && $termOk;
    }

    /**
     * Authoritative handler for child process readiness probe route.
     * Enforces GET-only access (returning 405 for all other HTTP methods).
     * Enforces readiness-only boundary pre-framework (returning 403 for non-readiness routes).
     *
     * @return array{status_code: int, headers: array<int, string>, body: array<string, mixed>}|null
     */
    public static function handleChildReadinessRoute(string $uri, string $method, string $nonce, bool $readinessOnly): ?array
    {
        if ($uri === '/_harness/readiness') {
            if ($method !== 'GET') {
                return [
                    'status_code' => 405,
                    'headers' => ['Allow: GET', 'Content-Type: application/json'],
                    'body' => ['error' => 'method_not_allowed', 'message' => 'Readiness probe strictly requires GET'],
                ];
            }

            return [
                'status_code' => 200,
                'headers' => ['Content-Type: application/json'],
                'body' => ['status' => 'ready', 'nonce' => $nonce],
            ];
        }

        if ($readinessOnly) {
            return [
                'status_code' => 403,
                'headers' => ['Content-Type: application/json'],
                'body' => [
                    'error' => 'forbidden',
                    'message' => 'Harness is running in READINESS-ONLY mode. Financial endpoints and paired requests are strictly blocked.',
                ],
            ];
        }

        return null;
    }

    /**
     * Generates the child HTTP bootstrap script for php -S subprocess.
     * Enforces:
     * - Config existence, permissions (0600 file, 0700 dir, UID match) and nonce validation
     * - Safe environment population
     * - Autoload-only require BEFORE handleChildReadinessRoute
     * - Pre-framework child readiness probe route (GET-only 200/405, readiness-only 403)
     * - Disposable schema ownership proof assertPrimarySchema BEFORE framework or PDO
     * - Isolated DB and port invariant assertions
     */
    public static function generateChildBootstrapScript(
        string $configPath,
        string $bootstrapDir,
        string $nonce,
        string $root,
        string $testEnvPath
    ): string {
        $envBasename = basename($testEnvPath);

        return <<<PHP
<?php

declare(strict_types=1);

// Test-only HTTP bootstrap for paired Mobile V2 HTTPS integration
// Cash-only harness: zero static file passthrough before guards

\$configPath = '{$configPath}';
\$bootstrapDir = '{$bootstrapDir}';
\$root = '{$root}';

if (! file_exists(\$configPath)) {
    http_response_code(500);
    echo "Harness bootstrap refused: missing child bootstrap config.\n";
    exit(1);
}

// Validate file permissions (0600) and parent dir (0700)
\$filePerms = fileperms(\$configPath) & 0777;
\$dirPerms = fileperms(\$bootstrapDir) & 0777;
if (\$filePerms !== 0600 || \$dirPerms !== 0700) {
    http_response_code(500);
    echo "Harness bootstrap refused: invalid config file or directory permissions.\n";
    exit(1);
}

if (function_exists('posix_getuid') && fileowner(\$configPath) !== posix_getuid()) {
    http_response_code(500);
    echo "Harness bootstrap refused: file owner UID mismatch.\n";
    exit(1);
}

\$rawConfig = file_get_contents(\$configPath);
\$config = json_decode(\$rawConfig ?: '', true);
if (! is_array(\$config) || (\$config['harness_nonce'] ?? null) !== '{$nonce}') {
    http_response_code(500);
    echo "Harness bootstrap refused: invalid config or nonce mismatch.\n";
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

require_once \$root.'/vendor/autoload.php';

// Harness readiness probe route
\$uri = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
\$method = \$_SERVER['REQUEST_METHOD'] ?? 'GET';
\$routeResult = \\Tests\\Support\\Harness\\DisposableMobileV2BackendHarness::handleChildReadinessRoute(
    uri: \$uri,
    method: \$method,
    nonce: (string) \$config['harness_nonce'],
    readinessOnly: ! empty(\$config['readiness_only'])
);
if (\$routeResult !== null) {
    http_response_code(\$routeResult['status_code']);
    foreach (\$routeResult['headers'] as \$hdr) {
        header(\$hdr);
    }
    echo json_encode(\$routeResult['body']);
    exit(0);
}

// Assert real disposable MariaDB schema ownership proof BEFORE framework or PDO!
\\Tests\\Support\\DisposableMariaDbSchema::assertPrimarySchema((string) \$config['schema_name']);

/** @var \\Illuminate\\Foundation\\Application \$app */
\$app = require \$root.'/bootstrap/app.php';
\$app->useEnvironmentPath(\$bootstrapDir);
\$app->loadEnvironmentFrom('{$envBasename}');

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

    if (! empty(\$config['simulated_fault_stage'])) {
        \\App\\Actions\\Sales\\ExecuteImmediateOnlineCashSaleAction::\$simulatedFaultStage = (string) \$config['simulated_fault_stage'];
    }
});

\$kernel = \$app->make(\\Illuminate\\Contracts\\Http\\Kernel::class);
\$response = \$kernel->handle(
    \$request = \\Illuminate\\Http\\Request::capture()
);

\$response->send();
\$kernel->terminate(\$request, \$response);
PHP;
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
     * @param  array<string, mixed>  $rawWireSample
     * @param  array<string, mixed>  $serverContext
     * @param  array<string, string>|null  $historicalCa5a
     * @param  array<string, mixed>|null  $startupTls
     * @return array<string, mixed>
     */
    public static function buildClientHandoff(
        array $runtimePins,
        array $fixture,
        string $credentialsPath,
        array $rawWireSample,
        array $serverContext,
        string $canonicalDigest,
        string $handoffScope,
        string $lifecyclePhase,
        string $createdAt,
        string $expiresAt,
        ?array $historicalCa5a = null,
        ?array $startupTls = null
    ): array {
        if ($createdAt === '' || $expiresAt === '') {
            throw new InvalidArgumentException('Created at and expires at timestamps are strictly required with no defaults.');
        }

        $pinKeys = [
            'qualified_core_head_sha',
            'backend_execution_head_sha',
            'qualified_app_tree_sha',
            'peer_android_approved_head_sha',
            'peer_android_executable_head_sha',
            'client_handoff_schema_sha256',
            'source_fixture_sha256',
            'guard_test_sha256',
            'cli_command_sha256',
            'cli_runner_sha256',
            'pairing_manifest_sha256',
        ];
        $pins = [];
        foreach ($pinKeys as $pk) {
            if (! isset($runtimePins[$pk]) || (string) $runtimePins[$pk] === '') {
                throw new InvalidArgumentException("Missing mandatory runtime pin '{$pk}' with zero defaults.");
            }
            $pins[$pk] = (string) $runtimePins[$pk];
        }

        return [
            'schema_version' => '1.0.0',
            'handoff_scope' => $handoffScope,
            'lifecycle_phase' => $lifecyclePhase,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'backend_runtime_pins' => $pins,
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
            'raw_wire_sample' => $rawWireSample,
            'server_synthesized_context' => $serverContext,
            'canonical_intent_profile_digest' => $canonicalDigest,
            'historical_ca5a_reference' => $historicalCa5a ?? [
                'ca5a_manifest_sha256' => self::CA5A_MANIFEST_SHA256,
                'ca5a_canonical_digest' => self::CA5A_CANONICAL_DIGEST,
            ],
            'startup_tls' => $startupTls ?? [
                'status' => 'UNAVAILABLE_AWAITING_TLS_START',
                'ca_cert_path' => null,
                'ca_cert_sha256' => null,
                'readiness_url' => null,
                'android_readiness_url' => null,
                'readiness_nonce' => null,
            ],
        ];
    }

    /**
     * Validates that a client handoff array strictly adheres to the schema.
     * Fails closed if any required property, pin, or structure is missing or malformed.
     *
     * @param  array<string, mixed>  $handoff
     *
     * @throws RuntimeException
     */
    public static function validateClientHandoff(array $handoff): void
    {
        $requiredTop = [
            'schema_version', 'handoff_scope', 'lifecycle_phase', 'created_at', 'expires_at',
            'backend_runtime_pins', 'endpoints', 'fixture', 'credentials_path',
            'raw_wire_sample', 'server_synthesized_context',
            'canonical_intent_profile_digest', 'historical_ca5a_reference', 'startup_tls',
        ];
        foreach ($requiredTop as $field) {
            if (! array_key_exists($field, $handoff)) {
                throw new RuntimeException("Malformed client handoff artifact: missing top-level field '{$field}'.");
            }
        }
        if ($handoff['schema_version'] !== '1.0.0') {
            throw new RuntimeException("Malformed client handoff artifact: unsupported schema_version '{$handoff['schema_version']}'.");
        }
        $validScopes = [
            self::SCOPE_PROVISION_ONLY,
            self::SCOPE_READINESS_ONLY,
            self::SCOPE_PAIRED_EXECUTION,
            self::SCOPE_ADVANCED_EXECUTION,
            'mobile_v2_online_cash_sale',
        ];
        if (! in_array($handoff['handoff_scope'], $validScopes, true)) {
            throw new RuntimeException("Malformed client handoff artifact: invalid handoff_scope '{$handoff['handoff_scope']}'.");
        }
        $validPhases = ['PROVISIONED_AWAITING_TLS_START', 'ACTIVE_TLS_READY', 'READINESS_ONLY_VERIFIED'];
        if (! in_array($handoff['lifecycle_phase'], $validPhases, true)) {
            throw new RuntimeException("Malformed client handoff artifact: invalid lifecycle_phase '{$handoff['lifecycle_phase']}'.");
        }
        $isoPattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';
        if (! is_string($handoff['created_at']) || preg_match($isoPattern, $handoff['created_at']) !== 1) {
            throw new RuntimeException('Malformed client handoff artifact: created_at must be valid ISO 8601 timestamp.');
        }
        if (! is_string($handoff['expires_at']) || preg_match($isoPattern, $handoff['expires_at']) !== 1) {
            throw new RuntimeException('Malformed client handoff artifact: expires_at must be valid ISO 8601 timestamp.');
        }
        $createdTs = strtotime($handoff['created_at']);
        $expiresTs = strtotime($handoff['expires_at']);
        if ($createdTs === false || $expiresTs === false || $expiresTs <= $createdTs) {
            throw new RuntimeException('Malformed client handoff artifact: expires_at must be strictly after created_at.');
        }
        $pins = $handoff['backend_runtime_pins'];
        if (! is_array($pins)) {
            throw new RuntimeException('Malformed client handoff artifact: backend_runtime_pins must be an array.');
        }
        $sha40Pins = [
            'qualified_core_head_sha',
            'backend_execution_head_sha',
            'qualified_app_tree_sha',
            'peer_android_approved_head_sha',
            'peer_android_executable_head_sha',
        ];
        $sha256Pins = [
            'client_handoff_schema_sha256',
            'source_fixture_sha256',
            'guard_test_sha256',
            'cli_command_sha256',
            'cli_runner_sha256',
            'pairing_manifest_sha256',
        ];
        foreach ($sha40Pins as $pin) {
            if (! isset($pins[$pin]) || ! is_string($pins[$pin]) || preg_match('/^[0-9a-f]{40}$/', $pins[$pin]) !== 1) {
                throw new RuntimeException("Malformed client handoff artifact: pin '{$pin}' must be a valid lowercase 40-hex SHA.");
            }
        }
        foreach ($sha256Pins as $pin) {
            if (! isset($pins[$pin]) || ! is_string($pins[$pin]) || preg_match('/^[0-9a-f]{64}$/', $pins[$pin]) !== 1) {
                throw new RuntimeException("Malformed client handoff artifact: pin '{$pin}' must be a valid lowercase 64-hex SHA-256.");
            }
        }
        if ($pins['qualified_core_head_sha'] !== self::QUALIFIED_EXECUTION_HEAD) {
            throw new RuntimeException('Malformed client handoff artifact: qualified_core_head_sha mismatch vs constant.');
        }
        if ($pins['qualified_app_tree_sha'] !== self::QUALIFIED_APP_TREE_BASE && $pins['qualified_app_tree_sha'] !== self::QUALIFIED_APP_TREE_G1) {
            throw new RuntimeException('Malformed client handoff artifact: qualified_app_tree_sha mismatch vs admitted constants.');
        }
        if ($pins['peer_android_approved_head_sha'] === $pins['peer_android_executable_head_sha']) {
            throw new RuntimeException('Malformed client handoff artifact: peer_android_approved_head_sha and peer_android_executable_head_sha must be distinct publication vs executable SHAs.');
        }
        $endpoints = $handoff['endpoints'];
        if (! is_array($endpoints) || empty($endpoints['base_url']) || empty($endpoints['loopback_base_url']) || ! is_array($endpoints['routes'] ?? null)) {
            throw new RuntimeException('Malformed client handoff artifact: endpoints structure missing or invalid.');
        }
        if (! filter_var($endpoints['base_url'], FILTER_VALIDATE_URL) || ! filter_var($endpoints['loopback_base_url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Malformed client handoff artifact: endpoint URLs must be valid URIs.');
        }
        if (! is_string($handoff['credentials_path']) || preg_match('/^\/.+\.json$/', $handoff['credentials_path']) !== 1) {
            throw new RuntimeException('Malformed client handoff artifact: credentials_path must be an absolute json path outside checkout.');
        }
        $rawWire = $handoff['raw_wire_sample'];
        if (! is_array($rawWire)
            || empty($rawWire['raw_wire_payload_json'])
            || empty($rawWire['raw_wire_payload_sha256'])
            || empty($rawWire['parsed_request'])
            || ! is_array($rawWire['parsed_request'])
        ) {
            throw new RuntimeException('Malformed client handoff artifact: raw_wire_sample must contain raw_wire_payload_json, raw_wire_payload_sha256, and parsed_request.');
        }
        $wireJson = (string) $rawWire['raw_wire_payload_json'];
        $wireSha = (string) $rawWire['raw_wire_payload_sha256'];
        if (preg_match('/^[0-9a-f]{64}$/', $wireSha) !== 1) {
            throw new RuntimeException('Malformed client handoff artifact: raw_wire_payload_sha256 must be a 64-hex SHA-256.');
        }
        $calculatedWireSha = hash('sha256', $wireJson);
        if (! hash_equals($calculatedWireSha, $wireSha)) {
            throw new RuntimeException("Malformed client handoff artifact: raw_wire_sample SHA-256 mismatch. Expected '{$calculatedWireSha}', got '{$wireSha}'.");
        }
        $canonicalWireExpected = CanonicalJsonSerializer::serialize($rawWire['parsed_request']);
        if ($wireJson !== $canonicalWireExpected) {
            throw new RuntimeException('Malformed client handoff artifact: raw_wire_payload_json must be strictly serialized in authoritative RFC 8785 JCS canonical JSON.');
        }
        $ctx = $handoff['server_synthesized_context'];
        if (! is_array($ctx)
            || empty($ctx['company_id'])
            || empty($ctx['actor_id'])
            || ! isset($ctx['currency_minor_units'])
            || ! is_int($ctx['currency_minor_units'])
            || $ctx['currency_minor_units'] < 0
            || $ctx['currency_minor_units'] > 6
            || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', (string) $ctx['company_id']) !== 1
            || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', (string) $ctx['actor_id']) !== 1
        ) {
            throw new RuntimeException('Malformed client handoff artifact: server_synthesized_context missing or invalid.');
        }
        $payment = $rawWire['parsed_request']['payment'] ?? null;
        if (! is_array($payment)
            || ($payment['settlement_mode'] ?? null) !== 'full'
            || ($payment['payment_method'] ?? null) !== 'cash'
            || empty($payment['cash_account_id'])
            || ! is_string($payment['cash_account_id'])
            || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $payment['cash_account_id']) !== 1
            || empty($payment['amount'])
            || ! is_string($payment['amount'])
        ) {
            throw new RuntimeException('Malformed client handoff artifact: payment must restrict to settlement_mode=full, payment_method=cash, non-null cash_account_id ULID, and valid amount.');
        }

        if (! is_string($handoff['canonical_intent_profile_digest'])
            || ! preg_match('/^sha256:[0-9a-f]{64}$/', $handoff['canonical_intent_profile_digest'])
        ) {
            throw new RuntimeException('Malformed client handoff artifact: canonical_intent_profile_digest must match sha256:<64hex>.');
        }
        $recomputedProfile = OnlineSaleIntentProfile::construct($ctx, $rawWire['parsed_request']);
        $recomputedDigest = OnlineSaleIntentProfile::computeDigest($recomputedProfile);
        if (! hash_equals($recomputedDigest, $handoff['canonical_intent_profile_digest'])) {
            throw new RuntimeException("Malformed client handoff artifact: canonical_intent_profile_digest mismatch vs recomputed intent profile digest. Expected '{$recomputedDigest}', got '{$handoff['canonical_intent_profile_digest']}'.");
        }
        $hist = $handoff['historical_ca5a_reference'];
        if (! is_array($hist)
            || ($hist['ca5a_manifest_sha256'] ?? null) !== self::CA5A_MANIFEST_SHA256
            || ($hist['ca5a_canonical_digest'] ?? null) !== self::CA5A_CANONICAL_DIGEST
        ) {
            throw new RuntimeException('Malformed client handoff artifact: historical_ca5a_reference missing or invalid.');
        }
        $tls = $handoff['startup_tls'];
        if (! is_array($tls) || empty($tls['status'])) {
            throw new RuntimeException('Malformed client handoff artifact: startup_tls missing or status unset.');
        }
        $allowedTlsStatuses = ['UNAVAILABLE_AWAITING_TLS_START', 'ACTIVE_TLS_READY', 'READINESS_ONLY_VERIFIED'];
        if (! in_array($tls['status'], $allowedTlsStatuses, true)) {
            throw new RuntimeException("Malformed client handoff artifact: unknown or invalid startup_tls status '{$tls['status']}'.");
        }
        if ($tls['status'] === 'UNAVAILABLE_AWAITING_TLS_START') {
            if ($tls['ca_cert_path'] !== null
                || $tls['ca_cert_sha256'] !== null
                || $tls['readiness_url'] !== null
                || ($tls['android_readiness_url'] ?? null) !== null
                || $tls['readiness_nonce'] !== null
            ) {
                throw new RuntimeException('Malformed client handoff artifact: startup_tls fields must be null when awaiting start.');
            }
        } elseif ($tls['status'] === 'ACTIVE_TLS_READY' || $tls['status'] === 'READINESS_ONLY_VERIFIED') {
            if (empty($tls['ca_cert_path'])
                || empty($tls['ca_cert_sha256'])
                || ! is_string($tls['ca_cert_sha256'])
                || preg_match('/^[0-9a-f]{64}$/', $tls['ca_cert_sha256']) !== 1
                || empty($tls['readiness_url'])
                || ! is_string($tls['readiness_url'])
                || ! str_starts_with($tls['readiness_url'], 'https://127.0.0.1:')
                || empty($tls['android_readiness_url'])
                || ! is_string($tls['android_readiness_url'])
                || ! str_starts_with($tls['android_readiness_url'], 'https://10.0.2.2:')
                || empty($tls['readiness_nonce'])
                || ! is_string($tls['readiness_nonce'])
                || preg_match('/^[0-9a-f]{32,64}$/', $tls['readiness_nonce']) !== 1
            ) {
                throw new RuntimeException('Malformed client handoff artifact: active startup_tls requires valid ca_cert_path, ca_cert_sha256, readiness_url (127.0.0.1), android_readiness_url (10.0.2.2), and readiness_nonce.');
            }
        } else {
            throw new RuntimeException("Malformed client handoff artifact: unhandled startup_tls status '{$tls['status']}'.");
        }
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
        $manifestInfo = self::assertDualManifestPin($expectedBackendHead, $peerAndroidHead, $manifestPath, $manifestShaPin);
        self::assertLifecycleScope($manifestPath, $manifestShaPin, 'provision');

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

            $currencyCode = (string) $company->base_currency_code;
            $minorUnits = app(CurrencyMinorUnitsResolver::class)->resolve($currencyCode);

            $this->companyId = (int) $company->id;
            $this->companyPublicId = (string) $company->public_id;

            $this->fixtureManifest = [
                'schema_name' => $this->schema->schemaName(),
                'company_public_id' => (string) $company->public_id,
                'company_base_currency' => $currencyCode,
                'currency_minor_units' => $minorUnits,
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
                'currency_minor_units' => $minorUnits,
            ];

            $profile = OnlineSaleIntentProfile::construct($serverContext, $sampleRequest);
            $canonicalDigest = OnlineSaleIntentProfile::computeDigest($profile);

            $rawWireJson = CanonicalJsonSerializer::serialize($sampleRequest);
            $rawWireSha256 = hash('sha256', $rawWireJson);
            $rawWireSample = [
                'raw_wire_payload_json' => $rawWireJson,
                'raw_wire_payload_sha256' => $rawWireSha256,
                'parsed_request' => $sampleRequest,
            ];
            $root = dirname(__DIR__, 3);
            $runtimePins = [
                'qualified_core_head_sha' => self::QUALIFIED_EXECUTION_HEAD,
                'backend_execution_head_sha' => $expectedBackendHead,
                'qualified_app_tree_sha' => $manifestInfo['qualified_tree'] ?? self::QUALIFIED_APP_TREE_BASE,
                'peer_android_approved_head_sha' => $manifestInfo['peer_android_approved_head_sha'],
                'peer_android_executable_head_sha' => $manifestInfo['peer_android_executable_head_sha'],
                'client_handoff_schema_sha256' => (string) hash_file('sha256', $root.'/docs/integration/mobile-v2-client-handoff-schema.json'),
                'source_fixture_sha256' => (string) hash_file('sha256', __FILE__),
                'guard_test_sha256' => (string) hash_file('sha256', $root.'/tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php'),
                'cli_command_sha256' => (string) hash_file('sha256', __DIR__.'/MobileV2HarnessCommand.php'),
                'cli_runner_sha256' => (string) hash_file('sha256', __DIR__.'/mobile-v2-harness-cli.php'),
                'pairing_manifest_sha256' => (string) $manifestShaPin,
            ];
            $manifestStatus = (string) ($manifestInfo['status'] ?? '');
            if ($manifestStatus === self::SCOPE_PROVISION_ONLY || str_contains($manifestStatus, 'PROVISION_ONLY')) {
                $authorizedScope = self::SCOPE_PROVISION_ONLY;
            } elseif ($this->readinessOnly || $manifestStatus === self::SCOPE_READINESS_ONLY) {
                $authorizedScope = self::SCOPE_READINESS_ONLY;
            } elseif ($manifestStatus === self::SCOPE_PAIRED_EXECUTION) {
                $authorizedScope = self::SCOPE_PAIRED_EXECUTION;
            } elseif ($manifestStatus === self::SCOPE_ADVANCED_EXECUTION) {
                $authorizedScope = self::SCOPE_ADVANCED_EXECUTION;
            } else {
                $authorizedScope = self::SCOPE_PROVISION_ONLY;
            }
            $this->clientHandoff = self::buildClientHandoff(
                runtimePins: $runtimePins,
                fixture: $this->fixtureManifest,
                credentialsPath: $this->credentialsFilePath,
                rawWireSample: $rawWireSample,
                serverContext: $serverContext,
                canonicalDigest: $canonicalDigest,
                handoffScope: $authorizedScope,
                lifecyclePhase: 'PROVISIONED_AWAITING_TLS_START',
                createdAt: now()->toIso8601String(),
                expiresAt: now()->addHours(1)->toIso8601String(),
            );
            self::validateClientHandoff($this->clientHandoff);

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

        // Dual manifest pin and lifecycle scope assertion before ANY socket or process creation
        $manifestInfo = self::assertDualManifestPin($backendHead, $peerAndroidHead, $manifestPath, $manifestShaPin);
        self::assertLifecycleScope($manifestPath, $manifestShaPin, $this->readinessOnly ? 'readiness' : 'start');

        $isSuppressionActive = $this->lostAckSuppression !== null && ! empty($this->lostAckSuppression['enabled']);

        // Preflight loopback ports ONLY AFTER ALL gates and manifest assertions have passed
        if ($isSuppressionActive) {
            self::assertPortsAvailable(self::BACKEND_LOOPBACK_PORT, self::TLS_PROXY_PORT, self::BACKEND_INTERNAL_PORT);
        } else {
            self::assertPortsAvailable(self::BACKEND_LOOPBACK_PORT, self::TLS_PROXY_PORT);
        }

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
        $childConfigPayload = [
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
        ];
        if ($this->lostAckSuppression !== null) {
            $childConfigPayload['lost_ack_suppression'] = $this->lostAckSuppression;
        }
        if ($this->simulatedFaultStage !== null) {
            $childConfigPayload['simulated_fault_stage'] = $this->simulatedFaultStage;
        }
        file_put_contents($childConfigPath, json_encode($childConfigPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
        $httpBootstrapContent = self::generateChildBootstrapScript(
            configPath: $childConfigPath,
            bootstrapDir: $bootstrapDir,
            nonce: $nonce,
            root: $root,
            testEnvPath: $testEnvPath,
        );
        file_put_contents($httpBootstrapPath, $httpBootstrapContent);
        chmod($httpBootstrapPath, 0600);

        // Generate private test TLS CA and server cert with standard X.509 v3 extensions
        $certs = self::generateTestTlsCertificates($bootstrapDir);
        $caCertPath = $certs['ca_cert_path'];
        $caKeyPath = $certs['ca_key_path'];
        $serverCertPath = $certs['server_cert_path'];
        $serverKeyPath = $certs['server_key_path'];

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
            $backendPort = $isSuppressionActive ? self::BACKEND_INTERNAL_PORT : self::BACKEND_LOOPBACK_PORT;
            // Launch supervised PHP HTTP backend
            $this->backendProcess = new Process(
                [PHP_BINARY, '-S', '127.0.0.1:'.$backendPort, $httpBootstrapPath],
                $root,
                $serveEnv,
            );
            $this->backendProcess->start();

            // Verify backend readiness via active bounded HTTP probe on backend port
            $this->verifyBackendReadiness($nonce, 5.0, $backendPort);

            // If suppression active, launch test transport proxy mediating on BACKEND_LOOPBACK_PORT
            if ($isSuppressionActive) {
                if ($this->companyId === null || $this->companyId <= 0) {
                    throw new RuntimeException('Transport proxy start refused: harness companyId is uninitialized.');
                }
                if ($this->companyPublicId === null || $this->companyPublicId === '') {
                    throw new RuntimeException('Transport proxy start refused: harness companyPublicId is uninitialized.');
                }
                if ($this->proofPath === null || $this->proofNonce === null) {
                    throw new RuntimeException('Transport proxy start refused: harness DB ownership proof is uninitialized.');
                }

                $dbCredentialsPath = $bootstrapDir.'/proxy_db_credentials.json';
                file_put_contents($dbCredentialsPath, json_encode([
                    'db_user' => $dbSettings['USERNAME'],
                    'db_password' => $dbSettings['PASSWORD'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                chmod($dbCredentialsPath, 0600);

                $targetNonce = (string) ($this->lostAckSuppression['target_nonce'] ?? '');
                $targetNonceHash = (string) ($this->lostAckSuppression['target_nonce_hash'] ?? hash('sha256', $targetNonce));
                $expectedDigest = (string) ($this->lostAckSuppression['expected_digest'] ?? '');
                $handoffSha = ($this->handoffFilePath !== null && is_file($this->handoffFilePath))
                    ? hash_file('sha256', $this->handoffFilePath)
                    : '';
                $manifestSha = $manifestShaPin ?? ($this->lostAckSuppression['manifest_sha256'] ?? '');
                if ($manifestSha === '' && $manifestPath !== null && is_file($manifestPath)) {
                    $manifestSha = hash_file('sha256', $manifestPath);
                }

                $proxyConfigFile = $bootstrapDir.'/test_transport_proxy_config.json';
                file_put_contents($proxyConfigFile, json_encode([
                    'scope' => self::SCOPE_ADVANCED_EXECUTION,
                    'owner_pid' => getmypid(),
                    'ttl_expires_at' => (new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                    'target_event_id' => $this->lostAckSuppression['target_event_id'] ?? '',
                    'target_schema' => $schemaName,
                    'target_company_id' => $this->companyId,
                    'target_company_public_id' => $this->companyPublicId,
                    'target_nonce_hash' => $targetNonceHash,
                    'expected_digest' => $expectedDigest,
                    'credentials_file' => $dbCredentialsPath,
                    'proof_path' => $this->proofPath,
                    'proof_nonce' => $this->proofNonce,
                    'witness_path' => $bootstrapDir.'/lost_ack_suppression_witness.json',
                    'listen_port' => self::BACKEND_LOOPBACK_PORT,
                    'upstream_port' => self::BACKEND_INTERNAL_PORT,
                    'db_host' => '127.0.0.1',
                    'db_port' => 3342,
                    'root_prestart_path' => $this->lostAckSuppression['root_prestart_path'] ?? ($this->rootPrestartApprovalPath ?? ''),
                    'manifest_path' => $manifestPath ?? ($this->lostAckSuppression['manifest_path'] ?? ''),
                    'manifest_sha256' => $manifestSha,
                    'backend_head' => $backendHead ?: ($this->lostAckSuppression['backend_head'] ?? ''),
                    'android_publication_head' => $this->lostAckSuppression['android_publication_head'] ?? $peerAndroidHead,
                    'android_executable_head' => $this->lostAckSuppression['android_executable_head'] ?? $peerAndroidHead,
                    'repo_root' => $root,
                    'root_live_approval_path' => $this->lostAckSuppression['root_live_approval_path'] ?? ($this->rootLiveApprovalPath ?? ''),
                    'handoff_path' => $this->lostAckSuppression['handoff_path'] ?? ($this->handoffFilePath ?? ''),
                    'handoff_sha256' => $this->lostAckSuppression['handoff_sha256'] ?? $handoffSha,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                chmod($proxyConfigFile, 0600);

                $this->proxyProcess = new Process(
                    [PHP_BINARY, __DIR__.'/test-transport-proxy-cli.php', '--config='.$proxyConfigFile],
                    $root,
                    $serveEnv,
                );
                $this->proxyProcess->start();
                usleep(50000); // 50ms
            }

            // Launch supervised Caddy TLS proxy
            $this->caddyProcess = new Process(
                ['caddy', 'run', '--config', $caddyConfigPath, '--adapter', 'caddyfile'],
                $bootstrapDir,
                $serveEnv,
            );
            $this->caddyProcess->start();

            // Record child process PIDs for supervisor lifecycle management
            $processPids = [
                'harness_pid' => getmypid(),
                'backend_pid' => $this->backendProcess?->getPid(),
                'proxy_pid' => $this->proxyProcess?->getPid(),
                'caddy_pid' => $this->caddyProcess?->getPid(),
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ];
            $pidsFile = $bootstrapDir.'/process_pids.json';
            file_put_contents($pidsFile, json_encode($processPids, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            chmod($pidsFile, 0600);

            // Verify TLS readiness via active bounded HTTPS probe
            $this->verifyTlsReadiness($nonce, $caCertPath);
        } catch (\Throwable $e) {
            $this->cleanup();
            throw $e;
        }

        if ($this->clientHandoff !== null) {
            $root = dirname(__DIR__, 3);
            $runtimePins = $this->clientHandoff['backend_runtime_pins'];
            $runtimePins['client_handoff_schema_sha256'] = (string) hash_file('sha256', $root.'/docs/integration/mobile-v2-client-handoff-schema.json');
            $runtimePins['source_fixture_sha256'] = (string) hash_file('sha256', __FILE__);
            $runtimePins['guard_test_sha256'] = (string) hash_file('sha256', $root.'/tests/Unit/Mobile/Harness/DisposableMobileV2BackendHarnessGuardTest.php');
            $runtimePins['cli_command_sha256'] = (string) hash_file('sha256', __DIR__.'/MobileV2HarnessCommand.php');
            $runtimePins['cli_runner_sha256'] = (string) hash_file('sha256', __DIR__.'/mobile-v2-harness-cli.php');
            $runtimePins['pairing_manifest_sha256'] = (string) $manifestShaPin;
            $runtimePins['peer_android_approved_head_sha'] = $manifestInfo['peer_android_approved_head_sha'];
            $runtimePins['peer_android_executable_head_sha'] = $manifestInfo['peer_android_executable_head_sha'];
            $this->clientHandoff['backend_runtime_pins'] = $runtimePins;
            $this->clientHandoff['lifecycle_phase'] = $this->readinessOnly ? 'READINESS_ONLY_VERIFIED' : 'ACTIVE_TLS_READY';
            $this->clientHandoff['startup_tls'] = [
                'status' => $this->readinessOnly ? 'READINESS_ONLY_VERIFIED' : 'ACTIVE_TLS_READY',
                'ca_cert_path' => $caCertPath,
                'ca_cert_sha256' => (string) hash_file('sha256', $caCertPath),
                'readiness_url' => 'https://127.0.0.1:'.self::TLS_PROXY_PORT.'/_harness/readiness',
                'android_readiness_url' => 'https://10.0.2.2:'.self::TLS_PROXY_PORT.'/_harness/readiness',
                'readiness_nonce' => $nonce,
            ];
            self::validateClientHandoff($this->clientHandoff);
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
    private function verifyBackendReadiness(string $expectedNonce, float $timeoutSeconds = 5.0, int $port = self::BACKEND_LOOPBACK_PORT): void
    {
        $start = microtime(true);
        $url = 'http://127.0.0.1:'.$port.'/_harness/readiness';
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
            "Harness start failed: PHP backend readiness probe timed out after {$timeoutSeconds}s on port ".$port
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

        if ($this->proxyProcess !== null && $this->proxyProcess->isRunning()) {
            $this->proxyProcess->stop(3.0);
            $this->proxyProcess = null;
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
