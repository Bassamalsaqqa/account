<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Products\BarcodeLabels;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\BarcodeLabelService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class BarcodeLabelsTest extends Phase5ETestCase
{
    protected BarcodeLabelService $service;

    protected Unit $cartonUnit;

    protected ProductUnit $cartonProductUnit;

    protected ProductBarcode $pieceBarcode;

    protected ProductBarcode $cartonBarcode;

    protected ProductBarcode $ean13Barcode;

    protected ProductBarcode $ean8Barcode;

    protected ProductBarcode $upcaBarcode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BarcodeLabelService::class);

        // Carton unit
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

        // Piece barcode (C128)
        $this->pieceBarcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->product->base_unit_id,
            'barcode' => 'PIECE-SKU-001',
            'type' => 'C128',
            'is_primary' => true,
        ]);

        // Carton barcode (C128)
        $this->cartonBarcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->cartonUnit->id,
            'barcode' => 'CARTON-SKU-001',
            'type' => 'C128',
            'is_primary' => false,
        ]);

        // EAN-13 barcode
        $this->ean13Barcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->product->base_unit_id,
            'barcode' => '9780201379624',
            'type' => 'EAN13',
            'is_primary' => false,
        ]);

        // EAN-8 barcode
        $this->ean8Barcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->product->base_unit_id,
            'barcode' => '96385074',
            'type' => 'EAN8',
            'is_primary' => false,
        ]);

        // UPC-A barcode
        $this->upcaBarcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->product->base_unit_id,
            'barcode' => '036000291452',
            'type' => 'UPCA',
            'is_primary' => false,
        ]);
    }

    public function test_service_resolves_stored_code_and_unit_id_correctly(): void
    {
        $prepared = $this->service->prepare([
            $this->cartonBarcode->id => 3,
        ], locale: 'ar', preset: 'a4-3x8');

        $this->assertSame('ar', $prepared['locale']);
        $this->assertSame('a4-3x8', $prepared['preset']);
        $this->assertCount(3, $prepared['labels']);

        $label = $prepared['labels'][0];
        $this->assertSame('CARTON-SKU-001', $label['code']);
        $this->assertSame('C128', $label['type']);
        $this->assertSame('ITM-01', $label['sku']);
        $this->assertSame((string) $this->cartonProductUnit->conversion_to_base, $label['conversion']);
        $this->assertStringContainsString('كرتونة', $label['unit']);
        $this->assertStringContainsString('×12', $label['unit']);
    }

    public function test_piece_and_carton_barcodes_are_non_interchangeable(): void
    {
        $prepared = $this->service->prepare([
            $this->pieceBarcode->id => 1,
            $this->cartonBarcode->id => 1,
        ], locale: 'en', preset: 'a4-2x7');

        $this->assertCount(2, $prepared['labels']);

        $pieceLabel = $prepared['labels'][0];
        $cartonLabel = $prepared['labels'][1];

        // Piece barcode never becomes carton label
        $this->assertSame('PIECE-SKU-001', $pieceLabel['code']);
        $this->assertSame((string) $this->unit->conversion_to_base, (string) $pieceLabel['conversion']);
        $this->assertStringNotContainsString('Carton', $pieceLabel['unit']);
        $this->assertStringNotContainsString('×12', $pieceLabel['unit']);

        // Carton barcode never becomes piece label
        $this->assertSame('CARTON-SKU-001', $cartonLabel['code']);
        $this->assertSame((string) $this->cartonProductUnit->conversion_to_base, $cartonLabel['conversion']);
        $this->assertStringContainsString('Carton', $cartonLabel['unit']);
        $this->assertStringContainsString('×12', $cartonLabel['unit']);
    }

    public function test_null_unit_resolves_stored_product_base_unit(): void
    {
        $nullUnitBarcode = ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => null,
            'barcode' => 'NULL-UNIT-001',
            'type' => 'standard',
            'is_primary' => false,
        ]);

        $prepared = $this->service->prepare([
            $nullUnitBarcode->id => 2,
        ], locale: 'ar', preset: 'a4-3x8');

        $this->assertCount(2, $prepared['labels']);
        $this->assertSame('NULL-UNIT-001', $prepared['labels'][0]['code']);
        $this->assertSame((string) $this->unit->conversion_to_base, (string) $prepared['labels'][0]['conversion']);
    }

    public function test_inactive_or_stale_product_unit_is_rejected(): void
    {
        $this->cartonProductUnit->active = false;
        $this->cartonProductUnit->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ProductUnit configuration/i');

        $this->service->prepare([$this->cartonBarcode->id => 1]);
    }

    public function test_inactive_product_is_rejected(): void
    {
        $this->product->active = false;
        $this->product->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Product for barcode/i');

        $this->service->prepare([$this->pieceBarcode->id => 1]);
    }

    public function test_foreign_company_barcode_is_rejected(): void
    {
        app(CompanyContext::class)->clear();
        $otherUser = User::factory()->create(['locale' => 'ar']);
        $company2 = app(CreateCompanyAction::class)->execute($otherUser, [
            'name_ar' => 'شركة ثانية أجنبية',
            'name_en' => 'Second Foreign Co',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($company2, $otherUser);
        $foreignProduct = app(ProductCatalogService::class)->createProduct($company2, [
            'name_ar' => 'منتج آخر', 'name_en' => 'Foreign Product', 'sku' => 'FOREIGN-01',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => Unit::where('company_id', $company2->id)->where('code', 'piece')->firstOrFail()->id,
        ], $otherUser->id);
        $foreignBarcode = ProductBarcode::create([
            'company_id' => $company2->id,
            'product_id' => $foreignProduct->id,
            'unit_id' => $foreignProduct->base_unit_id,
            'barcode' => 'FOREIGN-BARCODE-999',
            'type' => 'C128',
            'is_primary' => false,
        ]);

        $this->activate($this->owner);

        $this->expectException(InvalidArgumentException::class);
        $this->service->prepare([$foreignBarcode->id => 1]);
    }

    public function test_unauthorized_role_is_denied(): void
    {
        // Create a user without inventory.barcode_labels.print
        $unauthorizedUser = User::factory()->create();
        $this->company->users()->attach($unauthorizedUser->id, ['status' => 'active']);

        $roleService = app(CompanyRoleService::class);
        $roleService->seedCompanyRoles($this->company);

        // Assign Administrator role which does NOT have inventory.barcode_labels.print by default
        setPermissionsTeamId($this->company->id);
        $unauthorizedUser->assignRole('Administrator');

        $this->activate($unauthorizedUser);

        $this->expectException(AuthorizationException::class);
        $this->service->prepare([$this->pieceBarcode->id => 1]);
    }

    public function test_invalid_check_digits_rejected_for_ean13_ean8_upca(): void
    {
        // EAN-13 invalid check digit (expected 4, given 5)
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateAndNormalizeBarcode('9780201379625', 'EAN13');
    }

    public function test_invalid_check_digit_for_ean8(): void
    {
        // EAN-8 invalid check digit (expected 4, given 0)
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateAndNormalizeBarcode('96385070', 'EAN8');
    }

    public function test_invalid_check_digit_for_upca(): void
    {
        // UPC-A invalid check digit (expected 2, given 9)
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateAndNormalizeBarcode('036000291459', 'UPCA');
    }

    public function test_c128_printable_ascii_constraints_and_length_limits(): void
    {
        // Non-printable control char rejected
        try {
            $this->service->validateAndNormalizeBarcode("CODE\x00123", 'C128');
            $this->fail('Expected exception on control characters');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('non-printable', $e->getMessage());
        }

        // Non-ASCII Arabic characters rejected
        try {
            $this->service->validateAndNormalizeBarcode('باركود-عربي', 'C128');
            $this->fail('Expected exception on non-ASCII characters');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('non-printable', $e->getMessage());
        }

        // Code too long (> 80 chars) rejected
        try {
            $this->service->validateAndNormalizeBarcode(str_repeat('A', 81), 'C128');
            $this->fail('Expected exception on too long barcode');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('length must be between 1 and 80', $e->getMessage());
        }
    }

    public function test_max_500_total_and_100_distinct_caps_enforced(): void
    {
        // Exceeding 500 total labels rejected
        try {
            $this->service->prepare([$this->pieceBarcode->id => 501]);
            $this->fail('Expected exception on > 500 total labels');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('500 total labels', $e->getMessage());
        }

        // Exceeding 100 distinct barcodes rejected
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
    }

    public function test_actual_pdf_generation_with_mpdf_zero_economic_writes(): void
    {
        $postingBatchesBefore = PostingBatch::count();
        $stockMovementsBefore = StockMovement::count();

        $prepared = $this->service->prepare([
            $this->pieceBarcode->id => 12,
            $this->cartonBarcode->id => 6,
            $this->ean13Barcode->id => 4,
            $this->ean8Barcode->id => 2,
        ], locale: 'ar', preset: 'a4-3x8');

        $html = $this->service->html($prepared, printControls: true);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('svg', $html);

        $pdfBytes = $this->service->pdf($prepared);
        $this->assertNotEmpty($pdfBytes);
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
        $this->assertGreaterThan(5000, strlen($pdfBytes));
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes));

        // Invariant check: zero economic writes
        $this->assertSame($postingBatchesBefore, PostingBatch::count());
        $this->assertSame($stockMovementsBefore, StockMovement::count());
    }

    public function test_export_pdf_evidence_to_disk(): void
    {
        $preparedAr = $this->service->prepare([
            $this->pieceBarcode->id => 8,
            $this->cartonBarcode->id => 8,
            $this->ean13Barcode->id => 4,
            $this->upcaBarcode->id => 4,
        ], locale: 'ar', preset: 'a4-3x8');

        $pdfAr = $this->service->pdf($preparedAr);

        $preparedEn = $this->service->prepare([
            $this->pieceBarcode->id => 4,
            $this->cartonBarcode->id => 4,
            $this->ean8Barcode->id => 3,
            $this->upcaBarcode->id => 3,
        ], locale: 'en', preset: 'a4-2x7');

        $pdfEn = $this->service->pdf($preparedEn);

        $exportDir = base_path('.ai/delegations/phase9-implementation/pdf');
        if (! File::isDirectory($exportDir)) {
            File::makeDirectory($exportDir, 0755, true);
        }

        File::put($exportDir.'/labels-ar.pdf', $pdfAr);
        File::put($exportDir.'/labels-en.pdf', $pdfEn);

        $this->assertFileExists($exportDir.'/labels-ar.pdf');
        $this->assertFileExists($exportDir.'/labels-en.pdf');
        $this->assertGreaterThan(5000, filesize($exportDir.'/labels-ar.pdf'));
        $this->assertGreaterThan(5000, filesize($exportDir.'/labels-en.pdf'));
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdfAr));
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdfEn));
    }

    public function test_upce_uses_the_exact_stored_symbol_and_multi_sheet_boundaries(): void
    {
        $barcode = ProductBarcode::create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id,
            'unit_id' => $this->product->base_unit_id, 'barcode' => '04252614', 'type' => 'UPCE', 'is_primary' => false,
        ]);
        $prepared = $this->service->prepare([$barcode->id => 49], 'en');
        $this->assertSame('04252614', $prepared['labels'][0]['code']);
        $this->assertSame('042100005264', $prepared['labels'][0]['encoded_code']);
        $bytes = $this->service->pdf($prepared);
        $this->assertSame(3, preg_match_all('/\/Type\s*\/Page\b/', $bytes));
        File::put(base_path('.ai/delegations/phase9-implementation/pdf/labels-upce.pdf'), $bytes);
    }

    public function test_overwide_codes_fail_instead_of_printing_unreadable_symbols(): void
    {
        $this->pieceBarcode->update(['barcode' => str_repeat('A', 80)]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too wide');
        $this->service->prepare([$this->pieceBarcode->id => 1]);
    }

    public function test_label_ui_explains_retired_unit_instead_of_returning_server_error(): void
    {
        $this->cartonProductUnit->update(['active' => false]);
        Livewire::test(BarcodeLabels::class)
            ->call('selectBarcode', $this->cartonBarcode->id)->call('preview')
            ->assertSet('showPreviewModal', false)->assertSet('previewHtml', null)
            ->assertSet('errorMessage', __('labels.invalid_selection'))
            ->call('downloadPdf')->assertSet('errorMessage', __('labels.invalid_selection'));
    }

    public function test_livewire_component_selection_survives_filters_and_downloads_pdf(): void
    {
        Livewire::test(BarcodeLabels::class)
            ->assertSet('lockedCompanyId', $this->company->id)
            ->call('selectBarcode', $this->pieceBarcode->id)
            ->assertSet('quantities', [$this->pieceBarcode->id => 1])
            ->call('selectBarcode', $this->cartonBarcode->id)
            ->assertSet('quantities', [
                $this->pieceBarcode->id => 1,
                $this->cartonBarcode->id => 1,
            ])
            // Filter / search change preserves selected quantities
            ->set('search', 'PIECE')
            ->assertSet('quantities', [
                $this->pieceBarcode->id => 1,
                $this->cartonBarcode->id => 1,
            ])
            // Update quantity
            ->call('updateQuantity', $this->pieceBarcode->id, 5)
            ->assertSet('quantities', [
                $this->pieceBarcode->id => 5,
                $this->cartonBarcode->id => 1,
            ])
            // Preview
            ->call('preview')
            ->assertSet('showPreviewModal', true)
            ->assertSet('previewHtml', fn (?string $val): bool => $val !== null && $val !== '')
            ->call('closePreview')
            ->assertSet('showPreviewModal', false)
            // Remove barcode
            ->call('removeBarcode', $this->pieceBarcode->id)
            ->assertSet('quantities', [$this->cartonBarcode->id => 1])
            // Download PDF
            ->call('downloadPdf')
            ->assertFileDownloaded("barcode-labels-a4-3x8-{$this->owner->locale}.pdf");
    }
}
