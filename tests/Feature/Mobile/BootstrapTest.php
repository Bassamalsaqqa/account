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
use App\Support\Tenancy\CompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
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
        MobileDevice::create(['device_id' => $deviceId, 'device_name' => 'Tablet', 'user_id' => $this->user->id]);

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

    public function test_bootstrap_succeeds_with_valid_configuration(): void
    {
        $token = $this->issueToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap");

        $response->assertStatus(200)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $validator = new Process(['python', base_path('tests/Support/Mobile/validate-response.py'), 'bootstrap-response']);
        $validator->setInput($response->getContent())->run();
        $this->assertTrue($validator->isSuccessful(), $validator->getErrorOutput());

        $data = $response->json();
        $this->assertSame($this->company->public_id, $data['company_id']);
        $this->assertSame('ILS', $data['base_currency']);
        $this->assertSame($this->customer->public_id, $data['walk_in_customer_id']);
        $this->assertSame($this->warehouse->public_id, $data['default_warehouse']['id']);
        $this->assertSame('WH-MAIN', $data['default_warehouse']['code']);
        $this->assertSame($this->cashAccount->public_id, $data['approved_cash_account']['id']);
        $this->assertSame('ILS', $data['approved_cash_account']['currency']);
        $this->assertNull($data['device_lease']);

        $this->assertSame(['enabled' => false, 'availability' => 'unavailable'], $data['offline_policy']);
        $this->assertArrayNotHasKey('max_offline_hours', $data['offline_policy']);
        $this->assertArrayNotHasKey('max_offline_sales_count', $data['offline_policy']);
        $this->assertArrayNotHasKey('max_offline_total_amount', $data['offline_policy']);

        $this->assertCount(1, $data['units']);
        $this->assertSame($this->unit->public_id, $data['units'][0]['id']);
        $this->assertCount(1, $data['taxes']);
        $this->assertSame($this->taxRate->public_id, $data['taxes'][0]['id']);
        $this->assertTrue($data['taxes'][0]['is_default']);
    }

    public function test_bootstrap_fails_truthfully_when_walkin_customer_is_missing_or_inactive(): void
    {
        $token = $this->issueToken();

        // 1. Inactive walk-in customer
        $this->customer->update(['status' => 'inactive']);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap")
            ->assertStatus(409)->assertJsonPath('error.code', 'BOOTSTRAP_CONFIG_UNSUPPORTED');

        // 2. Different company customer
        $otherCompany = Company::factory()->create(['status' => 'active']);
        $otherCust = CompanyScope::executeWithoutScope(fn () => Customer::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $otherCompany->id,
            'code' => 'OTHER-CUST',
            'name_ar' => 'عميل آخر',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]));
        CompanyMobileSetting::where('company_id', $this->company->id)->update(['walk_in_customer_id' => $otherCust->id]);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap")
            ->assertStatus(409)->assertJsonPath('error.code', 'BOOTSTRAP_CONFIG_UNSUPPORTED');

        // 3. Null walk-in customer
        CompanyMobileSetting::where('company_id', $this->company->id)->update(['walk_in_customer_id' => null]);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap")
            ->assertStatus(409)->assertJsonPath('error.code', 'BOOTSTRAP_CONFIG_UNSUPPORTED');
    }

    public function test_bootstrap_fails_truthfully_when_cash_account_is_missing_inactive_or_currency_mismatched(): void
    {
        $token = $this->issueToken();

        // 1. Inactive cash account
        $this->cashAccount->update(['is_active' => false]);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap")
            ->assertStatus(409)->assertJsonPath('error.code', 'BOOTSTRAP_CONFIG_UNSUPPORTED');

        // 2. Currency mismatched
        $this->cashAccount->update(['is_active' => true, 'currency_code' => 'USD']);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap")
            ->assertStatus(409)->assertJsonPath('error.code', 'BOOTSTRAP_CONFIG_UNSUPPORTED');

        // 3. Null cash account
        CompanyMobileSetting::where('company_id', $this->company->id)->update(['approved_cash_account_id' => null]);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap")
            ->assertStatus(409)->assertJsonPath('error.code', 'BOOTSTRAP_CONFIG_UNSUPPORTED');
    }

    public function test_bootstrap_fails_truthfully_when_default_warehouse_is_missing_or_inactive(): void
    {
        $token = $this->issueToken();

        // 1. Inactive warehouse
        $this->warehouse->update(['active' => false]);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap")
            ->assertStatus(409)->assertJsonPath('error.code', 'BOOTSTRAP_CONFIG_UNSUPPORTED');

        // 2. Null warehouse
        CompanyMobileSetting::where('company_id', $this->company->id)->update(['default_warehouse_id' => null]);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap")
            ->assertStatus(409)->assertJsonPath('error.code', 'BOOTSTRAP_CONFIG_UNSUPPORTED');
    }

    public function test_bootstrap_allows_empty_units_and_taxes_without_invented_defaults(): void
    {
        CompanyScope::executeWithoutScope(function (): void {
            Unit::where('company_id', $this->company->id)->delete();
            TaxRate::where('company_id', $this->company->id)->delete();
        });
        CompanyMobileSetting::where('company_id', $this->company->id)->update(['default_tax_rate_id' => null]);

        $token = $this->issueToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap");

        $response->assertStatus(200);
        $validator = new Process(['python', base_path('tests/Support/Mobile/validate-response.py'), 'bootstrap-response']);
        $validator->setInput($response->getContent())->run();
        $this->assertTrue($validator->isSuccessful(), $validator->getErrorOutput());

        $data = $response->json();
        $this->assertSame([], $data['units']);
        $this->assertSame([], $data['taxes']);
    }
}
