<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturnLine;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Sales\SalesReturnAmounts;
use Brick\Math\BigDecimal;
use Illuminate\Container\Container;
use InvalidArgumentException;
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

        // Post-return remaining is pre-return minus current return (payments ignored!)
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

    public function test_prior_returns_accumulate_and_final_residual_assigned_exactly(): void
    {
        $calculator = new SalesReturnAmounts;

        $original = new SalesInvoiceLine([
            'quantity' => '3.000000',
            'line_total' => '100.000000',
            'line_discount' => '0.000000',
            'line_tax' => '0.000000',
            'line_subtotal' => '100.000000',
        ]);

        // Return 1 of 1: entitlement 100 * 1 / 3 = 33.33
        $ret1 = $calculator->calculateLine($original, BigDecimal::of('1'), [], 'ILS');
        self::assertSame('33.330000', (string) $ret1['total']);

        $prior1 = new SalesReturnLine([
            'quantity' => '1.000000',
            'line_total' => '33.330000',
            'line_discount' => '0.000000',
            'line_tax' => '0.000000',
            'line_subtotal' => '33.330000',
        ]);

        // Return 2 of 1: entitlement 100 * 2 / 3 = 66.67 minus 33.33 = 33.34
        $ret2 = $calculator->calculateLine($original, BigDecimal::of('1'), [$prior1], 'ILS');
        self::assertSame('33.340000', (string) $ret2['total']);

        $prior2 = new SalesReturnLine([
            'quantity' => '1.000000',
            'line_total' => '33.340000',
            'line_discount' => '0.000000',
            'line_tax' => '0.000000',
            'line_subtotal' => '33.340000',
        ]);

        // Return 3 of 1: final return assigns exact residual (100.00 - 66.67 = 33.33)
        $ret3 = $calculator->calculateLine($original, BigDecimal::of('1'), [$prior1, $prior2], 'ILS');
        self::assertSame('33.330000', (string) $ret3['total']);

        $sum = $ret1['total']->plus($ret2['total'])->plus($ret3['total']);
        self::assertSame('100.000000', (string) $sum);
    }

    public function test_paid_invoice_invariance_post_return_total_reflects_goods_value_ignoring_payments(): void
    {
        $origInvoiceTotal = BigDecimal::of('1000.000000');
        $priorReturnsTotal = BigDecimal::zero();

        // Customer has paid in full: paid amount is 1000.00
        $preReturnTotal = $origInvoiceTotal->minus($priorReturnsTotal);

        // Return goods worth 250.00
        $returnTotal = BigDecimal::of('250.000000');

        // Post-return remaining goods value is strictly 750.00 (NOT 0.00 or -250.00)
        $postReturnTotal = $preReturnTotal->minus($returnTotal);
        self::assertSame('750.000000', (string) $postReturnTotal->toScale(6));
        self::assertSame('750.00', SalesMoneyFormatter::displayMoney($postReturnTotal, 'ILS'));
    }

    public function test_overmax_and_non_positive_quantity_validation(): void
    {
        $calculator = new SalesReturnAmounts;

        $original = new SalesInvoiceLine([
            'quantity' => '5.000000',
            'line_total' => '500.000000',
            'line_discount' => '0.000000',
            'line_tax' => '0.000000',
            'line_subtotal' => '500.000000',
        ]);

        $prior = [
            new SalesReturnLine([
                'quantity' => '4.000000',
                'line_total' => '400.000000',
                'line_discount' => '0.000000',
                'line_tax' => '0.000000',
                'line_subtotal' => '400.000000',
            ]),
        ];

        // Requesting 2 when only 1 is remaining throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $calculator->calculateLine($original, BigDecimal::of('2'), $prior, 'ILS');
    }

    public function test_malformed_quantity_pattern_rejection(): void
    {
        $validatePattern = fn (string $raw): bool => (bool) preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', trim($raw));

        // Valid positive decimal strings
        self::assertTrue($validatePattern('1'));
        self::assertTrue($validatePattern('0.5'));
        self::assertTrue($validatePattern('12.345678')); // 6 decimals max
        self::assertTrue($validatePattern('100.000000'));

        // Invalid or malformed strings
        self::assertFalse($validatePattern(''));
        self::assertFalse($validatePattern('   '));
        self::assertFalse($validatePattern('abc'));
        self::assertFalse($validatePattern('-5'));
        self::assertFalse($validatePattern('-0.5'));
        self::assertFalse($validatePattern('1.2.3'));
        self::assertFalse($validatePattern('1e5'));
        self::assertFalse($validatePattern('1.1234567')); // 7 decimals exceeds scale
        self::assertFalse($validatePattern('1,000.00')); // Commas not permitted in raw line input
    }

    public function test_bilingual_translations(): void
    {
        $en = require __DIR__.'/../../../resources/lang/en/sales.php';
        $ar = require __DIR__.'/../../../resources/lang/ar/sales.php';

        self::assertArrayHasKey('return_summary', $en);
        self::assertArrayHasKey('return_summary', $ar);
        self::assertSame('Return Summary', $en['return_summary']);
        self::assertSame('ملخص المردود', $ar['return_summary']);

        self::assertArrayHasKey('pre_return_total', $en);
        self::assertArrayHasKey('pre_return_total', $ar);
        self::assertSame('Invoice Total Before Return', $en['pre_return_total']);
        self::assertSame('إجمالي الفاتورة قبل الإرجاع', $ar['pre_return_total']);

        self::assertArrayHasKey('current_return_total', $en);
        self::assertArrayHasKey('current_return_total', $ar);
        self::assertSame('Return Total', $en['current_return_total']);
        self::assertSame('إجمالي المردود', $ar['current_return_total']);

        self::assertArrayHasKey('post_return_remaining', $en);
        self::assertArrayHasKey('post_return_remaining', $ar);
        self::assertSame('Remaining Invoice Goods Value', $en['post_return_remaining']);
        self::assertSame('قيمة بضاعة الفاتورة بعد الإرجاع', $ar['post_return_remaining']);

        self::assertArrayHasKey('return_calculation_failed', $en);
        self::assertArrayHasKey('return_calculation_failed', $ar);
        self::assertArrayHasKey('invalid_return_quantity', $en);
        self::assertArrayHasKey('invalid_return_quantity', $ar);
    }
}
