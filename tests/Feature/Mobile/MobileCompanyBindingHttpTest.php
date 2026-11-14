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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileCompanyBindingHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $companyA;

    private Company $companyB;

    private Company $companyC;

    private string $password = 'BindingTest!Pass123';

    public function getJson($uri, array $headers = [], $options = 0)
    {
        return $this->get($uri, array_merge(['Accept' => 'application/json'], $headers));
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        config(['mobile.enabled' => true]);
        config(['mobile.v2_enabled' => true]);

        $this->user = User::factory()->create([
            'email' => 'binding_staff_'.Str::random(8).'@example.com',
            'password' => Hash::make($this->password),
        ]);

        $this->companyA = Company::factory()->create([
            'name_ar' => 'شركة الربط أ',
            'name_en' => 'Binding Co A',
            'base_currency_code' => 'ILS',
            'status' => 'active',
        ]);

        $this->companyB = Company::factory()->create([
            'name_ar' => 'شركة الربط ب',
            'name_en' => 'Binding Co B',
            'base_currency_code' => 'USD',
            'status' => 'active',
        ]);

        $this->companyC = Company::factory()->create([
            'name_ar' => 'شركة أجنبية ج',
            'name_en' => 'Foreign Co C',
            'base_currency_code' => 'JOD',
            'status' => 'active',
        ]);

        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->companyA);
        app(CompanyRoleService::class)->seedCompanyRoles($this->companyB);
        app(CompanyRoleService::class)->seedCompanyRoles($this->companyC);

        // User is active member in Company A and Company B
        $this->user->companies()->attach($this->companyA->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->companyA->id);
        $this->user->assignRole('Cashier');

        $this->user->companies()->attach($this->companyB->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->companyB->id);
        $this->user->assignRole('Cashier');

        // User is NOT a member in Company C

        // Seed basic operational settings for V1 endpoints
        $this->seedCompanyV1Fixtures($this->companyA);
        $this->seedCompanyV1Fixtures($this->companyB);

        app(CompanyContext::class)->clear();

        $this->registerV2HarnessRoute();
    }

    private function seedCompanyV1Fixtures(Company $company): void
    {
        \App\Support\Tenancy\CompanyScope::executeWithoutScope(function () use ($company): void {
            $customer = Customer::create([
                'company_id' => $company->id,
                'code' => 'CUST-'.Str::random(6),
                'name_ar' => 'عميل '.$company->name_ar,
                'status' => 'active',
                'created_by' => $this->user->id,
            ]);

            $warehouse = Warehouse::create([
                'company_id' => $company->id,
                'code' => 'WH-'.Str::random(4),
                'name_ar' => 'مستودع '.$company->name_ar,
                'is_default' => true,
                'active' => true,
                'created_by' => $this->user->id,
            ]);

            $ledgerAccount = LedgerAccount::create([
                'company_id' => $company->id,
                'code' => '101-'.Str::random(4),
                'name_ar' => 'صندوق',
                'account_type' => LedgerAccount::TYPE_ASSET,
                'normal_balance' => LedgerAccount::BALANCE_DEBIT,
                'is_control' => false,
                'is_system' => false,
                'active' => true,
            ]);

            $cashAccount = MoneyAccount::create([
                'company_id' => $company->id,
                'account_type' => 'cash',
                'name_ar' => 'صندوق نقاط البيع',
                'currency_code' => $company->base_currency_code,
                'ledger_account_id' => $ledgerAccount->id,
                'is_active' => true,
                'created_by' => $this->user->id,
            ]);

            $unit = Unit::create([
                'company_id' => $company->id,
                'code' => 'PCS-'.Str::random(3),
                'name_ar' => 'قطعة',
                'allows_fraction' => false,
                'decimal_places' => 0,
                'active' => true,
            ]);

            $taxRate = TaxRate::create([
                'company_id' => $company->id,
                'code' => 'VAT-'.Str::random(3),
                'name_ar' => 'ضريبة',
                'rate' => '16.000000',
                'calculation' => 'exclusive',
                'active' => true,
                'created_by' => $this->user->id,
            ]);

            CompanyMobileSetting::create([
                'company_id' => $company->id,
                'walk_in_customer_id' => $customer->id,
                'approved_cash_account_id' => $cashAccount->id,
                'default_warehouse_id' => $warehouse->id,
                'default_tax_rate_id' => $taxRate->id,
                'catalog_snapshot_version' => 'snap-test',
            ]);
        });
    }

    private function registerV2HarnessRoute(): void
    {
        $router = $this->app['router'];
        $router->prefix('api/mobile/v2/test-harness')
            ->group(function () use ($router): void {
                $router->get('/companies/{companyId}/read-probe', function () {
                    return response()->json(['status' => 'ok', 'scope' => 'company-read']);
                })->middleware('mobile.v2.auth:product.viewAny');
            });
    }

    private function issueToken(?int $companyId = null): string
    {
        $raw = 'st_m_'.bin2hex(random_bytes(32));
        $deviceId = (string) Str::uuid();

        MobileDevice::firstOrCreate(
            ['device_id' => $deviceId],
            ['device_name' => 'Binding Test Device', 'user_id' => $this->user->id]
        );

        MobileAccessToken::create([
            'token_hash' => hash('sha256', $raw),
            'device_id' => $deviceId,
            'user_id' => $this->user->id,
            'company_id' => $companyId,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        return $raw;
    }

    // =========================================================================
    // V1 HTTP Kernel Integration Tests
    // =========================================================================

    public function test_v1_bound_token_mismatch_returns_403_when_actor_active_in_both_companies(): void
    {
        // Token explicitly bound to Company A
        $tokenBoundA = $this->issueToken(companyId: $this->companyA->id);

        // Attempting to access Company B with token bound to Company A returns 403 FORBIDDEN_TENANT_MISMATCH
        $response = $this->withHeader('Authorization', "Bearer {$tokenBoundA}")
            ->getJson("/api/mobile/v1/companies/{$this->companyB->public_id}/customers");

        $response->assertStatus(403)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN_TENANT_MISMATCH',
                    'message' => 'User does not possess active authorized membership in the requested company context.',
                ],
            ]);

        // Verify context is clean and not contaminated with Company B
        $this->assertFalse(app(CompanyContext::class)->hasCompany());
    }

    public function test_v1_bound_token_matching_company_succeeds(): void
    {
        $tokenBoundA = $this->issueToken(companyId: $this->companyA->id);

        $response = $this->withHeader('Authorization', "Bearer {$tokenBoundA}")
            ->getJson("/api/mobile/v1/companies/{$this->companyA->public_id}/customers");

        $response->assertStatus(200)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
    }

    public function test_v1_null_staff_token_accepted_across_all_active_member_companies(): void
    {
        // Unbound staff+device token (company_id = null)
        $tokenNullStaff = $this->issueToken(companyId: null);

        // Succeeds on Company A
        $resA = $this->withHeader('Authorization', "Bearer {$tokenNullStaff}")
            ->getJson("/api/mobile/v1/companies/{$this->companyA->public_id}/customers");
        $resA->assertStatus(200);

        // Succeeds on Company B
        $resB = $this->withHeader('Authorization', "Bearer {$tokenNullStaff}")
            ->getJson("/api/mobile/v1/companies/{$this->companyB->public_id}/customers");
        $resB->assertStatus(200);
    }

    public function test_v1_null_staff_token_rejected_on_foreign_company_where_user_has_no_membership(): void
    {
        $tokenNullStaff = $this->issueToken(companyId: null);

        // Company C has no membership for user -> 403
        $response = $this->withHeader('Authorization', "Bearer {$tokenNullStaff}")
            ->getJson("/api/mobile/v1/companies/{$this->companyC->public_id}/customers");

        $response->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN_TENANT_MISMATCH',
                    'message' => 'User does not possess active authorized membership in the requested company context.',
                ],
            ]);
    }

    // =========================================================================
    // V2 HTTP Kernel Integration Tests
    // =========================================================================

    public function test_v2_bound_token_mismatch_returns_403_when_actor_active_in_both_companies(): void
    {
        $tokenBoundA = $this->issueToken(companyId: $this->companyA->id);

        $response = $this->withHeader('Authorization', "Bearer {$tokenBoundA}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyB->public_id.'/read-probe');

        $response->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN_TENANT_MISMATCH',
                    'message' => 'User does not possess active authorized membership in the requested company context.',
                ],
            ]);

        $this->assertFalse(app(CompanyContext::class)->hasCompany());
    }

    public function test_v2_bound_token_matching_company_succeeds(): void
    {
        $tokenBoundA = $this->issueToken(companyId: $this->companyA->id);

        $response = $this->withHeader('Authorization', "Bearer {$tokenBoundA}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok', 'scope' => 'company-read']);
    }

    public function test_v2_null_staff_token_accepted_across_all_active_member_companies(): void
    {
        $tokenNullStaff = $this->issueToken(companyId: null);

        $resA = $this->withHeader('Authorization', "Bearer {$tokenNullStaff}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');
        $resA->assertStatus(200)
            ->assertJson(['status' => 'ok', 'scope' => 'company-read']);

        $resB = $this->withHeader('Authorization', "Bearer {$tokenNullStaff}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyB->public_id.'/read-probe');
        $resB->assertStatus(200)
            ->assertJson(['status' => 'ok', 'scope' => 'company-read']);
    }

    public function test_v2_null_staff_token_rejected_on_foreign_company_where_user_has_no_membership(): void
    {
        $tokenNullStaff = $this->issueToken(companyId: null);

        $response = $this->withHeader('Authorization', "Bearer {$tokenNullStaff}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyC->public_id.'/read-probe');

        $response->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN_TENANT_MISMATCH',
                    'message' => 'User does not possess active authorized membership in the requested company context.',
                ],
            ]);
    }
}
