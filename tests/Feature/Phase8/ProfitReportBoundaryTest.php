<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Exceptions\MissingSystemAccountException;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Application\Reporting\Queries\ProfitReportQuery;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Posting\AccountingPostingService;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProfitReportBoundaryTest extends Phase8TestCase
{
    public function test_typed_filter_cannot_make_profit_claim_an_unsupported_currency_filter(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        app(ProfitReportQuery::class)->execute($this->company, new ReportFilters($this->period(), currencyCode: 'USD'), $this->owner);
    }

    public function test_wrong_system_account_normal_balance_fails_closed(): void
    {
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'sales_returns')->update(['normal_balance' => 'credit']);
        $this->expectException(MissingSystemAccountException::class);
        app(ProfitReportQuery::class)->execute($this->company, ['period' => $this->period()], $this->owner);
    }

    public function test_wrong_salary_parent_fails_closed(): void
    {
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'salary_expense')->update(['parent_id' => null]);
        $this->expectException(MissingSystemAccountException::class);
        app(ProfitReportQuery::class)->execute($this->company, ['period' => $this->period()], $this->owner);
    }

    public function test_other_expense_is_not_mislabelled_as_operating_or_discarded(): void
    {
        // A generic canonical journal may contain legitimate P&L outside the
        // operational Expense categories; it must remain in the Profit report.
        $account = LedgerAccount::create([
            'company_id' => $this->company->id, 'code' => '5998', 'name_ar' => 'مصروف مستقل',
            'name_en' => 'Independent Expense', 'account_type' => 'expense',
            'normal_balance' => 'debit', 'is_control' => false, 'is_system' => false, 'active' => true,
        ]);
        $equity = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'opening_balance_equity')->firstOrFail();
        app(AccountingPostingService::class)->post(new PostingCommand(
            company: $this->company, postingDate: CarbonImmutable::parse('2026-10-05'),
            sourceType: 'manual_journal', sourceId: 121, transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS', exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'profit-other-expense', postedBy: $this->owner,
            lines: [
                PostingLineCommand::debit(1, $account->id, '12.340000', 'ILS', '12.340000', ExchangeRate::one()),
                PostingLineCommand::credit(2, $equity->id, '12.340000', 'ILS', '12.340000', ExchangeRate::one()),
            ],
        ));

        $report = app(ProfitReportQuery::class)->execute($this->company, ['period' => $this->period()], $this->owner);
        $this->assertSame('0.000000', $report->operatingExpenses);
        $this->assertSame('12.340000', $report->otherExpense);
        $this->assertSame('-12.340000', $report->netProfit);
        $this->assertSame('12.340000', collect($report->rows)->firstWhere('key', 'other_expense')['amount']);
    }

    public function test_foreign_company_line_provenance_is_rejected_instead_of_silently_omitted(): void
    {
        $invoice = $this->invoice('ILS', '1', '25');
        app(CompanyContext::class)->clear();
        $foreignOwner = User::factory()->create();
        $foreign = app(CreateCompanyAction::class)->execute($foreignOwner, [
            'name_ar' => 'Foreign reporting company', 'base_currency_code' => 'ILS',
        ]);
        $this->activate($this->owner);
        DB::table('posting_lines')->where('posting_batch_id', $invoice->posting_batch_id)
            ->limit(1)->update(['company_id' => $foreign->id]);
        $this->expectException(ReportingException::class);
        app(ProfitReportQuery::class)->execute($this->company, ['period' => $this->period()], $this->owner);
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);
    }
}
