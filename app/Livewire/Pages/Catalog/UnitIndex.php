<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Catalog;

use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class UnitIndex extends Component
{
    public ?int $editingId = null;

    public string $code = '';

    public string $name_ar = '';

    public string $name_en = '';

    public string $symbol_ar = '';

    public string $symbol_en = '';

    public bool $allow_fractions = false;

    public int $decimal_places = 0;

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

        $this->reset(['editingId', 'code', 'name_ar', 'name_en', 'symbol_ar', 'symbol_en', 'allow_fractions', 'decimal_places', 'active', 'errorMessage', 'successMessage']);
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
        /** @var Unit $unit */
        $unit = Unit::where('company_id', $company->id)->where('id', $id)->firstOrFail();

        $this->editingId = $unit->id;
        $this->code = (string) $unit->code;
        $this->name_ar = (string) $unit->name_ar;
        $this->name_en = (string) ($unit->name_en ?? '');
        $this->symbol_ar = (string) ($unit->symbol_ar ?? '');
        $this->symbol_en = (string) ($unit->symbol_en ?? '');
        $this->allow_fractions = (bool) $unit->allows_fraction;
        $this->decimal_places = (int) $unit->decimal_places;
        $this->active = (bool) $unit->active;
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
                Rule::unique('units', 'code')
                    ->where('company_id', $company->id)
                    ->ignore($this->editingId),
            ],
            'name_ar' => ['required', 'string', 'max:128'],
            'name_en' => ['nullable', 'string', 'max:128'],
            'symbol_ar' => ['nullable', 'string', 'max:16'],
            'symbol_en' => ['nullable', 'string', 'max:16'],
            'allow_fractions' => ['required', 'boolean'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:6'],
            'active' => ['required', 'boolean'],
        ]);

        $data = [
            'code' => mb_strtolower($validated['code']),
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'] ?: null,
            'symbol_ar' => $validated['symbol_ar'] ?: null,
            'symbol_en' => $validated['symbol_en'] ?: null,
            'allows_fraction' => (bool) $validated['allow_fractions'],
            'decimal_places' => $validated['allow_fractions'] ? (int) $validated['decimal_places'] : 0,
            'active' => (bool) $validated['active'],
        ];

        try {
            if ($this->editingId !== null) {
                $catalogService->updateUnit($company, $this->editingId, $data);
            } else {
                $data['company_id'] = $company->id;
                Unit::create($data);
            }

            $this->successMessage = __('inventory.unit_saved_success');
            $this->showModal = false;
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();

        /** @var User $user */
        $user = auth()->user();
        $canManage = $user->hasPermissionTo('inventory.product.manage');

        $units = Unit::where('company_id', $company->id)->orderBy('name_ar')->get();

        return view('livewire.pages.catalog.unit-index', [
            'units' => $units,
            'canManage' => $canManage,
            'company' => $company,
        ]);
    }
}
