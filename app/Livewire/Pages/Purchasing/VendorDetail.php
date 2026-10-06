<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Domain\Purchasing\Queries\VendorBalanceQuery;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class VendorDetail extends Component
{
    use AuthorizesPurchasingPages;
    use WithPagination;

    #[Locked]
    public Vendor $vendor;

    #[Url(as: 'tab')]
    public string $activeTab = 'overview';

    // Statement filters
    public ?string $statementFrom = null;

    public ?string $statementTo = null;

    public function mount(string $publicId, CompanyContext $context): void
    {
        $this->pageCompanyId = (int) $context->companyId();
        $company = $this->authorizePurchasing('vendors.view');

        $this->vendor = Vendor::withTrashed()
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $this->statementFrom = Carbon::now()->startOfYear()->toDateString();
        $this->statementTo = Carbon::now()->toDateString();
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    public function render(CompanyContext $context, VendorStatementQuery $statementQuery): View
    {
        $company = $this->authorizePurchasing('vendors.view');
        $user = auth()->user();

        $this->vendor = Vendor::withTrashed()->where('company_id', $company->id)->findOrFail($this->vendor->id);

        $canManage = $user->hasPermissionTo('vendors.manage');
        $withCost = $this->canReadVendorFinancials();
        $canStatement = $withCost && $user->hasPermissionTo('vendors.statement.view');
        $canCreatePurchase = $user->hasPermissionTo('purchasing.cost.view') && $user->hasPermissionTo('purchasing.purchase.create');
        $canCreatePayment = $withCost && $user->hasPermissionTo('money.vendor_payment.create');

        if ($this->activeTab === 'payments') {
            $this->authorizePaymentFinancialRead();
        }

        if (! $withCost && $this->activeTab !== 'overview') {
            $this->activeTab = 'overview';
        }

        $currencyBalances = [];
        $tabPurchases = [];
        $tabReturns = [];
        $tabPayments = [];
        $statementData = null;

        if ($withCost) {
            $balancesByVendor = app(VendorBalanceQuery::class)->execute([$this->vendor->id]);
            $currencyBalances = $balancesByVendor[$this->vendor->id] ?? [];

            if ($this->activeTab === 'purchases') {
                $tabPurchases = Purchase::where('company_id', $company->id)
                    ->where('vendor_id', $this->vendor->id)
                    ->orderByDesc('purchase_date')
                    ->orderByDesc('id')
                    ->paginate(15);
            } elseif ($this->activeTab === 'returns') {
                $tabReturns = PurchaseReturn::where('company_id', $company->id)
                    ->where('vendor_id', $this->vendor->id)
                    ->orderByDesc('return_date')
                    ->orderByDesc('id')
                    ->paginate(15);
            } elseif ($this->activeTab === 'payments') {
                $tabPayments = VendorPayment::with('moneyAccount')
                    ->where('company_id', $company->id)
                    ->where('vendor_id', $this->vendor->id)
                    ->orderByDesc('payment_date')
                    ->orderByDesc('id')
                    ->paginate(15);
            } elseif ($this->activeTab === 'statement' && $canStatement) {
                $statementData = $statementQuery->execute($this->vendor, $this->statementFrom ?: null, $this->statementTo ?: null);
            }
        }

        return view('livewire.pages.purchasing.vendor-detail', [
            'canManage' => $canManage,
            'withCost' => $withCost,
            'canStatement' => $canStatement,
            'canCreatePurchase' => $canCreatePurchase,
            'canCreatePayment' => $canCreatePayment,
            'currencyBalances' => $currencyBalances,
            'tabPurchases' => $tabPurchases,
            'tabReturns' => $tabReturns,
            'tabPayments' => $tabPayments,
            'statementData' => $statementData,
            'company' => $company,
        ]);
    }
}
