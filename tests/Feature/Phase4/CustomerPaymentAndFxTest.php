<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Livewire\Pages\Sales\PaymentForm;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerPaymentAndFxTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Customer $customer;

    protected MoneyAccount $ilsCashAccount;

    protected MoneyAccount $usdCashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->company = $creator->execute($this->user, [
            'name_ar' => 'شركة المقبوضات والصرف',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->actingAs($this->user);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل المقبوضات الأجنبية',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $moneyAccountAction = app(CreateMoneyAccountAction::class);

        // ILS Cash Account
        $this->ilsCashAccount = $moneyAccountAction->execute($this->company, $this->user, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق الشيكل الرئيسي',
            'name_en' => 'Main ILS Cash Box',
        ]);

        // USD Cash Account
        $this->usdCashAccount = $moneyAccountAction->execute($this->company, $this->user, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'USD',
            'name_ar' => 'صندوق الدولار',
            'name_en' => 'USD Cash Box',
        ]);
    }

    private function createAndPostInvoice(string $currency, string $exchangeRate, string $amount): SalesInvoice
    {
        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $postAction = app(PostSalesInvoiceAction::class);

        $invoice = $draftAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'currency_code' => $currency,
            'exchange_rate' => $exchangeRate,
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'خدمات استشارية',
                    'quantity' => '1.000000',
                    'unit_price' => $amount,
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        return $postAction->execute($invoice, $this->user);
    }

    public static function receiptFormLocales(): array
    {
        return [
            'Arabic' => ['ar', 'رقم المرجع / مرجع التحويل', 'مثال: TR-998822', 'توزيع تلقائي'],
            'English' => ['en', 'Reference / Transfer Reference #', 'e.g. TR-998822', 'Auto Allocate'],
        ];
    }

    #[DataProvider('receiptFormLocales')]
    public function test_receipt_form_localizes_reference_and_auto_allocation_controls(
        string $locale,
        string $referenceLabel,
        string $referencePlaceholder,
        string $autoAllocationLabel,
    ): void {
        $invoice = $this->createAndPostInvoice('ILS', '1.0000000000', '100.000000');
        $this->assertSame(SalesInvoice::STATUS_POSTED, $invoice->status);
        $this->assertTrue($invoice->calculateOutstanding()->isPositive());

        app()->setLocale($locale);

        $component = Livewire::test(PaymentForm::class, ['customer_id' => $this->customer->id]);
        $allocations = $component->get('allocations');
        $this->assertCount(1, $allocations);
        $this->assertSame($invoice->id, $allocations[0]['sales_invoice_id']);

        $component
            ->assertSee($invoice->invoice_number)
            ->assertSeeHtml('wire:click="autoAllocate"')
            ->assertSee($referenceLabel)
            ->assertSeeHtml('placeholder="'.$referencePlaceholder.'"')
            ->assertSee($autoAllocationLabel)
            ->assertDontSee('Reference / Cheque / Transfer Slip #')
            ->assertSet('document_locale', $this->company->default_locale);

        if ($locale === 'ar') {
            $component
                ->assertDontSee('Reference / Transfer Reference #')
                ->assertDontSee('e.g. TR-998822')
                ->assertDontSee('Auto Allocate');
        }
    }

    public function test_check_method_is_strictly_prohibited(): void
    {
        $action = app(PostCustomerPaymentAction::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Checks are strictly prohibited');

        $action->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'receipt-'.__FUNCTION__,
            'payment_method' => 'check',
            'amount' => '100.000000',
            'exchange_rate' => '1.0000000000',
        ]);
    }

    public function test_cross_currency_allocation_is_rejected(): void
    {
        $usdInvoice = $this->createAndPostInvoice('USD', '3.5000000000', '100.000000');

        $action = app(PostCustomerPaymentAction::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cross-currency allocation is not supported');

        // Trying to allocate ILS payment to USD invoice
        $action->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'receipt-'.__FUNCTION__,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '350.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                [
                    'sales_invoice_id' => $usdInvoice->id,
                    'allocated_amount' => '100.000000',
                ],
            ],
        ]);
    }

    public function test_same_currency_foreign_settlement_with_realized_fx_gain(): void
    {
        // 100 USD invoice @ 3.50 = 350 ILS AR
        $invoice = $this->createAndPostInvoice('USD', '3.5000000000', '100.000000');
        $this->assertSame('100.000000', (string) $invoice->calculateOutstanding());

        // Customer pays 100 USD @ 3.60 = 360 ILS Cash. Realized FX Gain = 10 ILS.
        $action = app(PostCustomerPaymentAction::class);
        $payment = $action->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->usdCashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'receipt-'.__FUNCTION__,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '100.000000',
            'exchange_rate' => '3.6000000000',
            'reference_number' => 'USD-RCP-001',
            'allocations' => [
                [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '100.000000',
                ],
            ],
        ]);

        $this->assertNotNull($payment->payment_number);
        $this->assertFalse($payment->is_reversed);
        $this->assertSame('100.000000', $payment->amount);
        $this->assertSame('360.000000', $payment->amount_base);

        // Verify allocation details
        $alloc = CustomerPaymentAllocation::where('customer_payment_id', $payment->id)->firstOrFail();
        $this->assertSame('100.000000', $alloc->allocated_amount);
        $this->assertSame('350.000000', $alloc->base_amount_applied_to_receivable);
        $this->assertSame('360.000000', $alloc->settlement_base_value);
        $this->assertSame('10.000000', $alloc->realized_fx_gain_loss_base);

        // Invoice is now fully settled
        $invoice->refresh();
        $this->assertSame('0.000000', (string) $invoice->calculateOutstanding());
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_PAID, $invoice->derivedPaymentStatus());

        // Verify balanced GL batch
        $batch = PostingBatch::with('lines.account')->findOrFail($payment->posting_batch_id);
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);

        $totalDr = '0.000000';
        $totalCr = '0.000000';
        foreach ($batch->lines as $glLine) {
            $totalDr = bcadd($totalDr, (string) $glLine->debit_base, 6);
            $totalCr = bcadd($totalCr, (string) $glLine->credit_base, 6);
        }

        // Dr Cash (360) = 360
        // Cr AR (350) + Cr FX Gain (10) = 360
        $this->assertSame('360.000000', $totalDr);
        $this->assertSame('360.000000', $totalCr);

        $fxGainLine = $batch->lines->firstWhere('account.system_key', 'fx_gain');
        $this->assertNotNull($fxGainLine);
        $this->assertSame('10.000000', (string) $fxGainLine->credit_base);
    }

    public function test_same_currency_foreign_settlement_with_realized_fx_loss(): void
    {
        // 100 USD invoice @ 3.50 = 350 ILS AR
        $invoice = $this->createAndPostInvoice('USD', '3.5000000000', '100.000000');

        // Customer pays 100 USD @ 3.40 = 340 ILS Cash. Realized FX Loss = 10 ILS.
        $action = app(PostCustomerPaymentAction::class);
        $payment = $action->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->usdCashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'receipt-'.__FUNCTION__,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '100.000000',
            'exchange_rate' => '3.4000000000',
            'allocations' => [
                [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '100.000000',
                ],
            ],
        ]);

        $alloc = CustomerPaymentAllocation::where('customer_payment_id', $payment->id)->firstOrFail();
        $this->assertSame('-10.000000', $alloc->realized_fx_gain_loss_base);

        // Verify balanced GL batch
        $batch = PostingBatch::with('lines.account')->findOrFail($payment->posting_batch_id);
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);

        $totalDr = '0.000000';
        $totalCr = '0.000000';
        foreach ($batch->lines as $glLine) {
            $totalDr = bcadd($totalDr, (string) $glLine->debit_base, 6);
            $totalCr = bcadd($totalCr, (string) $glLine->credit_base, 6);
        }

        // Dr Cash (340) + Dr FX Loss (10) = 350
        // Cr AR (350) = 350
        $this->assertSame('350.000000', $totalDr);
        $this->assertSame('350.000000', $totalCr);

        $fxLossLine = $batch->lines->firstWhere('account.system_key', 'fx_loss');
        $this->assertNotNull($fxLossLine);
        $this->assertSame('10.000000', (string) $fxLossLine->debit_base);
    }

    public function test_unallocated_receipt_amount_posts_to_ar_advance_without_corruption(): void
    {
        $invoice = $this->createAndPostInvoice('ILS', '1.0000000000', '300.000000');

        // Customer pays 500 ILS: 300 allocated, 200 unallocated advance
        $action = app(PostCustomerPaymentAction::class);
        $payment = $action->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'receipt-'.__FUNCTION__,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '500.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '300.000000',
                ],
            ],
        ]);

        $this->assertSame('200.000000', $payment->unallocated_amount);
        $this->assertSame('200.000000', $payment->unallocated_amount_base);

        // Balanced GL Batch: Dr Cash 500, Cr AR Relief 300, Cr AR Advance 200
        $batch = PostingBatch::with('lines.account')->findOrFail($payment->posting_batch_id);
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);

        $totalDr = '0.000000';
        $totalCr = '0.000000';
        foreach ($batch->lines as $glLine) {
            $totalDr = bcadd($totalDr, (string) $glLine->debit_base, 6);
            $totalCr = bcadd($totalCr, (string) $glLine->credit_base, 6);
        }

        $this->assertSame('500.000000', $totalDr);
        $this->assertSame('500.000000', $totalCr);

        // Invoice outstanding is 0
        $this->assertSame('0.000000', (string) $invoice->calculateOutstanding());
    }

    public function test_payment_reversal_restores_invoice_outstanding_and_reverses_gl(): void
    {
        $invoice = $this->createAndPostInvoice('ILS', '1.0000000000', '250.000000');

        $action = app(PostCustomerPaymentAction::class);
        $payment = $action->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'receipt-'.__FUNCTION__,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '250.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '250.000000',
                ],
            ],
        ]);

        $this->assertSame('0.000000', (string) $invoice->calculateOutstanding());

        // Reverse the payment
        $reverseAction = app(ReverseCustomerPaymentAction::class);
        $reversedPayment = $reverseAction->execute($payment, $this->user, 'خطأ في التحصيل');

        $this->assertTrue($reversedPayment->is_reversed);
        $this->assertNotNull($reversedPayment->reversed_at);
        $this->assertSame($this->user->id, $reversedPayment->reversed_by);

        // Outstanding restored to 250 ILS
        $this->assertSame('250.000000', (string) $invoice->calculateOutstanding());
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_UNPAID, $invoice->derivedPaymentStatus());

        // GL batch marked reversed
        $originalBatch = PostingBatch::findOrFail($payment->posting_batch_id);
        $this->assertSame(PostingBatch::STATUS_REVERSED, $originalBatch->status);
    }
}
