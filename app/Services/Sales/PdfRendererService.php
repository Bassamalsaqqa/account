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
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mpdf\Mpdf;

class PdfRendererService
{
    /**
     * Generate QR Code as a base64 Data-URI.
     */
    public function generateQrDataUri(string $content): string
    {
        $url = parse_url($content);
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($url === false || ($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? null) !== $host
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! preg_match('#^/(?:share|catalog)/[A-Za-z0-9]{40}$#D', $url['path'] ?? '') || strlen($content) > 2048) {
            throw new InvalidArgumentException('QR requires an application-owned HTTPS URL.');
        }
        $qrCode = new QrCode($content);
        $writer = new PngWriter;
        $result = $writer->write($qrCode);

        return $result->getDataUri();
    }

    public function renderInvoice(SalesInvoice $invoice, ?string $qrUrl = null): string
    {
        return $this->renderDocument(app(DocumentDataBuilder::class)->build($invoice, forPdf: true), $qrUrl);
    }

    public function renderQuotation(Quotation $quotation, ?string $qrUrl = null): string
    {
        return $this->renderDocument(app(DocumentDataBuilder::class)->build($quotation, forPdf: true), $qrUrl);
    }

    public function renderReturn(SalesReturn $return, ?string $qrUrl = null): string
    {
        return $this->renderDocument(app(DocumentDataBuilder::class)->build($return, forPdf: true), $qrUrl);
    }

    public function renderPayment(CustomerPayment $payment): string
    {
        return $this->renderDocument(app(DocumentDataBuilder::class)->build($payment, forPdf: true));
    }

    /** @param array<string, mixed> $statementData */
    public function renderStatement(array $statementData): string
    {
        return $this->renderDocument(app(DocumentDataBuilder::class)->statement($statementData));
    }

    public function renderDocument(DocumentData $data, ?string $qrUrl = null, bool $guest = false): string
    {
        if ($guest) {
            $entries = 0;
            foreach ($data->statement['currencies'] ?? [] as $group) {
                $entries += count($group['entries'] ?? []);
            }
            if (count($data->lines) > 100 || $entries > 250) {
                throw new InvalidArgumentException('Public PDF exceeds the guest preparation budget.');
            }
        }
        $html = app(DocumentRenderer::class)->html($data, $qrUrl && ($data->presentation['show_qr'] ?? false) ? $this->generateQrDataUri($qrUrl) : null);

        return $this->renderHtml($html, __('documents.'.$data->type, [], $data->locale).' '.($data->document['number'] ?? ''), $data->locale === 'ar', $guest);
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
    protected function renderHtml(string $html, string $title, bool $isRtl = true, bool $guest = false): string
    {
        if (strlen($html) > DocumentRenderLimits::MAX_PDF_BYTES
            || preg_match('/(?:src|href)\s*=\s*["\']\s*(?:https?:|file:|ftp:|\/\/)/i', $html)
            || preg_match('/(?:@import|url\s*\()/i', $html)) {
            throw new InvalidArgumentException('PDF requires bounded HTML and embedded approved assets.');
        }
        $started = hrtime(true);
        $parent = storage_path('app/private/document-render');
        $tempDir = $parent.DIRECTORY_SEPARATOR.Str::uuid()->toString();
        File::makeDirectory($tempDir, 0755, true);

        try {
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'default_font' => 'dejavusans',
                'margin_left' => 12,
                'margin_right' => 12,
                'margin_top' => 20,
                'margin_bottom' => 20,
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'tempDir' => $tempDir,
            ]);

            $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $direction = $isRtl ? 'rtl' : 'ltr';
            $mpdf->SetTitle($title);
            $mpdf->SetDirectionality($direction);
            $mpdf->SetHTMLHeader('<div dir="'.$direction.'" style="font-size:9pt;border-bottom:1px solid #dce3ec;">'.$safeTitle.'</div>');
            $mpdf->SetHTMLFooter('<div style="text-align:center;font-size:9pt;direction:ltr;">{PAGENO} / {nbpg}</div>');
            $mpdf->WriteHTML($html);
            if ($mpdf->page > ($guest ? 20 : DocumentRenderLimits::MAX_PAGES)) {
                throw new InvalidArgumentException('PDF exceeds the supported page limit.');
            }
            $bytes = $mpdf->Output('', 'S');
            if (strlen($bytes) > ($guest ? 4 * 1024 * 1024 : DocumentRenderLimits::MAX_PDF_BYTES)
                || hrtime(true) - $started > ($guest ? 10 : DocumentRenderLimits::MAX_SECONDS) * 1_000_000_000) {
                throw new InvalidArgumentException('PDF exceeds the measured delivery budget.');
            }

            return $bytes;
        } finally {
            // This request owns only its UUID child, never another render's cache.
            $root = realpath($parent);
            $resolved = realpath($tempDir);
            if ($root !== false && $resolved !== false && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                File::deleteDirectory($resolved);
            }
        }
    }
}
