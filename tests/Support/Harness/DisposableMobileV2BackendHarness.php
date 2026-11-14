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
 * Enforces strict pre-socket isolation invariants:
 * - Refuses execution without APP_ENV=testing and PHASE8_ALLOW_DISPOSABLE_DB=1.
 * - Explicitly enforces MariaDB host 127.0.0.1 and port 3342 before schema creation.
 * - Enforces clean git working tree and exact tree hash comparison against qualified 6981511.
 * - Requires exact 40-hex Android commit SHA when pairing is activated.
 * - Encapsulates all owned DB lifecycle (create, migrate, seed) within guaranteed try/catch.
 * - Asserts actual @@port = 3342 and DATABASE() before and during fixture seeding.
 * - Sets explicit test authentication context (auth()->setUser($owner)) before opening stock.
 * - Prepared router/bootstrap source with explicit startup refusal until Root coordination grant.
 * - Protects credentials: write to private 0600 file outside checkout, never stdout/return.
 */
final class DisposableMobileV2BackendHarness
{
    public const string REQUIRED_BASE_BRANCH = 't3/recover-cash-writer';

    public const string QUALIFIED_EXECUTION_HEAD = '6981511405d7bcd04cd8f8a09e64443ce0aaf47e';

    public const string QUALIFIED_APP_TREE = '34d1da0ae82b182fa2c0adafd785416a86834a20';

    public const string EVIDENCE_PUBLICATION_COMMIT = '717c3da2829792a5ec4f11597905a17dbb8e7cd9';

    private bool $provisioned = false;

    private ?DisposableMariaDbSchema $schema = null;

    private ?string $harnessTempDir = null;

    private ?string $credentialsFilePath = null;

    /**
     * Optional listener process PID when live listener is bound.
     */
    private ?int $listenerPid = null;

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
     * @param  string|null  $expectedBackendHead  Full 40-hex commit SHA of current worktree HEAD.
     * @param  string|null  $peerAndroidHead  Full 40-hex commit SHA of peer Android integration HEAD.
     */
    public static function assertPreSocketInvariants(?string $expectedBackendHead = null, ?string $peerAndroidHead = null): void
    {
        // 1. Strict environment checks
        if (getenv('APP_ENV') !== 'testing') {
            throw new RuntimeException('Harness refused: APP_ENV must be testing.');
        }

        if (function_exists('app') && app()->bound('env') && ! app()->environment('testing')) {
            throw new RuntimeException('Harness refused: inherited booted app environment is not testing.');
        }

        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1') {
            throw new RuntimeException('Harness refused: PHASE8_ALLOW_DISPOSABLE_DB must be 1.');
        }

        if (getenv('MOBILE_V2_HARNESS_ENABLE') !== '1') {
            throw new RuntimeException('Harness refused: defaults to no-execute. Set MOBILE_V2_HARNESS_ENABLE=1 when Root explicitly coordinates start.');
        }

        // 2. Enforce exact 127.0.0.1:3342 administrative configuration before creating schemas
        $host = (string) getenv('PHASE8_TEST_DB_HOST');
        $port = (string) getenv('PHASE8_TEST_DB_PORT');

        if ($host !== '127.0.0.1') {
            throw new RuntimeException("Harness refused: DB host must be explicitly 127.0.0.1, got '{$host}'.");
        }

        if ($port !== '3342') {
            throw new RuntimeException("Harness refused: DB port must be explicitly 3342, got '{$port}'.");
        }

        DisposableMariaDbSchema::testSettings();

        // 3. Process-based git checks with exit code verification (fail-closed)
        $root = dirname(__DIR__, 3);

        // Check git status
        $statusProcess = new Process(['git', 'status', '--porcelain', 'app/', 'routes/', 'config/', 'composer.lock'], $root);
        $statusProcess->run();
        if (! $statusProcess->isSuccessful()) {
            throw new RuntimeException('Harness refused: git status check failed with exit code '.$statusProcess->getExitCode());
        }
        $gitStatus = trim($statusProcess->getOutput());
        if ($gitStatus !== '') {
            throw new RuntimeException("Harness refused: modified working tree detected in core paths:\n{$gitStatus}");
        }

