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
        $rate = $exchangeRate !== '' ? BigDecimal::of($exchangeRate) : null;
        $total = BigDecimal::zero();

        foreach ($allocations as &$allocation) {
            $allocated = BigDecimal::of((string) ($allocation['allocated_amount'] ?? '0'));
            if ($allocated->isNegative()) {
                $allocated = BigDecimal::zero();
            }
            $total = $total->plus($allocated);

            $purchaseRate = BigDecimal::of((string) ($allocation['purchase_exchange_rate'] ?? '1'));
            $book = $allocated->multipliedBy($purchaseRate)->toScale(6, RoundingMode::HALF_UP);
            // Missing explicit FX must not display a fabricated rate-one estimate.
            // AP orientation: delta = S - B; positive is FX LOSS, negative is FX GAIN.
            $allocation['preview_fx'] = $rate === null ? null
                : (string) $allocated->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP)->minus($book);
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
