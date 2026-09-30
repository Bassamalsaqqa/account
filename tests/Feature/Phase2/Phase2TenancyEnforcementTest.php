<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Accounting\EnsureSystemLedgerAccountsAction;
use App\Actions\Accounting\PostOpeningBalancesAction;
use App\Actions\Company\CreateCompanyAction;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Domain\Posting\Exceptions\PostingValidationException;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\DerivedLedgerBalanceService;
use App\Services\Money\ExchangeRateService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2TenancyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;

    protected User $userB;

    protected Company $companyA;

    protected Company $companyB;

    protected LedgerAccount $cashA;

    protected LedgerAccount $revenueA;

    protected LedgerAccount $cashB;

    protected LedgerAccount $revenueB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['locale' => 'ar']);
        $this->userB = User::factory()->create(['locale' => 'ar']);

        $creator = app(CreateCompanyAction::class);
        $this->companyA = $creator->execute($this->userA, [
            'name_ar' => 'شركة أ للأمان',
            'base_currency_code' => 'ILS',
        ]);

        $this->companyB = $creator->execute($this->userB, [
            'name_ar' => 'شركة ب للأمان',
            'base_currency_code' => 'ILS',
        ]);

        CompanyScope::executeWithoutScope(function () {
            $this->cashA = LedgerAccount::where('company_id', $this->companyA->id)->where('system_key', 'cash_control')->firstOrFail();
            $this->revenueA = LedgerAccount::where('company_id', $this->companyA->id)->where('system_key', 'sales_revenue')->firstOrFail();

            $this->cashB = LedgerAccount::where('company_id', $this->companyB->id)->where('system_key', 'cash_control')->firstOrFail();
            $this->revenueB = LedgerAccount::where('company_id', $this->companyB->id)->where('system_key', 'sales_revenue')->firstOrFail();
        });
    }

    public function test_posting_fails_without_active_company_context(): void
    {
        $context = app(CompanyContext::class);
        $context->clear();

        $command = new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 501,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'no-context-posting',
            postedBy: $this->userA,
            lines: [
                PostingLineCommand::debit(1, $this->cashA->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueA->id, '100.000000'),
            ]
        );

        $this->expectException(NoActiveCompanyException::class);
        $this->expectExceptionMessage('Cannot post accounting transaction without an active company context');

        app(AccountingPostingService::class)->post($command);
    }

    public function test_posting_fails_when_active_company_mismatches_target_company(): void
    {
        $context = app(CompanyContext::class);
        $context->setCompany($this->companyA, $this->userA);

        $command = new PostingCommand(
            company: $this->companyB, // Target is Company B while A is active!
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 502,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'mismatch-posting',
            postedBy: $this->userB,
            lines: [
                PostingLineCommand::debit(1, $this->cashB->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueB->id, '100.000000'),
            ]
        );

        $this->expectException(CompanyReassignmentException::class);
        $this->expectExceptionMessage("when active company is [{$this->companyA->id}]");

        app(AccountingPostingService::class)->post($command);
    }

    public function test_posting_fails_when_poster_is_null_or_not_member_of_company(): void
    {
        $context = app(CompanyContext::class);
        $context->setCompany($this->companyA, $this->userA);

        // 1. Poster is null
        $cmdNullPoster = new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 503,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'null-poster-key',
            postedBy: null,
            lines: [
                PostingLineCommand::debit(1, $this->cashA->id, '50.000000'),
                PostingLineCommand::credit(2, $this->revenueA->id, '50.000000'),
            ]
        );

        try {
            app(AccountingPostingService::class)->post($cmdNullPoster);
            $this->fail('Expected PostingValidationException for null poster in normal posting.');
        } catch (PostingValidationException $e) {
            $this->assertStringContainsString('Posted by user is required', $e->getMessage());
        }

        // 2. Poster is userB (not a member of companyA)
        $cmdForeignPoster = new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 504,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'foreign-poster-key',
            postedBy: $this->userB,
            lines: [
                PostingLineCommand::debit(1, $this->cashA->id, '50.000000'),
                PostingLineCommand::credit(2, $this->revenueA->id, '50.000000'),
            ]
        );

        try {
            app(AccountingPostingService::class)->post($cmdForeignPoster);
            $this->fail('Expected PostingValidationException for foreign user.');
        } catch (PostingValidationException $e) {
            $this->assertStringContainsString("User [{$this->userB->id}] is not an active member of company", $e->getMessage());
        }
    }

    public function test_reversal_service_tenancy_enforcement(): void
    {
        $context = app(CompanyContext::class);
        $context->setCompany($this->companyA, $this->userA);

        $postingService = app(AccountingPostingService::class);
        $reversalService = app(AccountingReversalService::class);

        $batchA = $postingService->post(new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 505,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'reversal-tenancy-batch-a',
            postedBy: $this->userA,
            lines: [
                PostingLineCommand::debit(1, $this->cashA->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueA->id, '100.000000'),
            ]
        ));

        // 1. Without active company context
        $context->clear();
        try {
            $reversalService->reverse($batchA, $this->userA);
            $this->fail('Expected NoActiveCompanyException on reversal without context.');
        } catch (NoActiveCompanyException $e) {
            $this->assertStringContainsString('without an active company context', $e->getMessage());
        }

        // 2. Active company mismatch (company B is active while reversing batch of company A)
        $context->setCompany($this->companyB, $this->userB);
        try {
            $reversalService->reverse($batchA, $this->userB);
            $this->fail('Expected CompanyReassignmentException on foreign batch reversal.');
        } catch (CompanyReassignmentException $e) {
            $this->assertStringContainsString("when active company is [{$this->companyB->id}]", $e->getMessage());
        }

        // 3. Under matching active company context, reversal succeeds
        $context->setCompany($this->companyA, $this->userA);
        $reversal = $reversalService->reverse($batchA, $this->userA);
        $this->assertSame(PostingBatch::STATUS_POSTED, $reversal->status);
        $batchA->refresh();
        $this->assertSame(PostingBatch::STATUS_REVERSED, $batchA->status);
    }

    public function test_exchange_rate_service_tenancy_enforcement(): void
    {
        $fx = app(ExchangeRateService::class);
        $context = app(CompanyContext::class);
        $context->clear();

        // 1. Without context
        try {
            $fx->recordRate($this->companyA, 'USD', '3.6500000000');
            $this->fail('Expected NoActiveCompanyException on recordRate without context.');
        } catch (NoActiveCompanyException $e) {
            $this->assertStringContainsString('without an active company context', $e->getMessage());
        }

        try {
            $fx->resolveRate($this->companyA, 'USD');
            $this->fail('Expected NoActiveCompanyException on resolveRate without context.');
        } catch (NoActiveCompanyException $e) {
            $this->assertStringContainsString('without an active company context', $e->getMessage());
        }

        // 2. With Company A active, targeting Company B
        $context->setCompany($this->companyA, $this->userA);

        try {
            $fx->recordRate($this->companyB, 'USD', '3.6500000000');
            $this->fail('Expected CompanyReassignmentException on recordRate for Company B.');
        } catch (CompanyReassignmentException $e) {
            $this->assertStringContainsString("when active company is [{$this->companyA->id}]", $e->getMessage());
        }

        try {
            $fx->resolveRate($this->companyB, 'USD');
            $this->fail('Expected CompanyReassignmentException on resolveRate for Company B.');
        } catch (CompanyReassignmentException $e) {
            $this->assertStringContainsString("when active company is [{$this->companyA->id}]", $e->getMessage());
        }

        // 3. Under active Company A context, operations succeed
        $rate = $fx->recordRate($this->companyA, 'USD', '3.6500000000');
        $this->assertSame('3.6500000000', $rate->rate);

        $resolved = $fx->resolveRate($this->companyA, 'USD');
        $this->assertSame('3.6500000000', $resolved->toDecimalString());
    }

    public function test_opening_balances_tenancy_enforcement(): void
    {
        $action = app(PostOpeningBalancesAction::class);
        $context = app(CompanyContext::class);
        $context->clear();

        // 1. Without context
        try {
            $action->execute(
                company: $this->companyA,
                date: now(),
                assetBalances: [['account_id' => $this->cashA->id, 'amount' => '100.000000']],
                liabilityBalances: [],
                postedBy: $this->userA
            );
            $this->fail('Expected NoActiveCompanyException on opening balances without context.');
        } catch (NoActiveCompanyException $e) {
            $this->assertStringContainsString('without an active company context', $e->getMessage());
        }

        // 2. Cross-company target
        $context->setCompany($this->companyA, $this->userA);
        try {
            $action->execute(
                company: $this->companyB,
                date: now(),
                assetBalances: [['account_id' => $this->cashB->id, 'amount' => '100.000000']],
                liabilityBalances: [],
                postedBy: $this->userB
            );
            $this->fail('Expected CompanyReassignmentException on opening balances for company B.');
        } catch (CompanyReassignmentException $e) {
            $this->assertStringContainsString("when active company is [{$this->companyA->id}]", $e->getMessage());
        }
    }

    public function test_derived_reads_tenancy_enforcement(): void
    {
        $service = app(DerivedLedgerBalanceService::class);
        $context = app(CompanyContext::class);
        $context->clear();

        // 1. Without context
        try {
            $service->getAccountBalance($this->cashA);
            $this->fail('Expected NoActiveCompanyException on getAccountBalance.');
        } catch (NoActiveCompanyException) {
            $this->assertTrue(true);
        }

        try {
            $service->getAccountActivity($this->cashA);
            $this->fail('Expected NoActiveCompanyException on getAccountActivity.');
        } catch (NoActiveCompanyException) {
            $this->assertTrue(true);
        }

        try {
            $service->getTrialBalance($this->companyA);
            $this->fail('Expected NoActiveCompanyException on getTrialBalance.');
        } catch (NoActiveCompanyException) {
            $this->assertTrue(true);
        }

        // 2. Cross-company under context
        $context->setCompany($this->companyA, $this->userA);

        try {
            $service->getAccountBalance($this->cashB);
            $this->fail('Expected CompanyReassignmentException on foreign account balance.');
        } catch (CompanyReassignmentException) {
            $this->assertTrue(true);
        }

        try {
            $service->getAccountActivity($this->cashB);
            $this->fail('Expected CompanyReassignmentException on foreign account activity.');
        } catch (CompanyReassignmentException) {
            $this->assertTrue(true);
        }

        try {
            $service->getTrialBalance($this->companyB);
            $this->fail('Expected CompanyReassignmentException on foreign trial balance.');
        } catch (CompanyReassignmentException) {
            $this->assertTrue(true);
        }

        // 3. Under active Company A context, operations succeed
        $tb = $service->getTrialBalance($this->companyA);
        $this->assertTrue($tb['is_balanced']);
    }

    public function test_system_chart_action_and_reconciliation_tenancy_enforcement(): void
    {
        $chartAction = app(EnsureSystemLedgerAccountsAction::class);
        $reconciler = app(AccountingReconciliationService::class);
        $context = app(CompanyContext::class);
        $context->clear();

        // 1. Chart action without context
        try {
            $chartAction->execute($this->companyA);
            $this->fail('Expected NoActiveCompanyException on chart action.');
        } catch (NoActiveCompanyException) {
            $this->assertTrue(true);
        }

        // 2. Reconciliation without context
        try {
            $reconciler->reconcile($this->companyA);
            $this->fail('Expected NoActiveCompanyException on reconcile.');
        } catch (NoActiveCompanyException) {
            $this->assertTrue(true);
        }

        // 3. Under company A, targeting company B
        $context->setCompany($this->companyA, $this->userA);

        try {
            $chartAction->execute($this->companyB);
            $this->fail('Expected CompanyReassignmentException on chart action for company B.');
        } catch (CompanyReassignmentException) {
            $this->assertTrue(true);
        }

        try {
            $reconciler->reconcile($this->companyB);
            $this->fail('Expected CompanyReassignmentException on reconcile for company B.');
        } catch (CompanyReassignmentException) {
            $this->assertTrue(true);
        }

        // 4. Trusted CLI / bootstrap execution succeeds without active context
        $context->clear();
        $this->artisan('accounting:bootstrap', ['companyPublicId' => $this->companyA->public_id])
            ->assertSuccessful();

        $this->artisan('accounting:reconcile', ['companyPublicId' => $this->companyA->public_id])
            ->assertSuccessful();
    }
}
