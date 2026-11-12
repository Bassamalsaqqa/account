<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Services\Purchasing\PurchaseProductSelection;
use App\Services\Sales\SalesProductSelection;
use App\Services\Search\BoundedProductSearchService;
use Tests\TestCase;

class BoundedProductSearchServiceTest extends TestCase
{
    private BoundedProductSearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BoundedProductSearchService;
    }

    public function test_shared_suggestions_convert_cartons_exactly_and_distinguish_missing_from_zero(): void
    {
        $product = new Product;
        $unit = new ProductUnit(['conversion_to_base' => '12']);
        foreach ([[new SalesProductSelection, 'default_sale_price_base', 'default_sale_price_base'],
            [new PurchaseProductSelection, 'default_purchase_cost_base', 'default_purchase_price_base']] as [$selection, $productField, $unitField]) {
            $this->assertNull($selection->suggestedBasePrice($product, $unit));
            $product->{$productField} = '1.234567';
            $this->assertTrue($selection->suggestedBasePrice($product, $unit)->isEqualTo('14.814804'));
            $unit->{$unitField} = '0';
            $this->assertTrue($selection->suggestedBasePrice($product, $unit)->isZero());
            $unit->{$unitField} = '9.876543';
            $this->assertTrue($selection->suggestedBasePrice($product, $unit)->isEqualTo('9.876543'));
        }
    }

    public function test_normalization_trims_and_handles_empty(): void
    {
        $this->assertSame('', $this->service->normalize(''));
        $this->assertSame('', $this->service->normalize('   '));
        $this->assertSame('test', $this->service->normalize('  TEST  '));
    }

    public function test_normalization_converts_eastern_arabic_and_persian_digits(): void
    {
        // Eastern Arabic: ٠١٢٣٤٥٦٧٨٩ -> 0123456789
        $this->assertSame('1234567890', $this->service->normalize('١٢٣٤٥٦٧٨٩٠'));

        // Persian: ۰۱۲۳۴۵۶۷۸۹ -> 0123456789
        $this->assertSame('1234567890', $this->service->normalize('۱۲۳۴۵۶۷۸۹۰'));

        // Mixed barcode with lowercase conversion: BAR-١٢٣-۴۵ -> bar-123-45
        $this->assertSame('bar-123-45', $this->service->normalize('BAR-١٢٣-۴۵'));
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
        $long = str_repeat('A', 200);
        $normalized = $this->service->normalize($long);
        $this->assertSame(160, mb_strlen($normalized));
        $this->assertSame(str_repeat('a', 160), $normalized);
    }

    public function test_escape_like_escapes_sql_metacharacters(): void
    {
        $this->assertSame('100\\%\\_clean\\\\test', $this->service->escapeLike('100%_clean\\test'));
        $this->assertSame('plain-sku', $this->service->escapeLike('plain-sku'));
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
        // Barcode with leading/trailing spaces, Persian digits, and uppercase English
        $input = "  BAR-۰۱۲۳-PROD  \n";
        $this->assertSame('bar-0123-prod', $this->service->normalize($input));

        // Arabic text with mixed tatweel and harakat
        $input2 = 'شــــامـبـو أطـــفـالٍ';
        $this->assertSame('شامبو اطفال', $this->service->normalize($input2));
    }
}
