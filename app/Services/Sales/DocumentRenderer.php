<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Documents\DocumentData;

final class DocumentRenderer
{
    public function html(DocumentData $data, ?string $qrDataUri = null): string
    {
        $previousLocale = app()->getLocale();
        try {
            app()->setLocale($data->locale);

            return view('pdf.document', ['data' => $data, 'qrDataUri' => $qrDataUri])->render();
        } finally {
            app()->setLocale($previousLocale);
        }
    }
}
