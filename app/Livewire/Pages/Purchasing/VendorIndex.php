<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Vendor;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class VendorIndex extends Component
{
    use AuthorizesPurchasingPages;
    use WithPagination;

    public function mount(CompanyContext $context): void
    {
        $this->pageCompanyId = $context->companyId();
        $this->authorizePurchasing('vendors.view');
    }

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render(CompanyContext $context): View
    {
        $company = $this->authorizePurchasing('vendors.view');
        $user = auth()->user();

        $canManage = $user->hasPermissionTo('vendors.manage');

        $query = Vendor::where('company_id', $company->id);

        if (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function (Builder $q) use ($term) {
                $q->where('name_ar', 'like', "%{$term}%")
                    ->orWhere('name_en', 'like', "%{$term}%")
                    ->orWhere('business_name_ar', 'like', "%{$term}%")
                    ->orWhere('business_name_en', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('whatsapp', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        if ($this->statusFilter === 'active') {
            $query->where('status', 'active');
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('status', 'inactive');
        }

        $vendors = $query->orderBy('name_ar')->paginate(15);

        return view('livewire.pages.purchasing.vendor-index', [
            'vendors' => $vendors,
            'canManage' => $canManage,
            'company' => $company,
        ]);
    }
}
