<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Inventory;

use App\Actions\Inventory\DisposeExpiredStockAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\InventoryCostState;
use App\Models\InventoryLotBalance;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class ExpiryCenter extends Component
{
    #[Url(as: 'filter')]
    public string $timeframeFilter = 'all'; // all, expired, 7, 30, 60, 90, custom

    #[Url(as: 'from')]
    public string $customFromDate = '';

    #[Url(as: 'to')]
    public string $customToDate = '';

    #[Url(as: 'product')]
    public string $productFilter = '';

    #[Url(as: 'warehouse')]
    public string $warehouseFilter = '';

    // Quick Disposal Modal State
    public bool $showDisposalModal = false;

    public ?int $disposingLotBalanceId = null;

    public ?InventoryLotBalance $disposingLotBalance = null;

    public string $disposalQuantity = '';

    public string $disposalReason = '';

    public string $disposalDate = '';

    public string $formIdempotencyKey = '';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage'])) {
            abort(403);
        }

        $this->disposalDate = now()->toDateString();
        $this->formIdempotencyKey = (string) Str::ulid();
    }

    public function openDisposalModal(int $lotBalanceId, CompanyContext $context): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.stock.adjust')) {
            abort(403);
        }

        $company = $context->company();
        /** @var InventoryLotBalance $lb */
        $lb = InventoryLotBalance::where('company_id', $company->id)
            ->where('id', $lotBalanceId)
            ->with(['lot', 'product', 'warehouse'])
            ->firstOrFail();

        $this->disposingLotBalanceId = $lb->id;
        $this->disposingLotBalance = $lb;
        $this->disposalQuantity = (string) $lb->quantity_base;
        $this->disposalReason = __('inventory.default_disposal_reason');
        $this->disposalDate = now()->toDateString();
        $this->formIdempotencyKey = (string) Str::ulid();
        $this->showDisposalModal = true;
    }

    public function confirmDisposal(CompanyContext $context, DisposeExpiredStockAction $action): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.stock.adjust')) {
            abort(403);
        }

        $company = $context->company();

        $validated = $this->validate([
            'disposalQuantity' => ['required', 'regex:/\A\d+(\.\d+)?\z/', 'gt:0'],
            'disposalReason' => ['required', 'string', 'max:500'],
            'disposalDate' => ['required', 'date'],
        ]);

        if (! $this->disposingLotBalance) {
            return;
        }

        if (empty($this->formIdempotencyKey)) {
            $this->formIdempotencyKey = (string) Str::ulid();
        }

        $idempotencyKey = 'disp-'.$this->formIdempotencyKey;

        try {
            $action->execute(
                company: $company,
                product: $this->disposingLotBalance->product,
                warehouse: $this->disposingLotBalance->warehouse,
                lotId: $this->disposingLotBalance->lot_id,
                quantity: Quantity::of($validated['disposalQuantity']),
                reason: $validated['disposalReason'],
                user: $user,
                idempotencyKey: $idempotencyKey,
                movementDate: $validated['disposalDate'],
            );

            $this->showDisposalModal = false;
            $this->reset(['disposingLotBalanceId', 'disposingLotBalance', 'disposalQuantity', 'disposalReason']);
            $this->formIdempotencyKey = (string) Str::ulid();
            $this->successMessage = __('inventory.disposal_success');
            $this->errorMessage = null;
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();

        /** @var User $user */
        $user = auth()->user();
        $canViewCost = $user->hasPermissionTo('inventory.cost.view');
        $canAdjust = $user->hasPermissionTo('inventory.stock.adjust');

        $query = InventoryLotBalance::where('inventory_lot_balances.company_id', $company->id)
            ->where('inventory_lot_balances.quantity_base', '>', 0)
            ->join('inventory_lots', 'inventory_lot_balances.lot_id', '=', 'inventory_lots.id')
            ->select('inventory_lot_balances.*')
            ->with(['lot', 'product.baseUnit', 'warehouse']);

        if ($this->productFilter !== '') {
            $query->where('inventory_lot_balances.product_id', $this->productFilter);
        }

        if ($this->warehouseFilter !== '') {
            $query->where('inventory_lot_balances.warehouse_id', $this->warehouseFilter);
        }

        $today = now()->toDateString();

        if ($this->timeframeFilter === 'expired') {
            $query->whereNotNull('inventory_lots.expiry_date')
                ->where('inventory_lots.expiry_date', '<', $today);
        } elseif ($this->timeframeFilter === '7') {
            $query->whereNotNull('inventory_lots.expiry_date')
                ->where('inventory_lots.expiry_date', '>=', $today)
                ->where('inventory_lots.expiry_date', '<=', now()->addDays(7)->toDateString());
        } elseif ($this->timeframeFilter === '30') {
            $query->whereNotNull('inventory_lots.expiry_date')
                ->where('inventory_lots.expiry_date', '>=', $today)
                ->where('inventory_lots.expiry_date', '<=', now()->addDays(30)->toDateString());
        } elseif ($this->timeframeFilter === '60') {
            $query->whereNotNull('inventory_lots.expiry_date')
                ->where('inventory_lots.expiry_date', '>=', $today)
                ->where('inventory_lots.expiry_date', '<=', now()->addDays(60)->toDateString());
        } elseif ($this->timeframeFilter === '90') {
            $query->whereNotNull('inventory_lots.expiry_date')
                ->where('inventory_lots.expiry_date', '>=', $today)
                ->where('inventory_lots.expiry_date', '<=', now()->addDays(90)->toDateString());
        } elseif ($this->timeframeFilter === 'custom') {
            if ($this->customFromDate !== '') {
                $query->where('inventory_lots.expiry_date', '>=', $this->customFromDate);
            }
            if ($this->customToDate !== '') {
                $query->where('inventory_lots.expiry_date', '<=', $this->customToDate);
            }
        }

        $lotBalances = $query->orderBy('inventory_lots.expiry_date', 'asc')->get();

        // Exact decimal valuation computation in PHP service layer (strictly gated, no Blade float math)
        $lotValuations = [];
        if ($canViewCost) {
            $costStates = InventoryCostState::where('company_id', $company->id)->get()->keyBy('product_id');
            foreach ($lotBalances as $lb) {
                $costState = $costStates->get($lb->product_id);
                if ($costState !== null) {
                    $qty = BigDecimal::of((string) $lb->quantity_base);
                    $avgCost = BigDecimal::of((string) $costState->average_cost_base);
                    $val = $qty->multipliedBy($avgCost)->toScale(2, RoundingMode::HALF_UP);
                    $lotValuations[$lb->id] = (string) $val;
                } else {
                    $lotValuations[$lb->id] = '0.00';
                }
            }
        }

        $products = Product::where('company_id', $company->id)->where('track_expiry', true)->orderBy('name_ar')->get();
        $warehouses = Warehouse::where('company_id', $company->id)->orderBy('name_ar')->get();

        return view('livewire.pages.inventory.expiry-center', [
            'lotBalances' => $lotBalances,
            'products' => $products,
            'warehouses' => $warehouses,
            'lotValuations' => $lotValuations,
            'canViewCost' => $canViewCost,
            'canAdjust' => $canAdjust,
            'company' => $company,
        ]);
    }
}
