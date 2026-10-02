<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Models\Customer;
use App\Models\Quotation;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class QuotationIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'customer')]
    public ?int $customerFilter = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCustomerFilter(): void
    {
        $this->resetPage();
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.quote.view')) {
            abort(403, 'Unauthorized.');
        }

        $canCreate = $user->hasPermissionTo('sales.quote.create');

        $query = Quotation::with('customer')
            ->where('company_id', $company->id);

        if (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function (Builder $q) use ($term) {
                $q->where('quotation_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', function (Builder $cQ) use ($term) {
                        $cQ->where('name_ar', 'like', "%{$term}%")
                            ->orWhere('name_en', 'like', "%{$term}%")
                            ->orWhere('code', 'like', "%{$term}%");
                    });
            });
        }

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->customerFilter !== null) {
            $query->where('customer_id', $this->customerFilter);
        }

        $quotations = $query->orderByDesc('quotation_date')->orderByDesc('id')->paginate(15);
        $customers = Customer::where('company_id', $company->id)->orderBy('name_ar')->get();

        return view('livewire.pages.sales.quotation-index', [
            'quotations' => $quotations,
            'customers' => $customers,
            'canCreate' => $canCreate,
            'company' => $company,
        ]);
    }
}
