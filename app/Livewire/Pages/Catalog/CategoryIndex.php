<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Catalog;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CategoryIndex extends Component
{
    public ?int $editingId = null;

    public string $name_ar = '';

    public string $name_en = '';

    public ?int $parent_id = null;

    public int $sort_order = 0;

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

        $this->reset(['editingId', 'name_ar', 'name_en', 'parent_id', 'sort_order', 'active', 'errorMessage', 'successMessage']);
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
        /** @var ProductCategory $category */
        $category = ProductCategory::where('company_id', $company->id)->where('id', $id)->firstOrFail();

        $this->editingId = $category->id;
        $this->name_ar = (string) $category->name_ar;
        $this->name_en = (string) ($category->name_en ?? '');
        $this->parent_id = $category->parent_id;
        $this->sort_order = (int) $category->sort_order;
        $this->active = (bool) $category->active;
        $this->showModal = true;
    }

    public function save(CompanyContext $context): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.product.manage')) {
            abort(403);
        }

        $company = $context->company();

        $validated = $this->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('product_categories', 'id')->where('company_id', $company->id),
                Rule::notIn($this->editingId !== null ? [$this->editingId] : []),
            ],
            'sort_order' => ['required', 'integer', 'min:0'],
            'active' => ['required', 'boolean'],
        ]);

        $data = [
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'] ?: null,
            'parent_id' => $validated['parent_id'] ?: null,
            'sort_order' => $validated['sort_order'],
            'active' => (bool) $validated['active'],
        ];

        if ($this->editingId !== null) {
            ProductCategory::where('company_id', $company->id)
                ->where('id', $this->editingId)
                ->update($data);
            $this->successMessage = __('inventory.category_saved_success');
        } else {
            $data['company_id'] = $company->id;
            ProductCategory::create($data);
            $this->successMessage = __('inventory.category_saved_success');
        }

        $this->showModal = false;
    }

    public function deleteCategory(int $id, CompanyContext $context): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasPermissionTo('inventory.product.manage')) {
            abort(403);
        }

        $company = $context->company();

        // Check product references
        $hasProducts = Product::where('company_id', $company->id)
            ->where('category_id', $id)
            ->exists();

        if ($hasProducts) {
            $this->errorMessage = __('inventory.cannot_delete_category_with_products');

            return;
        }

        ProductCategory::where('company_id', $company->id)->where('id', $id)->delete();
        $this->successMessage = __('inventory.category_deleted_success');
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();

        /** @var User $user */
        $user = auth()->user();
        $canManage = $user->hasPermissionTo('inventory.product.manage');

        $categories = ProductCategory::where('company_id', $company->id)
            ->with(['parent', 'products'])
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->get();

        return view('livewire.pages.catalog.category-index', [
            'categories' => $categories,
            'canManage' => $canManage,
            'company' => $company,
        ]);
    }
}
