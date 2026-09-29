<?php

namespace Tests\Feature\Phase1;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\SettingsIndex;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanySettingsLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(CompanyContext::class);
        $this->owner = User::factory()->create(['name' => 'Owner User', 'email' => 'owner@example.com']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة التاجر الصغير',
            'name_en' => 'Small Trader Co.',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
        ]);

        $this->context->setCompany($this->company, $this->owner);
    }

    public function test_owner_can_view_settings_page(): void
    {
        $response = $this->actingAs($this->owner)->get(route('settings.index'));

        $response->assertOk();
        $response->assertSee('شركة التاجر الصغير');
        $response->assertSee('ILS');
    }

    public function test_livewire_settings_loads_company_data(): void
    {
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->assertSet('name_ar', 'شركة التاجر الصغير')
            ->assertSet('name_en', 'Small Trader Co.')
            ->assertSet('base_currency', 'ILS')
            ->assertSet('default_locale', 'ar')
            ->assertSet('timezone', 'Asia/Hebron');
    }

    public function test_livewire_can_update_company_identity(): void
    {
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->set('name_ar', 'شركة التاجر المحدثة')
            ->set('name_en', 'Updated Trader Co.')
            ->set('tax_number', '987654321')
            ->call('saveIdentity')
            ->assertHasNoErrors();

        $this->company->refresh();
        $this->assertSame('شركة التاجر المحدثة', $this->company->name_ar);
        $this->assertSame('Updated Trader Co.', $this->company->name_en);
        $this->assertSame('987654321', $this->company->tax_number);

        $this->assertDatabaseHas('audit_events', [
            'company_id' => $this->company->id,
            'event_key' => 'company.identity_updated',
        ]);
    }

    public function test_livewire_can_update_localization(): void
    {
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->set('default_locale', 'en')
            ->set('timezone', 'Asia/Amman')
            ->call('saveLocalization')
            ->assertHasNoErrors();

        $this->company->refresh();
        $this->assertSame('en', $this->company->default_locale);
        $this->assertSame('Asia/Amman', $this->company->timezone);
    }

    public function test_livewire_can_update_currencies_and_enforces_single_base(): void
    {
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->set('base_currency', 'USD')
            ->set('currencies_enabled.USD', true)
            ->set('currencies_enabled.ILS', true)
            ->set('currencies_enabled.JOD', false)
            ->call('saveCurrencies')
            ->assertHasNoErrors();

        $this->company->refresh();
        $this->assertSame('USD', $this->company->base_currency_code);

        $baseCount = CompanyCurrency::where('company_id', $this->company->id)
            ->where('is_base', true)
            ->count();
        $this->assertSame(1, $baseCount);

        $usd = CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'USD')->first();
        $this->assertTrue($usd->is_base);
        $this->assertTrue($usd->enabled);
    }

    public function test_livewire_can_update_security_settings(): void
    {
        session(['auth.password_confirmed_at' => time()]);

        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->set('require_2fa_for_owner', true)
            ->set('require_2fa_for_admin', true)
            ->set('public_share_default_expiry_days', 60)
            ->call('saveSecurity')
            ->assertHasNoErrors();

        $security = $this->company->securitySettings()->first();
        $this->assertNotNull($security);
        $this->assertTrue($security->require_2fa_for_owner);
        $this->assertTrue($security->require_2fa_for_admin);
        $this->assertSame(60, $security->public_share_default_expiry_days);
    }

    public function test_livewire_can_add_new_member_with_role(): void
    {
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->set('new_user_name', 'Employee User')
            ->set('new_user_email', 'employee@example.com')
            ->set('new_user_password', 'secret-password-123')
            ->set('new_user_role', 'Sales')
            ->set('new_user_locale', 'ar')
            ->call('createUser')
            ->assertHasNoErrors();

        $newMember = User::where('email', 'employee@example.com')->first();
        $this->assertNotNull($newMember);

        $membership = CompanyUser::where('company_id', $this->company->id)
            ->where('user_id', $newMember->id)
            ->first();
        $this->assertNotNull($membership);
        $this->assertSame('active', $membership->status);

        setPermissionsTeamId($this->company->id);
        $this->assertTrue($newMember->hasRole('Sales'));
    }

    public function test_livewire_create_user_rejects_existing_email_with_error_message(): void
    {
        $existing = User::factory()->create([
            'email' => 'already.registered@example.com',
            'name' => 'Existing Person',
            'password' => Hash::make('existing-password'),
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('setSection', 'users')
            ->set('new_user_name', 'Impostor Attempt')
            ->set('new_user_email', 'already.registered@example.com')
            ->set('new_user_password', 'brand-new-password')
            ->set('new_user_role', 'Viewer')
            ->call('createUser');

        $component->assertSet('errorMessage', __('settings.error_user_email_already_registered'));

        // Verify credentials untouched and no membership created
        $existing->refresh();
        $this->assertTrue(Hash::check('existing-password', $existing->password));
        $this->assertDatabaseMissing('company_user', [
            'company_id' => $this->company->id,
            'user_id' => $existing->id,
        ]);
    }

    public function test_livewire_unauthorized_viewer_cannot_update_company(): void
    {
        $viewer = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $viewer->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $viewerRole = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail();
        $viewer->assignRole($viewerRole);

        $this->context->setCompany($this->company, $viewer);

        Livewire::actingAs($viewer)
            ->test(SettingsIndex::class)
            ->call('saveIdentity')
            ->assertForbidden();
    }

    public function test_viewer_does_not_receive_serialized_audit_events(): void
    {
        $viewer = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $viewer->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $viewerRole = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail();
        $viewer->assignRole($viewerRole);

        $this->context->setCompany($this->company, $viewer);

        // Ensure there are audit events in the DB
        $this->assertGreaterThan(0, AuditEvent::where('company_id', $this->company->id)->count());

        // Viewer does not have audit.events.view permission
        Livewire::actingAs($viewer)
            ->test(SettingsIndex::class)
            ->assertViewHas('auditEvents', function ($events) {
                return $events->isEmpty();
            });
    }

    public function test_view_only_user_sees_readonly_members_table_without_mutating_controls(): void
    {
        $viewer = User::factory()->create(['name' => 'View Only Auditor']);
        $viewerMembership = CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $viewer->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $viewerRole = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail();
        $viewer->assignRole($viewerRole);

        $this->context->setCompany($this->company, $viewer);

        $component = Livewire::actingAs($viewer)
            ->test(SettingsIndex::class)
            ->call('setSection', 'users');

        // 1. Members list table is visible
        $component->assertSee('View Only Auditor');
        $component->assertSee($this->owner->name);

        // 2. Mutating controls are NOT rendered for view-only user
        $component->assertDontSeeHtml('wire:submit="createUser"');
        $component->assertDontSeeHtml('wire:change="updateUserRole');
        $component->assertDontSeeHtml('wire:click="toggleUserStatus');
        $component->assertDontSee(__('settings.add_new_user'));

        // 3. Mutating actions fail forbidden at server-level
        Livewire::actingAs($viewer)
            ->test(SettingsIndex::class)
            ->call('createUser')
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(SettingsIndex::class)
            ->call('updateUserRole', $viewerMembership->id, 'Administrator')
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(SettingsIndex::class)
            ->call('toggleUserStatus', $viewerMembership->id)
            ->assertForbidden();

        // 4. In contrast, Owner sees mutating controls
        $ownerComponent = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('setSection', 'users');

        $ownerComponent->assertSeeHtml('wire:submit="createUser"');
        $ownerComponent->assertSee(__('settings.add_new_user'));
        $ownerComponent->assertSeeHtml('wire:change="updateUserRole');
        $ownerComponent->assertSeeHtml('wire:click="toggleUserStatus');
    }

    public function test_unauthorized_user_cannot_assign_owner_role(): void
    {
        // Administrator user
        $admin = User::factory()->create();
        $adminMembership = CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $adminRole = Role::where('company_id', $this->company->id)->where('name', 'Administrator')->firstOrFail();
        $admin->assignRole($adminRole);

        $this->context->setCompany($this->company, $admin);

        // Target user
        $target = User::factory()->create();
        $targetMembership = CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $target->id,
            'status' => 'active',
            'is_owner' => false,
        ]);

        // Administrator attempts to promote target to Owner
        Livewire::actingAs($admin)
            ->test(SettingsIndex::class)
            ->call('updateUserRole', $targetMembership->id, 'Owner')
            ->assertSet('errorMessage', 'Only an existing company owner can assign the Owner role.');

        // Target must not have Owner role
        $this->assertFalse($target->hasRole('Owner'));
        $this->assertFalse($targetMembership->fresh()->is_owner);
    }

    public function test_cannot_demote_the_last_remaining_owner(): void
    {
        $ownerMembership = CompanyUser::where('company_id', $this->company->id)
            ->where('user_id', $this->owner->id)
            ->firstOrFail();

        session(['auth.password_confirmed_at' => time()]);

        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('updateUserRole', $ownerMembership->id, 'Administrator')
            ->assertSet('errorMessage', 'Cannot demote the last remaining company owner.');

        $this->assertTrue($this->owner->hasRole('Owner'));
        $this->assertTrue($ownerMembership->fresh()->is_owner);
    }

    public function test_cannot_inject_arbitrary_permission_via_toggle_role_permission(): void
    {
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('selectRole', 'Administrator')
            ->call('toggleRolePermission', 'malicious.arbitrary.privilege')
            ->assertSet('errorMessage', "Permission 'malicious.arbitrary.privilege' is not defined in the static catalog.");

        // Must not create new permission in database
        $this->assertDatabaseMissing('permissions', [
            'name' => 'malicious.arbitrary.privilege',
        ]);
    }

    public function test_owner_can_navigate_every_settings_section_and_back_to_overview(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class);

        // All active sections in Phase 1
        $sections = ['identity', 'localization', 'currencies', 'security', 'users', 'roles', 'audit', 'overview'];

        foreach ($sections as $section) {
            $component->call('setSection', $section)
                ->assertSet('activeSection', $section);
        }
    }

    public function test_unauthorized_user_cannot_navigate_to_restricted_sections_and_sees_disabled_cards(): void
    {
        $limitedUser = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $limitedUser->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $limitedRole = Role::firstOrCreate([
            'company_id' => $this->company->id,
            'name' => 'CompanyViewerOnly',
            'guard_name' => 'web',
        ]);
        $limitedRole->syncPermissions(['settings.company.view']);
        $limitedUser->assignRole($limitedRole);

        $this->context->setCompany($this->company, $limitedUser);

        $component = Livewire::actingAs($limitedUser)
            ->test(SettingsIndex::class);

        // Limited user can access general sections
        $component->call('setSection', 'identity')->assertSet('activeSection', 'identity');
        $component->call('setSection', 'overview')->assertSet('activeSection', 'overview');

        // Limited user sees restricted card notice in rendered view
        $component->assertSee(__('settings.restricted_card_notice'));

        // Calling restricted sections is forbidden (HTTP 403)
        Livewire::actingAs($limitedUser)
            ->test(SettingsIndex::class)
            ->call('setSection', 'users')
            ->assertForbidden();

        Livewire::actingAs($limitedUser)
            ->test(SettingsIndex::class)
            ->call('setSection', 'roles')
            ->assertForbidden();

        Livewire::actingAs($limitedUser)
            ->test(SettingsIndex::class)
            ->call('setSection', 'audit')
            ->assertForbidden();
    }

    public function test_settings_page_renders_bilingual_strings_accurately_in_arabic_and_english(): void
    {
        // 1. Arabic LTR/RTL check
        $this->owner->update(['locale' => 'ar']);
        $arResponse = $this->actingAs($this->owner)
            ->withSession(['locale' => 'ar'])
            ->get(route('settings.index'));
        $arResponse->assertOk();
        $arResponse->assertSee('dir="rtl"', false);
        $arResponse->assertSee(__('settings.title', [], 'ar'));
        $arResponse->assertSee(__('settings.status_2fa_owner_active', [], 'ar'));
        $arResponse->assertSee(__('settings.aside_activity_title', [], 'ar'));

        // 2. English LTR/RTL check
        $this->owner->update(['locale' => 'en']);
        $enResponse = $this->actingAs($this->owner)
            ->withSession(['locale' => 'en'])
            ->get(route('settings.index'));
        $enResponse->assertOk();
        $enResponse->assertSee('dir="ltr"', false);
        $enResponse->assertSee('System Settings');
        $enResponse->assertSee('Stored (Owner)');
        $enResponse->assertSee('Recent Activity');

        // Reset locale back
        $this->owner->update(['locale' => 'ar']);
    }

    public function test_fresh_livewire_request_isolates_tenant_and_rejects_forged_company_id(): void
    {
        // 1. Create second company with different data (clear context first to respect single active context)
        $this->context->clear();
        $otherOwner = User::factory()->create();
        $companyB = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة ثانية منافسة',
            'name_en' => 'Competitor Corp.',
            'base_currency_code' => 'USD',
        ]);
        $this->context->setCompany($this->company, $this->owner);

        // 2. Initial real GET request authenticated as $this->owner for $this->company
        $getResponse = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->company->id])
            ->get(route('settings.index'));

        $getResponse->assertOk();
        $getResponse->assertSee('شركة التاجر الصغير');
        $getResponse->assertDontSee('شركة ثانية منافسة');

        // Extract component snapshot from real rendered HTML
        $html = $getResponse->getContent();
        $snapshot = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot');
        $this->assertIsArray($snapshot);
        $this->assertSame('pages.settings-index', $snapshot['memo']['name'] ?? null);

        // 3. Clear/poison in-memory CompanyContext to prove fresh request re-execution
        $this->context->clear();
        $this->assertFalse($this->context->hasCompany());

        // 4. Send real HTTP POST to Livewire update endpoint (/livewire/update)
        // Attacker attempts to forge query param: ?company_id={$companyB->id}
        // Attacker attempts property updates and calls saveIdentity
        $postResponse = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->company->id])
            ->withHeaders([
                'X-Livewire' => 'true',
            ])
            ->postJson(route('default-livewire.update', ['company_id' => $companyB->id]), [
                '_token' => csrf_token(),
                'components' => [
                    [
                        'snapshot' => json_encode($snapshot),
                        'updates' => [
                            'name_ar' => 'شركة التاجر المحدثة بالطلب الحقيقي',
                        ],
                        'calls' => [
                            [
                                'path' => '',
                                'method' => 'saveIdentity',
                                'params' => [],
                            ],
                        ],
                    ],
                ],
            ]);

        $postResponse->assertOk();

        // 5. Verify PersistentMiddleware and SetCompanyContext re-applied for $this->company:
        $this->assertTrue($this->context->hasCompany());
        $this->assertSame($this->company->id, $this->context->companyId());
        $this->assertSame($this->company->id, session('active_company_id'));
        $this->assertSame($this->company->id, getPermissionsTeamId());

        // 6. Verify mutation committed ONLY to $this->company and NEVER to companyB
        $this->assertSame('شركة التاجر المحدثة بالطلب الحقيقي', $this->company->fresh()->name_ar);
        $this->assertSame('شركة ثانية منافسة', $companyB->fresh()->name_ar);

        // 7. Verify response JSON snapshot contains company A data and NEVER company B data
        $responseData = $postResponse->json();
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('components', $responseData);
        $returnedSnapshot = json_decode($responseData['components'][0]['snapshot'], true);
        $this->assertSame('شركة التاجر المحدثة بالطلب الحقيقي', $returnedSnapshot['data']['name_ar'] ?? null);
        $this->assertSame('ILS', $returnedSnapshot['data']['base_currency'] ?? null);

        // 8. Also exercise component call for section navigation via real update request
        $this->context->clear();
        $this->assertFalse($this->context->hasCompany());

        $navResponse = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->company->id])
            ->withHeaders([
                'X-Livewire' => 'true',
            ])
            ->postJson(route('default-livewire.update', ['company_id' => $companyB->id]), [
                '_token' => csrf_token(),
                'components' => [
                    [
                        'snapshot' => $responseData['components'][0]['snapshot'],
                        'updates' => [],
                        'calls' => [
                            [
                                'path' => '',
                                'method' => 'setSection',
                                'params' => ['currencies'],
                            ],
                        ],
                    ],
                ],
            ]);

        $navResponse->assertOk();
        $this->assertSame($this->company->id, $this->context->companyId());
        $navSnapshot = json_decode($navResponse->json()['components'][0]['snapshot'], true);
        $this->assertSame('currencies', $navSnapshot['data']['activeSection'] ?? null);
    }

    public function test_save_security_requires_recent_password_confirmation_and_blocks_mutation(): void
    {
        $initialAuditCount = AuditEvent::where('company_id', $this->company->id)->count();

        // 1. Without password confirmation: redirects to password.confirm and blocks DB/audit mutation
        $component = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->set('require_2fa_for_owner', true)
            ->set('require_2fa_for_admin', true)
            ->set('public_share_default_expiry_days', 60)
            ->call('saveSecurity');

        $component->assertRedirect(route('password.confirm'));
        $component->assertSet('errorMessage', __('settings.password_confirmation_required'));
        $this->assertSame(route('settings.index'), session('url.intended'));

        // Verify DB settings unchanged
        $this->assertDatabaseMissing('company_security_settings', [
            'company_id' => $this->company->id,
            'require_2fa_for_admin' => true,
        ]);
        $this->assertSame($initialAuditCount, AuditEvent::where('company_id', $this->company->id)->count());

        // 2. With expired password confirmation (beyond 3 hours / 10800s): blocked
        session(['auth.password_confirmed_at' => time() - 10801]);
        $expiredComponent = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->set('require_2fa_for_admin', true)
            ->call('saveSecurity');

        $expiredComponent->assertRedirect(route('password.confirm'));
        $this->assertSame($initialAuditCount, AuditEvent::where('company_id', $this->company->id)->count());

        // 3. With valid confirmation: succeeds
        session(['auth.password_confirmed_at' => time()]);
        $validComponent = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->set('require_2fa_for_owner', true)
            ->set('require_2fa_for_admin', true)
            ->set('public_share_default_expiry_days', 60)
            ->call('saveSecurity');

        $validComponent->assertHasNoErrors();
        $this->assertDatabaseHas('company_security_settings', [
            'company_id' => $this->company->id,
            'require_2fa_for_admin' => true,
            'public_share_default_expiry_days' => 60,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'company_id' => $this->company->id,
            'event_key' => 'company.security_updated',
        ]);
    }

    public function test_toggle_user_status_deactivation_requires_recent_password_confirmation(): void
    {
        $member = User::factory()->create(['name' => 'Active Staff']);
        $membership = CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $member->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $viewerRole = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail();
        $member->assignRole($viewerRole);

        $initialAuditCount = AuditEvent::where('company_id', $this->company->id)->count();

        // 1. Without confirmation: deactivating active user redirects and blocks mutation
        session()->forget('auth.password_confirmed_at');
        $component = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('toggleUserStatus', $membership->id);

        $component->assertRedirect(route('password.confirm'));
        $component->assertSet('errorMessage', __('settings.password_confirmation_required'));
        $this->assertSame('active', $membership->fresh()->status);
        $this->assertSame($initialAuditCount, AuditEvent::where('company_id', $this->company->id)->count());

        // 2. With expired confirmation: blocked
        session(['auth.password_confirmed_at' => time() - 10801]);
        $expiredComponent = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('toggleUserStatus', $membership->id);

        $expiredComponent->assertRedirect(route('password.confirm'));
        $this->assertSame('active', $membership->fresh()->status);

        // 3. With valid confirmation: deactivation succeeds
        session(['auth.password_confirmed_at' => time()]);
        $validComponent = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('toggleUserStatus', $membership->id);

        $validComponent->assertHasNoErrors();
        $this->assertSame('inactive', $membership->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'company_id' => $this->company->id,
            'event_key' => 'user.status_updated',
        ]);

        // 4. Re-activating an inactive ordinary user does NOT require password confirmation
        session()->forget('auth.password_confirmed_at');
        $reactivateComponent = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('toggleUserStatus', $membership->id);

        $reactivateComponent->assertHasNoErrors();
        $this->assertSame('active', $membership->fresh()->status);
    }

    public function test_update_user_role_altering_owner_status_requires_recent_password_confirmation(): void
    {
        $member = User::factory()->create(['name' => 'Role Candidate']);
        $membership = CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $member->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $adminRole = Role::where('company_id', $this->company->id)->where('name', 'Administrator')->firstOrFail();
        $member->assignRole($adminRole);

        $initialAuditCount = AuditEvent::where('company_id', $this->company->id)->count();

        // 1. Promoting to Owner without confirmation redirects and blocks
        session()->forget('auth.password_confirmed_at');
        $component = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('updateUserRole', $membership->id, 'Owner');

        $component->assertRedirect(route('password.confirm'));
        $component->assertSet('errorMessage', __('settings.password_confirmation_required'));
        $this->assertFalse($membership->fresh()->is_owner);
        $this->assertSame($initialAuditCount, AuditEvent::where('company_id', $this->company->id)->count());

        // 2. Promoting to Owner with expired confirmation redirects and blocks
        session(['auth.password_confirmed_at' => time() - 10801]);
        $expiredComponent = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('updateUserRole', $membership->id, 'Owner');

        $expiredComponent->assertRedirect(route('password.confirm'));
        $this->assertFalse($membership->fresh()->is_owner);

        // 3. Promoting to Owner with valid confirmation succeeds
        session(['auth.password_confirmed_at' => time()]);
        $validComponent = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('updateUserRole', $membership->id, 'Owner');

        $validComponent->assertHasNoErrors();
        $this->assertTrue($membership->fresh()->is_owner);
        $this->assertTrue($member->fresh()->hasRole('Owner'));
        $this->assertDatabaseHas('audit_events', [
            'company_id' => $this->company->id,
            'event_key' => 'role.assigned',
        ]);

        // 4. Demoting an Owner without confirmation redirects and blocks
        session()->forget('auth.password_confirmed_at');
        $demoteUnconfirmed = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('updateUserRole', $membership->id, 'Administrator');

        $demoteUnconfirmed->assertRedirect(route('password.confirm'));
        $this->assertTrue($membership->fresh()->is_owner);

        // 5. Demoting an Owner with valid confirmation succeeds
        session(['auth.password_confirmed_at' => time()]);
        $demoteConfirmed = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('updateUserRole', $membership->id, 'Administrator');

        $demoteConfirmed->assertHasNoErrors();
        $this->assertFalse($membership->fresh()->is_owner);

        // 6. Routine non-owner role change (e.g. Administrator to Viewer) does NOT require confirmation
        session()->forget('auth.password_confirmed_at');
        $routineComponent = Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('updateUserRole', $membership->id, 'Viewer');

        $routineComponent->assertHasNoErrors();
        $this->assertTrue($member->fresh()->hasRole('Viewer'));
    }

    public function test_non_owner_with_manage_permission_cannot_demote_or_deactivate_owner(): void
    {
        // 1. Administrator user (has settings.users.manage)
        $admin = User::factory()->create(['name' => 'Admin Manager']);
        $adminMembership = CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $adminRole = Role::where('company_id', $this->company->id)->where('name', 'Administrator')->firstOrFail();
        $admin->assignRole($adminRole);

        // 2. Second Owner
        $secondOwner = User::factory()->create(['name' => 'Second Owner']);
        $secondOwnerMembership = CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $secondOwner->id,
            'status' => 'active',
            'is_owner' => true,
        ]);
        $ownerRole = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $secondOwner->assignRole($ownerRole);

        $this->context->setCompany($this->company, $admin);

        // 3. Administrator attempts to demote Second Owner -> 403 Forbidden
        session(['auth.password_confirmed_at' => time()]);
        Livewire::actingAs($admin)
            ->test(SettingsIndex::class)
            ->call('updateUserRole', $secondOwnerMembership->id, 'Administrator')
            ->assertForbidden();

        $this->assertTrue($secondOwnerMembership->fresh()->is_owner);

        // 4. Administrator attempts to deactivate Second Owner -> 403 Forbidden
        session(['auth.password_confirmed_at' => time()]);
        Livewire::actingAs($admin)
            ->test(SettingsIndex::class)
            ->call('toggleUserStatus', $secondOwnerMembership->id)
            ->assertForbidden();

        $this->assertSame('active', $secondOwnerMembership->fresh()->status);
    }

    public function test_real_http_livewire_update_enforces_password_confirmation_gate(): void
    {
        // 1. Initial real GET request authenticated as owner
        $getResponse = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->company->id])
            ->get(route('settings.index'));

        $getResponse->assertOk();
        $html = $getResponse->getContent();
        $snapshot = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot');
        $this->assertIsArray($snapshot);

        // Ensure initially require_2fa_for_admin is false in DB
        $this->assertDatabaseMissing('company_security_settings', [
            'company_id' => $this->company->id,
            'require_2fa_for_admin' => true,
        ]);

        // 2. Clear in-memory CompanyContext
        $this->context->clear();

        // 3. Real HTTP Livewire update request calling saveSecurity WITHOUT auth.password_confirmed_at
        $unconfirmedResponse = $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->company->id])
            ->withHeaders([
                'X-Livewire' => 'true',
            ])
            ->postJson(route('default-livewire.update'), [
                '_token' => csrf_token(),
                'components' => [
                    [
                        'snapshot' => json_encode($snapshot),
                        'updates' => [
                            'require_2fa_for_admin' => true,
                        ],
                        'calls' => [
                            [
                                'path' => '',
                                'method' => 'saveSecurity',
                                'params' => [],
                            ],
                        ],
                    ],
                ],
            ]);

        $unconfirmedResponse->assertOk();

        // Proves Livewire issued redirect effect pointing to password.confirm
        $unconfirmedData = $unconfirmedResponse->json();
        $this->assertSame(
            route('password.confirm'),
            $unconfirmedData['components'][0]['effects']['redirect'] ?? null
        );

        // Proves DB was NOT mutated
        $this->assertDatabaseMissing('company_security_settings', [
            'company_id' => $this->company->id,
            'require_2fa_for_admin' => true,
        ]);

        // 4. Real HTTP Livewire update request calling saveSecurity WITH valid auth.password_confirmed_at
        $this->context->clear();

        $confirmedResponse = $this->actingAs($this->owner)
            ->withSession([
                'active_company_id' => $this->company->id,
                'auth.password_confirmed_at' => time(),
            ])
            ->withHeaders([
                'X-Livewire' => 'true',
            ])
            ->postJson(route('default-livewire.update'), [
                '_token' => csrf_token(),
                'components' => [
                    [
                        'snapshot' => json_encode($snapshot),
                        'updates' => [
                            'require_2fa_for_admin' => true,
                        ],
                        'calls' => [
                            [
                                'path' => '',
                                'method' => 'saveSecurity',
                                'params' => [],
                            ],
                        ],
                    ],
                ],
            ]);

        $confirmedResponse->assertOk();

        // Proves no redirect effect to password.confirm
        $confirmedData = $confirmedResponse->json();
        $this->assertNull($confirmedData['components'][0]['effects']['redirect'] ?? null);

        // Proves DB was mutated atomically
        $this->assertDatabaseHas('company_security_settings', [
            'company_id' => $this->company->id,
            'require_2fa_for_admin' => true,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'company_id' => $this->company->id,
            'event_key' => 'company.security_updated',
        ]);
    }
}
