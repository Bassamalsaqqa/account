<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use Brick\Math\BigDecimal;

final class ImmediateSettlementPreview
{
    /**
     * Preview prospective remaining balance and validate immediate settlement before post.
     * Pure calculation: no database writes, exact BigDecimal arithmetic.
     *
     * @param  string|int|BigDecimal|null  $grandTotal
     * @param  array<string, mixed>  $settlement
     * @return array{
     *     valid: bool,
     *     error: ?string,
     *     error_field: ?string,
     *     remaining_balance: ?string,
     *     remaining_exact: ?string,
     *     applied_amount: ?string,
     *     unallocated_amount: ?string,
     * }
     */
    public function calculate(
        mixed $grandTotal,
        string $documentCurrency,
        array $settlement,
        string $paymentCurrency,
        string $baseCurrency,
    ): array {
        $emptyResult = [
            'valid' => true,
            'error' => null,
            'error_field' => null,
            'remaining_balance' => null,
            'remaining_exact' => null,
            'applied_amount' => null,
            'unallocated_amount' => null,
        ];

        if (($settlement['mode'] ?? 'unpaid') !== 'partial') {
            return $emptyResult;
        }

        $cleanTotal = trim((string) $grandTotal);
        if ($cleanTotal === '' || ! preg_match('/^-?\d+(?:\.\d+)?$/D', $cleanTotal)) {
            return $emptyResult;
        }

        $total = BigDecimal::of($cleanTotal);
        if (! $total->isPositive()) {
            return $emptyResult;
        }

        $isSameCurrency = ($paymentCurrency === $documentCurrency);

        if ($isSameCurrency) {
            $rawAmount = trim((string) ($settlement['amount'] ?? ''));
            if ($rawAmount === '') {
                return $emptyResult;
            }

            if (! preg_match('/^\d+(?:\.\d{1,6})?$/D', $rawAmount)) {
                return [
                    'valid' => false,
                    'error' => __('fin1.invalid_amount'),
                    'error_field' => 'amount',
                    'remaining_balance' => null,
                    'remaining_exact' => null,
                    'applied_amount' => null,
                    'unallocated_amount' => null,
                ];
            }

            $amount = BigDecimal::of($rawAmount);
            if (! $amount->isPositive()) {
                return [
                    'valid' => false,
                    'error' => __('fin1.invalid_amount'),
                    'error_field' => 'amount',
                    'remaining_balance' => null,
                    'remaining_exact' => null,
                    'applied_amount' => null,
                    'unallocated_amount' => null,
                ];
            }

            // In partial mode, payment amount must be strictly less than the total.
            if ($amount->isGreaterThanOrEqualTo($total)) {
                return [
                    'valid' => false,
                    'error' => __('fin1.partial_amount_exceeds_total'),
                    'error_field' => 'amount',
                    'remaining_balance' => null,
                    'remaining_exact' => null,
                    'applied_amount' => (string) $amount,
                    'unallocated_amount' => null,
                ];
            }

            $remaining = $total->minus($amount);

            return [
                'valid' => true,
                'error' => null,
                'error_field' => null,
                'remaining_balance' => SalesMoneyFormatter::formatMoneyInput($remaining),
                'remaining_exact' => (string) $remaining->toScale(6),
                'applied_amount' => (string) $amount,
                'unallocated_amount' => '0.00',
            ];
        }

        // Cross-currency settlement
        $rawAmount = trim((string) ($settlement['amount'] ?? ''));
        $rawInvoiceAmount = trim((string) ($settlement['invoice_amount'] ?? ''));
        $rawPaymentCurrencyAmount = trim((string) ($settlement['payment_currency_amount'] ?? ''));
        $rawExchangeRate = trim((string) ($settlement['exchange_rate'] ?? ''));

        // If nothing entered at all yet
        if ($rawAmount === '' && $rawInvoiceAmount === '' && $rawPaymentCurrencyAmount === '') {
            return $emptyResult;
        }

        // Validate exchange rate if provided or if base payment
        if ($paymentCurrency === $baseCurrency) {
            $exchangeRate = BigDecimal::one();
        } elseif ($rawExchangeRate !== '') {
            if (! preg_match('/^\d+(?:\.\d{1,10})?$/D', $rawExchangeRate) || ! BigDecimal::of($rawExchangeRate)->isPositive()) {
                return [
                    'valid' => false,
                    'error' => __('fin1.invalid_exchange_rate'),
                    'error_field' => 'exchange_rate',
                    'remaining_balance' => null,
                    'remaining_exact' => null,
                    'applied_amount' => null,
                    'unallocated_amount' => null,
                ];
            }
            $exchangeRate = BigDecimal::of($rawExchangeRate);
        } else {
            $exchangeRate = null;
        }

        // Validate total payment amount in payment currency if provided
        $amount = null;
        if ($rawAmount !== '') {
            if (! preg_match('/^\d+(?:\.\d{1,6})?$/D', $rawAmount) || ! BigDecimal::of($rawAmount)->isPositive()) {
                return [
                    'valid' => false,
                    'error' => __('fin1.invalid_amount'),
                    'error_field' => 'amount',
                    'remaining_balance' => null,
                    'remaining_exact' => null,
                    'applied_amount' => null,
                    'unallocated_amount' => null,
                ];
            }
            $amount = BigDecimal::of($rawAmount);
        }

        // Validate applied invoice amount in invoice currency
        if ($rawInvoiceAmount !== '') {
            if (! preg_match('/^\d+(?:\.\d{1,6})?$/D', $rawInvoiceAmount) || ! BigDecimal::of($rawInvoiceAmount)->isPositive()) {
                return [
                    'valid' => false,
                    'error' => __('fin1.invalid_invoice_amount'),
                    'error_field' => 'invoice_amount',
                    'remaining_balance' => null,
                    'remaining_exact' => null,
                    'applied_amount' => null,
                    'unallocated_amount' => null,
                ];
            }

            $allocated = BigDecimal::of($rawInvoiceAmount);
            if ($allocated->isGreaterThanOrEqualTo($total)) {
                return [
                    'valid' => false,
                    'error' => __('fin1.partial_amount_exceeds_total'),
                    'error_field' => 'invoice_amount',
                    'remaining_balance' => null,
                    'remaining_exact' => null,
                    'applied_amount' => (string) $allocated,
                    'unallocated_amount' => null,
                ];
            }
        } else {
            $allocated = null;
        }

        // Validate consumed payment currency amount if provided
        $consumed = null;
        if ($rawPaymentCurrencyAmount !== '') {
            if (! preg_match('/^\d+(?:\.\d{1,6})?$/D', $rawPaymentCurrencyAmount) || ! BigDecimal::of($rawPaymentCurrencyAmount)->isPositive()) {
                return [
                    'valid' => false,
                    'error' => __('fin1.invalid_payment_currency_amount'),
                    'error_field' => 'payment_currency_amount',
                    'remaining_balance' => null,
                    'remaining_exact' => null,
                    'applied_amount' => null,
                    'unallocated_amount' => null,
                ];
            }

            $consumed = BigDecimal::of($rawPaymentCurrencyAmount);
            if ($amount !== null && $consumed->isGreaterThan($amount)) {
                return [
                    'valid' => false,
                    'error' => __('fin1.consumed_exceeds_received'),
                    'error_field' => 'payment_currency_amount',
                    'remaining_balance' => null,
                    'remaining_exact' => null,
                    'applied_amount' => null,
                    'unallocated_amount' => null,
                ];
            }
        }

        // If allocated amount is known and valid, compute prospective remaining balance
        if ($allocated !== null) {
            $remaining = $total->minus($allocated);
            $unallocated = ($amount !== null && $consumed !== null) ? (string) $amount->minus($consumed) : null;

            return [
                'valid' => true,
                'error' => null,
                'error_field' => null,
                'remaining_balance' => SalesMoneyFormatter::formatMoneyInput($remaining),
                'remaining_exact' => (string) $remaining->toScale(6),
                'applied_amount' => (string) $allocated,
                'unallocated_amount' => $unallocated,
            ];
        }

        return $emptyResult;
    }
}
