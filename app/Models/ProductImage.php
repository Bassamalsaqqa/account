<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'product_id',
        'disk',
        'path',
        'thumbnail_path',
        'mime_type',
        'width',
        'height',
        'file_size',
        'is_primary',
        'sort_order',
        'alt_ar',
        'alt_en',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'file_size' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $image): void {
            if (empty($image->created_by)) {
                $image->created_by = auth()->id() ?? 1;
            }
        });

        static::saving(function (self $image): void {
            if ($image->product_id > 0) {
                $product = CompanyScope::executeWithoutScope(fn () => Product::find($image->product_id));
                if ($product === null || (int) $product->company_id !== (int) $image->company_id) {
                    throw new \InvalidArgumentException("ProductImage product [{$image->product_id}] does not belong to the same company.");
                }
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function thumbnailUrl(): string
    {
        if (! empty($this->thumbnail_path)) {
            return Storage::disk($this->disk)->url($this->thumbnail_path);
        }

        return $this->url();
    }
}
