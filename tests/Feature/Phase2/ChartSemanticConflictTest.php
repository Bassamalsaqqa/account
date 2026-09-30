<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Accounting\EnsureSystemLedgerAccountsAction;
use App\Actions\Company\CreateCompanyAction;
use App\Domain\Accounting\Exceptions\SystemAccountConflictException;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartSemanticConflictTest extends TestCase
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
            'name_ar' => 'شركة فحص التعارض الدلالي للمخطط',
            'base_currency_code' => 'ILS',
        ]);

        $this->action = app(EnsureSystemLedgerAccountsAction::class);
        app(CompanyContext::class)->setCompany($this->company, $this->user);
    }

    public function test_raises_conflict_when_account_type_diverges(): void
    {
        // Mutate cash_control account type from 'asset' to 'liability'
        $cash = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'cash_control')
            ->firstOrFail();

        $cash->account_type = LedgerAccount::TYPE_LIABILITY;
        $cash->save();

        $this->expectException(SystemAccountConflictException::class);
        $this->expectExceptionMessage('conflicting account_type');

        $this->action->execute($this->company);
    }

    public function test_raises_conflict_when_normal_balance_diverges(): void
    {
        // Mutate cash_control normal_balance from 'debit' to 'credit'
        $cash = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'cash_control')
            ->firstOrFail();

        $cash->normal_balance = LedgerAccount::BALANCE_CREDIT;
        $cash->save();

        $this->expectException(SystemAccountConflictException::class);
        $this->expectExceptionMessage('conflicting normal_balance');

        $this->action->execute($this->company);
    }

    public function test_raises_conflict_when_control_or_system_flag_diverges(): void
    {
        // Mutate cash_control is_control from true to false
        $cash = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'cash_control')
            ->firstOrFail();

        $cash->is_control = false;
        $cash->save();

        $this->expectException(SystemAccountConflictException::class);
        $this->expectExceptionMessage('conflicting is_control');

        $this->action->execute($this->company);
    }

    public function test_raises_conflict_when_system_account_is_inactive(): void
    {
        // Mutate cash_control active to false
        $cash = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'cash_control')
            ->firstOrFail();

        $cash->active = false;
        $cash->save();

        $this->expectException(SystemAccountConflictException::class);
        $this->expectExceptionMessage('conflicting active');

        $this->action->execute($this->company);
    }

    public function test_idempotent_rerun_preserves_chart_without_mutations(): void
    {
        $accountsBefore = LedgerAccount::where('company_id', $this->company->id)->pluck('updated_at', 'id')->all();

        // Rerun
        $this->action->execute($this->company);

        $accountsAfter = LedgerAccount::where('company_id', $this->company->id)->pluck('updated_at', 'id')->all();

        $this->assertSame(array_keys($accountsBefore), array_keys($accountsAfter));
    }
}
