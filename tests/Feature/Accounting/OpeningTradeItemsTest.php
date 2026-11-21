<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Actions\Accounting\PostOpeningBalancesAction;
use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Actions\Accounting\ReverseOpeningTradeItemAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Application\Platform\TenantWriteAccess;
use App\Domain\Accounting\Exceptions\ActiveSettlementBlockedException;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Accounting\Exceptions\LegacyGlOpeningOverlapException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Domain\Posting\Exceptions\IdempotencyConflictException;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\LedgerAccount;
use App\Models\OpeningTradeItem;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Accounting\GenericOpeningPostingScope;
use App\Services\Accounting\OpeningTradeCutoverAuditService;
use App\Services\Accounting\OpeningTradeEventScope;
use App\Services\Accounting\OpeningTradePostingCapability;
use App\Services\Posting\AccountingPostingService;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Phase8\TradeTestCase;
use Tests\Support\Phase11CsrfTestMiddleware;

final class OpeningTradeItemsTest extends TradeTestCase
{
    private Customer $customer;

    private Vendor $testVendor;

    /** @var array{id: string, token: string} */
    private array $deviceSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateUser($this->owner);
        $this->customer = $this->defaultCustomer;
        $this->testVendor = $this->vendor;

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'commands.api_enabled' => true,
            'session.driver' => 'database',
            'session.connection' => null,
        ]);
        $this->app->bind(PreventRequestForgery::class, Phase11CsrfTestMiddleware::class);
        $this->app->bind(ValidateCsrfToken::class, Phase11CsrfTestMiddleware::class);
        $this->deviceSession = $this->createDeviceSession($this->owner, $this->company);
    }

    private function createDeviceSession(User $actor, Company $company): array
    {
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        auth()->forgetGuards();
        $device = ['id' => Str::random(40), 'token' => Str::random(40)];
        DB::table('sessions')->insert([
            'id' => $device['id'],
            'user_id' => $actor->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'SyntheticTestAgent',
            'last_activity' => now()->timestamp,
            'payload' => base64_encode(serialize([
                auth()->guard('web')->getName() => $actor->id,
                'password_hash_web' => $actor->getAuthPassword(),
                '_token' => $device['token'],
                'active_company_id' => $company->id,
                'phase11_session_started_at' => now()->timestamp,
            ])),
        ]);

        return $device;
    }

    public function test_can_post_customer_opening_trade_item_with_canonical_gl_entries(): void
    {
        $action = app(PostOpeningTradeItemAction::class);

        $item = $action->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '1000.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-cust-1',
            originalReference: 'INIT-CUST-001',
            dueDate: '2026-01-15',
            notes: 'Opening customer receivable',
        );

        $this->assertInstanceOf(OpeningTradeItem::class, $item);
        $this->assertSame(OpeningTradeItem::STATUS_POSTED, $item->status);
        $this->assertSame('customer', $item->party_type);
        $this->assertSame($this->customer->id, $item->party_id);
        $this->assertTrue(BigDecimal::of($item->amount)->isEqualTo('1000.00'));
        $this->assertSame('1000.000000', (string) $item->base_carrying_value);
        $this->assertNotNull($item->posting_batch_id);

        // Verify GL batch
        $batch = PostingBatch::with('lines.account')->findOrFail($item->posting_batch_id);
        $this->assertSame('opening_trade_item', $batch->source_type);
        $this->assertCount(2, $batch->lines);

        $arLine = $batch->lines->firstWhere('account.system_key', 'accounts_receivable');
        $obeLine = $batch->lines->firstWhere('account.system_key', 'opening_balance_equity');

        $this->assertNotNull($arLine, 'Accounts Receivable line must exist');
        $this->assertNotNull($obeLine, 'Opening Balance Equity line must exist');

        // Dr AR 1000 / Cr OBE 1000
        $this->assertTrue(BigDecimal::of($arLine->debit_base)->isEqualTo(1000));
        $this->assertTrue(BigDecimal::of($arLine->credit_base)->isZero());
        $this->assertTrue(BigDecimal::of($obeLine->credit_base)->isEqualTo(1000));
        $this->assertTrue(BigDecimal::of($obeLine->debit_base)->isZero());

        // Verify party & company snapshot
        $this->assertSame($this->customer->name_ar, $item->party_name_snapshot);
        $this->assertSame($this->company->name_ar, $item->company_name_snapshot);

        // Verify immutability
        $this->expectException(\DomainException::class);
        $item->amount = '2000.00';
    }

    public function test_can_post_vendor_opening_trade_item_with_canonical_gl_entries(): void
    {
        $action = app(PostOpeningTradeItemAction::class);

        $item = $action->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_VENDOR,
            partyId: $this->testVendor->id,
            openingDate: '2026-01-01',
            amount: '750.50',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-vend-1',
            originalReference: 'INIT-VEND-001',
            dueDate: '2026-01-20',
        );

        $this->assertInstanceOf(OpeningTradeItem::class, $item);
        $this->assertSame(OpeningTradeItem::STATUS_POSTED, $item->status);
        $this->assertSame('vendor', $item->party_type);
        $this->assertSame($this->testVendor->id, $item->party_id);
        $this->assertTrue(BigDecimal::of($item->amount)->isEqualTo('750.50'));
        $this->assertSame('750.500000', (string) $item->base_carrying_value);

        // Verify GL batch
        $batch = PostingBatch::with('lines.account')->findOrFail($item->posting_batch_id);
        $this->assertSame('opening_trade_item', $batch->source_type);
        $this->assertCount(2, $batch->lines);

        $apLine = $batch->lines->firstWhere('account.system_key', 'accounts_payable');
        $obeLine = $batch->lines->firstWhere('account.system_key', 'opening_balance_equity');

        $this->assertNotNull($apLine, 'Accounts Payable line must exist');
        $this->assertNotNull($obeLine, 'Opening Balance Equity line must exist');

        // Dr OBE 750.50 / Cr AP 750.50
        $this->assertTrue(BigDecimal::of($obeLine->debit_base)->isEqualTo('750.50'));
        $this->assertTrue(BigDecimal::of($obeLine->credit_base)->isZero());
        $this->assertTrue(BigDecimal::of($apLine->credit_base)->isEqualTo('750.50'));
        $this->assertTrue(BigDecimal::of($apLine->debit_base)->isZero());
    }

    public function test_can_post_foreign_currency_opening_trade_item_with_frozen_rate(): void
    {
        $action = app(PostOpeningTradeItemAction::class);

        $item = $action->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '100.00',
            currencyCode: 'USD',
            exchangeRate: '3.5000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-foreign-1',
        );

        $this->assertTrue(BigDecimal::of($item->amount)->isEqualTo('100.00'));
        $this->assertSame('3.5000000000', (string) $item->exchange_rate);
        // 100 * 3.50 = 350.000000
        $this->assertSame('350.000000', (string) $item->base_carrying_value);

        $batch = PostingBatch::with('lines.account')->findOrFail($item->posting_batch_id);
        $arLine = $batch->lines->firstWhere('account.system_key', 'accounts_receivable');
        $obeLine = $batch->lines->firstWhere('account.system_key', 'opening_balance_equity');

        $this->assertTrue(BigDecimal::of($arLine->debit_base)->isEqualTo(350));
        $this->assertTrue(BigDecimal::of($obeLine->credit_base)->isEqualTo(350));
    }

    public function test_idempotency_key_replay_returns_existing_record_and_conflict_throws(): void
    {
        $action = app(PostOpeningTradeItemAction::class);

        $first = $action->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '500.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'replay-key-1',
        );

        $second = $action->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '500.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'replay-key-1',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->public_id, $second->public_id);

        // Different payload with same key throws conflict
        $this->expectException(IdempotencyConflictException::class);
        $action->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '600.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'replay-key-1',
        );
    }

    public function test_legacy_gl_opening_overlap_rejection(): void
    {
        // 1. Create a legacy opening balance journal that touched accounts_receivable
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
            'debit_base' => '5000.000000',
            'credit_base' => '0.000000',
            'transaction_currency_code' => 'ILS',
            'transaction_amount' => '5000.00',
            'exchange_rate' => '1.0000000000',
            'description' => 'Legacy generic AR balance',
        ]);

        PostingLine::create([
            'company_id' => $this->company->id,
            'posting_batch_id' => $batch->id,
            'line_number' => 2,
            'ledger_account_id' => $equityAccount->id,
            'debit_base' => '0.000000',
            'credit_base' => '5000.000000',
            'transaction_currency_code' => 'ILS',
            'transaction_amount' => '5000.00',
            'exchange_rate' => '1.0000000000',
            'description' => 'Legacy opening balance equity',
        ]);

        // 2. Attempting to post a customer opening trade item must now throw LegacyGlOpeningOverlapException
        $action = app(PostOpeningTradeItemAction::class);
        $this->expectException(LegacyGlOpeningOverlapException::class);

        $action->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '300.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-blocked-by-legacy',
        );
    }

    public function test_post_opening_balances_action_refuses_arap_when_opening_trade_items_exist(): void
    {
        // 1. Post an opening trade item
        $action = app(PostOpeningTradeItemAction::class);
        $action->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '250.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-item-for-gl-check',
        );

        // 2. Attempting to post GL opening balances containing AR or AP account must throw InvalidArgumentException
        $arAccount = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'accounts_receivable')
            ->firstOrFail();

        $equityAccount = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'opening_balance_equity')
            ->firstOrFail();

        $glAction = app(PostOpeningBalancesAction::class);
        $this->expectException(\InvalidArgumentException::class);

        $glAction->execute(
            company: $this->company,
            date: Carbon::parse('2026-01-01'),
            assetBalances: [
                [
                    'account_id' => $arAccount->id,
                    'amount' => '1000.00',
                ],
            ],
            liabilityBalances: [],
            equityBalances: [
                [
                    'account_id' => $equityAccount->id,
                    'amount' => '1000.00',
                ],
            ],
            postedBy: $this->owner,
            idempotencyKey: 'gl-with-ar-attempt'
        );
    }

    public function test_can_reverse_opening_trade_item(): void
    {
        $postAction = app(PostOpeningTradeItemAction::class);
        $item = $postAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '400.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'item-to-reverse',
        );

        $reverseAction = app(ReverseOpeningTradeItemAction::class);
        $reversed = $reverseAction->execute($item, $this->owner, 'Entered in error');

        $this->assertSame(OpeningTradeItem::STATUS_REVERSED, $reversed->status);
        $this->assertNotNull($reversed->reversed_at);
        $this->assertSame($this->owner->id, $reversed->reversed_by);
        $this->assertSame('Entered in error', $reversed->correction_reason);
        $this->assertNotNull($reversed->reversal_posting_batch_id);

        // Check reversal batch: Dr OBE 400 / Cr AR 400
        $revBatch = PostingBatch::with('lines.account')->findOrFail($reversed->reversal_posting_batch_id);
        $arLine = $revBatch->lines->firstWhere('account.system_key', 'accounts_receivable');
        $obeLine = $revBatch->lines->firstWhere('account.system_key', 'opening_balance_equity');

        $this->assertTrue(BigDecimal::of($obeLine->debit_base)->isEqualTo(400));
        $this->assertTrue(BigDecimal::of($arLine->credit_base)->isEqualTo(400));
    }

    public function test_reversal_is_blocked_when_active_settlements_exist(): void
    {
        $postAction = app(PostOpeningTradeItemAction::class);
        $item = $postAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '600.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'item-with-settlement',
        );

        // Post a customer payment with allocation to create an active settlement
        $this->activateUser($this->owner);
        $postPaymentAction = app(PostCustomerPaymentAction::class);
        $payment = $postPaymentAction->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-05',
            'payment_method' => CustomerPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '200.00',
            'idempotency_key' => 'pmt-active-settlement-block',
            'allocations' => [
                [
                    'kind' => 'opening_trade_item',
                    'opening_trade_item_id' => $item->id,
                    'allocated_amount' => '200.00',
                ],
            ],
        ]);

        $reverseAction = app(ReverseOpeningTradeItemAction::class);
        $this->expectException(ActiveSettlementBlockedException::class);
        $reverseAction->execute($item, $this->owner, 'Attempting reversal with allocation');
    }

    public function test_cutover_audit_service_and_api(): void
    {
        $postAction = app(PostOpeningTradeItemAction::class);
        $postAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '1200.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'audit-test-cust',
        );

        $auditService = app(OpeningTradeCutoverAuditService::class);
        $audit = $auditService->audit($this->company, $this->owner);

        $this->assertSame(1, $audit['customer_items_count']);
        $this->assertTrue(BigDecimal::of($audit['customer_items_total_base'])->isEqualTo('1200.000000'));
        $this->assertFalse($audit['has_legacy_gl_overlap']);

        // Test API endpoint with session cookie and CSRF token
        $response = $this->withCredentials()
            ->withCookie(config('session.cookie'), $this->deviceSession['id'])
            ->getJson("/api/v1/companies/{$this->company->id}/accounting/opening-trade-items/cutover-audit", [
                'X-CSRF-TOKEN' => $this->deviceSession['token'],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.customer_items_count', 1);
        $response->assertJsonPath('data.has_legacy_gl_overlap', false);
    }

    public function test_lookups_vendors_api_endpoint(): void
    {
        $response = $this->withCredentials()
            ->withCookie(config('session.cookie'), $this->deviceSession['id'])
            ->getJson("/api/v1/companies/{$this->company->id}/lookups/vendors?q=".urlencode(mb_substr($this->testVendor->name_ar, 0, 3)), [
                'X-CSRF-TOKEN' => $this->deviceSession['token'],
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'public_id', 'name_ar', 'name_en', 'phone', 'default_currency_code'],
            ],
        ]);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_opening_trade_items_api_preview_execute_and_reverse(): void
    {
        $headers = ['X-CSRF-TOKEN' => $this->deviceSession['token']];

        // Preview
        $previewResp = $this->withCredentials()
            ->withCookie(config('session.cookie'), $this->deviceSession['id'])
            ->postJson("/api/v1/companies/{$this->company->id}/accounting/opening-trade-items/preview", [
                'side' => 'customer',
                'party_id' => $this->customer->id,
                'opening_date' => '2026-01-01',
                'amount' => '500.00',
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
            ], $headers);

        $previewResp->assertStatus(200);
        $previewResp->assertJsonPath('data.base_carrying_value', '500.000000');
        $previewResp->assertJsonPath('data.has_gl_overlap', false);

        // Execute
        $execResp = $this->withCredentials()
            ->withCookie(config('session.cookie'), $this->deviceSession['id'])
            ->postJson("/api/v1/companies/{$this->company->id}/accounting/opening-trade-items/execute", [
                'side' => 'customer',
                'party_id' => $this->customer->id,
                'opening_date' => '2026-01-01',
                'amount' => '500.00',
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'api-exec-item-1',
            ], $headers);

        $execResp->assertStatus(201);
        $publicId = $execResp->json('data.public_id');
        $this->assertNotEmpty($publicId);

        // Reverse
        $revResp = $this->withCredentials()
            ->withCookie(config('session.cookie'), $this->deviceSession['id'])
            ->postJson("/api/v1/companies/{$this->company->id}/accounting/opening-trade-items/{$publicId}/reverse", [
                'reason' => 'Reversal via API test',
            ], $headers);

        $revResp->assertStatus(200);
        $revResp->assertJsonPath('data.status', 'reversed');
    }

    public function test_api_preview_is_read_only_and_confirmation_replay_is_payload_bound(): void
    {
        $url = "/api/v1/companies/{$this->company->id}/accounting/opening-trade-items";
        $headers = ['X-CSRF-TOKEN' => $this->deviceSession['token']];
        $payload = ['side' => 'customer', 'party_id' => $this->customer->id,
            'opening_date' => '2026-01-01', 'amount' => '100.00', 'currency_code' => 'USD',
            'exchange_rate' => '3.5', 'idempotency_key' => 'api-negative-replay'];
        $snapshot = function (): array {
            $rows = [];
            foreach (['opening_trade_items', 'posting_batches', 'posting_lines', 'audit_events', 'document_sequences'] as $table) {
                $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $rows;
        };
        $before = $snapshot();
        $this->withCredentials()->withCookie(config('session.cookie'), $this->deviceSession['id'])
            ->postJson($url.'/preview', $payload, $headers)->assertOk()->assertJsonPath('data.base_carrying_value', '350.000000');
        $this->assertSame($before, $snapshot());
        $first = $this->postJson($url.'/execute', $payload, $headers)->assertCreated();
        $posted = $snapshot();
        $this->postJson($url.'/execute', $payload, $headers)->assertCreated()->assertJsonPath('data.public_id', $first->json('data.public_id'));
        $this->assertSame($posted, $snapshot());
        $this->postJson($url.'/execute', array_replace($payload, ['amount' => '101']), $headers)->assertStatus(409);
        $this->assertSame($posted, $snapshot());
        foreach (['invalid', '0', '-1', '0.0000001', 1.25] as $amount) {
            $this->postJson($url.'/preview', array_replace($payload, ['amount' => $amount]), $headers)->assertStatus(422);
            $this->postJson($url.'/execute', array_replace($payload, ['amount' => $amount, 'idempotency_key' => 'invalid-api-'.$amount]), $headers)->assertStatus(422);
            $this->assertSame($posted, $snapshot());
        }
    }

    public function test_preview_and_execute_reject_overprecision_fx_for_both_sides(): void
    {
        $headers = ['X-CSRF-TOKEN' => $this->deviceSession['token']];
        foreach (['customer', 'vendor'] as $side) {
            foreach (['preview', 'execute'] as $operation) {
                $this->withCredentials()->withCookie(config('session.cookie'), $this->deviceSession['id'])
                    ->postJson("/api/v1/companies/{$this->company->id}/accounting/opening-trade-items/{$operation}", [
                        'side' => $side, 'party_id' => $side === 'customer' ? $this->customer->id : $this->vendor->id,
                        'amount' => '12', 'currency_code' => 'USD', 'exchange_rate' => '1.00000000001',
                        'opening_date' => '2026-01-01', 'idempotency_key' => 'invalid-fx-'.$side,
                    ], $headers)->assertStatus(422);
            }
        }
    }

    public function test_failure_after_persisted_audit_rolls_back_journal_and_all_provenance(): void
    {
        $tables = ['opening_trade_items', 'posting_batches', 'posting_lines', 'audit_events', 'document_sequences'];
        // Include complete rows: a consumed number is also a partial business event.
        $tables = array_values(array_filter($tables, fn ($table) => Schema::hasTable($table)));
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $dispatcher = OpeningTradeItem::getEventDispatcher();
        OpeningTradeItem::setEventDispatcher(clone $dispatcher);
        AuditEvent::created(function ($event): void {
            if ($event->event_key === 'accounting.opening_trade_item.posted') {
                $this->assertTrue(DB::table('posting_lines')->exists());
                $this->assertTrue(DB::table('audit_events')->where('id', $event->id)->exists());
                throw new \RuntimeException('Injected after persisted opening audit');
            }
        });
        try {
            app(PostOpeningTradeItemAction::class)->execute(
                company: $this->company, side: 'customer', partyId: $this->customer->id,
                openingDate: '2026-01-01', amount: '12.34', currencyCode: 'ILS',
                actor: $this->owner, idempotencyKey: 'fault-after-audit',
            );
            $this->fail('Injected fault must escape.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected after persisted opening audit', $e->getMessage());
        } finally {
            OpeningTradeItem::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_populated_opening_history_refuses_downgrade_before_ddl(): void
    {
        $item = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company, side: 'customer', partyId: $this->customer->id,
            openingDate: '2026-01-01', amount: '10', currencyCode: 'ILS',
            actor: $this->owner, idempotencyKey: 'downgrade-refusal',
        );
        $migration = require database_path('migrations/2026_11_20_000001_create_opening_trade_items_and_extend_allocations.php');
        $tables = ['opening_trade_items', 'customer_payment_allocations', 'vendor_payment_allocations', 'posting_batches', 'posting_lines', 'audit_events'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => [
            DB::table($table)->orderBy('id')->get()->toJson(),
            DB::select('SHOW CREATE TABLE '.$table)[0]->{'Create Table'},
        ]])->all();
        $before = $snapshot();
        try {
            $migration->down();
            $this->fail('Populated financial history must refuse downgrade.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot downgrade populated', $e->getMessage());
        }
        $this->assertSame($before, $snapshot());
        $this->assertDatabaseHas('opening_trade_items', ['id' => $item->id, 'posting_batch_id' => $item->posting_batch_id]);
        $this->assertTrue(Schema::hasColumn('customer_payment_allocations', 'opening_trade_item_id'));
    }

    public function test_public_scope_activation_cannot_forge_canonical_authority(): void
    {
        $scope = app(OpeningTradeEventScope::class);
        $this->expectException(ImmutableRecordException::class);
        $scope->withPostingCapability(
            app(PostOpeningTradeItemAction::class),
            new OpeningTradePostingCapability($this->company->id, $this->owner->id, 'customer', $this->customer->id),
            fn () => $this->fail('Forged authority must never execute.'),
        );
    }

    public function test_generic_opening_balance_requires_canonical_posting_scope(): void
    {
        $arAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_receivable')->firstOrFail();
        $obeAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'opening_balance_equity')->firstOrFail();

        $command = new PostingCommand(
            company: $this->company,
            postingDate: Carbon::parse('2026-01-01'),
            sourceType: 'opening_balance',
            sourceId: $this->company->id,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'direct-ob-bypass',
            postedBy: $this->owner,
            description: 'Direct opening balance bypass',
            lines: [
                PostingLineCommand::debit(1, $arAccount->id, MoneyAmount::from('100.00')),
                PostingLineCommand::credit(2, $obeAccount->id, MoneyAmount::from('100.00')),
            ],
        );

        $this->expectException(ImmutableRecordException::class);
        $this->expectExceptionMessage('Direct GL posting for opening_balance requires canonical PostOpeningBalancesAction authority.');
        app(AccountingPostingService::class)->post($command);
    }

    public function test_generic_opening_balance_guarded_against_existing_customer_and_vendor_opening_items(): void
    {
        $arAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_receivable')->firstOrFail();
        $apAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_payable')->firstOrFail();
        $obeAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'opening_balance_equity')->firstOrFail();
        $genericAction = app(PostOpeningBalancesAction::class);

        // 1. Post customer opening item
        app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company, side: 'customer', partyId: $this->customer->id,
            openingDate: '2026-01-01', amount: '250.00', currencyCode: 'ILS',
            actor: $this->owner, idempotencyKey: 'generic-guard-cust',
        );

        // Attempting generic opening balance touching AR must be rejected
        try {
            $genericAction->execute(
                company: $this->company,
                date: Carbon::parse('2026-01-01'),
                assetBalances: [['account_id' => $arAccount->id, 'amount' => '1000.00']],
                postedBy: $this->owner,
                idempotencyKey: 'generic-ob-ar-attempt',
            );
            $this->fail('Generic opening on AR must be rejected when customer opening trade items exist.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Cannot post generic opening balance to accounts_receivable when customer opening trade items exist', $e->getMessage());
        }

        // 2. Post vendor opening item
        app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company, side: 'vendor', partyId: $this->testVendor->id,
            openingDate: '2026-01-01', amount: '350.00', currencyCode: 'ILS',
            actor: $this->owner, idempotencyKey: 'generic-guard-vend',
        );

        // Attempting generic opening balance touching AP must be rejected
        try {
            $genericAction->execute(
                company: $this->company,
                date: Carbon::parse('2026-01-01'),
                liabilityBalances: [['account_id' => $apAccount->id, 'amount' => '1000.00']],
                postedBy: $this->owner,
                idempotencyKey: 'generic-ob-ap-attempt',
            );
            $this->fail('Generic opening on AP must be rejected when vendor opening trade items exist.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Cannot post generic opening balance to accounts_payable when vendor opening trade items exist', $e->getMessage());
        }
    }

    public function test_forged_generic_opening_scope_cannot_be_activated_by_an_idle_action(): void
    {
        $scope = app(GenericOpeningPostingScope::class);
        $this->expectException(ImmutableRecordException::class);
        $scope->withPostingCapability(app(PostOpeningBalancesAction::class), (int) $this->company->id, fn () => null);
    }

    public function test_generic_opening_rejects_previously_committed_trade_opening(): void
    {
        $arAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_receivable')->firstOrFail();
        $genericAction = app(PostOpeningBalancesAction::class);
        $openingAction = app(PostOpeningTradeItemAction::class);

        // Hold company lock in a transaction and post customer opening item
        app(TenantWriteAccess::class)->transaction((int) $this->company->id, function () use ($openingAction) {
            Company::where('id', $this->company->id)->lockForUpdate()->firstOrFail();

            $openingAction->execute(
                company: $this->company, side: 'customer', partyId: $this->customer->id,
                openingDate: '2026-01-01', amount: '500.00', currencyCode: 'ILS',
                actor: $this->owner, idempotencyKey: 'race-test-item',
            );
        });

        // Now, generic opening balance rechecks under lock and throws
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot post generic opening balance to accounts_receivable when customer opening trade items exist');
        $genericAction->execute(
            company: $this->company,
            date: Carbon::parse('2026-01-01'),
            assetBalances: [['account_id' => $arAccount->id, 'amount' => '700.00']],
            postedBy: $this->owner,
            idempotencyKey: 'race-test-generic',
        );
    }
}
