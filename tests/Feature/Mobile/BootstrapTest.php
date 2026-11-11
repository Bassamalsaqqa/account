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

    public function test_bootstrap_is_unavailable_without_approved_policy_even_with_complete_mappings(): void
    {
        $token = $this->issueToken();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/bootstrap")
            ->assertStatus(409)->assertJsonPath('error.code', 'BOOTSTRAP_CONFIG_UNSUPPORTED')
            ->assertJsonMissingPath('offline_policy')->assertJsonMissingPath('device_lease');
    }
}
