<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Domain\Money\Queries\MoneyDailySummaryQuery;
use App\Livewire\Pages\Money\AccountDetail;
use App\Livewire\Pages\Money\Overview;
use App\Models\Customer;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Phase7\Phase7TestCase;

class MoneyDailySummaryTest extends Phase7TestCase
{
    public function test_boundary_dates_before_any_activity_returns_zero_balances(): void
    {
        $this->activate($this->owner);

        $summary = app(MoneyDailySummaryQuery::class)->forAccount($this->ilsCashAccount, '2026-09-01');

        $this->assertTrue($summary['is_available']);
        $this->assertTrue($summary['currency_available']);
        $this->assertSame('0.000000', $summary['opening_base']);
        $this->assertSame('0.000000', $summary['inflow_base']);
        $this->assertSame('0.000000', $summary['outflow_base']);
        $this->assertSame('0.000000', $summary['closing_base']);
        $this->assertSame('0.000000', $summary['opening_currency']);
        $this->assertSame('0.000000', $summary['inflow_currency']);
        $this->assertSame('0.000000', $summary['outflow_currency']);
        $this->assertSame('0.000000', $summary['closing_currency']);
        $this->assertSame('2026-09-01', $summary['date']);
    }

    public function test_boundary_dates_on_and_around_single_inflow(): void
    {
        $this->activate($this->owner);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل اختبار',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0',
            'payment_date' => '2026-10-02',
            'idempotency_key' => 'receipt-boundary-1',
            'allocations' => [],
        ]);

        $query = app(MoneyDailySummaryQuery::class);

        // 1. Day before transaction (2026-10-01)
        $before = $query->forAccount($this->ilsCashAccount, '2026-10-01');
        $this->assertSame('0.000000', $before['opening_base']);
        $this->assertSame('0.000000', $before['inflow_base']);
        $this->assertSame('0.000000', $before['outflow_base']);
        $this->assertSame('0.000000', $before['closing_base']);

        // 2. Exact transaction date (2026-10-02)
        $onDay = $query->forAccount($this->ilsCashAccount, '2026-10-02');
        $this->assertSame('0.000000', $onDay['opening_base']);
        $this->assertSame('100.000000', $onDay['inflow_base']);
        $this->assertSame('0.000000', $onDay['outflow_base']);
        $this->assertSame('100.000000', $onDay['closing_base']);
        $this->assertSame('100.000000', $onDay['inflow_currency']);
        $this->assertSame('100.000000', $onDay['closing_currency']);

        // 3. Day after transaction (2026-10-03)
        $after = $query->forAccount($this->ilsCashAccount, '2026-10-03');
        $this->assertSame('100.000000', $after['opening_base']);
        $this->assertSame('0.000000', $after['inflow_base']);
        $this->assertSame('0.000000', $after['outflow_base']);
        $this->assertSame('100.000000', $after['closing_base']);
        $this->assertSame('100.000000', $after['opening_currency']);
        $this->assertSame('100.000000', $after['closing_currency']);
    }

    public function test_negative_reversal_flows_count_on_actual_posting_date(): void
    {
        $this->activate($this->owner);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل العكس',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $payment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_method' => 'cash',
            'amount' => '150.00',
            'exchange_rate' => '1.0',
            'payment_date' => '2026-10-02',
            'idempotency_key' => 'receipt-to-reverse-1',
            'allocations' => [],
        ]);

        app(ReverseCustomerPaymentAction::class)->execute(
            $payment,
            $this->owner,
            'Testing reversal timing',
            '2026-10-05'
        );

        $query = app(MoneyDailySummaryQuery::class);

        // On transaction date (2026-10-02): reversal on 2026-10-05 has not happened yet
        $day1 = $query->forAccount($this->ilsCashAccount, '2026-10-02');
        $this->assertSame('0.000000', $day1['opening_base']);
        $this->assertSame('150.000000', $day1['inflow_base']);
        $this->assertSame('0.000000', $day1['outflow_base']);
        $this->assertSame('150.000000', $day1['closing_base']);

        // Between transaction and reversal (2026-10-04)
        $dayInter = $query->forAccount($this->ilsCashAccount, '2026-10-04');
        $this->assertSame('150.000000', $dayInter['opening_base']);
        $this->assertSame('0.000000', $dayInter['inflow_base']);
        $this->assertSame('0.000000', $dayInter['outflow_base']);
        $this->assertSame('150.000000', $dayInter['closing_base']);

        // On reversal date (2026-10-05): reversal is recorded as outflow on actual posting date
        $dayRev = $query->forAccount($this->ilsCashAccount, '2026-10-05');
        $this->assertSame('150.000000', $dayRev['opening_base']);
        $this->assertSame('0.000000', $dayRev['inflow_base']);
        $this->assertSame('150.000000', $dayRev['outflow_base']);
        $this->assertSame('0.000000', $dayRev['closing_base']);

        // After reversal (2026-10-06)
        $dayAfter = $query->forAccount($this->ilsCashAccount, '2026-10-06');
        $this->assertSame('0.000000', $dayAfter['opening_base']);
        $this->assertSame('0.000000', $dayAfter['inflow_base']);
        $this->assertSame('0.000000', $dayAfter['outflow_base']);
        $this->assertSame('0.000000', $dayAfter['closing_base']);
    }

    public function test_base_only_fallback_policy_for_base_currency_account_with_missing_metadata(): void
    {
        $this->activate($this->owner);

        // Insert legacy opening balance posting batch and line without transaction currency metadata on base account
        $batchId = DB::table('posting_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'source_type' => 'manual',
            'source_id' => 1,
            'posting_date' => '2026-10-01',
            'status' => 'posted',
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'posted_by' => $this->owner->id,
            'posted_at' => now(),
            'idempotency_key' => 'legacy-open-ils',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('posting_lines')->insert([
            'posting_batch_id' => $batchId,
            'company_id' => $this->company->id,
            'ledger_account_id' => $this->ilsCashAccount->ledger_account_id,
            'line_number' => 1,
            'debit_base' => '500.000000',
            'credit_base' => '0.000000',
            'transaction_amount' => null,
            'transaction_currency_code' => null,
            'exchange_rate' => null,
            'created_at' => now(),
        ]);

        $summary = app(MoneyDailySummaryQuery::class)->forAccount($this->ilsCashAccount, '2026-10-02');

        // Base currency account preserves native balance from base ledger even when metadata was omitted
        $this->assertTrue($summary['currency_available']);
        $this->assertSame('500.000000', $summary['opening_base']);
        $this->assertSame('500.000000', $summary['opening_currency']);
        $this->assertSame('500.000000', $summary['closing_base']);
        $this->assertSame('500.000000', $summary['closing_currency']);
    }

    public function test_foreign_currency_account_indicates_native_unavailable_when_metadata_missing_or_mismatched(): void
    {
        $this->activate($this->owner);

        // Case A: Missing metadata on foreign USD account
        $batchId = DB::table('posting_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'source_type' => 'manual',
            'source_id' => 2,
            'posting_date' => '2026-10-01',
            'status' => 'posted',
            'transaction_currency_code' => 'USD',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '3.5000000000',
            'posted_by' => $this->owner->id,
            'posted_at' => now(),
            'idempotency_key' => 'missing-meta-usd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('posting_lines')->insert([
            'posting_batch_id' => $batchId,
            'company_id' => $this->company->id,
            'ledger_account_id' => $this->usdBankAccount->ledger_account_id,
            'line_number' => 1,
            'debit_base' => '350.000000',
            'credit_base' => '0.000000',
            'transaction_amount' => null,
            'transaction_currency_code' => null,
            'exchange_rate' => null,
            'created_at' => now(),
        ]);

        $summary = app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-02');

        $this->assertTrue($summary['is_available']);
        $this->assertFalse($summary['currency_available']);
        $this->assertSame('350.000000', $summary['opening_base']);
        $this->assertNull($summary['opening_currency']);
        $this->assertNull($summary['closing_currency']);

        // Case B: Mismatched foreign metadata (e.g. line tagged with EUR on USD account)
        DB::table('posting_lines')->where('posting_batch_id', $batchId)->update([
            'transaction_currency_code' => 'EUR',
            'transaction_amount' => '100.000000',
        ]);

        $summaryMismatched = app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-02');
        $this->assertFalse($summaryMismatched['currency_available']);
        $this->assertNull($summaryMismatched['opening_currency']);
        $this->assertSame('350.000000', $summaryMismatched['opening_base']);
    }

    public function test_restricted_checks_and_sensitive_phase7_sources_prevent_hidden_leakage(): void
    {
        $this->activate($this->owner);

        // Create an expense check (restricted under Phase 7)
        $check = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'expense',
            'category_id' => $this->operatingCategory->id,
            'amount' => '100.000000',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'date' => '2026-10-07',
            'due_date' => '2026-10-15',
            'check_number' => 'CHK-SECRET-DAILY',
            'bank_name' => 'Bank of Palestine',
            'money_account_id' => $this->usdBankAccount->id,
            'classification' => 'operating',
            'description' => 'Confidential fee',
            'idempotency_key' => 'chk-secret-daily-key',
        ]);
        app(TransitionCheckAction::class)->execute($check, $this->owner, ['event_type' => 'clear',
            'event_date' => '2026-10-15', 'exchange_rate' => '3.5', 'idempotency_key' => 'clear-secret-daily']);

        // User with ONLY bank view permission (NO expense or cost permission)
        $restrictedUser = $this->customActor(['money.bank.view']);
        $this->activate($restrictedUser);

        $summary = app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-15');

        // Must report unavailable to prevent amount leakage
        $this->assertFalse($summary['is_available']);
        $this->assertNull($summary['opening_base']);
        $this->assertNull($summary['inflow_base']);
        $this->assertNull($summary['outflow_base']);
        $this->assertNull($summary['closing_base']);
        $this->assertNull($summary['opening_currency']);
        $this->assertNull($summary['inflow_currency']);
        $this->assertNull($summary['outflow_currency']);
        $this->assertNull($summary['closing_currency']);

        // Owner with full permissions can view the truthful numbers
        $this->activate($this->owner);
        $ownerSummary = app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-15');
        $this->assertTrue($ownerSummary['is_available']);
    }

    public function test_tenant_switch_and_permission_loss_fail_closed(): void
    {
        $this->activate($this->owner);

        // Foreign company setup
        app(CompanyContext::class)->clear();
        $foreignOwner = User::factory()->create();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, [
            'name_ar' => 'شركة أخرى',
            'base_currency_code' => 'ILS',
        ]);
        $foreignAccount = MoneyAccount::withoutGlobalScopes()->where('company_id', $foreignCompany->id)->firstOrFail();
        $this->activate($this->owner);

        // Attempting to query foreign account while active in first company must fail
        $this->expectException(AuthorizationException::class);
        app(MoneyDailySummaryQuery::class)->forAccount($foreignAccount, '2026-10-02');
    }

    public function test_read_only_query_performs_no_financial_or_stock_writes(): void
    {
        $this->activate($this->owner);

        $batchesBefore = PostingBatch::count();
        $linesBefore = PostingLine::count();
        $accountsBefore = MoneyAccount::count();

        $query = app(MoneyDailySummaryQuery::class);
        $query->forAccount($this->ilsCashAccount, '2026-10-01');
        $query->forAccount($this->ilsCashAccount, '2026-10-02');
        $query->forTypes($this->company, ['cash', 'bank'], '2026-10-02');

        $this->assertSame($batchesBefore, PostingBatch::count());
        $this->assertSame($linesBefore, PostingLine::count());
        $this->assertSame($accountsBefore, MoneyAccount::count());
    }

    public function test_stale_account_type_cannot_bypass_the_persisted_bank_permission(): void
    {
        $actor = $this->customActor(['money.cash.view']);
        $this->activate($actor);
        $stale = clone $this->usdBankAccount;
        $stale->account_type = 'cash';
        $this->expectException(AuthorizationException::class);
        app(MoneyDailySummaryQuery::class)->forAccount($stale, '2026-10-01');
    }

    public function test_revoked_money_permission_denies_an_open_page_on_next_request(): void
    {
        $page = Livewire::test(Overview::class, ['type' => 'cash']);
        $this->owner->roles()->firstOrFail()->revokePermissionTo('money.cash.view');
        $page->call('$refresh')->assertForbidden();
    }

    public function test_livewire_overview_and_account_detail_date_selection_and_distinct_current_balance(): void
    {
        $this->activate($this->owner);

        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل العرض',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_method' => 'cash',
            'amount' => '250.00',
            'exchange_rate' => '1.0',
            'payment_date' => '2026-10-02',
            'idempotency_key' => 'receipt-ui-test-1',
            'allocations' => [],
        ]);

        // Overview test
        $overview = Livewire::test(Overview::class)
            ->assertStatus(200)
            ->assertSee(__('money.current_balance'))
            ->assertSee(__('money.daily_summary'))
            ->assertSee('250.000000');

        // Change date to past date where balance was zero
        $overview->set('date', '2026-10-01')
            ->assertStatus(200)
            ->assertSee('2026-10-01');

        // AccountDetail test
        $detail = Livewire::test(AccountDetail::class, ['publicId' => $this->ilsCashAccount->public_id])
            ->assertStatus(200)
            ->assertSee(__('money.current_balance'))
            ->assertSee(__('money.daily_summary'))
            ->assertSee('250.000000');

        $detail->set('date', '2026-10-01')
            ->assertStatus(200)
            ->assertSee('2026-10-01');
    }
}
