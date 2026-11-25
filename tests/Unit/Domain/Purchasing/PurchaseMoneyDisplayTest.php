<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Purchasing;

use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PurchaseMoneyDisplayTest extends TestCase
{
    #[DataProvider('documentAmounts')]
    public function test_purchase_amount_uses_explicit_document_precision(string $amount, int $scale, string $expected): void
    {
        self::assertSame($expected, SalesMoneyFormatter::displayMoney($amount, scale: $scale));
    }

    /** @return array<string, array{string, int, string}> */
    public static function documentAmounts(): array
    {
        return [
            'integer currency' => ['1234.000000', 0, '1,234.00'],
            'two decimals' => ['12.340000', 2, '12.34'],
            'one fil' => ['0.001000', 3, '0.00'],
            'historical return' => ['9.833000', 3, '9.83'],
            'four decimals' => ['12.345600', 4, '12.35'],
            'six decimals' => ['-12.345678', 6, '-12.35'],
            'explicit zero' => ['0', 3, '0.00'],
        ];
    }

    public function test_unit_cost_retains_fractional_precision_beyond_document_scale(): void
    {
        self::assertSame('0.12', SalesMoneyFormatter::formatMoneyInput('0.120000', 0));
        self::assertSame('0.125', SalesMoneyFormatter::formatMoneyInput('0.125000', 3));
    }

    public function test_existing_callers_keep_their_default_scale(): void
    {
        self::assertSame('9.83', SalesMoneyFormatter::displayMoney('9.833', 'JOD'));
        self::assertSame('—', SalesMoneyFormatter::displayMoney(null, scale: 3));
    }
}
