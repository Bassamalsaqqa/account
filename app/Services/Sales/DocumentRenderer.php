<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Documents\DocumentData;

final class DocumentRenderer
{
    public function html(DocumentData $data, ?string $qrDataUri = null, bool $printControls = false): string
    {
        app(DocumentRenderLimits::class)->assertDocument($data);
        $previousLocale = app()->getLocale();
        try {
            app()->setLocale($data->locale);

            return view('pdf.document', ['data' => $data, 'qrDataUri' => $qrDataUri, 'printControls' => $printControls])->render();
        } finally {
            app()->setLocale($previousLocale);
        }
    }
}
