<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Vendor;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class VendorDetail extends Component
{
    use AuthorizesPurchasingPages;

    #[Locked]
    public Vendor $vendor;

    public function mount(string $publicId, CompanyContext $context): void
    {
        $this->pageCompanyId = $context->companyId();
        $company = $this->authorizePurchasing('vendors.view');

        $this->vendor = Vendor::where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    public function render(CompanyContext $context): View
    {
        $company = $this->authorizePurchasing('vendors.view');
        $user = auth()->user();

        $this->vendor = Vendor::where('company_id', $company->id)->findOrFail($this->vendor->id);

        $canManage = $user->hasPermissionTo('vendors.manage');

        return view('livewire.pages.purchasing.vendor-detail', [
            'canManage' => $canManage,
            'company' => $company,
        ]);
    }
}
