<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Accounting\EnsureSystemLedgerAccountsAction;
use App\Actions\Company\CreateCompanyAction;
use App\Domain\Accounting\Catalog\SystemAccountsCatalog;
use App\Domain\Accounting\Exceptions\SystemAccountConflictException;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemChartProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected EnsureSystemLedgerAccountsAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة تجريبية للمخطط',
            'base_currency_code' => 'ILS',
        ]);

        $this->action = app(EnsureSystemLedgerAccountsAction::class);
        app(CompanyContext::class)->setCompany($this->company, $this->user);
    }

    public function test_new_company_automatically_has_all_system_accounts_provisioned(): void
    {
        $systemKeys = array_map(fn ($def) => $def->systemKey, SystemAccountsCatalog::all());

        $existingKeys = LedgerAccount::where('company_id', $this->company->id)
            ->whereNotNull('system_key')
            ->pluck('system_key')
            ->all();

        foreach ($systemKeys as $key) {
            $this->assertContains($key, $existingKeys, "System key [{$key}] was not provisioned in new company.");
        }

        // Verify required parent relationship
        $parent = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'operating_expense_parent')
            ->firstOrFail();

        $child = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'salary_expense')
            ->firstOrFail();

        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_provisioning_action_is_completely_idempotent(): void
    {
        $countBefore = LedgerAccount::where('company_id', $this->company->id)->count();

        // Run action again on the same company
        $this->action->execute($this->company);

        $countAfter = LedgerAccount::where('company_id', $this->company->id)->count();

        $this->assertSame($countBefore, $countAfter);
    }

    public function test_provisioning_raises_conflict_exception_on_code_collision(): void
    {
        // Delete a system account, but occupy its code with a custom account
        $cash = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'cash_control')
            ->firstOrFail();
        $code = $cash->code;

        // Force delete the system account to simulate a code occupied by a custom account
        LedgerAccount::where('id', $cash->id)->delete();

        // Create a rogue account with the same code
        LedgerAccount::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'system_key' => 'rogue_account',
            'name_ar' => 'حساب متعارض',
            'account_type' => LedgerAccount::TYPE_ASSET,
            'normal_balance' => LedgerAccount::BALANCE_DEBIT,
        ]);

        $this->expectException(SystemAccountConflictException::class);
        $this->expectExceptionMessage("Cannot provision system account [cash_control] with code [{$code}]");

        $this->action->execute($this->company);
    }

    public function test_accounting_bootstrap_artisan_command(): void
    {
        // Running command for specific company
        $this->artisan('accounting:bootstrap', [
            'companyPublicId' => $this->company->public_id,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain("System accounts provisioned successfully for [{$this->company->displayName()}]");

        // Running command for all companies
        $this->artisan('accounting:bootstrap')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully provisioned system chart of accounts for 1 company/companies.');
    }
}
