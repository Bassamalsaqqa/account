<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Products;

use App\Domain\Inventory\Services\StockQuantityBreakdown;
use App\Domain\Purchasing\Queries\ProductPurchaseHistoryQuery;
use App\Livewire\Concerns\HasTablePageSize;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryLotBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ProductDetail extends Component
{
    use HasTablePageSize;
    use WithPagination;

    public Product $product;

    public string $activeTab = 'overview'; // overview, warehouses, lots, movements

    public function mount(CompanyContext $context, string $publicId): void
    {
        $company = $context->company();

        $this->product = Product::where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->with(['category', 'brand', 'baseUnit', 'images', 'barcodes', 'productUnits.unit'])
            ->firstOrFail();

        $this->authorize('view', $this->product);
    }

    public function setTab(string $tab): void
    {
        /** @var User $user */
        $user = auth()->user();
        $canViewPurchasingCost = $user->hasPermissionTo('purchasing.cost.view');

        if ($tab === 'purchase_history' && ! $canViewPurchasingCost) {
            $this->activeTab = 'overview';

            return;
        }

        if (in_array($tab, ['overview', 'warehouses', 'lots', 'movements', 'units', 'barcodes', 'images', 'purchase_history'], true)) {
            $this->resetPage('warehousePage');
            $this->resetPage('lotsPage');
            $this->resetPage('movementsPage');
            $this->resetPage('purchaseHistoryPage');
            $this->activeTab = $tab;
        }
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        abort_unless((int) $this->product->company_id === (int) $company->id, 403);
        $this->authorize('view', $this->product);
        $this->product->load(['baseUnit', 'productUnits.unit']);

        /** @var User $user */
        $user = auth()->user();
        $canViewCost = $user->hasPermissionTo('inventory.cost.view');
        $canManageProduct = $user->hasPermissionTo('inventory.product.manage');
        $canViewPurchasingCost = $user->hasPermissionTo('purchasing.cost.view');
        $canViewPurchases = $user->hasPermissionTo('purchasing.purchase.view');
        $canViewVendors = $user->hasPermissionTo('vendors.view');

        if ($this->activeTab === 'purchase_history' && ! $canViewPurchasingCost) {
            $this->activeTab = 'overview';
        }

        // Balances by warehouse
        $warehouseBalances = InventoryBalance::where('company_id', $company->id)
            ->where('product_id', $this->product->id)
            ->with('warehouse')
            ->get();

        $stockTotal = BigDecimal::zero();
        $warehouseBreakdowns = [];
        foreach ($warehouseBalances as $balance) {
            $stockTotal = $stockTotal->plus((string) $balance->quantity_base);
            $warehouseBreakdowns[$balance->id] = app(StockQuantityBreakdown::class)->forProduct($this->product, (string) $balance->quantity_base);
        }
        $stockBreakdown = app(StockQuantityBreakdown::class)->forProduct($this->product, (string) $stockTotal);

        // Lots if expiry tracked (deferred query when tab is active)
        $lots = collect();
        if ($this->product->track_expiry) {
            if ($this->activeTab === 'lots') {
                $lots = InventoryLotBalance::where('company_id', $company->id)
                    ->where('product_id', $this->product->id)
                    ->with(['lot', 'warehouse'])
                    ->where('quantity_base', '>', 0)->orderBy('id')->paginate($this->tablePageSize(), ['*'], 'lotsPage');
            } else {
                $lotsCount = InventoryLotBalance::where('company_id', $company->id)
                    ->where('product_id', $this->product->id)
                    ->where('quantity_base', '>', 0)
                    ->count();
                $lots = new LengthAwarePaginator([], $lotsCount, $this->tablePageSize(), 1);
            }
        }

        // Movements history (deferred query when tab is active)
        $movements = ($this->activeTab === 'movements')
            ? StockMovement::where('company_id', $company->id)
                ->where('product_id', $this->product->id)
                ->with(['warehouse', 'lot', 'user'])
                ->orderByDesc('id')
                ->paginate($this->tablePageSize(), ['*'], 'movementsPage')
            : new LengthAwarePaginator([], 0, $this->tablePageSize(), 1);

        // Cost state (strictly gated)
        $costState = null;
        if ($canViewCost) {
            $costState = InventoryCostState::where('company_id', $company->id)
                ->where('product_id', $this->product->id)
                ->first();
        }

        $purchaseHistory = collect();
        if ($canViewPurchasingCost && $this->activeTab === 'purchase_history') {
            $purchaseHistory = app(ProductPurchaseHistoryQuery::class)->paginate($this->product, $company->id, $this->tablePageSize());
        }

        return view('livewire.pages.products.product-detail', [
            'canReceiveStock' => $this->product->active && $this->product->track_stock && $user->hasPermissionTo('purchasing.purchase.create') && $canViewPurchasingCost,
            'product' => $this->product,
            'warehouseBalances' => $this->paginateTableRows($warehouseBalances, 'warehousePage'),
            'stockTotal' => (string) $stockTotal->toScale(6),
            'stockBreakdown' => $stockBreakdown,
            'warehouseBreakdowns' => $warehouseBreakdowns,
            'lots' => $lots,
            'movements' => $movements,
            'costState' => $costState,
            'canViewCost' => $canViewCost,
            'canManageProduct' => $canManageProduct,
            'canViewPurchasingCost' => $canViewPurchasingCost,
            'canViewPurchases' => $canViewPurchases,
            'canViewVendors' => $canViewVendors,
            'purchaseHistory' => $purchaseHistory,
            'company' => $company,
        ]);
    }
}
