<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Accounting\DerivedLedgerBalanceService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DerivedLedgerBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected AccountingPostingService $postingService;

    protected AccountingReversalService $reversalService;

    protected DerivedLedgerBalanceService $balanceService;

    protected LedgerAccount $cash;

    protected LedgerAccount $revenue;

    protected LedgerAccount $expense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة فحص الأرصدة المشتقة',
            'base_currency_code' => 'ILS',
        ]);

        $this->postingService = app(AccountingPostingService::class);
        $this->reversalService = app(AccountingReversalService::class);
        $this->balanceService = app(DerivedLedgerBalanceService::class);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->cash = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'cash_control')->firstOrFail();
        $this->revenue = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'sales_revenue')->firstOrFail();
        $this->expense = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'salary_expense')->firstOrFail();
    }

    public function test_derived_balances_accurately_reflect_transactions(): void
    {
        // Sale 1 on Day 1: Dr Cash 500, Cr Revenue 500
        $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: Carbon::parse('2026-09-01'),
            sourceType: 'sale',
            sourceId: 1,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'derived-sale-1',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '500.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '500.000000'),
            ]
        ));

        // Expense on Day 2: Dr Salary Expense 200, Cr Cash 200
        $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: Carbon::parse('2026-09-02'),
            sourceType: 'expense',
            sourceId: 2,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'derived-expense-1',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->expense->id, '200.000000'),
                PostingLineCommand::credit(2, $this->cash->id, '200.000000'),
            ]
        ));

        // Balance as of Day 1:
        // Cash: 500
        $cashDay1 = $this->balanceService->getAccountBalance($this->cash, Carbon::parse('2026-09-01'));
        $this->assertSame('500.000000', $cashDay1->toDecimalString());

        // Balance as of Day 2:
        // Cash: 500 - 200 = 300
        $cashDay2 = $this->balanceService->getAccountBalance($this->cash, Carbon::parse('2026-09-02'));
        $this->assertSame('300.000000', $cashDay2->toDecimalString());

        // Revenue (normal credit): 500
        $revenueBal = $this->balanceService->getAccountBalance($this->revenue);
        $this->assertSame('500.000000', $revenueBal->toDecimalString());

        // Expense (normal debit): 200
        $expenseBal = $this->balanceService->getAccountBalance($this->expense);
        $this->assertSame('200.000000', $expenseBal->toDecimalString());

        // Trial Balance is in balance (Debits 700 == Credits 700)
        $trialBalance = $this->balanceService->getTrialBalance($this->company);
        $this->assertTrue($trialBalance['is_balanced']);
        $this->assertSame('700.000000', $trialBalance['total_debit']->toDecimalString());
        $this->assertSame('700.000000', $trialBalance['total_credit']->toDecimalString());
    }

    public function test_reversal_offsets_account_balance_back_to_initial_state(): void
    {
        // Post a sale: Dr Cash 400, Cr Revenue 400
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 3,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'derived-sale-to-reverse',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '400.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '400.000000'),
            ]
        ));

        $this->assertSame('400.000000', $this->balanceService->getAccountBalance($this->cash)->toDecimalString());

        // Reverse the batch
        $this->reversalService->reverse($batch, $this->user);

        // Net balance must now be exactly 0
        $this->assertSame('0.000000', $this->balanceService->getAccountBalance($this->cash)->toDecimalString());
        $this->assertSame('0.000000', $this->balanceService->getAccountBalance($this->revenue)->toDecimalString());

        // Trial balance remains balanced
        $trialBalance = $this->balanceService->getTrialBalance($this->company);
        $this->assertTrue($trialBalance['is_balanced']);
    }
}
