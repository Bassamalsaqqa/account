<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Domain\Posting\Exceptions\PostingValidationException;
use App\Domain\Posting\Exceptions\ReversalException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingReversalTest extends TestCase
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
            'name_ar' => 'شركة فحص العكس',
            'base_currency_code' => 'ILS',
        ]);

        $this->postingService = app(AccountingPostingService::class);
        $this->reversalService = app(AccountingReversalService::class);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->cash = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'cash_control')
            ->firstOrFail();

        $this->revenue = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'sales_revenue')
            ->firstOrFail();
    }

    public function test_can_reverse_posted_batch_with_reciprocal_linkage(): void
    {
        // 1. Post original batch: Dr Cash 200, Cr Revenue 200
        $command = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 100,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'orig-batch-to-reverse',
            postedBy: $this->user,
            description: 'Original sale to reverse',
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '200.000000', 'ILS', '200.000000', ExchangeRate::one(), 'Dr Cash'),
                PostingLineCommand::credit(2, $this->revenue->id, '200.000000', 'ILS', '200.000000', ExchangeRate::one(), 'Cr Revenue'),
            ]
        );

        $original = $this->postingService->post($command);
        $this->assertTrue($original->isPosted());
        $this->assertNull($original->reversed_by_batch_id);

        // 2. Perform reversal
        $reversal = $this->reversalService->reverse($original, $this->user, 'Customer returned merchandise');

        // Refresh original from DB
        $original->refresh();

        $this->assertInstanceOf(PostingBatch::class, $reversal);
        $this->assertTrue($original->isReversed());
        $this->assertSame($reversal->id, $original->reversed_by_batch_id);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame(PostingBatch::STATUS_POSTED, $reversal->status);
        $this->assertSame('reversal', $reversal->source_type);
        $this->assertSame((int) $original->id, $reversal->source_id);

        // Verify opposite lines in reversal: Line 1 was Dr 200 => becomes Cr 200; Line 2 was Cr 200 => becomes Dr 200
        $reversalLines = $reversal->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $reversalLines);

        $this->assertSame('0.000000', $reversalLines[0]->debit_base);
        $this->assertSame('200.000000', $reversalLines[0]->credit_base);
        $this->assertSame($this->cash->id, $reversalLines[0]->ledger_account_id);

        $this->assertSame('200.000000', $reversalLines[1]->debit_base);
        $this->assertSame('0.000000', $reversalLines[1]->credit_base);
        $this->assertSame($this->revenue->id, $reversalLines[1]->ledger_account_id);
    }

    public function test_repeat_reversal_is_idempotent_and_returns_existing_reversal(): void
    {
        $command = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 101,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'orig-batch-repeat-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        );

        $original = $this->postingService->post($command);

        $reversal1 = $this->reversalService->reverse($original, $this->user);
        $batchCountBefore = PostingBatch::where('company_id', $this->company->id)->count();

        // Attempting to reverse again
        $reversal2 = $this->reversalService->reverse($original, $this->user);
        $batchCountAfter = PostingBatch::where('company_id', $this->company->id)->count();

        $this->assertSame($reversal1->id, $reversal2->id);
        $this->assertSame($batchCountBefore, $batchCountAfter);
    }

    public function test_cannot_reverse_a_reversal_batch(): void
    {
        $command = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 102,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'orig-batch-chain-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '50.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '50.000000'),
            ]
        );

        $original = $this->postingService->post($command);
        $reversal = $this->reversalService->reverse($original, $this->user);

        // Attempting to reverse the reversal batch must be strictly rejected
        $this->expectException(ReversalException::class);
        $this->expectExceptionMessage('Cannot reverse a reversal batch');

        $this->reversalService->reverse($reversal, $this->user, 'Attempting forbidden reversal of a reversal');
    }

    public function test_reversal_by_non_member_user_is_strictly_rejected_with_zero_mutations(): void
    {
        $original = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 103,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'orig-batch-nonmember-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '150.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '150.000000'),
            ]
        ));

        // Create a foreign user with no membership in $this->company
        $foreignUser = User::factory()->create(['locale' => 'ar']);

        $batchesCountBefore = PostingBatch::count();
        $linesCountBefore = PostingLine::count();

        try {
            $this->reversalService->reverse($original, $foreignUser, 'Attempted by non-member');
            $this->fail('Expected PostingValidationException for non-member user');
        } catch (PostingValidationException $e) {
            $this->assertStringContainsString('is not an active member of company', $e->getMessage());
        }

        // Verify batch state is untouched and zero mutations occurred
        $original->refresh();
        $this->assertSame(PostingBatch::STATUS_POSTED, $original->status);
        $this->assertNull($original->reversed_by_batch_id);
        $this->assertSame($batchesCountBefore, PostingBatch::count());
        $this->assertSame($linesCountBefore, PostingLine::count());
    }

    public function test_reversal_by_inactive_member_is_strictly_rejected_with_zero_mutations(): void
    {
        $original = $this->postingService->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 104,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'orig-batch-inactive-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '175.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '175.000000'),
            ]
        ));

        // Create an inactive member in $this->company
        $inactiveUser = User::factory()->create(['locale' => 'ar']);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $inactiveUser->id,
            'role' => 'accountant',
            'status' => 'inactive',
        ]);

        $batchesCountBefore = PostingBatch::count();
        $linesCountBefore = PostingLine::count();

        try {
            $this->reversalService->reverse($original, $inactiveUser, 'Attempted by inactive member');
            $this->fail('Expected PostingValidationException for inactive member');
        } catch (PostingValidationException $e) {
            $this->assertStringContainsString('is not an active member of company', $e->getMessage());
        }

        // Verify batch state is untouched and zero mutations occurred
        $original->refresh();
        $this->assertSame(PostingBatch::STATUS_POSTED, $original->status);
        $this->assertNull($original->reversed_by_batch_id);
        $this->assertSame($batchesCountBefore, PostingBatch::count());
        $this->assertSame($linesCountBefore, PostingLine::count());
    }
}
