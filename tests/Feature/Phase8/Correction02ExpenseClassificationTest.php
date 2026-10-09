<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Expenses\ReverseExpenseAction;
use App\Application\Reporting\Queries\ExpenseReportQuery;
use App\Application\Reporting\Queries\ProfitReportQuery;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class Correction02ExpenseClassificationTest extends Phase8TestCase
{
    /**
     * @param  list<string>  $permissions
     */
    private function createMemberWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'Role_'.Str::random(10),
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo(array_unique($permissions));
        $user->assignRole($role);
        $this->activateUser($user);

        return $user;
    }

    private function postExpense(string $classification, string $amount, string $date = '2026-10-05'): Expense
    {
        return app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $classification === 'operating' ? $this->operatingCategory->id : $this->landedCategory->id,
            'expense_date' => $date,
            'classification' => $classification,
            'description' => "Expense {$classification} {$amount}",
            'currency_code' => 'ILS',
            'amount' => $amount,
            'exchange_rate' => '1.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->ilsCashAccount->id,
            'idempotency_key' => 'exp-'.Str::ulid(),
        ]);
    }

    public function test_operating_defaults_and_landed_cost_segregation(): void
    {
        $this->activateUser($this->owner);

        // 1. Post 100 ILS operating and 40 ILS landed cost
        $opExp = $this->postExpense('operating', '100.000000');
        $lcExp = $this->postExpense('landed_cost', '40.000000');

        $query = app(ExpenseReportQuery::class);

        // Default query: groups operating only
        $defaultResult = $query->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $defaultRows = $defaultResult->rows;
        $this->assertNotEmpty($defaultRows);
        $this->assertSame('100.000000', $defaultResult->totals['ordinary_operating_expense_base']);

        // Explicit operating query
        $opResult = $query->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'operating']);
        $this->assertSame('100.000000', $opResult->totals['ordinary_operating_expense_base']);

        // Explicit landed cost query
        $lcResult = $query->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'landed_cost']);
        $this->assertSame('40.000000', $lcResult->totals['landed_cost_clearing_base']);
        // Landed cost rows must only contain the landed category
        $this->assertCount(1, $lcResult->rows);
        $this->assertSame($this->landedCategory->id, $lcResult->rows[0]['category_id']);
    }

    public function test_landed_cost_query_fails_closed_without_purchasing_cost_view(): void
    {
        $restrictedUser = $this->createMemberWithPermissions([
            'reports.expenses.view',
            'money.expense.view',
        ]);
        $this->activateUser($restrictedUser);

        $query = app(ExpenseReportQuery::class);

        // Operating query succeeds
        $opResult = $query->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'operating']);
        $this->assertNotNull($opResult);

        // Landed cost query fails closed with AuthorizationException
        $this->expectException(AuthorizationException::class);
        $query->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'landed_cost']);
    }

    public function test_profit_report_excludes_landed_cost(): void
    {
        $this->activateUser($this->owner);

        // Post 50 operating and 200 landed cost
        $this->postExpense('operating', '50.000000');
        $this->postExpense('landed_cost', '200.000000');

        $profitQuery = app(ProfitReportQuery::class);
        $result = $profitQuery->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);

        // Operating expenses must reflect exactly 50, landed costs must NOT be included in operating_expenses or net profit
        $this->assertSame('50.000000', $result->operatingExpenses);
        $this->assertSame('-50.000000', $result->netProfit);
    }

    public function test_landed_cost_reversal_signs_preserved(): void
    {
        $this->activateUser($this->owner);

        $lcExp = $this->postExpense('landed_cost', '75.000000', '2026-10-02');
        app(ReverseExpenseAction::class)->execute($lcExp, $this->owner, 'Reversal test', '2026-10-08');

        $query = app(ExpenseReportQuery::class);
        $result = $query->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'landed_cost']);

        // Net landed base after reversal must be 0.000000
        $this->assertSame('0.000000', $result->totals['landed_cost_clearing_base']);

        // Reversal-only window: Oct 7 to Oct 9 must reflect signed negative reversal (-75.000000)
        $reversalWindow = $query->execute($this->company, ['from' => '2026-10-07', 'to' => '2026-10-09', 'status' => 'landed_cost']);
        $this->assertSame('-75.000000', $reversalWindow->totals['landed_cost_clearing_base']);
    }

    public function test_landed_cost_grouping_currency_and_period(): void
    {
        $this->activateUser($this->owner);

        // 1. Post Landed cost in ILS: 100 on Oct 2
        $this->postExpense('landed_cost', '100.000000', '2026-10-02');

        // 2. Post Landed cost in USD: 50 USD at rate 3.5 = 175 ILS on Oct 5
        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->landedCategory->id,
            'expense_date' => '2026-10-05',
            'classification' => 'landed_cost',
            'description' => 'Landed Cost USD',
            'currency_code' => 'USD',
            'amount' => '50.000000',
            'exchange_rate' => '3.500000',
            'payment_method' => 'bank',
            'money_account_id' => $this->usdBankAccount->id,
            'idempotency_key' => 'exp-'.Str::ulid(),
        ]);

        $query = app(ExpenseReportQuery::class);

        // Grouping by currency
        $currencyResult = $query->execute($this->company, [
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'status' => 'landed_cost',
            'grouping' => 'currency',
        ]);
        $this->assertCount(2, $currencyResult->rows);
        $ilsRow = collect($currencyResult->rows)->firstWhere('currency_code', 'ILS');
        $usdRow = collect($currencyResult->rows)->firstWhere('currency_code', 'USD');
        $this->assertSame('100.000000', $ilsRow['total_amount']);
        $this->assertSame('50.000000', $usdRow['total_amount']);

        // Grouping by period
        $periodResult = $query->execute($this->company, [
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'status' => 'landed_cost',
            'grouping' => 'period',
        ]);
        $this->assertCount(1, $periodResult->rows);
        $this->assertSame('2026-10', $periodResult->rows[0]['period']);
        // Total base = 100 + (50 * 3.5) = 275.000000
        $this->assertSame('275.000000', $periodResult->rows[0]['total_base']);
        $this->assertSame(2, $periodResult->rows[0]['transaction_count']);

        // Grouping by category
        $categoryResult = $query->execute($this->company, [
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'status' => 'landed_cost',
            'grouping' => 'category',
        ]);
        $this->assertCount(1, $categoryResult->rows);
        $this->assertSame('275.000000', $categoryResult->rows[0]['total_base']);
    }
}
