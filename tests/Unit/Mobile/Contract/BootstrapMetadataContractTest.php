<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Contract;

use App\Support\Mobile\WireResponse;
use Tests\TestCase;

final class BootstrapMetadataContractTest extends TestCase
{
    /**
     * G1 Contract: V1 Bootstrap optionally includes currency_minor_units (0..6).
     * Backward compatibility is preserved when omitted, and out-of-range/invalid scales are rejected.
     */
    public function test_bootstrap_wire_response_accepts_valid_currency_minor_units(): void
    {
        $fixture = json_decode(file_get_contents(base_path('docs/integration/mobile-v1/fixtures/golden-bootstrap.json')));

        // 1. Backward compatibility: omitted currency_minor_units remains valid
        $this->assertTrue(WireResponse::accepts($fixture, 'bootstrap-response'));

        // 2. Authoritative currency_minor_units in 0..6 accepted
        foreach ([0, 2, 3, 6] as $scale) {
            $clone = clone $fixture;
            $clone->currency_minor_units = $scale;
            $this->assertTrue(WireResponse::accepts($clone, 'bootstrap-response'));
        }

        // 3. Out-of-range (>6 or <0) rejected
        foreach ([7, -1, 10] as $badScale) {
            $bad = clone $fixture;
            $bad->currency_minor_units = $badScale;
            $this->assertFalse(WireResponse::accepts($bad, 'bootstrap-response'));
        }

        // 4. Boolean type rejected (strict integer requirement, no boolean coercion)
        $badBool = clone $fixture;
        $badBool->currency_minor_units = true;
        $this->assertFalse(WireResponse::accepts($badBool, 'bootstrap-response'));
    }

    /**
     * G2 Contract: Catalog products maintain truthful unassigned tax pairs.
     * (tax_rate_id == null && tax_rate == null) || (tax_rate_id != null && tax_rate != null)
     * No invented product tax_inclusive: false.
     */
    public function test_catalog_tax_pair_contract_unassigned_vs_assigned(): void
    {
        $fixture = json_decode(file_get_contents(base_path('docs/integration/mobile-v1/fixtures/golden-product-catalog.json')));

        // 1. Truthful unassigned tax pair (both null)
        $fixture->products[0]->tax_rate_id = null;
        $fixture->products[0]->tax_rate = null;
        $this->assertTrue(WireResponse::accepts($fixture, 'product-catalog-response'));

        // 2. Truthful assigned tax pair (both non-null)
        $fixture->products[0]->tax_rate_id = '01JCV8X9MDPTRSVWXYZ0123456';
        $fixture->products[0]->tax_rate = '16.000000';
        $this->assertTrue(WireResponse::accepts($fixture, 'product-catalog-response'));

        // 3. Mismatched tax pair is rejected by schema / WireResponse
        $badPair = clone $fixture;
        $badPair->products[0]->tax_rate = '100.000001'; // out of range
        $this->assertFalse(WireResponse::accepts($badPair, 'product-catalog-response'));
    }

    /**
     * G2 Contract: Bootstrap taxes[] holds the sole authority for tax calculation mode.
     * calculation is strictly enum ("inclusive", "exclusive").
     */
    public function test_bootstrap_taxes_calculation_authority(): void
    {
        $fixture = json_decode(file_get_contents(base_path('docs/integration/mobile-v1/fixtures/golden-bootstrap.json')));

        // Valid taxes have calculation "exclusive" or "inclusive"
        $this->assertSame('exclusive', $fixture->taxes[0]->calculation);

        // Mutating calculation to unknown value is rejected
        $badCalc = clone $fixture;
        $badCalc->taxes = [clone $fixture->taxes[0]];
        $badCalc->taxes[0]->calculation = 'compound';
        $this->assertFalse(WireResponse::accepts($badCalc, 'bootstrap-response'));
    }
}
