<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Customers;

use App\Application\Reporting\Security\ReportingGuard;
use App\Domain\Sales\Queries\CustomerBalanceQuery;
use App\Domain\Sales\Queries\CustomerCreditLimitQuery;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class CustomerDetail extends Component
{
    public Customer $customer;

    #[Locked]
    public int $pageCompanyId;

    #[Locked]
    public string $customerPublicId;

    #[Url(as: 'tab')]
    public string $activeTab = 'overview';

    // Statement filters
    public ?string $statementFrom = null;

    public ?string $statementTo = null;

    public function mount(string $publicId, CompanyContext $context): void
    {
        $company = $context->company();
        app(ReportingGuard::class)->authorize($company, auth()->user(), 'customers.view');
        $this->pageCompanyId = (int) $company->id;
        $this->customerPublicId = $publicId;

        $this->customer = Customer::where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $this->statementFrom = Carbon::now($company->timezone)->startOfYear()->toDateString();
        $this->statementTo = Carbon::now($company->timezone)->toDateString();
    }

    public function render(CompanyContext $context, CustomerStatementQuery $statementQuery): View
    {
        abort_unless($context->hasCompany() && (int) $context->companyId() === $this->pageCompanyId, 403);
        $company = $context->company();
        $permissions = app(ReportingGuard::class)->readPermissions($company, auth()->user(), 'customers.view');
        $this->customer = Customer::where('company_id', $this->pageCompanyId)
            ->where('public_id', $this->customerPublicId)->firstOrFail();

        $canManage = in_array('customers.manage', $permissions, true);
        $canInvoice = in_array('sales.invoice.create', $permissions, true);
        $canQuote = in_array('sales.quote.create', $permissions, true);
        $canPayment = in_array('money.receipt.create', $permissions, true);
        $canStatement = in_array('sales.statement.view', $permissions, true);

        $defaultCurr = $this->customer->default_currency_code ?? $company->base_currency_code;
        $currencyBalances = app(CustomerBalanceQuery::class)->execute([$this->customer->id])[$this->customer->id] ?? [];
        $currencyBalances[$defaultCurr] ??= ['invoiced' => '0.000000', 'outstanding' => '0.000000'];
        foreach ($currencyBalances as &$balance) {
            $balance['invoiced'] = BigDecimal::of($balance['invoiced']);
            $balance['outstanding'] = BigDecimal::of($balance['outstanding']);
        }
        unset($balance);

        $defaultOutstanding = $currencyBalances[$defaultCurr]['outstanding'];
        $defaultInvoiced = $currencyBalances[$defaultCurr]['invoiced'];

        $isOverCreditLimit = app(CustomerCreditLimitQuery::class)->exceeds($this->customer, BigDecimal::zero());

        $formattedBalances = [];
        foreach ($currencyBalances as $curr => $b) {
            $formattedBalances[$curr] = [
                'invoiced' => (string) $b['invoiced'],
                'outstanding' => (string) $b['outstanding'],
            ];
        }

        // Tab data
        $tabInvoices = [];
        $tabQuotations = [];
        $tabPayments = [];
        $tabReturns = [];
        $statementData = null;

        if ($this->activeTab === 'invoices') {
            $tabInvoices = SalesInvoice::where('company_id', $company->id)
                ->where('customer_id', $this->customer->id)
                ->orderByDesc('issue_date')
                ->orderByDesc('id')
                ->paginate(15);
        } elseif ($this->activeTab === 'quotations') {
            $tabQuotations = Quotation::where('company_id', $company->id)
                ->where('customer_id', $this->customer->id)
                ->orderByDesc('issue_date')
                ->orderByDesc('id')
                ->paginate(15);
        } elseif ($this->activeTab === 'payments') {
            $tabPayments = CustomerPayment::with('moneyAccount')
                ->where('company_id', $company->id)
                ->where('customer_id', $this->customer->id)
                ->orderByDesc('payment_date')
                ->orderByDesc('id')
                ->paginate(15);
        } elseif ($this->activeTab === 'returns') {
            $tabReturns = SalesReturn::where('company_id', $company->id)
                ->where('customer_id', $this->customer->id)
                ->orderByDesc('issue_date')
                ->orderByDesc('id')
                ->paginate(15);
        } elseif ($this->activeTab === 'statement' && $canStatement) {
            $statementData = $statementQuery->execute($this->customer, $this->statementFrom ?: null, $this->statementTo ?: null);
        }

        return view('livewire.pages.customers.customer-detail', [
            'defaultCurr' => $defaultCurr,
            'baseCurrency' => $company->base_currency_code,
            'canManage' => $canManage,
            'canInvoice' => $canInvoice,
            'canQuote' => $canQuote,
            'canPayment' => $canPayment,
            'canStatement' => $canStatement,
            'totalInvoiced' => (string) $defaultInvoiced,
            'totalOutstanding' => (string) $defaultOutstanding,
            'currencyBalances' => $formattedBalances,
            'isOverCreditLimit' => $isOverCreditLimit,
            'tabInvoices' => $tabInvoices,
            'tabQuotations' => $tabQuotations,
            'tabPayments' => $tabPayments,
            'tabReturns' => $tabReturns,
            'statementData' => $statementData,
        ]);
    }
}
