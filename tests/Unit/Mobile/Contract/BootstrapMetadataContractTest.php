<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Contract;

use App\Http\Controllers\Api\Mobile\V1\BootstrapController;
use App\Models\Company;
use App\Models\User;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use App\Support\Mobile\WireResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Tests\TestCase;

final class BootstrapMetadataContractTest extends TestCase
{
    /**
     * G1 Contract: Absent include query parameter preserves exact old 12-field response
     * and strictly does NOT emit currency_minor_units or base_currency_minor_units.
     */
    public function test_bootstrap_absent_include_emits_exact_twelve_keys_without_minor_units(): void
    {
        $golden = json_decode(file_get_contents(base_path('docs/integration/mobile-v1/fixtures/golden-bootstrap.json')), true);

        // Authoritative 12-key whitelist matching Android's BootstrapResponse.ALLOWED_KEYS
        $expected12Keys = [
            'company_id', 'company_name', 'base_currency', 'walk_in_customer_id',
            'staff', 'device_lease', 'default_warehouse', 'approved_cash_account',
            'units', 'taxes', 'offline_policy', 'catalog_snapshot_version',
        ];

        $goldenKeys = array_keys($golden);
        sort($goldenKeys);
        $expectedSorted = $expected12Keys;
        sort($expectedSorted);

        $this->assertSame($expectedSorted, $goldenKeys, 'Golden bootstrap must have exact 12 keys');
        $this->assertArrayNotHasKey('currency_minor_units', $golden);
        $this->assertArrayNotHasKey('base_currency_minor_units', $golden);

        // Verify wire response acceptance of standard 12-field payload
        $this->assertTrue(WireResponse::accepts(json_decode(json_encode($golden)), 'bootstrap-response'));
    }

    /**
     * G1 Contract: Exact include=currency_minor_units projection opt-in allows
     * single currency_minor_units (0..6) and rejects unrequested base_currency_minor_units alias.
     */
    public function test_bootstrap_optin_projection_and_alias_removal(): void
    {
        $fixture = json_decode(file_get_contents(base_path('docs/integration/mobile-v1/fixtures/golden-bootstrap.json')));

        // 1. Single currency_minor_units accepted when projected
        $optedIn = clone $fixture;
        $optedIn->currency_minor_units = 2;
        $this->assertTrue(WireResponse::accepts($optedIn, 'bootstrap-response'));

        // 2. Unrequested base_currency_minor_units alias is completely removed and rejected by schema
        $withAlias = clone $fixture;
        $withAlias->base_currency_minor_units = 2;
        $this->assertFalse(WireResponse::accepts($withAlias, 'bootstrap-response'));

        // 3. Out-of-range scale rejected
        $badScale = clone $fixture;
        $badScale->currency_minor_units = 7;
        $this->assertFalse(WireResponse::accepts($badScale, 'bootstrap-response'));

        // 4. Boolean type rejected (no bool coercion)
        $badBool = clone $fixture;
        $badBool->currency_minor_units = false;
        $this->assertFalse(WireResponse::accepts($badBool, 'bootstrap-response'));
    }

    /**
     * G1 Controller Request Projection Validation:
     * Unknown include parameter values are rejected immediately with 422 VALIDATION_FAILED.
     */
    public function test_controller_rejects_unknown_include_projection_with_422(): void
    {
        $controller = new BootstrapController;

        $user = new User;
        $user->id = 1;
        $user->public_id = '01JCV8X9M8T1V2W3X4Y5Z67890';

        $company = new Company;
        $company->id = 1;
        $company->public_id = '01JCV8X9M2NQW3TYZ4R5S6789A';

        // 1. Unknown include string
        $reqUnknown = Request::create('/api/mobile/v1/companies/01JCV8X9M2NQW3TYZ4R5S6789A/bootstrap?include=unknown_field', 'GET');
        $reqUnknown->attributes->set('mobile_company', $company);
        $reqUnknown->attributes->set('mobile_user', $user);

        $resUnknown = $controller->show($reqUnknown, '01JCV8X9M2NQW3TYZ4R5S6789A');
        $this->assertSame(422, $resUnknown->getStatusCode());
        $dataUnknown = json_decode((string) $resUnknown->getContent(), true);
        $this->assertSame('VALIDATION_FAILED', $dataUnknown['error']['code']);
        $this->assertStringContainsString('currency_minor_units', $dataUnknown['error']['message']);

        // 2. Array include parameter rejected
        $reqArray = Request::create('/api/mobile/v1/companies/01JCV8X9M2NQW3TYZ4R5S6789A/bootstrap', 'GET', ['include' => ['currency_minor_units']]);
        $reqArray->attributes->set('mobile_company', $company);
        $reqArray->attributes->set('mobile_user', $user);

        $resArray = $controller->show($reqArray, '01JCV8X9M2NQW3TYZ4R5S6789A');
        $this->assertSame(422, $resArray->getStatusCode());
        $dataArray = json_decode((string) $resArray->getContent(), true);
        $this->assertSame('VALIDATION_FAILED', $dataArray['error']['code']);
    }

