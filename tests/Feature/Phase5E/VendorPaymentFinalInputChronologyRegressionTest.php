<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Livewire\Pages\Purchasing\PaymentDetail;
use App\Livewire\Pages\Purchasing\PaymentForm;
use App\Livewire\Pages\Purchasing\VendorDetail;
use App\Models\VendorPayment;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use App\Services\Purchasing\VendorPaymentValidationException;
use App\Services\Sales\SalesReconciliationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class VendorPaymentFinalInputChronologyRegressionTest extends Phase5ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
    }

    private function intent(array $changes = []): array
    {
        return array_replace([
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01', 'payment_method' => 'cash', 'amount' => '100.00',
            'exchange_rate' => '1', 'idempotency_key' => 'input-chronology-payment', 'allocations' => [],
        ], $changes);
    }

    public static function foreignCurrencies(): array
    {
        return [['USD'], ['JOD']];
    }

    #[DataProvider('foreignCurrencies')]
    public function test_foreign_default_and_purchase_preselection_require_explicit_fx(string $currency): void
    {
        $account = $currency === 'USD' ? $this->usdCashAccount : $this->jodCashAccount;
        $account->update(['sort_order' => -1]);
        Livewire::test(PaymentForm::class)->assertSet('money_account_id', $account->id)->assertSet('exchange_rate', '');
        $purchase = $this->createAndPostPurchase(['currency_code' => $currency, 'exchange_rate' => '3.50']);
        Livewire::test(PaymentForm::class, ['purchase_id' => $purchase->id])
            ->assertSet('currency_code', $currency)->assertSet('exchange_rate', '')
            ->assertSet('amount', '100.000000')
            ->assertSet('allocations.0.preview_fx', null);
    }

    public function test_account_switch_discards_stale_foreign_fx_without_changing_amount(): void
    {
        $page = Livewire::test(PaymentForm::class)->set('amount', '12.34')->set('exchange_rate', '3.60');
        foreach ([$this->usdCashAccount, $this->jodCashAccount, $this->usdBankAccount] as $account) {
            $page->set('money_account_id', $account->id)->assertSet('exchange_rate', '')->assertSet('amount', '12.34')
                ->set('exchange_rate', '4.50');
        }
        $page->set('money_account_id', $this->ilsCashAccount->id)->assertSet('exchange_rate', '1.0000000000')->assertSet('amount', '12.34');
    }

    public function test_blank_foreign_fx_cannot_post_or_consume_a_number_and_preview_waits_for_rate(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $before = $this->historySnapshot();
        $page = Livewire::test(PaymentForm::class, ['purchase_id' => $purchase->id])
            ->assertSet('exchange_rate', '')->assertSet('allocations.0.preview_fx', null)
            ->call('save')->assertHasErrors(['exchange_rate' => 'required']);
        $this->assertSame($before, $this->historySnapshot());
        $page->set('exchange_rate', '3.60')->assertSet('allocations.0.preview_fx', '10.000000')
            ->call('save')->assertHasNoErrors();
        $payment = VendorPayment::sole();
        $this->assertSame('360.000000', $payment->amount_base);
        $this->assertSame('10.000000', $payment->allocations->sole()->realized_fx_gain_loss_base);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
        $this->assertHealthy();
    }

    public function test_foreign_rate_one_is_allowed_when_explicitly_entered(): void
    {
        Livewire::test(PaymentForm::class)->set('vendor_id', $this->vendor->id)->set('money_account_id', $this->usdCashAccount->id)
            ->assertSet('exchange_rate', '')->set('exchange_rate', '1')->set('amount', '1.00')->call('save')->assertHasNoErrors();
        $payment = VendorPayment::sole();
        $this->assertSame('1.000000', $payment->amount_base);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
    }

    private function historySnapshot(): array
    {
        $snapshot = [];
        foreach (['vendor_payments', 'vendor_payment_allocations', 'vendor_payment_application_events', 'posting_batches', 'posting_lines', 'document_sequences', 'audit_events'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    public function test_future_payment_reversal_fails_atomically_and_ui_explains_why(): void
    {
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent(['payment_date' => '2026-10-07']));
        $before = $this->historySnapshot();
        Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])->set('showReverseModal', true)->call('reversePayment')
            ->assertHasErrors('reversalReason')->assertSee(__('purchasing.reversal_before_activity_date'));
        $this->assertSame($before, $this->historySnapshot());
        $this->assertFalse($payment->fresh()->is_reversed);
        $this->assertHealthy();
    }

    public static function applicationCurrencies(): array
    {
        return [['ILS', '1', false], ['USD', '3.60', true]];
    }

    #[DataProvider('applicationCurrencies')]
    public function test_future_application_blocks_complete_reversal_including_zero_gl_events(string $currency, string $rate, bool $hasBatch): void
    {
        $account = $currency === 'USD' ? $this->usdCashAccount : $this->ilsCashAccount;
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent([
            'money_account_id' => $account->id, 'exchange_rate' => $rate,
        ]));
        $purchase = $this->createAndPostPurchase(['currency_code' => $currency, 'exchange_rate' => $currency === 'USD' ? '3.50' : '1']);
        $event = app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-07', 'idempotency_key' => 'future-application',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '60.00']],
        ]);
        $this->assertSame($hasBatch, $event->posting_batch_id !== null);
        $before = $this->historySnapshot();
        try {
            app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
            $this->fail('A future application was reversed before its business date.');
        } catch (VendorPaymentValidationException $exception) {
            $this->assertSame('purchasing.reversal_before_activity_date', $exception->translationKey);
        }
        $this->assertSame($before, $this->historySnapshot());
        $this->company->update(['timezone' => 'Asia/Hebron']);
        $this->activate($this->owner);
        $this->travelTo(Carbon::parse('2026-10-06 22:30:00', 'UTC'));
        $reversed = app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        $this->assertSame('2026-10-07', $reversed->reversalPostingBatch->posting_date->toDateString());
        $this->assertNotNull($event->fresh()->reversed_at);
        $this->assertHealthy();
        $batches = DB::table('posting_batches')->count();
        $this->assertSame($reversed->reversal_posting_batch_id, app(ReverseVendorPaymentAction::class)->execute($reversed, $this->owner)->reversal_posting_batch_id);
        $this->assertSame($batches, DB::table('posting_batches')->count());
    }

    public function test_history_rejects_reversal_date_before_original_payment(): void
    {
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent());
        $reversed = app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        DB::table('posting_batches')->where('id', $reversed->reversal_posting_batch_id)->update(['posting_date' => '2026-09-30']);
        $this->assertFalse(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_history_rejects_reversal_date_before_zero_gl_application(): void
    {
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent());
        $purchase = $this->createAndPostPurchase();
        app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-05', 'idempotency_key' => 'zero-gl',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '60.00']],
        ]);
        $reversed = app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        DB::table('posting_batches')->where('id', $reversed->reversal_posting_batch_id)->update(['posting_date' => '2026-10-04']);
        $this->assertFalse(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public static function invalidRanges(): array
    {
        return [
            ['2026-10-07', '2026-10-06'], ['2026-02-30', '2026-10-06'], ['2026-1-01', '2026-10-06'],
            ['2026-10-01', 'tomorrow'], ['2026-10-01', '2026-10-06 12:00:00'], ['2026-10-07', null],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_query_rejects_invalid_or_inverted_statement_dates(?string $from, ?string $to): void
    {
        $this->expectException(VendorPaymentValidationException::class);
        app(VendorStatementQuery::class)->execute($this->vendor, $from, $to);
    }

    public static function locales(): array
    {
        return [['en'], ['ar']];
    }

    #[DataProvider('locales')]
    public function test_invalid_live_statement_has_no_summary_keeps_filters_and_recovers(string $locale): void
    {
        app()->setLocale($locale);
        $this->createAndPostPurchase();
        $page = Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])->set('activeTab', 'statement')
            ->assertViewHas('statementData', fn ($data) => $data['currencies']['ILS']['closing_balance'] === '100.00')
            ->set('statementFrom', '2026-10-07')->assertHasErrors('statementDates')
            ->assertViewHas('statementData', null)->assertSee(__('purchasing.statement_dates_invalid'))
            ->assertSee('id="statement-from"', false)->assertSee('id="statement-to"', false);
        $page->set('statementFrom', '2026-10-01')->assertHasNoErrors('statementDates')
            ->assertViewHas('statementData', fn ($data) => $data['from_date'] === '2026-10-01'
                && $data['to_date'] === '2026-10-06' && $data['currencies']['ILS']['aging']['signed_vendor_balance'] === '100.00');
    }

    public function test_equal_date_and_explicit_valid_filters_keep_exact_cutoff(): void
    {
        $this->createAndPostPurchase();
        $data = app(VendorStatementQuery::class)->execute($this->vendor, '2026-10-01', '2026-10-01');
        $this->assertSame('2026-10-01', $data['from_date']);
        $this->assertSame('2026-10-01', $data['to_date']);
        $this->assertSame('100.00', $data['currencies']['ILS']['closing_balance']);
        $this->assertSame('100.00', $data['currencies']['ILS']['aging']['signed_vendor_balance']);
    }

    private function assertHealthy(): void
    {
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }
}
