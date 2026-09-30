<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Money\ValueObjects\ExchangeRate as ExchangeRateValueObject;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
use App\Services\Money\ExchangeRateService;
use App\Services\Posting\AccountingPostingService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $companyA;

    protected Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->companyA = $creator->execute($this->user, [
            'name_ar' => 'شركة عزل أ',
            'base_currency_code' => 'ILS',
        ]);

        $this->companyB = $creator->execute($this->user, [
            'name_ar' => 'شركة عزل ب',
            'base_currency_code' => 'ILS',
        ]);
    }

    public function test_exchange_rate_model_enforces_tenant_scoping(): void
    {
        $context = app(CompanyContext::class);

        // Record rate for Company A under Company A context
        $context->setCompany($this->companyA, $this->user);
        app(ExchangeRateService::class)->recordRate(
            $this->companyA,
            'USD',
            '3.6500000000'
        );

        // Record rate for Company B under Company B context
        $context->setCompany($this->companyB, $this->user);
        app(ExchangeRateService::class)->recordRate(
            $this->companyB,
            'USD',
            '3.7500000000'
        );

        // 1. Without context: query fails closed (0 rows)
        $context->clear();
        $this->assertTrue(ExchangeRate::all()->isEmpty());
        $this->assertSame(0, ExchangeRate::count());

        // 2. Without context: creation throws NoActiveCompanyException
        $this->expectException(NoActiveCompanyException::class);
        ExchangeRate::create([
            'base_currency_code' => 'ILS',
            'currency_code' => 'USD',
            'rate' => '3.5000000000',
            'effective_at' => now(),
        ]);
    }

    public function test_exchange_rate_model_under_active_context_isolates_records(): void
    {
        $context = app(CompanyContext::class);

        $context->setCompany($this->companyA, $this->user);
        app(ExchangeRateService::class)->recordRate($this->companyA, 'USD', '3.6500000000');

        $context->setCompany($this->companyB, $this->user);
        app(ExchangeRateService::class)->recordRate($this->companyB, 'USD', '3.7500000000');

        // Under Company A: only see Company A's rate
        $context->setCompany($this->companyA, $this->user);
        $ratesA = ExchangeRate::all();
        $this->assertCount(1, $ratesA);
        $this->assertSame('3.6500000000', $ratesA->first()->rate);

        // Under Company B: only see Company B's rate
        $context->setCompany($this->companyB, $this->user);
        $ratesB = ExchangeRate::all();
        $this->assertCount(1, $ratesB);
        $this->assertSame('3.7500000000', $ratesB->first()->rate);
    }

    public function test_ledger_accounts_fail_closed_without_context_and_isolate_under_context(): void
    {
        $context = app(CompanyContext::class);

        // Without context: query fails closed (0 rows)
        $context->clear();
        $this->assertTrue(LedgerAccount::all()->isEmpty());
        $this->assertSame(0, LedgerAccount::count());

        // Without context: creation throws NoActiveCompanyException
        try {
            LedgerAccount::create([
                'code' => '9999',
                'name_ar' => 'حساب غير مصرح',
                'account_type' => LedgerAccount::TYPE_ASSET,
                'normal_balance' => LedgerAccount::BALANCE_DEBIT,
            ]);
            $this->fail('Expected NoActiveCompanyException when creating LedgerAccount without context.');
        } catch (NoActiveCompanyException $e) {
            $this->assertStringContainsString('without active company context', $e->getMessage());
        }

        // Under Company A: only accounts for A
        $context->setCompany($this->companyA, $this->user);
        $accountsA = LedgerAccount::all();
        $this->assertNotEmpty($accountsA);
        foreach ($accountsA as $acc) {
            $this->assertSame($this->companyA->id, $acc->company_id);
        }

        // Under Company B: only accounts for B
        $context->setCompany($this->companyB, $this->user);
        $accountsB = LedgerAccount::all();
        $this->assertNotEmpty($accountsB);
        foreach ($accountsB as $acc) {
            $this->assertSame($this->companyB->id, $acc->company_id);
        }
    }

    public function test_posting_batch_and_lines_isolate_under_context(): void
    {
        $context = app(CompanyContext::class);
        $context->setCompany($this->companyA, $this->user);

        $cashA = CompanyScope::executeWithoutScope(fn () => LedgerAccount::where('company_id', $this->companyA->id)->where('system_key', 'cash_control')->firstOrFail());
        $revA = CompanyScope::executeWithoutScope(fn () => LedgerAccount::where('company_id', $this->companyA->id)->where('system_key', 'sales_revenue')->firstOrFail());

        $postingService = app(AccountingPostingService::class);
        $batch = $postingService->post(new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 1,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRateValueObject::one(),
            idempotencyKey: 'isolation-batch-a',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $cashA->id, '100.000000'),
                PostingLineCommand::credit(2, $revA->id, '100.000000'),
            ]
        ));

        // Under Company A: finds batch and lines
        $context->setCompany($this->companyA, $this->user);
        $this->assertCount(1, PostingBatch::all());
        $this->assertCount(2, PostingLine::all());

        // Under Company B: 0 batches and 0 lines
        $context->setCompany($this->companyB, $this->user);
        $this->assertCount(0, PostingBatch::all());
        $this->assertCount(0, PostingLine::all());
    }
}
