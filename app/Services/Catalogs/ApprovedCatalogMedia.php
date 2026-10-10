<?php

declare(strict_types=1);

namespace App\Services\Catalogs;

use App\Models\ProductImage;
use App\Services\Sales\IssuedDocumentContent;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/** Public marketing images: approval is frozen, but distributed static URLs cannot be recalled. */
final class ApprovedCatalogMedia
{
    /** @return array<string,int|string> */
    public function approve(int $companyId, int $productId, int $imageId): array
    {
        $image = ProductImage::where('company_id', $companyId)->where('product_id', $productId)->findOrFail($imageId);
        if ($image->disk !== 'public') {
            throw new InvalidArgumentException('Only managed public Product photographs may be approved.');
        }
        $path = (string) $image->path;
        $thumbnail = $image->thumbnail_path ?: $path;

        return ['image_id' => $imageId, 'company_id' => $companyId, 'product_id' => $productId, 'path' => $path, 'thumbnail' => $thumbnail,
            'hash' => $this->verifiedHash($companyId, $productId, $path), 'thumbnail_hash' => $this->verifiedHash($companyId, $productId, $thumbnail)];
    }

    private function file(int $companyId, int $productId, string $path): string
    {
        if (! preg_match('#^products/'.$companyId.'/'.$productId.'/[A-Za-z0-9_-]+\.webp$#D', $path)) {
            throw new InvalidArgumentException('Untrusted catalog image identity.');
        }
        $root = realpath(Storage::disk('public')->path('products/'.$companyId.'/'.$productId));
        $publicRoot = realpath(Storage::disk('public')->path(''));
        $file = realpath(Storage::disk('public')->path($path));
        $expectedRoot = $publicRoot === false ? null : $publicRoot.DIRECTORY_SEPARATOR.'products'.DIRECTORY_SEPARATOR.$companyId.DIRECTORY_SEPARATOR.$productId;
        if ($root === false || $root !== $expectedRoot || $file === false || $file !== $publicRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path)
            || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_readable($file)
            || filesize($file) > 2 * 1024 * 1024 || mime_content_type($file) !== 'image/webp') {
            throw new InvalidArgumentException('Catalog image is unavailable.');
        }
        $dimensions = getimagesize($file);
        if ($dimensions === false || $dimensions[0] > 2048 || $dimensions[1] > 2048) {
            throw new InvalidArgumentException('Catalog image exceeds the preparation limit.');
        }

        return $file;
    }

    private function verifiedHash(int $companyId, int $productId, string $path): string
    {
        $hash = hash_file('sha256', $this->file($companyId, $productId, $path));
        if ($hash === false) {
            throw new InvalidArgumentException('Catalog image integrity could not be verified.');
        }

        return $hash;
    }

    /** @param array<string,mixed> $reference */
    public function render(array $reference, int $companyId, int $productId, bool $embedded = false): ?string
    {
        try {
            if (($reference['company_id'] ?? null) !== $companyId || ($reference['product_id'] ?? null) !== $productId) {
                return null;
            }
            $current = $this->approve($companyId, $productId, $reference['image_id']);
            // No fallback to primary image, including replacement bytes under an approved key.
            $canonical = app(IssuedDocumentContent::class);
            if (! hash_equals($canonical->canonical($current), $canonical->canonical($reference))) {
                return null;
            }
            $file = $this->file($companyId, $productId, $reference['thumbnail']);
            if ($embedded) {
                $handle = fopen($file, 'rb');
                if ($handle === false) {
                    return null;
                }
                try {
                    $bytes = stream_get_contents($handle, 2 * 1024 * 1024 + 1);
                } finally {
                    fclose($handle);
                }

                return $bytes === false || strlen($bytes) > 2 * 1024 * 1024 || ! hash_equals($reference['thumbnail_hash'], hash('sha256', $bytes))
                    ? null : 'data:image/webp;base64,'.base64_encode($bytes);
            }

            return rtrim((string) config('app.url'), '/').'/storage/'.$reference['thumbnail'];
        } catch (\Throwable) {
            return null;
        }
    }
}
