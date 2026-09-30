<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Actions\Accounting\PostOpeningBalancesAction;
use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
use App\Services\Accounting\DerivedLedgerBalanceService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OpeningBalancesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected PostOpeningBalancesAction $action;

    protected DerivedLedgerBalanceService $balanceService;

    protected LedgerAccount $cash;

    protected LedgerAccount $inventory;

    protected LedgerAccount $payables;

    protected LedgerAccount $obe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة فحص الأرصدة الافتتاحية',
            'base_currency_code' => 'ILS',
        ]);

        $this->action = app(PostOpeningBalancesAction::class);
        $this->balanceService = app(DerivedLedgerBalanceService::class);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->cash = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'cash_control')->firstOrFail();
        $this->inventory = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'inventory')->firstOrFail();
        $this->payables = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_payable')->firstOrFail();
        $this->obe = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'opening_balance_equity')->firstOrFail();
    }

    public function test_can_post_balanced_opening_balances(): void
    {
        // Assets: Cash 5000, Inventory 10000 (Total Assets = 15000)
        // Liabilities: Payables 3000 (Total Liabilities = 3000)
        // Net Opening Balance Equity should be 12000 (Credit)
        $batch = $this->action->execute(
            company: $this->company,
            date: now(),
            assetBalances: [
                ['account_id' => $this->cash->id, 'amount' => '5000.000000', 'description' => 'Opening cash'],
                ['account_id' => $this->inventory->id, 'amount' => '10000.000000', 'description' => 'Opening inventory'],
            ],
            liabilityBalances: [
                ['account_id' => $this->payables->id, 'amount' => '3000.000000', 'description' => 'Opening payable to supplier'],
            ],
            postedBy: $this->user,
        );

        $this->assertInstanceOf(PostingBatch::class, $batch);
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);
        $this->assertSame('opening_balance', $batch->source_type);

        // Verify account balances derived on-the-fly
        $cashBalance = $this->balanceService->getAccountBalance($this->cash);
        $this->assertSame('5000.000000', $cashBalance->toDecimalString());

        $invBalance = $this->balanceService->getAccountBalance($this->inventory);
        $this->assertSame('10000.000000', $invBalance->toDecimalString());

        $apBalance = $this->balanceService->getAccountBalance($this->payables);
        $this->assertSame('3000.000000', $apBalance->toDecimalString());

        // Opening Balance Equity (normal credit):
        // Credits = 15000 (from assets offset), Debits = 3000 (from liability offset) => Net Credit = 12000
        $obeBalance = $this->balanceService->getAccountBalance($this->obe);
        $this->assertSame('12000.000000', $obeBalance->toDecimalString());

        // Verify trial balance is in perfect balance
        $trialBalance = $this->balanceService->getTrialBalance($this->company);
        $this->assertTrue($trialBalance['is_balanced']);
    }

    public function test_empty_opening_balances_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one positive opening balance entry is required');

        $this->action->execute(
            company: $this->company,
            date: now(),
            assetBalances: [],
            liabilityBalances: [],
            postedBy: $this->user,
        );
    }

    public function test_negative_or_zero_entry_is_strictly_rejected(): void
    {
        $batchCountBefore = PostingBatch::count();
        $lineCountBefore = PostingLine::count();

        // 1. Zero amount
        try {
            $this->action->execute(
                company: $this->company,
                date: now(),
                assetBalances: [
                    ['account_id' => $this->cash->id, 'amount' => '0.000000'],
                ],
                postedBy: $this->user,
            );
            $this->fail('Expected InvalidArgumentException for zero amount');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must be strictly positive', $e->getMessage());
        }

        // 2. Negative amount
        try {
            $this->action->execute(
                company: $this->company,
                date: now(),
                assetBalances: [
                    ['account_id' => $this->cash->id, 'amount' => '-100.000000'],
                ],
                postedBy: $this->user,
            );
            $this->fail('Expected InvalidArgumentException for negative amount');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must be strictly positive', $e->getMessage());
        }

        // Verify zero mutations
        $this->assertSame($batchCountBefore, PostingBatch::count());
        $this->assertSame($lineCountBefore, PostingLine::count());
    }

    public function test_invalid_account_type_for_bucket_is_strictly_rejected(): void
    {
        $batchCountBefore = PostingBatch::count();
        $lineCountBefore = PostingLine::count();

        $expense = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'salary_expense')->firstOrFail();

        // Attempting to post expense as asset opening balance
        try {
            $this->action->execute(
                company: $this->company,
                date: now(),
                assetBalances: [
                    ['account_id' => $expense->id, 'amount' => '1000.000000'],
                ],
                postedBy: $this->user,
            );
            $this->fail('Expected InvalidArgumentException for wrong account type');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('expected [asset]', $e->getMessage());
        }

        // Attempting to post asset as liability opening balance
        try {
            $this->action->execute(
                company: $this->company,
                date: now(),
                liabilityBalances: [
                    ['account_id' => $this->cash->id, 'amount' => '1000.000000'],
                ],
                postedBy: $this->user,
            );
            $this->fail('Expected InvalidArgumentException for wrong account type');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('expected [liability]', $e->getMessage());
        }

        // Attempting to post liability as equity opening balance
        try {
            $this->action->execute(
                company: $this->company,
                date: now(),
                equityBalances: [
                    ['account_id' => $this->payables->id, 'amount' => '1000.000000'],
                ],
                postedBy: $this->user,
            );
            $this->fail('Expected InvalidArgumentException for wrong account type');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('expected [equity]', $e->getMessage());
        }

        // Verify zero mutations
        $this->assertSame($batchCountBefore, PostingBatch::count());
        $this->assertSame($lineCountBefore, PostingLine::count());
    }

    public function test_cross_company_account_is_strictly_rejected(): void
    {
        $batchCountBefore = PostingBatch::count();
        $lineCountBefore = PostingLine::count();

        // Create Company B (clear active context first)
        app(CompanyContext::class)->clear();
        $userB = User::factory()->create(['locale' => 'ar']);
        $companyB = app(CreateCompanyAction::class)->execute($userB, [
            'name_ar' => 'شركة ثانية لفحص العزل',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($companyB, $userB);
        $cashB = LedgerAccount::where('company_id', $companyB->id)->where('system_key', 'cash_control')->firstOrFail();

        // Switch back context to company A
        app(CompanyContext::class)->setCompany($this->company, $this->user);

        try {
            $this->action->execute(
                company: $this->company,
                date: now(),
                assetBalances: [
                    ['account_id' => $cashB->id, 'amount' => '2000.000000'],
                ],
                postedBy: $this->user,
            );
            $this->fail('Expected InvalidArgumentException for cross-company account');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("does not exist, is inactive, or does not belong to company [{$this->company->id}]", $e->getMessage());
        }

        // Verify zero mutations
        $this->assertSame($batchCountBefore, PostingBatch::count());
        $this->assertSame($lineCountBefore, PostingLine::count());
    }

    public function test_inactive_or_missing_opening_balance_equity_account_is_strictly_rejected(): void
    {
        $batchCountBefore = PostingBatch::count();
        $lineCountBefore = PostingLine::count();

        // Deactivate OBE account
        $this->obe->active = false;
        $this->obe->save();

        try {
            $this->action->execute(
                company: $this->company,
                date: now(),
                assetBalances: [
                    ['account_id' => $this->cash->id, 'amount' => '1000.000000'],
                ],
                postedBy: $this->user,
            );
            $this->fail('Expected InvalidArgumentException for inactive OBE');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Active Opening Balance Equity account not found', $e->getMessage());
        }

        // Restore OBE active state
        $this->obe->active = true;
        $this->obe->save();

        // Verify zero mutations
        $this->assertSame($batchCountBefore, PostingBatch::count());
        $this->assertSame($lineCountBefore, PostingLine::count());
    }
}
