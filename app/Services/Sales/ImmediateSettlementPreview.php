<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Services\Money\MoneyValues;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

final class ImmediateSettlementPreview
{
    /**
     * @param  array<string, mixed>  $settlement
     * @return array{remaining_balance: ?string, remaining_exact: ?string, error: ?string, error_field: ?string}
     */
    public function calculate(
        string $grandTotal,
        string $documentCurrency,
        array $settlement,
        string $paymentCurrency,
        string $baseCurrency,
    ): array {
        $empty = ['remaining_balance' => null, 'remaining_exact' => null, 'error' => null, 'error_field' => null];
        $error = static fn (string $field, string $key): array => array_replace($empty, [
            'error' => __('fin1.'.$key),
            'error_field' => $field,
        ]);

        $total = BigDecimal::of($grandTotal);
        if (! $total->isPositive()) {
            return $empty;
        }

        $sameCurrency = $paymentCurrency === $documentCurrency;
        $allocatedField = $sameCurrency ? 'amount' : 'invoice_amount';
        $fields = ['amount' => [$paymentCurrency, 'invalid_amount']];
        if (! $sameCurrency) {
            $fields['invoice_amount'] = [$documentCurrency, 'invalid_invoice_amount'];
            $fields['payment_currency_amount'] = [$paymentCurrency, 'invalid_payment_currency_amount'];
        }

        $amounts = [];
        foreach ($fields as $field => [$currency, $message]) {
            $raw = $settlement[$field] ?? '';
            if ($raw === '') {
                continue;
            }
            if (! is_string($raw) || ! preg_match('/^\d+(?:\.\d{1,6})?$/D', $raw)) {
                return $error($field, $message);
            }
            try {
                $amounts[$field] = MoneyValues::amount($raw, $currency);
            } catch (InvalidArgumentException|MathException) {
                return $error($field, $message);
            }
        }

        $allocated = $amounts[$allocatedField] ?? null;
        if ($allocated !== null && $allocated->isGreaterThanOrEqualTo($total)) {
            return $error($allocatedField, 'partial_amount_exceeds_total');
        }
        if (isset($amounts['payment_currency_amount'], $amounts['amount'])
            && $amounts['payment_currency_amount']->isGreaterThan($amounts['amount'])) {
            return $error('payment_currency_amount', 'consumed_exceeds_received');
        }
        if ($allocated === null || count($amounts) !== count($fields)) {
            return $empty;
        }

        if (! $sameCurrency) {
            $rate = $settlement['exchange_rate'] ?? '';
            if ($rate === '') {
                return $empty;
            }
            if (! is_string($rate) || ! preg_match('/^\d+(?:\.\d{1,10})?$/D', $rate)) {
                return $error('exchange_rate', 'invalid_exchange_rate');
            }
            try {
                MoneyValues::rate($rate, $paymentCurrency, $baseCurrency);
            } catch (InvalidArgumentException|MathException) {
                return $error('exchange_rate', 'invalid_exchange_rate');
            }
        }

        $remaining = $total->minus($allocated);

        return array_replace($empty, [
            'remaining_balance' => SalesMoneyFormatter::formatMoneyInput($remaining),
            'remaining_exact' => (string) $remaining->toScale(6),
        ]);
    }
}
