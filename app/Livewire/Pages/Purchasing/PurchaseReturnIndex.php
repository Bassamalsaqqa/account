<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\PurchaseReturn;
use App\Models\Vendor;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class PurchaseReturnIndex extends Component
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

    public function boot(): void
    {
        if (isset($this->pageCompanyId)) {
            $this->authorizePurchasing('purchasing.purchase.view');
        }
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
        $canCreate = $withCost && auth()->user()->hasPermissionTo('purchasing.return.manage');

        $fields = [
            'id', 'public_id', 'company_id', 'purchase_id', 'vendor_id',
            'return_date', 'return_number', 'status', 'currency_code',
        ];

        if ($withCost) {
            $fields[] = 'grand_total_currency';
        }

        $term = trim($this->search);
        $query = PurchaseReturn::where('company_id', $company->id)
            ->select($fields)
            ->with([
                'vendor' => fn ($q) => $q->withTrashed()->where('company_id', $company->id)->select(['id', 'name_ar', 'name_en', 'code']),
                'purchase' => fn ($q) => $q->where('company_id', $company->id)->select(['id', 'public_id', 'purchase_number']),
            ]);

        if ($term !== '') {
            $query->where(fn ($q) => $q->where('return_number', 'like', "%{$term}%")
                ->orWhereHas('purchase', fn ($p) => $p->where('company_id', $company->id)->where('purchase_number', 'like', "%{$term}%"))
                ->orWhereHas('vendor', fn ($v) => $v->withTrashed()->where('company_id', $company->id)
                    ->where(fn ($names) => $names->where('name_ar', 'like', "%{$term}%")
                        ->orWhere('name_en', 'like', "%{$term}%")
                        ->orWhere('code', 'like', "%{$term}%"))));
        }

        if (in_array($this->statusFilter, [PurchaseReturn::STATUS_DRAFT, PurchaseReturn::STATUS_POSTED], true)) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->vendorFilter !== null) {
            $query->where('vendor_id', $this->vendorFilter);
        }

        return view('livewire.pages.purchasing.purchase-return-index', [
            'returns' => $query->orderByDesc('return_date')->orderByDesc('id')->paginate(15),
            'vendors' => Vendor::withTrashed()->where('company_id', $company->id)->orderBy('name_ar')->get(['id', 'name_ar', 'name_en']),
            'withCost' => $withCost,
            'canCreate' => $canCreate,
        ]);
    }
}
