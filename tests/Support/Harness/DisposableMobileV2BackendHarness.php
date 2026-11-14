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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\DisposableMariaDbSchema;

/**
 * Paired HTTPS Disposable Backend Harness Bootstrap.
 *
 * Source-only harness preparation for controlled HTTPS integration with Android client.
 * Refuses pre-socket execution without APP_ENV=testing, PHASE8_ALLOW_DISPOSABLE_DB=1,
 * valid disposable schema ownership proof, and explicit execution enablement.
 *
 * Defaults to NO-EXECUTE; leaves all production flags and provider bindings intact.
 */
final class DisposableMobileV2BackendHarness
{
    public const string REQUIRED_BASE_BRANCH = 't3/recover-cash-writer';

    public const string QUALIFIED_EXECUTION_HEAD = '6981511405d7bcd04cd8f8a09e64443ce0aaf47e';

    public const string EVIDENCE_PUBLICATION_COMMIT = '717c3da2829792a5ec4f11597905a17dbb8e7cd9';

    private bool $provisioned = false;

    private ?DisposableMariaDbSchema $schema = null;

    private ?string $harnessTempDir = null;

    /**
     * Tracked synthetic fixture state (sanitized public IDs).
     *
     * @var array<string, mixed>|null
     */
    private ?array $fixtureManifest = null;

    /**
     * Assert pre-socket execution environment invariants.
     */
    public static function assertPreSocketInvariants(): void
    {
        if (getenv('APP_ENV') !== 'testing') {
            throw new RuntimeException('Harness refused: APP_ENV must be testing.');
        }

        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1') {
            throw new RuntimeException('Harness refused: PHASE8_ALLOW_DISPOSABLE_DB must be 1.');
        }

        if (getenv('MOBILE_V2_HARNESS_ENABLE') !== '1') {
            throw new RuntimeException('Harness refused: defaults to no-execute. Set MOBILE_V2_HARNESS_ENABLE=1 when Root explicitly coordinates start.');
        }

        DisposableMariaDbSchema::testSettings();
    }

    /**
     * Phase 1: Provision disposable schema and seed synthetic qualification fixtures.
     *
     * Creates fresh Company, staff, roles, device, token, customer, warehouse,
     * base unit, stock product, tax rate, and cash account identical to OnlineCashSaleQualificationTest.
     * Zero copied real data.
     *
     * @return array{
     *     manifest: array<string, mixed>,
     *     raw_token: string,
     *     sample_request: array<string, mixed>
     * }
     */
    public function provision(): array
    {
        self::assertPreSocketInvariants();

        $this->schema = DisposableMariaDbSchema::create();
        $this->schema->migrate();

        $env = $this->schema->environment();
        foreach ($env as $key => $val) {
            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }

        // Establish private harness temporary directory outside git checkout
        $this->harnessTempDir = sys_get_temp_dir().'/v2_harness_'.bin2hex(random_bytes(8));
        if (! is_dir($this->harnessTempDir)) {
            mkdir($this->harnessTempDir, 0700, true);
        }

        // Seed domain fixtures using standard actions and services
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

        // Post opening stock
        app(CompanyContext::class)->setCompany($company, $owner);
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
        $runtimeCredentialsPath = $this->harnessTempDir.'/runtime_credentials.json';
        file_put_contents(
            $runtimeCredentialsPath,
            json_encode([
                'raw_token' => $rawToken,
                'token_hash' => hash('sha256', $rawToken),
                'device_id' => $device->device_id,
                'staff_email' => $staff->email,
                'schema' => $this->schema->schemaName(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        chmod($runtimeCredentialsPath, 0600);

        $this->provisioned = true;

        return [
            'manifest' => $this->fixtureManifest,
            'raw_token' => $rawToken,
            'sample_request' => $sampleRequest,
        ];
    }

    /**
     * Phase 2: Start phase (stubbed, NO-EXECUTE until Root explicit coordination).
     */
    public function start(): void
    {
        if (! $this->provisioned) {
            throw new RuntimeException('Harness must be provisioned before start.');
        }
        if (getenv('MOBILE_V2_HARNESS_START_LISTENER') !== '1') {
            throw new RuntimeException('Harness start listener refused: awaiting Android test readiness and explicit Root coordination.');
        }
    }

    /**
     * Phase 3: Explicit cleanup of owned schemas, files, and temporary directories.
     */
    public function cleanup(): void
    {
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

        if ($this->schema !== null) {
            $this->schema->drop();
            $this->schema = null;
        }

        $this->provisioned = false;
        $this->fixtureManifest = null;
    }
}
