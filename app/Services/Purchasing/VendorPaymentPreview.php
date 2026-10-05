<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class VendorPaymentPreview
{
    /**
     * @param  list<array<string, mixed>>  $allocations
     * @return array{allocations: list<array<string, mixed>>, allocated: string, unallocated: string}
     */
    public function calculate(string $amount, string $exchangeRate, array $allocations, string $currency): array
    {
        $rate = BigDecimal::of($exchangeRate !== '' ? $exchangeRate : '1');
        $total = BigDecimal::zero();

        foreach ($allocations as &$allocation) {
            $allocated = BigDecimal::of((string) ($allocation['allocated_amount'] ?? '0'));
            if ($allocated->isNegative()) {
                $allocated = BigDecimal::zero();
            }
            $total = $total->plus($allocated);

            $purchaseRate = BigDecimal::of((string) ($allocation['purchase_exchange_rate'] ?? '1'));
            $book = $allocated->multipliedBy($purchaseRate)->toScale(6, RoundingMode::HALF_UP);
            $settlement = $allocated->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP);

            // AP orientation: delta = S - B; positive is FX LOSS, negative is FX GAIN
            $allocation['preview_fx'] = (string) $settlement->minus($book);
        }
        unset($allocation);

        $scale = $currency === 'JOD' ? 3 : 2;
        $totalAmount = BigDecimal::of($amount !== '' ? $amount : '0');
        $unallocated = $totalAmount->minus($total);

        return [
            'allocations' => $allocations,
            'allocated' => (string) $total->toScale($scale, RoundingMode::HALF_UP),
            'unallocated' => (string) $unallocated->toScale($scale, RoundingMode::HALF_UP),
        ];
    }
}
