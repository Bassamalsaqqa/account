<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ReceiptPreview
{
    /** @param list<array<string, mixed>> $allocations
     * @return array{allocations: list<array<string, mixed>>, allocated: string, unallocated: string}
     */
    public function calculate(string $amount, string $exchangeRate, array $allocations, string $currency): array
    {
        $rate = BigDecimal::of($exchangeRate ?: '1');
        $total = BigDecimal::zero();
        foreach ($allocations as &$allocation) {
            $allocated = BigDecimal::of((string) ($allocation['allocated_amount'] ?: '0'));
            $total = $total->plus($allocated);
            $book = $allocated->multipliedBy((string) $allocation['invoice_exchange_rate'])->toScale(6, RoundingMode::HALF_UP);
            $settlement = $allocated->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP);
            $allocation['preview_fx'] = (string) $settlement->minus($book);
        }
        unset($allocation);
        $scale = $currency === 'JOD' ? 3 : 2;

        return ['allocations' => $allocations, 'allocated' => (string) $total->toScale($scale, RoundingMode::HALF_UP),
            'unallocated' => (string) BigDecimal::of($amount ?: '0')->minus($total)->toScale($scale, RoundingMode::HALF_UP)];
    }
}
