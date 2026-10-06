<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\MoneyAccount;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class PaymentIndex extends Component
{
    use AuthorizesPurchasingPages;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'vendor')]
    public ?int $vendorFilter = null;

    #[Url(as: 'account')]
    public ?int $accountFilter = null;

    public function boot(CompanyContext $context): void
    {
        if (isset($this->pageCompanyId)) {
            if (! $context->hasCompany() || (int) $context->companyId() !== $this->pageCompanyId) {
                abort(403);
            }
            $this->authorizePaymentFinancialRead();
        }
    }

    public function mount(CompanyContext $context): void
    {
        $this->pageCompanyId = (int) $context->companyId();
        $this->authorizePaymentFinancialRead();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedVendorFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAccountFilter(): void
    {
        $this->resetPage();
    }

    public function render(CompanyContext $context): View
    {
        $this->authorizePaymentFinancialRead();

        $companyId = $this->pageCompanyId;
        $user = auth()->user();
        $canCreate = $user->hasPermissionTo('money.vendor_payment.create');

        $query = VendorPayment::with(['vendor', 'moneyAccount', 'allocations'])
            ->where('company_id', $companyId);

        if (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function (Builder $q) use ($term) {
                $q->where('payment_number', 'like', "%{$term}%")
                    ->orWhere('reference_number', 'like', "%{$term}%")
                    ->orWhereHas('vendor', function (Builder $vQ) use ($term) {
                        $vQ->where('name_ar', 'like', "%{$term}%")
                            ->orWhere('name_en', 'like', "%{$term}%")
                            ->orWhere('code', 'like', "%{$term}%");
                    });
            });
        }

        if ($this->vendorFilter !== null) {
            $query->where('vendor_id', $this->vendorFilter);
        }

        if ($this->accountFilter !== null) {
            $query->where('money_account_id', $this->accountFilter);
        }

        $payments = $query->orderByDesc('payment_date')->orderByDesc('id')->paginate(15);
        $vendors = Vendor::where('company_id', $companyId)->orderBy('name_ar')->get();
        $accounts = MoneyAccount::where('company_id', $companyId)->where('is_active', true)->get();

        return view('livewire.pages.purchasing.payment-index', [
            'payments' => $payments,
            'vendors' => $vendors,
            'accounts' => $accounts,
            'canCreate' => $canCreate,
        ]);
    }
}
