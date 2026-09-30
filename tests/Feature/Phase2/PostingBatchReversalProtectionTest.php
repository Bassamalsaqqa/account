<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Domain\Posting\Exceptions\PostingValidationException;
use App\Domain\Posting\Exceptions\ReversalException;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use App\Support\Tenancy\CompanyContext;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class PostingBatchReversalProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected AccountingPostingService $postingService;

    protected AccountingReversalService $reversalService;

    protected LedgerAccount $cash;

    protected LedgerAccount $revenue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة حماية سجلات العكس',
            'base_currency_code' => 'ILS',
        ]);

        $this->postingService = app(AccountingPostingService::class);
        $this->reversalService = app(AccountingReversalService::class);
        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->cash = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'cash_control')->firstOrFail();
        $this->revenue = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'sales_revenue')->firstOrFail();
    }

    public function test_forged_status_update_via_model_save_is_strictly_rejected(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 601,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'forged-status-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $this->expectException(ImmutableRecordException::class);
        $this->expectExceptionMessage('cannot be updated');

        // Direct model manipulation attempt
        $batch->status = PostingBatch::STATUS_REVERSED;
        $batch->save();
    }

    public function test_forged_update_via_model_update_method_is_strictly_rejected(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 602,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'forged-update-method-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $this->expectException(ImmutableRecordException::class);
        $this->expectExceptionMessage('cannot be updated');

        $batch->update(['status' => PostingBatch::STATUS_REVERSED]);
    }

    public function test_forged_reversal_link_update_via_model_save_is_strictly_rejected(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 603,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'forged-link-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $this->expectException(ImmutableRecordException::class);
        $this->expectExceptionMessage('cannot be updated');

        // Direct model link manipulation attempt
        $batch->reversed_by_batch_id = 99999;
        $batch->save();
    }

    public function test_forged_delete_via_model_delete_is_strictly_rejected(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 604,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'forged-delete-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $this->expectException(ImmutableRecordException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $batch->delete();
    }

    public function test_repeated_reversal_returns_same_batch_without_new_records(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 605,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'repeated-reversal-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $reversal1 = $this->reversalService->reverse($batch, $this->user, 'First reversal attempt');

        $batchCount = PostingBatch::where('company_id', $this->company->id)->count();
        $lineCount = PostingLine::where('company_id', $this->company->id)->count();

        // Second reversal on the same original batch
        $reversal2 = $this->reversalService->reverse($batch, $this->user, 'Second reversal attempt');

        $this->assertSame($reversal1->id, $reversal2->id);
        $this->assertSame($batchCount, PostingBatch::where('company_id', $this->company->id)->count());
        $this->assertSame($lineCount, PostingLine::where('company_id', $this->company->id)->count());
    }

    public function test_cannot_reverse_a_reversal_batch(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 606,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'reverse-reversal-test-orig',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $reversal = $this->reversalService->reverse($batch, $this->user);

        $this->expectException(ReversalException::class);
        $this->expectExceptionMessage("Cannot reverse a reversal batch [{$reversal->id}]. Reversals cannot be chained.");

        $this->reversalService->reverse($reversal, $this->user);
    }

    public function test_reversal_succeeds_even_if_account_was_subsequently_deactivated(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 607,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'deactivated-account-reversal-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        // Deactivate revenue account after posting
        $this->revenue->active = false;
        $this->revenue->save();

        // Reversal must still succeed
        $reversal = $this->reversalService->reverse($batch, $this->user, 'Reversing historical deactivated account');

        $this->assertSame(PostingBatch::STATUS_POSTED, $reversal->status);
        $this->assertSame(2, $reversal->lines()->count());

        $batch->refresh();
        $this->assertSame(PostingBatch::STATUS_REVERSED, $batch->status);
        $this->assertSame($reversal->id, $batch->reversed_by_batch_id);
    }

    public function test_canonical_reversal_service_is_atomic_and_rolls_back_on_failure(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 608,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'rollback-reversal-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $batchCountBefore = PostingBatch::where('company_id', $this->company->id)->count();
        $lineCountBefore = PostingLine::where('company_id', $this->company->id)->count();

        try {
            DB::transaction(function () use ($batch): void {
                $this->reversalService->reverse($batch, $this->user);
                throw new Exception('Simulated crash immediately after reversal call before commit.');
            });
        } catch (Exception $e) {
            $this->assertSame('Simulated crash immediately after reversal call before commit.', $e->getMessage());
        }

        // Original batch must remain untouched (still 'posted' and reversed_by_batch_id is null)
        $batch->refresh();
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);
        $this->assertNull($batch->reversed_by_batch_id);

        // No new batches or lines persisted
        $this->assertSame($batchCountBefore, PostingBatch::where('company_id', $this->company->id)->count());
        $this->assertSame($lineCountBefore, PostingLine::where('company_id', $this->company->id)->count());
    }

    public function test_caller_supplied_reversal_source_type_in_post_is_strictly_rejected(): void
    {
        $forgedCommand = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'reversal',
            sourceId: 609,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'forged-source-type-reversal-post',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        );

        $this->expectException(PostingValidationException::class);
        $this->expectExceptionMessage('Reversal postings and links can only be created via AccountingReversalService.');

        $this->postingService->post($forgedCommand);
    }

    public function test_canonical_reversal_via_reversal_service_succeeds_with_reciprocal_linkage(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 610,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'canonical-reversal-link-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $reversal = $this->reversalService->reverse($batch, $this->user, 'Authorized customer return');

        $batch->refresh();
        $this->assertSame(PostingBatch::STATUS_REVERSED, $batch->status);
        $this->assertSame($reversal->id, $batch->reversed_by_batch_id);
        $this->assertSame($batch->id, $reversal->reversal_of_id);
        $this->assertSame(PostingBatch::STATUS_POSTED, $reversal->status);
    }

    public function test_reversal_succeeds_with_maximum_length_english_original_description(): void
    {
        $maxEnglishDesc = str_repeat('E', 512);

        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 701,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'max-english-desc-post',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '150.000000', description: $maxEnglishDesc),
                PostingLineCommand::credit(2, $this->revenue->id, '150.000000', description: $maxEnglishDesc),
            ]
        ));

        $origLine = $batch->lines()->where('line_number', 1)->firstOrFail();
        $this->assertSame(512, mb_strlen((string) $origLine->description));

        $reversal = $this->reversalService->reverse($batch, $this->user);

        $this->assertSame(PostingBatch::STATUS_POSTED, $reversal->status);
        $revLine = $reversal->lines()->where('line_number', 1)->firstOrFail();
        $this->assertSame(512, mb_strlen((string) $revLine->description));
        $this->assertStringStartsWith('Reversal: EEEE', (string) $revLine->description);

        // Historical line on original batch remains untouched
        $origLineFresh = $batch->fresh()->lines()->where('line_number', 1)->firstOrFail();
        $this->assertSame($maxEnglishDesc, $origLineFresh->description);
        $this->assertSame(512, mb_strlen((string) $origLineFresh->description));
    }

    public function test_reversal_succeeds_with_maximum_length_arabic_original_description(): void
    {
        $arabicRepeated = str_repeat('قيد عكس محاسبي اختباري باللغة العربية ', 20);
        $maxArabicDesc = mb_substr($arabicRepeated, 0, 512);
        $this->assertSame(512, mb_strlen($maxArabicDesc));

        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 702,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'max-arabic-desc-post',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '200.000000', description: $maxArabicDesc),
                PostingLineCommand::credit(2, $this->revenue->id, '200.000000', description: $maxArabicDesc),
            ]
        ));

        $origLine = $batch->lines()->where('line_number', 1)->firstOrFail();
        $this->assertSame(512, mb_strlen((string) $origLine->description));

        $reversal = $this->reversalService->reverse($batch, $this->user);

        $this->assertSame(PostingBatch::STATUS_POSTED, $reversal->status);
        $revLine = $reversal->lines()->where('line_number', 1)->firstOrFail();
        $this->assertSame(512, mb_strlen((string) $revLine->description));
        $this->assertStringStartsWith('Reversal: قيد', (string) $revLine->description);

        // Original line description remains intact and untouched
        $origLineFresh = $batch->fresh()->lines()->where('line_number', 1)->firstOrFail();
        $this->assertSame($maxArabicDesc, $origLineFresh->description);
        $this->assertSame(512, mb_strlen((string) $origLineFresh->description));
    }

    public function test_reversal_succeeds_with_maximum_length_caller_reason(): void
    {
        $maxReason = str_repeat('R', 512);

        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 703,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'max-reason-desc-post',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $reversal = $this->reversalService->reverse($batch, $this->user, $maxReason);

        $this->assertSame(PostingBatch::STATUS_POSTED, $reversal->status);
        $this->assertSame(512, mb_strlen((string) $reversal->description));
        $this->assertStringStartsWith('Reversal: RRRR', (string) $reversal->description);

        $revLine = $reversal->lines()->firstOrFail();
        $this->assertSame(512, mb_strlen((string) $revLine->description));
        $this->assertStringStartsWith('Reversal: RRRR', (string) $revLine->description);
    }

    public function test_excessive_caller_reason_is_rejected_upfront_with_zero_partial_writes(): void
    {
        $excessiveReason = str_repeat('X', 513);

        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 704,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'excessive-reason-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $batchesBefore = PostingBatch::where('company_id', $this->company->id)->count();
        $linesBefore = PostingLine::where('company_id', $this->company->id)->count();

        try {
            $this->reversalService->reverse($batch, $this->user, $excessiveReason);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Reversal reason cannot exceed 512 characters.', $e->getMessage());
        }

        // Verify zero mutations occurred
        $this->assertSame($batchesBefore, PostingBatch::where('company_id', $this->company->id)->count());
        $this->assertSame($linesBefore, PostingLine::where('company_id', $this->company->id)->count());

        $batch->refresh();
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);
        $this->assertNull($batch->reversed_by_batch_id);
    }

    public function test_excessive_caller_reason_on_posting_service_is_rejected_upfront_with_zero_partial_writes(): void
    {
        $excessiveReason = str_repeat('Y', 513);

        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 705,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'excessive-reason-posting-svc-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $batchesBefore = PostingBatch::where('company_id', $this->company->id)->count();
        $linesBefore = PostingLine::where('company_id', $this->company->id)->count();

        try {
            $this->postingService->reverse($batch, $this->user, $excessiveReason);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Reversal reason cannot exceed 512 characters.', $e->getMessage());
        }

        $this->assertSame($batchesBefore, PostingBatch::where('company_id', $this->company->id)->count());
        $this->assertSame($linesBefore, PostingLine::where('company_id', $this->company->id)->count());

        $batch->refresh();
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);
        $this->assertNull($batch->reversed_by_batch_id);
    }

    public function test_corrupted_prior_reversal_with_mismatched_reversal_of_id_fails_with_zero_mutations(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 706,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'corrupted-prior-rev-of-id-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        // Create a second valid batch so reversal_of_id satisfies foreign key constraint but mismatches batch 1
        $batch2 = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 7062,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'corrupted-prior-rev-batch2',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '50.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '50.000000'),
            ]
        ));

        // Create corrupt prior reversal with wrong reversal_of_id
        $reversalKey = "reversal-batch-{$batch->public_id}";
        PostingBatch::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'batch_number' => null,
            'posting_date' => now()->toDateString(),
            'status' => PostingBatch::STATUS_POSTED,
            'source_type' => 'reversal',
            'source_id' => (int) $batch->id,
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'description' => 'Corrupt prior reversal with wrong reversal_of_id',
            'idempotency_key' => $reversalKey,
            'posted_by' => $this->user->id,
            'posted_at' => now(),
            'reversal_of_id' => $batch2->id, // Mismatched!
            'reversed_by_batch_id' => null,
        ]);

        $batchesBefore = PostingBatch::where('company_id', $this->company->id)->count();
        $linesBefore = PostingLine::where('company_id', $this->company->id)->count();

        $this->expectException(ReversalException::class);

        try {
            $this->reversalService->reverse($batch, $this->user);
        } finally {
            $this->assertSame($batchesBefore, PostingBatch::where('company_id', $this->company->id)->count());
            $this->assertSame($linesBefore, PostingLine::where('company_id', $this->company->id)->count());

            $batch->refresh();
            $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);
            $this->assertNull($batch->reversed_by_batch_id);
        }
    }

    public function test_corrupted_prior_reversal_with_mismatched_line_amounts_fails_with_zero_mutations(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 707,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'corrupted-prior-lines-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $reversalKey = "reversal-batch-{$batch->public_id}";
        $corruptReversal = PostingBatch::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'batch_number' => null,
            'posting_date' => now()->toDateString(),
            'status' => PostingBatch::STATUS_POSTED,
            'source_type' => 'reversal',
            'source_id' => (int) $batch->id,
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'description' => 'Corrupt prior reversal with mismatched line amount',
            'idempotency_key' => $reversalKey,
            'posted_by' => $this->user->id,
            'posted_at' => now(),
            'reversal_of_id' => $batch->id,
            'reversed_by_batch_id' => null,
        ]);

        // Lines with wrong amount (50.000000 instead of 100.000000)
        PostingLine::create([
            'company_id' => $this->company->id,
            'posting_batch_id' => $corruptReversal->id,
            'ledger_account_id' => $this->cash->id,
            'line_number' => 1,
            'description' => 'Corrupt line 1',
            'debit_base' => '0.000000',
            'credit_base' => '50.000000', // Should be 100.000000
            'created_at' => now(),
        ]);
        PostingLine::create([
            'company_id' => $this->company->id,
            'posting_batch_id' => $corruptReversal->id,
            'ledger_account_id' => $this->revenue->id,
            'line_number' => 2,
            'description' => 'Corrupt line 2',
            'debit_base' => '50.000000', // Should be 100.000000
            'credit_base' => '0.000000',
            'created_at' => now(),
        ]);

        $batchesBefore = PostingBatch::where('company_id', $this->company->id)->count();
        $linesBefore = PostingLine::where('company_id', $this->company->id)->count();

        $this->expectException(ReversalException::class);
        $this->expectExceptionMessage('does not reciprocate original line');

        try {
            $this->reversalService->reverse($batch, $this->user);
        } finally {
            $this->assertSame($batchesBefore, PostingBatch::where('company_id', $this->company->id)->count());
            $this->assertSame($linesBefore, PostingLine::where('company_id', $this->company->id)->count());

            $batch->refresh();
            $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);
            $this->assertNull($batch->reversed_by_batch_id);
        }
    }

    public function test_corrupted_prior_reversal_with_non_posted_status_fails_with_zero_mutations(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 708,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'corrupted-prior-status-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $reversalKey = "reversal-batch-{$batch->public_id}";
        PostingBatch::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'batch_number' => null,
            'posting_date' => now()->toDateString(),
            'status' => 'draft', // Non-posted status!
            'source_type' => 'reversal',
            'source_id' => (int) $batch->id,
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'description' => 'Corrupt prior reversal with non-posted status',
            'idempotency_key' => $reversalKey,
            'posted_by' => $this->user->id,
            'posted_at' => now(),
            'reversal_of_id' => $batch->id,
            'reversed_by_batch_id' => null,
        ]);

        $batchesBefore = PostingBatch::where('company_id', $this->company->id)->count();
        $linesBefore = PostingLine::where('company_id', $this->company->id)->count();

        $this->expectException(ReversalException::class);
        $this->expectExceptionMessage('expected [posted]');

        try {
            $this->reversalService->reverse($batch, $this->user);
        } finally {
            $this->assertSame($batchesBefore, PostingBatch::where('company_id', $this->company->id)->count());
            $this->assertSame($linesBefore, PostingLine::where('company_id', $this->company->id)->count());

            $batch->refresh();
            $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);
            $this->assertNull($batch->reversed_by_batch_id);
        }
    }

    public function test_corrupted_repeat_reversal_with_null_link_fails(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 709,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'corrupt-repeat-null-link',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        // Corrupt via direct DB update
        DB::table('posting_batches')->where('id', $batch->id)->update([
            'status' => PostingBatch::STATUS_REVERSED,
            'reversed_by_batch_id' => null,
        ]);

        $this->expectException(ReversalException::class);
        $this->expectExceptionMessage('has no reversed_by_batch_id link');

        $this->reversalService->reverse($batch, $this->user);
    }

    public function test_corrupted_repeat_reversal_with_missing_linked_batch_fails(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 710,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'corrupt-repeat-missing-link',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        // Corrupt via direct DB update to non-existent ID (disabling FK checks to simulate orphaned DB state)
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('posting_batches')->where('id', $batch->id)->update([
            'status' => PostingBatch::STATUS_REVERSED,
            'reversed_by_batch_id' => 99999999,
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->expectException(ReversalException::class);
        $this->expectExceptionMessage('does not exist');

        $this->reversalService->reverse($batch, $this->user);
    }

    public function test_corrupted_repeat_reversal_with_incoherent_lines_fails(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 711,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'corrupt-repeat-incoherent-lines',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        $reversal = $this->reversalService->reverse($batch, $this->user);

        // Tamper with the linked reversal line
        DB::table('posting_lines')->where('posting_batch_id', $reversal->id)->where('line_number', 1)->update([
            'credit_base' => '999.000000',
        ]);

        $this->expectException(ReversalException::class);
        $this->expectExceptionMessage('does not reciprocate original line');

        $this->reversalService->reverse($batch, $this->user);
    }

    public function test_prior_reversal_recovery_succeeds_when_coherent_and_links_original(): void
    {
        $batch = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 712,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'prior-rev-recovery-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        // Create coherent prior reversal batch simulating interrupted second step
        $reversalKey = "reversal-batch-{$batch->public_id}";
        $priorReversal = PostingBatch::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'batch_number' => null,
            'posting_date' => now()->toDateString(),
            'status' => PostingBatch::STATUS_POSTED,
            'source_type' => 'reversal',
            'source_id' => (int) $batch->id,
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'description' => "Reversal of batch #{$batch->public_id}",
            'idempotency_key' => $reversalKey,
            'posted_by' => $this->user->id,
            'posted_at' => now(),
            'reversal_of_id' => $batch->id,
            'reversed_by_batch_id' => null,
        ]);

        PostingLine::create([
            'company_id' => $this->company->id,
            'posting_batch_id' => $priorReversal->id,
            'ledger_account_id' => $this->cash->id,
            'line_number' => 1,
            'description' => 'Reversal of line 1',
            'debit_base' => '0.000000',
            'credit_base' => '100.000000',
            'transaction_currency_code' => null,
            'transaction_amount' => null,
            'exchange_rate' => null,
            'created_at' => now(),
        ]);
        PostingLine::create([
            'company_id' => $this->company->id,
            'posting_batch_id' => $priorReversal->id,
            'ledger_account_id' => $this->revenue->id,
            'line_number' => 2,
            'description' => 'Reversal of line 2',
            'debit_base' => '100.000000',
            'credit_base' => '0.000000',
            'transaction_currency_code' => null,
            'transaction_amount' => null,
            'exchange_rate' => null,
            'created_at' => now(),
        ]);

        $batchesCountBefore = PostingBatch::where('company_id', $this->company->id)->count();

        // Calling reverse on the unlinked original should recover and link the prior reversal
        $recovered = $this->reversalService->reverse($batch, $this->user);

        $this->assertSame($priorReversal->id, $recovered->id);

        $batch->refresh();
        $this->assertSame(PostingBatch::STATUS_REVERSED, $batch->status);
        $this->assertSame($priorReversal->id, $batch->reversed_by_batch_id);

        // No new batch was created
        $this->assertSame($batchesCountBefore, PostingBatch::where('company_id', $this->company->id)->count());
    }
}