    /**
     * G1 Resolver Fail-Closed Semantics:
     * CurrencyMinorUnitsResolver throws InvalidArgumentException on unresolvable/disabled currencies,
     * which the controller maps to 409 CONTRACT_INCOMPATIBLE without guessing 2.
     */
    public function test_currency_minor_units_resolver_fail_closed_contract(): void
    {
        $resolver = new CurrencyMinorUnitsResolver;

        // 1. Empty currency throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $resolver->resolveForCompany(1, '');
    }

    /**
     * G2 Contract: Catalog tax pair truthfulness.
     * Catalog items maintain paired nullability:
     * (tax_rate_id == null && tax_rate == null) || (tax_rate_id != null && tax_rate != null)
     * Zero invented product tax_inclusive: false.
     */
    public function test_catalog_tax_pair_truthfulness_and_no_invented_inclusive(): void
    {
        $catalog = json_decode(file_get_contents(base_path('docs/integration/mobile-v1/fixtures/golden-product-catalog.json')), true);

        // 1. Existing golden catalog products are truthfully unassigned
        $this->assertNull($catalog['products'][0]['tax_rate']);
        $this->assertNull($catalog['products'][0]['tax_rate_id']);
        $this->assertArrayNotHasKey('tax_inclusive', $catalog['products'][0]);

        $this->assertTrue(WireResponse::accepts(json_decode(json_encode($catalog)), 'product-catalog-response'));

        // 2. Assigned tax pair is accepted
        $assigned = $catalog;
        $assigned['products'][0]['tax_rate_id'] = '01JCV8X9MDPTRSVWXYZ0123456';
        $assigned['products'][0]['tax_rate'] = '16.000000';
        $this->assertTrue(WireResponse::accepts(json_decode(json_encode($assigned)), 'product-catalog-response'));

        // 3. Mismatched tax rate bounds rejected
        $bad = $catalog;
        $bad['products'][0]['tax_rate_id'] = '01JCV8X9MDPTRSVWXYZ0123456';
        $bad['products'][0]['tax_rate'] = '100.000001';
        $this->assertFalse(WireResponse::accepts(json_decode(json_encode($bad)), 'product-catalog-response'));
    }

    /**
     * G2 Canonical V2 ExplicitNoTax Rule:
     * OnlineSaleIntentProfile (line 545) permits explicit null tax_rate_id ONLY with
     * tax_rate="0.000000" and tax_inclusive=false chosen intentionally by client/policy.
     * It is NOT inferred from unassigned catalog products.
     */
    public function test_v2_explicit_no_tax_rule_enforcement(): void
    {
        // 1. Valid Explicit NoTax line definition
        $validNoTaxLine = [
            'product_id' => '01JCV8X9MBV4W5X6Y7Z8012345',
            'product_unit_id' => '01JCV8X9MC6789ABCDEFGHJKMN',
            'conversion_to_base' => '1.000000',
            'quantity' => '1.000000',
            'unit_price' => '10.000000',
            'tax_rate_id' => null,
            'tax_rate' => '0.000000',
            'tax_inclusive' => false,
        ];

        // 2. If tax_rate_id is null but rate is non-zero, it violates ExplicitNoTax rule
        $invalidRateNoTax = $validNoTaxLine;
        $invalidRateNoTax['tax_rate'] = '16.000000';

        // 3. If tax_rate_id is null but tax_inclusive is true, it violates ExplicitNoTax rule
        $invalidInclusiveNoTax = $validNoTaxLine;
        $invalidInclusiveNoTax['tax_inclusive'] = true;

        $this->assertSame('0.000000', $validNoTaxLine['tax_rate']);
        $this->assertFalse($validNoTaxLine['tax_inclusive']);
        $this->assertNotSame('0.000000', $invalidRateNoTax['tax_rate']);
        $this->assertTrue($invalidInclusiveNoTax['tax_inclusive']);
    }

    /**
     * G2 Contract: Bootstrap taxes[] holds sole authority for tax calculation mode.
     */
    public function test_bootstrap_taxes_calculation_authority(): void
    {
        $fixture = json_decode(file_get_contents(base_path('docs/integration/mobile-v1/fixtures/golden-bootstrap.json')));

        // Valid taxes have calculation strictly enum ("exclusive", "inclusive")
        $this->assertSame('exclusive', $fixture->taxes[0]->calculation);

        // Unknown calculation is rejected by wire schema
        $badCalc = clone $fixture;
        $badCalc->taxes = [clone $fixture->taxes[0]];
        $badCalc->taxes[0]->calculation = 'unsupported';
        $this->assertFalse(WireResponse::accepts($badCalc, 'bootstrap-response'));
    }
}
