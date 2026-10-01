<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Products;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Inventory\ProductImageService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class ProductForm extends Component
{
    use WithFileUploads;

    public ?Product $product = null;

    public bool $isEditing = false;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    // Form fields
    public string $name_ar = '';

    public string $name_en = '';

    public string $sku = '';

    public ?int $category_id = null;

    public ?int $brand_id = null;

    public ?int $base_unit_id = null;

    public string $product_type = Product::TYPE_STOCK;

    public bool $track_stock = true;

    public bool $track_expiry = false;

    public bool $active = true;

    public string $description_ar = '';

    public string $description_en = '';

    public string $suggested_sale_price = '';

    public string $suggested_purchase_cost = '';

    public string $minimum_stock = '';

    // Alternate units
    public ?int $new_alt_unit_id = null;

    public string $new_alt_conversion = '';

    public bool $new_alt_sell = false;

    public bool $new_alt_purchase = false;

    // Barcodes
    public string $new_barcode = '';

    public ?int $new_barcode_unit_id = null;

    public bool $new_barcode_primary = false;

    // Images
    /** @var mixed */
    public $new_image = null;

    public bool $hasMovements = false;

    public function mount(CompanyContext $context, ?string $publicId = null): void
    {
        $company = $context->company();

        /** @var User $user */
        $user = auth()->user();
        $canViewCost = $user->hasPermissionTo('inventory.cost.view');

        if ($publicId !== null) {
            $this->isEditing = true;
            $this->product = Product::where('company_id', $company->id)
                ->where('public_id', $publicId)
                ->firstOrFail();

            $this->authorize('update', $this->product);

            $this->name_ar = (string) $this->product->name_ar;
            $this->name_en = (string) ($this->product->name_en ?? '');
            $this->sku = (string) ($this->product->sku ?? '');
            $this->category_id = $this->product->category_id;
            $this->brand_id = $this->product->brand_id;
            $this->base_unit_id = $this->product->base_unit_id;
            $this->product_type = (string) $this->product->product_type;
            $this->track_stock = (bool) $this->product->track_stock;
            $this->track_expiry = (bool) $this->product->track_expiry;
            $this->active = (bool) $this->product->active;
            $this->description_ar = (string) ($this->product->description_ar ?? '');
            $this->description_en = (string) ($this->product->description_en ?? '');
            $this->suggested_sale_price = $this->product->default_sale_price_base !== null ? (string) $this->product->default_sale_price_base : '';
            $this->minimum_stock = $this->product->minimum_stock_base !== null ? (string) $this->product->minimum_stock_base : '';

            // Only populate purchase cost if authorized
            if ($canViewCost) {
                $this->suggested_purchase_cost = $this->product->default_purchase_cost_base !== null ? (string) $this->product->default_purchase_cost_base : '';
            }

            $this->hasMovements = StockMovement::where('company_id', $company->id)
                ->where('product_id', $this->product->id)
                ->exists();
        } else {
            $this->isEditing = false;
            $this->authorize('create', Product::class);

            // Default base unit to first available or piece
            $defaultUnit = Unit::where('company_id', $company->id)->where('code', 'piece')->first()
                ?? Unit::where('company_id', $company->id)->first();
            $this->base_unit_id = $defaultUnit?->id;
        }
    }

    public function save(CompanyContext $context): void
    {
        $company = $context->company();

        /** @var User $user */
        $user = auth()->user();
        $canViewCost = $user->hasPermissionTo('inventory.cost.view');

        if ($this->isEditing && $this->product) {
            $this->authorize('update', $this->product);
        } else {
            $this->authorize('create', Product::class);
        }

        $validated = $this->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'sku' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('products', 'sku')
                    ->where('company_id', $company->id)
                    ->ignore($this->product?->id),
            ],
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('company_id', $company->id)],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('company_id', $company->id)],
            'base_unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('company_id', $company->id)],
            'product_type' => ['required', 'string', Rule::in([Product::TYPE_STOCK, Product::TYPE_NON_STOCK, Product::TYPE_SERVICE])],
            'track_stock' => ['required', 'boolean'],
            'track_expiry' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'suggested_sale_price' => ['nullable', 'regex:/\A\d+(\.\d+)?\z/', 'min:0'],
            'suggested_purchase_cost' => ['nullable', 'regex:/\A\d+(\.\d+)?\z/', 'min:0'],
            'minimum_stock' => ['nullable', 'regex:/\A\d+(\.\d+)?\z/', 'min:0'],
        ]);

        $data = [
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'] ?: null,
            'sku' => $validated['sku'] ?: null,
            'category_id' => $validated['category_id'] ?: null,
            'brand_id' => $validated['brand_id'] ?: null,
            'base_unit_id' => $validated['base_unit_id'],
            'product_type' => $validated['product_type'],
            'track_stock' => (bool) $validated['track_stock'],
            'track_expiry' => (bool) $validated['track_expiry'],
            'active' => (bool) $validated['active'],
            'description_ar' => $validated['description_ar'] ?: null,
            'description_en' => $validated['description_en'] ?: null,
            'default_sale_price_base' => $validated['suggested_sale_price'] !== '' ? $validated['suggested_sale_price'] : null,
            'minimum_stock_base' => $validated['minimum_stock'] !== '' ? $validated['minimum_stock'] : null,
        ];

        // Only save purchase cost if authorized to view/edit cost
        if ($canViewCost && $validated['suggested_purchase_cost'] !== '') {
            $data['default_purchase_cost_base'] = $validated['suggested_purchase_cost'];
        } elseif ($canViewCost) {
            $data['default_purchase_cost_base'] = null;
        }

        try {
            $catalogService = app(ProductCatalogService::class);

            if ($this->isEditing && $this->product) {
                $this->product = $catalogService->updateProduct($this->product, $data, (int) auth()->id());
                $this->successMessage = __('inventory.product_saved_success');
            } else {
                $this->product = $catalogService->createProduct($company, $data, (int) auth()->id());
                $this->isEditing = true;
                $this->successMessage = __('inventory.product_saved_success');
            }
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function addAlternateUnit(CompanyContext $context): void
    {
        if (! $this->product) {
            return;
        }

        $company = $context->company();
        $this->authorize('update', $this->product);

        $validated = $this->validate([
            'new_alt_unit_id' => [
                'required',
                'integer',
                Rule::exists('units', 'id')->where('company_id', $company->id),
                Rule::notIn([$this->product->base_unit_id]),
            ],
            'new_alt_conversion' => ['required', 'regex:/\A\d+(\.\d+)?\z/', 'gt:0'],
            'new_alt_sell' => ['required', 'boolean'],
            'new_alt_purchase' => ['required', 'boolean'],
        ]);

        $unitId = (int) $validated['new_alt_unit_id'];

        try {
            $catalogService = app(ProductCatalogService::class);
            $catalogService->addOrUpdateAlternateUnit(
                $this->product,
                $unitId,
                $validated['new_alt_conversion'],
                (bool) $validated['new_alt_sell'],
                (bool) $validated['new_alt_purchase']
            );

            $this->new_alt_unit_id = null;
            $this->new_alt_conversion = '';
            $this->new_alt_sell = false;
            $this->new_alt_purchase = false;
            $this->successMessage = __('inventory.product_saved_success');
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function removeAlternateUnit(int $productUnitId): void
    {
        if (! $this->product) {
            return;
        }

        $this->authorize('update', $this->product);

        try {
            $catalogService = app(ProductCatalogService::class);
            $catalogService->removeAlternateUnit($this->product, $productUnitId);
            $this->successMessage = __('inventory.product_saved_success');
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function addBarcode(CompanyContext $context): void
    {
        if (! $this->product) {
            return;
        }

        $company = $context->company();
        $this->authorize('update', $this->product);

        $validated = $this->validate([
            'new_barcode' => [
                'required',
                'string',
                'max:128',
                Rule::unique('product_barcodes', 'barcode')->where('company_id', $company->id),
            ],
            'new_barcode_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('company_id', $company->id)],
            'new_barcode_primary' => ['required', 'boolean'],
        ]);

        $trimmedBarcode = trim($validated['new_barcode']);

        try {
            $catalogService = app(ProductCatalogService::class);
            $catalogService->addBarcode(
                $this->product,
                $trimmedBarcode,
                $validated['new_barcode_unit_id'] ? (int) $validated['new_barcode_unit_id'] : null,
                (bool) $validated['new_barcode_primary']
            );

            $this->new_barcode = '';
            $this->new_barcode_unit_id = null;
            $this->new_barcode_primary = false;
            $this->successMessage = __('inventory.product_saved_success');
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function removeBarcode(int $barcodeId): void
    {
        if (! $this->product) {
            return;
        }

        $this->authorize('update', $this->product);

        try {
            $catalogService = app(ProductCatalogService::class);
            $catalogService->removeBarcode($this->product, $barcodeId);
            $this->successMessage = __('inventory.product_saved_success');
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function uploadImage(CompanyContext $context, ProductImageService $imageService): void
    {
        if (! $this->product) {
            return;
        }

        $this->authorize('update', $this->product);

        $this->validate([
            'new_image' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:5120'], // 5MB limit
        ]);

        /** @var User $user */
        $user = auth()->user();

        try {
            $imageService->store($this->product, $this->new_image, $user);
            $this->new_image = null;
            $this->successMessage = __('inventory.image_uploaded_success');
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function deleteImage(int $imageId, ProductImageService $imageService): void
    {
        if (! $this->product) {
            return;
        }

        $this->authorize('update', $this->product);

        /** @var ProductImage|null $image */
        $image = ProductImage::where('id', $imageId)
            ->where('product_id', $this->product->id)
            ->first();

        if ($image) {
            $imageService->delete($image);
            $this->successMessage = __('inventory.image_deleted_success');
        }
    }

    public function setPrimaryImage(int $imageId, ProductImageService $imageService): void
    {
        if (! $this->product) {
            return;
        }

        $this->authorize('update', $this->product);

        /** @var ProductImage|null $image */
        $image = ProductImage::where('id', $imageId)
            ->where('product_id', $this->product->id)
            ->first();

        if ($image) {
            $imageService->setPrimary($image);
            $this->successMessage = __('inventory.primary_image_updated_success');
        }
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();

        /** @var User $user */
        $user = auth()->user();
        $canViewCost = $user->hasPermissionTo('inventory.cost.view');

        $categories = ProductCategory::where('company_id', $company->id)->where('active', true)->orderBy('sort_order')->get();
        $brands = Brand::where('company_id', $company->id)->where('active', true)->orderBy('name_ar')->get();
        $units = Unit::where('company_id', $company->id)->where('active', true)->orderBy('name_ar')->get();

        $productUnits = collect();
        $productBarcodes = collect();
        $productImages = collect();

        if ($this->product) {
            $productUnits = ProductUnit::where('product_id', $this->product->id)->with('unit')->get();
            $productBarcodes = ProductBarcode::where('product_id', $this->product->id)->with('unit')->get();
            $productImages = ProductImage::where('product_id', $this->product->id)->orderBy('sort_order')->get();
        }

        return view('livewire.pages.products.product-form', [
            'categories' => $categories,
            'brands' => $brands,
            'units' => $units,
            'productUnits' => $productUnits,
            'productBarcodes' => $productBarcodes,
            'productImages' => $productImages,
            'canViewCost' => $canViewCost,
            'company' => $company,
        ]);
    }
}
