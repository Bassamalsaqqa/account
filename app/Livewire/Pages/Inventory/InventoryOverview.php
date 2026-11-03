<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Inventory;

use App\Domain\Inventory\Services\StockQuantityBreakdown;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Livewire\Concerns\HasTablePageSize;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class InventoryOverview extends Component
{
    use HasTablePageSize;
    use WithPagination;

    public string $search = '';

    public ?int $selectedWarehouseId = null;

    public string $stockFilter = 'all';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedWarehouseId(): void
    {
        $this->resetPage();
    }

    public function updatedStockFilter(): void
    {
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
        $canAdjust = $user->hasPermissionTo('inventory.stock.adjust');
        $canTransfer = $user->hasPermissionTo('inventory.stock.transfer');

        $warehouses = Warehouse::where('company_id', $company->id)->where('active', true)->get();

        // Total inventory valuation (gated)
        $totalValuation = SalesMoneyFormatter::displayMoney('0', $company->base_currency_code);
        if ($canViewCost) {
            $costStates = InventoryCostState::where('company_id', $company->id)->get();
            $sum = BigDecimal::zero();
            foreach ($costStates as $cs) {
                $sum = $sum->plus(BigDecimal::of((string) $cs->inventory_value_base));
            }
            $totalValuation = SalesMoneyFormatter::displayMoney($sum, $company->base_currency_code);
        }

        // Searchable and filterable stock/location query
        $stockQuery = InventoryBalance::where('company_id', $company->id)
            ->with(['product.baseUnit', 'product.productUnits.unit', 'warehouse'])
            ->whereHas('product', function ($q) {
                $q->where('active', true)->where('track_stock', true);
                if (trim($this->search) !== '') {
                    $term = '%'.trim($this->search).'%';
                    $q->where(function ($sub) use ($term) {
                        $sub->where('name_ar', 'like', $term)
                            ->orWhere('name_en', 'like', $term)
                            ->orWhere('sku', 'like', $term)
                            ->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('barcode', 'like', $term));
                    });
                }
            });

        if ($this->selectedWarehouseId !== null && $this->selectedWarehouseId > 0) {
            $stockQuery->where('warehouse_id', $this->selectedWarehouseId);
        }

        if ($this->stockFilter === 'in_stock') {
            $stockQuery->where('quantity_base', '>', 0);
        } elseif ($this->stockFilter === 'out_of_stock') {
            $stockQuery->where('quantity_base', '<=', 0);
        } elseif ($this->stockFilter === 'low_stock') {
            $stockQuery->whereHas('product', fn ($products) => $products
                ->whereNotNull('minimum_stock_base')
                ->whereColumn('inventory_balances.quantity_base', '<=', 'products.minimum_stock_base'));
        }

        $stockBalances = $stockQuery->orderByDesc('updated_at')->orderByDesc('id')->paginate($this->tablePageSize());
        $stockBreakdowns = [];
        foreach ($stockBalances as $balance) {
            $quantity = Quantity::of($balance->getRawOriginal('quantity_base'));
            $stockBreakdowns[$balance->id] = app(StockQuantityBreakdown::class)->forProduct($balance->product, $quantity->__toString());
        }

        return view('livewire.pages.inventory.inventory-overview', [
            'warehouses' => $warehouses,
            'stockBalances' => $stockBalances,
            'stockBreakdowns' => $stockBreakdowns,
            'totalValuation' => $totalValuation,
            'canViewCost' => $canViewCost,
            'canAdjust' => $canAdjust,
            'canTransfer' => $canTransfer,
            'company' => $company,
        ]);
    }
}
