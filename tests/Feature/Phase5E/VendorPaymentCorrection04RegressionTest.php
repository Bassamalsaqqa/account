<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Livewire\Pages\Purchasing\PaymentDetail;
use App\Livewire\Pages\Purchasing\PaymentForm;
use App\Livewire\Pages\Purchasing\PaymentIndex;
use App\Models\VendorPayment;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorPaymentHistoryCommands;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class VendorPaymentCorrection04RegressionTest extends Phase5ETestCase
{
    private function intent(array $changes = []): array
    {
        return array_replace([
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '1.00',
            'exchange_rate' => '1',
            'idempotency_key' => 'correction04-payment',
            'allocations' => [],
        ], $changes);
    }

    public function test_retired_account_resolves_exact_historical_identity_in_relation_detail_and_index(): void
    {
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent());
        $accountName = $this->ilsCashAccount->displayName();
        $this->ilsCashAccount->delete();
        $payment = $payment->fresh();

        $this->assertSame($this->ilsCashAccount->id, $payment->moneyAccount->id);
        $this->assertSame($this->company->id, $payment->moneyAccount->company_id);
        $this->assertTrue($payment->moneyAccount->trashed());
        Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])->assertOk()->assertSee($accountName);
        Livewire::test(PaymentIndex::class)->assertOk()->assertSee($accountName);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_retired_account_remains_ineligible_for_new_payment_with_zero_effects(): void
    {
        $this->ilsCashAccount->delete();
        $sequences = DB::table('document_sequences')->pluck('next_number', 'id')->all();
        try {
            app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent());
            $this->fail('New payment used a retired account.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('vendor_payments', 0);
            $this->assertDatabaseCount('posting_batches', 0);
            $this->assertSame($sequences, DB::table('document_sequences')->pluck('next_number', 'id')->all());
        }
    }

    public function test_historical_account_relation_does_not_authorize_substituted_account_history(): void
    {
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $this->intent());
        $this->ilsCashAccount->delete();
        DB::table('vendor_payments')->where('id', $payment->id)->update(['money_account_id' => $this->usdCashAccount->id]);
        $this->assertFalse(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_form_minimum_tracks_selected_currency_catalog_without_changing_entered_amount(): void
    {
        $page = Livewire::test(PaymentForm::class)->set('amount', '1.23');
        foreach ([[$this->ilsCashAccount, '0.01'], [$this->usdCashAccount, '0.01'], [$this->jodCashAccount, '0.001']] as [$account, $minimum]) {
            $page->set('money_account_id', (string) $account->id)
                ->assertSet('currency_code', $account->currency_code)->assertSet('amount', '1.23')
                ->assertViewHas('paymentAmountMinimum', $minimum);
            $document = new \DOMDocument;
            @$document->loadHTML($page->html(false));
            $inputs = (new \DOMXPath($document))->query('//input[@*[name()="wire:model.blur"]="amount"]');
            $this->assertSame(1, $inputs->length);
            $this->assertSame($minimum, $inputs->item(0)->getAttribute('min'));
            $this->assertSame('any', $inputs->item(0)->getAttribute('step'));
        }
    }

    public static function jodAmounts(): array
    {
        return [['0.001', '0.001000', '0.005000'], ['1.234', '1.234000', '6.170000']];
    }

    #[DataProvider('jodAmounts')]
    public function test_jod_form_posts_exact_amount_and_history_retry_and_reconciliation_remain_coherent(string $amount, string $storedAmount, string $base): void
    {
        $page = Livewire::test(PaymentForm::class)
            ->set('vendor_id', (string) $this->vendor->id)
            ->set('money_account_id', (string) $this->jodCashAccount->id)
            ->set('payment_date', '2026-10-02')
            ->set('exchange_rate', '5.0000000000')->set('amount', $amount)
            ->assertViewHas('paymentAmountMinimum', '0.001');
        $key = $page->get('idempotency_key');
        $intent = $this->intent([
            'money_account_id' => $this->jodCashAccount->id,
            'amount' => $amount, 'exchange_rate' => '5.0000000000',
            'idempotency_key' => $key,
            'document_locale' => $page->get('document_locale'),
            'reference_number' => $page->get('reference_number'),
            'notes' => $page->get('notes'),
        ]);
        $page->call('save')->assertHasNoErrors();
        $payment = VendorPayment::sole();
        $page->assertRedirect(route('vendor-payments.show', $payment->public_id));
        $this->assertSame('JOD', $payment->currency_code);
        $this->assertSame($storedAmount, $payment->amount);
        $this->assertSame($base, $payment->amount_base);
        $debits = BigDecimal::zero();
        $credits = BigDecimal::zero();
        foreach ($payment->postingBatch->lines as $line) {
            $debits = $debits->plus($line->debit_base);
            $credits = $credits->plus($line->credit_base);
        }
        $this->assertTrue($debits->isEqualTo($base));
        $this->assertTrue($credits->isEqualTo($debits));
        $this->assertTrue(app(VendorPaymentHistoryCommands::class)->payment($payment)->matchesBatch($payment->postingBatch));
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
        $counts = [DB::table('vendor_payments')->count(), DB::table('posting_batches')->count()];
        $sequences = DB::table('document_sequences')->pluck('next_number', 'id')->all();
        $this->assertSame($payment->id, app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $intent)->id);
        $this->assertSame($counts, [DB::table('vendor_payments')->count(), DB::table('posting_batches')->count()]);
        $this->assertSame($sequences, DB::table('document_sequences')->pluck('next_number', 'id')->all());
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }
}
