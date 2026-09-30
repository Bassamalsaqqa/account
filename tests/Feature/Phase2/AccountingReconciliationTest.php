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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected AccountingReconciliationService $reconciler;

    protected AccountingPostingService $postingService;

    protected LedgerAccount $cash;

    protected LedgerAccount $revenue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة فحص المطابقة',
            'base_currency_code' => 'ILS',
        ]);

        $this->reconciler = app(AccountingReconciliationService::class);
        $this->postingService = app(AccountingPostingService::class);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->cash = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'cash_control')->firstOrFail();
        $this->revenue = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'sales_revenue')->firstOrFail();
    }

    public function test_healthy_company_reports_no_violations(): void
    {
        // Post a normal valid transaction
        $command = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 201,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'reconcile-test-valid',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '300.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '300.000000'),
            ]
        );

        $this->postingService->post($command);

        $report = $this->reconciler->reconcile($this->company);

        $this->assertTrue($report->isHealthy);
        $this->assertEmpty($report->violations);
        $this->assertSame(1, $report->stats['batches_count']);
        $this->assertSame(2, $report->stats['lines_count']);

        // Check Artisan command exit code 0
        $this->artisan('accounting:reconcile', ['companyPublicId' => $this->company->public_id])
            ->assertSuccessful();
    }

    public function test_detects_missing_system_account(): void
    {
        // Delete one system account
        LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'inventory_loss')
            ->delete();

        $report = $this->reconciler->reconcile($this->company);

        $this->assertFalse($report->isHealthy);
        $this->assertNotEmpty($report->violations);
        $this->assertStringContainsString('Missing required system account with key [inventory_loss]', $report->violations[0]);

        // Check Artisan command returns FAILURE (exit code 1)
        $this->artisan('accounting:reconcile', ['companyPublicId' => $this->company->public_id])
            ->assertFailed();
    }

    public function test_detects_corrupted_unbalanced_batch(): void
    {
        // Post a valid batch first
        $command = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 202,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'reconcile-unbalance-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        );

        $batch = $this->postingService->post($command);

        // Corrupt via direct DB raw query (simulating low-level database corruption)
        DB::table('posting_lines')
            ->where('posting_batch_id', $batch->id)
            ->where('line_number', 1)
            ->update(['debit_base' => '999.000000']);

        $report = $this->reconciler->reconcile($this->company);

        $this->assertFalse($report->isHealthy);
        $hasImbalanceNotice = false;
        foreach ($report->violations as $v) {
            if (str_contains($v, 'Unbalanced batch') || str_contains($v, 'Whole-ledger imbalance')) {
                $hasImbalanceNotice = true;
            }
        }
        $this->assertTrue($hasImbalanceNotice);
    }

    public function test_detects_incoherent_reversal_status(): void
    {
        // Post a valid batch
        $command = new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 203,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'reconcile-reversal-incoherent',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '50.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '50.000000'),
            ]
        );

        $batch = $this->postingService->post($command);

        // Corrupt via raw DB update: mark as reversed without reversal link
        DB::table('posting_batches')
            ->where('id', $batch->id)
            ->update(['status' => 'reversed', 'reversed_by_batch_id' => null]);

        $report = $this->reconciler->reconcile($this->company);

        $this->assertFalse($report->isHealthy);
        $hasReversalNotice = false;
        foreach ($report->violations as $v) {
            if (str_contains($v, 'Incoherent reversal')) {
                $hasReversalNotice = true;
            }
        }
        $this->assertTrue($hasReversalNotice);
    }
}
