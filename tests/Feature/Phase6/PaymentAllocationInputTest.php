<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Livewire\Pages\Purchasing\PaymentDetail as VendorDetail;
use App\Livewire\Pages\Purchasing\PaymentForm as VendorForm;
use App\Livewire\Pages\Sales\PaymentDetail as CustomerDetail;
use App\Livewire\Pages\Sales\PaymentForm as CustomerForm;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\CustomerPaymentApplicationEvent;
use App\Models\PostingBatch;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Models\VendorPaymentApplicationEvent;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class PaymentAllocationInputTest extends Phase6TestCase
{
    public static function invalidFormInputs(): array
    {
        $cases = [];
        foreach (['customer', 'vendor'] as $party) {
            foreach (['allocated_amount', 'payment_currency_amount'] as $field) {
                foreach (['not-a-number', '1,2', '1.0000001'] as $value) {
                    $cases["$party/$field/$value"] = [$party, $field, $value];
                }
            }
        }

        return $cases;
    }

    public static function invalidApplicationInputs(): array
    {
        $cases = [];
        foreach (['customer', 'vendor'] as $party) {
            foreach (['not-a-number', '1,2', '1.0000001', '   '] as $value) {
                $cases["$party/$value"] = [$party, $value];
            }
        }

        return $cases;
    }

    public static function parties(): array
    {
        return [['customer'], ['vendor']];
    }

    #[DataProvider('invalidFormInputs')]
    public function test_new_payment_preview_and_submission_reject_invalid_allocations_without_writes(string $party, string $field, string $value): void
    {
        $target = $party === 'customer' ? $this->invoice() : $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $component = $party === 'customer' ? CustomerForm::class : VendorForm::class;
        $params = $party === 'customer' ? ['customer_id' => $target->customer_id] : ['vendor_id' => $target->vendor_id];
        $before = $this->financialCounts();
        $page = Livewire::test($component, $params)->set('money_account_id', $this->ilsCashAccount->id)
            ->set('amount', '360')->set('exchange_rate', '1')
            ->set('allocations.0.allocated_amount', '100')->set('allocations.0.payment_currency_amount', '360')
            ->set('allocations.0.'.$field, $value);

        foreach (['recalculateAllocations', 'save'] as $method) {
            $page->call($method)->assertStatus(200)->assertHasErrors(['allocations.0.'.$field => 'regex'])
                ->assertSee($page->errors()->first('allocations.0.'.$field));
            $this->assertSame($before, $this->financialCounts());
            $this->assertSame($value, $page->get('allocations')[0][$field]);
        }
    }

    #[DataProvider('invalidApplicationInputs')]
    public function test_later_credit_application_rejects_invalid_consumption_without_writes(string $party, string $value): void
    {
        [$target, $payment, $component] = $this->advance($party);
        $before = $this->financialCounts();
        $page = Livewire::test($component, ['publicId' => $payment->public_id])->call('openCreditForm')
            ->set('applicationDate', '2026-10-04')->set('creditAmounts.'.$target->id, '100')
            ->set('creditPaymentAmounts.'.$target->id, $value)->call('applyCredit')
            ->assertStatus(200)->assertSet('showCreditForm', true)->assertHasErrors(['creditPaymentAmounts.'.$target->id => trim($value) === '' ? 'filled' : 'regex']);
        $page->assertSee($page->errors()->first('creditPaymentAmounts.'.$target->id));
        $this->assertSame($before, $this->financialCounts());
        $this->assertSame($value, $page->get('creditPaymentAmounts')[$target->id]);
        $this->assertSame('360.000000', $payment->fresh()->unallocated_amount);
    }

    #[DataProvider('parties')]
    public function test_blank_and_negative_allocation_preview_behavior_is_preserved_and_valid_edits_can_post(string $party): void
    {
        $target = $party === 'customer' ? $this->invoice() : $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $component = $party === 'customer' ? CustomerForm::class : VendorForm::class;
        $params = $party === 'customer' ? ['customer_id' => $target->customer_id] : ['vendor_id' => $target->vendor_id];
        $page = Livewire::test($component, $params)->set('money_account_id', $this->ilsCashAccount->id)
            ->set('amount', '360')->set('exchange_rate', '1');
        foreach (['', '   ', '-1'] as $value) {
            $page->set('allocations.0.allocated_amount', $value)->set('allocations.0.payment_currency_amount', '   ')
                ->call('recalculateAllocations')->assertHasNoErrors()->assertSet('allocatedTotal', '0.00')->assertSet('unallocatedAmount', '360.00');
        }
        $page->set('allocations.0.allocated_amount', '100')->set('allocations.0.payment_currency_amount', 'bad')
            ->call('recalculateAllocations')->assertHasErrors('allocations.0.payment_currency_amount')
            ->set('allocations.0.payment_currency_amount', '360')->call('recalculateAllocations')->assertHasNoErrors()
            ->assertSet('unallocatedAmount', '0.00')->call('save')->assertHasNoErrors();
        $payment = $party === 'customer' ? CustomerPayment::sole() : VendorPayment::sole();
        $this->assertSame('360.000000', $payment->allocations->sole()->payment_currency_amount);
        $this->assertSame('100.000000', $payment->allocations->sole()->allocated_amount);
    }

    #[DataProvider('parties')]
    public function test_cross_currency_credit_consumption_is_required_before_calling_the_action(string $party): void
    {
        [$target, $payment, $component] = $this->advance($party);
        $before = $this->financialCounts();
        Livewire::test($component, ['publicId' => $payment->public_id])->call('openCreditForm')
            ->set('applicationDate', '2026-10-04')->set('creditAmounts.'.$target->id, '100')->call('applyCredit')
            ->assertStatus(200)->assertHasErrors(['creditPaymentAmounts.'.$target->id => 'required']);
        $this->assertSame($before, $this->financialCounts());
    }

    #[DataProvider('parties')]
    public function test_null_cleared_allocation_can_post_an_unallocated_payment(string $party): void
    {
        $target = $party === 'customer' ? $this->invoice() : $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $component = $party === 'customer' ? CustomerForm::class : VendorForm::class;
        $params = $party === 'customer' ? ['customer_id' => $target->customer_id] : ['vendor_id' => $target->vendor_id];
        Livewire::test($component, $params)->set('money_account_id', $this->ilsCashAccount->id)
            ->set('amount', '360')->set('exchange_rate', '1')->set('allocations.0.allocated_amount', null)
            ->call('recalculateAllocations')->assertHasNoErrors()->assertSet('unallocatedAmount', '360.00')
            ->call('save')->assertHasNoErrors();
        $payment = $party === 'customer' ? CustomerPayment::sole() : VendorPayment::sole();
        $this->assertSame('360.000000', $payment->unallocated_amount);
        $this->assertSame(0, $payment->allocations()->count());
    }

    #[DataProvider('parties')]
    public function test_valid_credit_consumption_can_be_submitted_after_validation_error(string $party): void
    {
        [$target, $payment, $component] = $this->advance($party);
        Livewire::test($component, ['publicId' => $payment->public_id])->call('openCreditForm')
            ->set('applicationDate', '2026-10-04')->set('creditAmounts.'.$target->id, '100')
            ->set('creditPaymentAmounts.'.$target->id, 'bad')->call('applyCredit')->assertHasErrors('creditPaymentAmounts.'.$target->id)
            ->set('creditPaymentAmounts.'.$target->id, '360')->call('applyCredit')->assertHasNoErrors()->assertSet('showCreditForm', false);
        $this->assertSame('0.000000', $payment->fresh()->unallocated_amount);
        $this->assertSame('360.000000', $payment->allocations()->sole()->payment_currency_amount);
        $this->assertSame('100.000000', $payment->allocations()->sole()->allocated_amount);
    }

    private function advance(string $party): array
    {
        $target = $party === 'customer' ? $this->invoice() : $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $data = ['money_account_id' => $this->ilsCashAccount->id, 'payment_date' => '2026-10-03', 'payment_method' => 'cash',
            'amount' => '360', 'exchange_rate' => '1', 'idempotency_key' => 'input-boundary-advance', 'allocations' => []];
        $payment = $party === 'customer'
            ? app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [...$data, 'customer_id' => $target->customer_id])
            : app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [...$data, 'vendor_id' => $target->vendor_id]);

        return [$target, $payment, $party === 'customer' ? CustomerDetail::class : VendorDetail::class];
    }

    private function financialCounts(): array
    {
        return [CustomerPayment::count(), VendorPayment::count(), PostingBatch::count(), CustomerPaymentAllocation::count(),
            VendorPaymentAllocation::count(), CustomerPaymentApplicationEvent::count(), VendorPaymentApplicationEvent::count()];
    }
}
