<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\Sales\DocumentRenderLimits;
use App\Services\Sales\PdfRendererService;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mpdf\Barcode;
use Mpdf\Mpdf;

class BarcodeLabelService
{
    public const int MAX_TOTAL_LABELS = 500;

    public const int MAX_DISTINCT_BARCODES = 100;

    public const array SUPPORTED_PRESETS = [
        'a4-3x8' => [
            'columns' => 3,
            'rows' => 8,
            'per_page' => 24,
            'cell_width_mm' => 70,
            'cell_height_mm' => 36,
        ],
        'a4-2x7' => [
            'columns' => 2,
            'rows' => 7,
            'per_page' => 14,
            'cell_width_mm' => 100,
            'cell_height_mm' => 41,
        ],
    ];

    /**
     * Prepare validated barcode label items for rendering.
     *
     * @param  array<array-key,mixed>  $quantities  Maps ProductBarcode id => positive count; validate untrusted input.
     * @return array{labels: list<array{name: string, sku: string, unit: string, conversion: string, code: string, type: string, mpdf_type: string, svg: string}>, locale: string, preset: string}
     */
    public function prepare(array $quantities, string $locale = 'ar', string $preset = 'a4-3x8'): array
    {
        if (empty($quantities)) {
            throw new InvalidArgumentException('Quantities cannot be empty.');
        }

        if (count($quantities) > self::MAX_DISTINCT_BARCODES) {
            throw new InvalidArgumentException('Exceeds maximum of 100 distinct barcodes.');
        }

        if (! array_key_exists($preset, self::SUPPORTED_PRESETS)) {
            throw new InvalidArgumentException("Unsupported preset [{$preset}].");
        }

        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException('Unsupported label locale.');
        }

        $totalCount = 0;
        /** @var array<int, int> $validatedQuantities */
        $validatedQuantities = [];
        foreach ($quantities as $barcodeId => $count) {
            if (! is_int($barcodeId) || $barcodeId <= 0) {
                throw new InvalidArgumentException('Barcode ID must be a positive integer.');
            }

            if (! is_int($count) || $count <= 0) {
                throw new InvalidArgumentException("Quantity for barcode [{$barcodeId}] must be a positive integer.");
            }
            if ($count > self::MAX_TOTAL_LABELS) {
                throw new InvalidArgumentException('Exceeds maximum limit of 500 total labels.');
            }

            $totalCount += $count;
            $validatedQuantities[$barcodeId] = $count;
        }

