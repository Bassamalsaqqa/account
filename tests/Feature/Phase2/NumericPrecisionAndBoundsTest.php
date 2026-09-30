<?php

declare(strict_types=1);

namespace Tests\Feature\Phase2;

use App\Domain\Money\Exceptions\InvalidMoneyException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;

class NumericPrecisionAndBoundsTest extends TestCase
{
    public function test_money_amount_rejects_over_precision_on_canonical_parsing(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('exceeds maximum allowed precision of 6 decimal places');

        // 7 decimal places
        MoneyAmount::from('100.1234567');
    }

    public function test_money_amount_allows_explicit_rounding(): void
    {
        $rounded = MoneyAmount::fromRounded('100.1234567', RoundingMode::HALF_UP);
        $this->assertSame('100.123457', $rounded->toDecimalString());

        $roundedDown = MoneyAmount::fromRounded('100.1234567', RoundingMode::DOWN);
        $this->assertSame('100.123456', $roundedDown->toDecimalString());
    }

    public function test_money_amount_rejects_magnitude_overflow(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('exceeds maximum representable bounds');

        // 100 trillion (exceeds 99,999,999,999,999.999999)
        MoneyAmount::from('100000000000000.000000');
    }

    public function test_money_amount_rejects_arithmetic_overflow(): void
    {
        $max = MoneyAmount::from(MoneyAmount::MAX_MAGNITUDE);

        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('exceeds maximum representable bounds');

        $max->plus('1.000000');
    }

    public function test_exchange_rate_rejects_over_precision_on_canonical_parsing(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('exceeds maximum allowed precision of 10 decimal places');

        // 11 decimal places
        ExchangeRate::from('3.12345678901');
    }

    public function test_exchange_rate_allows_explicit_rounding(): void
    {
        $rate = ExchangeRate::fromRounded('3.12345678905', RoundingMode::HALF_UP);
        $this->assertSame('3.1234567891', $rate->toDecimalString());

        $rateDown = ExchangeRate::fromRounded('3.12345678909', RoundingMode::DOWN);
        $this->assertSame('3.1234567890', $rateDown->toDecimalString());
    }

    public function test_exchange_rate_rejects_magnitude_overflow(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('exceeds maximum representable bounds');

        // Exceeds 9,999,999,999.9999999999
        ExchangeRate::from('10000000000.0000000000');
    }

    public function test_exchange_rate_rejects_tiny_positive_value_that_rounds_to_zero(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('Exchange rate must be strictly positive (> 0)');

        // Tiny positive value with 11 decimal places that rounds to 0.0000000000
        ExchangeRate::fromRounded('0.00000000004', RoundingMode::HALF_UP);
    }

    public function test_exchange_rate_rejects_tiny_positive_value_rounding_down_to_zero(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('Exchange rate must be strictly positive (> 0)');

        ExchangeRate::fromRounded('0.00000000009', RoundingMode::DOWN);
    }
}
