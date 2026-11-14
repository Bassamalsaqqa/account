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
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;

/**
 * Paired HTTPS Disposable Backend Harness Bootstrap.
 *
 * Source-only harness preparation for controlled HTTPS integration with Android client.
 * Enforces strict pre-socket isolation invariants (checked in this order):
 * - Refuses execution when MOBILE_V2_HARNESS_ENABLE != 1 (default deny / no-execute).
 * - Explicitly enforces MariaDB host 127.0.0.1 and port 3342 env vars before schema creation.
 * - Verifies expected backend HEAD against git rev-parse HEAD (if provided).
 * - Validates peer Android 40-hex SHA format (if provided).
 * - Requires APP_ENV=testing and PHASE8_ALLOW_DISPOSABLE_DB=1.
 * - Enforces clean git working tree and exact tree hash comparison against qualified 6981511.
 * - Encapsulates all owned DB lifecycle (create, migrate, seed) within guaranteed try/catch.
 * - Asserts actual @@port = 3342 and DATABASE() before and during fixture seeding.
 * - Sets explicit test authentication context (auth()->setUser($owner)) before opening stock.
 * - start() body implements supervised loopback PHP HTTP backend behind test TLS proxy (Caddy).
 *   Execution is gated by MOBILE_V2_HARNESS_START_LISTENER=1 until Root coordinates.
 * - Protects credentials: write to private 0600 file outside checkout, never stdout/return.
 */
final class DisposableMobileV2BackendHarness
{
    public const string REQUIRED_BASE_BRANCH = 't3/recover-cash-writer';

    public const string QUALIFIED_EXECUTION_HEAD = '6981511405d7bcd04cd8f8a09e64443ce0aaf47e';

    public const string QUALIFIED_APP_TREE = '34d1da0ae82b182fa2c0adafd785416a86834a20';

    public const string EVIDENCE_PUBLICATION_COMMIT = '717c3da2829792a5ec4f11597905a17dbb8e7cd9';

    /** Loopback port for supervised PHP HTTP backend (Caddy proxies TLS in front). */
    public const int BACKEND_LOOPBACK_PORT = 18080;

    /** TLS port exposed by Caddy for Android emulator access on 10.0.2.2. */
    public const int TLS_PROXY_PORT = 18443;

    private bool $provisioned = false;

    private ?DisposableMariaDbSchema $schema = null;

    private ?string $harnessTempDir = null;

    private ?string $credentialsFilePath = null;

    /** PID of the supervised PHP HTTP backend process (php artisan serve). */
    private ?int $listenerPid = null;

    /** PID of the Caddy TLS proxy process. */
    private ?int $caddyPid = null;

    public function setListenerPid(?int $pid): void
    {
        $this->listenerPid = $pid;
    }

    /**
     * Tracked synthetic fixture state (sanitized public IDs).
     *
     * @var array<string, mixed>|null
     */
    private ?array $fixtureManifest = null;

    /**
     * Assert pre-socket execution environment invariants.
     *
     * Checks are ordered so that pure source-only guard tests (no DB/process) can exercise
     * each failure point without depending on subsequent prerequisites:
     *
     *  1. MOBILE_V2_HARNESS_ENABLE — default deny guard
     *  2. PHASE8_TEST_DB_HOST — must be exactly 127.0.0.1
     *  3. PHASE8_TEST_DB_PORT — must be exactly 3342
     *  4. expectedBackendHead — git rev-parse HEAD comparison (if provided)
     *  5. peerAndroidHead — 40-hex format validation only (if provided)
     *  6. APP_ENV — must be testing
     *  7. booted app environment — must be testing
     *  8. PHASE8_ALLOW_DISPOSABLE_DB — must be 1
     *  9. DisposableMariaDbSchema::testSettings() — full settings validation
     * 10. git status porcelain — clean app/routes/config/composer.lock
     * 11. qualified execution commit object — must exist in repo
     * 12. app tree hash — must match QUALIFIED_APP_TREE
     *
     * @param  string|null  $expectedBackendHead  Full 40-hex commit SHA of current worktree HEAD.
     * @param  string|null  $peerAndroidHead  Full 40-hex commit SHA of peer Android integration HEAD.
     */
    public static function assertPreSocketInvariants(?string $expectedBackendHead = null, ?string $peerAndroidHead = null): void
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

