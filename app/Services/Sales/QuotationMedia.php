<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

final class QuotationMedia
{
    public function primary(int $companyId, ?int $productId, bool $pdf): ?string
    {
        if ($productId === null) {
            return null;
        }
        $image = ProductImage::where('company_id', $companyId)->where('product_id', $productId)->where('is_primary', true)->first();
        if ($image === null || $image->disk !== 'public' || ! preg_match("#^products/{$companyId}/{$productId}/[a-zA-Z0-9_-]+\\.webp$#D", $image->path)) {
            return null;
        }
        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $file = realpath($disk->path($image->path));
        if ($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)
            || ! is_file($file) || ! is_readable($file) || filesize($file) > 2 * 1024 * 1024) {
            return null;
        }
        $info = @getimagesize($file);
        if ($info === false || $info['mime'] !== 'image/webp' || $info[0] > 2048 || $info[1] > 2048) {
            return null;
        }

        // PDF embeds approved public bytes, never a private path or a remote network request.
        return $pdf ? 'data:image/webp;base64,'.base64_encode($disk->get($image->path)) : $disk->url($image->path);
    }
}
