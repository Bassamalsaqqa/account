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
            $allocationInput = (string) ($allocation['allocated_amount'] ?? '0');
            $allocated = BigDecimal::of(trim($allocationInput) === '' ? '0' : $allocationInput);
            if ($allocated->isNegative()) {
                $allocated = BigDecimal::zero();
            }
            $sameCurrency = ($allocation['document_currency_code'] ?? $currency) === $currency;
            $paymentInput = $sameCurrency ? (string) $allocated : (string) ($allocation['payment_currency_amount'] ?? '0');
            $consumed = BigDecimal::of(trim($paymentInput) === '' ? '0' : $paymentInput);
            if ($consumed->isNegative() || $allocated->isZero()) {
                $consumed = BigDecimal::zero();
            }
            $total = $total->plus($consumed);

            $purchaseRate = BigDecimal::of((string) ($allocation['purchase_exchange_rate'] ?? '1'));
            $book = $allocated->multipliedBy($purchaseRate)->toScale(6, RoundingMode::HALF_UP);
            // Missing explicit FX must not display a fabricated rate-one estimate.
            // AP orientation: delta = S - B; positive is FX LOSS, negative is FX GAIN.
            $allocation['preview_fx'] = $rate === null ? null
                : (string) $consumed->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP)->minus($book);
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
