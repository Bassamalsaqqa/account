<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Contract;

use App\Support\Mobile\WireResponse;
use Tests\TestCase;

final class WireResponseTest extends TestCase
{
    public function test_maximum_database_decimals_remain_strings_and_wire_overflow_is_rejected(): void
    {
        $fixture = json_decode(file_get_contents(base_path('docs/integration/mobile-v1/fixtures/golden-product-catalog.json')));
        foreach (['99999999999999.999999', '90071992547409.123456'] as $value) {
            $fixture->products[0]->unit_price = $value;
            $fixture->products[0]->available_quantity = $value;
            $this->assertTrue(WireResponse::accepts($fixture, 'product-catalog-response'));
            $decoded = json_decode(json_encode($fixture, JSON_THROW_ON_ERROR));
            $this->assertSame($value, $decoded->products[0]->unit_price);
            $this->assertSame($value, $decoded->products[0]->available_quantity);
        }
        $fixture->products[0]->unit_price = '100000000000000.000000';
        $this->assertFalse(WireResponse::accepts($fixture, 'product-catalog-response'));
    }

    public function test_percentage_and_unicode_wire_bounds_are_enforced_without_rounding(): void
    {
        $fixture = json_decode(file_get_contents(base_path('docs/integration/mobile-v1/fixtures/golden-product-catalog.json')));
        foreach (['0.000000', '16.123456', '100.000000'] as $percentage) {
            $fixture->products[0]->tax_rate_id = '01JCV8X9MDPTRSVWXYZ0123456';
            $fixture->products[0]->tax_rate = $percentage;
            $this->assertTrue(WireResponse::accepts($fixture, 'product-catalog-response'));
        }
        $fixture->products[0]->tax_rate = '100.000001';
        $this->assertFalse(WireResponse::accepts($fixture, 'product-catalog-response'));
        $fixture->products[0]->tax_rate = null;
        $fixture->products[0]->tax_rate_id = null;
        $fixture->products[0]->name = str_repeat('ع', 255);
        $this->assertTrue(WireResponse::accepts($fixture, 'product-catalog-response'));
        $fixture->products[0]->name = str_repeat('ع', 256);
        $this->assertFalse(WireResponse::accepts($fixture, 'product-catalog-response'));
        $this->assertSame(256, mb_strlen($fixture->products[0]->name));
    }
}
