<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Domain\Money\Exceptions\InvalidMoneyException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;

class MoneyValueObjectsTest extends TestCase
{
    public function test_floats_are_strictly_rejected(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('Floats are strictly prohibited');

        /** @phpstan-ignore argument.type */
        MoneyAmount::from(0.1);
    }

    public function test_exchange_rate_strictly_rejects_floats(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('Floats are strictly prohibited');

        /** @phpstan-ignore argument.type */
        ExchangeRate::from(3.55);
    }

    public function test_malformed_decimal_strings_are_rejected(): void
    {
        $this->expectException(InvalidMoneyException::class);
        MoneyAmount::from('12.34.56');
    }

    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidMoneyException::class);
        MoneyAmount::from('  ');
    }

    public function test_exact_tenth_addition_avoids_ieee_float_drift(): void
    {
        // In IEEE float: 0.1 + 0.2 = 0.30000000000000004
        $a = MoneyAmount::from('0.1');
        $b = MoneyAmount::from('0.2');

        $sum = $a->plus($b);

        $this->assertSame('0.300000', $sum->toDecimalString());
        $this->assertSame('0.30', $sum->formatForCurrency('ILS'));
    }

    public function test_six_decimal_internal_precision_is_preserved(): void
    {
        $amount = MoneyAmount::from('123456789.123456');

        $this->assertSame('123456789.123456', $amount->toDecimalString());
        $this->assertSame('123456789.123456', (string) $amount);
    }

    public function test_ten_decimal_exchange_rate_precision(): void
    {
        $rate = ExchangeRate::from('3.5123456789');

        $this->assertSame('3.5123456789', $rate->toDecimalString());
        $this->assertSame('3.5123456789', (string) $rate);
    }

    public function test_currency_minor_unit_display_formatting(): void
    {
        $amount = MoneyAmount::from('150.876543');

        // ILS & USD have 2 decimals (HALF_UP rounds .876543 to .88)
        $this->assertSame('150.88', $amount->formatForCurrency('ILS'));
        $this->assertSame('150.88', $amount->formatForCurrency('USD'));

        // JOD has 3 decimals (HALF_UP rounds .876543 to .877)
        $this->assertSame('150.877', $amount->formatForCurrency('JOD'));

        // Underlying precision remains intact
        $this->assertSame('150.876543', $amount->toDecimalString());
    }

    public function test_arithmetic_operations(): void
    {
        $m1 = MoneyAmount::from('100.000000');
        $m2 = MoneyAmount::from('30.500000');

        $this->assertSame('130.500000', $m1->plus($m2)->toDecimalString());
        $this->assertSame('69.500000', $m1->minus($m2)->toDecimalString());
        $this->assertSame('-100.000000', $m1->negated()->toDecimalString());
        $this->assertSame('100.000000', $m1->negated()->abs()->toDecimalString());

        // Multiply and Divide
        $this->assertSame('250.000000', $m1->multipliedBy('2.5')->toDecimalString());
        $this->assertSame('33.333333', $m1->dividedBy('3', 6, RoundingMode::HALF_UP)->toDecimalString());
    }

    public function test_comparison_methods(): void
    {
        $low = MoneyAmount::from('10.000000');
        $high = MoneyAmount::from('20.000000');
        $same = MoneyAmount::from('10.000000');

        $this->assertTrue($high->isGreaterThan($low));
        $this->assertTrue($low->isLessThan($high));
        $this->assertTrue($low->isEqualTo($same));
        $this->assertTrue($low->isGreaterThanOrEqualTo($same));
        $this->assertTrue($low->isLessThanOrEqualTo($high));
        $this->assertFalse($low->isZero());
        $this->assertTrue(MoneyAmount::zero()->isZero());
        $this->assertTrue($high->isPositive());
        $this->assertTrue($low->negated()->isNegative());
    }

    public function test_universal_fx_conversion_convention(): void
    {
        // Convention: Base units per 1 Transaction unit
        // Base = ILS, Transaction = USD, Rate = 3.5000000000 ILS/USD
        $rate = ExchangeRate::from('3.5000000000');
        $usdAmount = MoneyAmount::from('100.000000');

        // toBase: 100 USD * 3.5000000000 = 350.000000 ILS
        $baseIls = $rate->toBase($usdAmount);
        $this->assertSame('350.000000', $baseIls->toDecimalString());

        // toTransaction: 350 ILS / 3.5000000000 = 100.000000 USD
        $backToUsd = $rate->toTransaction($baseIls);
        $this->assertSame('100.000000', $backToUsd->toDecimalString());
    }

    public function test_negative_exchange_rate_is_rejected(): void
    {
        $this->expectException(InvalidMoneyException::class);
        ExchangeRate::from('-1.25');
    }

    public function test_zero_exchange_rate_is_rejected(): void
    {
        $this->expectException(InvalidMoneyException::class);
        ExchangeRate::from('0');
    }
}
