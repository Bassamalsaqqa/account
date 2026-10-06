<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Services\Purchasing\VendorPaymentPreview;
use Brick\Math\Exception\NumberFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class VendorPaymentBlankAllocationPreviewRegressionTest extends TestCase
{
    public static function blankInputs(): array
    {
        return [[''], ['   '], ["\t\n"], [null]];
    }

    #[DataProvider('blankInputs')]
    public function test_blank_allocation_is_zero_while_other_rows_keep_exact_totals_and_fx(?string $blank): void
    {
        $preview = (new VendorPaymentPreview)->calculate('100.00', '3.60', [
            ['allocated_amount' => $blank, 'purchase_exchange_rate' => '3.50'],
            ['allocated_amount' => '20.00', 'purchase_exchange_rate' => '3.50'],
        ], 'USD');
        $this->assertSame('20.00', $preview['allocated']);
        $this->assertSame('80.00', $preview['unallocated']);
        $this->assertSame('0.000000', $preview['allocations'][0]['preview_fx']);
        $this->assertSame('2.000000', $preview['allocations'][1]['preview_fx']);
    }

    public function test_blank_row_with_missing_foreign_fx_has_no_fabricated_preview(): void
    {
        $preview = (new VendorPaymentPreview)->calculate('100.00', '', [
            ['allocated_amount' => '', 'purchase_exchange_rate' => '3.50'],
            ['allocated_amount' => '20.00', 'purchase_exchange_rate' => '3.50'],
        ], 'USD');
        $this->assertSame('20.00', $preview['allocated']);
        $this->assertSame('80.00', $preview['unallocated']);
        $this->assertNull($preview['allocations'][0]['preview_fx']);
        $this->assertNull($preview['allocations'][1]['preview_fx']);
    }

    public function test_jod_blank_and_reentered_subcent_row_preserve_three_decimal_preview(): void
    {
        $rows = [['allocated_amount' => '', 'purchase_exchange_rate' => '4'], ['allocated_amount' => '0.234', 'purchase_exchange_rate' => '4']];
        $service = new VendorPaymentPreview;
        $preview = $service->calculate('1.234', '5', $rows, 'JOD');
        $this->assertSame('0.234', $preview['allocated']);
        $this->assertSame('1.000', $preview['unallocated']);
        $this->assertSame('0.234000', $preview['allocations'][1]['preview_fx']);
        $rows[0]['allocated_amount'] = '0.001';
        $preview = $service->calculate('1.234', '5', $rows, 'JOD');
        $this->assertSame('0.235', $preview['allocated']);
        $this->assertSame('0.999', $preview['unallocated']);
        $this->assertSame('0.001000', $preview['allocations'][0]['preview_fx']);
    }

    public function test_negative_allocation_remains_clamped_to_zero_in_preview(): void
    {
        $preview = (new VendorPaymentPreview)->calculate('100.00', '3.60', [
            ['allocated_amount' => '-1.00', 'purchase_exchange_rate' => '3.50'],
            ['allocated_amount' => '20.00', 'purchase_exchange_rate' => '3.50'],
        ], 'USD');
        $this->assertSame('20.00', $preview['allocated']);
        $this->assertSame('80.00', $preview['unallocated']);
        $this->assertSame('0.000000', $preview['allocations'][0]['preview_fx']);
    }

    public function test_nonblank_malformed_allocation_is_not_coerced_to_zero(): void
    {
        $this->expectException(NumberFormatException::class);
        (new VendorPaymentPreview)->calculate('100.00', '3.60', [
            ['allocated_amount' => 'not-a-number', 'purchase_exchange_rate' => '3.50'],
        ], 'USD');
    }
}
