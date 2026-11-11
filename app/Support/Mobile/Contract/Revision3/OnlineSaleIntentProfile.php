<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract\Revision3;

use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;
use App\Support\Mobile\Contract\Revision3\Exceptions\IntentProfileValidationException;
use App\Support\Mobile\Contract\StrictJsonObject;
use App\Support\Mobile\Contract\StrictJsonParser;

final class OnlineSaleIntentProfile
{
    private const string ULID_REGEX = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

    private const string UUID_REGEX = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    private const string CURRENCY_REGEX = '/^[A-Z]{3}$/';

    private const string POSITIVE_DECIMAL_REGEX = '/^(?:0\.(?=[0-9]{1,6}$)[0-9]*[1-9][0-9]*|[1-9][0-9]{0,13}(?:\.[0-9]{1,6})?)$/';

    private const string DECIMAL_MONEY_REGEX = '/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/';

    private const string TAX_RATE_REGEX = '/^(?:100(?:\.0{1,6})?|(?:0|[1-9][0-9]?)(?:\.[0-9]{1,6})?)$/';

    /**
     * Constructs the normative online canonical intent profile by combining:
     * - Authoritative company context from route / tenant membership
     * - Authoritative staff actor context from authenticated token session
     * - Server-qualified currency minor units context (0..6)
     * - Client request parameters: device_id, client_event_id, customer_id, warehouse_id, currency,
     *   ordered line items with exact decimal lexemes, and payment intent.
     * Preserves exact validated string decimal lexemes without implicit rounding, truncation, or repricing.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|string  $request
     * @return array<string, mixed>
     */
    public static function construct(array $context, array|string $request): array
    {
        self::validateContext($context);

        $req = self::normalizeAndValidateRawRequest($request);

        $actorId = $context['actor_id'];
        $companyId = $context['company_id'];
        $minorUnits = $context['currency_minor_units'];

        $lines = [];
        /** @var array<int, array<string, mixed>> $rawLines */
        $rawLines = $req['lines'];
        foreach ($rawLines as $line) {
            $lines[] = [
                'product_id' => $line['product_id'],
                'product_unit_id' => $line['product_unit_id'],
                'conversion_to_base' => $line['conversion_to_base'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate_id' => $line['tax_rate_id'],
                'tax_rate' => $line['tax_rate'],
                'tax_inclusive' => $line['tax_inclusive'],
            ];
        }

        /** @var array<string, mixed> $payment */
        $payment = $req['payment'];
        $settlementMode = $payment['settlement_mode'];
        $amount = $payment['amount'];

        if ($settlementMode === 'unpaid') {
            $profileMethod = null;
            $profileCashAccount = null;
        } else {
            $profileMethod = $payment['payment_method'];
            $profileCashAccount = $payment['cash_account_id'];
        }

        $profilePayment = [
            'settlement_mode' => $settlementMode,
            'payment_method' => $profileMethod,
            'cash_account_id' => $profileCashAccount,
            'amount' => $amount,
        ];

        return [
            'actor_id' => $actorId,
            'client_event_id' => $req['client_event_id'],
            'company_id' => $companyId,
            'currency' => $req['currency'],
            'currency_minor_units' => $minorUnits,
            'customer_id' => $req['customer_id'],
            'device_id' => $req['device_id'],
            'lines' => $lines,
            'payment' => $profilePayment,
            'warehouse_id' => $req['warehouse_id'],
        ];
    }

    /**
     * Serializes intent profile to canonical restricted JCS JSON string.
     *
     * @param  array<string, mixed>  $profile
     */
    public static function serialize(array $profile): string
    {
        return CanonicalJsonSerializer::serialize($profile);
    }

    /**
     * Computes 'sha256:<hex>' digest over canonical JSON bytes.
     *
     * @param  array<string, mixed>  $profile
     */
    public static function computeDigest(array $profile): string
    {
        return CanonicalJsonSerializer::computeDigest($profile);
    }

    /**
     * Normative verification function for online sale canonical intent profiles:
     * 1. Validates profile shape and invariants.
     * 2. Confirms no IEEE-754 floats are present anywhere.
     * 3. Serializes to RFC 8785 restricted JCS bytes.
     * 4. Computes SHA-256 digest and asserts equality against expectedDigest.
     *
     * @param  array<string, mixed>  $profile
     * @return array{0: bool, 1: string}
     */
    public static function verify(array $profile, string $expectedDigest): array
    {
        self::assertNoFloats($profile);
        self::validateProfileShape($profile);

        $computed = self::computeDigest($profile);
        if ($computed !== $expectedDigest) {
            throw new IntentProfileValidationException(
                "Intent profile digest mismatch! Computed: '{$computed}', expected: '{$expectedDigest}'"
            );
        }

        return [true, $computed];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function validateContext(array $context): void
    {
        $expectedContextKeys = ['actor_id', 'company_id', 'currency_minor_units'];
        $contextKeys = array_keys($context);
        sort($contextKeys);
        sort($expectedContextKeys);

        if ($contextKeys !== $expectedContextKeys) {
            $extraKeys = array_diff($contextKeys, $expectedContextKeys);
            $missingKeys = array_diff($expectedContextKeys, $contextKeys);
            $parts = [];
            if ($extraKeys !== []) {
                $parts[] = 'unknown extra keys '.json_encode(array_values($extraKeys));
            }
            if ($missingKeys !== []) {
                $parts[] = 'missing keys '.json_encode(array_values($missingKeys));
            }

            throw new IntentProfileValidationException('Invalid context keys: '.implode(', ', $parts));
        }

        $actorId = $context['actor_id'];
        $companyId = $context['company_id'];
        $minorUnits = $context['currency_minor_units'];

        if (! is_string($actorId) || preg_match(self::ULID_REGEX, $actorId) !== 1) {
            throw new IntentProfileValidationException("Invalid actor_id ULID in context: '".json_encode($actorId)."'");
        }

        if (! is_string($companyId) || preg_match(self::ULID_REGEX, $companyId) !== 1) {
            throw new IntentProfileValidationException("Invalid company_id ULID in context: '".json_encode($companyId)."'");
        }

        if (is_bool($minorUnits) || ! is_int($minorUnits) || $minorUnits < 0 || $minorUnits > 6) {
            throw new IntentProfileValidationException(
                "Invalid currency_minor_units in context (must be int 0..6, reject bool): '".json_encode($minorUnits)."'"
            );
        }
    }

    /**
     * @param  array<string, mixed>|string  $request
     * @return array<string, mixed>
     */
    private static function normalizeAndValidateRawRequest(array|string $request): array
    {
        if (is_string($request)) {
            try {
                $element = StrictJsonParser::parse($request);
            } catch (CanonicalSerializationException $e) {
                throw new IntentProfileValidationException("Raw request JSON parsing failed: {$e->getMessage()}", 0, $e);
            }
            if (! $element instanceof StrictJsonObject) {
                throw new IntentProfileValidationException('Raw request must be a JSON object');
            }
            $req = $element->toNative();
        } else {
            $req = $request;
        }

        self::assertNoFloats($req);

        $expectedRootKeys = ['client_event_id', 'currency', 'customer_id', 'device_id', 'lines', 'payment', 'warehouse_id'];
        $actualRootKeys = array_keys($req);
        sort($actualRootKeys);
        sort($expectedRootKeys);

        if ($actualRootKeys !== $expectedRootKeys) {
            $extra = array_diff($actualRootKeys, $expectedRootKeys);
            $missing = array_diff($expectedRootKeys, $actualRootKeys);
            $parts = [];
            if ($extra !== []) {
                $parts[] = 'unknown extra properties '.json_encode(array_values($extra));
            }
            if ($missing !== []) {
                $parts[] = 'missing required properties '.json_encode(array_values($missing));
            }

            throw new IntentProfileValidationException('Raw request schema validation failed: '.implode(', ', $parts));
        }

        if (! is_string($req['client_event_id']) || preg_match(self::UUID_REGEX, $req['client_event_id']) !== 1) {
            throw new IntentProfileValidationException("Invalid client_event_id UUID format: '".json_encode($req['client_event_id'])."'");
        }

        if (! is_string($req['device_id']) || preg_match(self::UUID_REGEX, $req['device_id']) !== 1) {
            throw new IntentProfileValidationException("Invalid device_id UUID format: '".json_encode($req['device_id'])."'");
        }

        if (! is_string($req['customer_id']) || preg_match(self::ULID_REGEX, $req['customer_id']) !== 1) {
            throw new IntentProfileValidationException("Invalid customer_id ULID format: '".json_encode($req['customer_id'])."'");
        }

        if (! is_string($req['warehouse_id']) || preg_match(self::ULID_REGEX, $req['warehouse_id']) !== 1) {
            throw new IntentProfileValidationException("Invalid warehouse_id ULID format: '".json_encode($req['warehouse_id'])."'");
        }

        if (! is_string($req['currency']) || preg_match(self::CURRENCY_REGEX, $req['currency']) !== 1) {
            throw new IntentProfileValidationException("Invalid currency format: '".json_encode($req['currency'])."'");
        }

        if (! is_array($req['lines']) || $req['lines'] === [] || count($req['lines']) > 100) {
            throw new IntentProfileValidationException('Online intent profile requires between 1 and 100 line items');
        }

        $expectedLineKeys = [
            'conversion_to_base',
            'product_id',
            'product_unit_id',
            'quantity',
            'tax_inclusive',
            'tax_rate',
            'tax_rate_id',
            'unit_price',
        ];

        foreach ($req['lines'] as $idx => $line) {
            if (! is_array($line)) {
                throw new IntentProfileValidationException("Line item at index {$idx} must be an object");
            }

            if (! array_key_exists('tax_rate_id', $line)) {
                throw new IntentProfileValidationException(
                    "Missing required 'tax_rate_id' on line {$idx} (ExplicitNoTax requires explicit null, never omitted key)"
                );
            }

            $lineKeys = array_keys($line);
            sort($lineKeys);
            if ($lineKeys !== $expectedLineKeys) {
                $extra = array_diff($lineKeys, $expectedLineKeys);
                $missing = array_diff($expectedLineKeys, $lineKeys);
                $parts = [];
                if ($extra !== []) {
                    $parts[] = 'unknown extra properties '.json_encode(array_values($extra));
                }
                if ($missing !== []) {
                    $parts[] = 'missing required properties '.json_encode(array_values($missing));
                }

                throw new IntentProfileValidationException("Line {$idx} schema validation failed: ".implode(', ', $parts));
            }

            if (! is_string($line['product_id']) || preg_match(self::ULID_REGEX, $line['product_id']) !== 1) {
                throw new IntentProfileValidationException("Invalid product_id ULID on line {$idx}");
            }

            if (! is_string($line['product_unit_id']) || preg_match(self::ULID_REGEX, $line['product_unit_id']) !== 1) {
                throw new IntentProfileValidationException("Invalid product_unit_id ULID on line {$idx}");
            }

            if (! is_string($line['conversion_to_base']) || preg_match(self::POSITIVE_DECIMAL_REGEX, $line['conversion_to_base']) !== 1) {
                throw new IntentProfileValidationException("Invalid positive conversion_to_base on line {$idx}");
            }

            if (! is_string($line['quantity']) || preg_match(self::POSITIVE_DECIMAL_REGEX, $line['quantity']) !== 1) {
                throw new IntentProfileValidationException("Invalid positive quantity on line {$idx}");
            }

            if (! is_string($line['unit_price']) || preg_match(self::DECIMAL_MONEY_REGEX, $line['unit_price']) !== 1) {
                throw new IntentProfileValidationException("Invalid unit_price on line {$idx}");
            }

            $taxRateId = $line['tax_rate_id'];
            if ($taxRateId === null) {
                if ($line['tax_rate'] !== '0.000000' || $line['tax_inclusive'] !== false) {
                    throw new IntentProfileValidationException(
                        "Explicit NoTax line at index {$idx} requires tax_rate='0.000000' and tax_inclusive=false"
                    );
                }
            } elseif (is_string($taxRateId)) {
                if (preg_match(self::ULID_REGEX, $taxRateId) !== 1) {
                    throw new IntentProfileValidationException("Invalid tax_rate_id ULID on line {$idx}");
                }
                if (! is_string($line['tax_rate']) || preg_match(self::TAX_RATE_REGEX, $line['tax_rate']) !== 1) {
                    throw new IntentProfileValidationException("Invalid tax_rate on line {$idx}");
                }
                if (! is_bool($line['tax_inclusive'])) {
                    throw new IntentProfileValidationException("tax_inclusive on line {$idx} must be a boolean");
                }
            } else {
                throw new IntentProfileValidationException("Invalid tax_rate_id on line {$idx} (must be ULID string or null)");
            }
        }

        $payment = $req['payment'];
        if (! is_array($payment)) {
            throw new IntentProfileValidationException('Payment must be an object');
        }

        $settlementMode = $payment['settlement_mode'] ?? null;
        if (! in_array($settlementMode, ['unpaid', 'full', 'partial'], true)) {
            throw new IntentProfileValidationException("Unsupported settlement_mode: '".json_encode($settlementMode)."'");
        }

        if (! array_key_exists('amount', $payment) || ! is_string($payment['amount']) || preg_match(self::DECIMAL_MONEY_REGEX, $payment['amount']) !== 1) {
            throw new IntentProfileValidationException('Payment amount must be a valid non-negative decimal string');
        }

        // Validate allowed payment properties
        $allowedPaymentKeys = ['amount', 'cash_account_id', 'payment_method', 'settlement_mode'];
        $paymentKeys = array_keys($payment);
        sort($paymentKeys);
        if (array_diff($paymentKeys, $allowedPaymentKeys) !== []) {
            $extra = array_diff($paymentKeys, $allowedPaymentKeys);

            throw new IntentProfileValidationException('Payment contains unknown extra properties: '.json_encode(array_values($extra)));
        }

        if ($settlementMode === 'unpaid') {
            $method = $payment['payment_method'] ?? null;
            $cashAccount = $payment['cash_account_id'] ?? null;
            if ($method !== null || $cashAccount !== null) {
                throw new IntentProfileValidationException('An unpaid sale cannot carry payment method or cash account instructions');
            }
        } else {
            if (! array_key_exists('payment_method', $payment) || $payment['payment_method'] !== 'cash') {
                throw new IntentProfileValidationException("Missing required payment_method for {$settlementMode} settlement mode (never defaults)");
            }
            if (! array_key_exists('cash_account_id', $payment) || ! is_string($payment['cash_account_id']) || preg_match(self::ULID_REGEX, $payment['cash_account_id']) !== 1) {
                throw new IntentProfileValidationException("Missing required cash_account_id for {$settlementMode} settlement mode (never defaults)");
            }
        }

        return $req;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private static function validateProfileShape(array $profile): void
    {
        $expectedProfileKeys = [
            'actor_id',
            'client_event_id',
            'company_id',
            'currency',
            'currency_minor_units',
            'customer_id',
            'device_id',
            'lines',
            'payment',
            'warehouse_id',
        ];
        $actualKeys = array_keys($profile);
        sort($actualKeys);
        sort($expectedProfileKeys);

        if ($actualKeys !== $expectedProfileKeys) {
            throw new IntentProfileValidationException('Profile shape mismatch against online-sale-intent-profile schema');
        }

        if (! is_string($profile['actor_id']) || preg_match(self::ULID_REGEX, $profile['actor_id']) !== 1) {
            throw new IntentProfileValidationException('Invalid actor_id ULID in profile');
        }

        if (! is_string($profile['company_id']) || preg_match(self::ULID_REGEX, $profile['company_id']) !== 1) {
            throw new IntentProfileValidationException('Invalid company_id ULID in profile');
        }

        if (is_bool($profile['currency_minor_units']) || ! is_int($profile['currency_minor_units']) || $profile['currency_minor_units'] < 0 || $profile['currency_minor_units'] > 6) {
            throw new IntentProfileValidationException('Invalid currency_minor_units in profile');
        }
    }

    private static function assertNoFloats(mixed $val, string $path = 'root'): void
    {
        if (is_float($val)) {
            throw new IntentProfileValidationException("Forbidden float detected at {$path}: {$val}");
        }

        if (is_array($val)) {
            foreach ($val as $k => $v) {
                self::assertNoFloats($v, "{$path}.{$k}");
            }
        }
    }
}
