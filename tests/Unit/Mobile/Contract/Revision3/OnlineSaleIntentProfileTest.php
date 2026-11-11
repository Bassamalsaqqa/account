<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Contract\Revision3;

use App\Support\Mobile\Contract\Revision3\Exceptions\IntentProfileValidationException;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use PHPUnit\Framework\TestCase;

final class OnlineSaleIntentProfileTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validContext(): array
    {
        return [
            'actor_id' => '01JCV8X9M8T1V2W3X4Y5Z67890',
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'currency_minor_units' => 2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validRequest(): array
    {
        return [
            'client_event_id' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
            'device_id' => '8e2b8bc6-f80c-4034-8c88-59368d1f2e10',
            'customer_id' => '01JCV8X9M4K1P2Q3R4S5T6V7W8',
            'warehouse_id' => '01JCV8X9M9A2B3C4D5E6F7G8H9',
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => '01JCV8X9M6A1B2C3D4E5F6G7H8',
                    'product_unit_id' => '01JCV8X9MBV4W5X6Y7Z8012345',
                    'conversion_to_base' => '1.000000',
                    'quantity' => '2.000000',
                    'unit_price' => '25.000000',
                    'tax_rate_id' => '01J9Z5K3MN0000000000000030',
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => true,
                ],
            ],
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
                'amount' => '50.000000',
            ],
        ];
    }

    public function test_construct_valid_full_profile(): void
    {
        $profile = OnlineSaleIntentProfile::construct($this->validContext(), $this->validRequest());

        $this->assertSame('01JCV8X9M8T1V2W3X4Y5Z67890', $profile['actor_id']);
        $this->assertSame('01JCV8X9M2NQW3TYZ4R5S6789A', $profile['company_id']);
        $this->assertSame(2, $profile['currency_minor_units']);
        $this->assertCount(1, $profile['lines']);
        $this->assertSame('full', $profile['payment']['settlement_mode']);
        $this->assertSame('50.000000', $profile['payment']['amount']);

        $digest = OnlineSaleIntentProfile::computeDigest($profile);
        $this->assertSame('sha256:93b9aa677275388defe694390ab756d4b08f122087ec084b8dbd8b421ffc45fd', $digest);

        [$valid, $vDigest] = OnlineSaleIntentProfile::verify($profile, $digest);
        $this->assertTrue($valid);
        $this->assertSame($digest, $vDigest);
    }

    public function test_context_validation_rejections(): void
    {
        // Missing company_id
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct(['actor_id' => '01JCV8X9M8T1V2W3X4Y5Z67890', 'currency_minor_units' => 2], $this->validRequest());
    }

    public function test_context_boolean_cmu_rejected(): void
    {
        $ctx = $this->validContext();
        $ctx['currency_minor_units'] = true;
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($ctx, $this->validRequest());
    }

    public function test_context_extra_keys_rejected(): void
    {
        $ctx = $this->validContext();
        $ctx['extra_key'] = 'unauthorized';
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($ctx, $this->validRequest());
    }

    public function test_raw_request_unknown_root_property_rejected(): void
    {
        $req = $this->validRequest();
        $req['extra_field'] = 'attack';
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_raw_request_unknown_line_property_rejected(): void
    {
        $req = $this->validRequest();
        $req['lines'][0]['unauthorized_discount'] = '10.000000';
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_raw_request_unknown_payment_property_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment']['extra_payment_instruction'] = 'unauthorized';
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_missing_tax_rate_id_throws_never_implicit_notax(): void
    {
        $req = $this->validRequest();
        unset($req['lines'][0]['tax_rate_id']);
        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage("Missing required 'tax_rate_id' on line 0 (ExplicitNoTax requires explicit null, never omitted key)");
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_explicit_notax_null_accepted(): void
    {
        $req = $this->validRequest();
        $req['lines'][0]['tax_rate_id'] = null;
        $req['lines'][0]['tax_rate'] = '0.000000';
        $req['lines'][0]['tax_inclusive'] = false;

        $profile = OnlineSaleIntentProfile::construct($this->validContext(), $req);
        $this->assertNull($profile['lines'][0]['tax_rate_id']);
        $this->assertSame('0.000000', $profile['lines'][0]['tax_rate']);
        $this->assertFalse($profile['lines'][0]['tax_inclusive']);
    }

    public function test_notax_with_nonzero_rate_rejected(): void
    {
        $req = $this->validRequest();
        $req['lines'][0]['tax_rate_id'] = null;
        $req['lines'][0]['tax_rate'] = '16.000000';
        $req['lines'][0]['tax_inclusive'] = false;

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_notax_with_inclusive_true_rejected(): void
    {
        $req = $this->validRequest();
        $req['lines'][0]['tax_rate_id'] = null;
        $req['lines'][0]['tax_rate'] = '0.000000';
        $req['lines'][0]['tax_inclusive'] = true;

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_unpaid_mode_with_omitted_or_null_payment_instructions(): void
    {
        // Omitted method and cash account
        $reqOmitted = $this->validRequest();
        $reqOmitted['payment'] = [
            'settlement_mode' => 'unpaid',
            'amount' => '0.000000',
        ];
        $profile1 = OnlineSaleIntentProfile::construct($this->validContext(), $reqOmitted);
        $this->assertNull($profile1['payment']['payment_method']);
        $this->assertNull($profile1['payment']['cash_account_id']);

        // Explicit null method and cash account
        $reqExplicit = $this->validRequest();
        $reqExplicit['payment'] = [
            'settlement_mode' => 'unpaid',
            'payment_method' => null,
            'cash_account_id' => null,
            'amount' => '0.000000',
        ];
        $profile2 = OnlineSaleIntentProfile::construct($this->validContext(), $reqExplicit);
        $this->assertNull($profile2['payment']['payment_method']);
        $this->assertNull($profile2['payment']['cash_account_id']);

        // Both normalize to bit-for-bit identical canonical JSON and SHA-256 digest
        $this->assertSame(
            OnlineSaleIntentProfile::serialize($profile1),
            OnlineSaleIntentProfile::serialize($profile2)
        );
        $this->assertSame(
            OnlineSaleIntentProfile::computeDigest($profile1),
            OnlineSaleIntentProfile::computeDigest($profile2)
        );
    }

    public function test_unpaid_mode_with_cash_instructions_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment'] = [
            'settlement_mode' => 'unpaid',
            'payment_method' => 'cash',
            'amount' => '0.000000',
        ];
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_full_mode_missing_payment_method_rejected(): void
    {
        $req = $this->validRequest();
        unset($req['payment']['payment_method']);
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_full_mode_null_payment_method_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment']['payment_method'] = null;
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_partial_mode_null_cash_account_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment']['settlement_mode'] = 'partial';
        $req['payment']['amount'] = '20.000000';
        $req['payment']['cash_account_id'] = null;
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_raw_json_string_with_duplicate_keys_rejected(): void
    {
        $rawJson = '{"client_event_id":"3fa85f64-5717-4562-b3fc-2c963f66afa6","currency":"ILS","currency":"USD"}';
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $rawJson);
    }

    public function test_raw_json_with_floats_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment']['amount'] = 50.0;
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }
}
