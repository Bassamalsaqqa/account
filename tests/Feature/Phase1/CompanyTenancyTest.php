<?php

namespace Tests\Feature\Phase1;

use App\Actions\Company\CreateCompanyAction;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected CreateCompanyAction $createAction;

    protected CompanyContext $context;

    protected User $userA;

    protected User $userB;

    protected User $sharedUser;

    protected Company $companyA;

    protected Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAction = app(CreateCompanyAction::class);
        $this->context = app(CompanyContext::class);

        // User A & Company A
        $this->userA = User::factory()->create(['name' => 'User A', 'email' => 'user.a@example.com']);
        $this->companyA = $this->createAction->execute($this->userA, [
            'name_ar' => 'شركة أ',
            'name_en' => 'Company A',
            'base_currency_code' => 'ILS',
        ]);

        // User B & Company B
        $this->userB = User::factory()->create(['name' => 'User B', 'email' => 'user.b@example.com']);
        $this->companyB = $this->createAction->execute($this->userB, [
            'name_ar' => 'شركة ب',
            'name_en' => 'Company B',
            'base_currency_code' => 'USD',
        ]);

        // Shared User: Admin in Company A, Viewer in Company B
        $this->sharedUser = User::factory()->create(['name' => 'Shared User', 'email' => 'shared@example.com']);

        // Attach to Company A as Administrator
        CompanyUser::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->sharedUser->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->companyA->id);
        $adminRoleA = Role::where('company_id', $this->companyA->id)->where('name', 'Administrator')->firstOrFail();
        $this->sharedUser->assignRole($adminRoleA);

        // Attach to Company B as Viewer
        CompanyUser::create([
            'company_id' => $this->companyB->id,
            'user_id' => $this->sharedUser->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->companyB->id);
        $viewerRoleB = Role::where('company_id', $this->companyB->id)->where('name', 'Viewer')->firstOrFail();
        $this->sharedUser->assignRole($viewerRoleB);

        $this->context->clear();
    }

    public function test_user_a_cannot_update_company_b_settings(): void
    {
        $this->context->setCompany($this->companyA, $this->userA);

        $this->assertTrue($this->userA->can('update', $this->companyA));
        $this->assertFalse($this->userA->can('update', $this->companyB));
    }

    public function test_ordinary_request_data_cannot_switch_company(): void
    {
        // Shared user is currently in Company A
        $response = $this->actingAs($this->sharedUser)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->get('/settings?company_id='.$this->companyB->id);

        $response->assertOk();
        // Context should remain Company A
        $this->assertSame($this->companyA->id, $this->context->companyId());
        $this->assertSame($this->companyA->id, session('active_company_id'));
    }

    public function test_csrf_protected_company_switch_route_switches_company(): void
    {
        $response = $this->actingAs($this->sharedUser)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->post(route('company.switch'), [
                'public_id' => $this->companyB->public_id,
            ]);

        $response->assertRedirect(route('settings.index'));
        $this->assertSame($this->companyB->id, session('active_company_id'));
        $this->assertSame($this->companyB->id, $this->sharedUser->fresh()->last_active_company_id);
    }

    public function test_forged_or_unowned_company_switch_fails(): void
    {
        // User A attempts to switch to Company B (not a member)
        $this->actingAs($this->userA)
            ->withSession(['active_company_id' => $this->companyA->id]);

        $this->expectException(AuthorizationException::class);
        $this->context->switchCompany($this->userA, $this->companyB);
    }

    public function test_inactive_or_non_member_cannot_activate_company(): void
    {
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        $this->context->switchCompany($outsider, $this->companyA);
    }

    public function test_stale_last_active_company_id_never_grants_access_without_active_membership(): void
    {
        // User A has last_active_company_id tampered to Company B
        $this->userA->update(['last_active_company_id' => $this->companyB->id]);

        $resolved = $this->context->resolveForUser($this->userA);

        // Must not resolve Company B; User A is not a member of B, resolves Company A
        $this->assertNotNull($resolved);
        $this->assertSame($this->companyA->id, $resolved->id);
    }

    public function test_shared_user_a_role_does_not_grant_b_capability(): void
    {
        // In Company A, shared user is Administrator
        $this->context->setCompany($this->companyA, $this->sharedUser);
        $this->assertTrue($this->sharedUser->can('settings.company.manage'));
        $this->assertTrue($this->sharedUser->hasRole('Administrator'));
        $this->assertFalse($this->sharedUser->hasRole('Viewer'));

        // In Company B, shared user is Viewer
        $this->context->setCompany($this->companyB, $this->sharedUser);
        $this->assertFalse($this->sharedUser->can('settings.company.manage'));
        $this->assertFalse($this->sharedUser->hasRole('Administrator'));
        $this->assertTrue($this->sharedUser->hasRole('Viewer'));
    }

    public function test_switching_company_updates_spatie_team_and_clears_cached_relations(): void
    {
        $this->context->setCompany($this->companyA, $this->sharedUser);
        $this->assertSame($this->companyA->id, getPermissionsTeamId());
        $this->assertTrue($this->sharedUser->hasRole('Administrator'));

        $this->context->switchCompany($this->sharedUser, $this->companyB);

        $this->assertSame($this->companyB->id, getPermissionsTeamId());
        $this->assertSame($this->companyB->id, session('active_company_id'));
        $this->assertSame($this->companyB->id, $this->sharedUser->fresh()->last_active_company_id);

        $this->assertTrue($this->sharedUser->hasRole('Viewer'));
        $this->assertFalse($this->sharedUser->hasRole('Administrator'));
    }

    public function test_company_owned_queries_fail_closed_without_context(): void
    {
        $this->context->clear();

        // When context is absent, company-scoped queries fail closed (return 0 rows)
        $this->assertSame(0, CompanyCurrency::count());
        $this->assertTrue(CompanyCurrency::all()->isEmpty());
    }

    public function test_company_owned_model_creation_without_context_throws_exception(): void
    {
        $this->context->clear();

        $this->expectException(NoActiveCompanyException::class);
        CompanyCurrency::create([
            'currency_code' => 'ILS',
            'enabled' => true,
            'is_base' => true,
        ]);
    }

    public function test_company_owned_model_creation_with_mismatched_company_id_throws_exception(): void
    {
        $this->context->setCompany($this->companyA, $this->userA);

        $this->expectException(CompanyReassignmentException::class);
        CompanyCurrency::create([
            'company_id' => $this->companyB->id,
            'currency_code' => 'USD',
            'enabled' => true,
            'is_base' => false,
        ]);
    }

    public function test_company_scope_bypass_allows_unscoped_queries(): void
    {
        $this->context->clear();

        $totalCount = CompanyScope::executeWithoutScope(fn () => CompanyCurrency::count());

        // 3 for Company A + 3 for Company B = 6
        $this->assertSame(6, $totalCount);
    }

    public function test_belongs_to_company_scopes_queries_and_prevents_cross_company_leakage(): void
    {
        $this->context->setCompany($this->companyA, $this->userA);
        $currenciesA = CompanyCurrency::all();
        $this->assertCount(3, $currenciesA);
        $this->assertTrue($currenciesA->every(fn ($c) => $c->company_id === $this->companyA->id));

        $this->context->setCompany($this->companyB, $this->userB);
        $currenciesB = CompanyCurrency::all();
        $this->assertCount(3, $currenciesB);
        $this->assertTrue($currenciesB->every(fn ($c) => $c->company_id === $this->companyB->id));
    }

    public function test_belongs_to_company_prevents_reassigning_company_id(): void
    {
        $this->context->setCompany($this->companyA, $this->userA);
        $curr = CompanyCurrency::firstOrFail();

        $this->expectException(CompanyReassignmentException::class);
        $this->expectExceptionMessage('Reassigning company ownership is prohibited.');

        $curr->update(['company_id' => $this->companyB->id]);
    }

    public function test_company_creation_defaults_exactly_once_with_one_base_currency(): void
    {
        $this->context->setCompany($this->companyA, $this->userA);

        $baseCurrencies = CompanyCurrency::where('company_id', $this->companyA->id)
            ->where('is_base', true)
            ->get();

        $this->assertCount(1, $baseCurrencies);
        $this->assertSame('ILS', $baseCurrencies->first()->currency_code);

        // Verify Owner membership
        $owners = CompanyUser::where('company_id', $this->companyA->id)
            ->where('is_owner', true)
            ->get();
        $this->assertCount(1, $owners);
        $this->assertSame($this->userA->id, $owners->first()->user_id);
    }

    public function test_owner_role_granted_only_in_created_company(): void
    {
        // User A is Owner in Company A
        $this->context->setCompany($this->companyA, $this->userA);
        $this->assertTrue($this->userA->hasRole('Owner'));

        // User A cannot set Company B as active because they are not a member
        $this->expectException(AuthorizationException::class);
        $this->context->setCompany($this->companyB, $this->userA);
    }

    public function test_zero_company_user_redirected_to_setup_state(): void
    {
        $newUser = User::factory()->create();

        $response = $this->actingAs($newUser)->get('/settings');
        $response->assertRedirect(route('companies.setup'));

        $setupResponse = $this->actingAs($newUser)->get(route('companies.setup'));
        $setupResponse->assertOk();
    }

    public function test_multi_company_user_without_selection_redirected_to_select_state(): void
    {
        $this->sharedUser->update(['last_active_company_id' => null]);

        $response = $this->actingAs($this->sharedUser)
            ->withSession([])
            ->get('/settings');

        $response->assertRedirect(route('companies.select'));

        $selectResponse = $this->actingAs($this->sharedUser)->get(route('companies.select'));
        $selectResponse->assertOk();
        $selectResponse->assertSee($this->companyA->displayName());
        $selectResponse->assertSee($this->companyB->displayName());
    }

    public function test_unauthenticated_or_no_user_activation_throws_authorization_exception(): void
    {
        auth()->logout();
        $this->context->clear();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Cannot activate company context without a verified user.');

        $this->context->setCompany($this->companyA);
    }
}
