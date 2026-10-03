<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Purchase;
use App\Services\Purchasing\DuplicateVendorInvoice;
use App\Services\Purchasing\PurchaseReadModel;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseDetail extends Component
{
    use AuthorizesPurchasingPages;

    #[Locked]
    public string $publicId;

    public function mount(string $publicId, CompanyContext $context): void
    {
        $this->pageCompanyId = $context->companyId();
        $company = $this->authorizePurchasing('purchasing.purchase.view');
        Purchase::where('company_id', $company->id)->where('public_id', $publicId)->firstOrFail();
        $this->publicId = $publicId;
    }

    public function render(): View
    {
        $company = $this->authorizePurchasing('purchasing.purchase.view');
        $purchase = Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->firstOrFail();
        $withCost = auth()->user()->hasPermissionTo('purchasing.cost.view');

        return view('livewire.pages.purchasing.purchase-detail', [
            'document' => app(PurchaseReadModel::class)->detail($purchase, $withCost), 'withCost' => $withCost,
            'canEdit' => $purchase->isDraft() && $withCost && auth()->user()->hasPermissionTo('purchasing.purchase.edit_draft'),
            'duplicateWarning' => app(DuplicateVendorInvoice::class)->exists($company->id, $purchase->vendor_id, $purchase->vendor_invoice_number, $purchase->id),
        ]);
    }
}
