<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5B;

use App\Domain\Purchasing\PurchaseCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PurchaseCalculatorTest extends TestCase
{
    public static function calculations(): array
    {
        return [
            'zero tax' => ['100', null, '0', '0', false, 'ILS', '100.000000', '0.000000', '100.000000'],
            'full tax' => ['100', null, '0', '100', false, 'ILS', '100.000000', '100.000000', '200.000000'],
            'inclusive full tax' => ['100', null, '0', '100', true, 'ILS', '50.000000', '50.000000', '100.000000'],
            'discount plus tax' => ['100', 'percent', '10', '16', false, 'USD', '90.000000', '14.400000', '104.400000'],
            'fixed plus inclusive tax' => ['126', 'fixed', '10', '16', true, 'ILS', '100.000000', '16.000000', '116.000000'],
            'JOD rounding' => ['1.2345', null, '0', '16', false, 'JOD', '1.235000', '0.198000', '1.433000'],
            'ILS rounding' => ['1.005', null, '0', null, false, 'ILS', '1.010000', '0.000000', '1.010000'],
        ];
    }

    #[DataProvider('calculations')]
    public function test_exact_purchase_calculations(string $cost, ?string $discount, string $discountValue, ?string $tax, bool $inclusive, string $currency, string $net, string $taxAmount, string $total): void
    {
        $result = app(PurchaseCalculator::class)->line('1', $cost, $discount, $discountValue, $tax, $inclusive, $currency, '1');
        $this->assertSame($net, (string) $result->netBeforeTax);
        $this->assertSame($taxAmount, (string) $result->tax);
        $this->assertSame($total, (string) $result->total);
        $this->assertSame($total, (string) $result->totalBase);
    }
}
