<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sales;

use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturnLine;
use App\Services\Sales\SalesReturnAmounts;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class SalesReturnAmountsTest extends TestCase
{
    public function test_frozen_and_legacy_precision_apply_without_live_currency_metadata(): void
    {
        $invoice = new SalesInvoice(['currency_code' => 'BHD', 'exchange_rate' => '1.3333333333']);
        $line = new SalesInvoiceLine([
            'quantity' => '3',
            'line_discount' => '2.000000',
            'line_tax' => '4.480000',
            'line_total' => '32.480000',
            'line_subtotal_base' => '40.000000',
            'line_discount_base' => '2.666667',
            'line_tax_base' => '5.973333',
            'line_total_base' => '43.306667',
        ]);
        $calculator = new SalesReturnAmounts;
        $legacy = $calculator->calculateLine($line, BigDecimal::one(), [], $invoice);
        $this->assertSame('10.827000', (string) $legacy['total']);
        $this->assertSame('1.990667', (string) $legacy['tax_base']);

        $invoice->currency_minor_units = 6;
        $partial = $calculator->calculateLine($line, BigDecimal::one(), [], $invoice);
        $this->assertSame([
            'discount' => '0.666667', 'discount_base' => '0.888889',
            'tax' => '1.493333', 'tax_base' => '1.991111',
            'total' => '10.826667', 'total_base' => '14.435556',
            'subtotal' => '10.000001', 'subtotal_base' => '13.333335',
        ], array_map('strval', $partial));

        $previous = new SalesReturnLine(['quantity' => '1']);
        foreach ($partial as $field => $value) {
            $previous->setAttribute('line_'.$field, (string) $value);
        }
        $remaining = $calculator->calculateLine($line, BigDecimal::of(2), [$previous], $invoice);
        $this->assertSame([
            'discount' => '1.333333', 'discount_base' => '1.777778',
            'tax' => '2.986667', 'tax_base' => '3.982222',
            'total' => '21.653333', 'total_base' => '28.871111',
            'subtotal' => '19.999999', 'subtotal_base' => '26.666665',
        ], array_map('strval', $remaining));
    }
}
