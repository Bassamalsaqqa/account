<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sales;

use App\Application\Commands\SalesDraftIntent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SalesDraftIntentCanonicalizerTest extends TestCase
{
    private SalesDraftIntent $intent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->intent = new SalesDraftIntent;
    }

    public function test_legacy_payload_absent_discount_fields_preserves_legacy_canonical_structure(): void
    {
        $payload = [
            'customer_id' => 10,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0',
            'issue_date' => '2026-11-25',
            'lines' => [
                [
                    'item_description' => 'Item 1',
                    'quantity' => '2',
                    'unit_price' => '50.00',
                ],
            ],
        ];

        $canonical = $this->intent->canonicalize($payload, isUpdate: false);

        // Verify that absent new discount fields are NOT added to canonical payload
        $this->assertArrayNotHasKey('discount_type', $canonical);
        $this->assertArrayNotHasKey('discount_value', $canonical);
        $this->assertArrayNotHasKey('discount_scope', $canonical);
        $this->assertArrayNotHasKey('discount_basis', $canonical);
        $this->assertArrayNotHasKey('discount_calculation_version', $canonical);

        // Verify line has no allocated document discount or manual discount amount
        $this->assertArrayNotHasKey('manual_discount_amount', $canonical['lines'][0]);
        $this->assertArrayNotHasKey('allocated_document_discount', $canonical['lines'][0]);

        // Verify deterministic hash is computed without error
        $hash = $this->intent->canonicalHash($canonical);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_document_discount_payload_includes_versioned_discount_keys(): void
    {
        $payload = [
            'customer_id' => 10,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '15.00',
            'lines' => [
                [
                    'item_description' => 'Item 1',
                    'quantity' => '2',
                    'unit_price' => '50.00',
                ],
            ],
        ];

        $canonical = $this->intent->canonicalize($payload, isUpdate: false);

        $this->assertSame('fixed', $canonical['discount_type']);
        $this->assertSame('15.000000', $canonical['discount_value']);
        $this->assertSame('document', $canonical['discount_scope']);
        $this->assertSame('subtotal', $canonical['discount_basis']);
        $this->assertSame(2, $canonical['discount_calculation_version']);

        $this->assertSame('0.000000', $canonical['lines'][0]['manual_discount_amount']);
        $this->assertSame('0.000000', $canonical['lines'][0]['allocated_document_discount']);
    }

    public function test_update_omitted_discount_does_not_inject_null_defaults(): void
    {
        $updatePayload = [
            'notes' => 'Updated notes without discount',
            'lines' => [
                [
                    'item_description' => 'Item 1',
                    'quantity' => '3',
                    'unit_price' => '50.00',
                ],
            ],
        ];

        $canonical = $this->intent->canonicalize($updatePayload, isUpdate: true);

        // In an update with omitted discount fields, they must NOT be injected into canonical
        $this->assertArrayNotHasKey('discount_type', $canonical);
        $this->assertArrayNotHasKey('discount_value', $canonical);
        $this->assertArrayNotHasKey('discount_scope', $canonical);
        $this->assertArrayNotHasKey('discount_basis', $canonical);
        $this->assertArrayNotHasKey('discount_calculation_version', $canonical);
    }

    public function test_update_explicit_clear_discount(): void
    {
        $updatePayload = [
            'discount_type' => 'none',
            'lines' => [
                [
                    'item_description' => 'Item 1',
                    'quantity' => '3',
                    'unit_price' => '50.00',
                ],
            ],
        ];

        $canonical = $this->intent->canonicalize($updatePayload, isUpdate: true);

        $this->assertNull($canonical['discount_type']);
        $this->assertSame('0.000000', $canonical['discount_value']);
    }

    public function test_reject_positive_header_discount_with_line_scope(): void
    {
        $payload = [
            'customer_id' => 10,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'discount_scope' => 'line',
            'lines' => [
                [
                    'item_description' => 'Item 1',
                    'quantity' => '1',
                    'unit_price' => '50.00',
                ],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Header discount cannot have 'line' discount scope.");

        $this->intent->canonicalize($payload, isUpdate: false);
    }

    public function test_reject_client_owned_currency_minor_units_header_field(): void
    {
        $payload = [
            'customer_id' => 10,
            'currency_code' => 'ILS',
            'currency_minor_units' => 2,
            'exchange_rate' => '1.0',
            'issue_date' => '2026-11-25',
            'lines' => [
                [
                    'item_description' => 'Item 1',
                    'quantity' => '1',
                    'unit_price' => '50.00',
                ],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown header field [currency_minor_units].');

        $this->intent->canonicalize($payload, isUpdate: false);
    }
}
