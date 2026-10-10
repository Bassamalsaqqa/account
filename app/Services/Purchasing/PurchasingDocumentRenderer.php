<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Services\Sales\PdfRendererService;
use Illuminate\Support\Facades\View;

class PurchasingDocumentRenderer extends PdfRendererService
{
    /**
     * Render the purchasing document as a standalone HTML page.
     *
     * @param  array<string, mixed>  $data
     */
    public function html(array $data, bool $printControls = false): string
    {
        app(PurchasingDocumentBuilder::class)->assertLimits($data);

        $locale = $data['locale'] ?? 'ar';
        $previousLocale = app()->getLocale();

        try {
            app()->setLocale($locale);

            return View::make('pdf.purchasing-document', [
                'data' => $data,
                'printControls' => $printControls,
            ])->render();
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * Render the purchasing document as PDF bytes using the inherited protected renderHtml.
     *
     * @param  array<string, mixed>  $data
     */
    public function pdf(array $data): string
    {
        $html = $this->html($data, printControls: false);

        $locale = $data['locale'] ?? 'ar';
        $type = $data['type'] ?? 'purchase';
        $number = (string) ($data['document']['number'] ?? '');
        $title = __('purchasing_documents.'.$type, [], $locale).' '.$number;

        return $this->renderHtml($html, $title, $locale === 'ar');
    }
}