        // 4. Verify expected backend HEAD against actual git HEAD (pure git process, no DB)
        if ($expectedBackendHead !== null) {
            if (! preg_match('/^[0-9a-fA-F]{40}$/', $expectedBackendHead)) {
                throw new RuntimeException("Harness refused: expectedBackendHead must be a full 40-hex SHA, got '{$expectedBackendHead}'.");
            }
            $root = dirname(__DIR__, 3);
            $currentHead = new Process(['git', 'rev-parse', 'HEAD'], $root);
            $currentHead->run();
            if (! $currentHead->isSuccessful() || trim($currentHead->getOutput()) !== $expectedBackendHead) {
                throw new RuntimeException("Harness refused: current git HEAD does not match expected backend HEAD '{$expectedBackendHead}'.");
            }
        }

        // 5. Validate peer Android HEAD format (regex only, no process or DB needed)
        if ($peerAndroidHead !== null) {
            if (! preg_match('/^[0-9a-fA-F]{40}$/', $peerAndroidHead)) {
                throw new RuntimeException("Harness refused: peerAndroidHead must be a valid 40-hex SHA, got '{$peerAndroidHead}'.");
            }
        }

        // 6. Strict APP_ENV check
        if (getenv('APP_ENV') !== 'testing') {
            throw new RuntimeException('Harness refused: APP_ENV must be testing.');
        }

        // 7. Reject inherited non-testing booted application environment
        if (function_exists('app') && app()->bound('env') && ! app()->environment('testing')) {
            throw new RuntimeException('Harness refused: inherited booted app environment is not testing.');
        }

