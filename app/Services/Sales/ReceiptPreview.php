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
        $rate = trim($exchangeRate) === '' ? null : BigDecimal::of($exchangeRate);
        $total = BigDecimal::zero();
        foreach ($allocations as &$allocation) {
            $allocated = BigDecimal::of(trim((string) ($allocation['allocated_amount'] ?? '')) === '' ? '0' : $allocation['allocated_amount']);
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
            $book = $allocated->multipliedBy((string) $allocation['invoice_exchange_rate'])->toScale(6, RoundingMode::HALF_UP);
            $allocation['preview_fx'] = $rate === null ? null : (string) $consumed->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP)->minus($book);
        }
        unset($allocation);
        $scale = $currency === 'JOD' ? 3 : 2;

        return ['allocations' => $allocations, 'allocated' => (string) $total->toScale($scale, RoundingMode::HALF_UP),
            'unallocated' => (string) BigDecimal::of($amount ?: '0')->minus($total)->toScale($scale, RoundingMode::HALF_UP)];
    }
}
