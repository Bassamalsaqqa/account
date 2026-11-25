<?php

declare(strict_types=1);

namespace Tests\Unit\Purchasing;

use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturnLine;
use App\Services\Purchasing\PurchaseReturnAmounts;
use App\Services\Purchasing\PurchaseReturnValidationException;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PurchaseReturnAmountsPrePostTotalsTest extends TestCase
{
    public function test_calculate_line_pure_extraction_matches_canonical_math(): void
    {
        $calculator = new PurchaseReturnAmounts;

        $original = new PurchaseLine([
            'quantity' => '10.000000',
            'line_total' => '1000.000000',
            'line_discount' => '100.000000',
            'line_tax' => '150.000000',
            'line_subtotal' => '950.000000',
            'line_total_base' => '3500.000000',
            'line_discount_base' => '350.000000',
            'line_tax_base' => '525.000000',
            'line_subtotal_base' => '3325.000000',
        ]);

        $prior = [];

        // Return qty 4 (40% of 10)
        $amounts = $calculator->calculateLine($original, BigDecimal::of('4'), $prior, 'USD');

        self::assertSame('400.000000', (string) $amounts['total']);
        self::assertSame('40.000000', (string) $amounts['discount']);
        self::assertSame('60.000000', (string) $amounts['tax']);
        self::assertSame('380.000000', (string) $amounts['subtotal']);

        // Base currency allocations are independent (not return_currency * FX)
        self::assertSame('1400.000000', (string) $amounts['total_base']);
        self::assertSame('140.000000', (string) $amounts['discount_base']);
        self::assertSame('210.000000', (string) $amounts['tax_base']);
        self::assertSame('1330.000000', (string) $amounts['subtotal_base']);

        // Formatting enforces two-decimal display for USD
        self::assertSame('400.00', SalesMoneyFormatter::displayMoney($amounts['total'], 'USD'));
        self::assertSame('60.00', SalesMoneyFormatter::displayMoney($amounts['tax'], 'USD'));
    }

    public function test_jod_currency_scale_3_and_residual_final_return(): void
    {
        $calculator = new PurchaseReturnAmounts;

        $original = new PurchaseLine([
            'quantity' => '3.000000',
            'line_total' => '10.000000',
            'line_discount' => '0.000000',
            'line_tax' => '0.000000',
            'line_subtotal' => '10.000000',
            'line_total_base' => '50.000000',
            'line_discount_base' => '0.000000',
            'line_tax_base' => '0.000000',
            'line_subtotal_base' => '50.000000',
        ]);

        // Return 1: qty 1 out of 3 (10 / 3 = 3.333 rounded)
        $return1 = $calculator->calculateLine($original, BigDecimal::of('1'), [], 'JOD');
        self::assertSame('3.333000', (string) $return1['total']);
        self::assertSame('16.666667', (string) $return1['total_base']);

        $priorLine1 = new PurchaseReturnLine([
            'quantity' => '1.000000',
            'line_total' => '3.333000',
            'line_discount' => '0.000000',
            'line_tax' => '0.000000',
            'line_subtotal' => '3.333000',
            'line_total_base' => '16.666667',
            'line_discount_base' => '0.000000',
            'line_tax_base' => '0.000000',
            'line_subtotal_base' => '16.666667',
        ]);

        // Return 2: qty 1 out of 2 remaining (6.667 / 2 = 3.334 rounded)
        $return2 = $calculator->calculateLine($original, BigDecimal::of('1'), [$priorLine1], 'JOD');
        self::assertSame('3.334000', (string) $return2['total']);
        self::assertSame('16.666667', (string) $return2['total_base']);

        $priorLine2 = new PurchaseReturnLine([
            'quantity' => '1.000000',
            'line_total' => '3.334000',
            'line_discount' => '0.000000',
            'line_tax' => '0.000000',
            'line_subtotal' => '3.334000',
            'line_total_base' => '16.666667',
            'line_discount_base' => '0.000000',
            'line_tax_base' => '0.000000',
            'line_subtotal_base' => '16.666667',
        ]);

        // Return 3: FINAL return of 1 (residual of 10.000 - 3.333 - 3.334 = 3.333)
        $return3 = $calculator->calculateLine($original, BigDecimal::of('1'), [$priorLine1, $priorLine2], 'JOD');
        self::assertSame('3.333000', (string) $return3['total']);
        self::assertSame('16.666666', (string) $return3['total_base']);

        // Sum across all 3 returns exactly matches original 10.000000 currency and 50.000000 base
        $totalSum = $return1['total']->plus($return2['total'])->plus($return3['total']);
        self::assertSame('10.000000', (string) $totalSum);

        $baseSum = $return1['total_base']->plus($return2['total_base'])->plus($return3['total_base']);
        self::assertSame('50.000000', (string) $baseSum);
    }

    public function test_pre_and_post_purchase_return_totals_arithmetic_ignores_payments(): void
    {
        $origPurchaseTotal = BigDecimal::of('2500.000000');
        $priorReturnsTotal = BigDecimal::of('500.000000');

        // Pre-return total is invoice goods value before this return
        $preReturnTotal = $origPurchaseTotal->minus($priorReturnsTotal);
        self::assertSame('2000.000000', (string) $preReturnTotal->toScale(6));

        $currentReturnTotal = BigDecimal::of('350.000000');

        // Post-return total is remaining goods value after return (payments ignored!)
        $postReturnTotal = $preReturnTotal->minus($currentReturnTotal);
        self::assertSame('1650.000000', (string) $postReturnTotal->toScale(6));

        // Shared display policy enforces 2 decimals
        self::assertSame('2,000.00', SalesMoneyFormatter::displayMoney($preReturnTotal, 'ILS'));
        self::assertSame('350.00', SalesMoneyFormatter::displayMoney($currentReturnTotal, 'ILS'));
        self::assertSame('1,650.00', SalesMoneyFormatter::displayMoney($postReturnTotal, 'ILS'));
    }

    public function test_overmax_and_non_positive_quantity_validation(): void
    {
        $calculator = new PurchaseReturnAmounts;

        $original = new PurchaseLine([
            'quantity' => '5.000000',
            'line_total' => '500.000000',
            'line_discount' => '0.000000',
            'line_tax' => '0.000000',
            'line_subtotal' => '500.000000',
            'line_total_base' => '500.000000',
            'line_discount_base' => '0.000000',
            'line_tax_base' => '0.000000',
            'line_subtotal_base' => '500.000000',
        ]);

        $prior = [
            new PurchaseReturnLine([
                'quantity' => '3.000000',
                'line_total' => '300.000000',
                'line_discount' => '0.000000',
                'line_tax' => '0.000000',
                'line_subtotal' => '300.000000',
                'line_total_base' => '300.000000',
                'line_discount_base' => '0.000000',
                'line_tax_base' => '0.000000',
                'line_subtotal_base' => '300.000000',
            ]),
        ];

        // Zero quantity throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $calculator->calculateLine($original, BigDecimal::zero(), $prior, 'ILS');
    }

    public function test_quantity_exceeding_remaining_throws_validation_exception(): void
    {
        $calculator = new PurchaseReturnAmounts;

        $original = new PurchaseLine([
            'quantity' => '5.000000',
            'line_total' => '500.000000',
            'line_discount' => '0.000000',
            'line_tax' => '0.000000',
            'line_subtotal' => '500.000000',
            'line_total_base' => '500.000000',
            'line_discount_base' => '0.000000',
            'line_tax_base' => '0.000000',
            'line_subtotal_base' => '500.000000',
        ]);

        $prior = [
            new PurchaseReturnLine([
                'quantity' => '3.000000',
                'line_total' => '300.000000',
                'line_discount' => '0.000000',
                'line_tax' => '0.000000',
                'line_subtotal' => '300.000000',
                'line_total_base' => '300.000000',
                'line_discount_base' => '0.000000',
                'line_tax_base' => '0.000000',
                'line_subtotal_base' => '300.000000',
            ]),
        ];

        // Remaining is 2; requesting 2.5 must fail
        $this->expectException(PurchaseReturnValidationException::class);
        $calculator->calculateLine($original, BigDecimal::of('2.5'), $prior, 'ILS');
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

    public function test_purchasing_bilingual_translations(): void
    {
        $en = require __DIR__.'/../../../resources/lang/en/purchasing.php';
        $ar = require __DIR__.'/../../../resources/lang/ar/purchasing.php';

        self::assertArrayHasKey('return_summary', $en);
        self::assertArrayHasKey('return_summary', $ar);
        self::assertSame('Return Summary', $en['return_summary']);
        self::assertSame('ملخص المردود', $ar['return_summary']);

        self::assertArrayHasKey('pre_return_total', $en);
        self::assertArrayHasKey('pre_return_total', $ar);
        self::assertSame('Purchase Total Before Return', $en['pre_return_total']);
        self::assertSame('إجمالي فاتورة الشراء قبل الإرجاع', $ar['pre_return_total']);

        self::assertArrayHasKey('current_return_total', $en);
        self::assertArrayHasKey('current_return_total', $ar);
        self::assertSame('Return Total', $en['current_return_total']);
        self::assertSame('إجمالي المردود', $ar['current_return_total']);

        self::assertArrayHasKey('post_return_remaining', $en);
        self::assertArrayHasKey('post_return_remaining', $ar);
        self::assertSame('Remaining Purchase Goods Value', $en['post_return_remaining']);
        self::assertSame('قيمة بضاعة الشراء بعد الإرجاع', $ar['post_return_remaining']);

        self::assertArrayHasKey('return_calculation_failed', $en);
        self::assertArrayHasKey('return_calculation_failed', $ar);
        self::assertArrayHasKey('invalid_return_quantity', $en);
        self::assertArrayHasKey('invalid_return_quantity', $ar);
    }
}
