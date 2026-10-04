<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Purchase;
use App\Models\Vendor;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class PurchaseIndex extends Component
{
    use AuthorizesPurchasingPages;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'vendor')]
    public ?int $vendorFilter = null;

    public function mount(CompanyContext $context): void
    {
        $this->pageCompanyId = $context->companyId();
        $this->authorizePurchasing('purchasing.purchase.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedVendorFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $company = $this->authorizePurchasing('purchasing.purchase.view');
        $withCost = auth()->user()->hasPermissionTo('purchasing.cost.view');
        $fields = ['id', 'public_id', 'company_id', 'vendor_id', 'purchase_date', 'purchase_number', 'vendor_invoice_number', 'status', 'currency_code'];
        if ($withCost) {
            $fields[] = 'grand_total_currency';
        }
        $term = trim($this->search);
        $query = Purchase::where('company_id', $company->id)->select($fields)
            ->with(['vendor' => fn ($q) => $q->where('company_id', $company->id)->select(['id', 'name_ar', 'name_en'])]);
        if ($term !== '') {
            $query->where(fn ($q) => $q->where('vendor_invoice_number', 'like', "%{$term}%")
                ->orWhere('purchase_number', 'like', "%{$term}%")
                ->orWhereHas('vendor', fn ($v) => $v->where('company_id', $company->id)
                    ->where(fn ($names) => $names->where('name_ar', 'like', "%{$term}%")->orWhere('name_en', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"))));
        }
        if (in_array($this->statusFilter, [Purchase::STATUS_DRAFT, Purchase::STATUS_POSTED, Purchase::STATUS_VOID], true)) {
            $query->where('status', $this->statusFilter);
        }
        if ($this->vendorFilter !== null) {
            $query->where('vendor_id', $this->vendorFilter);
        }

        return view('livewire.pages.purchasing.purchase-index', [
            'purchases' => $query->orderByDesc('purchase_date')->orderByDesc('id')->paginate(15),
            'vendors' => Vendor::where('company_id', $company->id)->orderBy('name_ar')->get(['id', 'name_ar', 'name_en']),
            'withCost' => $withCost, 'canCreate' => $withCost && auth()->user()->hasPermissionTo('purchasing.purchase.create'),
        ]);
    }
}
