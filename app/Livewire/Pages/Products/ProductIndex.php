<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Products;

use App\Livewire\Concerns\CameraBarcodeScan;
use App\Models\Brand;
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
    use CameraBarcodeScan;
    use WithPagination;

    #[Url(as: 'barcode')]
    public ?string $cameraBarcode = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'brand')]
    public string $brandFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'low_stock')]
    public bool $lowStockOnly = false;

    #[Url(as: 'expiry')]
    public bool $expiryOnly = false;

    public function updatedSearch(): void
    {
        $this->cameraBarcode = null;
        $this->resetPage();
    }

    public function clearCameraBarcode(): void
    {
        $this->cameraBarcode = null;
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedBrandFilter(): void
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

    /**
     * @return array{ok: bool, message: string}
     */
    protected function handleCameraBarcodeScan(string $code): array
    {
        $this->authorize('viewAny', Product::class);

        $trimmed = trim($code);
        if ($trimmed === '' || mb_strlen($trimmed) > 128) {
            return [
                'ok' => false,
                'message' => __('camera.invalid_code'),
            ];
        }

        $context = app(CompanyContext::class);
        $company = $context->company();

        $matched = Product::where('company_id', $company->id)
            ->where(function (Builder $q) use ($trimmed, $company) {
                $q->where('sku', $trimmed)
                    ->orWhereHas('barcodes', function (Builder $bQ) use ($trimmed, $company) {
                        $bQ->where('company_id', $company->id)->where('barcode', $trimmed);
                    });
            })
            ->first();

        if ($matched === null) {
            return [
                'ok' => false,
                'message' => __('camera.product_not_found'),
            ];
        }

        $this->cameraBarcode = $trimmed;
        $this->resetPage();

        return [
            'ok' => true,
            'message' => __('camera.scan_success'),
        ];
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
            ->with(['category', 'brand', 'baseUnit', 'primaryImage']);

        if ($canViewCost) {
            $query->with('costState');
        }

        // Camera barcode filter takes precedence for exact matching; bounds strictly verified
        $sanitizedBarcode = is_string($this->cameraBarcode) ? trim($this->cameraBarcode) : '';
        if (mb_strlen($sanitizedBarcode) > 128) {
            $this->cameraBarcode = null;
            $sanitizedBarcode = '';
            $this->addError('cameraBarcode', __('camera.barcode_too_long'));
        }
        if ($sanitizedBarcode !== '' && mb_strlen($sanitizedBarcode) <= 128) {
            $term = $sanitizedBarcode;
            $query->where(function (Builder $q) use ($term, $company) {
                $q->where('sku', $term)
                    ->orWhereHas('barcodes', function (Builder $bQ) use ($term, $company) {
                        $bQ->where('company_id', $company->id)->where('barcode', $term);
                    });
            });
        } elseif (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function (Builder $q) use ($term, $company) {
                $q->where('name_ar', 'like', "%{$term}%")
                    ->orWhere('name_en', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhereHas('barcodes', function (Builder $bQ) use ($term, $company) {
                        $bQ->where('company_id', $company->id)
                            ->where('barcode', 'like', "%{$term}%");
                    });
            });
        }

        // Category filter
        if ($this->categoryFilter !== '') {
            $query->where('category_id', $this->categoryFilter);
        }

        // Brand filter
        if ($this->brandFilter !== '') {
            $query->where('brand_id', $this->brandFilter);
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
        $brands = Brand::where('company_id', $company->id)
            ->withCount('products')
            ->orderBy('name_ar')
            ->get();

        return view('livewire.pages.products.product-index', [
            'products' => $products,
            'categories' => $categories,
            'brands' => $brands,
            'canViewCost' => $canViewCost,
            'canManageProduct' => $canManageProduct,
            'company' => $company,
        ]);
    }
}
