<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Products;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ProductIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'low_stock')]
    public bool $lowStockOnly = false;

    #[Url(as: 'expiry')]
    public bool $expiryOnly = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedLowStockOnly(): void
    {
        $this->resetPage();
    }

    public function updatedExpiryOnly(): void
    {
        $this->resetPage();
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        $this->authorize('viewAny', Product::class);

        /** @var User $user */
        $user = auth()->user();
        $canViewCost = $user->hasPermissionTo('inventory.cost.view');
        $canManageProduct = $user->hasPermissionTo('inventory.product.manage');

        $query = Product::where('company_id', $company->id)
            ->with(['category', 'baseUnit', 'primaryImage']);

        if ($canViewCost) {
            $query->with('costState');
        }

        // Search query
        if (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function (Builder $q) use ($term) {
                $q->where('name_ar', 'like', "%{$term}%")
                    ->orWhere('name_en', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhereHas('barcodes', function (Builder $bQ) use ($term) {
                        $bQ->where('barcode', 'like', "%{$term}%");
                    });
            });
        }

        // Category filter
        if ($this->categoryFilter !== '') {
            $query->where('category_id', $this->categoryFilter);
        }

        // Status filter
        if ($this->statusFilter === 'active') {
            $query->where('active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('active', false);
        }

        // Expiry tracking filter
        if ($this->expiryOnly) {
            $query->where('track_expiry', true);
        }

        // Low-stock filter: includes products below minimum or with zero stock (no cost state row)
        if ($this->lowStockOnly) {
            $query->where('track_stock', true)
                ->whereNotNull('minimum_stock_base')
                ->where(function (Builder $sub) {
                    $sub->whereDoesntHave('costState')
                        ->orWhereHas('costState', function (Builder $q) {
                            $q->whereRaw('inventory_cost_states.quantity_base <= (SELECT minimum_stock_base FROM products WHERE products.id = inventory_cost_states.product_id)');
                        });
                });
        }

        $products = $query->orderBy('name_ar')->paginate(15);

        $categories = ProductCategory::where('company_id', $company->id)->where('active', true)->orderBy('sort_order')->get();

        return view('livewire.pages.products.product-index', [
            'products' => $products,
            'categories' => $categories,
            'canViewCost' => $canViewCost,
            'canManageProduct' => $canManageProduct,
            'company' => $company,
        ]);
    }
}
