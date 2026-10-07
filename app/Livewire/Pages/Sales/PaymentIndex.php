<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
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
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'customer')]
    public ?int $customerFilter = null;

    #[Url(as: 'account')]
    public ?int $accountFilter = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCustomerFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAccountFilter(): void
    {
        $this->resetPage();
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('money.receipt.view')) {
            abort(403, 'Unauthorized.');
        }

        $canCreate = $user->hasPermissionTo('money.receipt.create');

        $query = CustomerPayment::with(['customer', 'moneyAccount', 'checkInstrument', 'allocations'])
            ->where('company_id', $company->id);

        if (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function (Builder $q) use ($term) {
                $q->where('payment_number', 'like', "%{$term}%")
                    ->orWhere('reference_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', function (Builder $cQ) use ($term) {
                        $cQ->where('name_ar', 'like', "%{$term}%")
                            ->orWhere('name_en', 'like', "%{$term}%")
                            ->orWhere('code', 'like', "%{$term}%");
                    });
            });
        }

        if ($this->customerFilter !== null) {
            $query->where('customer_id', $this->customerFilter);
        }

        if ($this->accountFilter !== null) {
            $query->where('money_account_id', $this->accountFilter);
        }

        $payments = $query->orderByDesc('payment_date')->orderByDesc('id')->paginate(15);
        $customers = Customer::where('company_id', $company->id)->orderBy('name_ar')->get();
        $accounts = MoneyAccount::where('company_id', $company->id)->where('is_active', true)->get();

        return view('livewire.pages.sales.payment-index', [
            'payments' => $payments,
            'customers' => $customers,
            'accounts' => $accounts,
            'canCreate' => $canCreate,
            'company' => $company,
        ]);
    }
}
