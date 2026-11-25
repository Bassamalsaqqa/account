<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Models\SalesInvoiceLine;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Sales\SalesReturnAmounts;
use Brick\Math\BigDecimal;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class SalesReturnAmountsPrePostTotalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        Container::setInstance($container);
        $mockResolver = new class
        {
            public function resolve(string $currencyCode, mixed $company = null, bool $requireActiveMaster = false, bool $requireCompanyEnabled = false): int
            {
                return in_array($currencyCode, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;
            }
        };
        $container->instance(CurrencyMinorUnitsResolver::class, $mockResolver);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_calculate_line_returns_exact_decimal_breakdown(): void
    {
        $calculator = new SalesReturnAmounts;

        // Original line: qty 10, total 1000.00, discount 100.00, tax 150.00, subtotal 950.00
        $original = new SalesInvoiceLine([
            'quantity' => '10.000000',
            'line_total' => '1000.000000',
            'line_discount' => '100.000000',
            'line_tax' => '150.000000',
            'line_subtotal' => '950.000000',
        ]);

        $prior = [];

        // Return qty 2 (20% of 10)
        $amounts = $calculator->calculateLine($original, BigDecimal::of('2'), $prior, 'ILS');

        self::assertSame('200.000000', (string) $amounts['total']);
        self::assertSame('20.000000', (string) $amounts['discount']);
        self::assertSame('30.000000', (string) $amounts['tax']);
        self::assertSame('190.000000', (string) $amounts['subtotal']);

        // Check human-facing display follows two decimals
        self::assertSame('200.00', SalesMoneyFormatter::displayMoney($amounts['total'], 'ILS'));
        self::assertSame('30.00', SalesMoneyFormatter::displayMoney($amounts['tax'], 'ILS'));
    }

    public function test_pre_and_post_return_totals_arithmetic(): void
    {
        $origInvoiceTotal = BigDecimal::of('1000.000000');
        $priorReturnsTotal = BigDecimal::of('150.000000');

        // Pre-return total is remaining invoice amount before this return
        $preReturnTotal = $origInvoiceTotal->minus($priorReturnsTotal);
        self::assertSame('850.000000', (string) $preReturnTotal->toScale(6));

        // Current return total calculated from lines
        $currentReturnTotal = BigDecimal::of('200.000000');

        // Post-return remaining is pre-return minus current return
        $postReturnTotal = $preReturnTotal->minus($currentReturnTotal);
        self::assertSame('650.000000', (string) $postReturnTotal->toScale(6));

        // UI displays all totals strictly with TWO decimal places
        self::assertSame('850.00', SalesMoneyFormatter::displayMoney($preReturnTotal, 'ILS'));
        self::assertSame('200.00', SalesMoneyFormatter::displayMoney($currentReturnTotal, 'ILS'));
        self::assertSame('650.00', SalesMoneyFormatter::displayMoney($postReturnTotal, 'ILS'));
    }

    public function test_fractional_jod_return_retains_exact_storage_while_displaying_two_decimals(): void
    {
        $origInvoiceTotal = BigDecimal::of('98.330000');
        $priorReturnsTotal = BigDecimal::zero();

        $preReturnTotal = $origInvoiceTotal->minus($priorReturnsTotal);
        $currentReturnTotal = BigDecimal::of('9.833000');
        $postReturnTotal = $preReturnTotal->minus($currentReturnTotal);

        // Economic storage preserves exact arbitrary precision (6 decimals)
        self::assertSame('98.330000', (string) $preReturnTotal->toScale(6));
        self::assertSame('9.833000', (string) $currentReturnTotal->toScale(6));
        self::assertSame('88.497000', (string) $postReturnTotal->toScale(6));

        // Shared display policy enforces TWO decimals across UI entry/display
        self::assertSame('98.33', SalesMoneyFormatter::displayMoney($preReturnTotal, 'JOD'));
        self::assertSame('9.83', SalesMoneyFormatter::displayMoney($currentReturnTotal, 'JOD'));
        self::assertSame('88.50', SalesMoneyFormatter::displayMoney($postReturnTotal, 'JOD'));
    }
}
