<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Accounting\PostOpeningBalancesAction;
use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Domain\Purchasing\Queries\VendorBalanceQuery;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Livewire\Pages\Purchasing\VendorDetail;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\Purchase;
use App\Models\VendorPayment;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorPaymentHistoryCommands;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class VendorPaymentCorrection03RegressionTest extends Phase5ETestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payment(array $changes = []): VendorPayment
    {
        return app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, array_replace([
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02', 'payment_method' => 'cash',
            'amount' => '100.00', 'exchange_rate' => '1',
            'idempotency_key' => 'correction03-payment', 'allocations' => [],
        ], $changes));
    }

    public function test_deleted_money_account_history_retry_reconciliation_and_exact_reversal(): void
    {
        $purchase = $this->createAndPostPurchase();
        $intent = ['allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']]];
        $payment = $this->payment($intent);
        $this->ilsCashAccount->delete();
        $counts = [DB::table('vendor_payments')->count(), DB::table('posting_batches')->count()];
        $this->assertSame($payment->id, $this->payment($intent)->id);
        $this->assertSame($counts, [DB::table('vendor_payments')->count(), DB::table('posting_batches')->count()]);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment->fresh());
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
        $reversed = app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        app(VendorPaymentHistoryCommands::class)->assertReversal($payment->postingBatch->fresh(), $reversed->reversal_posting_batch_id, $this->owner->id);
        $this->assertTrue($reversed->is_reversed);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertSame($payment->id, $this->payment($intent)->id);
    }

    public function test_new_payment_cannot_use_deleted_account_and_has_no_effect(): void
    {
        $this->ilsCashAccount->delete();
        $before = DB::table('document_sequences')->pluck('next_number', 'id')->all();
        try {
            $this->payment();
            $this->fail('Deleted account accepted for new Payment.');
        } catch (ModelNotFoundException $exception) {
            $this->assertDatabaseCount('vendor_payments', 0);
            $this->assertDatabaseCount('posting_batches', 0);
            $this->assertSame($before, DB::table('document_sequences')->pluck('next_number', 'id')->all());
        }
    }

    public function test_historical_deleted_account_cannot_be_substituted(): void
    {
        $payment = $this->payment();
        $this->ilsCashAccount->delete();
        $other = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH, 'currency_code' => 'ILS', 'name_ar' => 'Other Cash',
        ]);
        DB::table('vendor_payments')->where('id', $payment->id)->update(['money_account_id' => $other->id]);
        $this->assertFalse(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public static function financialReaders(): array
    {
        return array_map(fn (string $permission) => [$permission], [
            'money.vendor_payment.create', 'money.vendor_payment.allocate',
            'money.vendor_payment.reverse', 'vendors.statement.view',
        ]);
    }

    #[DataProvider('financialReaders')]
    public function test_each_approved_financial_reader_can_receive_vendor_summary(string $permission): void
    {
        $this->createAndPostPurchase(['lines' => [['unit_cost' => '123.45']]]);
        $actor = $this->customActor(['vendors.view', 'purchasing.cost.view', $permission]);
        $this->activate($actor);
        Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->assertOk()->assertViewHas('withCost', true)->assertSee('1,234.50')
            ->assertViewHas('currencyBalances', fn ($values) => $values['ILS']['balance'] === '1234.500000');
    }

    public function test_identity_and_cost_only_reader_receives_no_financial_data_even_on_requested_tabs(): void
    {
        $this->createAndPostPurchase(['lines' => [['unit_cost' => '123.45']]]);
        $actor = $this->customActor(['vendors.view', 'purchasing.cost.view']);
        $this->activate($actor);
        $spy = \Mockery::mock(new VendorBalanceQuery);
        $spy->shouldNotReceive('execute');
        app()->instance(VendorBalanceQuery::class, $spy);
        $page = Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->assertOk()->assertSee($this->vendor->displayName())
            ->assertViewHas('currencyBalances', [])->assertViewHas('withCost', false)
            ->assertDontSee('1,234.50');
        foreach (['purchases', 'returns', 'statement'] as $tab) {
            $page->set('activeTab', $tab)->assertSet('activeTab', 'overview')->assertViewHas('currencyBalances', [])
                ->assertViewHas('tabPurchases', [])->assertViewHas('tabReturns', [])->assertViewHas('tabPayments', [])
                ->assertViewHas('statementData', null)->assertDontSee('1,234.50');
            $this->assertStringNotContainsString('1234.500000', json_encode($page->snapshot, JSON_THROW_ON_ERROR));
        }
        $page->set('activeTab', 'payments')->assertForbidden();
    }

    public function test_financial_permission_without_cost_does_not_expose_summary(): void
    {
        $this->createAndPostPurchase();
        $actor = $this->customActor(['vendors.view', 'vendors.statement.view']);
        $this->activate($actor);
        Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->assertOk()->assertViewHas('currencyBalances', [])->assertDontSee('100.00');
    }

    public function test_stale_vendor_overview_stops_exposing_financials_after_revocation(): void
    {
        $this->createAndPostPurchase(['lines' => [['unit_cost' => '123.45']]]);
        $actor = $this->customActor(['vendors.view', 'purchasing.cost.view', 'money.vendor_payment.create']);
        $this->activate($actor);
        $page = Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])->assertSee('1,234.50');
        $actor->roles->first()->revokePermissionTo('money.vendor_payment.create');
        $page->call('$refresh')->assertOk()->assertViewHas('currencyBalances', [])->assertDontSee('1,234.50');
        $this->assertStringNotContainsString('1234.500000', json_encode($page->snapshot, JSON_THROW_ON_ERROR));
    }

    private function januaryPurchase(): Purchase
    {
        return $this->createAndPostPurchase(['purchase_date' => '2026-01-01', 'due_date' => '2026-01-15']);
    }

    private function statement(string $date): array
    {
        return app(VendorStatementQuery::class)->execute($this->vendor, null, $date)['currencies']['ILS'];
    }

    private function assertPosition(string $date, string $balance, string $gross, string $credit = '0.00'): array
    {
        $statement = $this->statement($date);
        $this->assertSame($balance, $statement['closing_balance']);
        $this->assertSame($balance, $statement['aging']['signed_vendor_balance']);
        $this->assertSame($gross, $statement['aging']['gross_open_purchases']);
        $this->assertSame($credit, $statement['aging']['unapplied_credit_position']);

        return $statement['aging'];
    }

    public function test_january_aging_excludes_february_payment_and_uses_january_bucket(): void
    {
        $purchase = $this->januaryPurchase();
        $this->payment(['payment_date' => '2026-02-01', 'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']]]);
        $aging = $this->assertPosition('2026-01-31', '100.00', '100.00');
        $this->assertSame('100.00', $aging['days_1_30']);
        $this->assertSame('0.00', $aging['days_90_plus']);
        $this->assertPosition('2026-02-28', '0.00', '0.00');
        $current = app(VendorStatementQuery::class)->execute($this->vendor)['currencies']['ILS'];
        $this->assertSame('0.00', $current['aging']['gross_open_purchases']);
    }

    public function test_january_aging_excludes_february_return(): void
    {
        $purchase = $this->januaryPurchase();
        $this->createAndPostReturn($purchase, ['return_date' => '2026-02-01', 'lines' => [['quantity' => '3']]]);
        $this->assertPosition('2026-01-31', '100.00', '100.00');
        $this->assertPosition('2026-02-28', '70.00', '70.00');
    }

    public function test_payment_reversal_after_cutoff_does_not_erase_historical_allocation(): void
    {
        $purchase = $this->januaryPurchase();
        $payment = $this->payment(['payment_date' => '2026-01-20', 'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']]]);
        Carbon::setTestNow('2026-03-01 12:00:00');
        app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        $this->assertPosition('2026-02-28', '0.00', '0.00');
        $this->assertPosition('2026-04-01', '100.00', '100.00');
    }

    public function test_later_advance_application_and_reversal_preserve_historical_gross_and_credit(): void
    {
        $payment = $this->payment(['payment_date' => '2026-01-01']);
        $purchase = $this->createAndPostPurchase(['purchase_date' => '2026-02-01', 'due_date' => '2026-02-15']);
        app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-03-01', 'idempotency_key' => 'correction03-application',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']],
        ]);
        $this->assertPosition('2026-02-28', '0.00', '100.00', '100.00');
        $this->assertPosition('2026-04-01', '0.00', '0.00');
        Carbon::setTestNow('2026-05-01 12:00:00');
        app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        $this->assertPosition('2026-04-01', '0.00', '0.00');
        $this->assertPosition('2026-05-31', '100.00', '100.00');
    }

    public function test_reversal_end_of_day_uses_company_timezone_in_statement_and_aging(): void
    {
        $this->company->update(['timezone' => 'Asia/Hebron']);
        $purchase = $this->januaryPurchase();
        $payment = $this->payment(['payment_date' => '2026-01-20', 'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']]]);
        // UTC Feb 28 evening is already March 1 in company-local time.
        Carbon::setTestNow(Carbon::parse('2026-02-28 23:30:00', 'UTC'));
        app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        $this->assertPosition('2026-02-28', '0.00', '0.00');
        $this->assertPosition('2026-03-01', '100.00', '100.00');
    }

    public function test_future_purchase_is_excluded_and_default_cutoff_is_company_today(): void
    {
        Carbon::setTestNow('2026-02-28 12:00:00');
        $this->januaryPurchase();
        $this->createAndPostPurchase(['purchase_date' => '2026-03-01']);
        $this->assertPosition('2026-02-28', '100.00', '100.00');
        $current = app(VendorStatementQuery::class)->execute($this->vendor)['currencies']['ILS'];
        $this->assertSame('100.00', $current['closing_balance']);
        $this->assertSame('100.00', $current['aging']['signed_vendor_balance']);
    }

    public function test_opening_ap_outside_vendor_sources_does_not_fail_reconciliation(): void
    {
        $ap = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_payable')->firstOrFail();
        $opening = app(PostOpeningBalancesAction::class)->execute($this->company, now(),
            liabilityBalances: [['account_id' => $ap->id, 'amount' => '500']], postedBy: $this->owner);
        $this->assertSame('opening_balance', $opening->source_type);
        $purchase = $this->createAndPostPurchase();
        $this->payment(['allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']]]);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertPosition('2026-10-31', '0.00', '0.00');
    }

    public static function canonicalSources(): array
    {
        return [['purchase'], ['purchase_return'], ['vendor_payment']];
    }

    #[DataProvider('canonicalSources')]
    public function test_canonical_ap_corruption_is_detected_by_source_validators(string $source): void
    {
        $purchase = $this->createAndPostPurchase();
        $batch = $purchase->posting_batch_id;
        if ($source === 'purchase_return') {
            $batch = $this->createAndPostReturn($purchase)->posting_batch_id;
        } elseif ($source === 'vendor_payment') {
            $batch = $this->payment()->posting_batch_id;
        }
        $ap = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_payable')->firstOrFail();
        $line = DB::table('posting_lines')->where('posting_batch_id', $batch)->where('ledger_account_id', $ap->id)->first();
        DB::table('posting_lines')->where('id', $line->id)->update(['debit_base' => '999.000000']);
        $this->assertFalse(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }
}
