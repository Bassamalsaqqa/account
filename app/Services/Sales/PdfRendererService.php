<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Documents\DocumentData;
use App\Models\CustomerPayment;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

class PdfRendererService
{
    /**
     * Generate QR Code as a base64 Data-URI.
     */
    public function generateQrDataUri(string $content): string
    {
        $qrCode = new QrCode($content);
        $writer = new PngWriter;
        $result = $writer->write($qrCode);

        return $result->getDataUri();
    }

    public function renderInvoice(SalesInvoice $invoice, ?string $qrUrl = null): string
    {
        return $this->renderDocument(app(DocumentDataBuilder::class)->build($invoice), $qrUrl);
    }

    public function renderQuotation(Quotation $quotation, ?string $qrUrl = null): string
    {
        return $this->renderDocument(app(DocumentDataBuilder::class)->build($quotation, forPdf: true), $qrUrl);
    }

    public function renderReturn(SalesReturn $return, ?string $qrUrl = null): string
    {
        return $this->renderDocument(app(DocumentDataBuilder::class)->build($return), $qrUrl);
    }

    public function renderPayment(CustomerPayment $payment): string
    {
        return $this->renderDocument(app(DocumentDataBuilder::class)->build($payment));
    }

    /** @param array<string, mixed> $statementData */
    public function renderStatement(array $statementData): string
    {
        return $this->renderDocument(app(DocumentDataBuilder::class)->statement($statementData));
    }

    public function renderDocument(DocumentData $data, ?string $qrUrl = null): string
    {
        $html = app(DocumentRenderer::class)->html($data, $qrUrl ? $this->generateQrDataUri($qrUrl) : null);

        return $this->renderHtml($html, $data->type.'_'.($data->document['number'] ?? 'draft'), $data->locale === 'ar');
    }

    public function renderSalesInvoice(SalesInvoice $invoice, ?string $qrUrl = null): string
    {
        return $this->renderInvoice($invoice, $qrUrl);
    }

    public function renderSalesReturn(SalesReturn $return, ?string $qrUrl = null): string
    {
        return $this->renderReturn($return, $qrUrl);
    }

    public function renderCustomerPayment(CustomerPayment $payment): string
    {
        return $this->renderPayment($payment);
    }

    /** @param array<string, mixed> $statementData */
    public function renderCustomerStatement(array $statementData): string
    {
        return $this->renderStatement($statementData);
    }

    /**
     * Internal method to build mPDF instance and render PDF binary.
     */
    protected function renderHtml(string $html, string $title, bool $isRtl = true): string
    {
        $tempDir = storage_path('app/mpdf_tmp');
        if (! File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 12,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir,
        ]);

        $mpdf->SetTitle($title);
        $mpdf->SetDirectionality($isRtl ? 'rtl' : 'ltr');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }
}
