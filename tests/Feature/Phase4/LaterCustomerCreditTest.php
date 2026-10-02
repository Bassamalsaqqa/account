<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Sales\Queries\CustomerBalanceQuery;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\CustomerPaymentApplicationEvent;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class LaterCustomerCreditTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    /** @param list<array{sales_invoice_id: int, allocated_amount: string}> $allocations */
    private function apply(CustomerPayment $payment, array $allocations, string $key = 'application'): CustomerPaymentApplicationEvent
    {
        return app(ApplyCustomerPaymentCreditAction::class)->execute($payment, $this->owner, ['application_date' => '2026-10-02', 'idempotency_key' => $key, 'allocations' => $allocations]);
    }

    public static function fxRates(): array
    {
        return [['3.60', '3.50', 'fx_gain', '10.000000'], ['3.50', '3.60', 'fx_loss', '-10.000000'], ['3.50', '3.50', null, '0.000000']];
    }

    #[DataProvider('fxRates')]
    public function test_later_credit_settles_and_reverses_exactly(string $receiptRate, string $invoiceRate, ?string $fxAccount, string $delta): void
    {
        $payment = $this->receipt(rate: $receiptRate);
        $paymentSnapshot = $payment->getAttributes();
        $invoice = $this->invoice(rate: $invoiceRate);
        $event = $this->apply($payment, [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '100']]);
        $this->assertSame($paymentSnapshot, $payment->fresh()->getAttributes());
        $this->assertSame('0.000000', (string) $invoice->calculateOutstanding()->toScale(6));
        $this->assertSame('0.000000', $payment->fresh()->unallocated_amount);
        $this->assertSame('0.000000', $payment->fresh()->unallocated_amount_base);
        $this->assertSame($delta, $event->allocations->first()->realized_fx_gain_loss_base);
        $this->assertSame('0.000000', app(CustomerBalanceQuery::class)->execute([$this->customer->id])[$this->customer->id]['USD']['outstanding']);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy,
            implode(';', app(SalesReconciliationService::class)->reconcile($this->company)->violations));
        $ar = DB::table('posting_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.company_id', $this->company->id)->where('a.system_key', 'accounts_receivable')->selectRaw('SUM(l.debit_base - l.credit_base) as balance')->value('balance');
        $this->assertSame('0.000000', $ar);
        if ($fxAccount === null) {
            $this->assertNull($event->posting_batch_id);
        } else {
            $this->assertNotNull($event->posting_batch_id);
            $this->assertSame(2, DB::table('posting_lines')->where('posting_batch_id', $event->posting_batch_id)->count());
            $this->assertFalse(DB::table('posting_lines')->where('posting_batch_id', $event->posting_batch_id)->where('ledger_account_id', $this->cash->ledger_account_id)->exists());
        }
        $allocations = CustomerPaymentAllocation::all()->map->getAttributes()->all();
        $reversed = app(ReverseCustomerPaymentAction::class)->execute($payment, $this->owner, 'Test reversal');
        $this->assertTrue($reversed->is_reversed);
        $this->assertNotNull($event->fresh()->reversed_at);
        $this->assertSame('100.000000', (string) $invoice->calculateOutstanding()->toScale(6));
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertSame($allocations, CustomerPaymentAllocation::all()->map->getAttributes()->all());
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy,
            implode(';', app(SalesReconciliationService::class)->reconcile($this->company)->violations));
        $count = PostingBatch::count();
        app(ReverseCustomerPaymentAction::class)->execute($payment, $this->owner, 'Test reversal');
        $this->assertSame($count, PostingBatch::count());
    }

    public function test_partial_multi_invoice_and_older_receipt_with_initial_and_later_allocations(): void
    {
        $first = $this->invoice('30.01', '3.3333333333');
        $payment = $this->receipt('100', '3.6666666667', [['sales_invoice_id' => $first->id, 'allocated_amount' => '10']]);
        $new = $this->invoice('69.99', '3.3333333333');
        $this->apply($payment, [['sales_invoice_id' => $first->id, 'allocated_amount' => '20.01']], 'partial');
        $this->assertSame('69.990000', $payment->fresh()->unallocated_amount);
        $event = $this->apply($payment, [['sales_invoice_id' => $new->id, 'allocated_amount' => '69.99']], 'final');
        $this->assertSame('0.000000', $payment->fresh()->unallocated_amount_base);
        $this->assertSame('0.000000', (string) $new->calculateOutstanding()->toScale(6));
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy,
            implode(';', app(SalesReconciliationService::class)->reconcile($this->company)->violations));
        $same = $this->apply($payment, [['sales_invoice_id' => $new->id, 'allocated_amount' => '69.990000']], 'final');
        $this->assertSame($event->id, $same->id);
        $this->expectException(IdempotencyConflictException::class);
        $this->apply($payment, [['sales_invoice_id' => $new->id, 'allocated_amount' => '1']], 'final');
    }

    public static function rejectionCases(): array
    {
        return array_map(fn ($n) => [$n], ['credit', 'outstanding', 'customer', 'currency', 'reversed', 'membership', 'permission', 'actor', 'company', 'precision', 'foreign_invoice']);
    }

    #[DataProvider('rejectionCases')]
    public function test_failed_application_has_zero_writes(string $case): void
    {
        $payment = $this->receipt('50');
        $invoice = $this->invoice('100');
        $amount = $case === 'credit' ? '51' : '10';
        if ($case === 'precision') {
            $amount = '0.001';
        }
        if ($case === 'foreign_invoice') {
            app(CompanyContext::class)->clear();
            $foreign = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Foreign company', 'base_currency_code' => 'ILS']);
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            DB::table('sales_invoices')->where('id', $invoice->id)->update(['company_id' => $foreign->id]);
        }
        if ($case === 'outstanding') {
            $invoice = $this->invoice('5');
        }
        if ($case === 'customer') {
            $other = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'Other', 'created_by' => $this->owner->id]);
            $invoice = $this->invoice(extra: ['customer_id' => $other->id]);
        }
        if ($case === 'currency') {
            $invoice = $this->invoice(currency: 'ILS', rate: '1');
        }
        if ($case === 'reversed') {
            app(ReverseCustomerPaymentAction::class)->execute($payment, $this->owner);
        }
        if ($case === 'membership') {
            DB::table('company_user')->where('company_id', $this->company->id)->update(['status' => 'inactive']);
        }
        if ($case === 'permission') {
            $this->owner->roles->first()->revokePermissionTo('money.receipt.allocate');
        }
        if ($case === 'actor') {
            $this->actingAs(User::factory()->create());
        }
        if ($case === 'company') {
            app(CompanyContext::class)->clear();
        }
        $counts = [PostingBatch::withoutGlobalScopes()->count(), CustomerPaymentAllocation::withoutGlobalScopes()->count(), CustomerPaymentApplicationEvent::withoutGlobalScopes()->count()];
        try {
            $this->apply($payment, [['sales_invoice_id' => $invoice->id, 'allocated_amount' => $amount]]);
            $this->fail('Invalid credit application succeeded.');
        } catch (\InvalidArgumentException|AuthorizationException $expected) {
            $this->assertSame($counts, [PostingBatch::withoutGlobalScopes()->count(), CustomerPaymentAllocation::withoutGlobalScopes()->count(), CustomerPaymentApplicationEvent::withoutGlobalScopes()->count()]);
        }
    }

    public function test_one_application_can_settle_multiple_invoices_and_normalizes_duplicate_intent(): void
    {
        $payment = $this->receipt();
        $first = $this->invoice('40');
        $second = $this->invoice('60');
        $event = $this->apply($payment, [
            ['sales_invoice_id' => $second->id, 'allocated_amount' => '60'],
            ['sales_invoice_id' => $first->id, 'allocated_amount' => '10'],
            ['sales_invoice_id' => $first->id, 'allocated_amount' => '30'],
        ]);
        $this->assertCount(2, $event->allocations);
        $this->assertTrue($first->calculateOutstanding()->isZero());
        $this->assertTrue($second->calculateOutstanding()->isZero());
        $this->assertSame('0.000000', $payment->fresh()->unallocated_amount_base);
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_receipt_reversal_rolls_back_every_application_when_history_is_inconsistent(): void
    {
        $payment = $this->receipt();
        $first = $this->invoice('40');
        $second = $this->invoice('60');
        $earlier = $this->apply($payment, [['sales_invoice_id' => $first->id, 'allocated_amount' => '40']], 'earlier');
        $later = $this->apply($payment, [['sales_invoice_id' => $second->id, 'allocated_amount' => '60']], 'later');
        DB::table('customer_payment_application_events')->where('id', $earlier->id)->update(['applied_at' => null]);
        $batches = PostingBatch::count();
        try {
            app(ReverseCustomerPaymentAction::class)->execute($payment, $this->owner);
            $this->fail('Inconsistent application history allowed reversal.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame($batches, PostingBatch::count());
            $this->assertFalse($payment->fresh()->is_reversed);
            $this->assertNull($later->fresh()->reversed_at);
        }
    }

    public function test_return_after_credit_settlement_can_create_customer_credit_without_false_reconciliation_corruption(): void
    {
        $payment = $this->receipt();
        $invoice = $this->invoice();
        $this->apply($payment, [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '100']]);
        $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, ['sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-02', 'lines' => [['sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '1']]]);
        app(PostSalesReturnAction::class)->execute($return, $this->owner);
        $report = app(SalesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, implode(';', $report->violations));
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertSame('-100.000000', app(CustomerBalanceQuery::class)->execute([$this->customer->id])[$this->customer->id]['USD']['outstanding']);
    }

    public function test_direct_append_to_existing_application_and_mutations_are_rejected(): void
    {
        $payment = $this->receipt();
        $invoice = $this->invoice();
        $event = $this->apply($payment, [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '10']]);
        foreach ([fn () => $event->update(['request_hash' => str_repeat('0', 64)]), fn () => $event->delete(),
            fn () => CustomerPaymentAllocation::create(['company_id' => $this->company->id, 'customer_payment_id' => $payment->id, 'sales_invoice_id' => $invoice->id, 'application_event_id' => $event->id, 'allocated_amount' => '1'])] as $mutation) {
            try {
                $mutation();
                $this->fail('History mutation succeeded.');
            } catch (\InvalidArgumentException|ImmutableRecordException $expected) {
                $this->assertTrue(true);
            }
        }
    }
}
