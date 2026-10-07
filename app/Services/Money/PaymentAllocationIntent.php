<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Services\Sales\ReceiptRequestValues;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Version 1 byte-compatible request identity; version 2 explicitly preserves BOTH currencies. */
final class PaymentAllocationIntent
{
    /** @param list<array<string, mixed>> $inputs */
    public static function version(array $inputs): int
    {
        foreach ($inputs as $input) {
            if (array_key_exists('payment_currency_amount', $input)) {
                return 2;
            }
        }

        return 1;
    }

    /** @param list<array<string, mixed>> $inputs
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $inputs, string $documentKey): array
    {
        $version = self::version($inputs);
        $grouped = [];
        foreach ($inputs as $input) {
            $id = ReceiptRequestValues::id($input[$documentKey]);
            $amount = BigDecimal::of(ReceiptRequestValues::decimal($input['allocated_amount'], 6));
            $paid = $version === 1 ? $amount : BigDecimal::of(ReceiptRequestValues::decimal($input['payment_currency_amount'] ?? null, 6));
            $previous = $grouped[$id] ?? ['amount' => BigDecimal::zero(), 'paid' => BigDecimal::zero()];
            $grouped[$id] = ['amount' => $previous['amount']->plus($amount), 'paid' => $previous['paid']->plus($paid)];
        }
        ksort($grouped, SORT_NUMERIC);
        $intent = [];
        foreach ($grouped as $id => $totals) {
            $row = [$documentKey => $id, 'allocated_amount' => (string) $totals['amount']->toScale(6)];
            if ($version === 2) {
                $row['payment_currency_amount'] = (string) $totals['paid']->toScale(6);
            }
            $intent[] = $row;
        }

        return $intent;
    }

    /** @param array<string, mixed> $input
     * @return array{document: BigDecimal, payment: BigDecimal}
     */
    public static function amounts(array $input, string $documentCurrency, string $paymentCurrency): array
    {
        $document = MoneyValues::amount($input['allocated_amount'], $documentCurrency);
        if ($documentCurrency !== $paymentCurrency && ! array_key_exists('payment_currency_amount', $input)) {
            throw new InvalidArgumentException('Cross-currency allocation requires an explicit payment-currency amount.');
        }
        $payment = MoneyValues::amount($input['payment_currency_amount'] ?? $input['allocated_amount'], $paymentCurrency);
        if ($documentCurrency === $paymentCurrency && ! $document->isEqualTo($payment)) {
            throw new InvalidArgumentException('Same-currency allocation amounts must match exactly.');
        }

        return ['document' => $document, 'payment' => $payment];
    }

    /** @return array<string, mixed> */
    public static function historicalRow(string $documentKey, int $id, string $documentAmount, string $paymentAmount, int $version): array
    {
        if (! in_array($version, [1, 2], true)) {
            throw new InvalidArgumentException('Unknown allocation history version.');
        }
        $row = [$documentKey => $id, 'allocated_amount' => $documentAmount];
        if ($version === 2) {
            $row['payment_currency_amount'] = $paymentAmount;
        } elseif (! BigDecimal::of($documentAmount)->isEqualTo($paymentAmount)) {
            throw new InvalidArgumentException('Legacy allocation payment-currency amount must equal its document amount.');
        }

        return $row;
    }
}
