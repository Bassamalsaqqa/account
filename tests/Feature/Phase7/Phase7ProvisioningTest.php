<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Accounting\EnsurePhase7FoundationAction;
use App\Actions\Company\CreateCompanyAction;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Phase7ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase7_bootstrap_provisions_accounts_categories_sequences_and_owner_permissions(): void
    {
        $owner = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة الاختبار',
            'name_en' => 'Test Company',
            'base_currency_code' => 'ILS',
        ]);

        // Verify accounts exist
        $this->assertDatabaseHas('ledger_accounts', [
            'company_id' => $company->id,
            'system_key' => 'employee_advances',
            'code' => '1202',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
        ]);

        $this->assertDatabaseHas('ledger_accounts', [
            'company_id' => $company->id,
            'system_key' => 'landed_cost_clearing',
            'code' => '1501',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
        ]);

        // Verify 13 default expense categories exist
        $categories = ExpenseCategory::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $this->assertCount(13, $categories);

        $salaryCat = $categories->firstWhere('code', 'salary');
        $this->assertNotNull($salaryCat);
        $salaryAccount = LedgerAccount::withoutGlobalScopes()->where('company_id', $company->id)->where('system_key', 'salary_expense')->first();
        $this->assertNotNull($salaryAccount);
        $this->assertSame($salaryAccount->id, $salaryCat->ledger_account_id);

        // Verify sequences exist
        foreach (['expense', 'employee_advance', 'salary_entry', 'salary_payment'] as $type) {
            $this->assertDatabaseHas('document_sequences', [
                'company_id' => $company->id,
                'document_type' => $type,
            ]);
        }

        // Verify Owner has all Phase 7 permissions
        setPermissionsTeamId($company->id);
        $ownerRole = Role::where('company_id', $company->id)->where('name', 'Owner')->first();
        $this->assertTrue($ownerRole->hasPermissionTo('purchasing.landed_cost.manage'));
        $this->assertTrue($ownerRole->hasPermissionTo('payroll.salary.post'));
        $this->assertTrue($ownerRole->hasPermissionTo('money.expense.reverse'));

        // Idempotency: run bootstrap again via artisan
        $exitCode = Artisan::call('phase7:bootstrap', ['companyPublicId' => $company->public_id]);
        $this->assertSame(0, $exitCode);

        // Still exactly 13 categories
        $this->assertCount(13, ExpenseCategory::withoutGlobalScopes()->where('company_id', $company->id)->get());
    }

    public function test_bootstrap_preserves_customized_non_owner_roles_and_categories(): void
    {
        $owner = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة ثانية',
            'base_currency_code' => 'ILS',
        ]);

        setPermissionsTeamId($company->id);

        // Customize Manager role by revoking a permission
        $managerRole = Role::where('company_id', $company->id)->where('name', 'Manager')->first();
        $managerRole->revokePermissionTo('audit.events.view');
        $this->assertFalse($managerRole->hasPermissionTo('audit.events.view'));

        // Customize a category
        $rent = ExpenseCategory::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'rent')->first();
        $this->assertNotNull($rent);
        $rent->update(['name_ar' => 'إيجار مخصص']);

        // Run bootstrap
        app(EnsurePhase7FoundationAction::class)->execute($company);

        // Manager still does NOT have the revoked permission (non-owner preserved)
        $managerRole->refresh();
        $this->assertFalse($managerRole->hasPermissionTo('audit.events.view'));

        // Customized category name preserved
        $rent->refresh();
        $this->assertSame('إيجار مخصص', $rent->name_ar);
    }
}
