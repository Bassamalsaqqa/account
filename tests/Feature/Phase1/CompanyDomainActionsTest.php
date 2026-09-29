<?php

namespace Tests\Feature\Phase1;

use App\Actions\Company\AddCompanyMemberAction;
use App\Actions\Company\CreateCompanyAction;
use App\Actions\Company\ToggleCompanyMemberStatusAction;
use App\Actions\Company\UpdateCompanyCurrenciesAction;
use App\Actions\Company\UpdateCompanyLocalizationAction;
use App\Actions\Company\UpdateCompanyMemberRoleAction;
use App\Actions\Company\UpdateRolePermissionsAction;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyLanguage;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDomainActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(CompanyContext::class);
        $this->owner = User::factory()->create(['name' => 'Owner', 'email' => 'owner@example.com']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة التجارة',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
        ]);

        $this->context->setCompany($this->company, $this->owner);
    }

    public function test_update_currencies_action_enforces_single_enabled_base(): void
    {
        $action = app(UpdateCompanyCurrenciesAction::class);

        // Attempting to pass base_currency as disabled must be overridden by invariant
        $action->execute($this->company, 'USD', [
            'USD' => false,
            'ILS' => true,
            'JOD' => false,
        ], $this->owner);

        $this->assertSame('USD', $this->company->fresh()->base_currency_code);

        $baseCurrencies = CompanyCurrency::where('company_id', $this->company->id)
            ->where('is_base', true)
            ->get();

        $this->assertCount(1, $baseCurrencies);
        $usd = $baseCurrencies->first();
        $this->assertSame('USD', $usd->currency_code);
        $this->assertTrue($usd->enabled);

        // Invalid currency code rejected
        $this->expectException(InvalidArgumentException::class);
        $action->execute($this->company, 'EUR', ['EUR' => true], $this->owner);
    }

    public function test_update_localization_action_enforces_single_default_language(): void
    {
        $action = app(UpdateCompanyLocalizationAction::class);

        $action->execute($this->company, 'en', 'Asia/Amman', $this->owner);

        $this->company->refresh();
        $this->assertSame('en', $this->company->default_locale);
        $this->assertSame('Asia/Amman', $this->company->timezone);

        $defaultLangs = CompanyLanguage::where('company_id', $this->company->id)
            ->where('is_default', true)
            ->get();

        $this->assertCount(1, $defaultLangs);
        $this->assertSame('en', $defaultLangs->first()->locale);
    }

    public function test_add_company_member_action_rejects_owner_role(): void
    {
        $action = app(AddCompanyMemberAction::class);

        $this->expectException(InvalidArgumentException::class);
        $action->execute(
            $this->company,
            [
                'name' => 'New Owner Attempt',
                'email' => 'newowner@example.com',
                'password' => 'secret-12345',
                'locale' => 'ar',
            ],
            'Owner',
            $this->owner
        );
    }

    public function test_add_company_member_action_creates_member_atomically(): void
    {
        $action = app(AddCompanyMemberAction::class);

        $membership = $action->execute(
            $this->company,
            [
                'name' => 'Cashier Employee',
                'email' => 'cashier@example.com',
                'password' => 'secret-12345',
                'locale' => 'ar',
            ],
            'Cashier',
            $this->owner
        );

        $this->assertNotNull($membership);
        $this->assertFalse($membership->is_owner);
        $this->assertSame('active', $membership->status);
        $this->assertTrue(Hash::check('secret-12345', $membership->user->password));

        setPermissionsTeamId($this->company->id);
        $this->assertTrue($membership->user->hasRole('Cashier'));

        // Cannot add duplicate member to same company (rejected with truthful message)
        $this->expectException(InvalidArgumentException::class);
        $action->execute(
            $this->company,
            [
                'name' => 'Cashier Employee',
                'email' => 'cashier@example.com',
                'password' => 'different-password',
                'locale' => 'ar',
            ],
            'Cashier',
            $this->owner
        );
    }

    public function test_add_company_member_action_rejects_existing_user_email_and_prevents_partial_writes(): void
    {
        $action = app(AddCompanyMemberAction::class);

        // 1. Create a pre-existing user in the system with their own credentials
        $existingUser = User::factory()->create([
            'email' => 'existing.user@example.com',
            'name' => 'Original Name',
            'password' => Hash::make('original-secret'),
        ]);

        $initialAuditCount = AuditEvent::where('company_id', $this->company->id)->count();

        // 2. Attempt to create new user with the same email
        try {
            $action->execute(
                $this->company,
                [
                    'name' => 'Attacker Overwrite Attempt',
                    'email' => 'existing.user@example.com',
                    'password' => 'new-secret-attempt',
                    'locale' => 'en',
                ],
                'Viewer',
                $this->owner
            );
            $this->fail('Expected InvalidArgumentException for existing user email.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('settings.error_user_email_already_registered'), $e->getMessage());
        }

        // 3. Verify zero side-effects: password untouched, name untouched, no membership, no role, no audit log
        $existingUser->refresh();
        $this->assertSame('Original Name', $existingUser->name);
        $this->assertTrue(Hash::check('original-secret', $existingUser->password));
        $this->assertFalse(Hash::check('new-secret-attempt', $existingUser->password));

        $this->assertDatabaseMissing('company_user', [
            'company_id' => $this->company->id,
            'user_id' => $existingUser->id,
        ]);

        $this->assertSame($initialAuditCount, AuditEvent::where('company_id', $this->company->id)->count());
    }

    public function test_update_member_role_action_prevents_unauthorized_owner_promotion_and_last_owner_demotion(): void
    {
        $memberAction = app(AddCompanyMemberAction::class);
        $roleAction = app(UpdateCompanyMemberRoleAction::class);

        // Add Administrator member
        $adminMembership = $memberAction->execute(
            $this->company,
            ['name' => 'Admin User', 'email' => 'admin@example.com', 'password' => 'secret-12345', 'locale' => 'ar'],
            'Administrator',
            $this->owner
        );

        // Add Viewer member
        $viewerMembership = $memberAction->execute(
            $this->company,
            ['name' => 'Viewer User', 'email' => 'viewer@example.com', 'password' => 'secret-12345', 'locale' => 'ar'],
            'Viewer',
            $this->owner
        );

        // 1. Administrator attempts to promote Viewer to Owner -> Unauthorized
        try {
            $roleAction->execute($this->company, $viewerMembership, 'Owner', $adminMembership->user);
            $this->fail('Administrator should not be able to assign Owner role.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Only an existing company owner can assign the Owner role', $e->getMessage());
        }

        // 2. Owner promotes Admin to Owner -> Success
        $roleAction->execute($this->company, $adminMembership, 'Owner', $this->owner);
        $this->assertTrue($adminMembership->fresh()->is_owner);

        // 3. Now there are 2 owners, one can be demoted
        $roleAction->execute($this->company, $adminMembership, 'Administrator', $this->owner);
        $this->assertFalse($adminMembership->fresh()->is_owner);

        // 4. Attempting to demote the LAST owner fails
        $ownerMembership = CompanyUser::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot demote the last remaining company owner.');
        $roleAction->execute($this->company, $ownerMembership, 'Administrator', $this->owner);
    }

    public function test_update_role_permissions_action_enforces_catalog_and_owner_immutability(): void
    {
        $action = app(UpdateRolePermissionsAction::class);

        setPermissionsTeamId($this->company->id);
        $ownerRole = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $adminRole = Role::where('company_id', $this->company->id)->where('name', 'Administrator')->firstOrFail();

        // 1. Modifying Owner role permissions is blocked
        try {
            $action->execute($this->company, $ownerRole, 'settings.company.view', false, $this->owner);
            $this->fail('Owner role permissions should be immutable.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Owner role permissions are immutable', $e->getMessage());
        }

        // 2. Modifying non-catalog permission is blocked
        try {
            $action->execute($this->company, $adminRole, 'malicious.hack.permission', true, $this->owner);
            $this->fail('Non-catalog permission should be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('is not defined in the static catalog', $e->getMessage());
        }

        // 3. Valid catalog permission on Administrator toggles cleanly
        $action->execute($this->company, $adminRole, 'settings.accounting.manage', false, $this->owner);
        $this->assertFalse($adminRole->fresh()->hasPermissionTo('settings.accounting.manage'));

        $action->execute($this->company, $adminRole, 'settings.accounting.manage', true, $this->owner);
        $this->assertTrue($adminRole->fresh()->hasPermissionTo('settings.accounting.manage'));
    }

    public function test_toggle_member_status_action_toggles_ordinary_member_and_prevents_deactivating_last_owner(): void
    {
        $memberAction = app(AddCompanyMemberAction::class);
        $roleAction = app(UpdateCompanyMemberRoleAction::class);
        $statusAction = app(ToggleCompanyMemberStatusAction::class);

        // 1. Add normal member
        $member = $memberAction->execute(
            $this->company,
            ['name' => 'Ordinary Staff', 'email' => 'staff@example.com', 'password' => 'secret-12345', 'locale' => 'en'],
            'Viewer',
            $this->owner
        );

        $this->assertSame('active', $member->status);

        // 2. Toggle to inactive
        $statusAction->execute($this->company, $member, $this->owner);
        $this->assertSame('inactive', $member->fresh()->status);

        // 3. Toggle back to active
        $statusAction->execute($this->company, $member, $this->owner);
        $this->assertSame('active', $member->fresh()->status);

        // 4. Attempting to deactivate the single active owner fails
        $ownerMembership = CompanyUser::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->firstOrFail();

        try {
            $statusAction->execute($this->company, $ownerMembership, $this->owner);
            $this->fail('Should not deactivate the only active owner.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Cannot deactivate the last remaining company owner', $e->getMessage());
        }
        $this->assertSame('active', $ownerMembership->fresh()->status);

        // 5. Multi-owner: promote second member to Owner, then deactivating one succeeds
        $secondMember = $memberAction->execute(
            $this->company,
            ['name' => 'Second Owner', 'email' => 'owner2@example.com', 'password' => 'secret-12345', 'locale' => 'ar'],
            'Administrator',
            $this->owner
        );
        $roleAction->execute($this->company, $secondMember, 'Owner', $this->owner);
        $this->assertTrue($secondMember->fresh()->is_owner);

        // Now deactivating the second owner succeeds because first owner is active
        $statusAction->execute($this->company, $secondMember, $this->owner);
        $this->assertSame('inactive', $secondMember->fresh()->status);

        // But deactivating the first owner now fails because only 1 ACTIVE owner remains
        try {
            $statusAction->execute($this->company, $ownerMembership, $this->owner);
            $this->fail('Should not deactivate the only remaining active owner.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Cannot deactivate the last remaining company owner', $e->getMessage());
        }
        $this->assertSame('active', $ownerMembership->fresh()->status);
    }

    public function test_owner_invariant_failures_are_atomic_and_roll_back_without_audit_records(): void
    {
        $roleAction = app(UpdateCompanyMemberRoleAction::class);
        $statusAction = app(ToggleCompanyMemberStatusAction::class);

        $ownerMembership = CompanyUser::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->firstOrFail();

        $initialAuditCount = AuditEvent::where('company_id', $this->company->id)->count();

        // 1. Demoting last owner fails atomically
        try {
            $roleAction->execute($this->company, $ownerMembership, 'Administrator', $this->owner);
            $this->fail('Expected InvalidArgumentException on last owner demotion.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Cannot demote the last remaining company owner', $e->getMessage());
        }

        // Verify state is completely intact
        $this->assertTrue($ownerMembership->fresh()->is_owner);
        $this->assertTrue($this->owner->hasRole('Owner'));
        $this->assertSame($initialAuditCount, AuditEvent::where('company_id', $this->company->id)->count());

        // 2. Deactivating last owner fails atomically
        try {
            $statusAction->execute($this->company, $ownerMembership, $this->owner);
            $this->fail('Expected InvalidArgumentException on last owner deactivation.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Cannot deactivate the last remaining company owner', $e->getMessage());
        }

        // Verify state is completely intact
        $this->assertSame('active', $ownerMembership->fresh()->status);
        $this->assertSame($initialAuditCount, AuditEvent::where('company_id', $this->company->id)->count());
    }

    public function test_non_owner_cannot_demote_or_deactivate_owner_at_domain_action_level(): void
    {
        $memberAction = app(AddCompanyMemberAction::class);
        $roleAction = app(UpdateCompanyMemberRoleAction::class);
        $statusAction = app(ToggleCompanyMemberStatusAction::class);

        // 1. Add Administrator
        $adminMembership = $memberAction->execute(
            $this->company,
            ['name' => 'Admin Actor', 'email' => 'admin.actor@example.com', 'password' => 'secret-12345', 'locale' => 'ar'],
            'Administrator',
            $this->owner
        );

        // 2. Add Second Owner
        $secondOwner = $memberAction->execute(
            $this->company,
            ['name' => 'Second Co-Owner', 'email' => 'owner2.test@example.com', 'password' => 'secret-12345', 'locale' => 'ar'],
            'Administrator',
            $this->owner
        );
        $roleAction->execute($this->company, $secondOwner, 'Owner', $this->owner);
        $this->assertTrue($secondOwner->fresh()->is_owner);

        $initialAuditCount = AuditEvent::where('company_id', $this->company->id)->count();

        // 3. Administrator attempts to demote Second Owner -> Blocked
        try {
            $roleAction->execute($this->company, $secondOwner, 'Administrator', $adminMembership->user);
            $this->fail('Administrator should not be able to demote an Owner.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Only an existing company owner can modify an owner\'s role', $e->getMessage());
        }

        // Verify state intact
        $this->assertTrue($secondOwner->fresh()->is_owner);
        $this->assertSame($initialAuditCount, AuditEvent::where('company_id', $this->company->id)->count());

        // 4. Administrator attempts to deactivate Second Owner -> Blocked
        try {
            $statusAction->execute($this->company, $secondOwner, $adminMembership->user);
            $this->fail('Administrator should not be able to deactivate an Owner.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Only an existing company owner can modify an owner\'s status', $e->getMessage());
        }

        // Verify state intact
        $this->assertSame('active', $secondOwner->fresh()->status);
        $this->assertSame($initialAuditCount, AuditEvent::where('company_id', $this->company->id)->count());
    }
}
