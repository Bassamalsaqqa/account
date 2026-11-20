<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Livewire\Pages\Accounting\OpeningTradeBalanceEntry;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\OpeningTradeItem;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\Vendor;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Phase8\TradeTestCase;

final class OpeningTradeBalanceEntryLivewireTest extends TradeTestCase
{
    private Customer $customer;

    private Vendor $testVendor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->activateUser($this->owner);
        $this->customer = $this->defaultCustomer;
        $this->testVendor = $this->vendor;
    }

    public function test_can_render_opening_trade_balance_entry_page(): void
    {
        $response = $this->actingAs($this->owner)->get(route('accounting.opening-balances.trade'));
        $response->assertStatus(200);
        $response->assertSeeLivewire(OpeningTradeBalanceEntry::class);
    }

    public function test_can_save_customer_opening_balance_via_livewire(): void
    {
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', OpeningTradeItem::SIDE_CUSTOMER)
            ->set('party_id', $this->customer->id)
            ->set('amount', '1500.00')
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('opening_date', '2026-01-01')
            ->set('original_reference', 'CUST-INIT-101')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('amount', '')
            ->assertSet('original_reference', null);

        $this->assertDatabaseHas('opening_trade_items', [
            'company_id' => $this->company->id,
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->customer->id,
            'amount_currency' => '1500.000000',
            'original_reference' => 'CUST-INIT-101',
            'status' => OpeningTradeItem::STATUS_POSTED,
        ]);
    }

    public function test_can_save_vendor_opening_balance_via_livewire(): void
    {
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', OpeningTradeItem::SIDE_VENDOR)
            ->set('party_id', $this->testVendor->id)
            ->set('amount', '820.00')
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('opening_date', '2026-01-01')
            ->set('original_reference', 'VEND-INIT-202')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('amount', '');

        $this->assertDatabaseHas('opening_trade_items', [
            'company_id' => $this->company->id,
            'side' => OpeningTradeItem::SIDE_VENDOR,
            'vendor_id' => $this->testVendor->id,
            'amount_currency' => '820.000000',
            'original_reference' => 'VEND-INIT-202',
            'status' => OpeningTradeItem::STATUS_POSTED,
        ]);
    }

    public function test_displays_gl_overlap_error_banner_when_legacy_opening_exists(): void
    {
        $arAccount = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'accounts_receivable')
            ->firstOrFail();

        $equityAccount = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'opening_balance_equity')
            ->firstOrFail();

        $batch = PostingBatch::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'source_type' => 'opening_balance',
            'source_id' => 8888,
            'batch_number' => 'OB-LEGACY-02',
            'posting_date' => '2026-01-01',
            'status' => PostingBatch::STATUS_POSTED,
            'description' => 'Legacy GL opening balance',
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'legacy-ob-02',
            'posted_by' => $this->owner->id,
            'posted_at' => now(),
        ]);

        PostingLine::create([
            'company_id' => $this->company->id,
            'posting_batch_id' => $batch->id,
            'line_number' => 1,
            'ledger_account_id' => $arAccount->id,
            'debit_base' => '2000.000000',
            'credit_base' => '0.000000',
            'transaction_currency_code' => 'ILS',
            'transaction_amount' => '2000.00',
            'exchange_rate' => '1.0000000000',
            'description' => 'Legacy AR balance',
        ]);

        PostingLine::create([
            'company_id' => $this->company->id,
            'posting_batch_id' => $batch->id,
            'line_number' => 2,
            'ledger_account_id' => $equityAccount->id,
            'debit_base' => '0.000000',
            'credit_base' => '2000.000000',
            'transaction_currency_code' => 'ILS',
            'transaction_amount' => '2000.00',
            'exchange_rate' => '1.0000000000',
            'description' => 'Legacy equity balance',
        ]);

        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', OpeningTradeItem::SIDE_CUSTOMER)
            ->set('party_id', $this->customer->id)
            ->set('amount', '500.00')
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->call('save')
            ->assertSee('GL_OVERLAP_REJECTED')
            ->assertSeeHtml('border-danger/30');
    }
}
