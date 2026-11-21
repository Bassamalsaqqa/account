<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Livewire\Pages\Accounting\OpeningTradeBalanceEntry;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\OpeningTradeItem;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
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

    public function test_invalid_additional_rows_retain_entries_and_post_no_legs(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            foreach (['', 'garbage', '0', '-1', '1e2', '0.0000001'] as $amount) {
                $beforeItems = OpeningTradeItem::count();
                $beforeBatches = PostingBatch::count();
                $beforeLines = PostingLine::count();
                $rows = [['amount' => '12', 'currency_code' => 'ILS'], ['amount' => $amount, 'currency_code' => 'ILS']];
                Livewire::actingAs($this->owner)->test(OpeningTradeBalanceEntry::class)
                    ->set('side', $side)
                    ->set('party_id', $side === 'customer' ? $this->customer->id : $this->testVendor->id)
                    ->set('amount', '100')->set('additional_lines', $rows)
                    ->call('save')->assertHasErrors('additional_lines.1.amount')
                    ->assertSet('additional_lines', $rows)->assertSet('amount', '100');
                $this->assertSame($beforeItems, OpeningTradeItem::count());
                $this->assertSame($beforeBatches, PostingBatch::count());
                $this->assertSame($beforeLines, PostingLine::count());
            }
        }
    }

    public function test_can_render_opening_trade_balance_entry_page(): void
    {
        $response = $this->actingAs($this->owner)->get(route('accounting.opening-balances.trade'));
        $response->assertStatus(200);
        $response->assertSeeLivewire(OpeningTradeBalanceEntry::class);
    }

    public function test_can_render_in_arabic_and_english(): void
    {
        // Arabic locale
        app()->setLocale('ar');
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->assertSee('تسجيل الأرصدة الافتتاحية للذمم')
            ->assertSee('ذمة زبون (لنا)')
            ->assertSee('مبلغ الرصيد')
            ->assertSee('تأكيد وقيد الرصيد');

        // English locale
        app()->setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->assertSee('Opening Trade Balances')
            ->assertSee('Customer (Receivable)')
            ->assertSee('Balance Amount')
            ->assertSee('Confirm and Post Balance');

        // Reset locale
        app()->setLocale('ar');
    }

    public function test_side_toggle_switches_context_and_resets_party(): void
    {
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', OpeningTradeItem::SIDE_CUSTOMER)
            ->set('party_id', $this->customer->id)
            ->assertSet('party_id', $this->customer->id)
            ->set('side', OpeningTradeItem::SIDE_VENDOR)
            ->assertSet('party_id', null)
            ->assertSet('side', OpeningTradeItem::SIDE_VENDOR);
    }

    public function test_foreign_currency_shows_rate_input_and_server_carrying_preview(): void
    {
        // Enable USD for testing
        CompanyCurrency::updateOrCreate([
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
        ], [
            'enabled' => true,
        ]);

        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->assertViewHas('isBase', true)
            ->assertDontSee('سعر الصرف التاريخي مقابل عملة الأساس')
            ->set('currency_code', 'USD')
            ->assertViewHas('isBase', false)
            ->set('amount', '200.00')
            ->set('exchange_rate', '3.5000000000')
            ->assertViewHas('carryingBasePreview', '700.00')
            ->assertSee('700.00 ILS')
            ->set('currency_code', 'ILS')
            ->assertViewHas('isBase', true);
    }

    public function test_draft_preserved_on_validation_failure_or_gl_overlap(): void
    {
        // 1. Validation failure: invalid negative decimal
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('party_id', $this->customer->id)
            ->set('amount', '-50.00')
            ->set('original_reference', 'PRESERVE-DRAFT-REF')
            ->call('save')
            ->assertHasErrors(['amount'])
            ->assertSet('amount', '-50.00')
            ->assertSet('original_reference', 'PRESERVE-DRAFT-REF');

        // 2. GL overlap failure: draft preserved
        $arAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_receivable')->firstOrFail();
        $equityAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'opening_balance_equity')->firstOrFail();

        $batch = PostingBatch::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'source_type' => 'opening_balance',
            'source_id' => 9999,
            'batch_number' => 'OB-LEGACY-01',
            'posting_date' => '2026-01-01',
            'status' => PostingBatch::STATUS_POSTED,
            'description' => 'Legacy GL opening balance',
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'legacy-ob-01',
            'posted_by' => $this->owner->id,
            'posted_at' => now(),
        ]);

        PostingLine::create([
            'company_id' => $this->company->id,
            'posting_batch_id' => $batch->id,
            'line_number' => 1,
            'ledger_account_id' => $arAccount->id,
            'debit_base' => '1000.000000',
            'credit_base' => '0.000000',
            'transaction_currency_code' => 'ILS',
            'transaction_amount' => '1000.00',
            'exchange_rate' => '1.0000000000',
            'description' => 'Legacy AR balance',
        ]);

        PostingLine::create([
            'company_id' => $this->company->id,
            'posting_batch_id' => $batch->id,
            'line_number' => 2,
            'ledger_account_id' => $equityAccount->id,
            'debit_base' => '0.000000',
            'credit_base' => '1000.000000',
            'transaction_currency_code' => 'ILS',
            'transaction_amount' => '1000.00',
            'exchange_rate' => '1.0000000000',
            'description' => 'Legacy equity balance',
        ]);

        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', OpeningTradeItem::SIDE_CUSTOMER)
            ->set('party_id', $this->customer->id)
            ->set('amount', '600.00')
            ->set('original_reference', 'PRESERVE-DRAFT-REF-2')
            ->call('save')
            ->assertSee('GL_OVERLAP_REJECTED')
            ->assertSet('amount', '600.00')
            ->assertSet('original_reference', 'PRESERVE-DRAFT-REF-2');
    }

    public function test_can_add_and_remove_additional_historical_lines(): void
    {
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->assertSet('has_multiple_lines', false)
            ->assertCount('additional_lines', 0)
            ->call('addAdditionalLine')
            ->assertSet('has_multiple_lines', true)
            ->assertCount('additional_lines', 1)
            ->call('addAdditionalLine')
            ->assertCount('additional_lines', 2)
            ->call('removeAdditionalLine', 0)
            ->assertCount('additional_lines', 1)
            ->call('removeAdditionalLine', 0)
            ->assertCount('additional_lines', 0)
            ->assertSet('has_multiple_lines', false);
    }

    public function test_can_save_customer_opening_balance_with_additional_lines(): void
    {
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', OpeningTradeItem::SIDE_CUSTOMER)
            ->set('party_id', $this->customer->id)
            ->set('amount', '1000.00')
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('opening_date', '2026-01-01')
            ->set('original_reference', 'MAIN-INV-1')
            ->call('addAdditionalLine')
            ->set('additional_lines.0.amount', '500.00')
            ->set('additional_lines.0.currency_code', 'ILS')
            ->set('additional_lines.0.original_reference', 'EXTRA-INV-2')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('amount', '')
            ->assertSet('has_multiple_lines', false);

        $this->assertDatabaseHas('opening_trade_items', [
            'company_id' => $this->company->id,
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->customer->id,
            'amount_currency' => '1000.000000',
            'original_reference' => 'MAIN-INV-1',
            'status' => OpeningTradeItem::STATUS_POSTED,
        ]);

        $this->assertDatabaseHas('opening_trade_items', [
            'company_id' => $this->company->id,
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->customer->id,
            'amount_currency' => '500.000000',
            'original_reference' => 'EXTRA-INV-2',
            'status' => OpeningTradeItem::STATUS_POSTED,
        ]);
    }

    public function test_permissions_and_tenant_isolation_enforced(): void
    {
        // User without company membership gets 403
        $foreignCompany = Company::create([
            'name_ar' => 'شركة أجنبية',
            'name_en' => 'Foreign Company',
            'base_currency_code' => 'ILS',
            'timezone' => 'Asia/Gaza',
            'status' => 'active',
        ]);
        $foreignUser = User::factory()->create();
        CompanyUser::create([
            'company_id' => $foreignCompany->id,
            'user_id' => $foreignUser->id,
            'status' => 'active',
            'is_owner' => true,
        ]);

        $response = $this->actingAs($foreignUser)->get(route('accounting.opening-balances.trade'));
        $response->assertStatus(403);
    }

    public function test_rendering_contains_no_restricted_sensitive_values(): void
    {
        $response = $this->actingAs($this->owner)->get(route('accounting.opening-balances.trade'));
        $response->assertStatus(200);
        $content = $response->getContent();

        // Verify no ledger account IDs, secret keys, or sensitive costing data rendered
        $this->assertStringNotContainsString('accounts_receivable', $content);
        $this->assertStringNotContainsString('accounts_payable', $content);
        $this->assertStringNotContainsString('opening_balance_equity', $content);
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
