<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Inventory;

use App\Models\InventoryCostState;
use App\Models\InventoryLot;
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
class InventoryOverview extends Component
{
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

        $productsCount = Product::where('company_id', $company->id)->where('active', true)->count();
        $warehousesCount = Warehouse::where('company_id', $company->id)->where('active', true)->count();

        // Expiring soon lots (next 30 days)
        $expiringLotsCount = InventoryLot::where('company_id', $company->id)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays(30)->toDateString())
            ->whereHas('balances', function ($q) {
                $q->where('quantity_base', '>', 0);
            })
            ->count();

        // Total inventory valuation (gated)
        $totalValuation = '0.00';
        if ($canViewCost) {
            $costStates = InventoryCostState::where('company_id', $company->id)->get();
            $sum = BigDecimal::zero();
            foreach ($costStates as $cs) {
                $sum = $sum->plus(BigDecimal::of((string) $cs->inventory_value_base));
            }
            $totalValuation = (string) $sum->toScale(2);
        }

        // Low stock products
        $allTrackedProducts = Product::where('company_id', $company->id)
            ->where('active', true)
            ->where('track_stock', true)
            ->whereNotNull('minimum_stock')
            ->with(['baseUnit'])
            ->get();

        $lowStockProducts = $allTrackedProducts->filter(function ($p) {
            if ($p->minimum_stock_base === null) {
                return false;
            }
            $stock = BigDecimal::of((string) $p->stock_quantity);
            $min = BigDecimal::of((string) $p->minimum_stock_base);

            return $stock->isLessThanOrEqualTo($min);
        })->take(10);

        // Recent stock movements
        $recentMovements = StockMovement::where('company_id', $company->id)
            ->with(['product', 'warehouse', 'user'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('livewire.pages.inventory.inventory-overview', [
            'productsCount' => $productsCount,
            'warehousesCount' => $warehousesCount,
            'expiringLotsCount' => $expiringLotsCount,
            'totalValuation' => $totalValuation,
            'lowStockProducts' => $lowStockProducts,
            'recentMovements' => $recentMovements,
            'canViewCost' => $canViewCost,
            'canAdjust' => $canAdjust,
            'canTransfer' => $canTransfer,
            'company' => $company,
        ]);
    }
}
