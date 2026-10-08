<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\AllocateLandedCostAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\RemoveLandedCostAllocationAction;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Expense;
use App\Models\LandedCostAllocation;
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

    public ?int $selectedExpenseId = null;

    public string $allocationMethod = 'value';

    /** @var array<int, string> */
    public array $manualAllocations = [];

    public ?string $landedError = null;

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
        $payablePosition = ($this->canReadVendorFinancials() && $purchase->isPosted()) ? $purchase->payablePosition() : null;

        $canReadLanded = $withCost && auth()->user()->hasPermissionTo('money.expense.view');
        $canManageLanded = $canReadLanded && $purchase->isDraft() && auth()->user()->hasPermissionTo('purchasing.landed_cost.manage');

        $landedAllocations = collect();
        $availableExpenses = collect();

        if ($canReadLanded) {
            $landedAllocations = LandedCostAllocation::where('company_id', $company->id)
                ->where('purchase_id', $purchase->id)
                ->with(['expense', 'purchaseLine'])
                ->get()
                ->groupBy('expense_id');

            if ($canManageLanded) {
                $alreadyAllocatedExpenseIds = LandedCostAllocation::where('company_id', $company->id)
                    ->whereIn('status', [LandedCostAllocation::STATUS_DRAFT, LandedCostAllocation::STATUS_LOCKED])
                    ->pluck('expense_id');

                $purchaseDate = $purchase->purchase_date->toDateString();

                $availableExpenses = Expense::where('company_id', $company->id)
                    ->where('status', Expense::STATUS_POSTED)
                    ->whereNull('reversed_at')
                    ->where('classification', Expense::CLASSIFICATION_LANDED_COST)
                    ->where('expense_date', '<=', $purchaseDate)
                    ->whereNotIn('id', $alreadyAllocatedExpenseIds)
                    ->orderByDesc('expense_date')
                    ->get();
            }
        }

        return view('livewire.pages.purchasing.purchase-detail', [
            'document' => app(PurchaseReadModel::class)->detail($purchase, $withCost),
            'withCost' => $withCost,
            'purchase' => $purchase,
            'payablePosition' => $payablePosition,
            'canPayVendor' => $payablePosition !== null && $payablePosition->hasOutstanding() && auth()->user()->hasPermissionTo('money.vendor_payment.create'),
            'canEdit' => $purchase->isDraft() && auth()->user()->hasPermissionTo('purchasing.purchase.edit_draft'),
            'canPost' => $purchase->isDraft() && auth()->user()->hasPermissionTo('purchasing.purchase.post'),
            'canCreateReturn' => $purchase->isPosted() && $withCost && auth()->user()->hasPermissionTo('purchasing.return.manage'),
            'duplicateWarning' => app(DuplicateVendorInvoice::class)->exists($company->id, $purchase->vendor_id, $purchase->vendor_invoice_number, $purchase->id),
            'canManageLanded' => $canManageLanded,
            'landedAllocations' => $landedAllocations,
            'availableExpenses' => $availableExpenses,
        ]);
    }

    public function attachLandedCost(): void
    {
        $company = $this->authorizePurchasing('purchasing.purchase.view');
        $this->authorizePurchasing('purchasing.landed_cost.manage');
        $this->authorizePurchasing('purchasing.cost.view');

        $purchase = Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->firstOrFail();
        if (! $purchase->isDraft()) {
            $this->landedError = __('purchasing.draft_effects_forbidden');

            return;
        }

        $this->validate([
            'selectedExpenseId' => 'required|integer',
            'allocationMethod' => 'required|in:value,quantity,manual',
            'manualAllocations' => 'array',
            'manualAllocations.*' => 'nullable|numeric|gte:0',
        ]);

        $expense = Expense::where('company_id', $company->id)->findOrFail($this->selectedExpenseId);

        try {
            app(AllocateLandedCostAction::class)->execute(
                $purchase,
                $expense,
                $this->allocationMethod,
                auth()->user(),
                $this->manualAllocations
            );
            $this->selectedExpenseId = null;
            $this->allocationMethod = 'value';
            $this->manualAllocations = [];
            $this->landedError = null;
            session()->flash('success', __('purchasing.landed_cost_attached'));
        } catch (\Throwable $e) {
            $this->landedError = __('purchasing.landed_cost_failed');
        }
    }

    public function removeLandedCost(int $expenseId): void
    {
        $company = $this->authorizePurchasing('purchasing.purchase.view');
        $this->authorizePurchasing('purchasing.landed_cost.manage');
        $this->authorizePurchasing('purchasing.cost.view');

        $purchase = Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->firstOrFail();
        if (! $purchase->isDraft()) {
            return;
        }

        $expense = Expense::where('company_id', $company->id)->findOrFail($expenseId);

        try {
            app(RemoveLandedCostAllocationAction::class)->execute($purchase, $expense, auth()->user());
            $this->landedError = null;
            session()->flash('success', __('purchasing.landed_cost_removed'));
        } catch (\Throwable $e) {
            $this->landedError = __('purchasing.landed_cost_failed');
        }
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
