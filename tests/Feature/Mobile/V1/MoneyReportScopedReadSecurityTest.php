<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V1;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Livewire\Pages\Money\AccountDetail;
use App\Livewire\Pages\Money\Overview;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyMobileSetting;
use App\Models\Currency;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\Money\MoneyActorGuard;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MoneyReportScopedReadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private MoneyAccount $cashAccount;

    private MoneyAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mobile.enabled' => true, 'mobile.merchant_api_enabled' => true]);

        Currency::updateOrCreate(['code' => 'ILS'], ['name' => 'Shekel', 'symbol' => 'ILS', 'minor_units' => 2, 'active' => true]);

        $this->owner = User::factory()->create(['name' => 'مالك النظام']);
        $creator = app(CreateCompanyAction::class);
        $this->company = $creator->execute($this->owner, [
            'name_ar' => 'شركة حماية التقارير المالية',
            'base_currency_code' => 'ILS',
            'timezone' => 'Asia/Hebron',
        ]);

        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);
        $this->owner->assignRole('Owner');
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        CompanyCurrency::firstOrCreate(
            ['company_id' => $this->company->id, 'currency_code' => 'ILS'],
            ['enabled' => true, 'is_base' => true]
        );

        $moneyAccountAction = app(CreateMoneyAccountAction::class);
        $this->cashAccount = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق تجريبي',
            'name_en' => 'Test Cash Account',
        ]);

        $this->bankAccount = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_BANK,
            'currency_code' => 'ILS',
            'name_ar' => 'بنك تجريبي',
            'name_en' => 'Test Bank Account',
        ]);

        CompanyMobileSetting::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'approved_cash_account_id' => $this->cashAccount->id,
            ]
        );
    }

    /**
     * @param  list<string>  $permissions
     * @return array{headers: array<string, string>, user: User, device: MobileDevice}
     */
    private function createActorWithPermissions(string $name, array $permissions): array
    {
        $actor = User::factory()->create(['name' => $name]);
        $this->company->users()->attach($actor->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);

        $role = Role::findOrCreate('Role_'.Str::random(8), 'web');
        $role->syncPermissions($permissions);
        $actor->assignRole($role);

        $deviceId = (string) Str::uuid();
        $device = MobileDevice::create(['device_id' => $deviceId, 'device_name' => $name.' Device', 'user_id' => $actor->id]);
        $rawToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $rawToken),
            'device_id' => $deviceId,
            'user_id' => $actor->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', $actor->password), 0, 16),
            'expires_at' => now()->addHour(),
        ]);

        return [
            'headers' => ['Authorization' => 'Bearer '.$rawToken],
            'user' => $actor,
            'device' => $device,
        ];
    }

    public function test_reports_only_actor_can_access_mobile_reports_and_scoped_report_balances_query(): void
    {
        $actorData = $this->createActorWithPermissions('مراقب مالي فقط', ['reports.financial.view']);
        $headers = $actorData['headers'];
        $user = $actorData['user'];

        // 1. Mobile endpoints succeed
        $this->withHeaders($headers)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-summary")
            ->assertOk()
            ->assertJsonStructure(['company_id', 'shift_date', 'currency', 'expected_cash']);

        $this->withHeaders(array_merge($headers, ['Idempotency-Key' => 'close-'.Str::random(10)]))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-close", [
                'counted_cash' => '100.00',
            ])
            ->assertOk()
            ->assertJsonPath('observation_recorded', true);

        $this->withHeaders($headers)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/treasury")
            ->assertOk()
            ->assertJsonStructure(['company_id', 'cash_accounts', 'bank_accounts']);

        // 2. Direct forReport query succeeds under reports.financial.view
        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($this->company, $user);
        setPermissionsTeamId($this->company->id);

        $cashBalances = app(MoneyBalanceQuery::class)->forReport($this->company, 'cash');
        $this->assertIsArray($cashBalances);
        $this->assertNotEmpty($cashBalances);
        $publicIds = array_column($cashBalances, 'public_id');
        $this->assertContains($this->cashAccount->public_id, $publicIds);

        $bankBalances = app(MoneyBalanceQuery::class)->forReport($this->company, 'bank');
        $this->assertIsArray($bankBalances);
        $this->assertNotEmpty($bankBalances);
        $this->assertSame($this->bankAccount->public_id, $bankBalances[0]['public_id']);
    }

    public function test_reports_only_actor_is_forbidden_from_money_actor_guard_for_type_and_web_money_ui(): void
    {
        $actorData = $this->createActorWithPermissions('مراقب مالي محروم من الخزينة', ['reports.financial.view']);
        $user = $actorData['user'];

        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($this->company, $user);
        setPermissionsTeamId($this->company->id);

        // 1. MoneyActorGuard::authorize strictly requires money permissions
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Money permission is required.');
        app(MoneyActorGuard::class)->authorize($this->company->id, 'money.cash.view');
    }

    public function test_reports_only_actor_is_forbidden_from_money_balance_for_type(): void
    {
        $actorData = $this->createActorWithPermissions('مراقب مالي محروم من كشف النقدية', ['reports.financial.view']);
        $user = $actorData['user'];

        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($this->company, $user);
        setPermissionsTeamId($this->company->id);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Money permission is required.');
        app(MoneyBalanceQuery::class)->forType($this->company, 'cash');
    }

    public function test_reports_only_actor_is_forbidden_from_web_money_overview_and_account_detail(): void
    {
        $actorData = $this->createActorWithPermissions('مراقب مالي محظور من واجهة الخزينة', ['reports.financial.view']);
        $user = $actorData['user'];

        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($this->company, $user);
        setPermissionsTeamId($this->company->id);

        // Web Money Overview with type=cash must be forbidden
        Livewire::actingAs($user)
            ->test(Overview::class, ['type' => 'cash'])
            ->assertForbidden();

        // Web Money Overview default (type=null) must be forbidden
        Livewire::actingAs($user)
            ->test(Overview::class, [])
            ->assertForbidden();

        // Web Money Account Detail must be forbidden
        Livewire::actingAs($user)
            ->test(AccountDetail::class, ['publicId' => $this->cashAccount->public_id])
            ->assertForbidden();
    }

    public function test_cash_only_actor_is_forbidden_from_mobile_reports_and_for_report_query_but_allowed_on_for_type(): void
    {
        $actorData = $this->createActorWithPermissions('أمين صندوق بلا تقارير', ['money.cash.view']);
        $headers = $actorData['headers'];
        $user = $actorData['user'];

        // 1. Mobile report endpoints return 403 Forbidden
        $this->withHeaders($headers)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-summary")
            ->assertStatus(403);

        $this->withHeaders(array_merge($headers, ['Idempotency-Key' => 'close-'.Str::random(10)]))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-close", [
                'counted_cash' => '100.00',
            ])
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/treasury")
            ->assertStatus(403);

        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($this->company, $user);
        setPermissionsTeamId($this->company->id);

        // 2. Direct forReport throws AuthorizationException
        try {
            app(MoneyBalanceQuery::class)->forReport($this->company, 'cash');
            $this->fail('Expected AuthorizationException on forReport for cash-only user');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Reports financial permission is required.', $e->getMessage());
        }

        // 3. Direct forType succeeds
        $cashBalances = app(MoneyBalanceQuery::class)->forType($this->company, 'cash');
        $this->assertIsArray($cashBalances);
        $this->assertNotEmpty($cashBalances);
        $publicIds = array_column($cashBalances, 'public_id');
        $this->assertContains($this->cashAccount->public_id, $publicIds);

        // 4. MoneyActorGuard::authorize succeeds
        $authorizedUser = app(MoneyActorGuard::class)->authorize($this->company->id, 'money.cash.view');
        $this->assertSame($user->id, $authorizedUser->id);

        // 5. Web Money Overview renders cash accounts
        Livewire::actingAs($user)
            ->test(Overview::class, ['type' => 'cash'])
            ->assertStatus(200);
    }

    public function test_neither_permission_actor_is_forbidden_from_both_mobile_reports_and_money_queries(): void
    {
        $actorData = $this->createActorWithPermissions('مستخدم فواتير فقط', ['sales.invoice.view']);
        $headers = $actorData['headers'];
        $user = $actorData['user'];

        $this->withHeaders($headers)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-summary")
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/treasury")
            ->assertStatus(403);

        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($this->company, $user);
        setPermissionsTeamId($this->company->id);

        try {
            app(MoneyBalanceQuery::class)->forReport($this->company, 'cash');
            $this->fail('Expected AuthorizationException on forReport for neither-permission user');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Reports financial permission is required.', $e->getMessage());
        }

        try {
            app(MoneyBalanceQuery::class)->forType($this->company, 'cash');
            $this->fail('Expected AuthorizationException on forType for neither-permission user');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Money permission is required.', $e->getMessage());
        }
    }

    public function test_for_report_enforces_strict_tenant_isolation_active_membership_and_valid_type(): void
    {
        $actorData = $this->createActorWithPermissions('مراقب مالي شركة أ', ['reports.financial.view']);
        $user = $actorData['user'];

        // Clear context before creating Company B
        app(CompanyContext::class)->clear();

        // Create Company B
        $otherCompany = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة ثانية منعزلة',
            'base_currency_code' => 'ILS',
            'timezone' => 'Asia/Hebron',
        ]);
        app(CompanyRoleService::class)->seedCompanyRoles($otherCompany);

        // User belongs to Company A, but tries to query Company B
        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($this->company, $user);
        setPermissionsTeamId($this->company->id);

        try {
            app(MoneyBalanceQuery::class)->forReport($otherCompany, 'cash');
            $this->fail('Expected AuthorizationException for cross-company query');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Active matching company membership is required.', $e->getMessage());
        }

        // Deactivated user in company cannot query
        $this->company->users()->updateExistingPivot($user->id, ['status' => 'inactive']);
        try {
            app(MoneyBalanceQuery::class)->forReport($this->company, 'cash');
            $this->fail('Expected AuthorizationException for deactivated member');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Active matching company membership is required.', $e->getMessage());
        }

        // Restore active user, test invalid account type
        $this->company->users()->updateExistingPivot($user->id, ['status' => 'active']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported money account type.');
        app(MoneyBalanceQuery::class)->forReport($this->company, 'crypto');
    }

    public function test_mobile_home_drawer_cash_redacted_for_reports_only_and_visible_for_cash_view(): void
    {
        $reportsActor = $this->createActorWithPermissions('مراقب مالي فقط', ['reports.financial.view']);
        $cashActor = $this->createActorWithPermissions('أمين صندوق', ['money.cash.view']);

        // Reports-only actor sees cash_in_drawer as null (redacted)
        $respReports = $this->withHeaders($reportsActor['headers'])
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/home");
        $respReports->assertOk()
            ->assertJsonPath('cash_in_drawer', null);

        // Cash-view actor sees non-null cash_in_drawer
        $respCash = $this->withHeaders($cashActor['headers'])
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/home");
        $respCash->assertOk()
            ->assertJsonPath('cash_in_drawer', '0.00');
    }
}
