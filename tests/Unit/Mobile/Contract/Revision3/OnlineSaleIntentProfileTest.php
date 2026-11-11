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

    // --- Defect 1: Terminal newline and absolute anchor regressions ---

    public function test_terminal_newline_rejected_in_actor_id(): void
    {
        $ctx = $this->validContext();
        $ctx['actor_id'] = "01JCV8X9M8T1V2W3X4Y5Z67890\n";
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($ctx, $this->validRequest());
    }

    public function test_terminal_newline_rejected_in_company_id(): void
    {
        $ctx = $this->validContext();
        $ctx['company_id'] = "01JCV8X9M2NQW3TYZ4R5S6789A\n";
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($ctx, $this->validRequest());
    }

    public function test_terminal_newline_rejected_in_request_ids_and_currency(): void
    {
        $fields = [
            'client_event_id' => "3fa85f64-5717-4562-b3fc-2c963f66afa6\n",
            'device_id' => "8e2b8bc6-f80c-4034-8c88-59368d1f2e10\n",
            'customer_id' => "01JCV8X9M4K1P2Q3R4S5T6V7W8\n",
            'warehouse_id' => "01JCV8X9M9A2B3C4D5E6F7G8H9\n",
            'currency' => "ILS\n",
        ];

        foreach ($fields as $key => $val) {
            $req = $this->validRequest();
            $req[$key] = $val;
            $caught = false;
            try {
                OnlineSaleIntentProfile::construct($this->validContext(), $req);
            } catch (IntentProfileValidationException) {
                $caught = true;
            }
            $this->assertTrue($caught, "Field {$key} with terminal newline must be rejected");
        }
    }

    public function test_terminal_newline_rejected_in_line_decimals_and_ids(): void
    {
        $lineMutations = [
            'product_id' => "01JCV8X9M6A1B2C3D4E5F6G7H8\n",
            'product_unit_id' => "01JCV8X9MBV4W5X6Y7Z8012345\n",
            'conversion_to_base' => "1.000000\n",
            'quantity' => "2.000000\n",
            'unit_price' => "25.000000\n",
            'tax_rate_id' => "01J9Z5K3MN0000000000000030\n",
            'tax_rate' => "16.000000\n",
        ];

        foreach ($lineMutations as $key => $val) {
            $req = $this->validRequest();
            $req['lines'][0][$key] = $val;
            $caught = false;
            try {
                OnlineSaleIntentProfile::construct($this->validContext(), $req);
            } catch (IntentProfileValidationException) {
                $caught = true;
            }
            $this->assertTrue($caught, "Line field {$key} with terminal newline must be rejected");
        }
    }

    public function test_terminal_newline_rejected_in_payment_amount_and_account(): void
    {
        $req1 = $this->validRequest();
        $req1['payment']['amount'] = "50.000000\n";
        $caught1 = false;
        try {
            OnlineSaleIntentProfile::construct($this->validContext(), $req1);
        } catch (IntentProfileValidationException) {
            $caught1 = true;
        }
        $this->assertTrue($caught1, 'Payment amount with terminal newline must be rejected');

        $req2 = $this->validRequest();
        $req2['payment']['cash_account_id'] = "01JCV8X9MAJ3K4M5N6P7Q8R9S0\n";
        $caught2 = false;
        try {
            OnlineSaleIntentProfile::construct($this->validContext(), $req2);
        } catch (IntentProfileValidationException) {
            $caught2 = true;
        }
        $this->assertTrue($caught2, 'Payment cash_account_id with terminal newline must be rejected');
    }

    public function test_raw_json_with_terminal_newlines_rejected(): void
    {
        $raw = json_encode($this->validRequest(), JSON_THROW_ON_ERROR);
        $rawNewline = str_replace('"ILS"', '"ILS\n"', $raw);
        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $rawNewline);
    }

    public function test_unicode_and_control_characters_rejected(): void
    {
        // Negative quantity
        $reqNeg = $this->validRequest();
        $reqNeg['lines'][0]['quantity'] = '-2.000000';
        $caught = false;
        try {
            OnlineSaleIntentProfile::construct($this->validContext(), $reqNeg);
        } catch (IntentProfileValidationException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'Negative decimal quantity must be rejected');

        // Control char in currency
        $reqCtl = $this->validRequest();
        $reqCtl['currency'] = "IL\x00";
        $caught = false;
        try {
            OnlineSaleIntentProfile::construct($this->validContext(), $reqCtl);
        } catch (IntentProfileValidationException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'Control character in currency must be rejected');
    }

    // --- Defect 2: AST shape validation and list/associative rejections ---

    public function test_raw_json_lines_as_associative_object_rejected(): void
    {
        $req = $this->validRequest();
        $raw = json_encode($req, JSON_THROW_ON_ERROR);
        // Replace "lines":[{...}] with "lines":{"wrong-key":{...}}
        $rawObj = preg_replace('/"lines":\s*\[/', '"lines":{"wrong-key":', $raw, 1);
        $rawObj = preg_replace('/\]\s*,\s*"payment"/', '},"payment"', (string) $rawObj, 1);

        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage("Property 'lines' must be a JSON array");
        OnlineSaleIntentProfile::construct($this->validContext(), (string) $rawObj);
    }

    public function test_raw_json_lines_as_numeric_key_object_rejected(): void
    {
        $req = $this->validRequest();
        $line = $req['lines'][0];
        $raw = json_encode([
            'client_event_id' => $req['client_event_id'],
            'device_id' => $req['device_id'],
            'customer_id' => $req['customer_id'],
            'warehouse_id' => $req['warehouse_id'],
            'currency' => $req['currency'],
            'lines' => ['0' => $line],
            'payment' => $req['payment'],
        ], JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $raw);
    }

    public function test_native_array_lines_nonlist_rejected(): void
    {
        $req = $this->validRequest();
        $req['lines'] = ['wrong-key' => $req['lines'][0]];

        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage("Property 'lines' must be a list of line items");
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_native_array_line_item_non_associative_rejected(): void
    {
        $req = $this->validRequest();
        $req['lines'][0] = ['not', 'an', 'associative', 'object'];

        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage('Line item at index 0 must be an associative object');
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_native_array_payment_non_associative_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment'] = ['full', 'cash', '50.000000'];

        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage('Payment must be an associative object');
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_native_array_malformed_utf8_rejected(): void
    {
        $req = $this->validRequest();
        $req['currency'] = "\xC3\x28"; // Invalid 2-octet sequence

        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage('Invalid UTF-8 string');
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_native_array_unicode_surrogate_rejected(): void
    {
        $req = $this->validRequest();
        $req['currency'] = "\xED\xA0\x80"; // CESU-8 high surrogate U+D800

        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage('Forbidden Unicode surrogate detected');
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_native_array_arbitrary_object_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment'] = (object) $req['payment'];

        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage('Forbidden object detected');
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    // --- Defect 3: Unpaid raw payment positive amount & schema enforcement ---

    public function test_unpaid_raw_payment_with_positive_amount_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment'] = [
            'settlement_mode' => 'unpaid',
            'amount' => '50.000000',
        ];

        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage("Unpaid settlement mode requires amount to be zero, got '50.000000'");
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_unpaid_raw_payment_with_subcent_positive_amount_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment'] = [
            'settlement_mode' => 'unpaid',
            'amount' => '0.000001',
        ];

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_full_raw_payment_with_zero_amount_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment'] = [
            'settlement_mode' => 'full',
            'payment_method' => 'cash',
            'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
            'amount' => '0.000000',
        ];

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_partial_raw_payment_with_zero_amount_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment'] = [
            'settlement_mode' => 'partial',
            'payment_method' => 'cash',
            'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
            'amount' => '0.000000',
        ];

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    public function test_unpaid_mode_with_non_null_cash_account_rejected(): void
    {
        $req = $this->validRequest();
        $req['payment'] = [
            'settlement_mode' => 'unpaid',
            'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
            'amount' => '0.000000',
        ];

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($this->validContext(), $req);
    }

    // --- Defect 4: Verify normative profile deep validation ---

    public function test_verify_rejects_malformed_lines_matching_own_digest(): void
    {
        $profile = OnlineSaleIntentProfile::construct($this->validContext(), $this->validRequest());
        // Mutate profile lines to an invalid string
        $profile['lines'] = 'invalid';
        $digest = OnlineSaleIntentProfile::computeDigest($profile);

        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage('Profile lines must be a non-empty list of at most 100 items');
        OnlineSaleIntentProfile::verify($profile, $digest);
    }

    public function test_verify_rejects_profile_missing_required_payment_keys(): void
    {
        $profile = OnlineSaleIntentProfile::construct($this->validContext(), $this->validRequest());
        unset($profile['payment']['cash_account_id']);
        $digest = OnlineSaleIntentProfile::computeDigest($profile);

        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage('Profile payment must explicitly contain exactly: settlement_mode, payment_method, cash_account_id, amount');
        OnlineSaleIntentProfile::verify($profile, $digest);
    }

    public function test_verify_rejects_unpaid_profile_with_non_null_payment_keys(): void
    {
        $req = $this->validRequest();
        $req['payment'] = ['settlement_mode' => 'unpaid', 'amount' => '0.000000'];
        $profile = OnlineSaleIntentProfile::construct($this->validContext(), $req);
        $profile['payment']['payment_method'] = 'cash';
        $digest = OnlineSaleIntentProfile::computeDigest($profile);

        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage('Profile unpaid payment requires payment_method to be explicit null');
        OnlineSaleIntentProfile::verify($profile, $digest);
    }

    public function test_verify_rejects_profile_with_nonlist_lines(): void
    {
        $profile = OnlineSaleIntentProfile::construct($this->validContext(), $this->validRequest());
        $line = $profile['lines'][0];
        $profile['lines'] = ['wrong-key' => $line];
        $digest = OnlineSaleIntentProfile::computeDigest($profile);

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::verify($profile, $digest);
    }

    public function test_verify_rejects_profile_with_invalid_tax_rate(): void
    {
        $profile = OnlineSaleIntentProfile::construct($this->validContext(), $this->validRequest());
        $profile['lines'][0]['tax_rate'] = '150.000000'; // > 100
        $digest = OnlineSaleIntentProfile::computeDigest($profile);

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::verify($profile, $digest);
    }

    public function test_verify_rejects_profile_with_negative_quantity(): void
    {
        $profile = OnlineSaleIntentProfile::construct($this->validContext(), $this->validRequest());
        $profile['lines'][0]['quantity'] = '-2.000000';
        $digest = OnlineSaleIntentProfile::computeDigest($profile);

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::verify($profile, $digest);
    }

    public function test_verify_rejects_profile_with_invalid_currency(): void
    {
        $profile = OnlineSaleIntentProfile::construct($this->validContext(), $this->validRequest());
        $profile['currency'] = 'US';
        $digest = OnlineSaleIntentProfile::computeDigest($profile);

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::verify($profile, $digest);
    }

    public function test_verify_rejects_profile_with_extra_root_keys(): void
    {
        $profile = OnlineSaleIntentProfile::construct($this->validContext(), $this->validRequest());
        $profile['tampered_extra'] = 'unauthorized';
        $digest = OnlineSaleIntentProfile::computeDigest($profile);

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::verify($profile, $digest);
    }

    public function test_native_cycle_is_rejected_with_bounded_depth(): void
    {
        $cycle = [];
        $cycle['self'] = &$cycle;
        $request = $this->validRequest();
        $request['cycle'] = $cycle;
        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage('Maximum structure nesting depth exceeded');
        OnlineSaleIntentProfile::construct($this->validContext(), $request);
    }

    public function test_native_deep_structure_is_rejected_with_bounded_depth(): void
    {
        $deep = [];
        for ($i = 0; $i < 34; $i++) {
            $deep = ['nested' => $deep];
        }
        $request = $this->validRequest();
        $request['deep'] = $deep;
        $this->expectException(IntentProfileValidationException::class);
        $this->expectExceptionMessage('Maximum structure nesting depth exceeded');
        OnlineSaleIntentProfile::construct($this->validContext(), $request);
    }
}
