<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Inventory;

use App\Domain\Inventory\DTO\StockTransferCommand;
use App\Domain\Inventory\DTO\StockTransferLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\InventoryLotBalance;
use App\Models\Product;
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
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.stock.transfer')) {
            abort(403);
        }

        $company = $context->company();
        $this->movement_date = now()->toDateString();
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
        if ($this->product_id) {
            $product = Product::find($this->product_id);
            if ($product) {
                $this->unit_id = $product->base_unit_id;
            }
        }
    }

    public function updatedSourceWarehouseId(): void
    {
        $this->lot_id = null;
    }

    public function save(CompanyContext $context, InventoryMovementService $movementService): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.stock.transfer')) {
            abort(403);
        }

        $company = $context->company();

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

    public function render(CompanyContext $context): View
    {
        $company = $context->company();

        $products = Product::where('company_id', $company->id)
            ->where('active', true)
            ->where('track_stock', true)
            ->with(['baseUnit', 'productUnits.unit'])
            ->orderBy('name_ar')
            ->get();

        $warehouses = Warehouse::where('company_id', $company->id)
            ->where('active', true)
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

            if ($selectedProduct->track_expiry && $this->source_warehouse_id) {
                $availableLots = InventoryLotBalance::where('company_id', $company->id)
                    ->where('product_id', $selectedProduct->id)
                    ->where('warehouse_id', $this->source_warehouse_id)
                    ->where('quantity_base', '>', 0)
                    ->with('lot')
                    ->get();
            }
        }

        return view('livewire.pages.inventory.stock-transfer-form', [
            'products' => $products,
            'warehouses' => $warehouses,
            'selectedProduct' => $selectedProduct,
            'availableUnits' => $availableUnits,
            'availableLots' => $availableLots,
            'company' => $company,
        ]);
    }
}
