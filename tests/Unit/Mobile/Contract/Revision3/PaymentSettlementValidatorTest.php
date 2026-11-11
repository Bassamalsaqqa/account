<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Contract\Revision3;

use App\Support\Mobile\Contract\Revision3\PaymentSettlementValidator;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PaymentSettlementValidatorTest extends TestCase
{
    public function test_validate_currency_minor_units(): void
    {
        $this->assertTrue(PaymentSettlementValidator::validateCurrencyMinorUnits(0));
        $this->assertTrue(PaymentSettlementValidator::validateCurrencyMinorUnits(2));
        $this->assertTrue(PaymentSettlementValidator::validateCurrencyMinorUnits(3));
        $this->assertTrue(PaymentSettlementValidator::validateCurrencyMinorUnits(6));

        // Rejections: bool, negative, >6, string, null
        $this->assertFalse(PaymentSettlementValidator::validateCurrencyMinorUnits(true));
        $this->assertFalse(PaymentSettlementValidator::validateCurrencyMinorUnits(false));
        $this->assertFalse(PaymentSettlementValidator::validateCurrencyMinorUnits(-1));
        $this->assertFalse(PaymentSettlementValidator::validateCurrencyMinorUnits(7));
        $this->assertFalse(PaymentSettlementValidator::validateCurrencyMinorUnits('2'));
        $this->assertFalse(PaymentSettlementValidator::validateCurrencyMinorUnits(null));
    }

    public function test_parse_and_validate_monetary_amount(): void
    {
        $amt = PaymentSettlementValidator::parseAndValidateMonetaryAmount('50.000000', 2);
        $this->assertInstanceOf(BigDecimal::class, $amt);
        $this->assertTrue($amt->isEqualTo('50'));

        // Trailing zeros representing integer currency minor units accepted
        $this->assertInstanceOf(BigDecimal::class, PaymentSettlementValidator::parseAndValidateMonetaryAmount('20.500000', 2));
        $this->assertInstanceOf(BigDecimal::class, PaymentSettlementValidator::parseAndValidateMonetaryAmount('0.010000', 2));
        $this->assertInstanceOf(BigDecimal::class, PaymentSettlementValidator::parseAndValidateMonetaryAmount('0.001000', 3));
        $this->assertInstanceOf(BigDecimal::class, PaymentSettlementValidator::parseAndValidateMonetaryAmount('0.000001', 6));

        // Overprecision beyond currency minor units strictly rejected without rounding
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('0.000001', 2));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('10.005000', 2));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('10.000500', 3));

        // Non-finite, scientific, negative, floats, or non-strings fail closed
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('NaN', 2));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('Infinity', 2));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('-Infinity', 2));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('1e5', 2));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('+50.00', 2));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('-50.00', 2));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount(50.0, 2));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount(true, 2));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount(null, 2));

        // Terminal newline rejected (Defect 1)
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount("50.000000\n", 2));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount("0.000000\n", 2));

        // Invalid cmu boundary claims
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('50.000000', -1));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('50.000000', 7));
        $this->assertNull(PaymentSettlementValidator::parseAndValidateMonetaryAmount('50.000000', true));
    }

    public function test_calculate_canonical_line(): void
    {
        // 2 items * 25.000000 @ 16% inclusive, cmu 2
        $resInc = PaymentSettlementValidator::calculateCanonicalLine('2.000000', '25.000000', '16.000000', true, 2);
        $this->assertSame('50.000000', $resInc['subtotal']);
        $this->assertSame('43.100000', $resInc['net_before_tax']);
        $this->assertSame('6.900000', $resInc['tax_amount']);
        $this->assertSame('50.000000', $resInc['line_total']);

        // Exclusive tax
        $resExc = PaymentSettlementValidator::calculateCanonicalLine('2.000000', '25.000000', '16.000000', false, 2);
        $this->assertSame('50.000000', $resExc['subtotal']);
        $this->assertSame('50.000000', $resExc['net_before_tax']);
        $this->assertSame('8.000000', $resExc['tax_amount']);
        $this->assertSame('58.000000', $resExc['line_total']);

        // Strict boundary validations (Defect 1 and review claims)
        $invalidCalls = [
            fn () => PaymentSettlementValidator::calculateCanonicalLine("2.000000\n", '25.000000', '16.000000', true, 2),
            fn () => PaymentSettlementValidator::calculateCanonicalLine('2.000000', "25.000000\n", '16.000000', true, 2),
            fn () => PaymentSettlementValidator::calculateCanonicalLine('2.000000', '25.000000', "16.000000\n", true, 2),
            fn () => PaymentSettlementValidator::calculateCanonicalLine('2.000000', '25.000000', '16.000000', true, -1),
            fn () => PaymentSettlementValidator::calculateCanonicalLine('2.000000', '25.000000', '16.000000', true, 7),
            fn () => PaymentSettlementValidator::calculateCanonicalLine('-2.000000', '25.000000', '16.000000', true, 2),
            fn () => PaymentSettlementValidator::calculateCanonicalLine('0.000000', '25.000000', '16.000000', true, 2),
            fn () => PaymentSettlementValidator::calculateCanonicalLine('2.000000', '-25.000000', '16.000000', true, 2),
            fn () => PaymentSettlementValidator::calculateCanonicalLine('2.000000', '25.000000', '100.000001', true, 2),
            fn () => PaymentSettlementValidator::calculateCanonicalLine('1e5', '25.000000', '16.000000', true, 2),
        ];

        foreach ($invalidCalls as $idx => $call) {
            $caught = false;
            try {
                $call();
            } catch (InvalidArgumentException) {
                $caught = true;
            }
            $this->assertTrue($caught, "calculateCanonicalLine invalid call at index {$idx} must throw InvalidArgumentException");
        }
    }

    public function test_verify_payment_decimal_consistency_offline(): void
    {
        $validTender = [
            'mode' => 'full',
            'amount_tendered' => '60.000000',
            'amount_settled' => '50.000000',
            'change_returned' => '10.000000',
        ];
        $this->assertTrue(PaymentSettlementValidator::verifyPaymentDecimalConsistency($validTender, '50.000000', 2));

        // Mismatched tender conservation: 50 + 5 != 60
        $badConservation = [
            'mode' => 'full',
            'amount_tendered' => '60.000000',
            'amount_settled' => '50.000000',
            'change_returned' => '5.000000',
        ];
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency($badConservation, '50.000000', 2));

        // Settled != grand total
        $badSettled = [
            'mode' => 'full',
            'amount_tendered' => '40.000000',
            'amount_settled' => '40.000000',
            'change_returned' => '0.000000',
        ];
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency($badSettled, '50.000000', 2));

        // Zero grand total rejected under first-release positive financial grand total policy
        $zeroOffline = [
            'mode' => 'full',
            'amount_tendered' => '0.000000',
            'amount_settled' => '0.000000',
            'change_returned' => '0.000000',
        ];
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency($zeroOffline, '0.000000', 2));

        // Zero settled with non-zero tendered rejected
        $zeroSettledOffline = [
            'mode' => 'full',
            'amount_tendered' => '10.000000',
            'amount_settled' => '0.000000',
            'change_returned' => '10.000000',
        ];
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency($zeroSettledOffline, '0.000000', 2));
    }

    public function test_verify_payment_decimal_consistency_online(): void
    {
        // Unpaid
        $this->assertTrue(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'unpaid', 'amount' => '0.000000'], '50.000000', 2));
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'unpaid', 'amount' => '10.000000'], '50.000000', 2));
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'unpaid', 'amount' => '0.000000'], '0.000000', 2)); // Zero grand total rejected

        // Partial
        $this->assertTrue(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'partial', 'amount' => '20.000000'], '50.000000', 2));
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'partial', 'amount' => '0.000000'], '50.000000', 2));
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'partial', 'amount' => '50.000000'], '50.000000', 2));
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'partial', 'amount' => '60.000000'], '50.000000', 2));

        // Full
        $this->assertTrue(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'full', 'amount' => '50.000000'], '50.000000', 2));
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'full', 'amount' => '40.000000'], '50.000000', 2)); // underpayment rejected
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'full', 'amount' => '60.000000'], '50.000000', 2)); // overpayment rejected
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'full', 'amount' => '0.000000'], '0.000000', 2)); // Zero grand total rejected
        $this->assertFalse(PaymentSettlementValidator::verifyPaymentDecimalConsistency(['settlement_mode' => 'full', 'amount' => '-50.000000'], '-50.000000', 2)); // Negative total rejected
    }

    public function test_adapt_offline_cash_to_canonical_receipt(): void
    {
        $tender = [
            'mode' => 'full',
            'amount_tendered' => '60.000000',
            'amount_settled' => '50.000000',
            'change_returned' => '10.000000',
            'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
        ];

        // Audit-only mode
        $receipt = PaymentSettlementValidator::adaptOfflineCashToCanonicalReceipt($tender, '50.000000', 2);
        // Net retained settled amount, never gross tendered amount
        $this->assertSame('50.000000', $receipt['receipt_amount']);
        $this->assertSame('50.000000', $receipt['receipt_command']['receipt_amount']);
        $this->assertSame('60.000000', $receipt['audit']['amount_tendered']);
        $this->assertSame('10.000000', $receipt['audit']['change_returned']);
        $this->assertArrayNotHasKey('amount_tendered', $receipt['receipt_command']);
        $this->assertArrayNotHasKey('change_returned', $receipt['receipt_command']);
        $this->assertArrayNotHasKey('canonical_command', $receipt);

        // Parameterized resolution
        $resolver = function (string $companyId, string $cashAccountUlid): int {
            if ($companyId === '01JCV8X9M2NQW3TYZ4R5S6789A' && $cashAccountUlid === '01JCV8X9MAJ3K4M5N6P7Q8R9S0') {
                return 101;
            }
            throw new InvalidArgumentException('Unknown account');
        };

        $parameterized = PaymentSettlementValidator::adaptOfflineCashToCanonicalReceipt(
            $tender,
            '50.000000',
            2,
            '2026-11-11',
            $resolver,
            '01JCV8X9M2NQW3TYZ4R5S6789A',
            42
        );

        $this->assertArrayHasKey('canonical_command', $parameterized);
        $this->assertSame(42, $parameterized['canonical_command']['customer_id']);
        $this->assertSame(101, $parameterized['canonical_command']['money_account_id']);
        $this->assertSame('2026-11-11', $parameterized['canonical_command']['payment_date']);
        $this->assertSame('50.000000', $parameterized['canonical_command']['amount']);
    }

    public function test_adapt_offline_cash_fails_closed_on_incomplete_command_context(): void
    {
        $tender = [
            'mode' => 'full',
            'amount_tendered' => '60.000000',
            'amount_settled' => '50.000000',
            'change_returned' => '10.000000',
            'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
        ];
        $resolver = fn (string $c, string $w): int => 101;

        // Date supplied, resolver missing
        $caught1 = false;
        try {
            PaymentSettlementValidator::adaptOfflineCashToCanonicalReceipt($tender, '50.000000', 2, '2026-11-11', null, '01JCV8X9M2NQW3TYZ4R5S6789A', 42);
        } catch (InvalidArgumentException) {
            $caught1 = true;
        }
        $this->assertTrue($caught1);

        // Resolver supplied, date missing
        $caught2 = false;
        try {
            PaymentSettlementValidator::adaptOfflineCashToCanonicalReceipt($tender, '50.000000', 2, null, $resolver, '01JCV8X9M2NQW3TYZ4R5S6789A', 42);
        } catch (InvalidArgumentException) {
            $caught2 = true;
        }
        $this->assertTrue($caught2);

        // Company missing
        $caught3 = false;
        try {
            PaymentSettlementValidator::adaptOfflineCashToCanonicalReceipt($tender, '50.000000', 2, '2026-11-11', $resolver, null, 42);
        } catch (InvalidArgumentException) {
            $caught3 = true;
        }
        $this->assertTrue($caught3);

        // Customer missing
        $caught4 = false;
        try {
            PaymentSettlementValidator::adaptOfflineCashToCanonicalReceipt($tender, '50.000000', 2, '2026-11-11', $resolver, '01JCV8X9M2NQW3TYZ4R5S6789A', null);
        } catch (InvalidArgumentException) {
            $caught4 = true;
        }
        $this->assertTrue($caught4);

        // Date with terminal newline
        $caught5 = false;
        try {
            PaymentSettlementValidator::adaptOfflineCashToCanonicalReceipt($tender, '50.000000', 2, "2026-11-11\n", $resolver, '01JCV8X9M2NQW3TYZ4R5S6789A', 42);
        } catch (InvalidArgumentException) {
            $caught5 = true;
        }
        $this->assertTrue($caught5);
    }

    public function test_adapt_online_payment_to_canonical_choice(): void
    {
        $resolver = function (string $companyId, string $cashAccountUlid): int {
            if ($companyId === '01JCV8X9M2NQW3TYZ4R5S6789A' && $cashAccountUlid === '01JCV8X9MAJ3K4M5N6P7Q8R9S0') {
                return 101;
            }
            throw new InvalidArgumentException('Unknown or foreign account');
        };

        // Unpaid: strips wire fields, returns EXACTLY ['mode' => 'unpaid'], no resolver or date needed
        $unpaidProfile = [
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'payment' => [
                'settlement_mode' => 'unpaid',
                'payment_method' => null,
                'cash_account_id' => null,
                'amount' => '0.000000',
            ],
        ];
        $choiceUnpaid = PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice($unpaidProfile, '50.000000', 2);
        $this->assertSame(['mode' => 'unpaid'], $choiceUnpaid);
        // Wire profile unmodified
        $this->assertSame('0.000000', $unpaidProfile['payment']['amount']);

        // Partial
        $partialProfile = [
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'payment' => [
                'settlement_mode' => 'partial',
                'payment_method' => 'cash',
                'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
                'amount' => '20.000000',
            ],
        ];
        $choicePartial = PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice(
            $partialProfile,
            '50.000000',
            2,
            '2026-11-11',
            $resolver
        );
        $this->assertSame('partial', $choicePartial['mode']);
        $this->assertSame(101, $choicePartial['money_account_id']);
        $this->assertSame('20.000000', $choicePartial['amount']);
        $this->assertSame('20.000000', $choicePartial['invoice_amount']);
        $this->assertSame('1.0000000000', $choicePartial['exchange_rate']);
        $this->assertSame('2026-11-11', $choicePartial['payment_date']);

        // Full exact
        $fullProfile = [
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
                'amount' => '50.000000',
            ],
        ];
        $choiceFull = PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice(
            $fullProfile,
            '50.000000',
            2,
            '2026-11-11',
            $resolver
        );
        $this->assertSame('full', $choiceFull['mode']);
        $this->assertSame(101, $choiceFull['money_account_id']);
        $this->assertSame('50.000000', $choiceFull['amount']);
        $this->assertSame('50.000000', $choiceFull['invoice_amount']);

        // Full overpayment rejected
        $overProfile = [
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
                'amount' => '60.000000',
            ],
        ];
        $this->expectException(InvalidArgumentException::class);
        PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice(
            $overProfile,
            '50.000000',
            2,
            '2026-11-11',
            $resolver
        );
    }

    // --- Defect 5: Resolver reflection, signature, result, and redaction tests ---

    public function test_adapt_online_payment_rejects_one_argument_closure(): void
    {
        $profile = [
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
                'amount' => '50.000000',
            ],
        ];

        // One-argument closure must be rejected via reflection
        $oneArgResolver = fn (string $company): int => 101;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cash account resolver must accept both company_id and wire_account arguments');
        PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice(
            $profile,
            '50.000000',
            2,
            '2026-11-11',
            $oneArgResolver
        );
    }

    public function test_adapt_online_payment_rejects_three_required_arguments_closure(): void
    {
        $profile = [
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
                'amount' => '50.000000',
            ],
        ];

        $threeArgResolver = fn (string $company, string $wire, string $extra): int => 101;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cash account resolver requires more than 2 parameters');
        PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice(
            $profile,
            '50.000000',
            2,
            '2026-11-11',
            $threeArgResolver
        );
    }

    public function test_adapt_online_payment_rejects_boolean_resolver_result(): void
    {
        $profile = [
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
                'amount' => '50.000000',
            ],
        ];

        $boolResolver = fn (string $company, string $wire): bool => false;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Resolved money_account_id must be a positive integer');
        PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice(
            $profile,
            '50.000000',
            2,
            '2026-11-11',
            $boolResolver
        );
    }

    public function test_adapt_online_payment_rejects_nonpositive_resolver_result(): void
    {
        $profile = [
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
                'amount' => '50.000000',
            ],
        ];

        $zeroResolver = fn (string $company, string $wire): int => 0;

        $caught = false;
        try {
            PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice($profile, '50.000000', 2, '2026-11-11', $zeroResolver);
        } catch (InvalidArgumentException $e) {
            $caught = true;
            $this->assertStringContainsString('Resolved money_account_id must be a positive integer', $e->getMessage());
        }
        $this->assertTrue($caught);

        $negResolver = fn (string $company, string $wire): int => -10;
        $caughtNeg = false;
        try {
            PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice($profile, '50.000000', 2, '2026-11-11', $negResolver);
        } catch (InvalidArgumentException $e) {
            $caughtNeg = true;
            $this->assertStringContainsString('Resolved money_account_id must be a positive integer', $e->getMessage());
        }
        $this->assertTrue($caughtNeg);
    }

    public function test_adapt_online_payment_redacts_resolver_error_without_secrets_leak(): void
    {
        $profile = [
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
                'amount' => '50.000000',
            ],
        ];

        $throwingResolver = function (string $c, string $w): int {
            throw new RuntimeException('Connection failed with password=SuperSecretPassword123! and BearerToken=SecretToken');
        };

        try {
            PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice(
                $profile,
                '50.000000',
                2,
                '2026-11-11',
                $throwingResolver
            );
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $msg = $e->getMessage();
            $this->assertSame('Cash account resolution failed', $msg);
            $this->assertStringNotContainsString('SuperSecretPassword123!', $msg);
            $this->assertStringNotContainsString('SecretToken', $msg);
        }
    }

    public function test_adapt_online_payment_rejects_terminal_newline_in_payment_date(): void
    {
        $profile = [
            'company_id' => '01JCV8X9M2NQW3TYZ4R5S6789A',
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => '01JCV8X9MAJ3K4M5N6P7Q8R9S0',
                'amount' => '50.000000',
            ],
        ];
        $resolver = fn (string $c, string $w): int => 101;

        $this->expectException(InvalidArgumentException::class);
        PaymentSettlementValidator::adaptOnlinePaymentToCanonicalChoice(
            $profile,
            '50.000000',
            2,
            "2026-11-11\n",
            $resolver
        );
    }
}
