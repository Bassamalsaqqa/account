<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Company\CreateCompanyAction;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\BarcodeLabelService;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\Feature\Phase5E\Phase5ETestCase;

class BarcodeCertificationTest extends Phase5ETestCase
{
    protected BarcodeLabelService $service;

    protected Unit $cartonUnit;

    protected ProductUnit $cartonProductUnit;

    protected ProductBarcode $pieceBarcode;

    protected ProductBarcode $cartonBarcode;

    protected ProductBarcode $ean13Barcode;

    protected ProductBarcode $ean8Barcode;

    protected ProductBarcode $upcaBarcode;

    protected ProductBarcode $upceBarcode;

    /** @var array<string, ProductBarcode> */
    protected array $upceBranchBarcodes = [];

    protected string $evidenceDir;

    protected string $pythonPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pythonPath = getenv('PHASE10_PYTHON_BINARY') ?: 'python';

        $this->service = app(BarcodeLabelService::class);
        $this->evidenceDir = base_path('.ai/phase10-barcode');

        if (! File::isDirectory($this->evidenceDir)) {
            File::makeDirectory($this->evidenceDir, 0755, true);
        }

        // Setup Carton unit
        $this->cartonUnit = Unit::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'carton'],
            [
                'name_ar' => 'كرتونة',
                'name_en' => 'Carton',
                'symbol_ar' => 'كرتون',
                'symbol_en' => 'ctn',
                'allows_fraction' => false,
                'decimal_places' => 0,
                'active' => true,
            ]
        );

        // Carton ProductUnit with exact decimal conversion_to_base = '12'
        $this->cartonProductUnit = ProductUnit::firstOrCreate(
            [
                'company_id' => $this->company->id,
                'product_id' => $this->product->id,
                'unit_id' => $this->cartonUnit->id,
            ],
            [
                'conversion_to_base' => '12',
                'is_base' => false,
                'is_default_purchase' => false,
                'is_default_sale' => false,
                'active' => true,
            ]
        );
        $this->cartonProductUnit->refresh();
        $this->unit->refresh();

        // 1. Code 128: Piece barcode (base unit 'piece', conversion '1')
        $this->pieceBarcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->product->base_unit_id,
            'barcode' => 'PIECE-SKU-001',
            'type' => 'C128',
            'is_primary' => true,
        ]);

        // 2. Code 128: Carton barcode (carton unit 'carton', conversion '12')
        $this->cartonBarcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->cartonUnit->id,
            'barcode' => 'CARTON-SKU-001',
            'type' => 'C128',
            'is_primary' => false,
        ]);

        // 3. EAN-13 barcode
        $this->ean13Barcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->product->base_unit_id,
            'barcode' => '9780201379624',
            'type' => 'EAN13',
            'is_primary' => false,
        ]);

        // 4. EAN-8 barcode
        $this->ean8Barcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->product->base_unit_id,
            'barcode' => '96385074',
            'type' => 'EAN8',
            'is_primary' => false,
        ]);

        // 5. UPC-A barcode
        $this->upcaBarcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->product->base_unit_id,
            'barcode' => '036000291452',
            'type' => 'UPCA',
            'is_primary' => false,
        ]);

        // 6. UPC-E barcode (suffix branch 1: 04252614 -> 042100005264)
        $this->upceBarcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->product->base_unit_id,
            'barcode' => '04252614',
            'type' => 'UPCE',
            'is_primary' => false,
        ]);

        // UPC-E suffix branch and number system fixtures:
        // Branch 0 (last '0', NS 0): 01234505 -> 012000003455
        // Branch 1 (last '1', NS 0): 04252614 -> 042100005264 (upceBarcode)
        // Branch 2 (last '2', NS 0): 01234523 -> 012200003453
        // Branch 3 (last '3', NS 0): 01234531 -> 012300000451
        // Branch 4 (last '4', NS 0): 01234543 -> 012340000053
        // Branch 5-9 (last '5'..'9', NS 0):
        //   '5': 01234558 -> 012345000058
        //   '6': 01234565 -> 012345000065
        //   '7': 01234572 -> 012345000072
        //   '8': 01234589 -> 012345000089
        //   '9': 01234596 -> 012345000096
        // Number System 1 branches:
        //   NS 1 Branch 0: 11234502 -> 112000003452
        //   NS 1 Branch 1: 14252611 -> 142100005261
        //   NS 1 Branch 3: 11234538 -> 112300000458
        //   NS 1 Branch 4: 11234540 -> 112340000050
        //   NS 1 Branch 5: 11234555 -> 112345000055
        $branchCodes = [
            'branch_0' => '01234505',
            'branch_1' => '04252614',
            'branch_2' => '01234523',
            'branch_3' => '01234531',
            'branch_4' => '01234543',
            'branch_5' => '01234558',
            'branch_6' => '01234565',
            'branch_7' => '01234572',
            'branch_8' => '01234589',
            'branch_9' => '01234596',
            'ns1_branch_0' => '11234502',
            'ns1_branch_1' => '14252611',
            'ns1_branch_3' => '11234538',
            'ns1_branch_4' => '11234540',
            'ns1_branch_5' => '11234555',
        ];

        foreach ($branchCodes as $key => $code) {
            if ($code === $this->upceBarcode->barcode) {
                $this->upceBranchBarcodes[$key] = $this->upceBarcode;

                continue;
            }

            $this->upceBranchBarcodes[$key] = ProductBarcode::create([
                'company_id' => $this->company->id,
                'product_id' => $this->product->id,
                'unit_id' => $this->product->base_unit_id,
                'barcode' => $code,
                'type' => 'UPCE',
                'is_primary' => false,
            ]);
        }
    }

    /**
     * Helper to execute python barcode decoder with manifest and output.
     *
     * @return list<array<string, mixed>>
     */
    protected function runDecoder(string $manifestPath, string $outputPath): array
    {
        $scriptPath = base_path('tests/Support/Phase10/decode-barcodes.py');
        $this->assertFileExists($scriptPath);

        $process = new Process(
            [$this->pythonPath, $scriptPath, '--manifest', $manifestPath, '--output', $outputPath],
            base_path()
        );
        $process->setTimeout(60)->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            'Decoder failed (exit '.$process->getExitCode().'): '.$process->getErrorOutput().PHP_EOL.$process->getOutput()
        );

        $this->assertFileExists($outputPath);
        $decoded = json_decode(File::get($outputPath), true);
        $this->assertIsArray($decoded);

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    public function test_arabic_pdf_sheet_renders_and_decodes_all_five_symbologies(): void
    {
        // 24 total labels on A4 3x8 sheet in Arabic
        // Piece C128 x 6, Carton C128 (x12) x 6, EAN-13 x 4, EAN-8 x 3, UPC-A x 3, UPC-E x 2 = 24
        $quantities = [
            $this->pieceBarcode->id => 6,
            $this->cartonBarcode->id => 6,
            $this->ean13Barcode->id => 4,
            $this->ean8Barcode->id => 3,
            $this->upcaBarcode->id => 3,
            $this->upceBarcode->id => 2,
        ];

        $prepared = $this->service->prepare($quantities, locale: 'ar', preset: 'a4-3x8');

        $this->assertSame('ar', $prepared['locale']);
        $this->assertSame('a4-3x8', $prepared['preset']);
        $this->assertCount(24, $prepared['labels']);

        // Check unit and caption resolutions
        $this->assertSame('PIECE-SKU-001', $prepared['labels'][0]['code']);
        $this->assertSame((string) $this->unit->conversion_to_base, (string) $prepared['labels'][0]['conversion']);
        $this->assertStringNotContainsString('×12', $prepared['labels'][0]['unit']);

        $this->assertSame('CARTON-SKU-001', $prepared['labels'][6]['code']);
        $this->assertSame((string) $this->cartonProductUnit->conversion_to_base, (string) $prepared['labels'][6]['conversion']);
        $this->assertStringContainsString('كرتونة', $prepared['labels'][6]['unit']);
        $this->assertStringContainsString('×12', $prepared['labels'][6]['unit']);

        // Check UPC-E label caption vs encoded code
        $this->assertSame('04252614', $prepared['labels'][22]['code']);
        $this->assertSame('042100005264', $prepared['labels'][22]['encoded_code']);

        // Render PDF binary
        $pdfBytes = $this->service->pdf($prepared);
        $this->assertNotEmpty($pdfBytes);
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
        $this->assertGreaterThan(5000, strlen($pdfBytes));
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes));

        $pdfPath = $this->evidenceDir.'/labels-ar.pdf';
        File::put($pdfPath, $pdfBytes);
        $this->assertFileExists($pdfPath);

        // Build expected labels list for decoder verification
        $expectedLabels = [];
        foreach ($prepared['labels'] as $lbl) {
            $expectedFmt = match ($lbl['type']) {
                'C128' => 'Code 128',
                'EAN13' => 'EAN-13',
                'EAN8' => 'EAN-8',
                'UPCA' => 'UPC-A',
                'UPCE' => 'UPC-E',
                default => $lbl['type'],
            };
            $expectedText = match ($lbl['type']) {
                'UPCE' => $lbl['encoded_code'],
                default => $lbl['code'],
            };
            $expectedLabels[] = [
                'expected_format' => $expectedFmt,
                'expected_text' => $expectedText,
                'stored_code' => $lbl['code'],
                'expected_expansion' => $lbl['encoded_code'] ?? null,
            ];
        }

        $manifest = [
            [
                'file' => $pdfPath,
                'preset' => 'a4-3x8',
                'locale' => 'ar',
                'rtl' => true,
                'labels' => $expectedLabels,
            ],
        ];

        $manifestPath = $this->evidenceDir.'/manifest-ar.json';
        $outputPath = $this->evidenceDir.'/decode-evidence-ar.json';
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $results = $this->runDecoder($manifestPath, $outputPath);

        $this->assertCount(1, $results);
        $res = $results[0];
        $this->assertTrue($res['passed'], 'Arabic sheet decoding did not pass completely.');
        $this->assertSame(24, $res['total_decoded']);
        $this->assertSame(24, $res['cells_checked']);

        // Verify each symbology decoded correctly
        $cells = $res['cells'];
        // Piece C128 (cells 0..5)
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame('Code 128', $cells[$i]['symbols'][0]['canonical_format']);
            $this->assertSame('PIECE-SKU-001', $cells[$i]['symbols'][0]['normalized_text']);
        }
        // Carton C128 (cells 6..11)
        for ($i = 6; $i < 12; $i++) {
            $this->assertSame('Code 128', $cells[$i]['symbols'][0]['canonical_format']);
            $this->assertSame('CARTON-SKU-001', $cells[$i]['symbols'][0]['normalized_text']);
        }
        // EAN-13 (cells 12..15)
        for ($i = 12; $i < 16; $i++) {
            $this->assertSame('EAN-13', $cells[$i]['symbols'][0]['canonical_format']);
            $this->assertSame('9780201379624', $cells[$i]['symbols'][0]['normalized_text']);
        }
        // EAN-8 (cells 16..18)
        for ($i = 16; $i < 19; $i++) {
            $this->assertSame('EAN-8', $cells[$i]['symbols'][0]['canonical_format']);
            $this->assertSame('96385074', $cells[$i]['symbols'][0]['normalized_text']);
        }
        // UPC-A (cells 19..21)
        for ($i = 19; $i < 22; $i++) {
            $this->assertSame('UPC-A', $cells[$i]['symbols'][0]['canonical_format']);
            $this->assertSame('036000291452', $cells[$i]['symbols'][0]['normalized_text']);
        }
        // UPC-E (cells 22..23): Scanner decodes UPC-E as 12-digit UPC-A canonical expansion
        for ($i = 22; $i < 24; $i++) {
            $this->assertSame('UPC-E', $cells[$i]['symbols'][0]['canonical_format']);
            $this->assertSame('042100005264', $cells[$i]['symbols'][0]['normalized_text']);
            $this->assertSame('04252614', $cells[$i]['expected']['stored_code']);
        }
    }

    public function test_english_pdf_sheet_renders_and_decodes_all_five_symbologies(): void
    {
        // 14 total labels on A4 2x7 sheet in English
        // Piece C128 x 3, Carton C128 (x12) x 3, EAN-13 x 3, EAN-8 x 2, UPC-A x 2, UPC-E x 1 = 14
        $quantities = [
            $this->pieceBarcode->id => 3,
            $this->cartonBarcode->id => 3,
            $this->ean13Barcode->id => 3,
            $this->ean8Barcode->id => 2,
            $this->upcaBarcode->id => 2,
            $this->upceBarcode->id => 1,
        ];

        $prepared = $this->service->prepare($quantities, locale: 'en', preset: 'a4-2x7');

        $this->assertSame('en', $prepared['locale']);
        $this->assertSame('a4-2x7', $prepared['preset']);
        $this->assertCount(14, $prepared['labels']);

        // Check English captions
        $this->assertSame('Food Item', $prepared['labels'][0]['name']);
        $this->assertStringContainsString('Carton', $prepared['labels'][3]['unit']);
        $this->assertStringContainsString('×12', $prepared['labels'][3]['unit']);

        // Render PDF binary
        $pdfBytes = $this->service->pdf($prepared);
        $this->assertNotEmpty($pdfBytes);
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
        $this->assertGreaterThan(5000, strlen($pdfBytes));
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes));

        $pdfPath = $this->evidenceDir.'/labels-en.pdf';
        File::put($pdfPath, $pdfBytes);
        $this->assertFileExists($pdfPath);

        // Build expected labels list
        $expectedLabels = [];
        foreach ($prepared['labels'] as $lbl) {
            $expectedFmt = match ($lbl['type']) {
                'C128' => 'Code 128',
                'EAN13' => 'EAN-13',
                'EAN8' => 'EAN-8',
                'UPCA' => 'UPC-A',
                'UPCE' => 'UPC-E',
                default => $lbl['type'],
            };
            $expectedText = match ($lbl['type']) {
                'UPCE' => $lbl['encoded_code'],
                default => $lbl['code'],
            };
            $expectedLabels[] = [
                'expected_format' => $expectedFmt,
                'expected_text' => $expectedText,
                'stored_code' => $lbl['code'],
                'expected_expansion' => $lbl['encoded_code'] ?? null,
            ];
        }

        $manifest = [
            [
                'file' => $pdfPath,
                'preset' => 'a4-2x7',
                'locale' => 'en',
                'rtl' => false,
                'labels' => $expectedLabels,
            ],
        ];

        $manifestPath = $this->evidenceDir.'/manifest-en.json';
        $outputPath = $this->evidenceDir.'/decode-evidence-en.json';
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $results = $this->runDecoder($manifestPath, $outputPath);

        $this->assertCount(1, $results);
        $res = $results[0];
        $this->assertTrue($res['passed'], 'English sheet decoding did not pass completely.');
        $this->assertSame(14, $res['total_decoded']);
        $this->assertSame(14, $res['cells_checked']);

        // Verify per-type decoding
        $cells = $res['cells'];
        // Piece C128 (cells 0..2)
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('Code 128', $cells[$i]['symbols'][0]['canonical_format']);
            $this->assertSame('PIECE-SKU-001', $cells[$i]['symbols'][0]['normalized_text']);
        }
        // Carton C128 (cells 3..5)
        for ($i = 3; $i < 6; $i++) {
            $this->assertSame('Code 128', $cells[$i]['symbols'][0]['canonical_format']);
            $this->assertSame('CARTON-SKU-001', $cells[$i]['symbols'][0]['normalized_text']);
        }
        // EAN-13 (cells 6..8)
        for ($i = 6; $i < 9; $i++) {
            $this->assertSame('EAN-13', $cells[$i]['symbols'][0]['canonical_format']);
            $this->assertSame('9780201379624', $cells[$i]['symbols'][0]['normalized_text']);
        }
        // EAN-8 (cells 9..10)
        for ($i = 9; $i < 11; $i++) {
            $this->assertSame('EAN-8', $cells[$i]['symbols'][0]['canonical_format']);
            $this->assertSame('96385074', $cells[$i]['symbols'][0]['normalized_text']);
        }
        // UPC-A (cells 11..12)
        for ($i = 11; $i < 13; $i++) {
            $this->assertSame('UPC-A', $cells[$i]['symbols'][0]['canonical_format']);
            $this->assertSame('036000291452', $cells[$i]['symbols'][0]['normalized_text']);
        }
        // UPC-E (cell 13)
        $this->assertSame('UPC-E', $cells[13]['symbols'][0]['canonical_format']);
        $this->assertSame('042100005264', $cells[13]['symbols'][0]['normalized_text']);
        $this->assertSame('04252614', $cells[13]['expected']['stored_code']);
    }

    public function test_upce_suffix_expansion_branches_and_number_systems_certified(): void
    {
        // Certify all 15 UPC-E suffix expansion branches and number systems
        $expectedExpansions = [
            'branch_0' => ['code' => '01234505', 'expanded' => '012000003455', 'last' => '0', 'ns' => '0'],
            'branch_1' => ['code' => '04252614', 'expanded' => '042100005264', 'last' => '1', 'ns' => '0'],
            'branch_2' => ['code' => '01234523', 'expanded' => '012200003453', 'last' => '2', 'ns' => '0'],
            'branch_3' => ['code' => '01234531', 'expanded' => '012300000451', 'last' => '3', 'ns' => '0'],
            'branch_4' => ['code' => '01234543', 'expanded' => '012340000053', 'last' => '4', 'ns' => '0'],
            'branch_5' => ['code' => '01234558', 'expanded' => '012345000058', 'last' => '5', 'ns' => '0'],
            'branch_6' => ['code' => '01234565', 'expanded' => '012345000065', 'last' => '6', 'ns' => '0'],
            'branch_7' => ['code' => '01234572', 'expanded' => '012345000072', 'last' => '7', 'ns' => '0'],
            'branch_8' => ['code' => '01234589', 'expanded' => '012345000089', 'last' => '8', 'ns' => '0'],
            'branch_9' => ['code' => '01234596', 'expanded' => '012345000096', 'last' => '9', 'ns' => '0'],
            'ns1_branch_0' => ['code' => '11234502', 'expanded' => '112000003452', 'last' => '0', 'ns' => '1'],
            'ns1_branch_1' => ['code' => '14252611', 'expanded' => '142100005261', 'last' => '1', 'ns' => '1'],
            'ns1_branch_3' => ['code' => '11234538', 'expanded' => '112300000458', 'last' => '3', 'ns' => '1'],
            'ns1_branch_4' => ['code' => '11234540', 'expanded' => '112340000050', 'last' => '4', 'ns' => '1'],
            'ns1_branch_5' => ['code' => '11234555', 'expanded' => '112345000055', 'last' => '5', 'ns' => '1'],
        ];

        // 1. Verify individual service validation & normalization
        foreach ($expectedExpansions as $key => $info) {
            $norm = $this->service->validateAndNormalizeBarcode($info['code'], 'UPCE');
            $this->assertSame($info['code'], $norm['code']);
            $this->assertSame('UPCE', $norm['type']);
            $this->assertSame('UPCE', $norm['mpdf_type']);
            $this->assertSame($info['expanded'], $norm['encoded_code']);
        }

        // 2. Prepare 1 label of each branch into single sheet (15 labels total on A4 3x8)
        $quantities = [];
        foreach ($expectedExpansions as $key => $info) {
            $barcodeModel = $this->upceBranchBarcodes[$key];
            $quantities[$barcodeModel->id] = 1;
        }

        $prepared = $this->service->prepare($quantities, locale: 'en', preset: 'a4-3x8');
        $this->assertCount(15, $prepared['labels']);

        // Verify stored code vs encoded code in prepared payload
        $i = 0;
        foreach ($expectedExpansions as $key => $info) {
            $this->assertSame($info['code'], $prepared['labels'][$i]['code']);
            $this->assertSame($info['expanded'], $prepared['labels'][$i]['encoded_code']);
            $i++;
        }

        // 3. Render PDF binary and save to evidence dir
        $pdfBytes = $this->service->pdf($prepared);
        $this->assertNotEmpty($pdfBytes);
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes));

        $pdfPath = $this->evidenceDir.'/labels-upce-branches.pdf';
        File::put($pdfPath, $pdfBytes);
        $this->assertFileExists($pdfPath);

        // 4. Build manifest and run decoder
        $expectedLabels = [];
        foreach ($prepared['labels'] as $lbl) {
            $expectedLabels[] = [
                'expected_format' => 'UPC-E',
                'expected_text' => $lbl['encoded_code'],
                'stored_code' => $lbl['code'],
                'expected_expansion' => $lbl['encoded_code'],
            ];
        }

        $manifest = [
            [
                'file' => $pdfPath,
                'preset' => 'a4-3x8',
                'locale' => 'en',
                'rtl' => false,
                'labels' => $expectedLabels,
                'expected_count' => 15,
            ],
        ];

        $manifestPath = $this->evidenceDir.'/manifest-upce-branches.json';
        $outputPath = $this->evidenceDir.'/decode-evidence-upce-branches.json';
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $results = $this->runDecoder($manifestPath, $outputPath);

        $this->assertCount(1, $results);
        $res = $results[0];
        $this->assertTrue($res['passed'], 'UPC-E branches decoding did not pass completely.');
        $this->assertSame(15, $res['total_decoded']);
        $this->assertSame(15, $res['cells_checked']);

        // Verify decoded values match canonical UPC-A expansion for every branch
        $cells = $res['cells'];
        $idx = 0;
        foreach ($expectedExpansions as $key => $info) {
            $cell = $cells[$idx];
            $this->assertSame('UPC-E', $cell['symbols'][0]['canonical_format']);
            $this->assertSame(
                $info['expanded'],
                $cell['symbols'][0]['normalized_text'],
                "Mismatch on UPC-E branch {$key} (stored {$info['code']})"
            );
            $this->assertSame($info['code'], $cell['expected']['stored_code']);
            $idx++;
        }
    }

    public function test_product_and_unit_identity_and_conversion_integrity(): void
    {
        // 1. Distinct piece and carton barcode preparation
        $prepared = $this->service->prepare([
            $this->pieceBarcode->id => 1,
            $this->cartonBarcode->id => 1,
        ], locale: 'ar', preset: 'a4-3x8');

        $this->assertCount(2, $prepared['labels']);
        $pieceLabel = $prepared['labels'][0];
        $cartonLabel = $prepared['labels'][1];

        // Piece resolves base unit conversion 1 and base unit caption
        $this->assertSame('PIECE-SKU-001', $pieceLabel['code']);
        $this->assertSame((string) $this->unit->conversion_to_base, (string) $pieceLabel['conversion']);
        $this->assertStringNotContainsString('×12', $pieceLabel['unit']);

        // Carton resolves carton unit conversion 12 and carton caption with multiplier
        $this->assertSame('CARTON-SKU-001', $cartonLabel['code']);
        $this->assertSame((string) $this->cartonProductUnit->conversion_to_base, (string) $cartonLabel['conversion']);
        $this->assertStringContainsString('كرتونة', $cartonLabel['unit']);
        $this->assertStringContainsString('×12', $cartonLabel['unit']);

        // 2. Barcode with null unit_id resolves stored product base_unit_id
        $nullUnitBarcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => null,
            'barcode' => 'NULL-UNIT-PIECE',
            'type' => 'C128',
            'is_primary' => false,
        ]);

        $preparedNull = $this->service->prepare([
            $nullUnitBarcode->id => 1,
        ], locale: 'ar', preset: 'a4-3x8');

        $this->assertSame('NULL-UNIT-PIECE', $preparedNull['labels'][0]['code']);
        $this->assertSame((string) $this->unit->conversion_to_base, (string) $preparedNull['labels'][0]['conversion']);
    }

    public function test_cross_tenant_and_foreign_barcode_refusal(): void
    {
        // Clear context and create second company with foreign product and barcode
        app(CompanyContext::class)->clear();
        $otherUser = User::factory()->create(['locale' => 'ar']);
        $company2 = app(CreateCompanyAction::class)->execute($otherUser, [
            'name_ar' => 'شركة أجنبية أخرى',
            'name_en' => 'Other Foreign Co',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($company2, $otherUser);
        $foreignProduct = app(ProductCatalogService::class)->createProduct($company2, [
            'name_ar' => 'منتج شركة أجنبية',
            'name_en' => 'Foreign Product',
            'sku' => 'FOR-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => Unit::where('company_id', $company2->id)->where('code', 'piece')->firstOrFail()->id,
        ], $otherUser->id);

        $foreignBarcode = ProductBarcode::create([
            'company_id' => $company2->id,
            'product_id' => $foreignProduct->id,
            'unit_id' => $foreignProduct->base_unit_id,
            'barcode' => 'FOREIGN-BARCODE-123',
            'type' => 'C128',
            'is_primary' => false,
        ]);

        // Activate original company and owner
        $this->activate($this->owner);

        // Attempting to prepare foreign barcode must be rejected
        $this->expectException(InvalidArgumentException::class);
        $this->service->prepare([$foreignBarcode->id => 1]);
    }

    public function test_inactive_and_deleted_catalog_refusals(): void
    {
        // Inactive product unit fails closed
        $this->cartonProductUnit->active = false;
        $this->cartonProductUnit->save();

        try {
            $this->service->prepare([$this->cartonBarcode->id => 1]);
            $this->fail('Expected exception on inactive product unit');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('ProductUnit configuration', $e->getMessage());
        }

        // Restore carton unit
        $this->cartonProductUnit->active = true;
        $this->cartonProductUnit->save();

        // Inactive product fails closed
        $this->product->active = false;
        $this->product->save();

        try {
            $this->service->prepare([$this->pieceBarcode->id => 1]);
            $this->fail('Expected exception on inactive product');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Product for barcode', $e->getMessage());
        }
    }

    public function test_batch_capacity_and_character_set_refusals(): void
    {
        // Cap refusal: > 500 total labels
        try {
            $this->service->prepare([$this->pieceBarcode->id => 501]);
            $this->fail('Expected exception on > 500 total labels');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('500 total labels', $e->getMessage());
        }

        // Cap refusal: > 100 distinct barcodes
        $largeQuantities = [];
        for ($i = 1; $i <= 101; $i++) {
            $largeQuantities[$i] = 1;
        }
        try {
            $this->service->prepare($largeQuantities);
            $this->fail('Expected exception on > 100 distinct barcodes');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('100 distinct barcodes', $e->getMessage());
        }

        // Empty quantities rejected
        try {
            $this->service->prepare([]);
            $this->fail('Expected exception on empty quantities');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be empty', $e->getMessage());
        }

        // Code 128 character set validation: non-printable ASCII
        try {
            $this->service->validateAndNormalizeBarcode("CODE\x07BAD", 'C128');
            $this->fail('Expected exception on non-printable control chars');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('non-printable', $e->getMessage());
        }

        // Code 128 character set validation: Arabic text
        try {
            $this->service->validateAndNormalizeBarcode('باركود-عربي', 'C128');
            $this->fail('Expected exception on Arabic text in C128');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('non-printable', $e->getMessage());
        }

        // Code 128 length bounds: > 80 chars
        try {
            $this->service->validateAndNormalizeBarcode(str_repeat('X', 81), 'C128');
            $this->fail('Expected exception on code length > 80');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('length must be between 1 and 80', $e->getMessage());
        }
    }

    public function test_invalid_check_digits_refusal_across_all_keyed_symbologies(): void
    {
        // EAN-13 invalid check digit (expected 4, given 5)
        try {
            $this->service->validateAndNormalizeBarcode('9780201379625', 'EAN13');
            $this->fail('Expected exception on invalid EAN-13 check digit');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid check digit', $e->getMessage());
        }

        // EAN-8 invalid check digit (expected 4, given 0)
        try {
            $this->service->validateAndNormalizeBarcode('96385070', 'EAN8');
            $this->fail('Expected exception on invalid EAN-8 check digit');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid check digit', $e->getMessage());
        }

        // UPC-A invalid check digit (expected 2, given 9)
        try {
            $this->service->validateAndNormalizeBarcode('036000291459', 'UPCA');
            $this->fail('Expected exception on invalid UPC-A check digit');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid check digit', $e->getMessage());
        }

        // UPC-E invalid check digit (expected 4, given 9)
        try {
            $this->service->validateAndNormalizeBarcode('04252619', 'UPCE');
            $this->fail('Expected exception on invalid UPC-E check digit');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid UPC-E check digit', $e->getMessage());
        }

        // UPC-E invalid number system (only 0 and 1 supported; 2 must be rejected)
        try {
            $this->service->validateAndNormalizeBarcode('24252614', 'UPCE');
            $this->fail('Expected exception on invalid UPC-E number system');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('UPC-E requires number system', $e->getMessage());
        }

        // UPC-E invalid length (7 digits instead of 8)
        try {
            $this->service->validateAndNormalizeBarcode('0425261', 'UPCE');
            $this->fail('Expected exception on invalid UPC-E length');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('UPC-E requires number system', $e->getMessage());
        }
    }

    public function test_zero_economic_or_inventory_side_effects(): void
    {
        $postingBatchesBefore = PostingBatch::count();
        $stockMovementsBefore = StockMovement::count();

        $prepared = $this->service->prepare([
            $this->pieceBarcode->id => 5,
            $this->cartonBarcode->id => 5,
            $this->ean13Barcode->id => 2,
            $this->ean8Barcode->id => 2,
            $this->upcaBarcode->id => 2,
            $this->upceBarcode->id => 2,
        ], locale: 'ar', preset: 'a4-3x8');

        $html = $this->service->html($prepared, printControls: true);
        $this->assertNotEmpty($html);

        $pdfBytes = $this->service->pdf($prepared);
        $this->assertNotEmpty($pdfBytes);

        // Economic and inventory tables must remain completely untouched
        $this->assertSame($postingBatchesBefore, PostingBatch::count());
        $this->assertSame($stockMovementsBefore, StockMovement::count());
    }
}
