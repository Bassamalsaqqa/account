<?php

namespace Tests\Feature;

use App\Actions\Company\CreateCompanyAction;
use App\Models\AuditEvent;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertSeeVolt('profile.delete-user-form');
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_with_zero_memberships_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $component
            ->assertHasErrors('password')
            ->assertNoRedirect();

        $this->assertNotNull($user->fresh());
    }

    public function test_sole_owner_cannot_delete_account(): void
    {
        $user = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($user, [
            'name_ar' => 'شركة المالك الوحيد',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component
            ->assertHasErrors('password')
            ->assertNoRedirect();

        $this->assertNotNull($user->fresh());
        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'is_owner' => true,
        ]);
    }

    public function test_co_owner_cannot_delete_account(): void
    {
        $owner1 = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($owner1, [
            'name_ar' => 'شركة بشركاء',
            'base_currency_code' => 'ILS',
        ]);

        $owner2 = User::factory()->create();
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $owner2->id,
            'status' => 'active',
            'is_owner' => true,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($company->id);
        $ownerRole = Role::where('company_id', $company->id)->where('name', 'Owner')->firstOrFail();
        $owner2->assignRole($ownerRole);

        $this->actingAs($owner2);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component
            ->assertHasErrors('password')
            ->assertNoRedirect();

        $this->assertNotNull($owner2->fresh());
        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id,
            'user_id' => $owner2->id,
            'is_owner' => true,
        ]);
    }

    public function test_ordinary_active_member_cannot_delete_account(): void
    {
        $owner = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة تجريبية',
            'base_currency_code' => 'ILS',
        ]);

        $member = User::factory()->create();
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $member->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($company->id);
        $viewerRole = Role::where('company_id', $company->id)->where('name', 'Viewer')->firstOrFail();
        $member->assignRole($viewerRole);

        $this->actingAs($member);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component
            ->assertHasErrors('password')
            ->assertNoRedirect();

        $this->assertNotNull($member->fresh());
        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_ordinary_inactive_member_cannot_delete_account(): void
    {
        $owner = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة تجريبية',
            'base_currency_code' => 'ILS',
        ]);

        $member = User::factory()->create();
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $member->id,
            'status' => 'inactive',
            'is_owner' => false,
            'joined_at' => now(),
        ]);

        $this->actingAs($member);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component
            ->assertHasErrors('password')
            ->assertNoRedirect();

        $this->assertNotNull($member->fresh());
        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id,
            'user_id' => $member->id,
            'status' => 'inactive',
        ]);
    }

    public function test_profile_deletion_rejection_has_no_db_role_or_audit_side_effects(): void
    {
        $owner = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة تجريبية',
            'base_currency_code' => 'ILS',
        ]);

        $member = User::factory()->create();
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $member->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($company->id);
        $role = Role::where('company_id', $company->id)->where('name', 'Cashier')->firstOrFail();
        $member->assignRole($role);

        $initialAuditCount = CompanyScope::executeWithoutScope(fn () => AuditEvent::count());

        $this->actingAs($member);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component->assertHasErrors('password');

        // Verify no side effects
        $this->assertNotNull($member->fresh());
        $this->assertTrue($member->fresh()->hasRole('Cashier'));
        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);

        $currentAuditCount = CompanyScope::executeWithoutScope(fn () => AuditEvent::count());
        $this->assertSame($initialAuditCount, $currentAuditCount);
    }

    public function test_profile_ui_hides_destructive_action_for_company_members(): void
    {
        $user = User::factory()->create();
        app(CreateCompanyAction::class)->execute($user, [
            'name_ar' => 'شركة تجريبية',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form');
        $component->assertSee(__('profile.deletion_blocked_business_member'));
        $component->assertDontSee('confirm-user-deletion');
    }

    public function test_profile_ui_shows_destructive_action_for_zero_member_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form');
        $component->assertDontSee(__('profile.deletion_blocked_business_member'));
        $component->assertSee('confirm-user-deletion');
    }
}
