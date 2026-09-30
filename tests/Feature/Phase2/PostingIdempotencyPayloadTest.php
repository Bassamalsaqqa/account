<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Domain\Posting\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingIdempotencyPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected AccountingPostingService $service;

    protected LedgerAccount $cash;

    protected LedgerAccount $revenue;

    protected LedgerAccount $expense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة فحص تكرار العمليات',
            'base_currency_code' => 'ILS',
        ]);

        $this->service = app(AccountingPostingService::class);
        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->cash = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'cash_control')->firstOrFail();
        $this->revenue = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'sales_revenue')->firstOrFail();
        $this->expense = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'salary_expense')->firstOrFail();
    }

    public function test_exact_retry_returns_same_batch_without_new_records(): void
    {
        $command = new PostingCommand(
            company: $this->company,
            postingDate: Carbon::parse('2026-09-10'),
            sourceType: 'invoice',
            sourceId: 1001,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'exact-retry-key',
            postedBy: $this->user,
            description: 'Invoice 1001',
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '200.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '200.000000'),
            ]
        );

        $batch1 = $this->service->post($command);
        $batchesCount = PostingBatch::where('company_id', $this->company->id)->count();
        $linesCount = PostingLine::where('company_id', $this->company->id)->count();

        // Exact retry with identical command
        $batch2 = $this->service->post($command);

        $this->assertSame($batch1->id, $batch2->id);
        $this->assertSame($batch1->public_id, $batch2->public_id);
        $this->assertSame($batchesCount, PostingBatch::where('company_id', $this->company->id)->count());
        $this->assertSame($linesCount, PostingLine::where('company_id', $this->company->id)->count());
    }

    public function test_same_key_with_different_amount_raises_conflict_and_creates_no_records(): void
    {
        $idempotencyKey = 'conflict-amount-key';

        $originalCommand = new PostingCommand(
            company: $this->company,
            postingDate: Carbon::parse('2026-09-10'),
            sourceType: 'invoice',
            sourceId: 1002,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $idempotencyKey,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        );

        $this->service->post($originalCommand);

        $batchesCountBefore = PostingBatch::where('company_id', $this->company->id)->count();
        $linesCountBefore = PostingLine::where('company_id', $this->company->id)->count();

        // Second command with same key and source, but different amount (250 instead of 100)
        $conflictingCommand = new PostingCommand(
            company: $this->company,
            postingDate: Carbon::parse('2026-09-10'),
            sourceType: 'invoice',
            sourceId: 1002,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $idempotencyKey,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '250.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '250.000000'),
            ]
        );

        try {
            $this->service->post($conflictingCommand);
            $this->fail('Expected IdempotencyConflictException for changed amount.');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('conflicting financial command', $e->getMessage());
        }

        $this->assertSame($batchesCountBefore, PostingBatch::where('company_id', $this->company->id)->count());
        $this->assertSame($linesCountBefore, PostingLine::where('company_id', $this->company->id)->count());
    }

    public function test_same_key_with_different_account_raises_conflict_and_creates_no_records(): void
    {
        $idempotencyKey = 'conflict-account-key';

        $originalCommand = new PostingCommand(
            company: $this->company,
            postingDate: Carbon::parse('2026-09-10'),
            sourceType: 'invoice',
            sourceId: 1003,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $idempotencyKey,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        );

        $this->service->post($originalCommand);

        $batchesCountBefore = PostingBatch::where('company_id', $this->company->id)->count();
        $linesCountBefore = PostingLine::where('company_id', $this->company->id)->count();

        // Conflicting command with same key and amount, but different debit account (expense instead of cash)
        $conflictingCommand = new PostingCommand(
            company: $this->company,
            postingDate: Carbon::parse('2026-09-10'),
            sourceType: 'invoice',
            sourceId: 1003,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $idempotencyKey,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->expense->id, '100.000000'), // Different account!
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        );

        try {
            $this->service->post($conflictingCommand);
            $this->fail('Expected IdempotencyConflictException for changed account.');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('conflicting financial command', $e->getMessage());
        }

        $this->assertSame($batchesCountBefore, PostingBatch::where('company_id', $this->company->id)->count());
        $this->assertSame($linesCountBefore, PostingLine::where('company_id', $this->company->id)->count());
    }

    public function test_same_key_with_different_date_raises_conflict(): void
    {
        $idempotencyKey = 'conflict-date-key';

        $cmd1 = new PostingCommand(
            company: $this->company,
            postingDate: Carbon::parse('2026-09-01'),
            sourceType: 'sale',
            sourceId: 1004,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $idempotencyKey,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '50.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '50.000000'),
            ]
        );

        $this->service->post($cmd1);

        $cmd2 = new PostingCommand(
            company: $this->company,
            postingDate: Carbon::parse('2026-09-02'), // Different date!
            sourceType: 'sale',
            sourceId: 1004,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $idempotencyKey,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '50.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '50.000000'),
            ]
        );

        $this->expectException(IdempotencyConflictException::class);
        $this->service->post($cmd2);
    }

    public function test_reusing_idempotency_key_of_reversed_batch_is_rejected(): void
    {
        $idempotencyKey = 'reversed-batch-reuse-key';

        $command = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 1005,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $idempotencyKey,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '80.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '80.000000'),
            ]
        );

        $batch = $this->service->post($command);

        // Reverse the batch
        app(AccountingReversalService::class)->reverse($batch, $this->user);

        // Re-posting with the same idempotency key must raise IdempotencyConflictException::alreadyReversed
        $this->expectException(IdempotencyConflictException::class);
        $this->expectExceptionMessage('references an already-reversed posting batch');

        $this->service->post($command);
    }

    public function test_retry_with_metadata_removed_raises_conflict(): void
    {
        CompanyCurrency::firstOrCreate([
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
            'enabled' => true,
        ]);

        $key = 'metadata-removal-key';

        $cmdWithMeta = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 2001,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $key,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '370.000000', 'USD', '100.000000', ExchangeRate::from('3.7000000000')),
                PostingLineCommand::credit(2, $this->revenue->id, '370.000000', 'USD', '100.000000', ExchangeRate::from('3.7000000000')),
            ]
        );

        $this->service->post($cmdWithMeta);

        // Retry with same amounts but metadata stripped (null)
        $cmdWithoutMeta = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 2001,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $key,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '370.000000', null, null, null),
                PostingLineCommand::credit(2, $this->revenue->id, '370.000000', null, null, null),
            ]
        );

        $this->expectException(IdempotencyConflictException::class);
        $this->expectExceptionMessage('conflicting financial command');

        $this->service->post($cmdWithoutMeta);
    }

    public function test_retry_with_metadata_added_raises_conflict(): void
    {
        CompanyCurrency::firstOrCreate([
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
            'enabled' => true,
        ]);

        $key = 'metadata-addition-key';

        $cmdWithoutMeta = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 2002,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $key,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '370.000000', null, null, null),
                PostingLineCommand::credit(2, $this->revenue->id, '370.000000', null, null, null),
            ]
        );

        $this->service->post($cmdWithoutMeta);

        // Retry with metadata added
        $cmdWithMeta = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 2002,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $key,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '370.000000', 'USD', '100.000000', ExchangeRate::from('3.7000000000')),
                PostingLineCommand::credit(2, $this->revenue->id, '370.000000', 'USD', '100.000000', ExchangeRate::from('3.7000000000')),
            ]
        );

        $this->expectException(IdempotencyConflictException::class);
        $this->expectExceptionMessage('conflicting financial command');

        $this->service->post($cmdWithMeta);
    }

    public function test_retry_with_mismatched_description_raises_conflict(): void
    {
        $key = 'description-mismatch-key';

        $cmd1 = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 2003,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $key,
            postedBy: $this->user,
            description: 'Original Batch Description',
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000', description: 'Original Line Description'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        );

        $this->service->post($cmd1);

        $cmd2 = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 2003,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $key,
            postedBy: $this->user,
            description: 'Changed Batch Description',
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000', description: 'Original Line Description'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        );

        $this->expectException(IdempotencyConflictException::class);
        $this->expectExceptionMessage('conflicting financial command');

        $this->service->post($cmd2);
    }

    public function test_retry_with_reordered_lines_matches_deterministically_without_duplicate(): void
    {
        $key = 'reordered-lines-idempotent-key';

        $cmdOriginalOrder = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 2004,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $key,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '250.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '250.000000'),
            ]
        );

        $batch1 = $this->service->post($cmdOriginalOrder);
        $batchCount = PostingBatch::where('company_id', $this->company->id)->count();
        $lineCount = PostingLine::where('company_id', $this->company->id)->count();

        // Retry with reversed line array order: Line 2 then Line 1
        $cmdReordered = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 2004,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: $key,
            postedBy: $this->user,
            lines: [
                PostingLineCommand::credit(2, $this->revenue->id, '250.000000'),
                PostingLineCommand::debit(1, $this->cash->id, '250.000000'),
            ]
        );

        $batch2 = $this->service->post($cmdReordered);

        $this->assertSame($batch1->id, $batch2->id);
        $this->assertSame($batchCount, PostingBatch::where('company_id', $this->company->id)->count());
        $this->assertSame($lineCount, PostingLine::where('company_id', $this->company->id)->count());
    }
}
