<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Domain\Posting\Exceptions\IdempotencyConflictException;
use App\Domain\Posting\Exceptions\PostingValidationException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
use App\Services\Posting\AccountingPostingService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $companyA;

    protected Company $companyB;

    protected AccountingPostingService $service;

    protected LedgerAccount $cashAccountA;

    protected LedgerAccount $revenueAccountA;

    protected LedgerAccount $cashAccountB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->companyA = $creator->execute($this->user, [
            'name_ar' => 'شركة أ للمحاسبة',
            'base_currency_code' => 'ILS',
        ]);

        $this->companyB = $creator->execute($this->user, [
            'name_ar' => 'شركة ب للمحاسبة',
            'base_currency_code' => 'ILS',
        ]);

        $this->service = app(AccountingPostingService::class);

        CompanyScope::executeWithoutScope(function () {
            $this->cashAccountA = LedgerAccount::where('company_id', $this->companyA->id)
                ->where('system_key', 'cash_control')
                ->firstOrFail();

            $this->revenueAccountA = LedgerAccount::where('company_id', $this->companyA->id)
                ->where('system_key', 'sales_revenue')
                ->firstOrFail();

            $this->cashAccountB = LedgerAccount::where('company_id', $this->companyB->id)
                ->where('system_key', 'cash_control')
                ->firstOrFail();
        });

        app(CompanyContext::class)->setCompany($this->companyA, $this->user);
    }

    public function test_can_post_valid_balanced_batch(): void
    {
        $command = new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 101,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'test-post-valid-001',
            postedBy: $this->user,
            description: 'Cash sale',
            lines: [
                PostingLineCommand::debit(1, $this->cashAccountA->id, '150.000000', 'ILS', '150.000000', ExchangeRate::one(), 'Debit cash'),
                PostingLineCommand::credit(2, $this->revenueAccountA->id, '150.000000', 'ILS', '150.000000', ExchangeRate::one(), 'Credit revenue'),
            ]
        );

        $batch = $this->service->post($command);

        $this->assertInstanceOf(PostingBatch::class, $batch);
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);
        $this->assertCount(2, $batch->lines);
        $this->assertSame('150.000000', $batch->lines[0]->debit_base);
        $this->assertSame('150.000000', $batch->lines[1]->credit_base);
    }

    public function test_unbalanced_command_throws_validation_exception(): void
    {
        $this->expectException(PostingValidationException::class);
        $this->expectExceptionMessage('Posting batch is unbalanced');

        new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 102,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'test-post-unbalanced',
            lines: [
                PostingLineCommand::debit(1, $this->cashAccountA->id, '150.000000'),
                PostingLineCommand::credit(2, $this->revenueAccountA->id, '100.000000'),
            ]
        );
    }

    public function test_single_line_posting_is_rejected(): void
    {
        $this->expectException(PostingValidationException::class);
        $this->expectExceptionMessage('requires at least two lines');

        new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 103,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'test-post-single-line',
            lines: [
                PostingLineCommand::debit(1, $this->cashAccountA->id, '100.000000'),
            ]
        );
    }

    public function test_duplicate_line_numbers_are_rejected(): void
    {
        $this->expectException(PostingValidationException::class);
        $this->expectExceptionMessage('unique sequential line numbers');

        new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 104,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'test-post-dup-lines',
            lines: [
                PostingLineCommand::debit(1, $this->cashAccountA->id, '100.000000'),
                PostingLineCommand::credit(1, $this->revenueAccountA->id, '100.000000'),
            ]
        );
    }

    public function test_posting_with_foreign_company_account_is_rejected(): void
    {
        $this->expectException(PostingValidationException::class);
        $this->expectExceptionMessage('does not belong to company');

        $command = new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 105,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'test-cross-company-account',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccountB->id, '100.000000'), // Belonging to company B!
                PostingLineCommand::credit(2, $this->revenueAccountA->id, '100.000000'),
            ]
        );

        $this->service->post($command);
    }

    public function test_posting_with_disabled_currency_is_rejected(): void
    {
        CompanyScope::executeWithoutScope(function () {
            CompanyCurrency::where('company_id', $this->companyA->id)
                ->where('currency_code', 'JOD')
                ->update(['enabled' => false]);
        });

        $this->expectException(PostingValidationException::class);
        $this->expectExceptionMessage('Currency is not enabled for company');

        $command = new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 106,
            transactionCurrencyCode: 'JOD',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::from('5.0000000000'),
            idempotencyKey: 'test-disabled-currency',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccountA->id, '500.000000'),
                PostingLineCommand::credit(2, $this->revenueAccountA->id, '500.000000'),
            ]
        );

        $this->service->post($command);
    }

    public function test_idempotency_returns_existing_batch_without_duplicate(): void
    {
        $idempotencyKey = 'unique-sale-tx-100';

        $command = new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'invoice',
            sourceId: 100,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $idempotencyKey,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccountA->id, '250.000000'),
                PostingLineCommand::credit(2, $this->revenueAccountA->id, '250.000000'),
            ]
        );

        $batch1 = $this->service->post($command);
        $lineCountBefore = PostingLine::where('company_id', $this->companyA->id)->count();

        // Re-post with same command
        $batch2 = $this->service->post($command);
        $lineCountAfter = PostingLine::where('company_id', $this->companyA->id)->count();

        $this->assertSame($batch1->id, $batch2->id);
        $this->assertSame($batch1->public_id, $batch2->public_id);
        $this->assertSame($lineCountBefore, $lineCountAfter);
    }

    public function test_idempotency_raises_conflict_on_mismatched_payload(): void
    {
        $idempotencyKey = 'shared-idempotency-key';

        $command1 = new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'invoice',
            sourceId: 101,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $idempotencyKey,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccountA->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccountA->id, '100.000000'),
            ]
        );

        $this->service->post($command1);

        // Same idempotency key, but different sourceId
        $command2 = new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'invoice',
            sourceId: 102,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $idempotencyKey,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccountA->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueAccountA->id, '100.000000'),
            ]
        );

        $this->expectException(IdempotencyConflictException::class);
        $this->expectExceptionMessage('already registered with');

        $this->service->post($command2);
    }

    public function test_posted_batches_and_lines_are_immutable(): void
    {
        $command = new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'manual_journal',
            sourceId: 107,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'test-immutability-001',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashAccountA->id, '75.000000'),
                PostingLineCommand::credit(2, $this->revenueAccountA->id, '75.000000'),
            ]
        );

        $batch = $this->service->post($command);
        $line = $batch->lines()->firstOrFail();

        // Test Batch immutability on update
        try {
            $batch->description = 'Tampered description';
            $batch->save();
            $this->fail('Expected ImmutableRecordException when updating PostingBatch.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Test Batch immutability on delete
        try {
            $batch->delete();
            $this->fail('Expected ImmutableRecordException when deleting PostingBatch.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        // Test Line immutability on update
        try {
            $line->debit_base = '999.000000';
            $line->save();
            $this->fail('Expected ImmutableRecordException when updating PostingLine.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Test Line immutability on delete
        try {
            $line->delete();
            $this->fail('Expected ImmutableRecordException when deleting PostingLine.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }
    }
}
