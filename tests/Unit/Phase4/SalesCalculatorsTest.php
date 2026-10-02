<?php

declare(strict_types=1);

namespace Tests\Unit\Phase4;

use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use PHPUnit\Framework\TestCase;

class SalesCalculatorsTest extends TestCase
{
    protected SalesLineCalculator $lineCalc;

    protected SalesDocumentTotalsCalculator $docCalc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lineCalc = new SalesLineCalculator;
        $this->docCalc = new SalesDocumentTotalsCalculator;
    }

    public function test_zero_values_return_zero_results(): void
    {
        $input = new SalesLineCalculationInput(
            quantity: 0,
            unitPrice: 0,
            discountType: null,
            discountValue: 0,
            taxRate: null,
            taxInclusive: false,
            currencyMinorUnits: 2,
            exchangeRate: 1,
            baseCurrencyMinorUnits: 2,
        );

        $res = $this->lineCalc->calculate($input);

        $this->assertSame('0.000000', (string) $res->subtotal);
        $this->assertSame('0.000000', (string) $res->discount);
        $this->assertSame('0.000000', (string) $res->tax);
        $this->assertSame('0.000000', (string) $res->total);
        $this->assertSame('0.000000', (string) $res->totalBase);
    }

    public function test_exclusive_tax_calculation_and_rounding(): void
    {
        // 3 items @ 10.33 = 30.99 subtotal. 16% exclusive tax = 30.99 * 0.16 = 4.9584 -> rounds to 4.96. Total = 35.95
        $input = new SalesLineCalculationInput(
            quantity: '3',
            unitPrice: '10.33',
            taxRate: '0.160000',
            taxInclusive: false,
            currencyMinorUnits: 2,
            exchangeRate: '3.5000000000',
            baseCurrencyMinorUnits: 2,
        );

        $res = $this->lineCalc->calculate($input);

        $this->assertSame('30.990000', (string) $res->subtotal);
        $this->assertSame('0.000000', (string) $res->discount);
        $this->assertSame('30.990000', (string) $res->netBeforeTax);
        $this->assertSame('4.960000', (string) $res->tax);
        $this->assertSame('35.950000', (string) $res->total);

        // Base currency (at 3.50):
        // subtotalBase = 30.99 * 3.50 = 108.465000
        // taxBase = 4.96 * 3.50 = 17.360000
        // totalBase = 35.95 * 3.50 = 125.825000
        $this->assertSame('108.465000', (string) $res->subtotalBase);
        $this->assertSame('17.360000', (string) $res->taxBase);
        $this->assertSame('125.825000', (string) $res->totalBase);
    }

    public function test_inclusive_tax_non_terminating_division(): void
    {
        // 1 item @ 100 with 16% inclusive tax.
        // net = 100 / 1.16 = 86.206896... -> rounds to 86.21.
        // tax = 100 - 86.21 = 13.79. Total = 100.00.
        $input = new SalesLineCalculationInput(
            quantity: '1',
            unitPrice: '100.00',
            taxRate: '0.160000',
            taxInclusive: true,
            currencyMinorUnits: 2,
            exchangeRate: '1.0000000000',
            baseCurrencyMinorUnits: 2,
        );

        $res = $this->lineCalc->calculate($input);

        $this->assertSame('100.000000', (string) $res->subtotal);
        $this->assertSame('0.000000', (string) $res->discount);
        $this->assertSame('86.210000', (string) $res->netBeforeTax);
        $this->assertSame('13.790000', (string) $res->tax);
        $this->assertSame('100.000000', (string) $res->total);
    }

    public function test_percent_discount_and_fixed_discount(): void
    {
        // 2 items @ 50 = 100. 15% discount = 15.00. Net = 85.00
        $inputPercent = new SalesLineCalculationInput(
            quantity: '2',
            unitPrice: '50.00',
            discountType: 'percent',
            discountValue: '15.00',
            currencyMinorUnits: 2,
            exchangeRate: '1',
        );
        $resPercent = $this->lineCalc->calculate($inputPercent);
        $this->assertSame('100.000000', (string) $resPercent->subtotal);
        $this->assertSame('15.000000', (string) $resPercent->discount);
        $this->assertSame('85.000000', (string) $resPercent->total);

        // Fixed discount exceeding subtotal is capped at subtotal
        $inputExcess = new SalesLineCalculationInput(
            quantity: '1',
            unitPrice: '40.00',
            discountType: 'fixed',
            discountValue: '55.00',
            currencyMinorUnits: 2,
            exchangeRate: '1',
        );
        $resExcess = $this->lineCalc->calculate($inputExcess);
        $this->assertSame('40.000000', (string) $resExcess->subtotal);
        $this->assertSame('40.000000', (string) $resExcess->discount);
        $this->assertSame('0.000000', (string) $resExcess->total);
    }

    public function test_document_totals_calculator_aggregates_lines(): void
    {
        $input1 = new SalesLineCalculationInput(
            quantity: '2',
            unitPrice: '50.00',
            taxRate: '0.160000',
            taxInclusive: false,
            currencyMinorUnits: 2,
            exchangeRate: '1',
        );
        $res1 = $this->lineCalc->calculate($input1); // subtotal: 100, tax: 16, total: 116

        $input2 = new SalesLineCalculationInput(
            quantity: '1',
            unitPrice: '200.00',
            discountType: 'percent',
            discountValue: '10',
            currencyMinorUnits: 2,
            exchangeRate: '1',
        );
        $res2 = $this->lineCalc->calculate($input2); // subtotal: 200, discount: 20, tax: 0, total: 180

        $docTotals = $this->docCalc->calculate([$res1, $res2]);

        $this->assertSame('300.000000', (string) $docTotals->subtotalCurrency);
        $this->assertSame('20.000000', (string) $docTotals->discountTotalCurrency);
        $this->assertSame('16.000000', (string) $docTotals->taxTotalCurrency);
        $this->assertSame('296.000000', (string) $docTotals->grandTotalCurrency);
    }
}
