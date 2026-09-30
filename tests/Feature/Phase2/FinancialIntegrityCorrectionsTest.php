<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Accounting\EnsureSystemLedgerAccountsAction;
use App\Actions\Company\CreateCompanyAction;
use App\Actions\Company\UpdateCompanyCurrenciesAction;
use App\Domain\Accounting\Exceptions\BaseCurrencyLockedException;
use App\Domain\Accounting\Exceptions\SystemAccountConflictException;
use App\Domain\Money\Exceptions\UnresolvedExchangeRateException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Domain\Posting\Exceptions\IdempotencyConflictException;
use App\Domain\Posting\Exceptions\PostingValidationException;
use App\Domain\Posting\Exceptions\ReversalException;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Livewire\Pages\SettingsIndex;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\ReconciliationReport;
use App\Services\Money\ExchangeRateService;
use App\Services\Posting\AccountingPostingService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class FinancialIntegrityCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected LedgerAccount $cashAccount;

    protected LedgerAccount $revenueAccount;

    protected LedgerAccount $receivableAccount;

    protected AccountingPostingService $postingService;

    protected ExchangeRateService $rateService;

    protected AccountingReconciliationService $reconciler;

    protected UpdateCompanyCurrenciesAction $currencyAction;

    protected EnsureSystemLedgerAccountsAction $accountsAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'owner@example.com',
            'name' => 'مالك الشركة',
            'locale' => 'ar',
        ]);

        $creator = app(CreateCompanyAction::class);
        $this->company = $creator->execute($this->user, [
            'name_ar' => 'شركة النزاهة المالية التجريبية',
            'base_currency_code' => 'ILS',
        ]);

        $this->postingService = app(AccountingPostingService::class);
        $this->rateService = app(ExchangeRateService::class);
        $this->reconciler = app(AccountingReconciliationService::class);
        $this->currencyAction = app(UpdateCompanyCurrenciesAction::class);
        $this->accountsAction = app(EnsureSystemLedgerAccountsAction::class);

        CompanyScope::executeWithoutScope(function () {
            $this->cashAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'cash_control')->firstOrFail();
            $this->revenueAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'sales_revenue')->firstOrFail();
            $this->receivableAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_receivable')->firstOrFail();
        });

        app(CompanyContext::class)->setCompany($this->company, $this->user);
    }

    // =========================================================================
    // 1. Permanent base-currency lock after first posting
    // =========================================================================

    public function test_base_currency_can_be_changed_before_first_posting(): void
    {
        $updated = $this->currencyAction->execute(
            company: $this->company,
            baseCurrencyCode: 'USD',
            currenciesEnabled: ['ILS' => true, 'USD' => true, 'JOD' => false],
            actor: $this->user
        );

        $this->assertSame('USD', $updated->base_currency_code);
        $this->assertDatabaseHas('companies', [
            'id' => $this->company->id,
            'base_currency_code' => 'USD',
        ]);
        $this->assertDatabaseHas('company_currencies', [
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
            'is_base' => true,
            'enabled' => true,
        ]);
    }

    public function test_base_currency_locked_permanently_after_first_posting(): void
    {
        // Post a valid double-entry batch
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 101,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'first-posting-locks-base',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '150.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '150.000000'),
            ]
        );
        $this->postingService->post($cmd);

        $initialAuditCount = AuditEvent::count();

        // Attempting to change base currency must throw BaseCurrencyLockedException
        $this->expectException(BaseCurrencyLockedException::class);

        try {
            $this->currencyAction->execute(
                company: $this->company,
                baseCurrencyCode: 'USD',
                currenciesEnabled: ['ILS' => true, 'USD' => true],
                actor: $this->user
            );
        } finally {
            // Assert zero mutation to company base currency, flags, or audit table
            $fresh = $this->company->fresh();
            $this->assertSame('ILS', $fresh->base_currency_code);
            $this->assertSame($initialAuditCount, AuditEvent::count());
        }
    }

    public function test_base_currency_remains_locked_after_batch_is_reversed(): void
    {
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 102,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'batch-to-reverse',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '200.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '200.000000'),
            ]
        );
        $batch = $this->postingService->post($cmd);
        $this->postingService->reverse($batch, $this->user, 'Reversing test batch');

        $this->expectException(BaseCurrencyLockedException::class);
        $this->currencyAction->execute(
            company: $this->company,
            baseCurrencyCode: 'USD',
            currenciesEnabled: ['ILS' => true, 'USD' => true],
            actor: $this->user
        );
    }

    public function test_non_base_currencies_can_be_toggled_even_when_base_is_locked(): void
    {
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 103,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'batch-for-toggle-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '100.000000'),
            ]
        );
        $this->postingService->post($cmd);

        // Toggle JOD off
        $this->currencyAction->execute(
            company: $this->company,
            baseCurrencyCode: 'ILS',
            currenciesEnabled: ['ILS' => true, 'USD' => true, 'JOD' => false],
            actor: $this->user
        );

        $jod = CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'JOD')->firstOrFail();
        $this->assertFalse((bool) $jod->enabled);

        // Toggle JOD back on
        $this->currencyAction->execute(
            company: $this->company,
            baseCurrencyCode: 'ILS',
            currenciesEnabled: ['ILS' => true, 'USD' => true, 'JOD' => true],
            actor: $this->user
        );

        $jodFresh = CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'JOD')->firstOrFail();
        $this->assertTrue((bool) $jodFresh->enabled);
    }

    public function test_base_currency_cannot_be_disabled(): void
    {
        $this->currencyAction->execute(
            company: $this->company,
            baseCurrencyCode: 'ILS',
            currenciesEnabled: ['ILS' => false, 'USD' => true],
            actor: $this->user
        );

        $baseCurr = CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'ILS')->firstOrFail();
        $this->assertTrue((bool) $baseCurr->enabled);
        $this->assertTrue((bool) $baseCurr->is_base);
    }

    public function test_settings_ui_locks_base_currency_and_displays_explanation(): void
    {
        $this->actingAs($this->user);

        // 1. Before posting: isBaseCurrencyLocked is false
        Livewire::test(SettingsIndex::class)
            ->call('setSection', 'currencies')
            ->assertViewHas('isBaseCurrencyLocked', false)
            ->assertDontSee(__('settings.base_currency_locked_badge'));

        // 2. Post a batch
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 104,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'ui-lock-test-posting',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '50.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '50.000000'),
            ]
        );
        $this->postingService->post($cmd);

        // 3. After posting: isBaseCurrencyLocked is true, badge and notice visible
        Livewire::test(SettingsIndex::class)
            ->call('setSection', 'currencies')
            ->assertViewHas('isBaseCurrencyLocked', true)
            ->assertSee(__('settings.base_currency_locked_notice'))
            ->assertSee(__('settings.base_currency_locked_badge'));

        // 4. Submitting altered base currency fails gracefully with notice
        Livewire::test(SettingsIndex::class)
            ->call('setSection', 'currencies')
            ->set('base_currency', 'USD')
            ->call('saveCurrencies')
            ->assertSee(__('settings.base_currency_locked_notice'));

        $this->assertSame('ILS', $this->company->fresh()->base_currency_code);
    }

    // =========================================================================
    // 2. Atomic validation and consistent lock order
    // =========================================================================

    public function test_exact_idempotent_retry_succeeds_even_after_mutable_config_changes(): void
    {
        // 1. Post a batch with USD
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 201,
            transactionCurrencyCode: 'USD',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::from('3.5000000000'),
            idempotencyKey: 'retry-after-config-change',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->receivableAccount->id, '350.000000', 'USD', '100.000000', ExchangeRate::from('3.5000000000')),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '350.000000', 'USD', '100.000000', ExchangeRate::from('3.5000000000')),
            ]
        );
        $firstBatch = $this->postingService->post($cmd);

        // 2. Mutate configuration: disable USD, deactivate both accounts
        CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['enabled' => false]);
        $this->receivableAccount->update(['active' => false]);
        $this->revenueAccount->update(['active' => false]);

        // 3. Exact idempotent retry must succeed and return original batch despite disabled currency and inactive accounts
        $retryBatch = $this->postingService->post($cmd);

        $this->assertSame($firstBatch->id, $retryBatch->id);
    }

    public function test_retry_with_mismatched_payload_fails_even_after_config_changes(): void
    {
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 202,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'mismatch-key',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '100.000000'),
            ]
        );
        $this->postingService->post($cmd);

        $alteredCmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 202,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'mismatch-key',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '150.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '150.000000'),
            ]
        );

        $this->expectException(IdempotencyConflictException::class);
        $this->postingService->post($alteredCmd);
    }

    public function test_reversing_and_then_reusing_key_fails(): void
    {
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 203,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'reversed-key-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '100.000000'),
            ]
        );
        $batch = $this->postingService->post($cmd);
        $this->postingService->reverse($batch, $this->user);

        $this->expectException(IdempotencyConflictException::class);
        $this->postingService->post($cmd);
    }

    public function test_new_posting_fails_and_writes_zero_records_when_account_inactive(): void
    {
        $this->cashAccount->update(['active' => false]);
        $initialBatchCount = PostingBatch::count();
        $initialLineCount = DB::table('posting_lines')->count();

        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 204,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'inactive-account-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '100.000000'),
            ]
        );

        $this->expectException(PostingValidationException::class);
        try {
            $this->postingService->post($cmd);
        } finally {
            $this->assertSame($initialBatchCount, PostingBatch::count());
            $this->assertSame($initialLineCount, DB::table('posting_lines')->count());
        }
    }

    public function test_reverse_validates_active_membership_within_transaction(): void
    {
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 205,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'reverse-inactive-member-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '100.000000'),
            ]
        );
        $batch = $this->postingService->post($cmd);

        // Deactivate member
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->user->id)->update(['status' => 'inactive']);

        $this->expectException(PostingValidationException::class);
        $this->postingService->reverse($batch, $this->user);
    }

    // =========================================================================
    // 3. FX date resolution
    // =========================================================================

    public function test_fx_date_resolution_filters_effective_at_and_breaks_ties_by_highest_id(): void
    {
        $baseTime = Carbon::parse('2026-09-15 12:00:00');

        // Rate 1: past rate (effective 2026-09-01)
        $ratePast = $this->rateService->recordRate(
            company: $this->company,
            currencyCode: 'USD',
            rate: '3.4000000000',
            effectiveAt: Carbon::parse('2026-09-01 10:00:00'),
            createdBy: $this->user
        );

        // Rate 2: current base rate (effective 2026-09-15 12:00:00)
        $rateCurrent1 = $this->rateService->recordRate(
            company: $this->company,
            currencyCode: 'USD',
            rate: '3.5000000000',
            effectiveAt: $baseTime,
            createdBy: $this->user
        );

        // Rate 3: tie for same timestamp, higher ID (effective 2026-09-15 12:00:00)
        $rateCurrent2 = $this->rateService->recordRate(
            company: $this->company,
            currencyCode: 'USD',
            rate: '3.5500000000',
            effectiveAt: $baseTime,
            createdBy: $this->user
        );

        // Rate 4: future rate (effective 2026-09-20)
        $rateFuture = $this->rateService->recordRate(
            company: $this->company,
            currencyCode: 'USD',
            rate: '3.9000000000',
            effectiveAt: Carbon::parse('2026-09-20 10:00:00'),
            createdBy: $this->user
        );

        // 1. Resolve at baseTime: must ignore future rate, and choose rateCurrent2 (higher ID)
        $resolved = $this->rateService->resolveRate($this->company, 'USD', $baseTime);
        $this->assertSame('3.5500000000', $resolved->toDecimalString());

        // 2. Resolve at historical time before baseTime (e.g. 2026-09-05): must resolve ratePast
        $resolvedHistorical = $this->rateService->resolveRate($this->company, 'USD', Carbon::parse('2026-09-05 00:00:00'));
        $this->assertSame('3.4000000000', $resolvedHistorical->toDecimalString());

        // 3. Resolve at future time (2026-09-25): must resolve rateFuture
        $resolvedFuture = $this->rateService->resolveRate($this->company, 'USD', Carbon::parse('2026-09-25 00:00:00'));
        $this->assertSame('3.9000000000', $resolvedFuture->toDecimalString());

        // 4. Resolve before any rate existed: throws UnresolvedExchangeRateException
        $this->expectException(UnresolvedExchangeRateException::class);
        $this->rateService->resolveRate($this->company, 'USD', Carbon::parse('2026-08-01 00:00:00'));
    }

    // =========================================================================
    // 4. Actor provenance at DB and service boundaries
    // =========================================================================

    public function test_db_restricts_deletion_of_user_referenced_in_posting_batch(): void
    {
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 301,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'db-restrict-user-delete-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '100.000000'),
            ]
        );
        $this->postingService->post($cmd);

        // Attempting to delete user must fail with FK constraint QueryException
        $this->expectException(QueryException::class);
        $this->user->delete();
    }

    public function test_db_restricts_deletion_of_user_referenced_in_exchange_rate(): void
    {
        $otherUser = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $otherUser->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
            'last_accessed_at' => now(),
        ]);

        app(CompanyContext::class)->setCompany($this->company, $otherUser);

        $this->rateService->recordRate(
            company: $this->company,
            currencyCode: 'USD',
            rate: '3.6000000000',
            createdBy: $otherUser
        );

        $this->expectException(QueryException::class);
        $otherUser->delete();
    }

    public function test_record_rate_rejects_conflicting_or_non_member_actors(): void
    {
        $foreignUser = User::factory()->create(); // Not a member

        // Non-member actor
        $this->expectException(InvalidArgumentException::class);
        $this->rateService->recordRate(
            company: $this->company,
            currencyCode: 'USD',
            rate: '3.6000000000',
            createdBy: $foreignUser
        );
    }

    // =========================================================================
    // 5. Canonical DTO and line metadata
    // =========================================================================

    public function test_posting_command_rejects_invalid_source_type_and_currency_format(): void
    {
        // 1. Empty sourceType
        try {
            new PostingCommand(
                company: $this->company,
                postingDate: now(),
                sourceType: '   ',
                sourceId: 1,
                transactionCurrencyCode: 'ILS',
                baseCurrencyCode: 'ILS',
                exchangeRate: ExchangeRate::one(),
                idempotencyKey: 'k1',
                lines: [
                    PostingLineCommand::debit(1, $this->cashAccount->id, '10'),
                    PostingLineCommand::credit(2, $this->revenueAccount->id, '10'),
                ]
            );
            $this->fail('Expected InvalidArgumentException for empty sourceType');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // 2. Malformed characters in sourceType
        try {
            new PostingCommand(
                company: $this->company,
                postingDate: now(),
                sourceType: 'sale with spaces!',
                sourceId: 1,
                transactionCurrencyCode: 'ILS',
                baseCurrencyCode: 'ILS',
                exchangeRate: ExchangeRate::one(),
                idempotencyKey: 'k2',
                lines: [
                    PostingLineCommand::debit(1, $this->cashAccount->id, '10'),
                    PostingLineCommand::credit(2, $this->revenueAccount->id, '10'),
                ]
            );
            $this->fail('Expected InvalidArgumentException for malformed sourceType');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // 3. Noncanonical lowercase currency code (must reject without normalizing)
        try {
            new PostingCommand(
                company: $this->company,
                postingDate: now(),
                sourceType: 'sale',
                sourceId: 1,
                transactionCurrencyCode: 'ils',
                baseCurrencyCode: 'ILS',
                exchangeRate: ExchangeRate::one(),
                idempotencyKey: 'k3',
                lines: [
                    PostingLineCommand::debit(1, $this->cashAccount->id, '10'),
                    PostingLineCommand::credit(2, $this->revenueAccount->id, '10'),
                ]
            );
            $this->fail('Expected InvalidArgumentException for lowercase currency');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_posting_line_command_rejects_partial_metadata_and_negative_amounts(): void
    {
        // Partial metadata (currency given, but amount and rate null)
        try {
            new PostingLineCommand(
                lineNumber: 1,
                ledgerAccountId: $this->cashAccount->id,
                debitBase: MoneyAmount::from('100.000000'),
                creditBase: MoneyAmount::zero(),
                transactionCurrencyCode: 'USD',
                transactionAmount: null,
                exchangeRate: null
            );
            $this->fail('Expected InvalidArgumentException for partial metadata');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('incomplete', $e->getMessage());
        }

        // Negative transaction amount
        try {
            PostingLineCommand::debit(
                lineNumber: 1,
                ledgerAccountId: $this->cashAccount->id,
                amount: '100.000000',
                transactionCurrencyCode: 'USD',
                transactionAmount: '-50.000000',
                exchangeRate: '2.0000000000'
            );
            $this->fail('Expected InvalidArgumentException for negative transaction amount');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('positive', $e->getMessage());
        }

        // Conversion mismatch: 100 base != 50 amount * 3.00 rate (150)
        try {
            PostingLineCommand::debit(
                lineNumber: 1,
                ledgerAccountId: $this->cashAccount->id,
                amount: '100.000000',
                transactionCurrencyCode: 'USD',
                transactionAmount: '50.000000',
                exchangeRate: '3.0000000000'
            );
            $this->fail('Expected InvalidArgumentException for conversion mismatch');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('conversion mismatch', $e->getMessage());
        }
    }

    public function test_valid_multicurrency_lines_succeed(): void
    {
        // Line 1: USD @ 3.50 -> 350 ILS
        // Line 2: JOD @ 5.00 -> 350 ILS (70 JOD * 5 = 350 ILS)
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 501,
            transactionCurrencyCode: 'USD',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::from('3.5000000000'),
            idempotencyKey: 'multi-curr-lines-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->receivableAccount->id, '350.000000', 'USD', '100.000000', ExchangeRate::from('3.5000000000')),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '350.000000', 'JOD', '70.000000', ExchangeRate::from('5.0000000000')),
            ]
        );

        $batch = $this->postingService->post($cmd);
        $this->assertCount(2, $batch->lines);
        $this->assertSame('USD', $batch->lines[0]->transaction_currency_code);
        $this->assertSame('JOD', $batch->lines[1]->transaction_currency_code);
    }

    // =========================================================================
    // 6. Reversal metadata equivalence
    // =========================================================================

    public function test_reversal_rejects_corrupt_prior_reversal_batch_rate(): void
    {
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 601,
            transactionCurrencyCode: 'USD',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::from('3.5000000000'),
            idempotencyKey: 'corrupt-batch-rate-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '350.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '350.000000'),
            ]
        );
        $original = $this->postingService->post($cmd);

        // Pre-insert a prior reversal batch with a corrupted exchange_rate
        $revKey = "reversal-batch-{$original->public_id}";
        $corruptRev = PostingBatch::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $original->company_id,
            'batch_number' => null,
            'posting_date' => now()->toDateString(),
            'status' => PostingBatch::STATUS_POSTED,
            'source_type' => 'reversal',
            'source_id' => (int) $original->id,
            'transaction_currency_code' => $original->transaction_currency_code,
            'base_currency_code' => $original->base_currency_code,
            'exchange_rate' => '9.9999999999', // Mismatched batch rate!
            'description' => 'Corrupt reversal',
            'idempotency_key' => $revKey,
            'posted_by' => $this->user->id,
            'posted_at' => now(),
            'reversal_of_id' => $original->id,
            'reversed_by_batch_id' => null,
        ]);

        $this->expectException(ReversalException::class);
        $this->postingService->reverse($original, $this->user);
    }

    public function test_reversal_rejects_corrupt_prior_reversal_line_rate(): void
    {
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 602,
            transactionCurrencyCode: 'USD',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::from('3.5000000000'),
            idempotencyKey: 'corrupt-line-rate-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '350.000000', 'USD', '100.000000', ExchangeRate::from('3.5000000000')),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '350.000000', 'USD', '100.000000', ExchangeRate::from('3.5000000000')),
            ]
        );
        $original = $this->postingService->post($cmd);

        $revKey = "reversal-batch-{$original->public_id}";
        $corruptRev = PostingBatch::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $original->company_id,
            'batch_number' => null,
            'posting_date' => now()->toDateString(),
            'status' => PostingBatch::STATUS_POSTED,
            'source_type' => 'reversal',
            'source_id' => (int) $original->id,
            'transaction_currency_code' => $original->transaction_currency_code,
            'base_currency_code' => $original->base_currency_code,
            'exchange_rate' => $original->exchange_rate,
            'description' => 'Corrupt line reversal',
            'idempotency_key' => $revKey,
            'posted_by' => $this->user->id,
            'posted_at' => now(),
            'reversal_of_id' => $original->id,
            'reversed_by_batch_id' => null,
        ]);

        // Insert reciprocal lines with mismatched exchange_rate on line 1
        DB::table('posting_lines')->insert([
            [
                'company_id' => $original->company_id,
                'posting_batch_id' => $corruptRev->id,
                'ledger_account_id' => $this->cashAccount->id,
                'line_number' => 1,
                'debit_base' => '0.000000',
                'credit_base' => '350.000000',
                'transaction_currency_code' => 'USD',
                'transaction_amount' => '100.000000',
                'exchange_rate' => '4.0000000000', // Mismatched line rate!
                'created_at' => now(),
            ],
            [
                'company_id' => $original->company_id,
                'posting_batch_id' => $corruptRev->id,
                'ledger_account_id' => $this->revenueAccount->id,
                'line_number' => 2,
                'debit_base' => '350.000000',
                'credit_base' => '0.000000',
                'transaction_currency_code' => 'USD',
                'transaction_amount' => '100.000000',
                'exchange_rate' => '3.5000000000',
                'created_at' => now(),
            ],
        ]);

        $this->expectException(ReversalException::class);
        $this->postingService->reverse($original, $this->user);
    }

    // =========================================================================
    // 7. Read-only reconciliation meaning
    // =========================================================================

    public function test_reconciler_detects_corruptions_without_mutations(): void
    {
        // Create an invalid batch directly: invalid status, nonpositive rate
        $corruptBatchId = DB::table('posting_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'batch_number' => 'CORRUPT-001',
            'posting_date' => now()->toDateString(),
            'status' => 'draft', // Invalid status!
            'source_type' => 'manual',
            'source_id' => 999,
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'USD', // Differs from company ILS!
            'exchange_rate' => '0.0000000000', // Non-positive rate!
            'idempotency_key' => 'corrupt-batch-rec-key',
            'posted_by' => $this->user->id,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert line with conversion mismatch
        DB::table('posting_lines')->insert([
            [
                'company_id' => $this->company->id,
                'posting_batch_id' => $corruptBatchId,
                'ledger_account_id' => $this->cashAccount->id,
                'line_number' => 1,
                'debit_base' => '100.000000',
                'credit_base' => '0.000000',
                'transaction_currency_code' => 'USD',
                'transaction_amount' => '50.000000',
                'exchange_rate' => '3.0000000000', // 50 * 3 = 150 != 100!
                'created_at' => now(),
            ],
            [
                'company_id' => $this->company->id,
                'posting_batch_id' => $corruptBatchId,
                'ledger_account_id' => $this->revenueAccount->id,
                'line_number' => 2,
                'debit_base' => '0.000000',
                'credit_base' => '100.000000',
                'transaction_currency_code' => null,
                'transaction_amount' => null,
                'exchange_rate' => null,
                'created_at' => now(),
            ],
        ]);

        $batchesBefore = DB::table('posting_batches')->get()->toArray();
        $linesBefore = DB::table('posting_lines')->get()->toArray();

        $report = $this->reconciler->reconcile($this->company);

        $this->assertFalse($report->isHealthy);
        $this->assertNotEmpty($report->violations);

        // Verify strictly read-only
        $this->assertEquals($batchesBefore, DB::table('posting_batches')->get()->toArray());
        $this->assertEquals($linesBefore, DB::table('posting_lines')->get()->toArray());
    }

    // =========================================================================
    // 8. Context-free system mode
    // =========================================================================

    public function test_context_free_system_mode_for_provisioning_and_reconciliation(): void
    {
        $context = app(CompanyContext::class);

        // Active company is set in setUp()
        $this->assertTrue($context->hasCompany());

        // Calling system mode when context has company MUST throw InvalidArgumentException
        try {
            $this->accountsAction->execute($this->company, isSystem: true);
            $this->fail('Expected InvalidArgumentException for system provisioning under active context');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('System mode account provisioning requires no active company context', $e->getMessage());
        }

        try {
            $this->reconciler->reconcile($this->company, isSystem: true);
            $this->fail('Expected InvalidArgumentException for system reconciliation under active context');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('System mode reconciliation requires no active company context', $e->getMessage());
        }

        // Clear context
        $context->clear();
        $this->assertFalse($context->hasCompany());

        // System mode without context succeeds cleanly
        $this->accountsAction->execute($this->company, isSystem: true);
        $report = $this->reconciler->reconcile($this->company, isSystem: true);
        $this->assertInstanceOf(ReconciliationReport::class, $report);

        // Normal mode without context throws NoActiveCompanyException
        $this->expectException(NoActiveCompanyException::class);
        $this->accountsAction->execute($this->company, isSystem: false);
    }

    // =========================================================================
    // 9. Canonical chart hierarchy
    // =========================================================================

    public function test_canonical_chart_hierarchy_rooted_and_child_accounts(): void
    {
        $context = app(CompanyContext::class);
        $context->clear();

        CompanyScope::executeWithoutScope(function () use (&$parent, &$salary, &$cash) {
            // 1. Check salary_expense has operating_expense_parent
            $parent = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'operating_expense_parent')->firstOrFail();
            $salary = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'salary_expense')->firstOrFail();
            $cash = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'cash_control')->firstOrFail();

            $this->assertSame($parent->id, $salary->parent_id);
            $this->assertNull($cash->parent_id);

            // 2. Corrupt both: clear salary parent, and set parent on cash
            $salary->update(['parent_id' => null]);
            $cash->update(['parent_id' => $parent->id]);
        });

        // 3. Reconciler detects both hierarchy corruptions
        $report = $this->reconciler->reconcile($this->company, isSystem: true);
        $this->assertFalse($report->isHealthy);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'hierarchy mismatch')));
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'Root system account [cash_control] should not have a parent')));

        // 4. Running provisioning action restores canonical hierarchy
        $this->accountsAction->execute($this->company, isSystem: true);

        CompanyScope::executeWithoutScope(function () use ($parent, $salary, $cash) {
            $this->assertSame($parent->id, $salary->fresh()->parent_id);
            $this->assertNull($cash->fresh()->parent_id);
        });

        // 5. Reconciler is now healthy
        $freshReport = $this->reconciler->reconcile($this->company, isSystem: true);
        $this->assertTrue($freshReport->isHealthy);
    }

    // =========================================================================
    // 10. Correction 02: Inactive company validation and stale model rejection
    // =========================================================================

    public function test_post_and_reverse_reject_for_inactive_company_with_stale_active_context(): void
    {
        // 1. Create initial posted batch while company is active
        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 501,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'active-company-initial-post',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '100.000000'),
            ],
        );
        $initialBatch = $this->postingService->post($cmd);
        $this->assertSame(PostingBatch::STATUS_POSTED, $initialBatch->status);

        // 2. Persist company deactivation directly in DB
        // The in-memory $this->company and CompanyContext still hold stale active state
        DB::table('companies')->where('id', $this->company->id)->update(['status' => 'inactive']);

        $batchesBefore = DB::table('posting_batches')->count();
        $linesBefore = DB::table('posting_lines')->count();

        // 3. New posting must reject
        $newCmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 502,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'inactive-company-new-post',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '50.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '50.000000'),
            ],
        );

        $postExceptionThrown = false;
        try {
            $this->postingService->post($newCmd);
        } catch (PostingValidationException $e) {
            $postExceptionThrown = true;
            $this->assertStringContainsString('inactive company', $e->getMessage());
        }
        $this->assertTrue($postExceptionThrown, 'Expected PostingValidationException for inactive company.');
        $this->assertSame($batchesBefore, DB::table('posting_batches')->count());
        $this->assertSame($linesBefore, DB::table('posting_lines')->count());

        // 4. Reversal must reject
        $reverseExceptionThrown = false;
        try {
            $this->postingService->reverse($initialBatch, $this->user);
        } catch (ReversalException $e) {
            $reverseExceptionThrown = true;
            $this->assertStringContainsString('inactive company', $e->getMessage());
        }
        $this->assertTrue($reverseExceptionThrown, 'Expected ReversalException for inactive company.');
        $this->assertSame($batchesBefore, DB::table('posting_batches')->count());
        $this->assertSame($linesBefore, DB::table('posting_lines')->count());
        $this->assertSame(PostingBatch::STATUS_POSTED, $initialBatch->fresh()->status);
        $this->assertNull($initialBatch->fresh()->reversed_by_batch_id);
    }

    public function test_post_reads_company_base_currency_inside_transaction_rejecting_stale_model(): void
    {
        // 1. Change DB base currency directly to USD before any postings exist
        DB::table('companies')->where('id', $this->company->id)->update(['base_currency_code' => 'USD']);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['is_base' => true, 'enabled' => true]);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'ILS')->update(['is_base' => false]);

        // 2. Caller holds stale $this->company where base_currency_code is still ILS
        $this->assertSame('ILS', $this->company->base_currency_code);

        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 503,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS', // Stale base currency!
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'stale-base-post-key',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '100.000000'),
            ],
        );

        $this->expectException(PostingValidationException::class);
        $this->expectExceptionMessage('Base currency must match company base currency [USD]');
        $this->postingService->post($cmd);
    }

    public function test_post_reads_membership_and_account_state_inside_transaction(): void
    {
        // 1. Deactivate membership directly in DB while caller retains in-memory active User
        DB::table('company_user')
            ->where('company_id', $this->company->id)
            ->where('user_id', $this->user->id)
            ->update(['status' => 'inactive']);

        $cmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 504,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'stale-member-post-key',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '100.000000'),
            ],
        );

        $membershipFailed = false;
        try {
            $this->postingService->post($cmd);
        } catch (PostingValidationException $e) {
            $membershipFailed = true;
            $this->assertStringContainsString('not an active member', $e->getMessage());
        }
        $this->assertTrue($membershipFailed);

        // Restore membership
        DB::table('company_user')
            ->where('company_id', $this->company->id)
            ->where('user_id', $this->user->id)
            ->update(['status' => 'active']);

        // 2. Deactivate ledger account directly in DB
        DB::table('ledger_accounts')
            ->where('id', $this->cashAccount->id)
            ->update(['active' => false]);

        $accountFailed = false;
        try {
            $this->postingService->post($cmd);
        } catch (PostingValidationException $e) {
            $accountFailed = true;
            $this->assertStringContainsString('inactive', $e->getMessage());
        }
        $this->assertTrue($accountFailed);
    }

    // =========================================================================
    // 11. Correction 02: Overflowing conversion and high-magnitude batch balance
    // =========================================================================

    public function test_reconciler_handles_overflowing_line_conversion_without_crashing_and_command_fails(): void
    {
        // Insert a batch with line metadata that causes MoneyAmount overflow on amount * rate
        $batchId = DB::table('posting_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'batch_number' => 'OVERFLOW-001',
            'posting_date' => now()->toDateString(),
            'status' => PostingBatch::STATUS_POSTED,
            'source_type' => 'sale',
            'source_id' => 999,
            'transaction_currency_code' => 'USD',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '2.0000000000',
            'idempotency_key' => 'overflow-rec-key',
            'posted_by' => $this->user->id,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Line 1: transaction_amount 99999999999999.999999 * rate 2.0000000000 = 199999999999999.999998
        // This exceeds MoneyAmount max bounds (99999999999999.999999)
        DB::table('posting_lines')->insert([
            [
                'company_id' => $this->company->id,
                'posting_batch_id' => $batchId,
                'ledger_account_id' => $this->cashAccount->id,
                'line_number' => 1,
                'debit_base' => '99999999999999.999999',
                'credit_base' => '0.000000',
                'transaction_currency_code' => 'USD',
                'transaction_amount' => '99999999999999.999999',
                'exchange_rate' => '2.0000000000',
                'created_at' => now(),
            ],
            [
                'company_id' => $this->company->id,
                'posting_batch_id' => $batchId,
                'ledger_account_id' => $this->revenueAccount->id,
                'line_number' => 2,
                'debit_base' => '0.000000',
                'credit_base' => '99999999999999.999999',
                'transaction_currency_code' => null,
                'transaction_amount' => null,
                'exchange_rate' => null,
                'created_at' => now(),
            ],
        ]);

        $batchesBefore = DB::table('posting_batches')->get()->toArray();
        $linesBefore = DB::table('posting_lines')->get()->toArray();

        // 1. Reconcile directly: should return report with violation, NOT crash
        $report = $this->reconciler->reconcile($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'conversion mismatch')));

        // Assert strictly zero DB mutations
        $this->assertEquals($batchesBefore, DB::table('posting_batches')->get()->toArray());
        $this->assertEquals($linesBefore, DB::table('posting_lines')->get()->toArray());

        // 2. Reconcile artisan command: should exit FAILURE (1), NOT crash
        $exitCode = Artisan::call('accounting:reconcile', ['companyPublicId' => $this->company->public_id]);
        $this->assertSame(1, $exitCode);
    }

    public function test_reconciler_detects_high_magnitude_batch_imbalances_with_globally_balanced_ledger(): void
    {
        // Two high-magnitude batches with opposite fractional imbalances
        // Batch 1: debit = 99999999999999.000001, credit = 99999999999999.000002 (diff: -0.000001)
        // Batch 2: debit = 99999999999999.000002, credit = 99999999999999.000001 (diff: +0.000001)
        // Global ledger: total_debit == total_credit == 199999999999998.000003
        $b1PublicId = (string) Str::ulid();
        $b1Id = DB::table('posting_batches')->insertGetId([
            'public_id' => $b1PublicId,
            'company_id' => $this->company->id,
            'batch_number' => 'HIGH-MAG-001',
            'posting_date' => now()->toDateString(),
            'status' => PostingBatch::STATUS_POSTED,
            'source_type' => 'manual',
            'source_id' => 901,
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'high-mag-1',
            'posted_by' => $this->user->id,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('posting_lines')->insert([
            [
                'company_id' => $this->company->id,
                'posting_batch_id' => $b1Id,
                'ledger_account_id' => $this->cashAccount->id,
                'line_number' => 1,
                'debit_base' => '99999999999999.000001',
                'credit_base' => '0.000000',
                'transaction_currency_code' => null,
                'transaction_amount' => null,
                'exchange_rate' => null,
                'created_at' => now(),
            ],
            [
                'company_id' => $this->company->id,
                'posting_batch_id' => $b1Id,
                'ledger_account_id' => $this->revenueAccount->id,
                'line_number' => 2,
                'debit_base' => '0.000000',
                'credit_base' => '99999999999999.000002',
                'transaction_currency_code' => null,
                'transaction_amount' => null,
                'exchange_rate' => null,
                'created_at' => now(),
            ],
        ]);

        $b2PublicId = (string) Str::ulid();
        $b2Id = DB::table('posting_batches')->insertGetId([
            'public_id' => $b2PublicId,
            'company_id' => $this->company->id,
            'batch_number' => 'HIGH-MAG-002',
            'posting_date' => now()->toDateString(),
            'status' => PostingBatch::STATUS_POSTED,
            'source_type' => 'manual',
            'source_id' => 902,
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'high-mag-2',
            'posted_by' => $this->user->id,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('posting_lines')->insert([
            [
                'company_id' => $this->company->id,
                'posting_batch_id' => $b2Id,
                'ledger_account_id' => $this->cashAccount->id,
                'line_number' => 1,
                'debit_base' => '99999999999999.000002',
                'credit_base' => '0.000000',
                'transaction_currency_code' => null,
                'transaction_amount' => null,
                'exchange_rate' => null,
                'created_at' => now(),
            ],
            [
                'company_id' => $this->company->id,
                'posting_batch_id' => $b2Id,
                'ledger_account_id' => $this->revenueAccount->id,
                'line_number' => 2,
                'debit_base' => '0.000000',
                'credit_base' => '99999999999999.000001',
                'transaction_currency_code' => null,
                'transaction_amount' => null,
                'exchange_rate' => null,
                'created_at' => now(),
            ],
        ]);

        $batchesBefore = DB::table('posting_batches')->get()->toArray();
        $linesBefore = DB::table('posting_lines')->get()->toArray();

        $report = $this->reconciler->reconcile($this->company);

        $this->assertFalse($report->isHealthy);
        $this->assertTrue(
            collect($report->violations)->contains(fn ($v) => str_contains($v, "Unbalanced batch [{$b1PublicId}]")),
            "Expected violation for batch {$b1PublicId}"
        );
        $this->assertTrue(
            collect($report->violations)->contains(fn ($v) => str_contains($v, "Unbalanced batch [{$b2PublicId}]")),
            "Expected violation for batch {$b2PublicId}"
        );

        // Assert strictly zero DB mutations
        $this->assertEquals($batchesBefore, DB::table('posting_batches')->get()->toArray());
        $this->assertEquals($linesBefore, DB::table('posting_lines')->get()->toArray());
    }

    // =========================================================================
    // Final Invariants Tests (20260930-pr2-final-invariants-correction)
    // =========================================================================

    public function test_record_rate_stale_model_uses_locked_persisted_base_and_rejects_old_base(): void
    {
        // 1. Initially, company base is ILS. In memory $this->company->base_currency_code is 'ILS'.
        // Update DB company to base USD (legitimate pre-posting change)
        DB::table('companies')->where('id', $this->company->id)->update(['base_currency_code' => 'USD']);
        DB::table('company_currencies')->where('company_id', $this->company->id)->update(['is_base' => false]);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['is_base' => true]);

        // Attempting to record rate for current base (USD) using stale model fails
        $rateCountBefore = DB::table('exchange_rates')->where('company_id', $this->company->id)->count();
        try {
            $this->rateService->recordRate($this->company, 'USD', '1.0000000000', createdBy: $this->user);
            $this->fail('Should not record rate for current persisted base currency');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Cannot record exchange rate for company base currency [USD]', $e->getMessage());
        }
        $this->assertSame($rateCountBefore, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());

        // Recording rate for former base (ILS) succeeds with locked base USD
        $rate = $this->rateService->recordRate($this->company, 'ILS', '3.5000000000', createdBy: $this->user);
        $this->assertSame('USD', $rate->base_currency_code);
        $this->assertSame('ILS', $rate->currency_code);
        $this->assertSame('3.5000000000', (string) $rate->rate);
    }

    public function test_record_rate_validates_disabled_currency_inactive_member_and_source(): void
    {
        $rateCountBefore = DB::table('exchange_rates')->where('company_id', $this->company->id)->count();

        // 1. Disabled currency
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'JOD')->update(['enabled' => false]);
        try {
            $this->rateService->recordRate($this->company, 'JOD', '0.7000000000', createdBy: $this->user);
            $this->fail('Should not record rate for disabled currency');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Currency [JOD] is not enabled', $e->getMessage());
        }
        $this->assertSame($rateCountBefore, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());

        // 2. Inactive membership
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->user->id)->update(['status' => 'inactive']);
        try {
            $this->rateService->recordRate($this->company, 'USD', '3.5000000000', createdBy: $this->user);
            $this->fail('Should not record rate for inactive member');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('is not an active member', $e->getMessage());
        }
        $this->assertSame($rateCountBefore, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());

        // Restore member status
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->user->id)->update(['status' => 'active']);

        // 3. Actor mismatch
        $otherUser = User::factory()->create();
        try {
            $this->rateService->recordRate($this->company, 'USD', '3.5000000000', createdBy: $otherUser);
            $this->fail('Should not record rate with mismatched actor');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('conflicts with authenticated user', $e->getMessage());
        }
        $this->assertSame($rateCountBefore, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());

        // 4. Source validation: empty, oversize, uppercase, special characters
        $badSources = ['', str_repeat('a', 33), 'MANUAL', 'manual@source', ' manual '];
        foreach ($badSources as $badSource) {
            try {
                $this->rateService->recordRate($this->company, 'USD', '3.5000000000', createdBy: $this->user, source: $badSource);
                $this->fail("Should reject invalid source [{$badSource}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid exchange rate source', $e->getMessage());
            }
        }
        $this->assertSame($rateCountBefore, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());

        // 5. Valid custom source succeeds
        $validRate = $this->rateService->recordRate($this->company, 'USD', '3.6500000000', createdBy: $this->user, source: 'manual-reuters_01');
        $this->assertSame('manual-reuters_01', $validRate->source);
    }

    public function test_resolve_rate_uses_current_persisted_base_and_preserves_historical_rates(): void
    {
        // 1. Record historical rate when base is ILS
        $rate1 = $this->rateService->recordRate($this->company, 'USD', '3.5000000000', createdBy: $this->user);
        $this->assertSame('ILS', $rate1->base_currency_code);

        // 2. Change base to USD legitimately before any posting
        DB::table('companies')->where('id', $this->company->id)->update(['base_currency_code' => 'USD']);
        DB::table('company_currencies')->where('company_id', $this->company->id)->update(['is_base' => false]);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['is_base' => true]);

        // Record new rate under USD base
        $rate2 = $this->rateService->recordRate($this->company, 'ILS', '3.6000000000', createdBy: $this->user);
        $this->assertSame('USD', $rate2->base_currency_code);

        // Resolving current base USD against stale $this->company (which has base_currency_code = 'ILS') returns exact 1
        $resolvedUsd = $this->rateService->resolveRate($this->company, 'USD');
        $this->assertTrue($resolvedUsd->isOne());

        // Resolving ILS uses reloaded persisted base USD
        $resolvedIls = $this->rateService->resolveRate($this->company, 'ILS');
        $this->assertSame('3.6000000000', $resolvedIls->toDecimalString());

        // Historical rate record with base ILS remains preserved in DB
        $historical = DB::table('exchange_rates')->where('id', $rate1->id)->first();
        $this->assertNotNull($historical);
        $this->assertSame('ILS', $historical->base_currency_code);
    }

    public function test_company_currency_invariant_and_posting_rejection_and_exact_retry(): void
    {
        // 1. Post a valid transaction when configuration is sound
        $validCmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 5501,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'idemp-currency-invariant-01',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '100.000000'),
            ]
        );
        $batch = $this->postingService->post($validCmd);
        $this->assertNotNull($batch);

        $initialBatchCount = DB::table('posting_batches')->where('company_id', $this->company->id)->count();

        // 2. Raw corruption: Zero base flags
        DB::table('company_currencies')->where('company_id', $this->company->id)->update(['is_base' => false]);

        $newCmd = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 5502,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'idemp-currency-invariant-02',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccount->id, '50.000000'),
                PostingLineCommand::credit(2, $this->revenueAccount->id, '50.000000'),
            ]
        );

        try {
            $this->postingService->post($newCmd);
            $this->fail('Should reject new posting when zero base flags');
        } catch (PostingValidationException $e) {
            $this->assertStringContainsString('exactly one base currency must be configured', $e->getMessage());
        }
        $this->assertSame($initialBatchCount, DB::table('posting_batches')->where('company_id', $this->company->id)->count());

        $report = $this->reconciler->reconcile($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'has no base currency flag')));

        // 3. Exact idempotent retry remains valid even with corrupted mutable config!
        $retriedBatch = $this->postingService->post($validCmd);
        $this->assertSame($batch->id, $retriedBatch->id);

        // 4. Raw corruption: Two base flags
        DB::table('company_currencies')->where('company_id', $this->company->id)->update(['is_base' => true]);
        try {
            $this->postingService->post($newCmd);
            $this->fail('Should reject new posting when multiple base flags');
        } catch (PostingValidationException $e) {
            $this->assertStringContainsString('exactly one base currency must be configured', $e->getMessage());
        }
        $report2 = $this->reconciler->reconcile($this->company);
        $this->assertFalse($report2->isHealthy);
        $this->assertTrue(collect($report2->violations)->contains(fn ($v) => str_contains($v, 'multiple base currency flags')));

        // 5. Raw corruption: Disabled base row
        DB::table('company_currencies')->where('company_id', $this->company->id)->update(['is_base' => false]);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'ILS')->update(['is_base' => true, 'enabled' => false]);
        try {
            $this->postingService->post($newCmd);
            $this->fail('Should reject new posting when base is disabled');
        } catch (PostingValidationException $e) {
            $this->assertStringContainsString('is not properly configured or enabled', $e->getMessage());
        }
        $report3 = $this->reconciler->reconcile($this->company);
        $this->assertFalse($report3->isHealthy);
        $this->assertTrue(collect($report3->violations)->contains(fn ($v) => str_contains($v, 'base currency [ILS] is disabled')));

        // 6. Raw corruption: Missing base row
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'ILS')->delete();
        try {
            $this->postingService->post($newCmd);
            $this->fail('Should reject new posting when base row is missing');
        } catch (PostingValidationException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'Standard currency [ILS] is missing')
                || str_contains($e->getMessage(), 'Company currency configuration is invalid')
                || str_contains($e->getMessage(), 'is not properly configured or enabled')
            );
        }
        $report4 = $this->reconciler->reconcile($this->company);
        $this->assertFalse($report4->isHealthy);
        $this->assertTrue(collect($report4->violations)->contains(fn ($v) => str_contains($v, 'missing base currency row [ILS]')));

        // 7. Raw corruption: Single wrong base flag (USD flagged base instead of ILS)
        // First restore ILS row without base flag
        DB::table('company_currencies')->insert([
            'company_id' => $this->company->id,
            'currency_code' => 'ILS',
            'enabled' => true,
            'is_base' => false,
            'display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['is_base' => true]);

        try {
            $this->postingService->post($newCmd);
            $this->fail('Should reject new posting when wrong currency is flagged as base');
        } catch (PostingValidationException $e) {
            $this->assertStringContainsString('is not properly configured or enabled', $e->getMessage());
        }
        $this->assertSame($initialBatchCount, DB::table('posting_batches')->where('company_id', $this->company->id)->count());

        $report5 = $this->reconciler->reconcile($this->company);
        $this->assertFalse($report5->isHealthy);
        $this->assertTrue(collect($report5->violations)->contains(fn ($v) => str_contains($v, 'currency [USD] is flagged as base, but company base is [ILS]')));
        $this->assertTrue(collect($report5->violations)->contains(fn ($v) => str_contains($v, 'base currency row [ILS] is not flagged as base')));

        // 8. Raw corruption: Missing non-base standard currency row (JOD deleted)
        // Restore ILS as base
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['is_base' => false]);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'ILS')->update(['is_base' => true, 'enabled' => true]);
        // Delete standard row JOD
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'JOD')->delete();

        try {
            $this->postingService->post($newCmd);
            $this->fail('Should reject new posting when non-base standard currency JOD is missing');
        } catch (PostingValidationException $e) {
            $this->assertStringContainsString('Standard currency [JOD] is missing', $e->getMessage());
        }
        $this->assertSame($initialBatchCount, DB::table('posting_batches')->where('company_id', $this->company->id)->count());

        $report6 = $this->reconciler->reconcile($this->company);
        $this->assertFalse($report6->isHealthy);
        $this->assertTrue(collect($report6->violations)->contains(fn ($v) => str_contains($v, 'missing standard currency row [JOD]')));

        // Exact retry still succeeds even with standard row missing!
        $retriedBatch2 = $this->postingService->post($validCmd);
        $this->assertSame($batch->id, $retriedBatch2->id);

        // 9. Reconcile back to healthy 3-row config via UpdateCompanyCurrenciesAction
        $this->currencyAction->execute($this->company, 'ILS', ['ILS' => true, 'USD' => true, 'JOD' => true], $this->user);
        $healthyReport = $this->reconciler->reconcile($this->company);
        $this->assertTrue($healthyReport->isHealthy);
    }

    public function test_record_rate_validates_company_currency_invariants_and_missing_standard_rows(): void
    {
        $initialRateCount = DB::table('exchange_rates')->where('company_id', $this->company->id)->count();
        $initialPostingCount = DB::table('posting_batches')->where('company_id', $this->company->id)->count();

        // 1. Duplicate base flag on requested currency (USD is_base = true while ILS is_base = true)
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['is_base' => true]);
        try {
            $this->rateService->recordRate($this->company, 'USD', '3.5000000000', createdBy: $this->user);
            $this->fail('Should reject recordRate when duplicate base flags exist');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exactly one base currency must be configured', $e->getMessage());
        }
        $this->assertSame($initialRateCount, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());
        $this->assertSame($initialPostingCount, DB::table('posting_batches')->where('company_id', $this->company->id)->count());

        // 2. Duplicate base flag on a third non-requested currency (JOD is_base = true while ILS is_base = true)
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['is_base' => false]);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'JOD')->update(['is_base' => true]);
        try {
            $this->rateService->recordRate($this->company, 'USD', '3.5000000000', createdBy: $this->user);
            $this->fail('Should reject recordRate when third currency has duplicate base flag');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exactly one base currency must be configured', $e->getMessage());
        }
        $this->assertSame($initialRateCount, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());
        $this->assertSame($initialPostingCount, DB::table('posting_batches')->where('company_id', $this->company->id)->count());

        // 3. Zero base flags
        DB::table('company_currencies')->where('company_id', $this->company->id)->update(['is_base' => false]);
        try {
            $this->rateService->recordRate($this->company, 'USD', '3.5000000000', createdBy: $this->user);
            $this->fail('Should reject recordRate when zero base flags exist');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exactly one base currency must be configured', $e->getMessage());
        }
        $this->assertSame($initialRateCount, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());

        // 4. Single wrong base flag (only USD is flagged base, ILS is not)
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['is_base' => true]);
        try {
            $this->rateService->recordRate($this->company, 'USD', '3.5000000000', createdBy: $this->user);
            $this->fail('Should reject recordRate when wrong currency is flagged as base');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Base currency [ILS] configuration is invalid', $e->getMessage());
        }
        $this->assertSame($initialRateCount, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());

        // 5. Disabled base row
        DB::table('company_currencies')->where('company_id', $this->company->id)->update(['is_base' => false]);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'ILS')->update(['is_base' => true, 'enabled' => false]);
        try {
            $this->rateService->recordRate($this->company, 'USD', '3.5000000000', createdBy: $this->user);
            $this->fail('Should reject recordRate when base currency is disabled');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Base currency [ILS] configuration is invalid', $e->getMessage());
        }
        $this->assertSame($initialRateCount, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());

        // 6. Missing base row (ILS deleted)
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'ILS')->delete();
        try {
            $this->rateService->recordRate($this->company, 'USD', '3.5000000000', createdBy: $this->user);
            $this->fail('Should reject recordRate when base row is missing');
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'Standard currency [ILS] is missing')
                || str_contains($e->getMessage(), 'Base currency [ILS] configuration is invalid')
            );
        }
        $this->assertSame($initialRateCount, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());

        // 7. Missing non-base standard row (restore ILS, delete JOD)
        DB::table('company_currencies')->insert([
            'company_id' => $this->company->id,
            'currency_code' => 'ILS',
            'enabled' => true,
            'is_base' => true,
            'display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'JOD')->delete();
        try {
            $this->rateService->recordRate($this->company, 'USD', '3.5000000000', createdBy: $this->user);
            $this->fail('Should reject recordRate when standard currency JOD is missing');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Standard currency [JOD] is missing', $e->getMessage());
        }
        $this->assertSame($initialRateCount, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());
        $this->assertSame($initialPostingCount, DB::table('posting_batches')->where('company_id', $this->company->id)->count());

        // 8. Restore normal three-row configuration via UpdateCompanyCurrenciesAction and verify rate recording succeeds
        $this->currencyAction->execute($this->company, 'ILS', ['ILS' => true, 'USD' => true, 'JOD' => true], $this->user);
        $newRate = $this->rateService->recordRate($this->company, 'USD', '3.5500000000', createdBy: $this->user);
        $this->assertSame('3.5500000000', (string) $newRate->rate);
        $this->assertSame($initialRateCount + 1, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());
    }

    public function test_update_company_currencies_action_domain_identity_guards(): void
    {
        $creator = app(CreateCompanyAction::class);
        app(CompanyContext::class)->clear();
        $companyB = $creator->execute($this->user, [
            'name_ar' => 'شركة ثانية',
            'base_currency_code' => 'USD',
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->user);

        // 1. Context A, target B -> Reassignment exception
        try {
            $this->currencyAction->execute($companyB, 'USD', ['USD' => true, 'ILS' => true, 'JOD' => false], $this->user);
            $this->fail('Should reject cross-company action');
        } catch (CompanyReassignmentException $e) {
            $this->assertStringContainsString('Cannot update company currencies for company', $e->getMessage());
        }

        // 2. Arbitrary supplied actor mismatch
        $otherUser = User::factory()->create();
        try {
            $this->currencyAction->execute($this->company, 'ILS', ['ILS' => true, 'USD' => true, 'JOD' => false], $otherUser);
            $this->fail('Should reject actor mismatch');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not match current authenticated or context user', $e->getMessage());
        }

        // 3. Inactive member in target
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->user->id)->update(['status' => 'inactive']);
        try {
            $this->currencyAction->execute($this->company, 'ILS', ['ILS' => true, 'USD' => true, 'JOD' => false], $this->user);
            $this->fail('Should reject inactive member');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('is not an active member', $e->getMessage());
        }
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->user->id)->update(['status' => 'active']);

        // 4. No company context
        app(CompanyContext::class)->clear();
        try {
            $this->currencyAction->execute($this->company, 'ILS', ['ILS' => true, 'USD' => true, 'JOD' => false], $this->user);
            $this->fail('Should reject when no active company context');
        } catch (NoActiveCompanyException $e) {
            $this->assertStringContainsString('Cannot update company currencies without an active company context', $e->getMessage());
        }

        // 5. Successful owner action
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $updated = $this->currencyAction->execute($this->company, 'ILS', ['ILS' => true, 'USD' => true, 'JOD' => false], $this->user);
        $this->assertSame('ILS', $updated->base_currency_code);
    }

    public function test_reconciliation_detects_system_account_semantics_and_provisioning_protects(): void
    {
        // 1. Wrong normal balance on sales_revenue
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'sales_revenue')->update([
            'normal_balance' => LedgerAccount::BALANCE_DEBIT,
        ]);
        $ledgerBefore1a = DB::table('ledger_accounts')->get()->toArray();
        $report1 = $this->reconciler->reconcile($this->company);
        $this->assertEquals($ledgerBefore1a, DB::table('ledger_accounts')->get()->toArray());
        $this->assertFalse($report1->isHealthy);
        $this->assertTrue(collect($report1->violations)->contains(fn ($v) => str_contains($v, '[sales_revenue] normal_balance mismatch')));

        $ledgerBefore1b = DB::table('ledger_accounts')->get()->toArray();
        try {
            $this->accountsAction->execute($this->company);
            $this->fail('Provisioning should not overwrite semantic conflict silently');
        } catch (SystemAccountConflictException $e) {
            $this->assertStringContainsString('sales_revenue', $e->getMessage());
        }
        $this->assertEquals($ledgerBefore1b, DB::table('ledger_accounts')->get()->toArray());
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'sales_revenue')->update([
            'normal_balance' => LedgerAccount::BALANCE_CREDIT,
        ]);

        // 2. Wrong account type on cash_control
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'cash_control')->update([
            'account_type' => LedgerAccount::TYPE_LIABILITY,
        ]);
        $ledgerBefore2a = DB::table('ledger_accounts')->get()->toArray();
        $report2 = $this->reconciler->reconcile($this->company);
        $this->assertEquals($ledgerBefore2a, DB::table('ledger_accounts')->get()->toArray());
        $this->assertFalse($report2->isHealthy);
        $this->assertTrue(collect($report2->violations)->contains(fn ($v) => str_contains($v, '[cash_control] account_type mismatch')));

        $ledgerBefore2b = DB::table('ledger_accounts')->get()->toArray();
        try {
            $this->accountsAction->execute($this->company);
            $this->fail('Provisioning should not overwrite semantic conflict silently');
        } catch (SystemAccountConflictException $e) {
            $this->assertStringContainsString('cash_control', $e->getMessage());
        }
        $this->assertEquals($ledgerBefore2b, DB::table('ledger_accounts')->get()->toArray());
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'cash_control')->update([
            'account_type' => LedgerAccount::TYPE_ASSET,
        ]);

        // 3. Altered code on salary_expense
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'salary_expense')->update([
            'code' => '9999',
        ]);
        $ledgerBefore3a = DB::table('ledger_accounts')->get()->toArray();
        $report3 = $this->reconciler->reconcile($this->company);
        $this->assertEquals($ledgerBefore3a, DB::table('ledger_accounts')->get()->toArray());
        $this->assertFalse($report3->isHealthy);
        $this->assertTrue(collect($report3->violations)->contains(fn ($v) => str_contains($v, '[salary_expense] code mismatch')));

        $ledgerBefore3b = DB::table('ledger_accounts')->get()->toArray();
        try {
            $this->accountsAction->execute($this->company);
            $this->fail('Provisioning should not overwrite semantic conflict silently');
        } catch (SystemAccountConflictException $e) {
            $this->assertStringContainsString('salary_expense', $e->getMessage());
        }
        $this->assertEquals($ledgerBefore3b, DB::table('ledger_accounts')->get()->toArray());
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'salary_expense')->update([
            'code' => '5202',
        ]);

        // 4. is_system = false on cogs
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'cogs')->update([
            'is_system' => false,
        ]);
        $ledgerBefore4a = DB::table('ledger_accounts')->get()->toArray();
        $report4 = $this->reconciler->reconcile($this->company);
        $this->assertEquals($ledgerBefore4a, DB::table('ledger_accounts')->get()->toArray());
        $this->assertFalse($report4->isHealthy);
        $this->assertTrue(collect($report4->violations)->contains(fn ($v) => str_contains($v, '[cogs] is_system mismatch')));

        $ledgerBefore4b = DB::table('ledger_accounts')->get()->toArray();
        try {
            $this->accountsAction->execute($this->company);
            $this->fail('Provisioning should not overwrite semantic conflict silently');
        } catch (SystemAccountConflictException $e) {
            $this->assertStringContainsString('cogs', $e->getMessage());
        }
        $this->assertEquals($ledgerBefore4b, DB::table('ledger_accounts')->get()->toArray());
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'cogs')->update([
            'is_system' => true,
        ]);

        // 5. Inactive required account on inventory
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'inventory')->update([
            'active' => false,
        ]);
        $ledgerBefore5a = DB::table('ledger_accounts')->get()->toArray();
        $report5 = $this->reconciler->reconcile($this->company);
        $this->assertEquals($ledgerBefore5a, DB::table('ledger_accounts')->get()->toArray());
        $this->assertFalse($report5->isHealthy);
        $this->assertTrue(collect($report5->violations)->contains(fn ($v) => str_contains($v, '[inventory] is inactive')));

        $ledgerBefore5b = DB::table('ledger_accounts')->get()->toArray();
        try {
            $this->accountsAction->execute($this->company);
            $this->fail('Provisioning should not overwrite semantic conflict silently');
        } catch (SystemAccountConflictException $e) {
            $this->assertStringContainsString('inventory', $e->getMessage());
        }
        $this->assertEquals($ledgerBefore5b, DB::table('ledger_accounts')->get()->toArray());
        DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'inventory')->update([
            'active' => true,
        ]);
    }

    public function test_reconciliation_exchange_rate_integrity_and_historical_tolerance(): void
    {
        // 1. Valid historical rate with old base (e.g. USD when current base is ILS) is accepted
        DB::table('exchange_rates')->insert([
            'company_id' => $this->company->id,
            'base_currency_code' => 'USD',
            'currency_code' => 'ILS',
            'rate' => '3.5000000000',
            'effective_at' => now()->subDays(10),
            'source' => 'manual',
            'created_by' => $this->user->id,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        // 2. Valid rate for disabled currency (JOD disabled) is accepted
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'JOD')->update(['enabled' => false]);
        DB::table('exchange_rates')->insert([
            'company_id' => $this->company->id,
            'base_currency_code' => 'ILS',
            'currency_code' => 'JOD',
            'rate' => '0.2000000000',
            'effective_at' => now()->subDays(5),
            'source' => 'manual',
            'created_by' => $this->user->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        // Healthy with both historical rates
        $healthyReport = $this->reconciler->reconcile($this->company);
        $this->assertTrue($healthyReport->isHealthy);

        // 3. Bad rows: base == quote, rate <= 0, malformed codes, base outside ILS/USD/JOD
        $badRows = [
            [
                'company_id' => $this->company->id,
                'base_currency_code' => 'ILS',
                'currency_code' => 'ILS',
                'rate' => '1.0000000000',
                'effective_at' => now(),
                'source' => 'manual',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $this->company->id,
                'base_currency_code' => 'ILS',
                'currency_code' => 'USD',
                'rate' => '0.0000000000',
                'effective_at' => now(),
                'source' => 'manual',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $this->company->id,
                'base_currency_code' => 'EUR',
                'currency_code' => 'USD',
                'rate' => '1.1000000000',
                'effective_at' => now(),
                'source' => 'manual',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $this->company->id,
                'base_currency_code' => 'ils', // raw lowercase base code
                'currency_code' => 'USD',
                'rate' => '3.5000000000',
                'effective_at' => now(),
                'source' => 'manual',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $this->company->id,
                'base_currency_code' => 'ILS',
                'currency_code' => 'Usd', // raw mixed-case quote code
                'rate' => '3.5000000000',
                'effective_at' => now(),
                'source' => 'manual',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $this->company->id,
                'base_currency_code' => 'ILS',
                'currency_code' => 'U-D', // malformed non-alpha code
                'rate' => '3.5000000000',
                'effective_at' => now(),
                'source' => 'manual',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $this->company->id,
                'base_currency_code' => 'ILS',
                'currency_code' => 'IL', // malformed 2-letter code
                'rate' => '3.5000000000',
                'effective_at' => now(),
                'source' => 'manual',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('exchange_rates')->insert($badRows);

        $ratesBefore = DB::table('exchange_rates')->get()->toArray();
        $unhealthyReport = $this->reconciler->reconcile($this->company);
        $this->assertFalse($unhealthyReport->isHealthy);

        $violations = collect($unhealthyReport->violations);
        $this->assertTrue($violations->contains(fn ($v) => str_contains($v, 'base currency [ILS] cannot equal quote currency [ILS]')));
        $this->assertTrue($violations->contains(fn ($v) => str_contains($v, 'rate [0.0000000000] must be strictly positive')));
        $this->assertTrue($violations->contains(fn ($v) => str_contains($v, 'base currency [EUR] is outside supported historical currencies')));
        $this->assertTrue($violations->contains(fn ($v) => str_contains($v, 'currency code [ils] or [USD] is malformed')));
        $this->assertTrue($violations->contains(fn ($v) => str_contains($v, 'base currency [ils] is outside supported historical currencies')));
        $this->assertTrue($violations->contains(fn ($v) => str_contains($v, 'currency code [ILS] or [Usd] is malformed')));
        $this->assertTrue($violations->contains(fn ($v) => str_contains($v, 'currency code [ILS] or [U-D] is malformed')));
        $this->assertTrue($violations->contains(fn ($v) => str_contains($v, 'currency code [ILS] or [IL] is malformed')));

        // Strictly zero DB mutations during reconciliation
        $this->assertEquals($ratesBefore, DB::table('exchange_rates')->get()->toArray());
    }

    public function test_company_context_clear_unsets_explicit_user_roles_and_permissions(): void
    {
        $explicitUser = User::factory()->create();
        DB::table('company_user')->insert([
            'company_id' => $this->company->id,
            'user_id' => $explicitUser->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
            'last_accessed_at' => now(),
        ]);
        $context = app(CompanyContext::class);

        $context->setCompany($this->company, $explicitUser);
        $explicitUser->load(['roles', 'permissions']);
        $this->assertTrue($explicitUser->relationLoaded('roles'));
        $this->assertTrue($explicitUser->relationLoaded('permissions'));

        $context->clear();

        $this->assertFalse($context->hasCompany());
        $this->assertNull(getPermissionsTeamId());
        $this->assertFalse($explicitUser->relationLoaded('roles'));
        $this->assertFalse($explicitUser->relationLoaded('permissions'));
    }

    public function test_posting_command_rejects_non_lowercase_source_types(): void
    {
        $badSources = ['Invoice', 'SALE', 'Sale', 'Sale_Order', 'Order!'];

        foreach ($badSources as $badSource) {
            try {
                new PostingCommand(
                    company: $this->company,
                    postingDate: now(),
                    sourceType: $badSource,
                    sourceId: 7001,
                    transactionCurrencyCode: 'ILS',
                    baseCurrencyCode: 'ILS',
                    exchangeRate: ExchangeRate::one(),
                    idempotencyKey: 'test-src-'.Str::random(8),
                    postedBy: $this->user,
                    lines: [
                        PostingLineCommand::debit(1, $this->cashAccount->id, '10.000000'),
                        PostingLineCommand::credit(2, $this->revenueAccount->id, '10.000000'),
                    ]
                );
                $this->fail("Expected PostingCommand to reject non-lowercase source type [{$badSource}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsStringIgnoringCase('must be 1-64 lowercase alphanumeric', $e->getMessage());
            }
        }

        // Valid lowercase source types succeed
        $goodSources = ['invoice', 'sale', 'sale-order', 'pos_receipt_01'];
        foreach ($goodSources as $goodSource) {
            $cmd = new PostingCommand(
                company: $this->company,
                postingDate: now(),
                sourceType: $goodSource,
                sourceId: 7002,
                transactionCurrencyCode: 'ILS',
                baseCurrencyCode: 'ILS',
                exchangeRate: ExchangeRate::one(),
                idempotencyKey: 'test-src-'.Str::random(8),
                postedBy: $this->user,
                lines: [
                    PostingLineCommand::debit(1, $this->cashAccount->id, '10.000000'),
                    PostingLineCommand::credit(2, $this->revenueAccount->id, '10.000000'),
                ]
            );
            $this->assertSame($goodSource, $cmd->sourceType);
        }
    }

    public function test_strict_source_and_currency_anchoring_rejects_trailing_newlines(): void
    {
        // 1. PostingCommand sourceType with trailing newline
        $newlineSources = ["sale\n", "invoice\r\n", "manual\n"];
        foreach ($newlineSources as $ns) {
            try {
                new PostingCommand(
                    company: $this->company,
                    postingDate: now(),
                    sourceType: $ns,
                    sourceId: 7010,
                    transactionCurrencyCode: 'ILS',
                    baseCurrencyCode: 'ILS',
                    exchangeRate: ExchangeRate::one(),
                    idempotencyKey: 'test-newline-src-'.Str::random(8),
                    postedBy: $this->user,
                    lines: [
                        PostingLineCommand::debit(1, $this->cashAccount->id, '10.000000'),
                        PostingLineCommand::credit(2, $this->revenueAccount->id, '10.000000'),
                    ]
                );
                $this->fail("Expected PostingCommand to reject sourceType with newline [{$ns}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid sourceType', $e->getMessage());
            }
        }

        // 2. PostingCommand transactionCurrencyCode with trailing newline
        try {
            new PostingCommand(
                company: $this->company,
                postingDate: now(),
                sourceType: 'sale',
                sourceId: 7011,
                transactionCurrencyCode: "ILS\n",
                baseCurrencyCode: 'ILS',
                exchangeRate: ExchangeRate::one(),
                idempotencyKey: 'test-newline-curr-'.Str::random(8),
                postedBy: $this->user,
                lines: [
                    PostingLineCommand::debit(1, $this->cashAccount->id, '10.000000'),
                    PostingLineCommand::credit(2, $this->revenueAccount->id, '10.000000'),
                ]
            );
            $this->fail('Expected PostingCommand to reject transactionCurrencyCode with newline');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid transactionCurrencyCode', $e->getMessage());
        }

        // 3. PostingCommand baseCurrencyCode with trailing newline
        try {
            new PostingCommand(
                company: $this->company,
                postingDate: now(),
                sourceType: 'sale',
                sourceId: 7012,
                transactionCurrencyCode: 'ILS',
                baseCurrencyCode: "ILS\n",
                exchangeRate: ExchangeRate::one(),
                idempotencyKey: 'test-newline-base-'.Str::random(8),
                postedBy: $this->user,
                lines: [
                    PostingLineCommand::debit(1, $this->cashAccount->id, '10.000000'),
                    PostingLineCommand::credit(2, $this->revenueAccount->id, '10.000000'),
                ]
            );
            $this->fail('Expected PostingCommand to reject baseCurrencyCode with newline');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid baseCurrencyCode', $e->getMessage());
        }

        // 4. PostingLineCommand transactionCurrencyCode with trailing newline
        try {
            PostingLineCommand::debit(
                lineNumber: 1,
                ledgerAccountId: $this->cashAccount->id,
                amount: '10.000000',
                transactionCurrencyCode: "USD\n",
                transactionAmount: '2.500000',
                exchangeRate: '4.0000000000'
            );
            $this->fail('Expected PostingLineCommand to reject line currency with newline');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('transactionCurrencyCode must be exactly 3 uppercase letters', $e->getMessage());
        }

        // 5. ExchangeRateService source with trailing newline
        $rateCountBefore = DB::table('exchange_rates')->where('company_id', $this->company->id)->count();
        try {
            $this->rateService->recordRate(
                company: $this->company,
                currencyCode: 'USD',
                rate: '3.5000000000',
                createdBy: $this->user,
                source: "manual\n"
            );
            $this->fail('Expected recordRate to reject source with newline');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid exchange rate source', $e->getMessage());
        }
        $this->assertSame($rateCountBefore, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());

        // 6. ExchangeRateService currencyCode with trailing newline
        try {
            $this->rateService->recordRate(
                company: $this->company,
                currencyCode: "USD\n",
                rate: '3.5000000000',
                createdBy: $this->user
            );
            $this->fail('Expected recordRate to reject currencyCode with newline');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid currency code', $e->getMessage());
        }
        $this->assertSame($rateCountBefore, DB::table('exchange_rates')->where('company_id', $this->company->id)->count());

        // 7. ExchangeRateService resolveRate currencyCode with trailing newline
        try {
            $this->rateService->resolveRate($this->company, "USD\n");
            $this->fail('Expected resolveRate to reject currencyCode with newline');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid currency code', $e->getMessage());
        }
    }
}
