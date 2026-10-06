<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Company\UpdateCompanyCurrenciesAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Livewire\Pages\Purchasing\PaymentForm;
use App\Models\MoneyAccount;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class VendorPaymentAccountEligibilityRegressionTest extends Phase5ETestCase
{
    public static function foreignCurrencies(): array
    {
        return [['USD'], ['JOD']];
    }

    private function account(string $currency): MoneyAccount
    {
        return $currency === 'USD' ? $this->usdCashAccount : $this->jodCashAccount;
    }

    private function setCurrencyEnabled(string $currency, bool $enabled): void
    {
        app(UpdateCompanyCurrenciesAction::class)->execute($this->company, 'ILS',
            array_replace(['ILS' => true, 'USD' => true, 'JOD' => true], [$currency => $enabled]), $this->owner);
    }

    private function assertCurrencyAbsentFromOptions($page, string $currency): void
    {
        $page->assertViewHas('accounts', fn ($accounts): bool => $accounts->every(fn ($account): bool => $account->currency_code !== $currency));
        foreach (MoneyAccount::where('company_id', $this->company->id)->where('currency_code', $currency)->get() as $account) {
            $page->assertDontSee($account->displayName());
            $this->assertTrue($account->is_active);
            $this->assertFalse($account->trashed());
        }
    }

    #[DataProvider('foreignCurrencies')]
    public function test_disabled_currency_account_is_not_default_or_dropdown_option_even_if_it_sorts_first(string $currency): void
    {
        MoneyAccount::where('company_id', $this->company->id)->update(['sort_order' => 100]);
        $this->ilsCashAccount->refresh()->update(['sort_order' => 0]);
        $this->account($currency)->update(['sort_order' => -100]);
        $this->setCurrencyEnabled($currency, false);
        $page = Livewire::test(PaymentForm::class)
            ->assertSet('money_account_id', $this->ilsCashAccount->id)->assertSet('currency_code', 'ILS');
        $this->assertCurrencyAbsentFromOptions($page, $currency);
    }

    #[DataProvider('foreignCurrencies')]
    public function test_purchase_preselection_does_not_adopt_disabled_matching_account(string $currency): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => $currency, 'exchange_rate' => '3.50']);
        $this->setCurrencyEnabled($currency, false);
        $page = Livewire::test(PaymentForm::class, ['purchase_id' => $purchase->id])
            ->assertOk()->assertSet('money_account_id', null)->assertSet('allocations', []);
        $this->assertCurrencyAbsentFromOptions($page, $currency);
    }

    #[DataProvider('foreignCurrencies')]
    public function test_reenabled_currency_account_is_available_for_selection_and_default_again(string $currency): void
    {
        $account = $this->account($currency);
        $account->update(['sort_order' => -100]);
        $this->setCurrencyEnabled($currency, false);
        $page = Livewire::test(PaymentForm::class);
        $this->assertCurrencyAbsentFromOptions($page, $currency);
        $this->setCurrencyEnabled($currency, true);
        $page->call('$refresh')->assertViewHas('accounts', fn ($accounts): bool => $accounts->contains('id', $account->id))
            ->set('money_account_id', $account->id)->assertSet('currency_code', $currency)->assertSet('exchange_rate', '');
        Livewire::test(PaymentForm::class)->assertSet('money_account_id', $account->id);
    }

    #[DataProvider('foreignCurrencies')]
    public function test_stale_or_tampered_disabled_id_is_cleared_without_adopting_its_currency(string $currency): void
    {
        $page = Livewire::test(PaymentForm::class)->set('money_account_id', $this->ilsCashAccount->id)->set('amount', '12.34');
        $this->setCurrencyEnabled($currency, false);
        $page->set('money_account_id', $this->account($currency)->id)
            ->assertSet('money_account_id', null)->assertSet('currency_code', 'ILS')
            ->assertSet('amount', '12.34')->assertSet('allocations', []);
        $this->assertCurrencyAbsentFromOptions($page, $currency);
    }

    #[DataProvider('foreignCurrencies')]
    public function test_canonical_new_payment_still_rejects_disabled_currency_atomically(string $currency): void
    {
        $this->setCurrencyEnabled($currency, false);
        $sequenceBefore = DB::table('document_sequences')->orderBy('id')->get()->toJson();
        try {
            app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
                'vendor_id' => $this->vendor->id, 'money_account_id' => $this->account($currency)->id,
                'payment_date' => '2026-10-02', 'payment_method' => 'cash', 'amount' => '1.00',
                'exchange_rate' => '3.50', 'idempotency_key' => 'disabled-currency', 'allocations' => [],
            ]);
            $this->fail('Canonical new payment accepted a disabled transaction currency.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Payment currency is not enabled.', $exception->getMessage());
        }
        $this->assertDatabaseCount('vendor_payments', 0);
        $this->assertDatabaseCount('vendor_payment_allocations', 0);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertSame($sequenceBefore, DB::table('document_sequences')->orderBy('id')->get()->toJson());
    }

    public function test_livewire_clearing_and_reentering_allocation_recalculates_exact_foreign_preview(): void
    {
        $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $page = Livewire::test(PaymentForm::class, ['vendor_id' => $this->vendor->id])
            ->set('money_account_id', $this->usdCashAccount->id)->set('amount', '100.00')->set('exchange_rate', '3.60')
            ->set('allocations.0.allocated_amount', '10.00')->set('allocations.1.allocated_amount', '20.00')
            ->call('recalculateAllocations')->assertSet('allocatedTotal', '30.00')->assertSet('unallocatedAmount', '70.00');
        $page->set('allocations.0.allocated_amount', '')->call('recalculateAllocations')->assertOk()
            ->assertSet('allocations.0.allocated_amount', '')->assertSet('allocatedTotal', '20.00')
            ->assertSet('unallocatedAmount', '80.00')->assertSet('allocations.0.preview_fx', '0.000000')
            ->assertSet('allocations.1.preview_fx', '2.000000');
        $page->set('allocations.0.allocated_amount', '15.00')->call('recalculateAllocations')
            ->assertSet('allocatedTotal', '35.00')->assertSet('unallocatedAmount', '65.00')
            ->assertSet('allocations.0.preview_fx', '1.500000')->assertSet('allocations.1.preview_fx', '2.000000');
    }
}
