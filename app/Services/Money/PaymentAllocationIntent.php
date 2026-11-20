<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Services\Sales\ReceiptRequestValues;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Version 1: byte-compatible legacy request identity ($documentKey, allocated_amount).
 * Version 2: explicitly preserves BOTH currencies ($documentKey, allocated_amount, payment_currency_amount).
 * Version 3: typed intent (kind, id) or explicit opening_trade_item_id preserving distinct IDs (e.g. invoice 42 vs opening item 42).
 */
final class PaymentAllocationIntent
{
    /** @param list<array<string, mixed>> $inputs */
    public static function version(array $inputs): int
    {
        foreach ($inputs as $input) {
            if (array_key_exists('kind', $input) || array_key_exists('target_type', $input) || array_key_exists('opening_trade_item_id', $input)) {
                return 3;
            }
        }

        foreach ($inputs as $input) {
            if (array_key_exists('payment_currency_amount', $input)) {
                return 2;
            }
        }

        return 1;
    }

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $inputs, string $documentKey): array
    {
        $version = self::version($inputs);

        if ($version < 3) {
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

        // Version 3: Typed target (kind, id) ensuring IDs like 42 for invoice and 42 for opening item never collide.
        $defaultKind = match ($documentKey) {
            'sales_invoice_id' => 'invoice',
            'purchase_id' => 'purchase',
            default => 'document',
        };

        $grouped = [];
        foreach ($inputs as $input) {
            $isOpening = array_key_exists('opening_trade_item_id', $input) && $input['opening_trade_item_id'] !== null;
            $kind = $isOpening
                ? 'opening_trade_item'
                : (string) ($input['kind'] ?? $input['target_type'] ?? $defaultKind);

            $kind = match ($kind) {
                'sales_invoice' => 'invoice',
                'opening_trade_item', 'invoice', 'purchase' => $kind,
                default => throw new InvalidArgumentException('Unknown allocation target kind.'),
            };
            if ($kind !== 'opening_trade_item' && $kind !== $defaultKind) {
                throw new InvalidArgumentException('Allocation target kind does not match this payment.');
            }
            if ($isOpening && isset($input[$documentKey])) {
                throw new InvalidArgumentException('An allocation must identify exactly one target.');
            }

            $id = $isOpening
                ? ReceiptRequestValues::id($input['opening_trade_item_id'])
                : (isset($input['id']) ? ReceiptRequestValues::id($input['id']) : ReceiptRequestValues::id($input[$documentKey]));

            $compositeKey = "{$kind}:{$id}";
            $amount = BigDecimal::of(ReceiptRequestValues::decimal($input['allocated_amount'], 6));
            $paid = array_key_exists('payment_currency_amount', $input)
                ? BigDecimal::of(ReceiptRequestValues::decimal($input['payment_currency_amount'], 6))
                : $amount;

            $previous = $grouped[$compositeKey] ?? [
                'kind' => $kind,
                'id' => $id,
                'amount' => BigDecimal::zero(),
                'paid' => BigDecimal::zero(),
            ];

            $grouped[$compositeKey] = [
                'kind' => $kind,
                'id' => $id,
                'amount' => $previous['amount']->plus($amount),
                'paid' => $previous['paid']->plus($paid),
            ];
        }

        ksort($grouped, SORT_STRING);
        $intent = [];
        foreach ($grouped as $data) {
            $row = [
                'kind' => $data['kind'],
                'id' => $data['id'],
                'allocated_amount' => (string) $data['amount']->toScale(6),
                'payment_currency_amount' => (string) $data['paid']->toScale(6),
            ];

            if ($data['kind'] === 'opening_trade_item') {
                $row['opening_trade_item_id'] = $data['id'];
            } else {
                $row[$documentKey] = $data['id'];
            }

            $intent[] = $row;
        }

        return $intent;
    }

    /**
     * @param  array<string, mixed>  $input
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
        if (! in_array($version, [1, 2, 3], true)) {
            throw new InvalidArgumentException('Unknown allocation history version.');
        }

        $row = [$documentKey => $id, 'allocated_amount' => $documentAmount];

        if ($version >= 2) {
            $row['payment_currency_amount'] = $paymentAmount;
        } elseif (! BigDecimal::of($documentAmount)->isEqualTo($paymentAmount)) {
            throw new InvalidArgumentException('Legacy allocation payment-currency amount must equal its document amount.');
        }

        if ($version === 3) {
            $row['id'] = $id;
            $row['kind'] = match ($documentKey) {
                'opening_trade_item_id' => 'opening_trade_item',
                'sales_invoice_id' => 'invoice',
                'purchase_id' => 'purchase',
                default => 'document',
            };

            return self::normalize([$row], $documentKey === 'opening_trade_item_id' ? 'sales_invoice_id' : $documentKey)[0];
        }

        return $row;
    }
}
