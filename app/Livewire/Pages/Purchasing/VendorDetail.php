<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Domain\Purchasing\Queries\VendorBalanceQuery;
use App\Domain\Purchasing\Queries\VendorProductHistoryQuery;
use App\Domain\Purchasing\Queries\VendorProductPriceHistoryQuery;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\Purchasing\VendorPaymentValidationException;
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

    #[Url(as: 'product_id')]
    public ?int $selectedProductId = null;

    public function mount(string $publicId, CompanyContext $context): void
    {
        $this->pageCompanyId = (int) $context->companyId();
        $company = $this->authorizePurchasing('vendors.view');

        $this->vendor = Vendor::withTrashed()
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $companyNow = Carbon::now($company->timezone);
        $this->statementFrom = $companyNow->copy()->startOfYear()->toDateString();
        $this->statementTo = $companyNow->toDateString();
    }

    public function updatedActiveTab(): void
    {
        $this->selectedProductId = null;
        $this->resetPage();
    }

    public function selectProductFilter(?int $productId): void
    {
        $this->selectedProductId = $productId;
        $this->resetPage();
    }

    public function render(CompanyContext $context, VendorStatementQuery $statementQuery): View
    {
        $company = $this->authorizePurchasing('vendors.view');
        $user = auth()->user();

        $this->vendor = Vendor::withTrashed()->where('company_id', $company->id)->findOrFail($this->vendor->id);

        $canManage = $user->hasPermissionTo('vendors.manage');
        $withCost = $this->canReadVendorFinancials();
        $canViewCost = $user->hasPermissionTo('purchasing.cost.view');
        $canViewPurchases = $user->hasPermissionTo('purchasing.purchase.view');
        $canViewProducts = $user->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage']);
        $canStatement = $withCost && $user->hasPermissionTo('vendors.statement.view');
        $canCreatePurchase = $user->hasPermissionTo('purchasing.cost.view') && $user->hasPermissionTo('purchasing.purchase.create');
        $canCreatePayment = $withCost && $user->hasPermissionTo('money.vendor_payment.create');

        if ($this->activeTab === 'payments') {
            $this->authorizePaymentFinancialRead();
        }

        if ($this->activeTab === 'products') {
            if (! $canViewCost) {
                $this->activeTab = 'overview';
            }
        } elseif (! $withCost && $this->activeTab !== 'overview') {
            $this->activeTab = 'overview';
        }

        $currencyBalances = [];
        $tabPurchases = [];
        $tabReturns = [];
        $tabPayments = [];
        $statementData = null;
        $vendorProducts = collect();
        $vendorPriceLines = collect();

        if ($canViewCost && $this->activeTab === 'products') {
            $vendorProducts = app(VendorProductHistoryQuery::class)->productsSupplied($this->vendor, $company->id);
            if ($this->selectedProductId !== null) {
                $vendorPriceLines = app(VendorProductPriceHistoryQuery::class)->execute($this->vendor, $this->selectedProductId, $company->id, limit: 20);
            } else {
                $vendorPriceLines = app(VendorProductHistoryQuery::class)->execute($this->vendor, $company->id, limit: 20);
            }
        }

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
                $this->resetErrorBag('statementDates');
                try {
                    $statementData = $statementQuery->execute($this->vendor, $this->statementFrom ?: null, $this->statementTo ?: null);
                } catch (VendorPaymentValidationException $exception) {
                    $this->addError('statementDates', __($exception->translationKey));
                }
            }
        }

        return view('livewire.pages.purchasing.vendor-detail', [
            'canManage' => $canManage,
            'withCost' => $withCost,
            'canViewCost' => $canViewCost,
            'canViewPurchases' => $canViewPurchases,
            'canViewProducts' => $canViewProducts,
            'canStatement' => $canStatement,
            'canCreatePurchase' => $canCreatePurchase,
            'canCreatePayment' => $canCreatePayment,
            'currencyBalances' => $currencyBalances,
            'tabPurchases' => $tabPurchases,
            'tabReturns' => $tabReturns,
            'tabPayments' => $tabPayments,
            'statementData' => $statementData,
            'vendorProducts' => $vendorProducts,
            'vendorPriceLines' => $vendorPriceLines,
            'selectedProductId' => $this->selectedProductId,
            'company' => $company,
        ]);
    }
}
