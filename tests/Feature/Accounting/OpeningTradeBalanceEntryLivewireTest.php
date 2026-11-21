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
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Phase8\TradeTestCase;

final class OpeningTradeBalanceEntryLivewireTest extends TradeTestCase
{
    private Customer $customer;

    private Vendor $testVendor;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->activateUser($this->owner);
        $this->customer = $this->defaultCustomer;
        $this->testVendor = $this->vendor;
    }

    public function test_invalid_additional_rows_retain_entries_and_post_no_legs(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            foreach (['', 'garbage', '0', '-1', '1e2', '0.0000001', '1.001'] as $amount) {
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

    public function test_currency_minor_unit_precision_and_disabled_currency_validation_on_main_and_additional_rows(): void
    {
        // 1. ILS main row rejects overprecision 1.001
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('amount', '1.001')
            ->call('save')
            ->assertHasErrors(['amount']);

        // 2. Main row accepts valid ILS 1.01
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('amount', '1.01')
            ->call('save')
            ->assertHasNoErrors(['amount']);

        // 3. JOD enabled: accepts 1.001, rejects 1.0001
        DB::table('currencies')->updateOrInsert(['code' => 'JOD'], ['name' => 'JOD', 'symbol' => 'JD', 'minor_units' => 3, 'active' => true]);
        DB::table('company_currencies')->updateOrInsert(['company_id' => $this->company->id, 'currency_code' => 'JOD'], ['enabled' => true]);

        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', 'vendor')
            ->set('party_id', $this->testVendor->id)
            ->set('currency_code', 'JOD')
            ->set('exchange_rate', '5.0000000000')
            ->set('amount', '1.0001')
            ->call('save')
            ->assertHasErrors(['amount']);

        // 4. Additional row with JOD rejects 1.0001
        $rows = [
            ['amount' => '1.0001', 'currency_code' => 'JOD', 'exchange_rate' => '5.0000000000'],
        ];
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('amount', '100.00')
            ->set('additional_lines', $rows)
            ->call('save')
            ->assertHasErrors(['additional_lines.0.amount']);

        // 5. Disabled or missing currency on additional line fails with opening_currency_unavailable
        $disabledRow = [
            ['amount' => '10.00', 'currency_code' => 'ZZZ'],
        ];
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('amount', '100.00')
            ->set('additional_lines', $disabledRow)
            ->call('save')
            ->assertHasErrors(['additional_lines.0.amount']);
    }

    public function test_foreign_currency_additional_rows_use_visible_shared_exchange_rate_and_prevent_stale_rate(): void
    {
        CompanyCurrency::updateOrCreate([
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
        ], [
            'enabled' => true,
        ]);

        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'USD')
            ->assertSet('exchange_rate', '1.0000000000')
            ->call('addAdditionalLine')
            ->set('additional_lines.0.amount', '100.00')
            ->set('amount', '200.00')
            // Operator changes visible exchange rate to 3.5 after adding row
            ->set('exchange_rate', '3.5000000000')
            ->assertViewHas('carryingBasePreview', '1050.00') // (200 + 100) * 3.5
            ->call('save')
            ->assertHasNoErrors();

        // Main line posted with rate 3.5 => 200 * 3.5 = 700 ILS
        $this->assertDatabaseHas('opening_trade_items', [
            'company_id' => $this->company->id,
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->customer->id,
            'currency_code' => 'USD',
            'amount_currency' => '200.000000',
            'exchange_rate' => '3.5000000000',
            'carrying_value_base' => '700.000000',
            'status' => OpeningTradeItem::STATUS_POSTED,
        ]);

        // Additional row ALSO posted with rate 3.5 => 100 * 3.5 = 350 ILS (NOT stale rate 1!)
        $this->assertDatabaseHas('opening_trade_items', [
            'company_id' => $this->company->id,
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->customer->id,
            'currency_code' => 'USD',
            'amount_currency' => '100.000000',
            'exchange_rate' => '3.5000000000',
            'carrying_value_base' => '350.000000',
            'status' => OpeningTradeItem::STATUS_POSTED,
        ]);
    }

    public function test_livewire_lost_ack_replay_succeeds_after_currency_disabled_while_conflict_and_new_events_fail(): void
    {
        CompanyCurrency::updateOrCreate([
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
        ], [
            'enabled' => true,
        ]);

        $key = 'lost-ack-livewire-key-1';

        // 1. Initial submission succeeds while currency is enabled
        $component = Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('formIdempotencyKey', $key)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('successMessage', __('accounting.success_customer', ['name' => $this->customer->name_ar]));

        $this->assertDatabaseHas('opening_trade_items', [
            'company_id' => $this->company->id,
            'idempotency_key' => $key,
            'currency_code' => 'USD',
            'amount_currency' => '100.000000',
            'status' => OpeningTradeItem::STATUS_POSTED,
        ]);

        // 2. Disable USD in company currency settings
        CompanyCurrency::where('company_id', $this->company->id)
            ->where('currency_code', 'USD')
            ->update(['enabled' => false]);

        // 3. Lost-ACK replay: same formIdempotencyKey with original payload succeeds without fresh currency block
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('formIdempotencyKey', $key)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('successMessage', __('accounting.success_customer', ['name' => $this->customer->name_ar]));

        // 4. Same formIdempotencyKey with CHANGED payload conflicts and fails
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('formIdempotencyKey', $key)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '200.00') // altered amount
            ->call('save')
            ->assertSet('errorMessage', "Opening trade item idempotency key [{$key}] was already used with a different economic payload.");

        // 5. New event with disabled currency fails fresh currency validation
        $newKey = 'new-event-disabled-usd';
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('formIdempotencyKey', $newKey)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasErrors(['amount']);
    }

    public function test_livewire_multi_row_lost_ack_replay_and_mixed_partial_conflict(): void
    {
        CompanyCurrency::updateOrCreate([
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
        ], [
            'enabled' => true,
        ]);

        $multiKey = 'multi-row-lost-ack-key';

        // Initial multi-row submission
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('formIdempotencyKey', $multiKey)
            ->set('side', 'vendor')
            ->set('party_id', $this->testVendor->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '200.00')
            ->call('addAdditionalLine')
            ->set('additional_lines.0.amount', '100.00')
            ->call('save')
            ->assertHasNoErrors();

        // Disable USD
        CompanyCurrency::where('company_id', $this->company->id)
            ->where('currency_code', 'USD')
            ->update(['enabled' => false]);

        // Replay identical multi-row submission succeeds
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('formIdempotencyKey', $multiKey)
            ->set('side', 'vendor')
            ->set('party_id', $this->testVendor->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '200.00')
            ->call('addAdditionalLine')
            ->set('additional_lines.0.amount', '100.00')
            ->call('save')
            ->assertHasNoErrors();

        // Mixed partial-row / altered line conflict
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('formIdempotencyKey', $multiKey)
            ->set('side', 'vendor')
            ->set('party_id', $this->testVendor->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '200.00')
            ->call('addAdditionalLine')
            ->set('additional_lines.0.amount', '999.00') // altered additional line
            ->call('save')
            ->assertSet('errorMessage', "Opening trade item idempotency key [{$multiKey}:line:0] was already used with a different economic payload.");
    }

    public function test_whole_form_replay_row_set_count_mismatch_fails_atomically(): void
    {
        CompanyCurrency::updateOrCreate([
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
        ], [
            'enabled' => true,
        ]);

        $key = 'whole-form-rowset-count-key';

        // 1. Initial 2-row post: main + 1 additional line
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('formIdempotencyKey', $key)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '100.00')
            ->call('addAdditionalLine')
            ->set('additional_lines.0.amount', '50.00')
            ->call('save')
            ->assertHasNoErrors();

        $countItems = fn () => OpeningTradeItem::where('company_id', $this->company->id)
            ->where(function ($q) use ($key): void {
                $q->where('idempotency_key', $key)
                    ->orWhere('idempotency_key', 'like', $key.':line:%');
            })->count();

        $this->assertSame(2, $countItems());

        // 2. Replay with extra line appended (3 rows) fails with count mismatch and creates 0 new records
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('formIdempotencyKey', $key)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '100.00')
            ->call('addAdditionalLine')
            ->set('additional_lines.0.amount', '50.00')
            ->call('addAdditionalLine')
            ->set('additional_lines.1.amount', '25.00')
            ->call('save')
            ->assertSet('errorMessage', "Form idempotency key [{$key}] was already used with a different row set count: committed 2 rows, received 3.");

        $this->assertSame(2, $countItems());

        // 3. Replay with removed line (1 row) fails with count mismatch
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('formIdempotencyKey', $key)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '100.00')
            ->call('save')
            ->assertSet('errorMessage', "Form idempotency key [{$key}] was already used with a different row set count: committed 2 rows, received 1.");

        $this->assertSame(2, $countItems());
    }

    public function test_mixed_currency_and_rate_tampering_on_additional_lines_rejected(): void
    {
        CompanyCurrency::updateOrCreate([
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
        ], [
            'enabled' => true,
        ]);
        CompanyCurrency::updateOrCreate([
            'company_id' => $this->company->id,
            'currency_code' => 'JOD',
        ], [
            'enabled' => true,
        ]);

        // Main line is USD with rate 3.5; tampered additional line with JOD is rejected
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '100.00')
            ->call('addAdditionalLine')
            ->set('additional_lines.0.amount', '50.00')
            ->set('additional_lines.0.currency_code', 'JOD')
            ->call('save')
            ->assertHasErrors(['additional_lines.0.currency_code']);

        // Main line is USD with rate 3.5; tampered additional line with rate 5.0 is rejected
        Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', 'customer')
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '3.5000000000')
            ->set('amount', '100.00')
            ->call('addAdditionalLine')
            ->set('additional_lines.0.amount', '50.00')
            ->set('additional_lines.0.exchange_rate', '5.0000000000')
            ->call('save')
            ->assertHasErrors(['additional_lines.0.exchange_rate']);
    }

    public function test_livewire_public_methods_revalidate_permissions_on_every_interaction(): void
    {
        // Create user with explicit permissions for vendor opening balance
        $member = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $member->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'VendorOpeningManager_'.Str::random(8),
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo(['customers.manage', 'vendors.manage', 'settings.accounting.manage', 'purchasing.cost.view']);
        $member->assignRole($role);
        $this->activateUser($member);

        // Mount component successfully
        $component = Livewire::actingAs($member)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', 'vendor')
            ->set('party_id', $this->testVendor->id);

        // Revoke purchasing.cost.view mid-session
        $role->revokePermissionTo('purchasing.cost.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $member->unsetRelation('roles');
        $member->unsetRelation('permissions');
        auth()->setUser($member);

        // 1. checkGlOverlap aborts 403 via Livewire HTTP update
        $component->call('checkGlOverlap')->assertForbidden();

        // 2. Direct calls on mounted component instance verify fresh side permission revalidation on EVERY public method
        $instance = new OpeningTradeBalanceEntry;
        $instance->pageCompanyId = (int) $this->company->id;
        $instance->side = OpeningTradeItem::SIDE_VENDOR;
        $instance->party_id = $this->testVendor->id;

        foreach (['checkGlOverlap', 'updatedSide', 'updatedCurrencyCode', 'addAdditionalLine', 'getPartyName', 'render'] as $method) {
            try {
                $instance->{$method}();
                $this->fail("Method [{$method}] must throw AuthorizationException when purchasing.cost.view is revoked.");
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('vendor opening trade permissions', $e->getMessage());
            }
        }

        try {
            $instance->removeAdditionalLine(0);
            $this->fail('Method [removeAdditionalLine] must throw AuthorizationException when purchasing.cost.view is revoked.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('vendor opening trade permissions', $e->getMessage());
        }

        // 3. Re-grant purchasing.cost.view but revoke company membership: all methods deny on membership check
        $role->givePermissionTo('purchasing.cost.view');
        $this->company->memberships()->where('user_id', $member->id)->delete();
        $member->unsetRelation('memberships');

        try {
            $instance->checkGlOverlap();
            $this->fail('Method [checkGlOverlap] must throw AuthorizationException when company membership is revoked.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('membership', $e->getMessage());
        }

        // 4. Tenant change simulation: company context switched to foreign company denies access
        $foreignCompany = Company::create([
            'name_ar' => 'شركة أخرى',
            'name_en' => 'Other Company',
            'base_currency_code' => 'ILS',
            'timezone' => 'Asia/Gaza',
            'status' => 'active',
        ]);
        $foreignCompany->memberships()->create([
            'user_id' => $member->id,
            'status' => 'active',
            'is_owner' => true,
        ]);
        app(CompanyContext::class)->setCompany($foreignCompany, $member);

        try {
            $instance->checkGlOverlap();
            $this->fail('Method [checkGlOverlap] must throw AuthorizationException on tenant company context change.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Company context mismatch', $e->getMessage());
        }
    }

    public function test_form_carrying_value_preview_sums_per_row_six_place_values_before_display_rounding(): void
    {
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'minor_units' => 2, 'active' => true]);
        DB::table('company_currencies')->updateOrInsert(['company_id' => $this->company->id, 'currency_code' => 'USD'], ['enabled' => true]);

        // Rate is 0.0024999900
        // Row 1: 1.00 USD -> 1.00 * 0.0024999900 = 0.0024999900 -> 6-place: 0.002500
        // Row 2: 1.00 USD -> 1.00 * 0.0024999900 = 0.0024999900 -> 6-place: 0.002500
        // Sum of per-row 6-place values: 0.002500 + 0.002500 = 0.005000
        // Display rounding (2-place): 0.005000 -> 0.01
        $component = Livewire::actingAs($this->owner)
            ->test(OpeningTradeBalanceEntry::class)
            ->set('side', OpeningTradeItem::SIDE_CUSTOMER)
            ->set('party_id', $this->customer->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '0.0024999900')
            ->set('amount', '1.00')
            ->set('has_multiple_lines', true)
            ->set('additional_lines', [
                [
                    'amount' => '1.00',
                    'currency_code' => 'USD',
                    'original_reference' => 'LINE-2',
                    'due_date' => '',
                    'notes' => '',
                ],
            ]);

        $component->assertSee('≈ 0.01 ILS');
    }
}
