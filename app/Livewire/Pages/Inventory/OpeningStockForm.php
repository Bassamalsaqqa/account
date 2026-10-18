<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Inventory;

use App\Actions\Inventory\PostOpeningStockAction;
use App\Application\Inventory\InventoryFormAvailability;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Livewire\Pages\Inventory\Concerns\AuthorizesInventoryPages;
use App\Livewire\Pages\Inventory\Concerns\HandlesInventoryDraftControls;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class OpeningStockForm extends Component
{
    use AuthorizesInventoryPages;
    use HandlesInventoryDraftControls;

    public ?int $product_id = null;

    public ?int $warehouse_id = null;

    public string $quantity = '';

    public ?int $unit_id = null;

    public string $unit_cost_base = '';

    public string $lot_number = '';

    public string $expiry_date = '';

    public string $movement_date = '';

    public string $reason = '';

    public string $formIdempotencyKey = '';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function boot(): void
    {
        if (isset($this->pageCompanyId)) {
            $this->authorizeInventoryContext();
        }
    }

    protected function authorizeInventoryContext(): Company
    {
        $company = $this->authorizeInventory('inventory.stock.adjust');
        abort_unless(auth()->user()->hasPermissionTo('inventory.cost.view'), 403);

        return $company;
    }

    public function mount(CompanyContext $context): void
    {
        $this->pageCompanyId = (int) $context->companyId();
        $company = $this->authorizeInventoryContext();
        $this->movement_date = now($company->timezone)->toDateString();
        $this->formIdempotencyKey = (string) Str::ulid();
        $this->reason = __('inventory.default_opening_stock_reason');

        $defaultWarehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->first();
        if ($defaultWarehouse) {
            $this->warehouse_id = $defaultWarehouse->id;
        }
    }

    public function updatedProductId(): void
    {
        $this->lot_number = '';
        $this->expiry_date = '';

        $company = $this->authorizeInventoryContext();
        $this->unit_id = null;
        if ($this->product_id) {
            $product = Product::where('company_id', $company->id)->where('active', true)->where('track_stock', true)->find($this->product_id);
            if ($product) {
                $this->unit_id = $product->base_unit_id;
                /** @var User $user */
                $user = auth()->user();
                if ($user->hasPermissionTo('inventory.cost.view') && $product->default_purchase_cost_base !== null) {
                    $this->unit_cost_base = (string) $product->default_purchase_cost_base;
                }
            }
        }
    }

    public function save(CompanyContext $context, PostOpeningStockAction $action): void
    {
        $company = $this->authorizeInventoryContext();
        /** @var User $user */
        $user = auth()->user();

        $validated = $this->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $company->id)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $company->id)],
            'quantity' => ['required', 'regex:/\A\d+(\.\d+)?\z/', 'gt:0'],
            'unit_cost_base' => ['required', 'regex:/\A\d+(\.\d+)?\z/', 'min:0'],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('company_id', $company->id)],
            'lot_number' => ['nullable', 'string', 'max:128'],
            'expiry_date' => ['nullable', 'date'],
            'movement_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Product $product */
        $product = Product::where('company_id', $company->id)->where('id', $validated['product_id'])->firstOrFail();
        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::where('company_id', $company->id)->where('id', $validated['warehouse_id'])->firstOrFail();

        if ($product->track_expiry && empty($validated['expiry_date'])) {
            $this->errorMessage = __('inventory.expiry_date_required_for_expiry_tracked');

            return;
        }

        if (empty($this->formIdempotencyKey)) {
            $this->formIdempotencyKey = (string) Str::ulid();
        }

        $idempotencyKey = 'open-'.$this->formIdempotencyKey;

        try {
            $action->execute(
                company: $company,
                product: $product,
                warehouse: $warehouse,
                quantity: Quantity::of($validated['quantity']),
                unitCostBase: $validated['unit_cost_base'],
                user: $user,
                idempotencyKey: $idempotencyKey,
                unitId: $validated['unit_id'] ?: null,
                lotNumber: ! empty($validated['lot_number']) ? $validated['lot_number'] : null,
                expiryDate: ! empty($validated['expiry_date']) ? $validated['expiry_date'] : null,
                movementDate: $validated['movement_date'],
                reason: $validated['reason'] ?: __('inventory.default_opening_stock_reason'),
            );

            $this->reset(['quantity', 'unit_cost_base', 'lot_number', 'expiry_date']);
            $this->formIdempotencyKey = (string) Str::ulid();
            $this->successMessage = __('inventory.opening_stock_success');
            $this->errorMessage = null;
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render(CompanyContext $context): View
    {
        $company = $this->authorizeInventoryContext();

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
        }

        return view('livewire.pages.inventory.opening-stock-form', [
            'products' => $products,
            'warehouses' => $warehouses,
            'selectedProduct' => $selectedProduct,
            'stockAvailability' => app(InventoryFormAvailability::class)->forProduct((int) $company->id, $selectedProduct, $this->warehouse_id),
            'availableUnits' => $availableUnits,
            'canViewCost' => $canViewCost,
            'company' => $company,
        ]);
    }
}
