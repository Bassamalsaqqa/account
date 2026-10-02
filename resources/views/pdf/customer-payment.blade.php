@include('pdf.document', ['data' => app(\App\Services\Sales\DocumentDataBuilder::class)->build($payment), 'qrDataUri' => $qrDataUri ?? null])
