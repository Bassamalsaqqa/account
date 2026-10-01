<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Inventory;

use App\Actions\Inventory\AdjustStockAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\InventoryLotBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockAdjustmentForm extends Component
{
    public ?int $product_id = null;

    public ?int $warehouse_id = null;

    public string $type = StockMovement::TYPE_ADJUSTMENT_INCREASE;

    public string $quantity = '';

    public ?int $unit_id = null;

    public string $unit_cost_base = '';

    public ?int $lot_id = null;

    public string $lot_number = '';

    public string $expiry_date = '';

    public string $reason = '';

    public string $movement_date = '';

    public string $formIdempotencyKey = '';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(CompanyContext $context): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.stock.adjust')) {
            abort(403);
        }

        $company = $context->company();
        $this->movement_date = now()->toDateString();
        $this->formIdempotencyKey = (string) Str::ulid();

        $defaultWarehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->first();
        if ($defaultWarehouse) {
            $this->warehouse_id = $defaultWarehouse->id;
        }
    }

    public function updatedProductId(): void
    {
        $this->lot_id = null;
        $this->lot_number = '';
        $this->expiry_date = '';

        if ($this->product_id) {
            $product = Product::find($this->product_id);
            if ($product) {
                $this->unit_id = $product->base_unit_id;
            }
        }
    }

    public function save(CompanyContext $context, AdjustStockAction $action): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.stock.adjust')) {
            abort(403);
        }

        $company = $context->company();
        $canViewCost = $user->hasPermissionTo('inventory.cost.view');

        $validated = $this->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $company->id)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $company->id)],
            'type' => ['required', 'string', Rule::in([
                StockMovement::TYPE_ADJUSTMENT_INCREASE,
                StockMovement::TYPE_ADJUSTMENT_DECREASE,
                StockMovement::TYPE_DAMAGE_OR_LOSS,
            ])],
            'quantity' => ['required', 'regex:/\A\d+(\.\d+)?\z/', 'gt:0'],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('company_id', $company->id)],
            'unit_cost_base' => ['nullable', 'regex:/\A\d+(\.\d+)?\z/', 'min:0'],
            'lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', $company->id)],
            'lot_number' => ['nullable', 'string', 'max:128'],
            'expiry_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'movement_date' => ['required', 'date'],
        ]);

        /** @var Product $product */
        $product = Product::where('company_id', $company->id)->where('id', $validated['product_id'])->firstOrFail();
        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::where('company_id', $company->id)->where('id', $validated['warehouse_id'])->firstOrFail();

        // Lot requirements
        if ($product->track_expiry) {
            if ($validated['type'] !== StockMovement::TYPE_ADJUSTMENT_INCREASE && empty($validated['lot_id'])) {
                $this->errorMessage = __('inventory.lot_required_for_expiry_decrease');

                return;
            }
        }

        $rawCost = trim($this->unit_cost_base);
        $isCostProvided = $rawCost !== '';

        if (! $canViewCost && $isCostProvided) {
            abort(403);
        }

        $cost = null;
        if ($canViewCost && $isCostProvided) {
            $cost = $rawCost;
        }

        if (empty($this->formIdempotencyKey)) {
            $this->formIdempotencyKey = (string) Str::ulid();
        }

        $idempotencyKey = 'adj-'.$this->formIdempotencyKey;

        try {
            $action->execute(
                company: $company,
                product: $product,
                warehouse: $warehouse,
                type: $validated['type'],
                quantity: Quantity::of($validated['quantity']),
                reason: $validated['reason'],
                user: $user,
                idempotencyKey: $idempotencyKey,
                unitCostBase: $cost,
                unitId: $validated['unit_id'] ?: null,
                lotId: ! empty($validated['lot_id']) ? (int) $validated['lot_id'] : null,
                lotNumber: ! empty($validated['lot_number']) ? $validated['lot_number'] : null,
                expiryDate: ! empty($validated['expiry_date']) ? $validated['expiry_date'] : null,
                movementDate: $validated['movement_date'],
            );

            $this->reset(['quantity', 'unit_cost_base', 'lot_id', 'lot_number', 'expiry_date', 'reason']);
            $this->formIdempotencyKey = (string) Str::ulid();
            $this->successMessage = __('inventory.adjustment_success');
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

        $products = Product::where('company_id', $company->id)
            ->where('active', true)
            ->where('track_stock', true)
            ->with(['baseUnit', 'productUnits.unit'])
            ->orderBy('name_ar')
            ->get();

        $warehouses = Warehouse::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('is_default', 'desc')
            ->orderBy('name_ar')
            ->get();

        $selectedProduct = $this->product_id ? $products->firstWhere('id', $this->product_id) : null;

        $availableUnits = collect();
        $availableLots = collect();

        if ($selectedProduct) {
            $unitsList = [];
            if ($selectedProduct->baseUnit !== null) {
                $unitsList[] = $selectedProduct->baseUnit;
            }
            foreach ($selectedProduct->productUnits as $pu) {
                if ($pu->unit !== null) {
                    $unitsList[] = $pu->unit;
                }
            }
            $availableUnits = collect($unitsList);

            if ($selectedProduct->track_expiry && $this->warehouse_id) {
                $availableLots = InventoryLotBalance::where('company_id', $company->id)
                    ->where('product_id', $selectedProduct->id)
                    ->where('warehouse_id', $this->warehouse_id)
                    ->where('quantity_base', '>', 0)
                    ->with('lot')
                    ->get();
            }
        }

        return view('livewire.pages.inventory.stock-adjustment-form', [
            'products' => $products,
            'warehouses' => $warehouses,
            'selectedProduct' => $selectedProduct,
            'availableUnits' => $availableUnits,
            'availableLots' => $availableLots,
            'canViewCost' => $canViewCost,
            'company' => $company,
        ]);
    }
}
