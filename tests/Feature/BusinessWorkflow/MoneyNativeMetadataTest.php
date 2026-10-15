<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Money\PostMoneyTransferAction;
use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Domain\Money\Queries\MoneyDailySummaryQuery;
use App\Domain\Money\Queries\MoneyMovementQuery;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Livewire\Pages\Money\AccountDetail;
use App\Livewire\Pages\Money\Overview;
use App\Models\MoneyAccount;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesPostingLines;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Phase7\Phase7TestCase;

final class MoneyNativeMetadataTest extends Phase7TestCase
{
    /** @return array<string, array{list<array{?string, string, string}>, string}> */
    public static function incompleteHistories(): array
    {
        return [
            'all null debit' => [[[null, '350', '0']], '350.000000'],
            'all null credit' => [[[null, '0', '350']], '-350.000000'],
            'unknown then known' => [[[null, '350', '0'], ['10', '35', '0']], '385.000000'],
            'known then unknown' => [[['10', '35', '0'], [null, '350', '0']], '385.000000'],
        ];
    }

    /** @param list<array{?string, string, string}> $history */
    #[DataProvider('incompleteHistories')]
    public function test_matching_code_missing_amount_is_unavailable_across_all_reads(array $history, string $base): void
    {
        foreach ($history as [$amount, $debit, $credit]) {
            $this->legacy($this->usdBankAccount, $debit, $credit, 'USD', $amount);
        }
        $before = $this->fingerprints();
        $balance = $this->balance($this->usdBankAccount);
        $this->assertSame($base, $balance['balance_base']);
        $this->assertFalse($balance['currency_balance_available']);
        $this->assertNull($balance['balance_currency']);
        foreach (['2026-10-01', '2026-10-02'] as $date) {
            $summary = app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, $date);
            $this->assertSame($base, $summary['closing_base']);
            $this->assertTrue($summary['is_available']);
            $this->assertFalse($summary['currency_available']);
            foreach (['opening', 'inflow', 'outflow', 'closing'] as $field) {
                $this->assertNull($summary[$field.'_currency']);
            }
        }
        $latest = app(MoneyMovementQuery::class)->forAccount($this->usdBankAccount, perPage: 1)->items()[0];
        $this->assertSame($base, $latest->running_base);
        $this->assertGreaterThan(0, (int) $latest->unknown_currency_lines);
        $this->assertSame($before, $this->fingerprints());
    }

    public function test_empty_and_complete_foreign_history_have_truthful_zero_and_native_amounts(): void
    {
        $empty = $this->balance($this->usdBankAccount);
        $this->assertTrue($empty['currency_balance_available']);
        $this->assertSame('0.000000', $empty['balance_currency']);
        $this->legacy($this->usdBankAccount, '350', '0', 'USD', '100');
        $this->assertSame('100.000000', $this->balance($this->usdBankAccount)['balance_currency']);
        $summary = app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-01');
        $this->assertTrue($summary['currency_available']);
        $this->assertSame('100.000000', $summary['inflow_currency']);
        $this->assertSame('350.000000', $summary['closing_base']);
    }

    public function test_base_currency_fallback_survives_null_aggregates_and_base_only_entries(): void
    {
        $this->legacy($this->ilsCashAccount, '350', '0', 'ILS', null);
        $this->legacy($this->ilsCashAccount, '0', '35', null, null);
        $balance = $this->balance($this->ilsCashAccount);
        $this->assertTrue($balance['currency_balance_available']);
        $this->assertSame('315.000000', $balance['balance_currency']);
        $summary = app(MoneyDailySummaryQuery::class)->forAccount($this->ilsCashAccount, '2026-10-01');
        $this->assertTrue($summary['currency_available']);
        $this->assertSame('350.000000', $summary['inflow_currency']);
        $this->assertSame('35.000000', $summary['outflow_currency']);
        $this->assertSame('315.000000', $summary['closing_currency']);
        Livewire::test(AccountDetail::class, ['publicId' => $this->ilsCashAccount->public_id])->assertStatus(200);
    }

    public function test_explicit_zero_native_amount_is_not_confused_with_null(): void
    {
        $this->legacy($this->usdBankAccount, '0.000001', '0', 'USD', '0');
        $balance = $this->balance($this->usdBankAccount);
        $this->assertTrue($balance['currency_balance_available']);
        $this->assertSame('0.000000', $balance['balance_currency']);
        $this->assertSame('0.000001', $balance['balance_base']);
        $summary = app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-01');
        $this->assertTrue($summary['currency_available']);
        $this->assertSame('0.000000', $summary['closing_currency']);
    }

    public function test_historical_cutoffs_and_running_windows_exclude_future_missing_metadata(): void
    {
        $this->legacy($this->usdBankAccount, '350', '0', 'USD', '100');
        $this->legacy($this->usdBankAccount, '35', '0', 'USD', null, '2026-10-02');
        $historical = $this->balance($this->usdBankAccount, '2026-10-01');
        $this->assertTrue($historical['currency_balance_available']);
        $this->assertSame('100.000000', $historical['balance_currency']);
        $summary = app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-01');
        $this->assertSame('100.000000', $summary['closing_currency']);
        $later = app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-02');
        $this->assertSame('350.000000', $later['opening_base']);
        $this->assertSame('35.000000', $later['inflow_base']);
        $this->assertFalse($later['currency_available']);
        $prior = app(MoneyMovementQuery::class)->forAccount($this->usdBankAccount, page: 2, perPage: 1)->items()[0];
        $this->assertSame(0, (int) $prior->unknown_currency_lines);
        $this->assertSame('100.000000', $prior->known_running_currency);
    }

    public function test_residual_exception_requires_a_complete_same_account_native_sibling(): void
    {
        // Synthetic legacy shape built with the canonical rounding-line builder.
        // This is a read-policy fixture, not a newly created canonical payment.
        $lines = [];
        app(SalesPostingLines::class)->append($lines, 1, $this->usdBankAccount->ledger_account_id,
            MoneyAmount::from('3.333334'), MoneyAmount::zero(), 'USD', MoneyAmount::from('1'), ExchangeRate::from('3.3333333333'));
        $this->assertCount(2, $lines);
        $batch = $this->legacy($this->usdBankAccount, '3.333333', '0', 'USD', '1', source: 'customer_payment');
        $equity = DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('account_type', 'equity')->value('id');
        DB::table('posting_lines')->where('posting_batch_id', $batch)->where('ledger_account_id', $equity)->update(['credit_base' => '3.333334']);
        DB::table('posting_lines')->insert(['posting_batch_id' => $batch, 'company_id' => $this->company->id,
            'ledger_account_id' => $this->usdBankAccount->ledger_account_id, 'line_number' => 3,
            'debit_base' => '0.000001', 'credit_base' => '0.000000', 'transaction_currency_code' => null,
            'transaction_amount' => null, 'exchange_rate' => null, 'created_at' => now()]);
        $this->assertTrue($this->balance($this->usdBankAccount)['currency_balance_available']);
        $this->assertSame('1.000000', $this->balance($this->usdBankAccount)['balance_currency']);
        $this->assertSame('3.333334', $this->balance($this->usdBankAccount)['balance_base']);
        $this->assertTrue(app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-02')['currency_available']);
        $this->assertSame(0, (int) app(MoneyMovementQuery::class)->forAccount($this->usdBankAccount)->items()[0]->unknown_currency_lines);
        DB::table('posting_lines')->where('posting_batch_id', $batch)->where('transaction_currency_code', 'USD')->update(['transaction_amount' => null]);
        $this->assertFalse($this->balance($this->usdBankAccount)['currency_balance_available']);
        $this->assertFalse(app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-02')['currency_available']);
        $this->assertGreaterThan(0, (int) app(MoneyMovementQuery::class)->forAccount($this->usdBankAccount)->items()[0]->unknown_currency_lines);
    }

    public function test_missing_and_wrong_currency_codes_are_not_treated_as_complete(): void
    {
        foreach ([null, 'JOD'] as $code) {
            $this->legacy($this->usdBankAccount, '35', '0', $code, '10');
            $this->assertFalse($this->balance($this->usdBankAccount)['currency_balance_available']);
            $this->assertFalse(app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-01')['currency_available']);
        }
    }

    public function test_money_pages_render_unavailable_in_both_languages_without_economic_writes(): void
    {
        $this->legacy($this->usdBankAccount, '350', '0', 'USD', null);
        $before = $this->fingerprints();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(Overview::class)->assertStatus(200)->assertSee(__('money.native_unavailable'));
            Livewire::test(AccountDetail::class, ['publicId' => $this->usdBankAccount->public_id])
                ->assertStatus(200)->assertSee(__('money.unavailable'));
        }
        $this->assertSame($before, $this->fingerprints());
    }

    public function test_restricted_history_remains_redacted_when_its_native_amount_is_missing(): void
    {
        $this->legacy($this->usdBankAccount, '350', '0', 'USD', null, source: 'vendor_payment');
        $this->legacy($this->usdBankAccount, '35', '0', 'USD', '10', '2026-10-02');
        $this->activate($this->customActor(['money.bank.view']));
        $summary = app(MoneyDailySummaryQuery::class)->forAccount($this->usdBankAccount, '2026-10-02');
        $this->assertFalse($summary['is_available']);
        foreach (['opening', 'inflow', 'outflow', 'closing'] as $field) {
            $this->assertNull($summary[$field.'_base']);
            $this->assertNull($summary[$field.'_currency']);
        }
        $rows = app(MoneyMovementQuery::class)->forAccount($this->usdBankAccount)->items();
        $this->assertCount(1, $rows);
        foreach ($rows as $row) {
            $this->assertNotSame('vendor_payment', $row->source_type);
            $this->assertNull($row->running_base);
            $this->assertNull($row->known_running_currency);
            $this->assertNull($row->unknown_currency_lines);
        }
    }

    public function test_canonical_fx_preserves_native_values_and_all_six_reconciliations(): void
    {
        app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, [
            'from_money_account_id' => $this->usdCashAccount->id, 'to_money_account_id' => $this->ilsCashAccount->id,
            'transfer_date' => '2026-10-03', 'from_amount' => '1.01', 'to_amount' => '3.37',
            'from_exchange_rate' => '3.3333333333', 'to_exchange_rate' => '1', 'idempotency_key' => 'native-fx-control',
        ]);
        $before = $this->fingerprints();
        $this->assertSame('-1.010000', $this->balance($this->usdCashAccount)['balance_currency']);
        $this->assertSame('3.370000', $this->balance($this->ilsCashAccount)['balance_currency']);
        $summary = app(MoneyDailySummaryQuery::class)->forAccount($this->usdCashAccount, '2026-10-03');
        $this->assertTrue($summary['currency_available']);
        $this->assertSame('1.010000', $summary['outflow_currency']);
        foreach ([
            [AccountingReconciliationService::class, 'reconcile'],
            [InventoryReconciliationService::class, 'auditCompany'],
            [SalesReconciliationService::class, 'reconcile'],
            [PayablesReconciliationService::class, 'reconcile'],
            [MoneyReconciliationService::class, 'reconcile'],
            [Phase7ReconciliationService::class, 'reconcile'],
        ] as [$service, $method]) {
            $this->assertTrue(app($service)->$method($this->company)->isHealthy, $service);
        }
        $this->assertSame($before, $this->fingerprints());
    }

    public function test_foreign_company_context_is_denied(): void
    {
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة أخرى']);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->expectException(AuthorizationException::class);
        app(MoneyBalanceQuery::class)->forType($other, 'bank');
    }

    /** @return array<string, mixed> */
    private function balance(MoneyAccount $account, ?string $date = null): array
    {
        return collect(app(MoneyBalanceQuery::class)->forType($this->company, $account->account_type, $date))
            ->firstWhere('public_id', $account->public_id);
    }

    /** Deliberately incomplete, balanced historical fixture on the owned disposable schema only. */
    private function legacy(MoneyAccount $account, string $debit, string $credit, ?string $code, ?string $amount, string $date = '2026-10-01', string $source = 'manual'): int
    {
        $batch = DB::table('posting_batches')->insertGetId(['public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id, 'source_type' => $source, 'source_id' => 9000,
            'posting_date' => $date, 'status' => 'posted', 'transaction_currency_code' => $account->currency_code,
            'base_currency_code' => 'ILS', 'exchange_rate' => '3.5000000000', 'posted_by' => $this->owner->id,
            'posted_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $equity = DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('account_type', 'equity')->value('id');
        foreach ([[$account->ledger_account_id, $debit, $credit, $code, $amount], [$equity, $credit, $debit, null, null]] as $i => [$ledger, $dr, $cr, $currency, $native]) {
            DB::table('posting_lines')->insert(['posting_batch_id' => $batch, 'company_id' => $this->company->id,
                'ledger_account_id' => $ledger, 'line_number' => $i + 1, 'debit_base' => (string) BigDecimal::of($dr)->toScale(6),
                'credit_base' => (string) BigDecimal::of($cr)->toScale(6), 'transaction_currency_code' => $currency,
                'transaction_amount' => $native, 'exchange_rate' => null, 'created_at' => now()]);
        }

        return $batch;
    }

    /** @return array<string, string> */
    private function fingerprints(): array
    {
        $hashes = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'inventory_balances', 'inventory_cost_states', 'inventory_lots', 'inventory_lot_balances', 'document_sequences'] as $table) {
            $hashes[$table] = hash('sha256', DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->toJson());
        }

        return $hashes;
    }
}
