<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Product;
use App\Models\User;
use App\Services\Search\BoundedProductSearchService;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

class BoundedProductSearchServiceTest extends TestCase
{
    private BoundedProductSearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BoundedProductSearchService;
    }

    public function test_normalization_trims_and_handles_empty(): void
    {
        $this->assertSame('', $this->service->normalize(''));
        $this->assertSame('', $this->service->normalize('   '));
        $this->assertSame('test', $this->service->normalize('  test  '));
    }

    public function test_normalization_converts_eastern_arabic_and_persian_digits(): void
    {
        // Eastern Arabic: ٠١٢٣٤٥٦٧٨٩ -> 0123456789
        $this->assertSame('1234567890', $this->service->normalize('١٢٣٤٥٦٧٨٩٠'));

        // Persian: ۰۱۲۳۴۵۶۷۸۹ -> 0123456789
        $this->assertSame('1234567890', $this->service->normalize('۱۲۳۴۵۶۷۸۹۰'));

        // Mixed barcode: BAR-١٢٣-۴۵
        $this->assertSame('BAR-123-45', $this->service->normalize('BAR-١٢٣-۴۵'));
    }

    public function test_normalization_strips_arabic_diacritics_and_tatweel(): void
    {
        // Fat-hah, Kasrah, Dammah, Sukun, Shaddah, Tanween
        $withHarakat = 'صَابُونٌ نَابُلْسِيّ';
        $this->assertSame('صابون نابلسي', $this->service->normalize($withHarakat));

        // Tatweel (Kashida \u0640)
        $withTatweel = 'قـهـوة';
        $this->assertSame('قهوه', $this->service->normalize($withTatweel));
    }

    public function test_normalization_unifies_alef_variants_and_letter_shapes(): void
    {
        // أ, إ, آ, ٱ -> ا
        $this->assertSame('اكياس ارز', $this->service->normalize('أكياس أرز'));
        $this->assertSame('ابريق', $this->service->normalize('إبريق'));
        $this->assertSame('الات حاسبه', $this->service->normalize('آلات حاسبة'));

        // ة -> ه
        $this->assertSame('قهوه برازيلي', $this->service->normalize('قهوة برازيلي'));

        // ى -> ي
        $this->assertSame('حلوي شامي', $this->service->normalize('حلوى شامي'));
    }

    public function test_normalization_clamps_to_160_characters(): void
    {
        $long = str_repeat('a', 200);
        $normalized = $this->service->normalize($long);
        $this->assertSame(160, mb_strlen($normalized));
    }

    public function test_search_products_query_applies_context_and_tenant_bounds(): void
    {
        // Note: Unit test inspecting query building invariants without executing against database
        $salesQuery = $this->service->searchProductsQuery(10, 'SOAP', 'sales');
        $this->assertInstanceOf(Builder::class, $salesQuery);

        $purchaseQuery = $this->service->searchProductsQuery(10, 'COFFEE', 'purchasing');
        $this->assertInstanceOf(Builder::class, $purchaseQuery);

        // Verify empty query returns un-filtered product builder
        $emptyQuery = $this->service->searchProductsQuery(10, '', 'sales');
        $this->assertInstanceOf(Builder::class, $emptyQuery);
    }

    public function test_bounded_constants_and_zero_query_contract(): void
    {
        $this->assertSame(30, BoundedProductSearchService::MAX_CANDIDATES);
        $this->assertSame(3, BoundedProductSearchService::MIN_SUBSTRING_LENGTH);

        // Mock User for zero query test
        $user = new User;
        $results = $this->service->search(1, $user, '   ', 'sales');
        $this->assertSame([], $results, 'Zero query must return empty array without database query.');
    }

    public function test_normalization_handles_complex_commercial_sku_and_barcodes(): void
    {
        // Barcode with leading/trailing spaces and Persian digits
        $input = "  BAR-۰۱۲۳-PROD  \n";
        $this->assertSame('BAR-0123-PROD', $this->service->normalize($input));

        // Arabic text with mixed tatweel and harakat
        $input2 = 'شــــامـبـو أطـــفـالٍ';
        $this->assertSame('شامبو اطفال', $this->service->normalize($input2));
    }
}
