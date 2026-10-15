<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Domain\Inventory\Services\StockQuantityBreakdown;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class StockQuantityBreakdownTest extends TestCase
{
    public function test_whole_packages_preserve_the_exact_fractional_base_remainder(): void
    {
        $units = [['name' => 'Pack', 'conversion' => '6'], ['name' => 'Carton', 'conversion' => '24']];
        $parts = (new StockQuantityBreakdown)->parts('53.125000', 'Piece', $units);
        $this->assertSame([['quantity' => '2', 'name' => 'Carton'], ['quantity' => '5.125', 'name' => 'Piece']], $parts);
        $this->assertSame('53.125', (string) BigDecimal::of($parts[0]['quantity'])->multipliedBy('24')->plus($parts[1]['quantity']));
        $this->assertSame([['quantity' => '1', 'name' => 'Carton'], ['quantity' => '1', 'name' => 'Pack']], (new StockQuantityBreakdown)->parts('30', 'Piece', $units));
    }

    public function test_fractional_factors_large_values_and_duplicate_factors_are_exact(): void
    {
        $parts = (new StockQuantityBreakdown)->parts('9007199254740993.625', 'Kg', [['name' => 'Bag', 'conversion' => '2.5'], ['name' => 'Box', 'conversion' => '2.5']]);
        $this->assertSame('Bag', $parts[0]['name']);
        $this->assertSame('9007199254740993.625', (string) BigDecimal::of($parts[0]['quantity'])->multipliedBy('2.5')->plus($parts[1]['quantity']));
    }

    public function test_zero_negative_and_sub_base_units_do_not_suggest_packages(): void
    {
        $formatter = new StockQuantityBreakdown;
        $this->assertSame([['quantity' => '0', 'name' => 'Piece']], $formatter->parts('0.000000', 'Piece', []));
        $this->assertSame([['quantity' => '-53.125', 'name' => 'Piece']], $formatter->parts('-53.125000', 'Piece', [['name' => 'Carton', 'conversion' => '24']]));
        $this->assertSame([['quantity' => '3.5', 'name' => 'Piece']], $formatter->parts('3.5', 'Piece', [['name' => 'Half', 'conversion' => '0.5'], ['name' => 'Invalid', 'conversion' => '0']]));
    }
}
