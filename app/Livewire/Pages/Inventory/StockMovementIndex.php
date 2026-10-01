<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Inventory;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class StockMovementIndex extends Component
{
    use WithPagination;

    #[Url(as: 'product')]
    public string $productFilter = '';

    #[Url(as: 'warehouse')]
    public string $warehouseFilter = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    #[Url(as: 'from')]
    public string $fromDate = '';

    #[Url(as: 'to')]
    public string $toDate = '';

    public function updatedProductFilter(): void
    {
        $this->resetPage();
    }

    public function updatedWarehouseFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFromDate(): void
    {
        $this->resetPage();
    }

    public function updatedToDate(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['productFilter', 'warehouseFilter', 'typeFilter', 'fromDate', 'toDate']);
        $this->resetPage();
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();

        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage'])) {
            abort(403);
        }

        $canViewCost = $user->hasPermissionTo('inventory.cost.view');

        $query = StockMovement::where('company_id', $company->id)
            ->with(['product.baseUnit', 'warehouse', 'lot', 'user']);

        if ($this->productFilter !== '') {
            $query->where('product_id', $this->productFilter);
        }

        if ($this->warehouseFilter !== '') {
            $query->where('warehouse_id', $this->warehouseFilter);
        }

        if ($this->typeFilter !== '') {
            $query->where('movement_type', $this->typeFilter);
        }

        if ($this->fromDate !== '') {
            $query->where('movement_date', '>=', $this->fromDate);
        }

        if ($this->toDate !== '') {
            $query->where('movement_date', '<=', $this->toDate);
        }

        $movements = $query->orderByDesc('movement_date')->orderByDesc('id')->paginate(20);

        $products = Product::where('company_id', $company->id)->orderBy('name_ar')->get(['id', 'name_ar', 'sku']);
        $warehouses = Warehouse::where('company_id', $company->id)->orderBy('name_ar')->get(['id', 'name_ar']);

        $movementTypes = [
            StockMovement::TYPE_OPENING_BALANCE,
            StockMovement::TYPE_ADJUSTMENT_INCREASE,
            StockMovement::TYPE_ADJUSTMENT_DECREASE,
            StockMovement::TYPE_DAMAGE_OR_LOSS,
            StockMovement::TYPE_EXPIRY_DISPOSAL,
            StockMovement::TYPE_TRANSFER_OUT,
            StockMovement::TYPE_TRANSFER_IN,
        ];

        return view('livewire.pages.inventory.stock-movement-index', [
            'movements' => $movements,
            'products' => $products,
            'warehouses' => $warehouses,
            'movementTypes' => $movementTypes,
            'canViewCost' => $canViewCost,
            'company' => $company,
        ]);
    }
}
