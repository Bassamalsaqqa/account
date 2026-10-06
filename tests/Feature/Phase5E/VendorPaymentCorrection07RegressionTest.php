<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Models\PostingBatch;
use App\Models\VendorPayment;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use App\Services\Sales\SalesReconciliationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class VendorPaymentCorrection07RegressionTest extends Phase5ETestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    private function freezeCompanyTime(string $utc, string $timezone): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        $this->company->update(['timezone' => $timezone]);
        $this->activate($this->owner);
    }

    private function payment(array $changes = []): VendorPayment
    {
        return app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, array_replace([
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01', 'payment_method' => 'cash',
            'amount' => '100.00', 'exchange_rate' => '1', 'idempotency_key' => 'c07-payment', 'allocations' => [],
        ], $changes));
    }

    public static function localDateBoundaries(): array
    {
        return [
            'ahead' => ['2026-10-06 22:30:00', 'Asia/Hebron', '2026-10-07'],
            'behind' => ['2026-10-07 01:30:00', 'Pacific/Honolulu', '2026-10-06'],
            'New Year' => ['2026-12-31 23:30:00', 'Asia/Tokyo', '2027-01-01'],
        ];
    }

    #[DataProvider('localDateBoundaries')]
    public function test_parent_reversal_date_matches_statement_and_absolute_timestamp_and_retry(string $utc, string $timezone, string $date): void
    {
        $this->freezeCompanyTime($utc, $timezone);
        $payment = $this->payment();
        $reversed = app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        $this->assertNotSame($date, Carbon::now()->toDateString());
        $this->assertSame($date, $reversed->reversalPostingBatch->posting_date->toDateString());
        $this->assertSame($utc, $reversed->reversed_at->format('Y-m-d H:i:s'));
        $this->assertSame($date, $reversed->reversed_at->copy()->setTimezone($timezone)->toDateString());
        $statement = app(VendorStatementQuery::class)->execute($this->vendor, null, $date)['currencies']['ILS'];
        $entry = collect($statement['entries'])->firstWhere('type', 'payment_reversal');
        $this->assertSame($date, $entry['date']);
        $this->assertSame('0.00', $statement['closing_balance']);
        $this->assertSame('0.00', $statement['aging']['signed_vendor_balance']);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($reversed);
        $batches = DB::table('posting_batches')->count();
        $sequences = DB::table('document_sequences')->pluck('next_number', 'id')->all();
        Carbon::setTestNow(Carbon::parse($utc, 'UTC')->addDays(2));
        $retry = app(ReverseVendorPaymentAction::class)->execute($reversed, $this->owner);
        $this->assertSame($reversed->reversal_posting_batch_id, $retry->reversal_posting_batch_id);
        $this->assertSame($date, $retry->reversalPostingBatch->posting_date->toDateString());
        $this->assertSame($utc, $retry->reversed_at->format('Y-m-d H:i:s'));
        $this->assertSame($batches, DB::table('posting_batches')->count());
        $this->assertSame($sequences, DB::table('document_sequences')->pluck('next_number', 'id')->all());
    }

    public function test_dependent_fx_applications_reverse_newest_first_on_one_local_date_and_all_domains_remain_healthy(): void
    {
        $this->freezeCompanyTime('2026-10-06 22:30:00', 'Asia/Hebron');
        $payment = $this->payment(['money_account_id' => $this->usdCashAccount->id, 'exchange_rate' => '3.60']);
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $events = [];
        foreach (['40.00', '30.00'] as $i => $amount) {
            $events[] = app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
                'application_date' => '2026-10-02', 'idempotency_key' => 'c07-apply-'.$i,
                'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => $amount]],
            ]);
        }
        $reversed = app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        foreach ($events as $event) {
            $event->refresh();
            $this->assertNotNull($event->reversal_posting_batch_id);
            $this->assertSame('2026-10-07', $event->reversalPostingBatch->posting_date->toDateString());
            $this->assertSame('2026-10-06 22:30:00', $event->reversed_at->format('Y-m-d H:i:s'));
        }
        $this->assertLessThan($events[0]->reversal_posting_batch_id, $events[1]->reversal_posting_batch_id);
        $this->assertLessThan($reversed->reversal_posting_batch_id, $events[0]->reversal_posting_batch_id);
        $this->assertSame('2026-10-07', $reversed->reversalPostingBatch->posting_date->toDateString());
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_zero_effect_application_has_no_reversal_batch(): void
    {
        $this->freezeCompanyTime('2026-12-31 23:30:00', 'Asia/Tokyo');
        $payment = $this->payment();
        $purchase = $this->createAndPostPurchase();
        $event = app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-02', 'idempotency_key' => 'c07-zero',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']],
        ]);
        $this->assertNull($event->posting_batch_id);
        $before = PostingBatch::count();
        $reversed = app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        $event->refresh();
        $this->assertNull($event->reversal_posting_batch_id);
        $this->assertNotNull($event->reversed_at);
        $this->assertSame($before + 1, PostingBatch::count());
        $this->assertSame('2027-01-01', $reversed->reversalPostingBatch->posting_date->toDateString());
        app(VendorPaymentPostedIntegrityValidator::class)->validate($reversed);
    }

    public function test_unspecified_shared_reversal_date_retains_existing_utc_default(): void
    {
        $this->freezeCompanyTime('2026-10-06 22:30:00', 'Asia/Hebron');
        $purchase = $this->createAndPostPurchase();
        $reversal = app(AccountingReversalService::class)->reverse($purchase->postingBatch, $this->owner);
        $this->assertSame('2026-10-06', $reversal->posting_date->toDateString());
        $this->assertSame('2026-10-07', Carbon::now($this->company->timezone)->toDateString());
    }

    public static function invalidDates(): array
    {
        return [['2026-02-30'], ['2026-1-01'], ['tomorrow'], ['2026-10-07T00:00:00Z']];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_explicit_date_cannot_create_a_reversal(string $date): void
    {
        $purchase = $this->createAndPostPurchase();
        $before = PostingBatch::count();
        try {
            app(AccountingReversalService::class)->reverse($purchase->postingBatch, $this->owner, null, $date);
            $this->fail('Invalid business date accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, PostingBatch::count());
            $this->assertSame('posted', $purchase->postingBatch->fresh()->status);
        }
    }

    public function test_tampered_parent_reversal_date_fails_history_retry_and_payables_reconciliation(): void
    {
        $this->freezeCompanyTime('2026-10-06 22:30:00', 'Asia/Hebron');
        $payment = $this->payment(['money_account_id' => $this->usdCashAccount->id, 'exchange_rate' => '3.60']);
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-02', 'idempotency_key' => 'c07-corrupt-parent',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']],
        ]);
        $payment = app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        DB::table('posting_batches')->where('id', $payment->reversal_posting_batch_id)->update(['posting_date' => '2026-10-06']);
        $this->assertFalse(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->expectException(ImmutableRecordException::class);
        app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
    }

    public function test_tampered_application_reversal_date_is_detected(): void
    {
        $this->freezeCompanyTime('2026-10-06 22:30:00', 'Asia/Hebron');
        $payment = $this->payment(['money_account_id' => $this->usdCashAccount->id, 'exchange_rate' => '3.60']);
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $event = app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-02', 'idempotency_key' => 'c07-corrupt-application',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']],
        ]);
        $payment = app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        $event->refresh();
        DB::table('posting_batches')->where('id', $event->reversal_posting_batch_id)->update(['posting_date' => '2026-10-06']);
        $this->assertFalse(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->expectException(ImmutableRecordException::class);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
    }

    public function test_clock_crossing_company_midnight_rolls_back_entire_reversal(): void
    {
        $this->freezeCompanyTime('2026-10-06 20:59:59', 'Asia/Hebron');
        $payment = $this->payment();
        $batches = PostingBatch::count();
        $audits = DB::table('audit_events')->count();
        $real = new AccountingReversalService(app(AccountingPostingService::class));
        $mock = \Mockery::mock(AccountingReversalService::class);
        $mock->shouldReceive('reverse')->once()->andReturnUsing(function ($batch, $actor, $reason, $date) use ($real) {
            $result = $real->reverse($batch, $actor, $reason, $date);
            Carbon::setTestNow(Carbon::parse('2026-10-06 22:30:00', 'UTC'));

            return $result;
        });
        $this->app->instance(AccountingReversalService::class, $mock);
        try {
            app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
            $this->fail('Inconsistent Company-local reversal date committed.');
        } catch (ImmutableRecordException) {
            $this->assertFalse($payment->fresh()->is_reversed);
            $this->assertNull($payment->fresh()->reversed_at);
            $this->assertSame('posted', $payment->postingBatch->fresh()->status);
            $this->assertSame($batches, PostingBatch::count());
            $this->assertSame($audits, DB::table('audit_events')->count());
        }
    }

    public function test_later_company_timezone_change_preserves_retry_statement_and_historical_aging(): void
    {
        $this->freezeCompanyTime('2026-10-06 22:30:00', 'Asia/Hebron');
        $purchase = $this->createAndPostPurchase();
        $intent = ['allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']]];
        $payment = app(ReverseVendorPaymentAction::class)->execute($this->payment($intent), $this->owner);
        $before = PostingBatch::count();
        $this->company->update(['timezone' => 'Pacific/Honolulu']);
        $this->activate($this->owner);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment->fresh());
        $this->assertSame($payment->id, $this->payment($intent)->id);
        $this->assertSame($payment->reversal_posting_batch_id, app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner)->reversal_posting_batch_id);
        $this->assertSame($before, PostingBatch::count());
        $earlier = app(VendorStatementQuery::class)->execute($this->vendor, null, '2026-10-06')['currencies']['ILS'];
        $later = app(VendorStatementQuery::class)->execute($this->vendor, null, '2026-10-07')['currencies']['ILS'];
        $this->assertSame('0.00', $earlier['closing_balance']);
        $this->assertSame('0.00', $earlier['aging']['gross_open_purchases']);
        $this->assertSame('100.00', $later['closing_balance']);
        $this->assertSame('100.00', $later['aging']['gross_open_purchases']);
        $entry = collect($later['entries'])->firstWhere('type', 'payment_reversal');
        $this->assertSame('2026-10-07', $entry['date']);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_shared_explicit_date_is_preserved_without_conversion_and_retry_keeps_original_date(): void
    {
        $this->freezeCompanyTime('2026-10-06 22:30:00', 'Asia/Hebron');
        $purchase = $this->createAndPostPurchase();
        $service = app(AccountingReversalService::class);
        $reversal = $service->reverse($purchase->postingBatch, $this->owner, null, '2026-10-04');
        $this->assertSame('2026-10-04', $reversal->posting_date->toDateString());
        $before = PostingBatch::count();
        Carbon::setTestNow(Carbon::parse('2026-10-08 22:30:00', 'UTC'));
        $retry = $service->reverse($purchase->postingBatch->fresh(), $this->owner, null, '2026-10-09');
        $this->assertSame($reversal->id, $retry->id);
        $this->assertSame('2026-10-04', $retry->posting_date->toDateString());
        $this->assertSame($before, PostingBatch::count());
    }
}
