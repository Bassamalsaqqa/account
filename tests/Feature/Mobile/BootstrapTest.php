<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    private MoneyAccount $cashAccount;

    private Unit $unit;

    private TaxRate $taxRate;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobile.enabled' => true]);

        $this->user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->company = Company::factory()->create([
            'name_ar' => 'شركة الأمل للتجزئة',
            'name_en' => 'Al-Amal Retail Company',
            'base_currency_code' => 'ILS',
            'status' => 'active',
        ]);

        $this->user->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => false]);
        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);
        $this->user->assignRole('Cashier');

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'WH-MAIN',
            'name_ar' => 'المستودع الرئيسي',
            'name_en' => 'Main Store Warehouse',
            'active' => true,
            'is_default' => true,
            'created_by' => $this->user->id,
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'CUST-WALK-IN',
            'name_ar' => 'عميل نقدي',
            'name_en' => 'Walk-in Customer',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $ledgerAccount = LedgerAccount::create([
            'company_id' => $this->company->id,
            'code' => '1010',
            'name_ar' => 'النقدية',
            'name_en' => 'Cash',
            'account_type' => LedgerAccount::TYPE_ASSET,
            'normal_balance' => LedgerAccount::BALANCE_DEBIT,
            'is_control' => false,
            'is_system' => false,
            'active' => true,
        ]);

        $this->cashAccount = MoneyAccount::create([
            'company_id' => $this->company->id,
            'account_type' => 'cash',
            'name_ar' => 'صندوق نقاط البيع',
            'name_en' => 'Cash Register 01 (ILS)',
            'currency_code' => 'ILS',
            'ledger_account_id' => $ledgerAccount->id,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->unit = Unit::create([
            'company_id' => $this->company->id,
            'code' => 'PCS',
            'name_ar' => 'قطعة',
            'name_en' => 'Piece',
            'symbol_en' => 'pc',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);

        $this->taxRate = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT16',
            'name_ar' => 'ضريبة القيمة المضافة',
            'name_en' => 'Standard VAT (16%)',
            'rate' => '16.000000',
            'calculation' => 'exclusive',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        CompanyMobileSetting::create([
            'company_id' => $this->company->id,
            'walk_in_customer_id' => $this->customer->id,
            'approved_cash_account_id' => $this->cashAccount->id,
            'default_warehouse_id' => $this->warehouse->id,
            'default_tax_rate_id' => $this->taxRate->id,
            'offline_policy_enabled' => false,
            'offline_cash_only' => true,
            'max_offline_hours' => 12,
            'max_offline_sales_count' => 50,
            'max_offline_total_amount' => 5000,
            'catalog_snapshot_version' => 'snap-20261110-v1',
        ]);

        app(CompanyContext::class)->clear();
    }

    private function issueToken(): string
    {
        $raw = 'st_m_'.bin2hex(random_bytes(32));
        $deviceId = (string) Str::uuid();
        MobileDevice::create(['device_id' => $deviceId, 'device_name' => 'Tablet']);

        MobileAccessToken::create([
            'token_hash' => hash('sha256', $raw),
            'device_id' => $deviceId,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        return $raw;
    }

    public function test_bootstrap_returns_valid_structure_matching_schema(): void
    {
        $token = $this->issueToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap");

        $response->assertStatus(200)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $data = $response->json();

        // 1. Identifiers and Strings
        $this->assertSame($this->company->public_id, $data['company_id']);
        $this->assertNotEmpty($data['company_name']);
        $this->assertSame('ILS', $data['base_currency']);
        $this->assertSame($this->customer->public_id, $data['walk_in_customer_id']);

        // 2. Staff Profile
        $this->assertSame($this->user->public_id, $data['staff']['id']);
        $this->assertSame('cashier', $data['staff']['role']);
        $this->assertIsArray($data['staff']['permissions']);

        // 3. Device Lease is strictly null when offline_policy.enabled is false
        $this->assertNull($data['device_lease']);

        // 4. Warehouse & Cash Account
        $this->assertSame($this->warehouse->public_id, $data['default_warehouse']['id']);
        $this->assertSame('WH-MAIN', $data['default_warehouse']['code']);
        $this->assertSame($this->cashAccount->public_id, $data['approved_cash_account']['id']);
        $this->assertSame('ILS', $data['approved_cash_account']['currency']);

        // 5. Units
        $this->assertNotEmpty($data['units']);
        $this->assertSame($this->unit->public_id, $data['units'][0]['id']);
        $this->assertSame('PCS', $data['units'][0]['code']);
        $this->assertFalse($data['units'][0]['allows_fraction']);
        $this->assertSame(0, $data['units'][0]['decimal_places']);

        // 6. Taxes (exact 6-decimal string rate and boolean is_default)
        $this->assertNotEmpty($data['taxes']);
        $this->assertSame($this->taxRate->public_id, $data['taxes'][0]['id']);
        $this->assertSame('16.000000', $data['taxes'][0]['rate']);
        $this->assertSame('exclusive', $data['taxes'][0]['calculation']);
        $this->assertTrue($data['taxes'][0]['is_default']);

        // 7. Offline Policy
        $this->assertFalse($data['offline_policy']['enabled']);
        $this->assertTrue($data['offline_policy']['cash_only']);
        $this->assertSame(12, $data['offline_policy']['max_offline_hours']);
        $this->assertSame(50, $data['offline_policy']['max_offline_sales_count']);
        $this->assertSame('5000.000000', $data['offline_policy']['max_offline_total_amount']);

        // 8. Snapshot Version
        $this->assertSame('snap-20261110-v1', $data['catalog_snapshot_version']);
    }

    public function test_bootstrap_truthfully_fails_when_walk_in_customer_is_not_configured(): void
    {
        // Remove walk-in customer configuration
        CompanyMobileSetting::where('company_id', $this->company->id)->update(['walk_in_customer_id' => null]);
        $this->customer->update(['code' => 'CUST-REGULAR']);

        $token = $this->issueToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'BOOTSTRAP_CONFIG_INCOMPLETE');
    }
}
