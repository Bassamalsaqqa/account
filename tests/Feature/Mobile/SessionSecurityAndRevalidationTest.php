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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SessionSecurityAndRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobile.enabled' => true]);

        $this->user = User::factory()->create([
            'password' => Hash::make('initial-password'),
        ]);

        $this->companyA = Company::factory()->create(['status' => 'active', 'base_currency_code' => 'ILS']);
        $this->companyB = Company::factory()->create(['status' => 'active', 'base_currency_code' => 'USD']);

        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->companyA);
        app(CompanyRoleService::class)->seedCompanyRoles($this->companyB);

        // User belongs ONLY to Company A
        $this->user->companies()->attach($this->companyA->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->companyA->id);
        $this->user->assignRole('Cashier');

        $this->seedBootstrapData($this->companyA);
    }

    private function seedBootstrapData(Company $company): void
    {
        app(CompanyContext::class)->setCompany($company, $this->user);

        $warehouse = Warehouse::create([
            'company_id' => $company->id,
            'code' => 'WH-MAIN',
            'name_ar' => 'المستودع الرئيسي',
            'name_en' => 'Main Warehouse',
            'active' => true,
            'is_default' => true,
            'created_by' => $this->user->id,
        ]);

        $customer = Customer::create([
            'company_id' => $company->id,
            'code' => 'CUST-WALK-IN',
            'name_ar' => 'عميل نقدي',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $ledgerAccount = LedgerAccount::create([
            'company_id' => $company->id,
            'code' => '1010',
            'name_ar' => 'النقدية',
            'name_en' => 'Cash',
            'account_type' => LedgerAccount::TYPE_ASSET,
            'normal_balance' => LedgerAccount::BALANCE_DEBIT,
            'is_control' => false,
            'is_system' => false,
            'active' => true,
        ]);

        $cash = MoneyAccount::create([
            'company_id' => $company->id,
            'account_type' => 'cash',
            'name_ar' => 'الصندوق الرئيسي',
            'currency_code' => $company->base_currency_code,
            'ledger_account_id' => $ledgerAccount->id,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $unit = Unit::create([
            'company_id' => $company->id,
            'code' => 'PCS',
            'name_ar' => 'قطعة',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);

        $tax = TaxRate::create([
            'company_id' => $company->id,
            'code' => 'VAT16',
            'name_ar' => 'ضريبة القيمة المضافة',
            'rate' => '16.000000',
            'calculation' => 'exclusive',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        CompanyMobileSetting::create([
            'company_id' => $company->id,
            'walk_in_customer_id' => $customer->id,
            'approved_cash_account_id' => $cash->id,
            'default_warehouse_id' => $warehouse->id,
            'default_tax_rate_id' => $tax->id,
        ]);

        app(CompanyContext::class)->clear();
    }

    private function issueToken(string $deviceId): string
    {
        $raw = 'st_m_'.bin2hex(random_bytes(32));
        MobileDevice::firstOrCreate(['device_id' => $deviceId], ['device_name' => 'Tablet', 'user_id' => $this->user->id]);

        MobileAccessToken::create([
            'token_hash' => hash('sha256', $raw),
            'device_id' => $deviceId,
            'user_id' => $this->user->id,
            'company_id' => $this->companyA->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        return $raw;
    }

    public function test_expired_token_rejected_with_401(): void
    {
        $deviceId = (string) Str::uuid();
        $token = $this->issueToken($deviceId);

        // Fast-forward 8 days past 7-day TTL
        Carbon::setTestNow(now()->addDays(8));

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->companyA->public_id}/bootstrap");

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_password_change_invalidates_old_tokens(): void
    {
        $deviceId = (string) Str::uuid();
        $token = $this->issueToken($deviceId);

        // Verify token works initially
        $resp1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->companyA->public_id}/bootstrap");
        $resp1->assertOk();

        // User changes password
        $this->user->update(['password' => Hash::make('new-secure-password')]);

        // Same token now fails with 401 PASSWORD_REVISED
        $resp2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->companyA->public_id}/bootstrap");
        $resp2->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.details.reason', 'PASSWORD_REVISED');
    }

    public function test_membership_revocation_denies_company_access(): void
    {
        $deviceId = (string) Str::uuid();
        $token = $this->issueToken($deviceId);

        // Deactivate user's membership in Company A
        $this->user->companies()->updateExistingPivot($this->companyA->id, ['status' => 'inactive']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->companyA->public_id}/bootstrap");

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_MISMATCH');
    }

    public function test_tenant_mismatch_denied_before_lookup(): void
    {
        $deviceId = (string) Str::uuid();
        $token = $this->issueToken($deviceId);

        // User authenticated in Company A attempts to access Company B
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->companyB->public_id}/bootstrap");

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_MISMATCH')
            ->assertJsonPath('error.details.requested_company_id', $this->companyB->public_id);
    }

    public function test_mobile_token_does_not_forge_browser_session_or_bypass_guard(): void
    {
        $deviceId = (string) Str::uuid();
        $token = $this->issueToken($deviceId);

        // Calling browser command API with Bearer token must fail at Phase11ApiGuard / auth.session
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/session');

        // Phase11 command API requires web session + cookie auth + Phase11ApiGuard, which rejects bearer token
        $this->assertNotEquals(200, $response->getStatusCode());
    }
}
