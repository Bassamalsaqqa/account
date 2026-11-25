<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sales\Formatters;

use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SalesMoneyFormatterTest extends TestCase
{
    #[DataProvider('moneyDisplayCases')]
    public function test_display_money_enforces_two_decimal_policy_across_currencies(
        ?string $amount,
        ?string $currencyCode,
        string $expected
    ): void {
        self::assertSame($expected, SalesMoneyFormatter::displayMoney($amount, $currencyCode));
    }

    /**
     * @return array<string, array{string|null, ?string, string}>
     */
    public static function moneyDisplayCases(): array
    {
        return [
            'ils integer' => ['280.000000', 'ILS', '280.00'],
            'jod three decimals truncated/rounded to two for human display' => ['280.001000', 'JOD', '280.00'],
            'jod fractional 9.833 becomes 9.83 in UI display' => ['9.833000', 'JOD', '9.83'],
            'bhd one fil becomes 0.00' => ['0.001000', 'BHD', '0.00'],
            'usd cents' => ['12.340000', 'USD', '12.34'],
            'round half up check' => ['12.345000', 'USD', '12.35'],
            'large amount with commas' => ['9007199254740993.125000', 'USD', '9,007,199,254,740,993.13'],
            'explicit zero' => ['0', 'JOD', '0.00'],
            'negative amount' => ['-12.340000', 'ILS', '-12.34'],
            'negative zero rounded' => ['-0.000001', 'ILS', '0.00'],
            'null value' => [null, 'ILS', '—'],
            'empty string' => ['', 'USD', '—'],
            'non-numeric string untouched' => ['Restricted', 'ILS', 'Restricted'],
        ];
    }

    public function test_display_money_accepts_scale_parameter_without_violating_two_decimal_policy(): void
    {
        // Even if callers from prior variable-scale attempts pass scale: 3, displayMoney enforces TWO decimals
        self::assertSame('9.83', SalesMoneyFormatter::displayMoney('9.833000', 'JOD', 3));
        self::assertSame('280.00', SalesMoneyFormatter::displayMoney('280.000000', scale: 3));
        self::assertSame('—', SalesMoneyFormatter::displayMoney(null, scale: 3));
    }

    #[DataProvider('moneyInputCases')]
    public function test_format_money_input_preserves_exact_fractional_precision_with_two_decimal_minimum(
        ?string $input,
        string $expected
    ): void {
        self::assertSame($expected, SalesMoneyFormatter::formatMoneyInput($input));
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function moneyInputCases(): array
    {
        return [
            'integer input' => ['100', '100.00'],
            'single decimal' => ['100.5', '100.50'],
            'two decimals' => ['100.50', '100.50'],
            'trailing zeros trimmed to two' => ['100.000000', '100.00'],
            'three decimal unit price preserved without silent rounding' => ['100.125', '100.125'],
            'six decimal cost preserved' => ['280.123456', '280.123456'],
            'historical jod unit cost preserved' => ['9.833', '9.833'],
            'null input' => [null, ''],
            'empty input' => ['', ''],
            'whitespace input' => ['   ', ''],
            'non-numeric input untouched' => ['abc', 'abc'],
        ];
    }

    public function test_format_quantity_trims_trailing_zeros_without_loss(): void
    {
        self::assertSame('10', SalesMoneyFormatter::formatQuantity('10.000000'));
        self::assertSame('10.5', SalesMoneyFormatter::formatQuantity('10.500000'));
        self::assertSame('0.125', SalesMoneyFormatter::formatQuantity('0.125000'));
        self::assertSame('0', SalesMoneyFormatter::formatQuantity('0.000000'));
        self::assertSame('0', SalesMoneyFormatter::formatQuantity(null));
        self::assertSame('0', SalesMoneyFormatter::formatQuantity(''));
    }

    public function test_format_currency_uses_two_decimal_policy(): void
    {
        self::assertSame('9.83', SalesMoneyFormatter::formatCurrency('9.833', 'JOD'));
        self::assertSame('100.00', SalesMoneyFormatter::formatCurrency('100', 'ILS'));
    }
}
