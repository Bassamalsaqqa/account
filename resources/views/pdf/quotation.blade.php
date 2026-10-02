@include('pdf.document', ['data' => app(\App\Services\Sales\DocumentDataBuilder::class)->build($quotation), 'qrDataUri' => $qrDataUri ?? null])
