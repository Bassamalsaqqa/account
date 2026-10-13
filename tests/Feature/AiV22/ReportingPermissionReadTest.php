<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportingPermissionReadTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $owner = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'Synthetic permission company', 'name_en' => 'Synthetic permission company', 'base_currency_code' => 'ILS',
        ]);
        $this->reader = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $this->reader->id,
            'status' => 'active', 'is_owner' => false]);
        $this->actingAs($this->reader);
        app(CompanyContext::class)->setCompany($this->company, $this->reader);
        setPermissionsTeamId($this->company->id);
    }

    public function test_direct_and_company_role_permissions_match_installed_spatie(): void
    {
        $this->reader->givePermissionTo('ai.assistant.use');
        $role = Role::create(['name' => 'Synthetic report role', 'guard_name' => 'web', 'company_id' => $this->company->id]);
        $role->givePermissionTo(['reports.sales.view', 'sales.invoice.view']);
        $this->reader->assignRole($role);
        $this->assertParity(['ai.assistant.use', 'reports.sales.view', 'sales.invoice.view']);
        $this->assertTrue(app(ReportingGuard::class)->authorize($this->company, $this->reader, 'reports.sales.view')->is($this->reader));
    }

    public function test_global_role_uses_the_current_company_pivot_only(): void
    {
        $role = Role::create(['name' => 'Synthetic global report role', 'guard_name' => 'web', 'company_id' => null]);
        $role->givePermissionTo('reports.sales.view');
        $this->reader->assignRole($role);
        $this->assertParity(['reports.sales.view']);
        DB::table('model_has_roles')->where('model_id', $this->reader->id)->where('company_id', $this->company->id)->delete();
        $this->assertParity([]);
    }

    public function test_foreign_company_role_and_direct_permissions_are_excluded(): void
    {
        app(CompanyContext::class)->clear();
        $foreignOwner = User::factory()->create();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, [
            'name_ar' => 'Synthetic foreign company', 'name_en' => 'Synthetic foreign company', 'base_currency_code' => 'ILS',
        ]);
        setPermissionsTeamId($foreignCompany->id);
        $this->reader->givePermissionTo('payroll.salary.view');
        $foreignRole = Role::create(['name' => 'Synthetic foreign role', 'guard_name' => 'web', 'company_id' => $foreignCompany->id]);
        $foreignRole->givePermissionTo('purchasing.cost.view');
        $this->reader->assignRole($foreignRole);
        app(CompanyContext::class)->setCompany($this->company, $this->reader);
        setPermissionsTeamId($this->company->id);
        $this->reader->givePermissionTo('ai.assistant.use');
        // Even a corrupt cross-company role pivot must not turn a foreign role into a local grant.
        DB::table('model_has_roles')->insert(['company_id' => $this->company->id, 'role_id' => $foreignRole->id,
            'model_type' => $this->reader->getMorphClass(), 'model_id' => $this->reader->id]);
        $this->assertParity(['ai.assistant.use']);
    }

    public function test_non_web_guard_permissions_are_excluded_from_direct_and_role_reads(): void
    {
        $otherGuardPermission = Permission::findOrCreate('synthetic.other-guard.permission', 'api');
        $this->reader->permissions()->attach($otherGuardPermission->id, ['company_id' => $this->company->id]);
        $otherGuardRole = Role::create(['name' => 'Synthetic API role', 'guard_name' => 'api', 'company_id' => $this->company->id]);
        $otherGuardRole->givePermissionTo($otherGuardPermission);
        $this->reader->roles()->attach($otherGuardRole->id, ['company_id' => $this->company->id]);
        $this->reader->givePermissionTo('reports.sales.view');
        $this->assertParity(['reports.sales.view']);
    }

    public function test_warmed_role_permission_is_rechecked_after_direct_database_revocation(): void
    {
        $role = Role::create(['name' => 'Synthetic revoke role', 'guard_name' => 'web', 'company_id' => $this->company->id]);
        $role->givePermissionTo('reports.sales.view');
        $this->reader->assignRole($role);
        $this->assertTrue($this->reader->getAllPermissions()->pluck('name')->contains('reports.sales.view'));
        DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
        $this->assertParity([]);
        $this->expectException(AuthorizationException::class);
        app(ReportingGuard::class)->authorize($this->company, $this->reader, 'reports.sales.view');
    }

    public function test_inactive_membership_cannot_use_a_warmed_permission_grant(): void
    {
        $this->reader->givePermissionTo('reports.sales.view');
        $this->reader->getAllPermissions();
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->reader->id)->update(['status' => 'inactive']);
        $this->expectException(AuthorizationException::class);
        app(ReportingGuard::class)->authorize($this->company, $this->reader, 'reports.sales.view');
    }

    public function test_inactive_company_cannot_use_a_warmed_permission_grant(): void
    {
        $this->reader->givePermissionTo('reports.sales.view');
        $this->reader->getAllPermissions();
        $this->company->update(['status' => 'inactive']);
        $this->expectException(AuthorizationException::class);
        app(ReportingGuard::class)->authorize($this->company, $this->reader, 'reports.sales.view');
    }

    /** @param list<string> $expected */
    private function assertParity(array $expected): void
    {
        $optimized = app(ReportingGuard::class)->permissionNames($this->reader);
        $this->reader->unsetRelation('roles')->unsetRelation('permissions');
        $installed = $this->reader->getAllPermissions()->where('guard_name', 'web')->pluck('name')->unique()->values()->all();
        sort($expected);
        sort($optimized);
        sort($installed);
        $this->assertSame($installed, $optimized, 'Optimized read must match installed Spatie team/guard semantics.');
        $this->assertSame($expected, $optimized);
    }
}
