<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Inventory\Exceptions\InventorySecurityException;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductImageService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ProductImageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Product $product;

    protected ProductImageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->actingAs($this->user);

        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة تجارب الصور',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $unit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج تجريبي للصور',
            'sku' => 'IMG-TEST-001',
            'base_unit_id' => $unit->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->service = app(ProductImageService::class);
    }

    public function test_valid_image_is_processed_stored_as_webp_and_sets_primary(): void
    {
        // 1. Create a fake valid PNG image (500x500)
        $file = UploadedFile::fake()->image('test_product.png', 500, 500);

        $image = $this->service->storeImage(
            product: $this->product,
            file: $file,
            actor: $this->user,
            isPrimary: true,
            altAr: 'صورة المنتج الرئيسية'
        );

        $this->assertInstanceOf(ProductImage::class, $image);
        $this->assertTrue($image->is_primary);
        $this->assertSame('image/webp', $image->mime_type);
        $this->assertSame($this->company->id, $image->company_id);
        $this->assertSame($this->product->id, $image->product_id);

        // Verify storage paths
        $this->assertStringStartsWith("products/{$this->company->id}/{$this->product->id}/", $image->path);
        $this->assertStringEndsWith('.webp', $image->path);
        $this->assertStringEndsWith('_thumb.webp', $image->thumbnail_path);

        Storage::disk('public')->assertExists($image->path);
        Storage::disk('public')->assertExists($image->thumbnail_path);
    }

    public function test_oversized_file_size_is_rejected(): void
    {
        // 6 MB image exceeds 5MB limit
        $file = UploadedFile::fake()->create('large.jpg', 6 * 1024, 'image/jpeg');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image file size exceeds the 5MB maximum limit.');

        $this->service->storeImage($this->product, $file, $this->user);
    }

    public function test_corrupted_or_spoofed_file_is_rejected(): void
    {
        // Text file disguised with .jpg extension
        $file = UploadedFile::fake()->createWithContent('malicious.jpg', '<?php echo "evil"; ?>');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Uploaded file is corrupted or not a valid image.');

        $this->service->storeImage($this->product, $file, $this->user);
    }

    public function test_dimensions_exceeding_max_allowed_are_rejected(): void
    {
        // 4001x10 exceeds 4000 max input dimension without memory exhaustion
        $file = UploadedFile::fake()->image('huge_dimensions.png', 4001, 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image dimensions [4001x10] exceed the maximum allowed [4000x4000].');

        $this->service->storeImage($this->product, $file, $this->user);
    }

    public function test_safe_delete_removes_files_and_promotes_next_primary(): void
    {
        $file1 = UploadedFile::fake()->image('img1.png', 200, 200);
        $file2 = UploadedFile::fake()->image('img2.png', 200, 200);

        $img1 = $this->service->storeImage($this->product, $file1, $this->user, isPrimary: true);
        $img2 = $this->service->storeImage($this->product, $file2, $this->user, isPrimary: false);

        $this->assertTrue($img1->fresh()->is_primary);
        $this->assertFalse($img2->fresh()->is_primary);

        // Delete primary image img1
        $this->service->deleteImage($img1);

        Storage::disk('public')->assertMissing($img1->path);
        Storage::disk('public')->assertMissing($img1->thumbnail_path);

        // img2 should now be promoted to primary
        $this->assertTrue($img2->fresh()->is_primary);
    }

    public function test_delete_image_with_unsafe_path_throws_security_exception(): void
    {
        $image = ProductImage::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'disk' => 'public',
            'path' => 'products/999/other_company_file.webp', // Malformed/foreign path traversal attempt
            'thumbnail_path' => 'products/999/other_company_thumb.webp',
            'mime_type' => 'image/webp',
            'width' => 100,
            'height' => 100,
            'file_size' => 1000,
            'is_primary' => false,
            'sort_order' => 1,
            'created_by' => $this->user->id,
        ]);

        $this->expectException(InventorySecurityException::class);
        $this->service->deleteImage($image);
    }

    public function test_image_upload_rejected_for_foreign_actor(): void
    {
        $foreignUser = User::factory()->create(['locale' => 'ar']);
        $file = UploadedFile::fake()->image('test.png', 200, 200);

        $this->expectException(InventorySecurityException::class);
        $this->service->storeImage($this->product, $file, $foreignUser);
    }
}
