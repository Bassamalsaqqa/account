@include('pdf.document', ['data' => app(\App\Services\Sales\DocumentDataBuilder::class)->build($invoice), 'qrDataUri' => $qrDataUri ?? null])
