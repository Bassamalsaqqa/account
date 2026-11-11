<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract\Revision3;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class PaymentSettlementValidator
{
    private const string DECIMAL_MONEY_REGEX = '/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/';

    private const string ULID_REGEX = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

    public static function validateCurrencyMinorUnits(mixed $cmu): bool
    {
        if ($cmu === null || is_bool($cmu) || ! is_int($cmu)) {
            return false;
        }

        return $cmu >= 0 && $cmu <= 6;
    }

    public static function parseAndValidateMonetaryAmount(mixed $val, int $cmu): ?BigDecimal
    {
        if (! is_string($val)) {
            return null;
        }

        if (preg_match(self::DECIMAL_MONEY_REGEX, $val) !== 1) {
            return null;
        }

        try {
            $d = BigDecimal::of($val);
        } catch (Throwable) {
            return null;
        }

        $cents = $d->multipliedBy(BigDecimal::of(10)->power($cmu));
        if ($cents->hasNonZeroFractionalPart()) {
            return null;
        }

        return $d;
    }

    /**
     * @return array{subtotal: string, net_before_tax: string, tax_amount: string, line_total: string}
     */
    public static function calculateCanonicalLine(
        string $quantityStr,
        string $unitPriceStr,
        string $taxRateStr,
        bool $taxInclusive,
        int $minorUnits
    ): array {
        if (! self::validateCurrencyMinorUnits($minorUnits)) {
            throw new InvalidArgumentException("minor_units must be an explicit integer between 0 and 6 (got {$minorUnits})");
        }

        $q = BigDecimal::of($quantityStr);
        $p = BigDecimal::of($unitPriceStr);
        $sub = $q->multipliedBy($p)->toScale($minorUnits, RoundingMode::HALF_UP);
        $rate = BigDecimal::of($taxRateStr)->dividedBy(BigDecimal::of(100), 10, RoundingMode::HALF_UP);

        if ($taxInclusive) {
            $net = $sub->dividedBy(BigDecimal::of(1)->plus($rate), $minorUnits, RoundingMode::HALF_UP);
            $tax = $sub->minus($net);
            $tot = $sub;
        } else {
            $net = $sub;
            $tax = $sub->multipliedBy($rate)->toScale($minorUnits, RoundingMode::HALF_UP);
            $tot = $sub->plus($tax);
        }

        return [
            'subtotal' => (string) $sub->toScale(6),
            'net_before_tax' => (string) $net->toScale(6),
            'tax_amount' => (string) $tax->toScale(6),
            'line_total' => (string) $tot->toScale(6),
        ];
    }

    public static function verifyPaymentDecimalConsistency(
        mixed $payment,
        mixed $grandTotalStr,
        mixed $currencyMinorUnits = null
    ): bool {
        if (! is_array($payment)) {
            return false;
        }

        if (! self::validateCurrencyMinorUnits($currencyMinorUnits)) {
            return false;
        }

        /** @var int $cmu */
        $cmu = $currencyMinorUnits;

        $gt = self::parseAndValidateMonetaryAmount($grandTotalStr, $cmu);
        if ($gt === null) {
            return false;
        }

        // Positive financial grand total ONLY policy: total <= 0 strictly rejected
        if (! $gt->isPositive()) {
            return false;
        }

        // 1. Offline payment tender conservation branch
        if (array_key_exists('amount_tendered', $payment) || array_key_exists('amount_settled', $payment)) {
            if (
                ! array_key_exists('amount_tendered', $payment)
                || ! array_key_exists('amount_settled', $payment)
                || ! array_key_exists('change_returned', $payment)
            ) {
                return false;
            }

            $tendered = self::parseAndValidateMonetaryAmount($payment['amount_tendered'], $cmu);
            $settled = self::parseAndValidateMonetaryAmount($payment['amount_settled'], $cmu);
            $change = self::parseAndValidateMonetaryAmount($payment['change_returned'], $cmu);

            if ($tendered === null || $settled === null || $change === null) {
                return false;
            }

            if (($payment['mode'] ?? null) !== 'full') {
                return false;
            }

            if (! $settled->isPositive()) {
                return false;
            }

            if (! $settled->isEqualTo($gt)) {
                return false;
            }

            return $tendered->isEqualTo($settled->plus($change));
        }

        // 2. Online payment intent branch
        if (array_key_exists('settlement_mode', $payment)) {
            $mode = $payment['settlement_mode'];
            if (! array_key_exists('amount', $payment)) {
                return false;
            }

            $amt = self::parseAndValidateMonetaryAmount($payment['amount'], $cmu);
            if ($amt === null) {
                return false;
            }

            if ($mode === 'unpaid') {
                return $amt->isZero();
            }

            if ($mode === 'partial') {
                return $amt->isPositive() && $amt->isLessThan($gt);
            }

            if ($mode === 'full') {
                return $amt->isEqualTo($gt);
            }

            return false;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  (Closure(string, string): mixed)|null  $cashAccountResolver
     * @return array<string, mixed>
     */
    public static function adaptOfflineCashToCanonicalReceipt(
        array $payment,
        string $grandTotalStr,
        int $currencyMinorUnits,
        ?string $reviewedPaymentDate = null,
        ?Closure $cashAccountResolver = null,
        ?string $companyId = null,
        ?int $customerId = null
    ): array {
        if (! self::validateCurrencyMinorUnits($currencyMinorUnits)) {
            throw new InvalidArgumentException("currency_minor_units must be an explicit integer in 0..6 (got {$currencyMinorUnits})");
        }

        if (! self::verifyPaymentDecimalConsistency($payment, $grandTotalStr, $currencyMinorUnits)) {
            throw new InvalidArgumentException('Invalid offline cash payment tender conservation or non-positive settled amount');
        }

        $settledStr = (string) $payment['amount_settled'];
        $tenderedStr = (string) $payment['amount_tendered'];
        $changeStr = (string) $payment['change_returned'];

        $res = [
            'audit' => [
                'mode' => 'full',
                'payment_method' => 'cash',
                'amount_tendered' => $tenderedStr,
                'amount_settled' => $settledStr,
                'change_returned' => $changeStr,
            ],
            'receipt_command' => [
                'mode' => 'full',
                'payment_method' => 'cash',
                'receipt_amount' => $settledStr,
            ],
            'receipt_amount' => $settledStr,
            'amount_tendered' => $tenderedStr,
            'change_returned' => $changeStr,
        ];

        if ($reviewedPaymentDate !== null && $cashAccountResolver !== null) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewedPaymentDate) !== 1) {
                throw new InvalidArgumentException("reviewed_payment_date must be valid Y-m-d format, got '{$reviewedPaymentDate}'");
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $reviewedPaymentDate);
            if ($date === false || $date->format('Y-m-d') !== $reviewedPaymentDate) {
                throw new InvalidArgumentException("reviewed_payment_date is not a valid calendar date: '{$reviewedPaymentDate}'");
            }

            if ($customerId === null || $customerId <= 0) {
                throw new InvalidArgumentException('customer_id must be a positive integer for canonical receipt command');
            }

            $wireAcct = $payment['cash_account_id'] ?? null;
            if (! is_string($wireAcct) || $wireAcct === '') {
                throw new InvalidArgumentException('cash_account_id required to resolve money_account_id');
            }

            if (! is_string($companyId) || preg_match(self::ULID_REGEX, $companyId) !== 1) {
                throw new InvalidArgumentException('Explicit company identity required');
            }

            try {
                $internalId = $cashAccountResolver($companyId, $wireAcct);
            } catch (Throwable) {
                throw new InvalidArgumentException('Cash account resolution failed');
            }

            if (! is_int($internalId) || $internalId <= 0) {
                throw new InvalidArgumentException('Resolved money_account_id must be a positive integer');
            }

            $res['canonical_command'] = [
                'customer_id' => $customerId,
                'money_account_id' => $internalId,
                'payment_date' => $reviewedPaymentDate,
                'payment_method' => 'cash',
                'amount' => $settledStr,
                'exchange_rate' => '1.0000000000',
            ];
        }

        return $res;
    }

    /**
     * @param  array<string, mixed>  $intentProfile
     * @param  (Closure(string, string): mixed)|null  $cashAccountResolver
     * @return array<string, mixed>
     */
    public static function adaptOnlinePaymentToCanonicalChoice(
        array $intentProfile,
        string $grandTotalStr,
        int $currencyMinorUnits,
        ?string $reviewedPaymentDate = null,
        ?Closure $cashAccountResolver = null
    ): array {
        if (! self::validateCurrencyMinorUnits($currencyMinorUnits)) {
            throw new InvalidArgumentException("currency_minor_units must be an explicit integer in 0..6 (got {$currencyMinorUnits})");
        }

        $payment = $intentProfile['payment'] ?? [];
        if (! is_array($payment) || ! self::verifyPaymentDecimalConsistency($payment, $grandTotalStr, $currencyMinorUnits)) {
            throw new InvalidArgumentException('Online payment decimal consistency or domain bound violation');
        }

        $mode = $payment['settlement_mode'] ?? null;
        if ($mode === 'unpaid') {
            return ['mode' => 'unpaid'];
        }

        if ($mode === 'full' || $mode === 'partial') {
            if ($reviewedPaymentDate === null) {
                throw new InvalidArgumentException('reviewed_payment_date is mandatory for full/partial settlement (no default permitted)');
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewedPaymentDate) !== 1) {
                throw new InvalidArgumentException("reviewed_payment_date must be valid Y-m-d format, got '{$reviewedPaymentDate}'");
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $reviewedPaymentDate);
            if ($date === false || $date->format('Y-m-d') !== $reviewedPaymentDate) {
                throw new InvalidArgumentException("reviewed_payment_date is not a valid calendar date: '{$reviewedPaymentDate}'");
            }

            if ($cashAccountResolver === null) {
                throw new InvalidArgumentException('cash_account_resolver is mandatory for full/partial settlement (company-scoped resolver required)');
            }

            $companyId = $intentProfile['company_id'] ?? null;
            if (! is_string($companyId) || preg_match(self::ULID_REGEX, $companyId) !== 1) {
                throw new InvalidArgumentException('Explicit company identity required');
            }

            $wireAcctId = $payment['cash_account_id'] ?? null;
            if (! is_string($wireAcctId) || $wireAcctId === '') {
                throw new InvalidArgumentException('Missing cash_account_id in payment intent profile');
            }

            try {
                $internalMoneyAccountId = $cashAccountResolver($companyId, $wireAcctId);
            } catch (Throwable) {
                throw new InvalidArgumentException('Cash account resolution failed');
            }

            if (! is_int($internalMoneyAccountId) || $internalMoneyAccountId <= 0) {
                throw new InvalidArgumentException('Resolved money_account_id must be a positive integer');
            }

            $amtStr = (string) $payment['amount'];
            $amt = BigDecimal::of($amtStr);
            $total = BigDecimal::of($grandTotalStr);

            if ($mode === 'full') {
                if (! $amt->isEqualTo($total)) {
                    throw new InvalidArgumentException("Full settlement amount {$amtStr} must equal grand total {$grandTotalStr}");
                }

                return [
                    'mode' => 'full',
                    'payment_method' => 'cash',
                    'money_account_id' => $internalMoneyAccountId,
                    'payment_date' => $reviewedPaymentDate,
                    'amount' => $amtStr,
                    'exchange_rate' => '1.0000000000',
                    'invoice_amount' => $grandTotalStr,
                    'payment_currency_amount' => $amtStr,
                ];
            }

            return [
                'mode' => 'partial',
                'payment_method' => 'cash',
                'money_account_id' => $internalMoneyAccountId,
                'payment_date' => $reviewedPaymentDate,
                'amount' => $amtStr,
                'exchange_rate' => '1.0000000000',
                'invoice_amount' => $amtStr,
                'payment_currency_amount' => $amtStr,
            ];
        }

        throw new InvalidArgumentException("Unsupported settlement mode: {$mode}");
    }
}
