<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Customers;

use App\Domain\Sales\Queries\CustomerBalanceQuery;
use App\Models\Customer;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class CustomerIndex extends Component
{
    use WithPagination;

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
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('customers.view')) {
            abort(403, 'Unauthorized.');
        }

        $canManage = $user->hasPermissionTo('customers.manage');

        $query = Customer::where('company_id', $company->id);

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

        $customers = $query->orderBy('name_ar')->paginate(15);

        $currencyBalances = app(CustomerBalanceQuery::class)->execute($customers->pluck('id')->all());
        $balances = [];
        foreach ($customers as $customer) {
            $currency = $customer->default_currency_code ?? $company->base_currency_code;
            $balances[$customer->id] = $currencyBalances[$customer->id][$currency]['outstanding'] ?? '0.000000';
        }

        return view('livewire.pages.customers.customer-index', [
            'customers' => $customers,
            'balances' => $balances,
            'currencyBalances' => $currencyBalances,
            'canManage' => $canManage,
            'company' => $company,
        ]);
    }
}
