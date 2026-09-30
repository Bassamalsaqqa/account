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
use App\Services\Posting\AccountingPostingService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected AccountingPostingService $service;

    protected LedgerAccount $cash;

    protected LedgerAccount $revenue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة فحص تكامل المخطط',
            'base_currency_code' => 'ILS',
        ]);

        $this->service = app(AccountingPostingService::class);
        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->cash = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'cash_control')->firstOrFail();
        $this->revenue = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'sales_revenue')->firstOrFail();
    }

    public function test_schema_column_types_and_lengths_match_blueprint(): void
    {
        // 1. posting_batches table
        $this->assertSame('bigint', Schema::getColumnType('posting_batches', 'source_id'));
        $this->assertContains(Schema::getColumnType('posting_batches', 'batch_number'), ['string', 'varchar']);
        $this->assertContains(Schema::getColumnType('posting_batches', 'description'), ['string', 'varchar']);

        // 2. posting_lines table
        $this->assertContains(Schema::getColumnType('posting_lines', 'description'), ['string', 'varchar']);

        // 3. ledger_accounts table
        $this->assertContains(Schema::getColumnType('ledger_accounts', 'name_ar'), ['string', 'varchar']);
        $this->assertContains(Schema::getColumnType('ledger_accounts', 'name_en'), ['string', 'varchar']);
    }

    public function test_foreign_keys_restrict_deletion_of_company_with_financial_records(): void
    {
        // Post a transaction so posting_batches and posting_lines exist
        $batch = $this->service->post(new PostingCommand(
            company: $this->company,
            postingDate: now(),
            sourceType: 'sale',
            sourceId: 701,
            transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS',
            exchangeRate: ExchangeRate::one(),
            idempotencyKey: 'schema-restrict-test',
            postedBy: $this->user,
            lines: [
                PostingLineCommand::debit(1, $this->cash->id, '100.000000'),
                PostingLineCommand::credit(2, $this->revenue->id, '100.000000'),
            ]
        ));

        // Attempting to delete the company via raw DB query must be blocked by foreign key constraint
        try {
            DB::table('companies')->where('id', $this->company->id)->delete();
            $this->fail('Expected QueryException because company deletion must be restricted by foreign keys.');
        } catch (QueryException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'foreign key constraint') ||
                str_contains($e->getMessage(), '1451') ||
                str_contains($e->getMessage(), 'FOREIGN KEY')
            );
        }

        // Attempting to delete the posting_batch directly via raw DB query must be restricted by posting_lines foreign key
        try {
            DB::table('posting_batches')->where('id', $batch->id)->delete();
            $this->fail('Expected QueryException because batch deletion must be restricted by lines foreign key.');
        } catch (QueryException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'foreign key constraint') ||
                str_contains($e->getMessage(), '1451') ||
                str_contains($e->getMessage(), 'FOREIGN KEY')
            );
        }

        // Attempting to delete a ledger account referenced by posting lines must be restricted
        try {
            DB::table('ledger_accounts')->where('id', $this->cash->id)->delete();
            $this->fail('Expected QueryException because account deletion must be restricted by lines foreign key.');
        } catch (QueryException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'foreign key constraint') ||
                str_contains($e->getMessage(), '1451') ||
                str_contains($e->getMessage(), 'FOREIGN KEY')
            );
        }
    }
}
