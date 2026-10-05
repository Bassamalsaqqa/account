<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\PurchaseReturn;
use App\Services\Purchasing\PurchaseReturnReadModel;
use App\Support\Tenancy\CompanyContext;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseReturnDetail extends Component
{
    use AuthorizesPurchasingPages;

    #[Locked]
    public string $publicId;

    public function mount(string $publicId, CompanyContext $context): void
    {
        $this->pageCompanyId = $context->companyId();
        $company = $this->authorizePurchasing('purchasing.purchase.view');
        PurchaseReturn::where('company_id', $company->id)->where('public_id', $publicId)->firstOrFail();
        $this->publicId = $publicId;
    }

    public function boot(): void
    {
        if (isset($this->pageCompanyId)) {
            $this->authorizePurchasing('purchasing.purchase.view');
        }
    }

    public function render(): View
    {
        $company = $this->authorizePurchasing('purchasing.purchase.view');
        $return = PurchaseReturn::where('company_id', $company->id)
            ->where('public_id', $this->publicId)
            ->firstOrFail();

        $withCost = auth()->user()->hasPermissionTo('purchasing.cost.view');
        $canManage = auth()->user()->hasPermissionTo('purchasing.return.manage');

        return view('livewire.pages.purchasing.purchase-return-detail', [
            'document' => app(PurchaseReturnReadModel::class)->detail($return, $withCost),
            'withCost' => $withCost,
            'canEdit' => $return->isDraft() && $withCost && $canManage,
            'canPost' => $return->isDraft() && $withCost && $canManage,
        ]);
    }

    public function post(): void
    {
        $company = $this->authorizePurchasing('purchasing.return.manage');
        $this->authorizePurchasing('purchasing.cost.view');

        $return = PurchaseReturn::where('company_id', $company->id)
            ->where('public_id', $this->publicId)
            ->firstOrFail();

        try {
            app(PostPurchaseReturnAction::class)->execute($return, auth()->user());
        } catch (InvalidArgumentException|DomainException|ModelNotFoundException|InvalidInventoryMovementException|InvalidUnitConversionException|InvalidQuantityException $exception) {
            $this->addError('post', __('purchasing.return_integrity_failed'));

            return;
        }

        session()->flash('success', __('purchasing.purchase_return_posted'));
    }
}