        // Verify qualified commit object exists
        $catCommit = new Process(['git', 'cat-file', '-t', self::QUALIFIED_EXECUTION_HEAD], $root);
        $catCommit->run();
        if (! $catCommit->isSuccessful() || trim($catCommit->getOutput()) !== 'commit') {
            throw new RuntimeException('Harness refused: qualified execution commit '.self::QUALIFIED_EXECUTION_HEAD.' not found in git repository.');
        }

        // Verify core app tree matches qualified execution head tree
        $revParseApp = new Process(['git', 'rev-parse', 'HEAD:app'], $root);
        $revParseApp->run();
        if (! $revParseApp->isSuccessful() || trim($revParseApp->getOutput()) !== self::QUALIFIED_APP_TREE) {
            throw new RuntimeException('Harness refused: app tree hash does not match qualified execution head tree '.self::QUALIFIED_APP_TREE);
        }

        // Verify expected backend HEAD if provided
        if ($expectedBackendHead !== null) {
            if (! preg_match('/^[0-9a-fA-F]{40}$/', $expectedBackendHead)) {
                throw new RuntimeException("Harness refused: expectedBackendHead must be a full 40-hex SHA, got '{$expectedBackendHead}'.");
            }
            $currentHead = new Process(['git', 'rev-parse', 'HEAD'], $root);
            $currentHead->run();
            if (! $currentHead->isSuccessful() || trim($currentHead->getOutput()) !== $expectedBackendHead) {
                throw new RuntimeException("Harness refused: current git HEAD does not match expected backend HEAD '{$expectedBackendHead}'.");
            }
        }

        // Verify exact peer Android 40-hex HEAD when provided
        if ($peerAndroidHead !== null) {
            if (! preg_match('/^[0-9a-fA-F]{40}$/', $peerAndroidHead)) {
                throw new RuntimeException("Harness refused: peerAndroidHead must be a valid 40-hex SHA, got '{$peerAndroidHead}'.");
            }
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
     * Phase 2: Prepared start phase.
     *
     * Prepares router bootstrap configuration and asserts execution prerequisites.
     * Explicitly refuses socket startup with NOT_IMPLEMENTED until live lifecycle coordination
     * and Android test readiness are confirmed. Zero background listeners are launched.
     *
     * @param  string  $peerAndroidHead  Full 40-hex commit SHA of peer Android integration HEAD.
     */
    public function start(string $peerAndroidHead): void
    {
        if (! $this->provisioned || $this->schema === null) {
            throw new RuntimeException('Harness refused: must be provisioned before start.');
        }

        if (! preg_match('/^[0-9a-fA-F]{40}$/', $peerAndroidHead)) {
            throw new RuntimeException("Harness refused: start requires valid 40-hex peerAndroidHead SHA, got '{$peerAndroidHead}'.");
        }

        if (getenv('MOBILE_V2_HARNESS_START_LISTENER') !== '1') {
            throw new RuntimeException(
                'NOT_IMPLEMENTED: Paired HTTPS listener startup is held pending Android test readiness and explicit Root coordination grant. Zero background servers or network sockets started.'
            );
        }

        // Future live listener entrypoint (never executed during preparation)
        throw new RuntimeException('NOT_IMPLEMENTED: Listener execution held.');
    }

    /**
     * Phase 3: Explicit cleanup of owned schemas, files, and temporary directories.
     */
    public function cleanup(): void
    {
        if ($this->listenerPid !== null) {
            posix_kill($this->listenerPid, SIGTERM);
            $this->listenerPid = null;
        }

        if ($this->harnessTempDir !== null && is_dir($this->harnessTempDir)) {
            $files = glob($this->harnessTempDir.'/*');
            if (is_array($files)) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
            @rmdir($this->harnessTempDir);
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
}
