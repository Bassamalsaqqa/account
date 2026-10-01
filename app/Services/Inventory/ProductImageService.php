<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\Inventory\Exceptions\InventorySecurityException;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use InvalidArgumentException;

class ProductImageService
{
    public const int MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024; // 5 MB

    public const int MAX_INPUT_DIMENSION = 4000; // 4000px max before decoding

    public const int DISPLAY_MAX_DIMENSION = 1600;

    public const int THUMBNAIL_MAX_DIMENSION = 300;

    public const array ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver, decodeAnimation: false);
    }

    /**
     * Store and process a product image.
     * Enforces active matching company context, authenticated actor, and permission.
     */
    public function storeImage(Product $product, UploadedFile $file, User $actor, bool $isPrimary = false, ?string $altAr = null, ?string $altEn = null): ProductImage
    {
        $this->authorizeActorForProduct($product, $actor);

        // 1. Basic byte limit check
        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException('Image file size exceeds the 5MB maximum limit.');
        }

        // 2. Inspect real image dimensions and MIME via getimagesize
        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo === false) {
            throw new InvalidArgumentException('Uploaded file is corrupted or not a valid image.');
        }

        [$width, $height] = $imageInfo;
        $mime = $imageInfo['mime'];

        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported image format [{$mime}]. Allowed formats: JPG, PNG, WebP.");
        }

        if ($width > self::MAX_INPUT_DIMENSION || $height > self::MAX_INPUT_DIMENSION) {
            throw new InvalidArgumentException("Image dimensions [{$width}x{$height}] exceed the maximum allowed [".self::MAX_INPUT_DIMENSION.'x'.self::MAX_INPUT_DIMENSION.'].');
        }

        // 3. Decode safely (with animation disabled) and re-encode to standard WebP formats
        try {
            $image = $this->manager->decodePath($file->getRealPath());
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Failed to decode image data: '.$e->getMessage(), 0, $e);
        }

        // Standard display image
        $display = clone $image;
        $display->scaleDown(width: self::DISPLAY_MAX_DIMENSION, height: self::DISPLAY_MAX_DIMENSION);
        $encodedDisplay = $display->encode(new WebpEncoder(quality: 85));

        // Thumbnail image
        $thumb = clone $image;
        $thumb->scaleDown(width: self::THUMBNAIL_MAX_DIMENSION, height: self::THUMBNAIL_MAX_DIMENSION);
        $encodedThumb = $thumb->encode(new WebpEncoder(quality: 80));

        // 4. Managed storage paths (strictly traversal-free)
        $companyId = $product->company_id;
        $productId = $product->id;
        $hash = (string) Str::ulid();
        $directory = "products/{$companyId}/{$productId}";

        $displayPath = "{$directory}/{$hash}.webp";
        $thumbPath = "{$directory}/{$hash}_thumb.webp";

        $this->validateManagedPath($displayPath, $companyId, $productId);
        $this->validateManagedPath($thumbPath, $companyId, $productId);

        // Write display image
        $putDisplay = Storage::disk('public')->put($displayPath, (string) $encodedDisplay);
        if (! $putDisplay) {
            throw new InvalidArgumentException('Failed to write image file to disk.');
        }

        // Write thumbnail; on failure, clean up display image
        try {
            $putThumb = Storage::disk('public')->put($thumbPath, (string) $encodedThumb);
            if (! $putThumb) {
                throw new InvalidArgumentException('Failed to write thumbnail file to disk.');
            }
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($displayPath);
            throw new InvalidArgumentException('Failed to store image thumbnail: '.$e->getMessage(), 0, $e);
        }

        // 5. Metadata creation under Product lock
        try {
            return DB::transaction(function () use ($productId, $companyId, $displayPath, $thumbPath, $display, $encodedDisplay, $isPrimary, $altAr, $altEn, $actor) {
                // Lock product
                Product::where('id', $productId)->lockForUpdate()->firstOrFail();

                if ($isPrimary) {
                    ProductImage::where('company_id', $companyId)
                        ->where('product_id', $productId)
                        ->update(['is_primary' => false]);
                } else {
                    $hasPrimary = ProductImage::where('company_id', $companyId)
                        ->where('product_id', $productId)
                        ->where('is_primary', true)
                        ->exists();
                    if (! $hasPrimary) {
                        $isPrimary = true;
                    }
                }

                $sortOrder = (int) (ProductImage::where('company_id', $companyId)
                    ->where('product_id', $productId)
                    ->max('sort_order') ?? 0) + 1;

                return ProductImage::create([
                    'company_id' => $companyId,
                    'product_id' => $productId,
                    'disk' => 'public',
                    'path' => $displayPath,
                    'thumbnail_path' => $thumbPath,
                    'mime_type' => 'image/webp',
                    'width' => $display->width(),
                    'height' => $display->height(),
                    'file_size' => strlen((string) $encodedDisplay),
                    'is_primary' => $isPrimary,
                    'sort_order' => $sortOrder,
                    'alt_ar' => $altAr,
                    'alt_en' => $altEn,
                    'created_by' => $actor->id,
                ]);
            });
        } catch (\Throwable $e) {
            // DB failure: clean up orphan files
            Storage::disk('public')->delete($displayPath);
            Storage::disk('public')->delete($thumbPath);
            throw $e;
        }
    }

    /**
     * Safely delete a product image ensuring managed-prefix tenant boundaries.
     * Enforces active matching company context, authenticated actor, and permission.
     */
    public function deleteImage(ProductImage $image, ?User $actor = null): void
    {
        $actor = $actor ?? auth()->user();
        if (! $actor) {
            throw new InventorySecurityException('Image deletion requires an authenticated user.');
        }

        $this->authorizeActorForCompany($image->company_id, $actor);

        if ($image->disk !== 'public') {
            throw new InventorySecurityException("Unsafe disk [{$image->disk}] rejected.");
        }

        $this->validateManagedPath($image->path, $image->company_id, $image->product_id);
        if (! empty($image->thumbnail_path)) {
            $this->validateManagedPath($image->thumbnail_path, $image->company_id, $image->product_id);
        }

        DB::transaction(function () use ($image) {
            Product::where('id', $image->product_id)->lockForUpdate()->firstOrFail();

            Storage::disk($image->disk)->delete($image->path);
            if (! empty($image->thumbnail_path)) {
                Storage::disk($image->disk)->delete($image->thumbnail_path);
            }

            $wasPrimary = $image->is_primary;
            $productId = $image->product_id;
            $companyId = $image->company_id;

            $image->delete();

            // If primary was deleted, promote next image in sort order
            if ($wasPrimary) {
                $next = ProductImage::where('company_id', $companyId)
                    ->where('product_id', $productId)
                    ->orderBy('sort_order')
                    ->first();

                $next?->update(['is_primary' => true]);
            }
        });
    }

    /**
     * Mark an image as the primary image for its product.
     * Enforces active matching company context, authenticated actor, and permission.
     */
    public function setPrimary(ProductImage $image, ?User $actor = null): void
    {
        $actor = $actor ?? auth()->user();
        if (! $actor) {
            throw new InventorySecurityException('Setting primary image requires an authenticated user.');
        }

        $this->authorizeActorForCompany($image->company_id, $actor);

        DB::transaction(function () use ($image) {
            Product::where('id', $image->product_id)->lockForUpdate()->firstOrFail();

            ProductImage::where('company_id', $image->company_id)
                ->where('product_id', $image->product_id)
                ->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);
        });
    }

    /**
     * Convenience alias for storeImage.
     */
    public function store(Product $product, UploadedFile $file, User $actor, bool $isPrimary = false, ?string $altAr = null, ?string $altEn = null): ProductImage
    {
        return $this->storeImage($product, $file, $actor, $isPrimary, $altAr, $altEn);
    }

    /**
     * Convenience alias for deleteImage.
     */
    public function delete(ProductImage $image, ?User $actor = null): void
    {
        $this->deleteImage($image, $actor);
    }

    /**
     * Authorize actor for product operations.
     */
    private function authorizeActorForProduct(Product $product, User $actor): void
    {
        $this->authorizeActorForCompany($product->company_id, $actor);
    }

    /**
     * Authorize actor for company operations.
     */
    private function authorizeActorForCompany(int $companyId, User $actor): void
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== $companyId) {
            throw new InventorySecurityException("Active company context does not match target company [{$companyId}].");
        }

        if (! auth()->check()) {
            throw new InventorySecurityException('Image operation requires an authenticated user.');
        }

        $authUser = auth()->user();
        if ((int) $actor->id !== (int) $authUser->id) {
            throw new InventorySecurityException("Authenticated user [{$authUser->id}] cannot operate on behalf of [{$actor->id}].");
        }

        if (! $authUser->hasPermissionTo('inventory.product.manage') && ! $authUser->hasRole('owner')) {
            throw new AuthorizationException('User does not have permission to manage product images.');
        }

        $isMember = CompanyUser::where('company_id', $companyId)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            throw new InventorySecurityException(
                "Actor [{$actor->id}] is not an active member of company [{$companyId}]. Operation rejected."
            );
        }
    }

    /**
     * Validate that path strictly matches managed traversal-free pattern.
     */
    private function validateManagedPath(string $path, int $companyId, int $productId): void
    {
        $pattern = "#^products/{$companyId}/{$productId}/[a-zA-Z0-9_\-]+\.webp$#";
        if (! preg_match($pattern, $path)) {
            throw new InventorySecurityException("Unsafe or invalid image path [{$path}] rejected for company [{$companyId}].");
        }
    }
}
