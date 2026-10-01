<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Products;

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
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ProductDetail extends Component
{
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
        if (in_array($tab, ['overview', 'warehouses', 'lots', 'movements', 'units', 'barcodes', 'images'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        $this->authorize('view', $this->product);

        /** @var User $user */
        $user = auth()->user();
        $canViewCost = $user->hasPermissionTo('inventory.cost.view');
        $canManageProduct = $user->hasPermissionTo('inventory.product.manage');

        // Balances by warehouse
        $warehouseBalances = InventoryBalance::where('company_id', $company->id)
            ->where('product_id', $this->product->id)
            ->with('warehouse')
            ->get();

        // Lots if expiry tracked
        $lots = collect();
        if ($this->product->track_expiry) {
            $lots = InventoryLotBalance::where('company_id', $company->id)
                ->where('product_id', $this->product->id)
                ->with(['lot', 'warehouse'])
                ->get()
                ->filter(fn ($lb) => BigDecimal::of((string) $lb->quantity_base)->isPositive());
        }

        // Movements history
        $movements = StockMovement::where('company_id', $company->id)
            ->where('product_id', $this->product->id)
            ->with(['warehouse', 'lot', 'user'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // Cost state (strictly gated)
        $costState = null;
        if ($canViewCost) {
            $costState = InventoryCostState::where('company_id', $company->id)
                ->where('product_id', $this->product->id)
                ->first();
        }

        return view('livewire.pages.products.product-detail', [
            'product' => $this->product,
            'warehouseBalances' => $warehouseBalances,
            'lots' => $lots,
            'movements' => $movements,
            'costState' => $costState,
            'canViewCost' => $canViewCost,
            'canManageProduct' => $canManageProduct,
            'company' => $company,
        ]);
    }
}
