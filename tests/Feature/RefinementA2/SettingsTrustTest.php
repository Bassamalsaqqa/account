<?php

declare(strict_types=1);

namespace Tests\Feature\RefinementA2;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\SettingsIndex;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SettingsTrustTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyRoleService::class)->ensurePermissionsExist();
        $this->owner = User::factory()->create();
        $this->company = $this->createCompany($this->owner);
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
    }

    private function createCompany(User $owner): Company
    {
        app(CompanyContext::class)->clear();

        return app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'A2 Test Company', 'name_en' => 'A2 Test Company',
            'base_currency_code' => 'ILS', 'default_locale' => 'ar', 'timezone' => 'Asia/Hebron',
        ]);
    }

    private function member(array $permissions, string $roleName = 'A2 Restricted'): User
    {
        $user = User::factory()->create(['last_active_company_id' => $this->company->id]);
        CompanyUser::create([
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'status' => 'active', 'is_owner' => false, 'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->company->id);
        $role = Role::firstOrCreate(['company_id' => $this->company->id, 'name' => $roleName, 'guard_name' => 'web']);
        if (! in_array($roleName, ['Sales', 'Warehouse', 'Cashier'], true)) {
            $role->syncPermissions($permissions);
        }
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(CompanyContext::class)->setCompany($this->company, $user);

        return $user;
    }

    public function test_settings_distinguishes_company_preferences_from_personal_enrollment(): void
    {
        Livewire::actingAs($this->owner)->test(SettingsIndex::class)
            ->assertSee(__('settings.policy_preference_only'))
            ->assertSee(__('settings.personal_2fa_not_enabled'))
            ->assertDontSee('Owner 2FA ON')
            ->assertDontSee(__('settings.system_normal'))
            ->call('setSection', 'security')
            ->assertSee(__('settings.policy_not_enforced'));
    }

    public function test_audit_is_paginated_filtered_company_scoped_and_omits_private_payloads(): void
    {
        $foreignOwner = User::factory()->create();
        $foreignCompany = $this->createCompany($foreignOwner);
        app(CompanyContext::class)->setCompany($foreignCompany, $foreignOwner);
        AuditEvent::create(['event_key' => 'a2.sample', 'summary' => 'Foreign company event']);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        for ($i = 0; $i < 25; $i++) {
            AuditEvent::create([
                'event_key' => 'a2.sample', 'summary' => 'Local A2 event '.$i,
                'before_json' => ['secret' => 'A2_PRIVATE_PAYLOAD'],
                'meta_json' => ['credential' => 'A2_PRIVATE_METADATA'],
                'created_at' => '2026-10-19 10:00:00',
            ]);
        }
        $component = Livewire::actingAs($this->owner)->test(SettingsIndex::class)
            ->call('setSection', 'audit')
            ->set('auditEvent', 'a2.')
            ->set('auditFrom', '2026-10-19')->set('auditUntil', '2026-10-19')
            ->call('applyAuditFilters')
            ->assertViewHas('auditEvents', fn ($events) => $events->count() === 20 && $events->hasMorePages())
            ->assertDontSee('Foreign company event')
            ->assertDontSee('A2_PRIVATE_PAYLOAD')->assertDontSee('A2_PRIVATE_METADATA');
        $component->call('nextPage', 'auditPage')
            ->assertViewHas('auditEvents', fn ($events) => $events->count() === 5 && ! $events->hasMorePages());
        $component->set('auditFrom', '2026-10-20')->set('auditUntil', '2026-10-20')->call('applyAuditFilters')
            ->assertViewHas('auditEvents', fn ($events) => $events->count() === 0);
    }

    public function test_audit_permission_cannot_be_bypassed_with_public_section_or_direct_filter_action(): void
    {
        $user = $this->member(['settings.company.view']);
        Livewire::actingAs($user)->test(SettingsIndex::class)->set('activeSection', 'audit')->assertForbidden();
        Livewire::actingAs($user)->test(SettingsIndex::class)->call('applyAuditFilters')->assertForbidden();
    }

    public function test_revoked_membership_and_permission_are_honored_on_existing_component(): void
    {
        $user = $this->member(['settings.company.view', 'audit.events.view']);
        $component = Livewire::actingAs($user)->test(SettingsIndex::class)->call('setSection', 'audit');
        $user->roles()->firstOrFail()->revokePermissionTo('audit.events.view');
        $component->call('applyAuditFilters')->assertForbidden();

        $component = Livewire::actingAs($this->owner)->test(SettingsIndex::class);
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $component->call('setSection', 'identity')->assertForbidden();
    }

    public function test_inactive_company_is_rejected_even_when_context_model_was_active(): void
    {
        $component = Livewire::actingAs($this->owner)->test(SettingsIndex::class);
        $this->company->update(['status' => 'inactive']);
        $component->call('setSection', 'audit')->assertForbidden();
    }

    public function test_stale_settings_cannot_write_after_active_company_switch(): void
    {
        $component = Livewire::actingAs($this->owner)->test(SettingsIndex::class);
        $other = $this->createCompany($this->owner);
        app(CompanyContext::class)->setCompany($other, $this->owner);
        $component->call('saveIdentity')->assertForbidden();
        $this->assertSame('A2 Test Company', $other->fresh()->name_en);
    }

    public function test_owner_export_preparation_requires_recent_password_confirmation(): void
    {
        Livewire::actingAs($this->owner)->test(SettingsIndex::class)
            ->call('prepareCompanyDataDownload')->assertRedirect(route('password.confirm'));
        $this->assertSame(route('settings.index'), session('url.intended'));
    }

    public function test_restricted_settings_has_no_privileged_navigation_and_cannot_prepare_owner_export(): void
    {
        $user = $this->member(['settings.company.view']);
        Livewire::actingAs($user)->test(SettingsIndex::class)
            ->assertSee(route('profile'))
            ->assertDontSee(route('warehouses.index'))
            ->assertDontSee(route('settings.taxes'))
            ->assertDontSee('id="company-data-title"', false)
            ->assertDontSee(route('settings.company-data.download'))
            ->call('prepareCompanyDataDownload')->assertForbidden();
    }

    public function test_personal_profile_remains_accessible_to_sales_warehouse_and_cashier_without_settings_permission(): void
    {
        foreach (['Sales', 'Warehouse', 'Cashier'] as $role) {
            $user = $this->member([], $role);
            $this->actingAs($user)->get(route('profile'))->assertOk();
            $this->actingAs($user)->get(route('settings.index'))->assertForbidden();
        }
    }
}
