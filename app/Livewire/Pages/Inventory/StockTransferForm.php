<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Inventory;

use App\Application\Inventory\AvailableLotQuery;
use App\Application\Inventory\InventoryFormAvailability;
use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Livewire\Concerns\HandlesSearchableSelectors;
use App\Livewire\Pages\Inventory\Concerns\AuthorizesInventoryPages;
use App\Livewire\Pages\Inventory\Concerns\HandlesInventoryDraftControls;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockTransferForm extends Component
{
    use AuthorizesInventoryPages;
    use HandlesInventoryDraftControls;
    use HandlesSearchableSelectors;

    protected function authorizeInventoryContext(): Company
    {
        return $this->authorizeInventory('inventory.stock.transfer');
    }

    protected function authorizeInventoryContextReadOnly(): Company
    {
        return $this->authorizeInventoryReadOnly('inventory.stock.transfer');
    }

    public ?int $source_warehouse_id = null;

    public ?int $destination_warehouse_id = null;

    public ?int $product_id = null;

    public string $quantity = '';

    public ?int $unit_id = null;

    public ?int $lot_id = null;

    public string $movement_date = '';

    public string $reason = '';

    public string $formIdempotencyKey = '';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(CompanyContext $context): void
    {
        $this->pageCompanyId = $context->companyId();
        $company = $this->authorizeInventoryContextReadOnly();
        $this->movement_date = now($company->timezone)->toDateString();
        $this->formIdempotencyKey = (string) Str::ulid();

        $warehouses = Warehouse::where('company_id', $company->id)->where('active', true)->get();
        if ($warehouses->count() >= 2) {
            $default = $warehouses->firstWhere('is_default', true) ?? $warehouses->first();
            $this->source_warehouse_id = $default->id;
            $other = $warehouses->firstWhere('id', '!==', $default->id);
            $this->destination_warehouse_id = $other?->id;
        }
    }

    public function updatedProductId(): void
    {
        $this->lot_id = null;
        $company = $this->authorizeInventory('inventory.stock.transfer');
        if ($this->product_id) {
            $product = Product::where('company_id', $company->id)->where('active', true)->where('track_stock', true)->find($this->product_id);
            if ($product) {
                $unitValid = $this->unit_id && ($this->unit_id === $product->base_unit_id || ProductUnit::where('company_id', $company->id)->where('product_id', $product->id)->where('unit_id', $this->unit_id)->where('active', true)->exists());
                if (! $unitValid) {
                    $this->unit_id = $product->base_unit_id;
                }
            }
        } else {
            $this->unit_id = null;
        }
    }

    public function updatedSourceWarehouseId(): void
    {
        $this->lot_id = null;
    }

    public function save(CompanyContext $context, InventoryMovementService $movementService): void
    {
        $company = $this->authorizeInventory('inventory.stock.transfer');
        /** @var User $user */
        $user = auth()->user();

        $validated = $this->validate([
            'source_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $company->id)],
            'destination_warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $company->id),
                Rule::notIn([$this->source_warehouse_id]),
            ],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $company->id)],
            'quantity' => ['required', 'regex:/\A\d+(\.\d+)?\z/', 'gt:0'],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('company_id', $company->id)],
            'lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', $company->id)],
            'movement_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Product $product */
        $product = Product::where('company_id', $company->id)->where('id', $validated['product_id'])->firstOrFail();

        if ($product->track_expiry && empty($validated['lot_id'])) {
            $this->errorMessage = __('inventory.lot_required_for_expiry_transfer');

            return;
        }

        if (empty($this->formIdempotencyKey)) {
            $this->formIdempotencyKey = (string) Str::ulid();
        }

        $idempotencyKey = 'xfr-'.$this->formIdempotencyKey;

        try {
            $command = new StockTransferCommand(
                companyId: $company->id,
                sourceWarehouseId: (int) $validated['source_warehouse_id'],
                destinationWarehouseId: (int) $validated['destination_warehouse_id'],
                movementDate: $validated['movement_date'],
                lines: [
                    new StockTransferLineCommand(
                        productId: $product->id,
                        quantity: Quantity::of($validated['quantity']),
                        unitId: $validated['unit_id'] ?: null,
                        lotId: ! empty($validated['lot_id']) ? (int) $validated['lot_id'] : null,
                    ),
                ],
                idempotencyKey: $idempotencyKey,
                createdBy: $user->id,
                reason: $validated['reason'] ?: __('inventory.default_transfer_reason'),
            );

            $movementService->transfer($command);

            $this->reset(['quantity', 'lot_id', 'reason']);
            $this->formIdempotencyKey = (string) Str::ulid();
            $this->successMessage = __('inventory.transfer_success');
            $this->errorMessage = null;
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function boot(): void
    {
        if (isset($this->pageCompanyId)) {
            $this->authorizeInventoryReadOnly('inventory.stock.transfer');
        }
    }

    public function render(CompanyContext $context): View
    {
        $company = $this->authorizeInventoryReadOnly('inventory.stock.transfer');

        $productsQuery = Product::where('company_id', $company->id)
            ->where('active', true)
            ->where('track_stock', true);

        $products = (clone $productsQuery)->orderBy('name_ar')->orderBy('id')->limit(25)->get();
        if ($this->product_id && ! $products->contains('id', $this->product_id)) {
            $selected = Product::where('company_id', $company->id)->where('id', $this->product_id)->first();
            if ($selected) {
                $products->prepend($selected);
            }
        }

        $warehouses = Warehouse::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('name_ar')
            ->get();

        $selectedProduct = $this->product_id
            ? Product::with(['baseUnit', 'productUnits.unit'])->where('company_id', $company->id)->find($this->product_id)
            : null;

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

            if ($selectedProduct->track_expiry && $this->source_warehouse_id) {
                $availableLots = app(AvailableLotQuery::class)->get($company->id, $selectedProduct->id, $this->source_warehouse_id);
            }
        }

        return view('livewire.pages.inventory.stock-transfer-form', [
            'products' => $products,
            'warehouses' => $warehouses,
            'selectedProduct' => $selectedProduct,
            'stockAvailability' => app(InventoryFormAvailability::class)->forProduct((int) $company->id, $selectedProduct, $this->source_warehouse_id),
            'availableUnits' => $availableUnits,
            'availableLots' => $availableLots,
            'company' => $company,
            'lotReferenceDate' => now($company->timezone)->startOfDay(),
        ]);
    }
}
