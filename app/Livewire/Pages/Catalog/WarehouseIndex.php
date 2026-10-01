<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Catalog;

use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WarehouseIndex extends Component
{
    public ?int $editingId = null;

    public string $code = '';

    public string $name_ar = '';

    public string $name_en = '';

    public string $address_ar = '';

    public string $address_en = '';

    public bool $is_default = false;

    public bool $active = true;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public bool $showModal = false;

    public function openCreateModal(): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.product.manage')) {
            abort(403);
        }

        $this->reset(['editingId', 'code', 'name_ar', 'name_en', 'address_ar', 'address_en', 'is_default', 'active', 'errorMessage', 'successMessage']);
        $this->active = true;
        $this->showModal = true;
    }

    public function openEditModal(int $id, CompanyContext $context): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.product.manage')) {
            abort(403);
        }

        $company = $context->company();
        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::where('company_id', $company->id)->where('id', $id)->firstOrFail();

        $this->editingId = $warehouse->id;
        $this->code = (string) $warehouse->code;
        $this->name_ar = (string) $warehouse->name_ar;
        $this->name_en = (string) ($warehouse->name_en ?? '');
        $this->address_ar = (string) ($warehouse->address_ar ?? '');
        $this->address_en = (string) ($warehouse->address_en ?? '');
        $this->is_default = (bool) $warehouse->is_default;
        $this->active = (bool) $warehouse->active;
        $this->showModal = true;
    }

    public function save(CompanyContext $context, ProductCatalogService $catalogService): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.product.manage')) {
            abort(403);
        }

        $company = $context->company();

        $validated = $this->validate([
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('warehouses', 'code')
                    ->where('company_id', $company->id)
                    ->ignore($this->editingId),
            ],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'address_ar' => ['nullable', 'string', 'max:500'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'is_default' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ]);

        if (! $validated['active'] && $validated['is_default']) {
            $this->errorMessage = __('inventory.cannot_deactivate_default_warehouse');

            return;
        }

        $data = [
            'code' => mb_strtolower($validated['code']),
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'] ?: null,
            'address_ar' => $validated['address_ar'] ?: null,
            'address_en' => $validated['address_en'] ?: null,
            'is_default' => (bool) $validated['is_default'],
            'active' => (bool) $validated['active'],
        ];

        try {
            if ($this->editingId !== null) {
                $catalogService->updateWarehouse($company, $this->editingId, $data);
            } else {
                DB::transaction(function () use ($company, $data, $user) {
                    Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
                    if ($data['is_default']) {
                        Warehouse::where('company_id', $company->id)->update(['is_default' => false]);
                    }
                    $data['company_id'] = $company->id;
                    $data['created_by'] = $user->id;
                    $warehouse = Warehouse::create($data);
                    if ($data['is_default']) {
                        CompanyInventorySettings::where('company_id', $company->id)->update([
                            'default_warehouse_id' => $warehouse->id,
                        ]);
                    }
                });
            }

            $this->successMessage = __('inventory.warehouse_saved_success');
            $this->showModal = false;
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function deleteWarehouse(int $id, CompanyContext $context): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.product.manage')) {
            abort(403);
        }

        $company = $context->company();

        DB::transaction(function () use ($company, $id) {
            Company::where('id', $company->id)->lockForUpdate()->firstOrFail();

            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::where('company_id', $company->id)->where('id', $id)->lockForUpdate()->firstOrFail();

            if ($warehouse->is_default) {
                $this->errorMessage = __('inventory.cannot_delete_default_warehouse');

                return;
            }

            $hasMovements = StockMovement::where('company_id', $company->id)
                ->where('warehouse_id', $id)
                ->exists();

            if ($hasMovements) {
                $this->errorMessage = __('inventory.cannot_delete_warehouse_with_movements');

                return;
            }

            $warehouse->delete();
            $this->successMessage = __('inventory.warehouse_deleted_success');
        });
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();

        /** @var User $user */
        $user = auth()->user();
        $canManage = $user->hasPermissionTo('inventory.product.manage');

        $warehouses = Warehouse::where('company_id', $company->id)->orderBy('is_default', 'desc')->orderBy('name_ar')->get();

        return view('livewire.pages.catalog.warehouse-index', [
            'warehouses' => $warehouses,
            'canManage' => $canManage,
            'company' => $company,
        ]);
    }
}