        if ($totalCount > self::MAX_TOTAL_LABELS) {
            throw new InvalidArgumentException('Exceeds maximum limit of 500 total labels.');
        }

        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || ! auth()->check()) {
            throw new AuthorizationException('An active company and authenticated actor are required.');
        }
        $companyId = (int) $context->companyId();
        /** @var User $actor */
        $actor = auth()->user();

        // Fresh active company membership and source authority check inside read transaction
        return DB::transaction(function () use ($companyId, $actor, $validatedQuantities, $locale, $preset) {
            $guard = app(SalesActorGuard::class);
            $guard->lockAndAuthorize($companyId, $actor, 'inventory.barcode_labels.print');
            if (! $actor->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage'])) {
                throw new AuthorizationException('Product selection authority is required.');
            }
            $guard->lockAndAuthorize($companyId, $actor, 'inventory.barcode_labels.print');

            $barcodeIds = array_keys($validatedQuantities);
            $barcodes = ProductBarcode::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $barcodeIds)
                ->get()
                ->keyBy('id');

            if ($barcodes->count() !== count($barcodeIds)) {
                throw new InvalidArgumentException('One or more barcodes are unknown, foreign, or inactive.');
            }

            $productIds = $barcodes->pluck('product_id')->unique()->all();
            $products = Product::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $productIds)
                ->get(['id', 'company_id', 'name_ar', 'name_en', 'sku', 'base_unit_id', 'active', 'deleted_at'])
                ->keyBy('id');

            $unitIds = $barcodes->pluck('unit_id')->filter()->merge($products->pluck('base_unit_id'))->unique()->all();
            $allUnits = Unit::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $unitIds)
                ->get(['id', 'company_id', 'name_ar', 'name_en', 'active'])
                ->keyBy('id');

            $allProductUnits = ProductUnit::query()
                ->where('company_id', $companyId)
                ->whereIn('product_id', $productIds)
                ->whereIn('unit_id', $unitIds)
                ->get(['id', 'company_id', 'product_id', 'unit_id', 'conversion_to_base', 'active']);

            $labels = [];

            foreach ($validatedQuantities as $barcodeId => $count) {
                /** @var ProductBarcode $barcodeModel */
                $barcodeModel = $barcodes->get($barcodeId);
                if ((int) $barcodeModel->company_id !== $companyId) {
                    throw new InvalidArgumentException("Barcode [{$barcodeId}] belongs to a foreign company.");
                }

                /** @var Product|null $product */
                $product = $products->get($barcodeModel->product_id);
                if ($product === null || ! $product->active || $product->deleted_at !== null) {
                    throw new InvalidArgumentException("Product for barcode [{$barcodeId}] is inactive, deleted, or ineligible.");
                }

                // Resolve Unit: unit_id is Unit ID, never ProductUnit ID. Null unit resolves stored base_unit_id.
                $resolvedUnitId = $barcodeModel->unit_id !== null ? (int) $barcodeModel->unit_id : (int) $product->base_unit_id;
                if ($resolvedUnitId <= 0) {
                    throw new InvalidArgumentException("Product for barcode [{$barcodeId}] has no configured unit.");
                }

                /** @var Unit|null $unit */
                $unit = $allUnits->get($resolvedUnitId);
                if ($unit === null || ! $unit->active) {
                    throw new InvalidArgumentException("Unit [{$resolvedUnitId}] for barcode [{$barcodeId}] is inactive or missing.");
                }

                /** @var ProductUnit|null $productUnit */
                $productUnit = $allProductUnits->first(
                    fn (ProductUnit $pu) => (int) $pu->product_id === (int) $product->id && (int) $pu->unit_id === $resolvedUnitId
                );
                if ($productUnit === null || ! $productUnit->active) {
                    throw new InvalidArgumentException("ProductUnit configuration for unit [{$resolvedUnitId}] is inactive or ineligible.");
                }

                // Preserve stored conversion_to_base exact string; no float calculations
                $conversionToBase = (string) $productUnit->conversion_to_base;

                // Validate barcode symbology, check digits and printable ASCII constraints
                $validatedBarcode = $this->validateAndNormalizeBarcode(
                    (string) $barcodeModel->barcode,
                    $barcodeModel->type
                );

                // Localized captions (RTL / LTR safe)
                $productName = $locale === 'ar'
                    ? (trim((string) $product->name_ar) ?: (string) $product->name_en)
                    : (trim((string) $product->name_en) ?: (string) $product->name_ar);

                $unitName = $locale === 'ar'
                    ? (trim((string) $unit->name_ar) ?: (string) $unit->name_en)
                    : (trim((string) $unit->name_en) ?: (string) $unit->name_ar);

                // Conversion caption meaning: carton barcode vs piece label
                $isBaseUnit = (int) $resolvedUnitId === (int) $product->base_unit_id;
                if (! $isBaseUnit && $conversionToBase !== '1' && $conversionToBase !== '1.000000') {
                    /** @var Unit|null $baseUnit */
                    $baseUnit = $allUnits->get((int) $product->base_unit_id);
                    $baseUnitName = $baseUnit ? ($locale === 'ar' ? ($baseUnit->name_ar ?: $baseUnit->name_en) : ($baseUnit->name_en ?: $baseUnit->name_ar)) : '';
                    $unitCaption = $unitName.' (×'.$conversionToBase.($baseUnitName !== '' ? ' '.$baseUnitName : '').')';
                } else {
                    $unitCaption = $unitName;
                }

                $encodedCode = $validatedBarcode['encoded_code'] ?? $validatedBarcode['code'];
                $svg = $this->generateBarcodeSvg($encodedCode, $validatedBarcode['mpdf_type']);
                $bars = (new Barcode)->getBarcodeArray($encodedCode, $validatedBarcode['mpdf_type']);
                $maxWidth = $preset === 'a4-2x7' ? 88 : 56;
                if (($bars['maxw'] + 20) * 0.25 > $maxWidth) {
                    throw new InvalidArgumentException('Barcode is too wide for this label preset. Use a larger label or a shorter stored internal code.');
                }
                foreach ([$productName, $unitCaption, (string) $product->sku] as $caption) {
                    if (mb_strlen($caption) > 160) {
                        throw new InvalidArgumentException('Label caption exceeds the supported length.');
                    }
                }

                $labelData = [
                    'name' => $productName,
                    'sku' => (string) $product->sku,
                    'unit' => $unitCaption,
                    'conversion' => $conversionToBase,
                    'code' => $validatedBarcode['code'],
                    'type' => $validatedBarcode['type'],
                    'mpdf_type' => $validatedBarcode['mpdf_type'],
                    'encoded_code' => $encodedCode,
                    'svg' => $svg,
                ];

                for ($i = 0; $i < $count; $i++) {
                    $labels[] = $labelData;
                }
            }

            return [
                'labels' => $labels,
                'locale' => $locale,
                'preset' => $preset,
            ];
        });
    }

    /**
     * Validate barcode value and symbology type.
     *
     * @return array{code: string, type: string, mpdf_type: string, encoded_code?: string}
     */
    public function validateAndNormalizeBarcode(string $code, ?string $rawType): array
    {
        // Preserve the exact stored symbol, including meaningful ASCII spaces.
        $len = strlen($code);

        if ($len === 0 || $len > 80) {
            throw new InvalidArgumentException("Barcode code length must be between 1 and 80 characters [{$code}].");
        }

        $typeNormalized = strtoupper(trim((string) $rawType));

        // Generic internal ASCII code defaults to Code128 (C128)
        if ($typeNormalized === '' || $typeNormalized === 'STANDARD' || $typeNormalized === 'C128' || $typeNormalized === 'CODE128' || $typeNormalized === 'CODE-128') {
            if (! preg_match('/^[\x20-\x7E]+$/', $code)) {
                throw new InvalidArgumentException("Code128 barcode [{$code}] contains invalid or non-printable ASCII characters.");
            }

            return [
                'code' => $code,
                'type' => 'C128',
                'mpdf_type' => 'C128B',
            ];
        }

        if ($typeNormalized === 'EAN13' || $typeNormalized === 'EAN-13') {
            if (! preg_match('/^\d{13}$/', $code)) {
                throw new InvalidArgumentException("EAN-13 barcode must consist of exactly 13 digits [{$code}].");
            }
            if (! $this->validateEan13CheckDigit($code)) {
                throw new InvalidArgumentException("EAN-13 barcode [{$code}] has an invalid check digit.");
            }

            return [
                'code' => $code,
                'type' => 'EAN13',
                'mpdf_type' => 'EAN13',
            ];
        }

        if ($typeNormalized === 'EAN8' || $typeNormalized === 'EAN-8') {
            if (! preg_match('/^\d{8}$/', $code)) {
                throw new InvalidArgumentException("EAN-8 barcode must consist of exactly 8 digits [{$code}].");
            }
            if (! $this->validateEan8CheckDigit($code)) {
                throw new InvalidArgumentException("EAN-8 barcode [{$code}] has an invalid check digit.");
            }

            return [
                'code' => $code,
                'type' => 'EAN8',
                'mpdf_type' => 'EAN8',
            ];
        }

        if ($typeNormalized === 'UPCA' || $typeNormalized === 'UPC-A') {
            if (! preg_match('/^\d{12}$/', $code)) {
                throw new InvalidArgumentException("UPC-A barcode must consist of exactly 12 digits [{$code}].");
            }
            if (! $this->validateUpcACheckDigit($code)) {
                throw new InvalidArgumentException("UPC-A barcode [{$code}] has an invalid check digit.");
            }

            return [
                'code' => $code,
                'type' => 'UPCA',
                'mpdf_type' => 'UPCA',
            ];
        }

        if ($typeNormalized === 'UPCE' || $typeNormalized === 'UPC-E') {
            if (! preg_match('/^[01][0-9]{7}$/D', $code)) {
                throw new InvalidArgumentException('UPC-E requires number system, six digits and checksum.');
            }
            $digits = substr($code, 1, 6);
            $last = $digits[5];
            $expanded = match ($last) {
                '0','1','2' => $code[0].substr($digits, 0, 2).$last.'0000'.substr($digits, 2, 3),
                '3' => $code[0].substr($digits, 0, 3).'00000'.substr($digits, 3, 2),
                '4' => $code[0].substr($digits, 0, 4).'00000'.$digits[4],
                default => $code[0].substr($digits, 0, 5).'0000'.$last,
            };
            if (! $this->validateUpcACheckDigit($expanded.$code[7])) {
                throw new InvalidArgumentException('Invalid UPC-E check digit.');
            }

            // mPDF expects the expanded UPC-A value when generating a UPC-E symbol.
            return ['code' => $code, 'type' => 'UPCE', 'mpdf_type' => 'UPCE', 'encoded_code' => $expanded.$code[7]];
        }

        throw new InvalidArgumentException("Unsupported barcode symbology [{$rawType}].");
    }

    /**
     * EAN-13 check digit validator (modulo 10 weighting 1, 3).
     */
    public function validateEan13CheckDigit(string $code): bool
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $d = (int) $code[$i];
            $sum += ($i % 2 === 0) ? $d : ($d * 3);
        }
        $check = (10 - ($sum % 10)) % 10;

        return $check === (int) $code[12];
    }

    /**
     * EAN-8 check digit validator (modulo 10 weighting 3, 1).
     */
    public function validateEan8CheckDigit(string $code): bool
    {
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = (int) $code[$i];
            $sum += ($i % 2 === 0) ? ($d * 3) : $d;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $check === (int) $code[7];
    }

    /**
     * UPC-A check digit validator (modulo 10 weighting 3, 1).
     */
    public function validateUpcACheckDigit(string $code): bool
    {
        $sum = 0;
        for ($i = 0; $i < 11; $i++) {
            $d = (int) $code[$i];
            $sum += ($i % 2 === 0) ? ($d * 3) : $d;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $check === (int) $code[11];
    }

    /**
     * Generate safe vector SVG barcode bars using bundled mPDF Barcode generator.
     */
    public function generateBarcodeSvg(string $code, string $mpdfType, int $height = 36): string
    {
        try {
            $barcode = new Barcode;
            $arr = $barcode->getBarcodeArray($code, $mpdfType);
            if ($arr === false || ! isset($arr['bcode'], $arr['maxw']) || (int) $arr['maxw'] <= 0) {
                throw new InvalidArgumentException('Barcode could not be encoded.');
            }
            $totalWidth = (int) $arr['maxw'] + 20;
            $x = 10;
            $rects = '';
            foreach ($arr['bcode'] as $bar) {
                $w = (int) $bar['w'];
                if (! empty($bar['t'])) {
                    $rects .= "<rect x=\"{$x}\" y=\"0\" width=\"{$w}\" height=\"{$height}\" fill=\"#000000\" />";
                }
                $x += $w;
            }

            return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$totalWidth} {$height}\" preserveAspectRatio=\"none\" style=\"width:100%;height:{$height}px;display:block;\">{$rects}</svg>";
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Barcode could not be encoded.', previous: $e);
        }
    }

    /**
     * Render HTML print preview.
     *
     * @param  array<string, mixed>  $prepared
     */
    public function html(array $prepared, bool $printControls = false): string
    {
        return view('pdf.barcode-labels', [
            'prepared' => $prepared,
            'forPdf' => false,
            'printControls' => $printControls,
        ])->render();
    }

    /**
     * Render PDF binary string via dedicated subclass of PdfRendererService.
     *
     * @param  array<string, mixed>  $prepared
     */
    public function pdf(array $prepared): string
    {
        $html = view('pdf.barcode-label-sheet', [
            'prepared' => $prepared,
            'forPdf' => true,
            'printControls' => false,
        ])->render();

        return app(BarcodeLabelPdfRenderer::class)->renderLabelsPdf(
            $html,
            'Barcode Labels',
            ($prepared['locale'] ?? 'ar') === 'ar'
        );
    }
}

