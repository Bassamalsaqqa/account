<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\DeletePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Exceptions\NoActiveCompanyException;
use App\Livewire\Concerns\HasTablePageSize;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Purchase;
use App\Models\Vendor;
use App\Services\Purchasing\PurchasePayablePosition;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Layout('layouts.app')]
class PurchaseIndex extends Component
{
    use AuthorizesPurchasingPages;
    use HasTablePageSize;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'vendor')]
    public ?int $vendorFilter = null;

    #[Locked]
    public ?int $payingPurchaseId = null;

    #[On('close-payment-modal')]
    #[On('vendor-payment-recorded')]
    public function closePaymentModal(): void
    {
        $this->payingPurchaseId = null;
    }

    public function deleteDraft(string $publicId): void
    {
        $company = $this->authorizePurchasing('purchasing.purchase.edit_draft');
        $this->authorizePurchasing('purchasing.cost.view');
        $purchase = Purchase::where('company_id', $company->id)->where('public_id', $publicId)->firstOrFail();
        try {
            app(DeletePurchaseDraftAction::class)->execute($purchase, auth()->user());
            $this->resetPage();
            session()->flash('success', __('purchase_draft_delete.deleted'));
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());
        }
    }

    public function openPaymentModal(int $purchaseId): void
    {
        $company = $this->authorizePurchasing('money.vendor_payment.create');
        Purchase::where('company_id', $company->id)
            ->where('status', Purchase::STATUS_POSTED)->findOrFail($purchaseId);
        $this->payingPurchaseId = $purchaseId;
    }

    public function postPurchase(string $publicId): void
    {
        $company = $this->authorizePurchasing('purchasing.purchase.post');
        $this->authorizePurchasing('purchasing.cost.view');

        $purchase = Purchase::where('company_id', $company->id)->where('public_id', $publicId)->firstOrFail();
        if (! $purchase->isDraft()) {
            return;
        }

        try {
            app(PostPurchaseAction::class)->execute($purchase, auth()->user());
            session()->flash('success', __('purchasing.purchase_posted'));
        } catch (AuthorizationException|NoActiveCompanyException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($exception instanceof HttpException && $exception->getStatusCode() === 403) {
                throw $exception;
            }
            report($exception);
            $msg = $this->resolvePurchaseErrorMessage($exception);
            session()->flash('error', $msg);
        }
    }

    private function resolvePurchaseErrorMessage(\Throwable $e): string
    {
        if ($e instanceof \InvalidArgumentException) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'positive_post_total_required') || str_contains($msg, 'positive')) {
                return __('purchasing.positive_post_total_required');
            }
            if (str_contains($msg, 'lots') || str_contains($msg, 'lot') || str_contains($msg, 'expiry')) {
                return __('purchasing.lots_must_be_complete');
            }
        }

        return __('purchasing.post_integrity_failed');
    }

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
            $fields[] = 'grand_total_base';
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

        $purchases = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($this->tablePageSize());
        $payablePositions = [];
        if ($this->canReadVendorFinancials()) {
            $postedPurchases = $purchases->getCollection()->filter(fn ($p) => $p->status === Purchase::STATUS_POSTED);
            if ($postedPurchases->isNotEmpty()) {
                $postedPurchases->load(['returns', 'paymentAllocations.vendorPayment']);
                $payablePositions = PurchasePayablePosition::forPurchases($postedPurchases);
            }
        }

        return view('livewire.pages.purchasing.purchase-index', [
            'purchases' => $purchases,
            'payablePositions' => $payablePositions,
            'vendors' => Vendor::where('company_id', $company->id)->orderBy('name_ar')->get(['id', 'name_ar', 'name_en']),
            'withCost' => $withCost,
            'canCreate' => $withCost && auth()->user()->hasPermissionTo('purchasing.purchase.create'),
        ]);
    }
}
