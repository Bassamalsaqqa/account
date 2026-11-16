<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Contract\Revision3;

use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Contract\Revision3\Exceptions\IntentProfileValidationException;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use App\Support\Mobile\Contract\Revision3\PaymentSettlementValidator;
use PHPUnit\Framework\TestCase;

final class CrossLanguageParityTest extends TestCase
{
    private string $contractsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contractsPath = dirname(__DIR__, 5).'/contracts/mobile-revision3/docs/integration/mobile-v1';
    }

    public function test_golden_online_sale_intent_profile_digest_parity(): void
    {
        $fixturePath = $this->contractsPath.'/fixtures/golden-online-sale-intent-profile.json';
        $this->assertFileExists($fixturePath);

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $context = $fixture['context'];
        $request = $fixture['request'];
        $expectedCanonicalJson = $fixture['canonical_json'];
        $expectedDigest = $fixture['payload_digest'];

        $profile = OnlineSaleIntentProfile::construct($context, $request);

        $canonicalJson = OnlineSaleIntentProfile::serialize($profile);
        $this->assertSame($expectedCanonicalJson, $canonicalJson);

        $digest = OnlineSaleIntentProfile::computeDigest($profile);
        $this->assertSame($expectedDigest, $digest);

        [$valid, $computedDigest] = OnlineSaleIntentProfile::verify($profile, $expectedDigest);
        $this->assertTrue($valid);
        $this->assertSame($expectedDigest, $computedDigest);
    }

    public function test_golden_offline_sale_intake_v2_digest_parity(): void
    {
        $fixturePath = $this->contractsPath.'/fixtures/golden-offline-sale-intake-v2.json';
        $this->assertFileExists($fixturePath);

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $event = $fixture['request']['event'];
        $expectedDigest = $fixture['request']['payload_digest'];

        $digest = CanonicalJsonSerializer::computeDigest($event);
        $this->assertSame($expectedDigest, $digest);
    }

    public function test_revision_3_validation_cases_parity(): void
    {
        $fixturePath = $this->contractsPath.'/fixtures/revision-3-validation-cases.json';
        $this->assertFileExists($fixturePath);

        /** @var list<array<string, mixed>> $cases */
        $cases = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(31, $cases);

        $context = [
            'actor_id' => '01JCV8X9M8T1V2W3X4Y5Z67890',
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'currency_minor_units' => 2,
        ];

        $testedCount = 0;
        foreach ($cases as $case) {
            $name = $case['name'] ?? 'unnamed';
            $schema = $case['schema'] ?? '';
            $expectedValid = (bool) ($case['valid'] ?? false);
            $instance = $case['instance'];

            if ($schema === 'sale-preview-request.json') {
                $testedCount++;
                if ($expectedValid) {
                    $profile = OnlineSaleIntentProfile::construct($context, $instance);
                    $this->assertNotEmpty($profile, "Case '{$name}' should have constructed valid profile");
                } else {
                    $caught = false;
                    try {
                        OnlineSaleIntentProfile::construct($context, $instance);
                    } catch (IntentProfileValidationException) {
                        $caught = true;
                    }
                    $this->assertTrue($caught, "Case '{$name}' was expected to be rejected by schema/profile validation");
                }
            }
        }

        $this->assertGreaterThan(0, $testedCount);
    }

    public function test_mutation_tampering_rejections(): void
    {
        $fixturePath = $this->contractsPath.'/fixtures/golden-online-sale-intent-profile.json';
        /** @var array<string, mixed> $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $baseContext = $fixture['context'];
        $baseRequest = $fixture['request'];
        $baseDigest = $fixture['payload_digest'];

        // 1. Actor tampering
        $mutContext = $baseContext;
        $mutContext['actor_id'] = '01JCV8X9M8T1V2W3X4Y5Z67899';
        $profile = OnlineSaleIntentProfile::construct($mutContext, $baseRequest);
        $this->assertNotSame($baseDigest, OnlineSaleIntentProfile::computeDigest($profile));

        // 2. Company tampering
        $mutContext = $baseContext;
        $mutContext['company_id'] = '01JCV8X9M2NQW3TYZ4R5S67899';
        $profile = OnlineSaleIntentProfile::construct($mutContext, $baseRequest);
        $this->assertNotSame($baseDigest, OnlineSaleIntentProfile::computeDigest($profile));

        // 3. Device tampering
        $mutRequest = $baseRequest;
        $mutRequest['device_id'] = '8e2b8bc6-f80c-4034-8c88-59368d1f2e11';
        $profile = OnlineSaleIntentProfile::construct($baseContext, $mutRequest);
        $this->assertNotSame($baseDigest, OnlineSaleIntentProfile::computeDigest($profile));

        // 4. Client event tampering
        $mutRequest = $baseRequest;
        $mutRequest['client_event_id'] = '3fa85f64-5717-4562-b3fc-2c963f66afa7';
        $profile = OnlineSaleIntentProfile::construct($baseContext, $mutRequest);
        $this->assertNotSame($baseDigest, OnlineSaleIntentProfile::computeDigest($profile));

        // 5. Tax rate ID tampering
        $mutRequest = $baseRequest;
        $mutRequest['lines'][0]['tax_rate_id'] = '01J9Z5K3MN0000000000000031';
        $profile = OnlineSaleIntentProfile::construct($baseContext, $mutRequest);
        $this->assertNotSame($baseDigest, OnlineSaleIntentProfile::computeDigest($profile));

        // 6. Tax inclusive tampering
        $mutRequest = $baseRequest;
        $mutRequest['lines'][0]['tax_inclusive'] = false;
        $profile = OnlineSaleIntentProfile::construct($baseContext, $mutRequest);
        $this->assertNotSame($baseDigest, OnlineSaleIntentProfile::computeDigest($profile));

        // 7. Conversion to base tampering
        $mutRequest = $baseRequest;
        $mutRequest['lines'][0]['conversion_to_base'] = '2.000000';
        $profile = OnlineSaleIntentProfile::construct($baseContext, $mutRequest);
        $this->assertNotSame($baseDigest, OnlineSaleIntentProfile::computeDigest($profile));

        // 8. Decimal string lexeme tampering ('2' vs '2.000000')
        $mutRequest = $baseRequest;
        $mutRequest['lines'][0]['quantity'] = '2';
        $profile = OnlineSaleIntentProfile::construct($baseContext, $mutRequest);
        $this->assertNotSame($baseDigest, OnlineSaleIntentProfile::computeDigest($profile));

        // 9. Payment amount tampering
        $mutRequest = $baseRequest;
        $mutRequest['payment']['amount'] = '60.000000';
        $profile = OnlineSaleIntentProfile::construct($baseContext, $mutRequest);
        $this->assertNotSame($baseDigest, OnlineSaleIntentProfile::computeDigest($profile));

        // 10. Currency minor units tampering
        $mutContext = $baseContext;
        $mutContext['currency_minor_units'] = 3;
        $profile = OnlineSaleIntentProfile::construct($mutContext, $baseRequest);
        $this->assertNotSame($baseDigest, OnlineSaleIntentProfile::computeDigest($profile));
    }

    public function test_pure_line_calculator_parity(): void
    {
        $line = PaymentSettlementValidator::calculateCanonicalLine(
            '2.000000',
            '25.000000',
            '16.000000',
            true,
            2
        );

        $this->assertSame('50.000000', $line['subtotal']);
        $this->assertSame('43.100000', $line['net_before_tax']);
        $this->assertSame('6.900000', $line['tax_amount']);
        $this->assertSame('50.000000', $line['line_total']);
    }

    public function test_golden_fixture_defect_1_appended_newline_rejected(): void
    {
        $fixturePath = $this->contractsPath.'/fixtures/golden-online-sale-intent-profile.json';
        /** @var array<string, mixed> $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $mutContext = $fixture['context'];
        $mutContext['actor_id'] = $mutContext['actor_id']."\n";

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($mutContext, $fixture['request']);
    }

    public function test_golden_fixture_defect_2_lines_associative_object_rejected(): void
    {
        $fixturePath = $this->contractsPath.'/fixtures/golden-online-sale-intent-profile.json';
        /** @var array<string, mixed> $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $mutRequest = $fixture['request'];
        $mutRequest['lines'] = ['wrong-key' => $mutRequest['lines'][0]];

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($fixture['context'], $mutRequest);
    }

    public function test_golden_fixture_defect_3_unpaid_raw_payment_with_positive_amount_rejected(): void
    {
        $fixturePath = $this->contractsPath.'/fixtures/golden-online-sale-intent-profile.json';
        /** @var array<string, mixed> $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $mutRequest = $fixture['request'];
        $mutRequest['payment'] = [
            'settlement_mode' => 'unpaid',
            'amount' => '50.000000',
        ];

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::construct($fixture['context'], $mutRequest);
    }

    public function test_golden_fixture_defect_4_verify_malformed_profile_matching_own_digest_rejected(): void
    {
        $fixturePath = $this->contractsPath.'/fixtures/golden-online-sale-intent-profile.json';
        /** @var array<string, mixed> $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $profile = OnlineSaleIntentProfile::construct($fixture['context'], $fixture['request']);
        $profile['lines'] = 'invalid';
        $digest = OnlineSaleIntentProfile::computeDigest($profile);

        $this->expectException(IntentProfileValidationException::class);
        OnlineSaleIntentProfile::verify($profile, $digest);
    }

    public function test_golden_fixture_defect_5_one_argument_resolver_rejected(): void
    {
        $fixturePath = $this->contractsPath.'/fixtures/golden-online-sale-intent-profile.json';
        /** @var array<string, mixed> $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $profile = OnlineSaleIntentProfile::construct($fixture['context'], $fixture['request']);

        $oneArgResolver = fn (string $company): int => 101;

        $this->expectException(\InvalidArgumentException::class);
        PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice(
            $profile,
            '50.000000',
            2,
            '2026-11-11',
            $oneArgResolver
        );
    }
}
