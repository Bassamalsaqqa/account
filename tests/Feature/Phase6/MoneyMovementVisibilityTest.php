<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Domain\Money\Queries\MoneyActivityQuery;
use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Domain\Money\Queries\MoneyMovementQuery;
use App\Domain\Money\Queries\MoneySourceLinksQuery;
use App\Livewire\Pages\Money\AccountDetail;
use App\Livewire\Pages\Money\Overview;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\User;
use App\Models\VendorPayment;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class MoneyMovementVisibilityTest extends Phase6TestCase
{
    public static function permissions(): array
    {
        return [
            'viewer' => [[], false, false],
            'vendor reader' => [['purchasing.cost.view', 'money.vendor_payment.create'], true, false],
            'outgoing reader' => [['purchasing.cost.view', 'money.check.view'], false, true],
            'cost only' => [['purchasing.cost.view'], false, false],
            'checks without cost' => [['money.check.view'], false, false],
            'all domains' => [['purchasing.cost.view', 'money.vendor_payment.create', 'money.check.view'], true, true],
        ];
    }

    #[DataProvider('permissions')]
    public function test_source_policy_is_applied_to_originals_and_inverses_in_both_queries(array $permissions, bool $vendorVisible, bool $outgoingVisible): void
    {
        $history = $this->history();
        $reader = $this->customActor(['money.cash.view', 'money.bank.view', ...$permissions]);
        $this->activate($reader);
        $recent = app(MoneyActivityQuery::class)->recent($this->company->id, ['cash', 'bank']);
        $movements = collect();
        foreach ([$this->usdCashAccount, $this->usdBankAccount] as $account) {
            $rows = app(MoneyMovementQuery::class)->forAccount($account)->items();
            $movements = $movements->concat($rows);
            foreach ($rows as $row) {
                $this->assertFalse(property_exists($row, 'visibility_batch_id'));
                $this->assertFalse(property_exists($row, 'has_hidden_history'));
                $hidden = $account->id === $this->usdCashAccount->id ? ! $vendorVisible : ! $outgoingVisible;
                if ($hidden) {
                    $this->assertNull($row->running_base);
                    $this->assertNull($row->known_running_currency);
                    $this->assertNull($row->unknown_currency_lines);
                } else {
                    $this->assertNotNull($row->running_base);
                }
            }
        }
        foreach ([$recent, $movements] as $rows) {
            foreach ($history['public_lines'] as $id) {
                $this->assertTrue($rows->contains('id', $id), 'Unrelated Customer/Transfer/incoming movement must remain visible.');
            }
            foreach ($history['vendor_lines'] as $id) {
                $this->assertSame($vendorVisible, $rows->contains('id', $id), 'Vendor original and reversal use the same policy.');
            }
            foreach ($history['outgoing_lines'] as $id) {
                $this->assertSame($outgoingVisible, $rows->contains('id', $id), 'Outgoing original and reversal use the same policy.');
            }
            $links = app(MoneySourceLinksQuery::class)->forRows($this->company->id, $rows);
            $this->assertSame($vendorVisible, collect($history['vendor_lines'])->every(fn ($id) => isset($links[$id])));
            $this->assertSame($outgoingVisible, collect($history['outgoing_lines'])->every(fn ($id) => isset($links[$id])));
        }
    }

    public function test_viewer_html_view_data_and_livewire_snapshots_contain_no_sensitive_movements(): void
    {
        $history = $this->history();
        $this->activate($this->customActor(['money.cash.view', 'money.bank.view']));
        $overview = Livewire::test(Overview::class)->assertStatus(200);
        foreach ([$overview, Livewire::test(AccountDetail::class, ['publicId' => $this->usdCashAccount->public_id]),
            Livewire::test(AccountDetail::class, ['publicId' => $this->usdBankAccount->public_id])] as $page) {
            $page->assertStatus(200);
            $data = $page === $overview ? $page->viewData('activity')->all() : $page->viewData('movements')->items();
            $response = $page->html(false).json_encode($page->snapshot, JSON_THROW_ON_ERROR).json_encode($data, JSON_THROW_ON_ERROR);
            foreach (['VENDOR_PRIVATE_7329', $history['payment']->payment_number, 'CHECK_PRIVATE_9183',
                '73.290000', '256.515000', '91.830000', '321.405000', 'Vendor Payment', 'Check cleared CHECK_PRIVATE'] as $secret) {
                $this->assertStringNotContainsString($secret, $response);
            }
            if ($page !== $overview) {
                $page->assertSee(__('money.unavailable'));
            }
        }
        $overview->assertSee('PUBLIC_CHECK_4361')->assertSee('Customer Receipt')->assertSee('17.430000');
    }

    public function test_running_balances_are_truthful_for_full_reader_and_unavailable_when_intervening_rows_are_hidden(): void
    {
        $history = $this->history();
        $full = collect(app(MoneyMovementQuery::class)->forAccount($this->usdCashAccount)->items());
        $transfer = $full->firstWhere('source_type', 'money_transfer');
        $this->assertSame('-208.425000', $transfer->running_base);
        $this->assertSame('-59.550000', $transfer->known_running_currency);
        $this->activate($this->customActor(['money.cash.view', 'money.bank.view']));
        $query = app(MoneyMovementQuery::class);
        $first = $query->forAccount($this->usdCashAccount, perPage: 1);
        $prior = $query->forAccount($this->usdCashAccount, page: 2, perPage: 1);
        $this->assertSame(2, $first->total());
        foreach ([$first->items()[0], $prior->items()[0]] as $row) {
            $this->assertNull($row->running_base);
            $this->assertNull($row->known_running_currency);
            $this->assertNotContains($row->id, $history['vendor_lines']);
        }
        $balance = collect(app(MoneyBalanceQuery::class)->forType($this->company, 'cash'))->firstWhere('public_id', $this->usdCashAccount->public_id);
        $this->assertSame('13.740000', $balance['balance_currency']);
        $this->assertSame('48.090000', $balance['balance_base']);
    }

    public function test_running_balances_remain_available_for_restricted_reader_when_account_has_no_hidden_history(): void
    {
        $this->receipt('31.17', 'visible-only');
        $this->activate($this->customActor(['money.cash.view']));
        $row = app(MoneyMovementQuery::class)->forAccount($this->usdCashAccount)->items()[0];
        $this->assertSame('109.095000', $row->running_base);
        $this->assertSame('31.170000', $row->known_running_currency);
    }

    public function test_recent_limit_is_filled_by_visible_rows_not_newer_hidden_rows(): void
    {
        $visible = [];
        for ($i = 0; $i < 13; $i++) {
            $receipt = $this->receipt('1.23', 'public-'.$i);
            $visible[] = DB::table('posting_lines')->where('posting_batch_id', $receipt->posting_batch_id)
                ->where('ledger_account_id', $this->usdCashAccount->ledger_account_id)->value('id');
        }
        for ($i = 0; $i < 13; $i++) {
            $this->vendorPayment('hidden-'.$i);
        }
        $this->activate($this->customActor(['money.cash.view']));
        $rows = app(MoneyActivityQuery::class)->recent($this->company->id, ['cash']);
        $this->assertCount(12, $rows);
        $this->assertSame(array_slice(array_reverse($visible), 0, 12), $rows->pluck('id')->all());
        $this->assertSame(['customer_payment'], $rows->pluck('source_type')->unique()->values()->all());
    }

    public function test_stale_revocation_removes_financial_rows_on_both_livewire_surfaces(): void
    {
        $history = $this->history();
        $reader = $this->customActor(['money.cash.view', 'money.bank.view', 'money.check.view', 'purchasing.cost.view', 'money.vendor_payment.create']);
        $this->activate($reader);
        $pages = [Livewire::test(Overview::class), Livewire::test(AccountDetail::class, ['publicId' => $this->usdCashAccount->public_id]),
            Livewire::test(AccountDetail::class, ['publicId' => $this->usdBankAccount->public_id])];
        $pages[0]->assertSee($history['payment']->payment_number)->assertSee('CHECK_PRIVATE_9183');
        $reader->roles()->firstOrFail()->revokePermissionTo('purchasing.cost.view');
        foreach ($pages as $page) {
            $page->call('$refresh')->assertStatus(200)->assertDontSee($history['payment']->payment_number)->assertDontSee('CHECK_PRIVATE_9183');
            $response = $page->html(false).json_encode($page->snapshot, JSON_THROW_ON_ERROR);
            foreach (['VENDOR_PRIVATE_7329', '73.290000', '91.830000', '-208.425000'] as $secret) {
                $this->assertStringNotContainsString($secret, $response);
            }
        }
    }

    public function test_reversal_with_foreign_company_original_fails_closed(): void
    {
        $history = $this->history();
        $foreignOwner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $foreign = app(CreateCompanyAction::class)->execute($foreignOwner, ['name_ar' => 'Foreign', 'base_currency_code' => 'ILS']);
        DB::table('posting_batches')->where('id', $history['payment']->posting_batch_id)->update(['company_id' => $foreign->id]);
        $this->activate($this->owner);
        $rows = app(MoneyMovementQuery::class)->forAccount($this->usdCashAccount)->items();
        $ids = collect($rows)->pluck('id')->all();
        foreach ($history['vendor_lines'] as $id) {
            $this->assertNotContains($id, $ids);
        }
        foreach ($rows as $row) {
            $this->assertNull($row->running_base);
        }
    }

    public function test_revoking_last_vendor_permission_keeps_outgoing_check_and_customer_authority_separate(): void
    {
        $history = $this->history();
        $reader = $this->customActor(['money.cash.view', 'money.bank.view', 'money.check.view', 'purchasing.cost.view', 'money.vendor_payment.create']);
        $this->activate($reader);
        $overview = Livewire::test(Overview::class)->assertSee($history['payment']->payment_number)->assertSee('CHECK_PRIVATE_9183');
        $detail = Livewire::test(AccountDetail::class, ['publicId' => $this->usdCashAccount->public_id])->assertSee($history['payment']->payment_number);
        $reader->roles()->firstOrFail()->revokePermissionTo('money.vendor_payment.create');
        $overview->call('$refresh')->assertDontSee($history['payment']->payment_number)->assertSee('CHECK_PRIVATE_9183')->assertSee('Customer Receipt');
        $detail->call('$refresh')->assertDontSee($history['payment']->payment_number)->assertSee(__('money.unavailable'));
        foreach ($overview->viewData('activity') as $row) {
            $this->assertNotContains($row->id, $history['vendor_lines']);
        }
    }

    private function receipt(string $amount, string $key): CustomerPayment
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'PUBLIC_CUSTOMER', 'active' => true, 'created_by' => $this->owner->id]);

        return app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'money_account_id' => $this->usdCashAccount->id, 'payment_method' => 'cash',
            'amount' => $amount, 'exchange_rate' => '3.5', 'payment_date' => '2026-10-02', 'idempotency_key' => $key, 'allocations' => [],
        ]);
    }

    private function vendorPayment(string $key): VendorPayment
    {
        return app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->usdCashAccount->id, 'payment_method' => 'cash',
            'amount' => '73.29', 'exchange_rate' => '3.5', 'payment_date' => '2026-10-03', 'idempotency_key' => $key, 'allocations' => [],
        ]);
    }

    private function history(): array
    {
        $this->vendor->update(['name_ar' => 'VENDOR_PRIVATE_7329', 'name_en' => 'VENDOR_PRIVATE_7329']);
        $receipt = $this->receipt('31.17', 'public-receipt');
        $payment = $this->vendorPayment('private-payment');
        $transfer = app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, [
            'from_money_account_id' => $this->usdCashAccount->id, 'to_money_account_id' => $this->usdBankAccount->id,
            'from_amount' => '17.43', 'to_amount' => '17.43', 'from_exchange_rate' => '3.5', 'to_exchange_rate' => '3.5',
            'transfer_date' => '2026-10-04', 'idempotency_key' => 'public-transfer',
        ]);
        $incoming = app(ReceiveCheckAction::class)->execute($this->company, $this->owner,
            array_replace($this->checkIntent(), ['check_number' => 'PUBLIC_CHECK_4361', 'amount' => '43.61']));
        $this->event($incoming, 'deposit');
        $incomingClear = $this->event($incoming->fresh(), 'clear', date: '2026-10-04');
        $outgoing = app(IssueCheckAction::class)->execute($this->company, $this->owner,
            array_replace($this->checkIntent('outgoing'), ['check_number' => 'CHECK_PRIVATE_9183', 'amount' => '91.83']));
        $outgoingClear = $this->event($outgoing, 'clear', date: '2026-10-04');
        $payment = app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner, 'Private reversal', '2026-10-06');
        $outgoingInverse = $this->disposableOutgoingInverse((int) $outgoingClear->posting_batch_id);
        $lineIds = fn (array $batches): array => DB::table('posting_lines')->whereIn('posting_batch_id', $batches)
            ->whereIn('ledger_account_id', [$this->usdCashAccount->ledger_account_id, $this->usdBankAccount->ledger_account_id])->pluck('id')->all();

        return ['payment' => $payment, 'public_lines' => $lineIds([$receipt->posting_batch_id, $transfer->posting_batch_id, $incomingClear->posting_batch_id]),
            'vendor_lines' => $lineIds([$payment->posting_batch_id, $payment->reversal_posting_batch_id]),
            'outgoing_lines' => $lineIds([$outgoingClear->posting_batch_id, $outgoingInverse])];
    }

    private function disposableOutgoingInverse(int $originalId): int
    {
        // Read-side corruption fixture ONLY: outgoing clearance has no accepted return lifecycle.
        // Prove even a separately present inverse cannot bypass the original source's visibility.
        $batch = (array) DB::table('posting_batches')->find($originalId);
        unset($batch['id']);
        $batch['public_id'] = (string) Str::ulid();
        $batch['source_type'] = 'reversal';
        $batch['source_id'] = $originalId;
        $batch['reversal_of_id'] = $originalId;
        $batch['posting_date'] = '2026-10-06';
        $batch['idempotency_key'] = 'disposable-clear-inverse';
        $id = DB::table('posting_batches')->insertGetId($batch);
        foreach (DB::table('posting_lines')->where('posting_batch_id', $originalId)->get() as $line) {
            $values = (array) $line;
            unset($values['id']);
            $values['posting_batch_id'] = $id;
            [$values['debit_base'], $values['credit_base']] = [$values['credit_base'], $values['debit_base']];
            DB::table('posting_lines')->insert($values);
        }

        return $id;
    }
}
