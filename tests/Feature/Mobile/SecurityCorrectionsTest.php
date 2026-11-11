<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\Company;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SecurityCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $actor): string
    {
        $device = (string) Str::uuid();
        MobileDevice::create(['device_id' => $device, 'device_name' => 'Owned', 'user_id' => $actor->id]);
        $raw = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create(['token_hash' => hash('sha256', $raw), 'user_id' => $actor->id,
            'device_id' => $device, 'password_epoch' => 'epoch-'.substr(hash('sha256', $actor->password), 0, 16),
            'expires_at' => now()->addDay()]);

        return $raw;
    }

    public function test_customized_administrator_without_grants_cannot_read_any_tenant_endpoint(): void
    {
        config(['mobile.enabled' => true]);
        $user = User::factory()->create();
        $company = Company::factory()->create(['status' => 'active']);
        $user->companies()->attach($company->id, ['status' => 'active']);
        app(CompanyRoleService::class)->seedCompanyRoles($company);
        setPermissionsTeamId($company->id);
        $user->assignRole('Administrator');
        /** @var Role $role */
        $role = $user->roles()->firstOrFail();
        $role->syncPermissions([]);
        $token = $this->token($user);
        foreach (['products', 'customers', 'bootstrap'] as $endpoint) {
            $this->withToken($token)->getJson("/api/mobile/v1/companies/{$company->public_id}/{$endpoint}")->assertForbidden();
            $this->assertFalse(app(CompanyContext::class)->hasCompany());
            $this->assertNull(getPermissionsTeamId());
        }
    }

    public function test_multiple_authorized_companies_do_not_mutate_token_and_revoked_grants_take_effect(): void
    {
        config(['mobile.enabled' => true]);
        app(CompanyRoleService::class)->ensurePermissionsExist();
        $user = User::factory()->create();
        $token = $this->token($user);
        foreach (range(1, 2) as $i) {
            $company = Company::factory()->create(['status' => 'active']);
            $user->companies()->attach($company->id, ['status' => 'active']);
            setPermissionsTeamId($company->id);
            $user->unsetRelation('permissions')->givePermissionTo('customers.view');
            $this->withToken($token)->getJson("/api/mobile/v1/companies/{$company->public_id}/customers")->assertOk();
            $this->assertNull(MobileAccessToken::firstOrFail()->company_id);
            setPermissionsTeamId($company->id);
            $user->unsetRelation('permissions')->revokePermissionTo('customers.view');
            $this->withToken($token)->getJson("/api/mobile/v1/companies/{$company->public_id}/customers")->assertForbidden();
        }
    }

    public function test_foreign_and_unknown_device_cannot_be_revoked_or_claimed(): void
    {
        config(['mobile.enabled' => true]);
        $user = User::factory()->create(['password' => 'password']);
        $company = Company::factory()->create(['status' => 'active']);
        $user->companies()->attach($company->id, ['status' => 'active']);
        $token = $this->token($user);
        $foreign = MobileDevice::create(['device_id' => (string) Str::uuid(), 'device_name' => 'Foreign', 'user_id' => User::factory()->create()->id]);
        foreach ([$foreign->device_id, (string) Str::uuid()] as $id) {
            $this->withToken($token)->postJson("/api/mobile/v1/devices/{$id}/revoke", ['reason' => 'test'])->assertForbidden();
        }
        $challenge = $this->postJson('/api/mobile/v1/auth/challenge', ['device_id' => $foreign->device_id, 'app_version' => '1', 'platform' => 'android'])->json();
        unset($challenge['expires_at']);
        $this->postJson('/api/mobile/v1/auth/login', $challenge + ['device_id' => $foreign->device_id, 'device_name' => 'Attack', 'email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $this->assertNull($foreign->fresh()->revoked_at);
        $this->assertSame('Foreign', $foreign->fresh()->device_name);
        $this->assertSame(2, MobileDevice::count());
    }

    public function test_account_throttle_survives_device_and_ip_rotation(): void
    {
        config(['mobile.enabled' => true]);
        RateLimiter::clear('mobile_login_actor:'.hash('sha256', 'target@example.com'));
        for ($i = 0; $i < 6; $i++) {
            $id = (string) Str::uuid();
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.'.($i + 2)]);
            $challenge = $this->postJson('/api/mobile/v1/auth/challenge', ['device_id' => $id, 'app_version' => '1', 'platform' => 'android'])->json();
            unset($challenge['expires_at']);
            $response = $this->postJson('/api/mobile/v1/auth/login', $challenge + ['device_id' => $id, 'device_name' => 'Rotated', 'email' => 'target@example.com', 'password' => 'wrong']);
            $response->assertStatus($i === 5 ? 429 : 401);
        }
    }

    public function test_mature_long_customer_name_fails_truthfully_without_truncating(): void
    {
        config(['mobile.enabled' => true]);
        app(CompanyRoleService::class)->ensurePermissionsExist();
        $user = User::factory()->create();
        $company = Company::factory()->create(['status' => 'active']);
        $user->companies()->attach($company->id, ['status' => 'active']);
        app(CompanyContext::class)->setCompany($company, $user);
        $user->givePermissionTo('customers.view');
        $name = str_repeat('ع', 255);
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'CUST-255', 'name_ar' => $name, 'status' => 'active', 'created_by' => $user->id]);
        $token = $this->token($user);
        $response = $this->withToken($token)->getJson("/api/mobile/v1/companies/{$company->public_id}/customers")
            ->assertOk();
        $this->assertSame($name, $response->json('customers.0.name'));
        app(CompanyContext::class)->setCompany($company, $user);
        $this->assertSame($name, $customer->fresh()->name_ar);
    }

    public function test_authenticated_limit_is_actor_and_company_scoped(): void
    {
        config(['mobile.enabled' => true, 'mobile.rate_limits.authenticated_per_minute' => 1]);
        app(CompanyRoleService::class)->ensurePermissionsExist();
        $user = User::factory()->create();
        $company = Company::factory()->create(['status' => 'active']);
        $user->companies()->attach($company->id, ['status' => 'active']);
        setPermissionsTeamId($company->id);
        $user->givePermissionTo('customers.view');
        foreach ([200, 429] as $status) {
            $token = $this->token($user); // Device rotation does not reset actor/company quota.
            $this->withToken($token)->getJson("/api/mobile/v1/companies/{$company->public_id}/customers")->assertStatus($status);
        }
    }

    public function test_permission_cache_not_thrashed_and_tenant_context_cleaned_on_exception_paths(): void
    {
        config(['mobile.enabled' => true]);
        app(CompanyRoleService::class)->ensurePermissionsExist();

        $user = User::factory()->create();
        $companyA = Company::factory()->create(['status' => 'active']);
        $companyB = Company::factory()->create(['status' => 'active']);
        $user->companies()->attach([$companyA->id, $companyB->id], ['status' => 'active']);

        setPermissionsTeamId($companyA->id);
        $user->givePermissionTo('customers.view');
        setPermissionsTeamId($companyB->id);
        $user->givePermissionTo('customers.view');

        $token = $this->token($user);

        // 1. Request to company A cleans context cleanly
        $this->withToken($token)->getJson("/api/mobile/v1/companies/{$companyA->public_id}/customers")->assertOk();
        $this->assertFalse(app(CompanyContext::class)->hasCompany());
        $this->assertNull(getPermissionsTeamId());

        // 2. Exception path (invalid route/unauthorized endpoint) ensures context cleanup in finally
        $this->withToken($token)->getJson("/api/mobile/v1/companies/{$companyA->public_id}/non-existent-endpoint")->assertNotFound();
        $this->assertFalse(app(CompanyContext::class)->hasCompany());
        $this->assertNull(getPermissionsTeamId());

        // 3. Request to company B succeeds with company B team isolation
        $this->withToken($token)->getJson("/api/mobile/v1/companies/{$companyB->public_id}/customers")->assertOk();
        $this->assertFalse(app(CompanyContext::class)->hasCompany());
        $this->assertNull(getPermissionsTeamId());

        // 4. Immediate revocation in company B takes effect without affecting company A
        setPermissionsTeamId($companyB->id);
        $user->unsetRelation('permissions')->revokePermissionTo('customers.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withToken($token)->getJson("/api/mobile/v1/companies/{$companyB->public_id}/customers")->assertForbidden();
        $this->withToken($token)->getJson("/api/mobile/v1/companies/{$companyA->public_id}/customers")->assertOk();
    }
}
