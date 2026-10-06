<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Livewire\Pages\Purchasing\PaymentDetail;
use App\Livewire\Pages\Purchasing\PaymentForm;
use App\Livewire\Pages\Purchasing\PaymentIndex;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Livewire\Pages\Purchasing\PurchaseIndex;
use App\Livewire\Pages\Purchasing\VendorDetail;
use App\Models\MoneyAccount;
use Livewire\Livewire;

class VendorPaymentUiTest extends Phase5ETestCase
{
    public function test_vendor_payments_index_renders_and_filters_for_authorized_user(): void
    {
        $this->activate($this->owner);

        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '60.00',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'ui-test-pay-1',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '60.00'],
            ],
        ]);

        Livewire::test(PaymentIndex::class)
            ->assertOk()
            ->assertSee($payment->payment_number)
            ->assertSee($this->vendor->name_ar)
            ->assertSee('60.00')
            ->set('search', 'NONEXISTENT')
            ->assertDontSee($payment->payment_number)
            ->set('search', $payment->payment_number)
            ->assertSee($payment->payment_number)
            ->set('vendorFilter', $this->vendor->id)
            ->assertSee($payment->payment_number)
            ->set('accountFilter', $this->ilsCashAccount->id)
            ->assertSee($payment->payment_number);
    }

    public function test_vendor_payments_index_refuses_access_without_cost_view(): void
    {
        $noCostUser = $this->customActor([
            'vendors.view',
            'money.vendor_payment.create',
        ]);

        $this->activate($noCostUser);

        Livewire::actingAs($noCostUser)
            ->test(PaymentIndex::class)
            ->assertForbidden();
    }

    public function test_vendor_payments_index_refuses_access_without_payment_permissions(): void
    {
        $costOnlyUser = $this->customActor([
            'vendors.view',
            'purchasing.cost.view',
        ]);

        $this->activate($costOnlyUser);

        Livewire::actingAs($costOnlyUser)
            ->test(PaymentIndex::class)
            ->assertForbidden();
    }

    public function test_vendor_payments_create_form_mounts_with_preselected_purchase(): void
    {
        $this->activate($this->owner);

        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '15'],
            ],
        ]);

        $test = Livewire::test(PaymentForm::class, ['purchase_id' => $purchase->id])
            ->assertOk()
            ->assertSet('vendor_id', $this->vendor->id)
            ->assertSet('currency_code', 'ILS')
            ->assertSet('amount', '150.000000')
            ->assertSet('allocatedTotal', '150.00')
            ->assertSet('unallocatedAmount', '0.00');

        $this->assertNotNull($test->get('money_account_id'));

        $test->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('vendor_payments', [
            'company_id' => $this->company->id,
            'vendor_id' => $this->vendor->id,
            'amount' => '150.000000',
        ]);
    }

    public function test_vendor_payments_create_auto_allocate_advisory(): void
    {
        $this->activate($this->owner);

        $p1 = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-01',
            'due_date' => '2026-10-10',
            'currency_code' => 'ILS',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10']],
        ]);

        $p2 = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-02',
            'due_date' => '2026-10-05',
            'currency_code' => 'ILS',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10']],
        ]);

        Livewire::test(PaymentForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('money_account_id', $this->ilsCashAccount->id)
            ->set('amount', '70.00')
            ->call('autoAllocate')
            ->assertHasNoErrors()
            ->assertSet('allocatedTotal', '70.00')
            ->assertSet('unallocatedAmount', '0.00');
    }

    public function test_vendor_payments_create_account_change_reloads_purchases_without_clearing_amount(): void
    {
        $this->activate($this->owner);

        $purchaseUsd = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.6000000000',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10']],
        ]);

        Livewire::test(PaymentForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('money_account_id', $this->ilsCashAccount->id)
            ->set('amount', '50.00')
            ->set('money_account_id', $this->usdCashAccount->id)
            ->assertSet('currency_code', 'USD')
            ->assertSet('amount', '50.00')
            ->assertSee($purchaseUsd->purchase_number);
    }

    public function test_vendor_payments_detail_renders_and_reverses(): void
    {
        $this->activate($this->owner);

        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10']],
        ]);

        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'ui-detail-rev-1',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])
            ->assertOk()
            ->assertSee($payment->payment_number)
            ->assertSee('100.00')
            ->set('showReverseModal', true)
            ->set('reversalReason', 'Wrong vendor invoice selected')
            ->call('reversePayment')
            ->assertHasNoErrors();

        $this->assertTrue($payment->fresh()->is_reversed);
    }

    public function test_vendor_payments_detail_applies_advance(): void
    {
        $this->activate($this->owner);

        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'ui-advance-1',
            'allocations' => [],
        ]);

        $purchase = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-02',
            'currency_code' => 'ILS',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10']],
        ]);

        Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])
            ->assertOk()
            ->assertSee('100.00')
            ->call('openCreditForm')
            ->assertSet('showCreditForm', true)
            ->set('creditAmounts.'.$purchase->id, '50.00')
            ->call('applyCredit')
            ->assertHasNoErrors();

        $position = $purchase->payablePosition();
        $this->assertEquals('50.000000', (string) $position->activeAllocatedAmount);
        $this->assertEquals('0.000000', (string) $position->outstanding);
        $this->assertEquals('settled', $position->status);
    }

    public function test_purchase_detail_displays_payable_position_and_pay_vendor_cta(): void
    {
        $this->activate($this->owner);

        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10']],
        ]);

        // 1. Owner with cost view sees payable position and Pay Vendor CTA
        Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertOk()
            ->assertSee(__('purchasing.pay_vendor'))
            ->assertSee(__('purchasing.unpaid'))
            ->assertSee('100.00');

        // 2. User without purchasing.cost.view does NOT see cost or Pay Vendor CTA
        $noCostUser = $this->customActor([
            'purchasing.purchase.view',
            'money.vendor_payment.create',
        ]);
        $this->activate($noCostUser);

        Livewire::actingAs($noCostUser)
            ->test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertOk()
            ->assertDontSee(__('purchasing.pay_vendor'))
            ->assertDontSee('100.00');
    }

    public function test_purchase_index_displays_payable_positions_and_masks_for_restricted_user(): void
    {
        $this->activate($this->owner);

        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10']],
        ]);

        // Owner sees grand total and status
        Livewire::test(PurchaseIndex::class)
            ->assertOk()
            ->assertSee('100.00')
            ->assertSee(__('purchasing.unpaid'));

        // Restricted user does not see grand totals or payable status badges
        $noCostUser = $this->customActor(['purchasing.purchase.view']);
        $this->activate($noCostUser);

        Livewire::actingAs($noCostUser)
            ->test(PurchaseIndex::class)
            ->assertOk()
            ->assertDontSee('100.00')
            ->assertDontSee(__('purchasing.unpaid'));
    }

    public function test_vendor_detail_financial_tabs_and_cost_redaction(): void
    {
        $this->activate($this->owner);

        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10']],
        ]);

        // Owner sees financial tabs and statement
        Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->assertOk()
            ->assertSee(__('purchasing.purchases'))
            ->assertSee(__('purchasing.vendor_payments'))
            ->assertSee(__('purchasing.statement'))
            ->set('activeTab', 'statement')
            ->assertOk()
            ->assertSee('100.00');

        // Restricted user without cost view sees only overview
        $noCostUser = $this->customActor(['vendors.view']);
        $this->activate($noCostUser);

        Livewire::actingAs($noCostUser)
            ->test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->assertOk()
            ->assertSee($this->vendor->displayName())
            ->assertDontSee('100.00')
            ->set('activeTab', 'statement')
            ->assertSet('activeTab', 'overview');
    }

    public function test_stale_permission_revocation_fails_closed(): void
    {
        $this->activate($this->owner);

        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '50.00',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'ui-stale-rev-1',
            'allocations' => [],
        ]);

        $user = $this->customActor([
            'vendors.view',
            'purchasing.cost.view',
            'money.vendor_payment.create',
            'money.vendor_payment.reverse',
        ]);
        $this->activate($user);

        $component = Livewire::actingAs($user)
            ->test(PaymentDetail::class, ['publicId' => $payment->public_id])
            ->assertOk();

        // Revoke reverse permission before call
        $user->roles->first()->revokePermissionTo('money.vendor_payment.reverse');
        setPermissionsTeamId($this->company->id);

        $component->call('reversePayment')
            ->assertForbidden();
    }

    public function test_purchase_prefill_without_matching_currency_account_cannot_post_an_unrelated_advance(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.5']);
        MoneyAccount::where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['is_active' => false]);
        Livewire::test(PaymentForm::class, ['purchase_id' => $purchase->id])
            ->assertSet('currency_code', 'USD')
            ->assertSet('money_account_id', null)
            ->call('save')->assertHasErrors(['money_account_id']);
        $this->assertDatabaseCount('vendor_payments', 0);
    }

    public function test_deleted_vendor_with_historical_purchase_can_be_selected_and_fully_settled(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'ILS']);
        $this->vendor->delete();
        Livewire::test(PaymentForm::class, ['purchase_id' => $purchase->id])
            ->assertSee($this->vendor->name_ar)
            ->assertSet('vendor_id', $this->vendor->id)
            ->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('vendor_payments', 1);
        $this->assertSame('0.000000', (string) $purchase->fresh()->payablePosition()->outstanding);
    }

    public function test_vendor_preselection_uses_enabled_preferred_document_locale(): void
    {
        $this->vendor->update(['preferred_locale' => 'en']);
        Livewire::test(PaymentForm::class, ['vendor_id' => $this->vendor->id])
            ->assertSet('document_locale', 'en');
        $purchase = $this->createAndPostPurchase(['currency_code' => 'ILS']);
        Livewire::test(PaymentForm::class, ['purchase_id' => $purchase->id])
            ->assertSet('document_locale', 'en');
    }

    public function test_statement_activity_uses_ui_locale_and_translated_labels(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'ILS']);
        $this->vendor->update(['preferred_locale' => 'ar']);
        app()->setLocale('en');
        $data = app(VendorStatementQuery::class)->execute($this->vendor);
        $this->assertSame('Purchase #'.$purchase->purchase_number, $data['currencies']['ILS']['entries'][0]['description']);
        Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->set('activeTab', 'statement')->assertSee('Opening Balance')
            ->assertSee('Payable Increase (Cr)')->assertDontSee('purchasing.purchase');
        app()->setLocale('ar');
        $data = app(VendorStatementQuery::class)->execute($this->vendor);
        $this->assertSame('فاتورة مشتريات #'.$purchase->purchase_number, $data['currencies']['ILS']['entries'][0]['description']);
    }
}
