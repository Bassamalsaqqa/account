<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Catalog;

use App\Application\Platform\TenantWriteAccess;
use App\Models\Brand;
use App\Models\Company;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class BrandIndex extends Component
{
    use WithPagination;

    #[Locked]
    public int $lockedCompanyId;

    #[Url(as: 'q')]
    public string $search = '';

    #[Locked]
    public ?int $editingId = null;

    public string $name_ar = '';

    public string $name_en = '';

    public bool $active = true;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public bool $showModal = false;

    public function mount(CompanyContext $context): void
    {
        if (! $context->hasCompany() || ! auth()->check()) {
            abort(403);
        }

        $this->lockedCompanyId = (int) $context->companyId();
        $this->authorizeManager();
    }

    public function hydrate(): void
    {
        $this->authorizeManager();
    }

    public function updatedSearch(): void
    {
        $this->authorizeManager();
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->authorizeManager();

        $this->reset(['editingId', 'name_ar', 'name_en', 'errorMessage', 'successMessage']);
        $this->resetErrorBag();
        $this->active = true;
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $this->authorizeManager();

        /** @var Brand $brand */
        $brand = Brand::where('company_id', $this->lockedCompanyId)
            ->where('id', $id)
            ->firstOrFail();

        $this->resetErrorBag();
        $this->reset(['errorMessage', 'successMessage']);
        $this->editingId = $brand->id;
        $this->name_ar = (string) $brand->name_ar;
        $this->name_en = (string) ($brand->name_en ?? '');
        $this->active = (bool) $brand->active;
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->authorizeManager();
        $this->showModal = false;
        $this->reset(['editingId', 'name_ar', 'name_en']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->name_ar = trim($this->name_ar);
        $this->name_en = trim($this->name_en);

        $validated = $this->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
        ]);

        $editingId = $this->editingId;
        $nameAr = $validated['name_ar'];
        $nameEn = $validated['name_en'] !== null && $validated['name_en'] !== '' ? $validated['name_en'] : null;
        $active = (bool) $validated['active'];

        try {
            app(TenantWriteAccess::class)->transaction((int) $this->lockedCompanyId, function () use ($editingId, $nameAr, $nameEn, $active) {
                /** @var User $actor */
                $actor = auth()->user();
                app(SalesActorGuard::class)->lockAndAuthorize(
                    $this->lockedCompanyId,
                    $actor,
                    'inventory.product.manage'
                );

                if ($editingId !== null) {
                    /** @var Brand $brand */
                    $brand = Brand::where('company_id', $this->lockedCompanyId)
                        ->where('id', $editingId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $brand->update([
                        'name_ar' => $nameAr,
                        'name_en' => $nameEn,
                        'active' => $active,
                    ]);
                } else {
                    Brand::create([
                        'company_id' => $this->lockedCompanyId,
                        'name_ar' => $nameAr,
                        'name_en' => $nameEn,
                        'active' => $active,
                    ]);
                }
            });
        } catch (AuthorizationException $e) {
            abort(403);
        }

        $this->successMessage = __('inventory.brand_saved_success');
        $this->showModal = false;
        $this->reset(['editingId', 'name_ar', 'name_en']);
    }

    public function toggleActive(int $id): void
    {
        try {
            $newActive = app(TenantWriteAccess::class)->transaction((int) $this->lockedCompanyId, function () use ($id) {
                /** @var User $actor */
                $actor = auth()->user();
                app(SalesActorGuard::class)->lockAndAuthorize(
                    $this->lockedCompanyId,
                    $actor,
                    'inventory.product.manage'
                );

                /** @var Brand $brand */
                $brand = Brand::where('company_id', $this->lockedCompanyId)
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $newActive = ! $brand->active;
                $brand->update(['active' => $newActive]);

                return $newActive;
            });
        } catch (AuthorizationException $e) {
            abort(403);
        }

        $this->successMessage = $newActive
            ? __('inventory.brand_activated_success')
            : __('inventory.brand_deactivated_success');
    }

    public function render(CompanyContext $context): View
    {
        $this->authorizeManager();

        $query = Brand::where('company_id', $this->lockedCompanyId)
            ->withCount('products');

        $searchTerm = mb_substr(trim($this->search), 0, 160);
        if ($searchTerm !== '') {
            $query->where(function (Builder $q) use ($searchTerm) {
                $q->where('name_ar', 'like', "%{$searchTerm}%")
                    ->orWhere('name_en', 'like', "%{$searchTerm}%");
            });
        }

        $brands = $query->orderBy('name_ar')->orderBy('id')->paginate(15);

        return view('livewire.pages.catalog.brand-index', [
            'brands' => $brands,
            'company' => $context->company(),
        ]);
    }

    private function authorizeManager(): Company
    {
        if (! auth()->check()) {
            abort(403);
        }

        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || ! isset($this->lockedCompanyId) || (int) $context->companyId() !== $this->lockedCompanyId) {
            abort(403);
        }

        try {
            return app(TenantWriteAccess::class)->transaction((int) $this->lockedCompanyId, function () {
                /** @var User $actor */
                $actor = auth()->user();

                return app(SalesActorGuard::class)->lockAndAuthorize(
                    $this->lockedCompanyId,
                    $actor,
                    'inventory.product.manage'
                );
            });
        } catch (AuthorizationException $e) {
            abort(403);
        }
    }
}
