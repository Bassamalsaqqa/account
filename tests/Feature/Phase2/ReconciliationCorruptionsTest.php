<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Posting\AccountingPostingService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconciliationCorruptionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $companyA;

    protected Company $companyB;

    protected AccountingReconciliationService $reconciler;

    protected AccountingPostingService $postingService;

    protected LedgerAccount $cashA;

    protected LedgerAccount $revenueA;

    protected LedgerAccount $cashB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->companyA = $creator->execute($this->user, [
            'name_ar' => 'شركة فحص الفساد المالي أ',
            'base_currency_code' => 'ILS',
        ]);

        $this->companyB = $creator->execute($this->user, [
            'name_ar' => 'شركة فحص الفساد المالي ب',
            'base_currency_code' => 'ILS',
        ]);

        $this->reconciler = app(AccountingReconciliationService::class);
        $this->postingService = app(AccountingPostingService::class);
        CompanyScope::executeWithoutScope(function () {
            $this->cashA = LedgerAccount::where('company_id', $this->companyA->id)->where('system_key', 'cash_control')->firstOrFail();
            $this->revenueA = LedgerAccount::where('company_id', $this->companyA->id)->where('system_key', 'sales_revenue')->firstOrFail();
            $this->cashB = LedgerAccount::where('company_id', $this->companyB->id)->where('system_key', 'cash_control')->firstOrFail();
        });

        app(CompanyContext::class)->setCompany($this->companyA, $this->user);
    }

    public function test_detects_empty_batch_with_zero_lines(): void
    {
        // Insert a raw corrupted batch with zero lines
        $emptyBatchId = DB::table('posting_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->companyA->id,
            'batch_number' => 'EMPTY-001',
            'posting_date' => now()->toDateString(),
            'status' => 'posted',
            'source_type' => 'manual',
            'source_id' => 9999,
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'empty-batch-key',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = $this->reconciler->reconcile($this->companyA);

        $this->assertFalse($report->isHealthy);
        $foundEmptyNotice = false;
        foreach ($report->violations as $v) {
            if (str_contains($v, 'batch has only 0 line(s)')) {
                $foundEmptyNotice = true;
                break;
            }
        }
        $this->assertTrue($foundEmptyNotice, 'Reconciler failed to detect empty batch with zero lines.');
    }

    public function test_detects_one_line_batch(): void
    {
        // Insert a raw corrupted batch with only one line
        $batchId = DB::table('posting_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->companyA->id,
            'batch_number' => 'ONE-001',
            'posting_date' => now()->toDateString(),
            'status' => 'posted',
            'source_type' => 'manual',
            'source_id' => 9998,
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'one-line-batch-key',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('posting_lines')->insert([
            'company_id' => $this->companyA->id,
            'posting_batch_id' => $batchId,
            'ledger_account_id' => $this->cashA->id,
            'line_number' => 1,
            'debit_base' => '0.000000',
            'credit_base' => '0.000000',
            'transaction_currency_code' => 'ILS',
            'created_at' => now(),
        ]);

        $report = $this->reconciler->reconcile($this->companyA);

        $this->assertFalse($report->isHealthy);
        $foundOneLineNotice = false;
        foreach ($report->violations as $v) {
            if (str_contains($v, 'batch has only 1 line(s)')) {
                $foundOneLineNotice = true;
                break;
            }
        }
        $this->assertTrue($foundOneLineNotice, 'Reconciler failed to detect one-line batch.');
    }

    public function test_detects_bidirectional_cross_company_leaks(): void
    {
        // Valid batch in Company A
        $batchA = $this->postingService->post(new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 801,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'valid-batch-a',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashA->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenueA->id, '100.000000'),
            ]
        ));

        // Corrupt Line 1 to point to Company B's cash account
        DB::table('posting_lines')
            ->where('posting_batch_id', $batchA->id)
            ->where('line_number', 1)
            ->update(['ledger_account_id' => $this->cashB->id]);

        $report = $this->reconciler->reconcile($this->companyA);

        $this->assertFalse($report->isHealthy);
        $foundLeak = false;
        foreach ($report->violations as $v) {
            if (str_contains($v, 'Cross-company account leak')) {
                $foundLeak = true;
                break;
            }
        }
        $this->assertTrue($foundLeak, 'Reconciler failed to detect cross-company account leak.');
    }

    public function test_detects_orphan_reversal(): void
    {
        $origBatch = $this->postingService->post(new PostingCommand(
            company: $this->companyA,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 802,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'orig-batch-for-orphan',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cashA->id, '50.000000'),
                PostingLineCommand::credit(2, $this->revenueA->id, '50.000000'),
            ]
        ));

        // Insert a reversal batch pointing to origBatch, but origBatch is not marked reversed and does not point back
        DB::table('posting_batches')->insert([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->companyA->id,
            'batch_number' => 'REV-ORPHAN',
            'posting_date' => now()->toDateString(),
            'status' => 'posted',
            'source_type' => 'reversal',
            'source_id' => (int) $origBatch->id,
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'orphan-rev-key',
            'reversal_of_id' => $origBatch->id,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = $this->reconciler->reconcile($this->companyA);

        $this->assertFalse($report->isHealthy);
        $foundOrphanNotice = false;
        foreach ($report->violations as $v) {
            if (str_contains($v, 'Orphan reversal')) {
                $foundOrphanNotice = true;
                break;
            }
        }
        $this->assertTrue($foundOrphanNotice, 'Reconciler failed to detect orphan reversal.');
    }

    public function test_reconciler_is_strictly_read_only_and_performs_no_mutations(): void
    {
        // Record state hashes/counts
        $batchesBefore = DB::table('posting_batches')->get()->toArray();
        $linesBefore = DB::table('posting_lines')->get()->toArray();
        $accountsBefore = DB::table('ledger_accounts')->get()->toArray();

        // Run reconciliation
        $report = $this->reconciler->reconcile($this->companyA);

        $batchesAfter = DB::table('posting_batches')->get()->toArray();
        $linesAfter = DB::table('posting_lines')->get()->toArray();
        $accountsAfter = DB::table('ledger_accounts')->get()->toArray();

        $this->assertEquals($batchesBefore, $batchesAfter);
        $this->assertEquals($linesBefore, $linesAfter);
        $this->assertEquals($accountsBefore, $accountsAfter);
    }
}