/**
 * Dedicated subclass of PdfRendererService for accurate label sheet rendering.
 * Inherits private temp directory cleanup and memory/time delivery budgets.
 */
class BarcodeLabelPdfRenderer extends PdfRendererService
{
    public function renderLabelsPdf(string $html, string $title = 'Barcode Labels', bool $isRtl = true): string
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
                'margin_left' => 0,
                'margin_right' => 0,
                'margin_top' => 0,
                'margin_bottom' => 0,
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'tempDir' => $tempDir,
            ]);

            $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $direction = $isRtl ? 'rtl' : 'ltr';
            $mpdf->SetTitle($safeTitle);
            $mpdf->SetDirectionality($direction);
            $mpdf->WriteHTML($html);

            if ($mpdf->page > DocumentRenderLimits::MAX_PAGES) {
                throw new InvalidArgumentException('PDF exceeds the supported page limit.');
            }

            $bytes = $mpdf->Output('', 'S');
            if (strlen($bytes) > DocumentRenderLimits::MAX_PDF_BYTES
                || hrtime(true) - $started > DocumentRenderLimits::MAX_SECONDS * 1_000_000_000) {
                throw new InvalidArgumentException('PDF exceeds the measured delivery budget.');
            }

            return $bytes;
        } finally {
            $root = realpath($parent);
            $resolved = realpath($tempDir);
            if ($root !== false && $resolved !== false && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                File::deleteDirectory($resolved);
            }
        }
    }
}
