<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DocumentSequence;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SalesBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->company = $creator->execute($this->user, [
            'name_ar' => 'شركة البداية والتهيئة',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
    }

    public function test_new_company_creation_automatically_provisions_sequences_and_cash_account(): void
    {
        // 1. Verify 4 document sequences exist
        $sequences = DocumentSequence::where('company_id', $this->company->id)->whereIn('document_type', [
            DocumentSequence::TYPE_QUOTATION, DocumentSequence::TYPE_SALES_INVOICE,
            DocumentSequence::TYPE_SALES_RETURN, DocumentSequence::TYPE_CUSTOMER_PAYMENT,
        ])->get();
        $this->assertCount(4, $sequences);

        $types = $sequences->pluck('document_type')->all();
        $this->assertContains(DocumentSequence::TYPE_QUOTATION, $types);
        $this->assertContains(DocumentSequence::TYPE_SALES_INVOICE, $types);
        $this->assertContains(DocumentSequence::TYPE_SALES_RETURN, $types);
        $this->assertContains(DocumentSequence::TYPE_CUSTOMER_PAYMENT, $types);

        // 2. Verify default Cash Account exists in base currency (ILS)
        $cashAccount = MoneyAccount::where('company_id', $this->company->id)
            ->where('account_type', MoneyAccount::TYPE_CASH)
            ->first();

        $this->assertNotNull($cashAccount);
        $this->assertSame('ILS', $cashAccount->currency_code);
        $this->assertSame('الصندوق - ILS', $cashAccount->name_ar);
        $this->assertNotNull($cashAccount->ledger_account_id);

        // 3. Verify ZERO business transactions or master records were created
        $this->assertSame(0, Customer::where('company_id', $this->company->id)->count());
        $this->assertSame(0, Product::where('company_id', $this->company->id)->count());
        $this->assertSame(0, Quotation::where('company_id', $this->company->id)->count());
        $this->assertSame(0, SalesInvoice::where('company_id', $this->company->id)->count());
        $this->assertSame(0, SalesReturn::where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::where('company_id', $this->company->id)->count());
        $this->assertSame(0, StockMovement::where('company_id', $this->company->id)->count());
    }

    public function test_sales_bootstrap_command_is_idempotent_on_all_companies(): void
    {
        app(CompanyContext::class)->clear();
        // First run
        $exitCode1 = Artisan::call('sales:bootstrap', ['--all' => true]);
        $this->assertSame(0, $exitCode1);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $seqCount1 = DocumentSequence::where('company_id', $this->company->id)->count();
        $moneyCount1 = MoneyAccount::where('company_id', $this->company->id)->count();

        app(CompanyContext::class)->clear();
        // Second run
        $exitCode2 = Artisan::call('sales:bootstrap', ['--all' => true]);
        $this->assertSame(0, $exitCode2);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $seqCount2 = DocumentSequence::where('company_id', $this->company->id)->count();
        $moneyCount2 = MoneyAccount::where('company_id', $this->company->id)->count();

        // Exactly unchanged
        $this->assertSame($seqCount1, $seqCount2);
        $this->assertSame($moneyCount1, $moneyCount2);
    }

    public function test_sales_bootstrap_command_on_single_company(): void
    {
        app(CompanyContext::class)->clear();
        $exitCode = Artisan::call('sales:bootstrap', [
            'companyPublicId' => $this->company->public_id,
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_sales_bootstrap_never_creates_business_records(): void
    {
        app(CompanyContext::class)->clear();
        Artisan::call('sales:bootstrap', ['--all' => true]);
        Artisan::call('sales:bootstrap', ['companyPublicId' => $this->company->public_id]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Quotation::count());
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, SalesReturn::count());
        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(0, StockMovement::count());
    }
}
