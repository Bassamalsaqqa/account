<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Livewire\Pages\Purchasing\PaymentDetail;
use App\Livewire\Pages\Purchasing\PaymentForm;
use App\Livewire\Pages\Purchasing\VendorDetail;
use App\Models\LedgerAccount;
use App\Models\VendorPayment;
use App\Models\VendorPaymentApplicationEvent;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Permanent tracked regression tests covering all Phase 5E Correction 01 findings. */
final class VendorPaymentCorrection01RegressionTest extends Phase5ETestCase
{
    private function intent(array $changes = []): array
    {
        return array_replace([
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'corr01-regression-payment',
            'allocations' => [],
        ], $changes);
    }

    private function postPayment(array $changes = []): VendorPayment
    {
        return app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent($changes));
    }

    private function assertRejected(callable $operation, string $message): void
    {
        $rejected = false;
        try {
            $operation();
        } catch (\Exception $exception) {
            $rejected = true;
        }
        $this->assertTrue($rejected, $message);
    }

    public function test_direct_provisional_payment_creation_requires_runtime_authority(): void
    {
        $before = VendorPayment::count();
        $this->assertRejected(fn () => DB::transaction(fn () => VendorPayment::create([
            'company_id' => $this->company->id, 'payment_number' => 'FORGED-VPM',
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash',
            'currency_code' => 'ILS', 'base_currency_code' => 'ILS',
            'amount' => '100.000000', 'exchange_rate' => '1.0000000000', 'amount_base' => '100.000000',
            'vendor_snapshot' => [], 'company_snapshot' => [], 'document_locale' => 'ar',
            'idempotency_key' => 'direct-forgery', 'request_hash' => str_repeat('a', 64),
            'created_by' => $this->owner->id,
        ])), 'Ordinary create persisted a numbered payment with no posting capability or GL.');
        $this->assertSame($before, VendorPayment::count());
    }

    public function test_public_application_methods_cannot_commit_active_relief_without_fx_batch(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $payment = $this->postPayment(['money_account_id' => $this->usdCashAccount->id, 'exchange_rate' => '3.60']);
        $action = app(ApplyVendorPaymentCreditAction::class);
        $intent = [['purchase_id' => $purchase->id, 'allocated_amount' => '40.000000']];
        $this->assertRejected(function () use ($action, $payment, $intent): void {
            DB::transaction(function () use ($action, $payment, $intent): void {
                $prepared = $action->prepare($payment, $intent, '2026-10-03');
                $event = VendorPaymentApplicationEvent::recordCanonicalApplication($payment, $this->owner, [
                    'application_date' => '2026-10-03', 'idempotency_key' => 'standalone-application',
                    'request_hash' => $action::requestHash($this->company->id, $payment->id, $this->owner->id, '2026-10-03', $intent),
                ], $prepared);
                $event->completeCanonicalApplication(null, $this->owner);
            });
        }, 'Public record/complete committed USD40 active relief and FX4 with no FX/AP batch or canonical application scope.');
    }

    public function test_new_zero_fx_application_requires_enabled_currency(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $payment = $this->postPayment(['money_account_id' => $this->usdCashAccount->id, 'exchange_rate' => '3.50']);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['enabled' => false]);
        $this->assertRejected(fn () => app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-03', 'idempotency_key' => 'disabled-currency',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '40.00']],
        ]), 'New zero-FX application succeeded after USD was disabled.');
    }

    public function test_new_application_rejects_corrupt_payment_book_value(): void
    {
        $purchase = $this->createAndPostPurchase();
        $payment = $this->postPayment();
        DB::table('vendor_payments')->where('id', $payment->id)->update(['amount_base' => '110.000000']);
        $this->assertRejected(fn () => app(ApplyVendorPaymentCreditAction::class)->execute($payment->fresh(), $this->owner, [
            'application_date' => '2026-10-03', 'idempotency_key' => 'corrupt-payment',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100.00']],
        ]), 'Application spent a corrupted advance carrying value, creating fictitious FX.');
    }

    public function test_application_retry_rejects_corrupted_history(): void
    {
        $purchase = $this->createAndPostPurchase();
        $payment = $this->postPayment();
        $data = ['application_date' => '2026-10-03', 'idempotency_key' => 'event-retry',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '40.00']]];
        $event = app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, $data);
        DB::table('vendor_payment_allocations')->where('application_event_id', $event->id)->update(['base_amount_applied_to_payable' => '49.000000']);
        $this->assertRejected(fn () => app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, $data),
            'Exact application retry returned corrupted allocation history without integrity validation.');
    }

    public function test_new_payment_rejects_incoherent_original_purchase(): void
    {
        $purchase = $this->createAndPostPurchase();
        DB::table('purchases')->where('id', $purchase->id)->update(['posting_batch_id' => null]);
        $this->assertRejected(fn () => $this->postPayment(['allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '40.00']]]),
            'Payment allocated AP relief to a Posted Purchase with missing canonical GL provenance.');
    }

    public function test_new_payment_requires_asset_debit_money_ledger(): void
    {
        DB::table('ledger_accounts')->where('id', $this->ilsCashAccount->ledger_account_id)->update([
            'account_type' => LedgerAccount::TYPE_EXPENSE, 'normal_balance' => LedgerAccount::BALANCE_CREDIT,
        ]);
        $this->assertRejected(fn () => $this->postPayment(), 'New payment credited an expense/credit-normal money ledger under cash_control.');
    }

    public function test_positive_foreign_allocation_cannot_drop_zero_base_metadata(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '0.0000100000']);
        $this->assertRejected(fn () => $this->postPayment([
            'money_account_id' => $this->usdCashAccount->id, 'exchange_rate' => '0.0000100000',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '0.01']],
        ]), 'USD0.01 allocation with zero base was accepted while its transaction metadata disappeared from GL.');
    }

    public function test_disabled_vendor_preferred_locale_falls_back_to_company(): void
    {
        DB::table('vendors')->where('id', $this->vendor->id)->update(['preferred_locale' => 'ar']);
        DB::table('companies')->where('id', $this->company->id)->update(['default_locale' => 'en']);
        DB::table('company_languages')->where('company_id', $this->company->id)->where('locale', 'ar')->update(['enabled' => false]);
        $this->assertSame('en', $this->postPayment()->document_locale);
    }

    public function test_payables_audit_detects_corrupt_original_gl(): void
    {
        $payment = $this->postPayment();
        DB::table('posting_lines')->where('posting_batch_id', $payment->posting_batch_id)->where('debit_base', '>', 0)->update(['debit_base' => '99.000000']);
        $this->assertFalse(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy,
            'Payables reconciliation declared a corrupt Payment batch healthy.');
    }

    public function test_payables_audit_detects_corrupt_application_relief(): void
    {
        $purchase = $this->createAndPostPurchase();
        $payment = $this->postPayment();
        $event = app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-03', 'idempotency_key' => 'audit-event',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '40.00']],
        ]);
        DB::table('vendor_payment_allocations')->where('application_event_id', $event->id)->update(['base_amount_applied_to_payable' => '49.000000']);
        $this->assertFalse(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy,
            'Payables reconciliation declared a corrupt later AP allocation healthy.');
    }

    public function test_historical_advance_audit_survives_vendor_inactivation(): void
    {
        $this->postPayment();
        DB::table('vendors')->where('id', $this->vendor->id)->update(['status' => 'inactive']);
        $report = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, implode('; ', $report->violations));
    }

    public function test_retry_rejects_missing_request_identity_even_with_changed_intent(): void
    {
        $payment = $this->postPayment();
        DB::table('vendor_payments')->where('id', $payment->id)->update(['request_hash' => null]);
        $this->assertRejected(fn () => $this->postPayment(['amount' => '70.00']), 'Null request_hash bypassed same-key intent conflict.');
    }

    public function test_posted_validator_checks_transaction_currency_metadata(): void
    {
        $payment = $this->postPayment();
        DB::table('posting_lines')->where('posting_batch_id', $payment->posting_batch_id)->where('credit_base', '>', 0)->update(['transaction_amount' => '70.000000']);
        $this->assertRejected(fn () => app(VendorPaymentPostedIntegrityValidator::class)->validate($payment),
            'Posted integrity ignored corrupt Cash transaction metadata while base net stayed unchanged.');
    }

    public function test_payment_detail_rejects_context_switch_on_stale_component(): void
    {
        $payment = $this->postPayment();
        $component = Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])->assertOk();
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة أخرى', 'name_en' => 'Other Company', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($otherCompany, $this->owner);
        setPermissionsTeamId($otherCompany->id);
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');
        $component->call('$refresh')->assertForbidden();
    }

    public function test_vendor_payments_tab_requires_central_payment_read_authority(): void
    {
        $this->postPayment();
        $actor = $this->customActor(['vendors.view', 'purchasing.cost.view']);
        $this->activate($actor);
        Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])->set('activeTab', 'payments')->assertForbidden();
    }

    public function test_deleted_vendor_financial_history_remains_accessible(): void
    {
        $this->postPayment();
        DB::table('vendors')->where('id', $this->vendor->id)->update(['deleted_at' => now()]);
        Livewire::test(VendorDetail::class, ['publicId' => $this->vendor->public_id])->assertOk();
    }

    public function test_statement_rechecks_active_membership(): void
    {
        $this->createAndPostPurchase();
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $this->assertRejected(fn () => app(VendorStatementQuery::class)->execute($this->vendor),
            'Statement read returned financial history after active membership was revoked.');
    }

    public function test_payment_detail_uses_frozen_vendor_identity(): void
    {
        app()->setLocale('ar');
        $payment = $this->postPayment();
        $oldName = $payment->vendor_snapshot['name_ar'];
        DB::table('vendors')->where('id', $this->vendor->id)->update(['name_ar' => 'هوية أحدث لا تخص السند', 'name_en' => 'Later Master Identity']);
        Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])->assertSee($oldName);
    }

    public function test_arabic_form_does_not_display_raw_english_domain_error(): void
    {
        app()->setLocale('ar');
        Livewire::test(PaymentForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('money_account_id', $this->ilsCashAccount->id)
            ->set('amount', '100.005')
            ->call('save')
            ->assertHasErrors('amount')
            ->assertDontSee('Payment amount precision exceeds 2 decimals.');
    }
}
