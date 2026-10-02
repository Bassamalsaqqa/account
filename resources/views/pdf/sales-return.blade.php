@include('pdf.document', ['data' => app(\App\Services\Sales\DocumentDataBuilder::class)->build($return), 'qrDataUri' => $qrDataUri ?? null])
