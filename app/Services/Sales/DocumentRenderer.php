<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Documents\DocumentData;

final class DocumentRenderer
{
    /** @param array<string,string> $publicLinks */
    public function html(DocumentData $data, ?string $qrDataUri = null, bool $printControls = false, array $publicLinks = []): string
    {
        app(DocumentRenderLimits::class)->assertDocument($data);
        $previousLocale = app()->getLocale();
        try {
            app()->setLocale($data->locale);

            return view('pdf.document', ['data' => $data, 'qrDataUri' => $qrDataUri, 'printControls' => $printControls, 'publicLinks' => $publicLinks])->render();
        } finally {
            app()->setLocale($previousLocale);
        }
    }
}