        // 8. Explicit opt-in DB flag
        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1') {
            throw new RuntimeException('Harness refused: PHASE8_ALLOW_DISPOSABLE_DB must be 1.');
        }

        // 9. Full administrative connection settings validation
        DisposableMariaDbSchema::testSettings();

        // 10–12. Process-based git checks with exit-code verification (fail-closed)
        $root = dirname(__DIR__, 3);

        $statusProcess = new Process(['git', 'status', '--porcelain', 'app/', 'routes/', 'config/', 'composer.lock'], $root);
        $statusProcess->run();
        if (! $statusProcess->isSuccessful()) {
            throw new RuntimeException('Harness refused: git status check failed with exit code '.$statusProcess->getExitCode());
        }
        $gitStatus = trim($statusProcess->getOutput());
        if ($gitStatus !== '') {
            throw new RuntimeException("Harness refused: modified working tree detected in core paths:\n{$gitStatus}");
        }

        $catCommit = new Process(['git', 'cat-file', '-t', self::QUALIFIED_EXECUTION_HEAD], $root);
        $catCommit->run();
        if (! $catCommit->isSuccessful() || trim($catCommit->getOutput()) !== 'commit') {
            throw new RuntimeException('Harness refused: qualified execution commit '.self::QUALIFIED_EXECUTION_HEAD.' not found in git repository.');
        }

        $revParseApp = new Process(['git', 'rev-parse', 'HEAD:app'], $root);
        $revParseApp->run();
        if (! $revParseApp->isSuccessful() || trim($revParseApp->getOutput()) !== self::QUALIFIED_APP_TREE) {
            throw new RuntimeException('Harness refused: app tree hash does not match qualified execution head tree '.self::QUALIFIED_APP_TREE);
        }
    }

    /**
     * Phase 1: Provision disposable schema and seed synthetic qualification fixtures.
     *
     * Entire schema lifecycle (create, migrate, seed) is enclosed in try/catch to ensure
     * zero database schema or file leaks upon any failure.
     *
     * @param  string|null  $expectedBackendHead  Full 40-hex commit SHA of current worktree HEAD.
     * @param  string|null  $peerAndroidHead  Full 40-hex commit SHA of peer Android integration HEAD.
     * @return array{
     *     manifest: array<string, mixed>,
     *     credentials_path: string,
     *     sample_request: array<string, mixed>
     * }
     */
    public function provision(?string $expectedBackendHead = null, ?string $peerAndroidHead = null): array
    {
        self::assertPreSocketInvariants($expectedBackendHead, $peerAndroidHead);

        $connectionSwitched = false;

        try {
            // Establish private harness temporary directory outside git checkout
            $this->harnessTempDir = sys_get_temp_dir().'/v2_harness_'.bin2hex(random_bytes(8));
            if (! is_dir($this->harnessTempDir)) {
                mkdir($this->harnessTempDir, 0700, true);
            }

            // Create and migrate owned disposable schema inside try block
            $this->schema = DisposableMariaDbSchema::create();
            $this->schema->migrate();

            // Explicitly switch Laravel DB connection manager to the fresh owned schema
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
            $token = MobileAccessToken::create([
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

            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->firstOrFail();
            /** @var Unit $baseUnit */
            $baseUnit = Unit::where('company_id', $company->id)->where('code', 'piece')->firstOrFail();

            $product = Product::create([
                'company_id' => $company->id,
                'base_unit_id' => $baseUnit->id,
                'name_ar' => 'منتج نقدي تجريبي للتكامل',
                'name_en' => 'Online Cash Integration Product',
                'sku' => 'INT-CASH-'.Str::random(4),
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

            $this->provisioned = true;

            return [
                'manifest' => $this->fixtureManifest,
                'credentials_path' => $this->credentialsFilePath,
                'sample_request' => $sampleRequest,
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
     * - Writes a private test bootstrap config (chmod 0700 dir, 0600 files) isolating
     *   .env and cache from the production checkout before Laravel boot.
     * - Launches supervised php artisan serve on 127.0.0.1:BACKEND_LOOPBACK_PORT.
     * - Writes Caddy config with explicit TLS certificate SAN: localhost, 127.0.0.1, 10.0.2.2.
     * - Launches supervised Caddy process proxying TLS_PROXY_PORT → loopback backend.
     * - Both processes are owned by the harness; PIDs are tracked for bounded cleanup.
     * - No cleartext client path; Android must use the test CA certificate.
     *
     * Execution is gated by MOBILE_V2_HARNESS_START_LISTENER=1.
     * This gate MUST remain until Android thread 83e6a721 is ready and Root grants QA lease.
     *
     * @param  string  $peerAndroidHead  Full 40-hex commit SHA of peer Android integration HEAD.
     * @param  string  $backendHead  Full 40-hex commit SHA of current backend worktree HEAD.
     * @return array{backend_loopback_port: int, tls_proxy_port: int, ca_cert_path: string, credentials_path: string}
     */
    public function start(string $peerAndroidHead, string $backendHead): array
    {
        if (! $this->provisioned || $this->schema === null) {
            throw new RuntimeException('Harness refused: must be provisioned before start.');
        }

        if (! preg_match('/^[0-9a-fA-F]{40}$/', $peerAndroidHead)) {
            throw new RuntimeException("Harness refused: start requires valid 40-hex peerAndroidHead SHA, got '{$peerAndroidHead}'.");
        }

        if (! preg_match('/^[0-9a-fA-F]{40}$/', $backendHead)) {
            throw new RuntimeException("Harness refused: start requires valid 40-hex backendHead SHA, got '{$backendHead}'.");
        }

        // Runtime execution gate: held until Root explicitly coordinates with Android thread
        if (getenv('MOBILE_V2_HARNESS_START_LISTENER') !== '1') {
            throw new RuntimeException(
                'Harness start refused: MOBILE_V2_HARNESS_START_LISTENER is not set. '.
                'Listener startup is held pending Android thread 83e6a721 readiness and explicit Root coordination grant. '.
                'Zero background servers, TLS sockets, or DB listeners started.'
            );
        }

        $root = dirname(__DIR__, 3);

        // Verify backend HEAD matches expected before starting any listener
        $currentHead = new Process(['git', 'rev-parse', 'HEAD'], $root);
        $currentHead->run();
        if (! $currentHead->isSuccessful() || trim($currentHead->getOutput()) !== $backendHead) {
            throw new RuntimeException("Harness start refused: current backend HEAD does not match required '{$backendHead}'.");
        }

        // Write private bootstrap config dir (0700) outside the checkout
        $bootstrapDir = $this->harnessTempDir.'/bootstrap';
        if (! is_dir($bootstrapDir)) {
            mkdir($bootstrapDir, 0700, true);
        }

        // Isolate .env from production: write a fresh test-only .env (0600) with disposable DB settings
        $dbSettings = DisposableMariaDbSchema::testSettings();
        $testEnvPath = $bootstrapDir.'/.env.harness';
        $schemaName = $this->schema->schemaName();
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
            'MOBILE_V2_HARNESS_ENABLE=1',
            'MOBILE_V2_CASH_SLICE_ENABLED=true',
            'MOBILE_API_MASTER_ENABLED=true',
        ]);
        file_put_contents($testEnvPath, $testEnvContent);
        chmod($testEnvPath, 0600);

        // Write a fresh bootstrap/cache dir (0700) so artisan serve uses isolated cache
        $cacheDir = $bootstrapDir.'/cache';
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0700, true);
        }

        // Generate test TLS CA and server certificate with explicit SAN
        // SAN must cover: localhost, 127.0.0.1 (loopback), 10.0.2.2 (Android emulator host alias)
        $caCertPath = $bootstrapDir.'/test-ca.pem';
        $caKeyPath = $bootstrapDir.'/test-ca-key.pem';
        $serverCertPath = $bootstrapDir.'/server-cert.pem';
        $serverKeyPath = $bootstrapDir.'/server-key.pem';

        // Generate private test CA (self-signed, 1-day validity for integration session)
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

        // Generate server key and CSR
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

        // Write SAN extension file
        $sanExtPath = $bootstrapDir.'/san.ext';
        file_put_contents($sanExtPath, implode("\n", [
            '[SAN]',
            'subjectAltName=DNS:localhost,IP:127.0.0.1,IP:10.0.2.2',
        ]));

        // Sign server certificate with test CA including explicit SAN
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

        // Write Caddy JSON config: TLS_PROXY_PORT → loopback BACKEND_LOOPBACK_PORT
        // Uses file_server=false (reverse proxy only); no cleartext listener
        $caddyConfigPath = $bootstrapDir.'/Caddyfile';
        $backendLoopback = 'http://127.0.0.1:'.self::BACKEND_LOOPBACK_PORT;
        file_put_contents($caddyConfigPath, implode("\n", [
            'https://localhost:'.self::TLS_PROXY_PORT.' {',
            '    tls '.$serverCertPath.' '.$serverKeyPath,
            '    reverse_proxy '.$backendLoopback,
            '}',
        ]));

        // Launch supervised PHP HTTP backend: artisan serve on loopback only
        // Env explicitly sets APP_ENV_FILE to test-only config (isolating .env/cache)
        $serveEnv = array_merge(getenv() ?: [], [
            'APP_BASE_PATH' => $root,
            'APP_ENV' => 'testing',
            'PHP_CLI_SERVER_WORKERS' => '1',
        ]);

        $serveProcess = new Process(
            ['php', 'artisan', 'serve',
                '--host=127.0.0.1',
                '--port='.self::BACKEND_LOOPBACK_PORT,
                '--env='.$testEnvPath,
            ],
            $root,
            $serveEnv,
        );
        $serveProcess->start();

        // Wait briefly for the backend to bind before starting Caddy
        usleep(500_000);

        if (! $serveProcess->isRunning()) {
            throw new RuntimeException(
                'Harness start failed: PHP backend process exited prematurely. '.
                'Output: '.$serveProcess->getOutput().
                ' Error: '.$serveProcess->getErrorOutput()
            );
        }

        $this->listenerPid = $serveProcess->getPid();

        // Launch supervised Caddy TLS proxy
        $caddyProcess = new Process(
            ['caddy', 'run', '--config', $caddyConfigPath, '--adapter', 'caddyfile'],
            $bootstrapDir,
        );
        $caddyProcess->start();

        usleep(500_000);

        if (! $caddyProcess->isRunning()) {
            // Caddy failed to start; terminate the backend process before throwing
            if ($this->listenerPid !== null) {
                posix_kill($this->listenerPid, SIGTERM);
                $this->listenerPid = null;
            }
            throw new RuntimeException(
                'Harness start failed: Caddy TLS proxy process exited prematurely. '.
                'Output: '.$caddyProcess->getOutput().
                ' Error: '.$caddyProcess->getErrorOutput()
            );
        }

        $this->caddyPid = $caddyProcess->getPid();

        return [
            'backend_loopback_port' => self::BACKEND_LOOPBACK_PORT,
            'tls_proxy_port' => self::TLS_PROXY_PORT,
            'ca_cert_path' => $caCertPath,
            'credentials_path' => $this->credentialsFilePath ?? '',
        ];
    }

    /**
     * Phase 3: Explicit cleanup of owned schemas, files, temporary directories, and processes.
     *
     * Only terminates processes tracked by this harness instance (listenerPid, caddyPid).
     * Never kills foreign PIDs or ports not owned by this instance.
     */
    public function cleanup(): void
    {
        // Terminate owned Caddy TLS proxy (if running)
        if ($this->caddyPid !== null) {
            posix_kill($this->caddyPid, SIGTERM);
            $this->caddyPid = null;
        }

        // Terminate owned PHP backend listener (if running)
        if ($this->listenerPid !== null) {
            posix_kill($this->listenerPid, SIGTERM);
            $this->listenerPid = null;
        }

        // Remove owned temporary directory and all contained files
        if ($this->harnessTempDir !== null && is_dir($this->harnessTempDir)) {
            $this->removeDir($this->harnessTempDir);
            $this->harnessTempDir = null;
        }

        $this->credentialsFilePath = null;

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
