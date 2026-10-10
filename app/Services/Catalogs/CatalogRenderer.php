<?php

declare(strict_types=1);

namespace App\Services\Catalogs;

use App\Services\Sales\PdfRendererService;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;

final class CatalogRenderer extends PdfRendererService
{
    public const int MAX_ITEMS = 250;

    public const int MAX_TEXT_BYTES = 262144; // 256 KiB

    public const int MAX_IMAGE_BYTES = 4194304; // 4 MiB

    /**
     * Render the public / preview HTML string.
     *
     * @param  array<string, mixed>  $data
     */
    public function html(array $data, bool $printControls = false, ?string $token = null): string
    {
        return View::make('catalogs.public', [
            'data' => $data,
            'printControls' => $printControls,
            'token' => $token,
        ])->render();
    }

    /**
     * Render the catalog PDF binary.
     *
     * @param  array<string, mixed>  $data
     */
    public function pdf(array $data, bool $guest = false): string
    {
        $this->assertBoundedPreparation($data);

        $previousLocale = app()->getLocale();
        app()->setLocale($data['locale']);
        try {
            $html = View::make('pdf.catalog', [
                'data' => $data,
            ])->render();

            $title = (string) ($data['title'] ?? 'Product Catalog');
            $isRtl = ($data['locale'] ?? 'ar') === 'ar';

            return $this->renderHtml($html, $title, $isRtl, $guest);
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * Bounded preparation enforcement:
     * - Max 250 items
     * - Aggregate text <= 256 KiB
     * - Aggregate images <= 4 MiB
     * - No remote URLs (http:// or https://)
     *
     * @param  array<string, mixed>  $data
     */
    private function assertBoundedPreparation(array $data): void
    {
        $items = $data['items'] ?? [];
        if (! is_array($items) || count($items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Catalog exceeds the maximum of 250 items for PDF rendering.');
        }

        $textBytes = 0;
        $imageBytes = 0;

        foreach (['title', 'description', 'locale', 'currency_code', 'tax_basis'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $textBytes += strlen($data[$field]);
            }
        }

        if (isset($data['company']) && is_array($data['company'])) {
            foreach ($data['company'] as $val) {
                if (is_string($val)) {
                    $textBytes += strlen($val);
                }
            }
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['name', 'unit', 'sku', 'description'] as $field) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    $textBytes += strlen($item[$field]);
                }
            }

            if (isset($item['image']) && is_string($item['image'])) {
                $img = $item['image'];
                if (preg_match('#^https?://#i', $img)) {
                    throw new InvalidArgumentException('Remote image URLs are forbidden in PDF rendering.');
                }
                $imageBytes += strlen($img);
            }
        }

        if ($textBytes > self::MAX_TEXT_BYTES) {
            throw new InvalidArgumentException('Catalog text exceeds the 256 KiB preparation budget.');
        }

        if ($imageBytes > self::MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException('Catalog images exceed the 4 MiB preparation budget.');
        }
    }
}
