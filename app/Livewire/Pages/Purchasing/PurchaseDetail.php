<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\PostPurchaseAction;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Purchase;
use App\Services\Purchasing\DuplicateVendorInvoice;
use App\Services\Purchasing\PurchaseReadModel;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $payablePosition = ($withCost && $purchase->isPosted()) ? $purchase->payablePosition() : null;

        return view('livewire.pages.purchasing.purchase-detail', [
            'document' => app(PurchaseReadModel::class)->detail($purchase, $withCost),
            'withCost' => $withCost,
            'purchase' => $purchase,
            'payablePosition' => $payablePosition,
            'canPayVendor' => $payablePosition !== null && $payablePosition->hasOutstanding() && auth()->user()->hasPermissionTo('money.vendor_payment.create'),
            'canEdit' => $purchase->isDraft() && $withCost && auth()->user()->hasPermissionTo('purchasing.purchase.edit_draft'),
            'canPost' => $purchase->isDraft() && $withCost && auth()->user()->hasPermissionTo('purchasing.purchase.post'),
            'canCreateReturn' => $purchase->isPosted() && $withCost && auth()->user()->hasPermissionTo('purchasing.return.manage'),
            'duplicateWarning' => app(DuplicateVendorInvoice::class)->exists($company->id, $purchase->vendor_id, $purchase->vendor_invoice_number, $purchase->id),
        ]);
    }

    public function post(): void
    {
        $company = $this->authorizePurchasing('purchasing.purchase.post');
        $this->authorizePurchasing('purchasing.cost.view');
        $purchase = Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->firstOrFail();
        try {
            app(PostPurchaseAction::class)->execute($purchase, auth()->user());
        } catch (\InvalidArgumentException|ModelNotFoundException|InvalidUnitConversionException|InvalidQuantityException $exception) {
            $this->addError('post', __('purchasing.post_integrity_failed'));

            return;
        }
        session()->flash('success', __('purchasing.purchase_posted'));
    }
}
