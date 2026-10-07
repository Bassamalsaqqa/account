<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Livewire\Pages\Money\AccountDetail;
use App\Livewire\Pages\Money\CheckDetail;
use App\Livewire\Pages\Money\CheckForm;
use App\Livewire\Pages\Money\CheckIndex;
use App\Livewire\Pages\Money\Overview;
use App\Livewire\Pages\Money\TransferDetail;
use App\Livewire\Pages\Money\TransferForm;
use App\Livewire\Pages\Money\TransferIndex;
use App\Livewire\Pages\Purchasing\PaymentForm as VendorForm;
use App\Livewire\Pages\Sales\PaymentForm as CustomerForm;
use App\Models\Check;
use App\Models\CompanyCurrency;
use App\Models\CustomerPayment;
use App\Models\PostingBatch;
use App\Models\VendorPayment;
use App\Services\Money\MoneyReconciliationService;
use Carbon\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;

class MoneyUiTest extends Phase6TestCase
{
    public static function pages(): array
    {
        return [[Overview::class, []], [Overview::class, ['type' => 'cash']], [Overview::class, ['type' => 'bank']],
            [TransferIndex::class, []], [TransferForm::class, []], [CheckIndex::class, []],
            [CheckForm::class, ['direction' => 'incoming']], [CheckForm::class, ['direction' => 'outgoing']]];
    }

    #[DataProvider('pages')]
    public function test_money_pages_render_for_authorized_operator_without_financial_side_effects(string $component, array $params): void
    {
        $before = PostingBatch::count();
        Livewire::test($component, $params)->assertStatus(200);
        $this->assertSame($before, PostingBatch::count());
        $this->assertSame(0, Check::count());
    }

    public function test_historical_account_and_check_detail_render(): void
    {
        $check = $this->check();
        Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->assertSee($check->check_number)->assertStatus(200);
        Livewire::test(AccountDetail::class, ['publicId' => $this->usdBankAccount->public_id])->assertSee($this->usdBankAccount->displayName())->assertStatus(200);
    }

    public function test_vendor_payment_form_lists_cross_currency_documents_and_posts_two_explicit_amounts(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $page = Livewire::test(VendorForm::class, ['vendor_id' => $this->vendor->id]);
        $page->set('money_account_id', $this->ilsCashAccount->id)->set('amount', '360')->set('exchange_rate', '1')
            ->assertSee($purchase->purchase_number)->assertSee('USD')
            ->set('allocations.0.allocated_amount', '100')->set('allocations.0.payment_currency_amount', '360')
            ->call('recalculateAllocations')->assertSet('unallocatedAmount', '0.00')->call('save')->assertHasNoErrors();
        $payment = VendorPayment::sole();
        $this->assertSame('360.000000', $payment->allocations->sole()->payment_currency_amount);
        $this->assertSame('100.000000', $payment->allocations->sole()->allocated_amount);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_customer_receipt_form_lists_cross_currency_documents(): void
    {
        $invoice = $this->invoice();
        Livewire::test(CustomerForm::class, ['customer_id' => $invoice->customer_id])->set('money_account_id', $this->ilsCashAccount->id)
            ->set('amount', '330')->set('exchange_rate', '1')->assertSee($invoice->invoice_number)
            ->set('allocations.0.allocated_amount', '100')->set('allocations.0.payment_currency_amount', '330')
            ->call('recalculateAllocations')->assertSet('unallocatedAmount', '0.00')->call('save')->assertHasNoErrors();
        $this->assertSame('330.000000', CustomerPayment::sole()->allocations->sole()->payment_currency_amount);
    }

    public function test_stale_money_page_permission_revocation_denies_next_request(): void
    {
        $page = Livewire::test(TransferIndex::class);
        $role = $this->owner->roles()->firstOrFail();
        $role->revokePermissionTo('money.transfer.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $page->call('$refresh')->assertForbidden();
    }

    public function test_check_viewer_without_cost_never_receives_outgoing_check_data(): void
    {
        $incoming = $this->check();
        $outgoing = $this->check('outgoing');
        $role = $this->owner->roles()->firstOrFail();
        $role->revokePermissionTo('purchasing.cost.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Livewire::test(CheckIndex::class)->assertSee($incoming->check_number)->assertDontSee($outgoing->check_number);
        Livewire::test(CheckDetail::class, ['publicId' => $outgoing->public_id])->assertForbidden();
    }

    public function test_disabled_currency_accounts_are_not_offered_in_new_money_forms(): void
    {
        CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['enabled' => false]);
        Livewire::test(TransferForm::class)->assertDontSee($this->usdBankAccount->displayName())->assertDontSee($this->usdCashAccount->displayName());
        Livewire::test(CheckForm::class, ['direction' => 'outgoing'])->assertDontSee($this->usdBankAccount->displayName());
    }

    public function test_partially_edited_blank_check_allocation_does_not_crash_submission(): void
    {
        $invoice = $this->invoice();
        Livewire::test(CheckForm::class, ['direction' => 'incoming'])->set('partyId', $invoice->customer_id)
            ->set('number', 'PARTIAL-ROW')->set('bankName', 'Bank')->set('amount', '1')->set('rate', '1')
            ->set('allocations', [$invoice->id => ['document' => '']])->call('save')->assertHasNoErrors();
        $this->assertSame(1, Check::count());
        $this->assertSame(0, CustomerPayment::sole()->allocations()->count());
    }

    public function test_future_transfer_reversal_reports_validation_without_changing_history(): void
    {
        $transfer = app(PostMoneyTransferAction::class)->execute($this->company, $this->owner,
            ['from_money_account_id' => $this->usdCashAccount->id, 'to_money_account_id' => $this->usdBankAccount->id,
                'transfer_date' => '2099-01-01', 'from_amount' => '1', 'to_amount' => '1',
                'from_exchange_rate' => '3.5', 'to_exchange_rate' => '3.5', 'idempotency_key' => 'future-transfer']);
        $count = PostingBatch::count();
        Livewire::test(TransferDetail::class, ['publicId' => $transfer->public_id])->call('reverse')->assertHasErrors('transfer');
        $this->assertFalse($transfer->fresh()->is_reversed);
        $this->assertSame($count, PostingBatch::count());
    }

    public function test_due_register_uses_company_today_and_excludes_future_and_terminal_checks(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 10:00:00', $this->company->timezone));
        try {
            $due = $this->check();
            $future = app(ReceiveCheckAction::class)->execute($this->company, $this->owner,
                array_replace($this->checkIntent(), ['due_date' => '2026-10-04']));
            $cancelled = $this->check();
            $this->event($cancelled, 'cancel');
            Livewire::test(CheckIndex::class)->set('status', 'due')->assertSee($due->check_number)
                ->assertDontSee($future->check_number)->assertDontSee($cancelled->check_number);
        } finally {
            $this->travelBack();
        }
    }
}
