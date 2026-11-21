<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Actions\Accounting\PostOpeningBalancesAction;
use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Actions\Accounting\ReverseOpeningTradeItemAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Application\Platform\TenantWriteAccess;
use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Support\TradeOpenPositions;
use App\Domain\Accounting\Exceptions\ActiveSettlementBlockedException;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Accounting\Exceptions\LegacyGlOpeningOverlapException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Domain\Posting\Exceptions\IdempotencyConflictException;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\LedgerAccount;
use App\Models\OpeningTradeItem;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\GenericOpeningPostingScope;
use App\Services\Accounting\OpeningTradeCutoverAuditService;
use App\Services\Accounting\OpeningTradeEventScope;
use App\Services\Accounting\OpeningTradePostingCapability;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
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

    public function test_new_amounts_follow_fresh_currency_policy_but_receipts_replay_immutable_economics(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            foreach ([['ILS', 2, '1.001', '1.01'], ['USD', 2, '1.001', '1.01'], ['JOD', 3, '1.0001', '1.001'], ['JPY', 0, '1.1', '1']] as [$code, $scale, $invalid, $valid]) {
                DB::table('currencies')->updateOrInsert(['code' => $code], ['name' => $code, 'symbol' => $code, 'minor_units' => $scale, 'active' => true]);
                DB::table('company_currencies')->updateOrInsert(['company_id' => $this->company->id, 'currency_code' => $code], ['enabled' => true]);
                $args = [
                    'company' => $this->company,
                    'side' => $side,
                    'partyId' => $side === 'customer' ? $this->customer->id : $this->testVendor->id,
                    'openingDate' => '2026-01-01',
                    'amount' => $invalid,
                    'currencyCode' => $code,
                    'exchangeRate' => '1',
                    'actor' => $this->owner,
                    'idempotencyKey' => 'precision-invalid-'.$side.'-'.$code,
                ];
                $batchCount = PostingBatch::count();
                $auditCount = AuditEvent::count();
                $itemCount = OpeningTradeItem::count();

                // 1. Overprecision rejected before numbering/journals/audit
                try {
                    app(PostOpeningTradeItemAction::class)->execute(...$args);
                    $this->fail('Overprecision accepted for currency '.$code);
                } catch (\InvalidArgumentException) {
                    $this->assertSame($batchCount, PostingBatch::count());
                    $this->assertSame($auditCount, AuditEvent::count());
                    $this->assertSame($itemCount, OpeningTradeItem::count());
                }

                // 2. Valid precision accepted and posted
                $args['amount'] = $valid;
                $args['idempotencyKey'] = 'precision-valid-'.$side.'-'.$code;
                $item = app(PostOpeningTradeItemAction::class)->execute(...$args);
                $this->assertSame($batchCount + 1, PostingBatch::count());
                $this->assertSame($auditCount + 1, AuditEvent::count());
                $this->assertSame($itemCount + 1, OpeningTradeItem::count());
                $snapshot = $item->fresh()->getAttributes();

                // 3. Currency policy retired/disabled
                DB::table('currencies')->where('code', $code)->update(['active' => false]);
                DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', $code)->update(['enabled' => false]);

                // 4. Identical idempotency replay returns immutable receipt snapshot
                $replayed = app(PostOpeningTradeItemAction::class)->execute(...$args);
                $this->assertSame($item->id, $replayed->id);
                $this->assertSame($snapshot, $replayed->getAttributes());
                $this->assertSame($snapshot, $item->fresh()->getAttributes());

                // 5. New item on retired currency rejected without side effects
                $args['idempotencyKey'] .= '-new';
                try {
                    app(PostOpeningTradeItemAction::class)->execute(...$args);
                    $this->fail('Retired currency accepted for new item '.$code);
                } catch (\InvalidArgumentException) {
                    $this->assertSame($batchCount + 1, PostingBatch::count());
                    $this->assertSame($auditCount + 1, AuditEvent::count());
                    $this->assertSame($itemCount + 1, OpeningTradeItem::count());
                }

                // Restore currency policy so subsequent currencies have active base currency
                DB::table('currencies')->where('code', $code)->update(['active' => true]);
                DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', $code)->update(['enabled' => true]);
            }

            // 6. Missing and disabled currency configuration rejected
            foreach (['MIS', 'DIS'] as $currCode) {
                if ($currCode === 'DIS') {
                    DB::table('currencies')->updateOrInsert(['code' => $currCode], ['name' => $currCode, 'symbol' => $currCode, 'minor_units' => 2, 'active' => true]);
                    DB::table('company_currencies')->updateOrInsert(['company_id' => $this->company->id, 'currency_code' => $currCode], ['enabled' => false]);
                }
                $batchCount = PostingBatch::count();
                $auditCount = AuditEvent::count();
                $itemCount = OpeningTradeItem::count();
                try {
                    app(PostOpeningTradeItemAction::class)->execute(
                        company: $this->company,
                        side: $side,
                        partyId: $side === 'customer' ? $this->customer->id : $this->testVendor->id,
                        openingDate: '2026-01-01',
                        amount: '10.00',
                        currencyCode: $currCode,
                        exchangeRate: '1',
                        actor: $this->owner,
                        idempotencyKey: 'cfg-fail-'.$side.'-'.$currCode,
                    );
                    $this->fail('Missing or disabled currency accepted');
                } catch (\InvalidArgumentException) {
                    $this->assertSame($batchCount, PostingBatch::count());
                    $this->assertSame($auditCount, AuditEvent::count());
                    $this->assertSame($itemCount, OpeningTradeItem::count());
                }
            }
        }

        // Re-enable ILS and USD so reconciliation can inspect clean standard tenant
        DB::table('currencies')->whereIn('code', ['ILS', 'USD'])->update(['active' => true]);
        DB::table('company_currencies')->where('company_id', $this->company->id)->whereIn('currency_code', ['ILS', 'USD'])->update(['enabled' => true]);
        $this->assertAllSixReconciliations();
    }

    private function assertAllSixReconciliations(): void
    {
        foreach ([
            AccountingReconciliationService::class,
            SalesReconciliationService::class,
            PayablesReconciliationService::class,
            MoneyReconciliationService::class,
            Phase7ReconciliationService::class,
        ] as $service) {
            $report = app($service)->reconcile($this->company);
            $this->assertTrue($report->isHealthy, $service.': '.json_encode($report));
        }
        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($report->isHealthy, json_encode($report));
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
        foreach (['invalid', '0', '-1', '0.0000001', '1.001', 1.25] as $amount) {
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

    public function test_direct_creation_of_posted_opening_trade_item_without_canonical_capability_is_rejected(): void
    {
        $this->expectException(ImmutableRecordException::class);
        $this->expectExceptionMessage('Direct creation of posted opening trade item is prohibited outside canonical posting action.');

        OpeningTradeItem::create([
            'company_id' => $this->company->id,
            'public_id' => (string) Str::ulid(),
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->customer->id,
            'opening_date' => '2026-01-01',
            'currency_code' => 'ILS',
            'amount_currency' => '100.000000',
            'exchange_rate' => '1.0000000000',
            'carrying_value_base' => '100.000000',
            'status' => OpeningTradeItem::STATUS_POSTED,
            'created_by' => $this->owner->id,
            'source_identity' => 'direct_bypass',
            'idempotency_key' => 'direct-bypass-create',
            'idempotency_payload_hash' => hash('sha256', 'bypass'),
        ]);
    }

    public function test_direct_creation_of_reversed_opening_trade_item_is_rejected(): void
    {
        $this->expectException(ImmutableRecordException::class);
        $this->expectExceptionMessage('Direct creation of reversed opening trade item is prohibited.');

        OpeningTradeItem::create([
            'company_id' => $this->company->id,
            'public_id' => (string) Str::ulid(),
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->customer->id,
            'opening_date' => '2026-01-01',
            'currency_code' => 'ILS',
            'amount_currency' => '100.000000',
            'exchange_rate' => '1.0000000000',
            'carrying_value_base' => '100.000000',
            'status' => OpeningTradeItem::STATUS_REVERSED,
            'created_by' => $this->owner->id,
            'source_identity' => 'direct_bypass_reversed',
            'idempotency_key' => 'direct-bypass-create-rev',
            'idempotency_payload_hash' => hash('sha256', 'bypass-rev'),
        ]);
    }

    public function test_direct_status_mutation_from_posted_to_reversed_bypassing_canonical_action_is_rejected(): void
    {
        $item = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '150.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: 'lifecycle-direct-save-bypass',
        );

        $this->expectException(ImmutableRecordException::class);
        $this->expectExceptionMessage('Direct status transition to reversed is prohibited outside canonical reversal action.');

        $item->status = OpeningTradeItem::STATUS_REVERSED;
        $item->save();
    }

    public function test_direct_status_mutation_with_forged_reversal_metadata_without_reversal_batch_is_rejected(): void
    {
        $item = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_VENDOR,
            partyId: $this->testVendor->id,
            openingDate: '2026-01-01',
            amount: '220.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: 'lifecycle-forged-meta-bypass',
        );

        // Attempt direct save with status=reversed and dummy metadata
        $item->status = OpeningTradeItem::STATUS_REVERSED;
        $item->reversed_at = now();
        $item->reversed_by = $this->owner->id;
        $item->correction_reason = 'Forged metadata attempt';
        $item->reversal_posting_batch_id = 999999;

        $this->expectException(ImmutableRecordException::class);
        $this->expectExceptionMessage('Direct status transition to reversed is prohibited outside canonical reversal action.');

        $item->save();
    }

    public function test_economic_attributes_and_reversal_immutability(): void
    {
        $item = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '300.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: 'economic-immutability-test',
        );

        try {
            $item->amount_currency = '400.000000';
            $this->fail('Direct modification of amount_currency must throw ImmutableRecordException');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $item->delete();
            $this->fail('Direct deletion of opening trade item must throw ImmutableRecordException');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        $reversed = app(ReverseOpeningTradeItemAction::class)->execute(
            item: $item,
            actor: $this->owner,
            reason: 'Valid correction reason',
            reversalDate: '2026-01-02',
        );
        $this->assertTrue($reversed->isReversed());

        try {
            $reversed->status = OpeningTradeItem::STATUS_POSTED;
            $this->fail('Direct mutation of reversed item must throw ImmutableRecordException');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_api_preview_requires_side_specific_permissions_before_overlap_disclosure(): void
    {
        $unauthorizedUser = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $unauthorizedUser->id,
            'status' => 'active',
            'is_owner' => false,
        ]);

        $deviceSession = $this->createDeviceSession($unauthorizedUser, $this->company);

        $headers = ['X-CSRF-TOKEN' => $deviceSession['token']];

        // 1. Customer side preview without permissions returns 403 and does not disclose GL overlap
        $responseCust = $this->withCredentials()
            ->withCookie(config('session.cookie'), $deviceSession['id'])
            ->postJson("/api/v1/companies/{$this->company->id}/accounting/opening-trade-items/preview", [
                'side' => 'customer',
                'party_id' => $this->customer->id,
                'amount' => '100.00',
                'currency_code' => 'ILS',
            ], $headers);

        $responseCust->assertStatus(403);
        $this->assertArrayNotHasKey('has_gl_overlap', $responseCust->json('data') ?? []);

        // 2. Vendor side preview without permissions returns 403 and does not disclose GL overlap
        $responseVend = $this->withCredentials()
            ->withCookie(config('session.cookie'), $deviceSession['id'])
            ->postJson("/api/v1/companies/{$this->company->id}/accounting/opening-trade-items/preview", [
                'side' => 'vendor',
                'party_id' => $this->testVendor->id,
                'amount' => '100.00',
                'currency_code' => 'ILS',
            ], $headers);

        $responseVend->assertStatus(403);
        $this->assertArrayNotHasKey('has_gl_overlap', $responseVend->json('data') ?? []);
    }

    public function test_api_preview_rejects_base_currency_exchange_rate_different_from_one_for_both_sides(): void
    {
        $headers = ['X-CSRF-TOKEN' => $this->deviceSession['token']];

        // 1. Customer side: base currency ILS with exchange_rate = 2 returns 422
        $responseCust = $this->withCredentials()
            ->withCookie(config('session.cookie'), $this->deviceSession['id'])
            ->postJson("/api/v1/companies/{$this->company->id}/accounting/opening-trade-items/preview", [
                'side' => 'customer',
                'party_id' => $this->customer->id,
                'amount' => '100.00',
                'currency_code' => 'ILS',
                'exchange_rate' => '2.0000000000',
            ], $headers);

        $responseCust->assertStatus(422);
        $responseCust->assertJsonPath('detail', 'The given data failed validation.');

        // 2. Vendor side: base currency ILS with exchange_rate = 2 returns 422
        $responseVend = $this->withCredentials()
            ->withCookie(config('session.cookie'), $this->deviceSession['id'])
            ->postJson("/api/v1/companies/{$this->company->id}/accounting/opening-trade-items/preview", [
                'side' => 'vendor',
                'party_id' => $this->testVendor->id,
                'amount' => '100.00',
                'currency_code' => 'ILS',
                'exchange_rate' => '2.0000000000',
            ], $headers);

        $responseVend->assertStatus(422);
        $responseVend->assertJsonPath('detail', 'The given data failed validation.');
    }

    public function test_creation_omitting_status_throws_immutable_record_exception(): void
    {
        $this->expectException(ImmutableRecordException::class);
        $this->expectExceptionMessage('Creation of opening trade item requires canonical action capability with explicit posted status.');

        OpeningTradeItem::create([
            'company_id' => $this->company->id,
            'public_id' => (string) Str::ulid(),
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->customer->id,
            'opening_date' => '2026-01-01',
            'currency_code' => 'ILS',
            'amount_currency' => '100.000000',
            'exchange_rate' => '1.0000000000',
            'carrying_value_base' => '100.000000',
            'created_by' => $this->owner->id,
            'source_identity' => 'direct_omitted_status',
            'idempotency_key' => 'direct-omitted-status',
            'idempotency_payload_hash' => hash('sha256', 'bypass'),
        ]);
    }

    public function test_creation_with_null_draft_or_unsupported_status_throws(): void
    {
        foreach ([null, 'draft', 'pending', 'reversed'] as $badStatus) {
            try {
                OpeningTradeItem::create([
                    'company_id' => $this->company->id,
                    'public_id' => (string) Str::ulid(),
                    'side' => OpeningTradeItem::SIDE_CUSTOMER,
                    'customer_id' => $this->customer->id,
                    'opening_date' => '2026-01-01',
                    'currency_code' => 'ILS',
                    'amount_currency' => '100.000000',
                    'exchange_rate' => '1.0000000000',
                    'carrying_value_base' => '100.000000',
                    'status' => $badStatus,
                    'created_by' => $this->owner->id,
                    'source_identity' => 'direct_bad_status',
                    'idempotency_key' => 'direct-bad-status-'.(string) $badStatus,
                    'idempotency_payload_hash' => hash('sha256', 'bypass-'.(string) $badStatus),
                ]);
                $this->fail("Creation with status [{$badStatus}] must throw ImmutableRecordException.");
            } catch (ImmutableRecordException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_creation_with_non_null_posting_batch_or_reversal_metadata_throws(): void
    {
        // 1. non-null posting_batch_id
        try {
            OpeningTradeItem::create([
                'company_id' => $this->company->id,
                'public_id' => (string) Str::ulid(),
                'side' => OpeningTradeItem::SIDE_CUSTOMER,
                'customer_id' => $this->customer->id,
                'opening_date' => '2026-01-01',
                'currency_code' => 'ILS',
                'amount_currency' => '100.000000',
                'exchange_rate' => '1.0000000000',
                'carrying_value_base' => '100.000000',
                'status' => OpeningTradeItem::STATUS_POSTED,
                'posting_batch_id' => 999,
                'created_by' => $this->owner->id,
                'source_identity' => 'direct_posting_batch',
                'idempotency_key' => 'direct-posting-batch',
                'idempotency_payload_hash' => hash('sha256', 'bypass-batch'),
            ]);
            $this->fail('Creation with non-null posting_batch_id must throw.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('canonical posting capability', $e->getMessage());
        }

        // 2. non-null reversal metadata
        try {
            OpeningTradeItem::create([
                'company_id' => $this->company->id,
                'public_id' => (string) Str::ulid(),
                'side' => OpeningTradeItem::SIDE_CUSTOMER,
                'customer_id' => $this->customer->id,
                'opening_date' => '2026-01-01',
                'currency_code' => 'ILS',
                'amount_currency' => '100.000000',
                'exchange_rate' => '1.0000000000',
                'carrying_value_base' => '100.000000',
                'status' => OpeningTradeItem::STATUS_POSTED,
                'reversal_posting_batch_id' => 888,
                'created_by' => $this->owner->id,
                'source_identity' => 'direct_reversal_meta',
                'idempotency_key' => 'direct-reversal-meta',
                'idempotency_payload_hash' => hash('sha256', 'bypass-rev'),
            ]);
            $this->fail('Creation with non-null reversal_posting_batch_id must throw.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('Reversal attributes cannot be populated on creation', $e->getMessage());
        }
    }

    public function test_mutating_reversal_metadata_while_status_is_posted_throws(): void
    {
        $item = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '120.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: 'reversal-meta-mutation-test',
        );

        // Mutating reversal_posting_batch_id while status remains posted throws
        try {
            $item->reversal_posting_batch_id = 999;
            $item->save();
            $this->fail('Setting reversal_posting_batch_id on posted item must throw.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('canonical transition to reversed', $e->getMessage());
        }

        // Mutating reversed_at while status remains posted throws
        try {
            $item->reversed_at = now();
            $item->save();
            $this->fail('Setting reversed_at on posted item must throw.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('canonical transition to reversed', $e->getMessage());
        }

        // Mutating correction_reason while status remains posted throws
        try {
            $item->correction_reason = 'Fake reason';
            $item->save();
            $this->fail('Setting correction_reason on posted item must throw.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('canonical transition to reversed', $e->getMessage());
        }
    }

    public function test_statement_queries_and_open_positions_ignore_non_canonical_reversals(): void
    {
        $custItem = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '350.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: 'cust-statement-canonical-test',
        );

        $vendItem = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_VENDOR,
            partyId: $this->testVendor->id,
            openingDate: '2026-01-01',
            amount: '450.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: 'vend-statement-canonical-test',
        );

        // Activate owner and ensure statement query permissions
        $this->activateUser($this->owner);
        $this->owner->givePermissionTo(['vendors.statement.view', 'purchasing.cost.view']);
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');

        // Verify statements before reversal include only the opening entry
        $custStmt = (new CustomerStatementQuery)->execute($this->customer);
        $custEntries = collect($custStmt['currencies']['ILS']['entries'] ?? []);
        $this->assertTrue($custEntries->contains('type', 'opening_balance'));
        $this->assertFalse($custEntries->contains('type', 'reversal'));

        $vendStmt = (new VendorStatementQuery)->execute($this->testVendor);
        $vendEntries = collect($vendStmt['currencies']['ILS']['entries'] ?? []);
        $this->assertTrue($vendEntries->contains('type', 'opening_balance'));
        $this->assertFalse($vendEntries->contains('type', 'reversal'));

        // Test incoherent / non-canonical reversal link on vendor item:
        // An unposted or unlinked reversal batch must NOT cause the item to be treated as reversed on statements or open positions.
        $unpostedBatch = new PostingBatch([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'source_type' => 'opening_trade_item',
            'source_id' => $vendItem->id,
            'posting_date' => '2026-01-05',
            'posted_at' => '2026-01-05 00:00:00',
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'status' => 'draft', // Unposted!
            'idempotency_key' => 'unposted-rev-batch-'.Str::ulid(),
            'description' => 'Unposted reversal attempt',
            'posted_by' => $this->owner->id,
            'created_by' => $this->owner->id,
        ]);
        $unpostedBatch->saveQuietly();

        DB::table('opening_trade_items')
            ->where('id', $vendItem->id)
            ->update([
                'status' => OpeningTradeItem::STATUS_REVERSED,
                'reversal_posting_batch_id' => $unpostedBatch->id,
            ]);

        $incoherentVend = $vendItem->fresh();
        $this->assertFalse($incoherentVend->hasCanonicalReversal());
        $this->assertTrue(BigDecimal::of('450.00')->isEqualTo($incoherentVend->calculateOutstanding()));

        // Vendor statement must NOT include a reversal entry for non-canonical reversal
        $vendStmtIncoherent = (new VendorStatementQuery)->execute($this->testVendor);
        $vendEntriesIncoherent = collect($vendStmtIncoherent['currencies']['ILS']['entries'] ?? []);
        $this->assertFalse($vendEntriesIncoherent->contains('type', 'reversal'));

        // Open positions must STILL include the vendor item
        $openPositionsIncoherent = TradeOpenPositions::read(
            $this->company,
            new ReportFilters(period: ReportPeriod::custom('2026-01-01', '2026-01-10', $this->company)),
            vendor: true,
            aging: false,
            overdue: false,
        );
        $itemsIncoherent = collect($openPositionsIncoherent['rows'] ?? []);
        $this->assertTrue($itemsIncoherent->contains('opening_trade_item_id', $vendItem->id));

        // Restore vendItem to posted state so it can be tested canonically
        DB::table('opening_trade_items')
            ->where('id', $vendItem->id)
            ->update([
                'status' => OpeningTradeItem::STATUS_POSTED,
                'reversal_posting_batch_id' => null,
            ]);

        // Canonical reversal
        $reversedCust = app(ReverseOpeningTradeItemAction::class)->execute(
            item: $custItem,
            actor: $this->owner,
            reason: 'Legitimate correction',
            reversalDate: '2026-01-02',
        );
        $this->assertTrue($reversedCust->hasCanonicalReversal());

        // Now statement includes REV line
        $custStmtAfter = (new CustomerStatementQuery)->execute($this->customer);
        $custTxAfter = collect($custStmtAfter['currencies']['ILS']['entries'] ?? []);
        $this->assertTrue($custTxAfter->contains('type', 'reversal'));
    }

    public function test_event_suppressed_and_bulk_orm_operations_are_strictly_rejected(): void
    {
        $item = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '200.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: 'persistence-guards-test',
        );

        // 1. Direct model saveQuietly on new item without canonical action authority throws
        try {
            $unauthorizedItem = new OpeningTradeItem([
                'company_id' => $this->company->id,
                'public_id' => (string) Str::ulid(),
                'side' => OpeningTradeItem::SIDE_CUSTOMER,
                'customer_id' => $this->customer->id,
                'opening_date' => '2026-01-01',
                'currency_code' => 'ILS',
                'amount_currency' => '100.00',
                'exchange_rate' => '1.0000000000',
                'carrying_value_base' => '100.00',
                'status' => OpeningTradeItem::STATUS_POSTED,
                'created_by' => $this->owner->id,
                'source_identity' => 'test',
                'idempotency_key' => 'quiet-create',
                'idempotency_payload_hash' => hash('sha256', 'quiet'),
            ]);
            $unauthorizedItem->saveQuietly();
            $this->fail('saveQuietly creation without canonical capability must throw.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('outside canonical posting action', $e->getMessage());
        }

        // 2. withoutEvents creation throws
        try {
            OpeningTradeItem::withoutEvents(function () {
                $unauthorizedItem = new OpeningTradeItem([
                    'company_id' => $this->company->id,
                    'public_id' => (string) Str::ulid(),
                    'side' => OpeningTradeItem::SIDE_CUSTOMER,
                    'customer_id' => $this->customer->id,
                    'opening_date' => '2026-01-01',
                    'currency_code' => 'ILS',
                    'amount_currency' => '100.00',
                    'exchange_rate' => '1.0000000000',
                    'carrying_value_base' => '100.00',
                    'status' => OpeningTradeItem::STATUS_POSTED,
                    'created_by' => $this->owner->id,
                    'source_identity' => 'test',
                    'idempotency_key' => 'without-events-create',
                    'idempotency_payload_hash' => hash('sha256', 'without-events'),
                ]);
                $unauthorizedItem->save();
            });
            $this->fail('withoutEvents creation must throw.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('outside canonical posting action', $e->getMessage());
        }

        // 3. Bulk update on Eloquent query throws
        try {
            OpeningTradeItem::where('id', $item->id)->update(['notes' => 'Attempted direct bulk update']);
            $this->fail('Bulk update via Eloquent query must throw.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('Direct bulk updates on opening trade items are prohibited', $e->getMessage());
        }

        // 4. Bulk delete on Eloquent query throws
        try {
            OpeningTradeItem::where('id', $item->id)->delete();
            $this->fail('Bulk delete via Eloquent query must throw.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        // 5. Model delete() throws
        try {
            $item->delete();
            $this->fail('Direct model delete() must throw.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        // 6. Bulk inserts and upserts throw
        try {
            OpeningTradeItem::query()->insert([
                'public_id' => (string) Str::ulid(),
                'company_id' => $this->company->id,
                'side' => OpeningTradeItem::SIDE_CUSTOMER,
                'customer_id' => $this->customer->id,
                'opening_date' => '2026-01-01',
                'currency_code' => 'ILS',
                'amount_currency' => '100.00',
                'exchange_rate' => '1.0000000000',
                'carrying_value_base' => '100.00',
                'status' => OpeningTradeItem::STATUS_POSTED,
                'created_by' => $this->owner->id,
                'source_identity' => 'test',
                'idempotency_key' => 'bulk-insert',
                'idempotency_payload_hash' => hash('sha256', 'bulk-insert'),
            ]);
            $this->fail('Bulk insert must throw ImmutableRecordException.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('Direct bulk inserts on opening trade items are prohibited', $e->getMessage());
        }

        try {
            OpeningTradeItem::query()->upsert([
                [
                    'public_id' => (string) Str::ulid(),
                    'company_id' => $this->company->id,
                    'side' => OpeningTradeItem::SIDE_CUSTOMER,
                    'customer_id' => $this->customer->id,
                    'opening_date' => '2026-01-01',
                    'currency_code' => 'ILS',
                    'amount_currency' => '100.00',
                    'exchange_rate' => '1.0000000000',
                    'carrying_value_base' => '100.00',
                    'status' => OpeningTradeItem::STATUS_POSTED,
                    'created_by' => $this->owner->id,
                    'source_identity' => 'test',
                    'idempotency_key' => 'bulk-upsert',
                    'idempotency_payload_hash' => hash('sha256', 'bulk-upsert'),
                ],
            ], ['idempotency_key']);
            $this->fail('Bulk upsert must throw ImmutableRecordException.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('Direct bulk upserts on opening trade items are prohibited', $e->getMessage());
        }

        // 7. Increment and decrement throw
        try {
            OpeningTradeItem::where('id', $item->id)->increment('amount_currency', 10);
            $this->fail('Increment must throw ImmutableRecordException.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('Direct increments on opening trade items are prohibited', $e->getMessage());
        }

        try {
            OpeningTradeItem::where('id', $item->id)->decrement('amount_currency', 10);
            $this->fail('Decrement must throw ImmutableRecordException.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('Direct decrements on opening trade items are prohibited', $e->getMessage());
        }

        // 8. Truncate throws
        try {
            OpeningTradeItem::truncate();
            $this->fail('Truncate must throw ImmutableRecordException.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('cannot be truncated', $e->getMessage());
        }
    }

    public function test_capability_requires_exact_item_and_journal_binding(): void
    {
        $capability = new OpeningTradePostingCapability(
            (int) $this->company->id,
            (int) $this->owner->id,
            OpeningTradeItem::SIDE_CUSTOMER,
            (int) $this->customer->id,
        );

        $action = app(PostOpeningTradeItemAction::class);
        $eventScope = app(OpeningTradeEventScope::class);

        $refProp = new \ReflectionProperty($action, 'activeEventScope');
        $refProp->setValue($action, $eventScope);

        try {
            // 1. Attempting to create an item without capability->authorizeItem throws
            $eventScope->withPostingCapability($action, $capability, function () {
                $item = new OpeningTradeItem([
                    'company_id' => $this->company->id,
                    'public_id' => (string) Str::ulid(),
                    'side' => OpeningTradeItem::SIDE_CUSTOMER,
                    'customer_id' => $this->customer->id,
                    'opening_date' => '2026-01-01',
                    'currency_code' => 'ILS',
                    'amount_currency' => '100.00',
                    'exchange_rate' => '1.0000000000',
                    'carrying_value_base' => '100.00',
                    'status' => OpeningTradeItem::STATUS_POSTED,
                    'created_by' => $this->owner->id,
                    'source_identity' => 'test',
                    'idempotency_key' => 'unauth-item',
                    'idempotency_payload_hash' => hash('sha256', 'unauth'),
                ]);

                try {
                    $item->save();
                    $this->fail('Item not authorized by capability must throw.');
                } catch (ImmutableRecordException $e) {
                    $this->assertStringContainsString('not authorized by the active posting capability', $e->getMessage());
                }
            });

            // 2. Journal PostingCommand with cross-item source ID throws
            $eventScope->withPostingCapability($action, $capability, function () use ($eventScope) {
                $cmd = new PostingCommand(
                    company: $this->company,
                    postingDate: now(),
                    sourceType: 'opening_trade_item',
                    sourceId: 999999, // Unassigned cross-item sourceId
                    transactionCurrencyCode: 'ILS',
                    baseCurrencyCode: 'ILS',
                    exchangeRate: ExchangeRate::one(),
                    idempotencyKey: 'cmd-cross-item-test',
                    postedBy: $this->owner,
                    lines: [
                        PostingLineCommand::debit(1, $this->ilsCashAccount->ledger_account_id, MoneyAmount::from('10.00')),
                        PostingLineCommand::credit(2, $this->ilsCashAccount->ledger_account_id, MoneyAmount::from('10.00')),
                    ],
                );

                try {
                    $eventScope->assertPosting($cmd);
                    $this->fail('PostingCommand with cross-item source ID must throw.');
                } catch (ImmutableRecordException $e) {
                    $this->assertStringContainsString('is not authorized by the active posting capability', $e->getMessage());
                }
            });

            // 3. Journal PostingCommand with cross-actor throws
            $otherUser = User::factory()->create();
            $eventScope->withPostingCapability($action, $capability, function () use ($eventScope, $otherUser) {
                $cmd = new PostingCommand(
                    company: $this->company,
                    postingDate: now(),
                    sourceType: 'opening_trade_item',
                    sourceId: 1,
                    transactionCurrencyCode: 'ILS',
                    baseCurrencyCode: 'ILS',
                    exchangeRate: ExchangeRate::one(),
                    idempotencyKey: 'cmd-cross-actor-test',
                    postedBy: $otherUser,
                    lines: [
                        PostingLineCommand::debit(1, $this->ilsCashAccount->ledger_account_id, MoneyAmount::from('10.00')),
                        PostingLineCommand::credit(2, $this->ilsCashAccount->ledger_account_id, MoneyAmount::from('10.00')),
                    ],
                );

                try {
                    $eventScope->assertPosting($cmd);
                    $this->fail('PostingCommand with cross-actor must throw.');
                } catch (ImmutableRecordException $e) {
                    $this->assertStringContainsString('Posting command actor does not match', $e->getMessage());
                }
            });

            // 4. Query update broadening inside active callback throws
            $item = app(PostOpeningTradeItemAction::class)->execute(
                company: $this->company,
                side: OpeningTradeItem::SIDE_CUSTOMER,
                partyId: $this->customer->id,
                openingDate: '2026-01-01',
                amount: '300.00',
                currencyCode: 'ILS',
                actor: $this->owner,
                idempotencyKey: 'scope-broaden-test-'.Str::ulid(),
            );

            $eventScope->withPostingCapability($action, $capability, function () use ($item) {
                // Attempting to call update() with forged/missing token throws
                try {
                    OpeningTradeItem::where('id', $item->id)->update(['posting_batch_id' => 999]);
                    $this->fail('Update without valid binding token must throw.');
                } catch (ImmutableRecordException $e) {
                    $this->assertStringContainsString('prohibited', $e->getMessage());
                }

                // Attempting to call update() with broadened where throws
                $builder = OpeningTradeItem::query();
                $builder->bindAuthorizedUpdate($item, 'invalid-token');
                try {
                    $builder->where('id', '!=', $item->id)->update(['posting_batch_id' => 999]);
                    $this->fail('Update with invalid token must throw.');
                } catch (ImmutableRecordException $e) {
                    $this->assertStringContainsString('Invalid or expired model update token', $e->getMessage());
                }
            });
        } finally {
            $refProp->setValue($action, null);
        }
    }

    public function test_execute_form_atomic_commit_retry_and_conflict_handling(): void
    {
        $action = app(PostOpeningTradeItemAction::class);
        $formKey = 'form-atomic-'.Str::ulid();

        $rows = [
            [
                'amount' => '100.00',
                'original_reference' => 'REF-1',
                'due_date' => '2026-02-01',
                'notes' => 'Row 1 notes',
            ],
            [
                'amount' => '250.00',
                'original_reference' => 'REF-2',
                'due_date' => '2026-02-15',
                'notes' => 'Row 2 notes',
            ],
        ];

        // 1. Initial execution commits both rows and their GL batches atomically
        $items = $action->executeForm(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            currencyCode: 'ILS',
            exchangeRate: null,
            rows: $rows,
            actor: $this->owner,
            formIdempotencyKey: $formKey,
            sourceIdentity: 'manual_web',
        );

        $this->assertCount(2, $items);
        $this->assertSame('100.000000', $items[0]->amount_currency);
        $this->assertSame('250.000000', $items[1]->amount_currency);
        $this->assertNotNull($items[0]->posting_batch_id);
        $this->assertNotNull($items[1]->posting_batch_id);

        // Verify journal source identity on both batches
        $b1 = PostingBatch::findOrFail($items[0]->posting_batch_id);
        $b2 = PostingBatch::findOrFail($items[1]->posting_batch_id);
        $this->assertSame('opening_trade_item', $b1->source_type);
        $this->assertSame($items[0]->id, $b1->source_id);
        $this->assertSame('opening_trade_item', $b2->source_type);
        $this->assertSame($items[1]->id, $b2->source_id);

        // 2. Identical retry returns existing items with identical IDs and batches
        $retryItems = $action->executeForm(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            currencyCode: 'ILS',
            exchangeRate: null,
            rows: $rows,
            actor: $this->owner,
            formIdempotencyKey: $formKey,
            sourceIdentity: 'manual_web',
        );

        $this->assertCount(2, $retryItems);
        $this->assertSame($items[0]->id, $retryItems[0]->id);
        $this->assertSame($items[1]->id, $retryItems[1]->id);

        // 3. Append conflict: same key with 3 rows throws IdempotencyConflictException
        $appendedRows = array_merge($rows, [
            ['amount' => '50.00', 'original_reference' => 'REF-3', 'due_date' => null, 'notes' => null],
        ]);
        try {
            $action->executeForm(
                company: $this->company,
                side: OpeningTradeItem::SIDE_CUSTOMER,
                partyId: $this->customer->id,
                openingDate: '2026-01-01',
                currencyCode: 'ILS',
                exchangeRate: null,
                rows: $appendedRows,
                actor: $this->owner,
                formIdempotencyKey: $formKey,
            );
            $this->fail('Append conflict must throw IdempotencyConflictException.');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('different row set count', $e->getMessage());
        }

        // 4. Remove conflict: same key with 1 row throws IdempotencyConflictException
        try {
            $action->executeForm(
                company: $this->company,
                side: OpeningTradeItem::SIDE_CUSTOMER,
                partyId: $this->customer->id,
                openingDate: '2026-01-01',
                currencyCode: 'ILS',
                exchangeRate: null,
                rows: [$rows[0]],
                actor: $this->owner,
                formIdempotencyKey: $formKey,
            );
            $this->fail('Remove conflict must throw IdempotencyConflictException.');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('different row set count', $e->getMessage());
        }

        // 5. Reorder conflict: row 0 and row 1 swapped throws IdempotencyConflictException
        try {
            $action->executeForm(
                company: $this->company,
                side: OpeningTradeItem::SIDE_CUSTOMER,
                partyId: $this->customer->id,
                openingDate: '2026-01-01',
                currencyCode: 'ILS',
                exchangeRate: null,
                rows: [$rows[1], $rows[0]],
                actor: $this->owner,
                formIdempotencyKey: $formKey,
            );
            $this->fail('Reorder conflict must throw IdempotencyConflictException.');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('different economic payload', $e->getMessage());
        }

        // 6. Changed economic payload throws IdempotencyConflictException
        $changedRows = $rows;
        $changedRows[1]['amount'] = '300.00';
        try {
            $action->executeForm(
                company: $this->company,
                side: OpeningTradeItem::SIDE_CUSTOMER,
                partyId: $this->customer->id,
                openingDate: '2026-01-01',
                currencyCode: 'ILS',
                exchangeRate: null,
                rows: $changedRows,
                actor: $this->owner,
                formIdempotencyKey: $formKey,
            );
            $this->fail('Changed payload must throw IdempotencyConflictException.');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('different economic payload', $e->getMessage());
        }

        // 7. Failure rollback: when a row fails, zero items/journals remain committed
        $failKey = 'form-fail-'.Str::ulid();
        $failRows = [
            ['amount' => '150.00', 'original_reference' => 'VALID'],
            ['amount' => '-50.00', 'original_reference' => 'INVALID_NEGATIVE'],
        ];

        $beforeItemsCount = OpeningTradeItem::count();
        $beforeBatchesCount = PostingBatch::count();

        try {
            $action->executeForm(
                company: $this->company,
                side: OpeningTradeItem::SIDE_CUSTOMER,
                partyId: $this->customer->id,
                openingDate: '2026-01-01',
                currencyCode: 'ILS',
                exchangeRate: null,
                rows: $failRows,
                actor: $this->owner,
                formIdempotencyKey: $failKey,
            );
            $this->fail('Invalid row must throw InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($beforeItemsCount, OpeningTradeItem::count());
            $this->assertSame($beforeBatchesCount, PostingBatch::count());
        }
    }

    public function test_statements_aging_and_open_positions_agree_on_reversals_and_legacy_fixtures(): void
    {
        $item = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '500.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: 'agree-item-'.Str::ulid(),
        );

        $revAction = app(ReverseOpeningTradeItemAction::class);
        $revAction->execute($item, $this->owner, 'Reversal test', '2026-01-10');

        // As of 2026-01-05 (before reversal):
        // Statement: opening balance present, reversal not present (as of 2026-01-05) -> balance = 500
        // Open positions: item is open (as of 2026-01-05) -> balance = 500
        $stmtBefore = (new CustomerStatementQuery)->execute($this->customer, toDate: '2026-01-05');
        $entriesBefore = collect($stmtBefore['currencies']['ILS']['entries'] ?? []);
        $this->assertTrue($entriesBefore->contains('type', 'opening_balance'));
        $this->assertFalse($entriesBefore->contains('type', 'reversal'));

        $openPositionsBefore = TradeOpenPositions::read(
            $this->company,
            new ReportFilters(period: ReportPeriod::custom('2026-01-01', '2026-01-05', $this->company)),
            vendor: false,
            aging: false,
            overdue: false,
        );
        $itemsBefore = collect($openPositionsBefore['rows'] ?? []);
        $this->assertTrue($itemsBefore->contains('opening_trade_item_id', $item->id));

        // As of 2026-01-15 (after reversal):
        // Statement: both opening balance and reversal present -> net balance = 0
        // Open positions: excluded -> 0
        $stmtAfter = (new CustomerStatementQuery)->execute($this->customer, toDate: '2026-01-15');
        $entriesAfter = collect($stmtAfter['currencies']['ILS']['entries'] ?? []);
        $this->assertTrue($entriesAfter->contains('type', 'opening_balance'));
        $this->assertTrue($entriesAfter->contains('type', 'reversal'));

        $openPositionsAfter = TradeOpenPositions::read(
            $this->company,
            new ReportFilters(period: ReportPeriod::custom('2026-01-01', '2026-01-15', $this->company)),
            vendor: false,
            aging: false,
            overdue: false,
        );
        $itemsAfter = collect($openPositionsAfter['rows'] ?? []);
        $this->assertFalse($itemsAfter->contains('opening_trade_item_id', $item->id));

        // 3. Incoherent legacy reversal link on posted item:
        // Create an item, set reversal_posting_batch_id while keeping status 'posted' directly in DB
        $legacyItem = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '750.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: 'legacy-incoherent-'.Str::ulid(),
        );
        // Link to an existing reversal batch in DB directly
        DB::table('opening_trade_items')->where('id', $legacyItem->id)->update([
            'reversal_posting_batch_id' => $item->reversal_posting_batch_id,
        ]);
        // Neither statement nor open positions should treat this as canonically reversed
        $legacyStmt = (new CustomerStatementQuery)->execute($this->customer, toDate: '2026-01-15');
        $legacyEntries = collect($legacyStmt['currencies']['ILS']['entries'] ?? []);
        $this->assertTrue($legacyEntries->contains('type', 'opening_balance'));
        $this->assertFalse($legacyEntries->contains('number', 'REV-'.$legacyItem->public_id));

        $legacyOpen = TradeOpenPositions::read(
            $this->company,
            new ReportFilters(period: ReportPeriod::custom('2026-01-01', '2026-01-15', $this->company)),
            vendor: false,
            aging: false,
            overdue: false,
        );
        $legacyOpenRows = collect($legacyOpen['rows'] ?? []);
        $this->assertTrue($legacyOpenRows->contains('opening_trade_item_id', $legacyItem->id));
    }

    public function test_standalone_api_key_and_web_form_key_collision_refuses_adoption(): void
    {
        $action = app(PostOpeningTradeItemAction::class);
        $sharedKey = 'shared-key-'.Str::ulid();

        // 1. Post a standalone single item via execute() with $sharedKey
        $singleItem = $action->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '150.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: $sharedKey,
            sourceIdentity: 'manual_api',
        );
        $this->assertNotNull($singleItem->id);

        // 2. Post a 1-row web form with the same key
        try {
            $action->executeForm(
                company: $this->company,
                side: OpeningTradeItem::SIDE_CUSTOMER,
                partyId: $this->customer->id,
                openingDate: '2026-01-01',
                currencyCode: 'ILS',
                exchangeRate: null,
                rows: [
                    [
                        'amount' => '150.00',
                        'original_reference' => null,
                        'due_date' => null,
                        'notes' => null,
                    ],
                ],
                actor: $this->owner,
                formIdempotencyKey: $sharedKey,
                sourceIdentity: 'manual_web',
            );
            $this->fail('Web form reusing standalone API key must not adopt existing item and must throw IdempotencyConflictException.');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('conflicts with an existing standalone opening trade item', $e->getMessage());
        }
    }
}
