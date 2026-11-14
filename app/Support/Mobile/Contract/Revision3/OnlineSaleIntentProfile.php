<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract\Revision3;

use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Contract\ContractConstants;
use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;
use App\Support\Mobile\Contract\Revision3\Exceptions\IntentProfileValidationException;
use App\Support\Mobile\Contract\StrictJsonArray;
use App\Support\Mobile\Contract\StrictJsonObject;
use App\Support\Mobile\Contract\StrictJsonParser;
use Brick\Math\BigDecimal;

final class OnlineSaleIntentProfile
{
    private const string ULID_REGEX = '/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/';

    private const string UUID_REGEX = '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';

    private const string CURRENCY_REGEX = '/\A[A-Z]{3}\z/';

    private const string POSITIVE_DECIMAL_REGEX = '/\A(?:0\.(?=[0-9]{1,6}\z)[0-9]*[1-9][0-9]*|[1-9][0-9]{0,13}(?:\.[0-9]{1,6})?)\z/';

    private const string DECIMAL_MONEY_REGEX = '/\A(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?\z/';

    private const string TAX_RATE_REGEX = '/\A(?:100(?:\.0{1,6})?|(?:0|[1-9][0-9]?)(?:\.[0-9]{1,6})?)\z/';

    /**
     * Constructs the normative online canonical intent profile by combining:
     * - Authoritative company context from route / tenant membership
     * - Authoritative staff actor context from authenticated token session
     * - Server-qualified currency minor units context (0..6)
     * - Client request parameters: device_id, client_event_id, customer_id, warehouse_id, currency,
     *   ordered line items with exact decimal lexemes, and payment intent.
     * Preserves exact validated string decimal lexemes without implicit rounding, truncation, or repricing.
     *
     * @param  array<array-key, mixed>  $context
     * @param  array<array-key, mixed>|string  $request
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
        /** @var list<array<string, mixed>> $rawLines */
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

        $profile = [
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

        // Ensure constructed profile is 100% compliant with normative profile schema
        self::validateNormativeProfile($profile);

        return $profile;
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
     * 1. Validates entire nested normative profile shape and invariants (zero normalization).
     * 2. Confirms no IEEE-754 floats, surrogates, or invalid UTF-8 are present anywhere.
     * 3. Serializes to RFC 8785 restricted JCS bytes.
     * 4. Computes SHA-256 digest and asserts equality against expectedDigest.
     *
     * @param  array<string, mixed>  $profile
     * @return array{0: bool, 1: string}
     */
    public static function verify(array $profile, string $expectedDigest): array
    {
        self::validateNormativeProfile($profile);

        $computed = self::computeDigest($profile);
        if ($computed !== $expectedDigest) {
            throw new IntentProfileValidationException(
                "Intent profile digest mismatch! Computed: '{$computed}', expected: '{$expectedDigest}'"
            );
        }

        return [true, $computed];
    }

    /**
     * Validates the normative online sale intent profile against the full schema.
     *
     * @param  array<array-key, mixed>  $profile
     */
    public static function validateNormativeProfile(array $profile): void
    {
        self::assertValidNativeStructure($profile);

        if (array_is_list($profile)) {
            throw new IntentProfileValidationException('Profile must be an associative object');
        }

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
            $extra = array_diff($actualKeys, $expectedProfileKeys);
            $missing = array_diff($expectedProfileKeys, $actualKeys);
            $parts = [];
            if ($extra !== []) {
                $parts[] = 'unknown extra properties '.json_encode(array_values($extra));
            }
            if ($missing !== []) {
                $parts[] = 'missing required properties '.json_encode(array_values($missing));
            }

            throw new IntentProfileValidationException('Profile shape mismatch against online-sale-intent-profile schema: '.implode(', ', $parts));
        }

        if (! is_string($profile['actor_id']) || preg_match(self::ULID_REGEX, $profile['actor_id']) !== 1) {
            throw new IntentProfileValidationException('Invalid actor_id ULID in profile');
        }

        if (! is_string($profile['client_event_id']) || preg_match(self::UUID_REGEX, $profile['client_event_id']) !== 1) {
            throw new IntentProfileValidationException('Invalid client_event_id UUID in profile');
        }

        if (! is_string($profile['company_id']) || preg_match(self::ULID_REGEX, $profile['company_id']) !== 1) {
            throw new IntentProfileValidationException('Invalid company_id ULID in profile');
        }

        if (! is_string($profile['currency']) || preg_match(self::CURRENCY_REGEX, $profile['currency']) !== 1) {
            throw new IntentProfileValidationException('Invalid currency in profile');
        }

        if (is_bool($profile['currency_minor_units']) || ! is_int($profile['currency_minor_units']) || $profile['currency_minor_units'] < 0 || $profile['currency_minor_units'] > 6) {
            throw new IntentProfileValidationException('Invalid currency_minor_units in profile (must be int 0..6)');
        }

        if (! is_string($profile['customer_id']) || preg_match(self::ULID_REGEX, $profile['customer_id']) !== 1) {
            throw new IntentProfileValidationException('Invalid customer_id ULID in profile');
        }

        if (! is_string($profile['device_id']) || preg_match(self::UUID_REGEX, $profile['device_id']) !== 1) {
            throw new IntentProfileValidationException('Invalid device_id UUID in profile');
        }

        if (! is_string($profile['warehouse_id']) || preg_match(self::ULID_REGEX, $profile['warehouse_id']) !== 1) {
            throw new IntentProfileValidationException('Invalid warehouse_id ULID in profile');
        }

        if (! is_array($profile['lines']) || ! array_is_list($profile['lines']) || $profile['lines'] === [] || count($profile['lines']) > 100) {
            throw new IntentProfileValidationException('Profile lines must be a non-empty list of at most 100 items');
        }

        foreach ($profile['lines'] as $idx => $line) {
            if (! is_array($line) || array_is_list($line)) {
                throw new IntentProfileValidationException("Profile line at index {$idx} must be an associative object");
            }
            self::validateLineItem($line, $idx);
        }

        $payment = $profile['payment'];
        if (! is_array($payment) || array_is_list($payment)) {
            throw new IntentProfileValidationException('Profile payment must be an associative object');
        }

        $expectedPaymentKeys = ['amount', 'cash_account_id', 'payment_method', 'settlement_mode'];
        $actualPaymentKeys = array_keys($payment);
        sort($actualPaymentKeys);
        sort($expectedPaymentKeys);

        if ($actualPaymentKeys !== $expectedPaymentKeys) {
            throw new IntentProfileValidationException('Profile payment must explicitly contain exactly: settlement_mode, payment_method, cash_account_id, amount');
        }

        $mode = $payment['settlement_mode'];
        if (! in_array($mode, ['unpaid', 'full', 'partial'], true)) {
            throw new IntentProfileValidationException("Profile payment settlement_mode must be unpaid, full, or partial (got '".json_encode($mode)."')");
        }

        if (! is_string($payment['amount']) || preg_match(self::DECIMAL_MONEY_REGEX, $payment['amount']) !== 1) {
            throw new IntentProfileValidationException('Profile payment amount must be a valid non-negative decimal string');
        }

        if ($mode === 'unpaid') {
            if ($payment['payment_method'] !== null) {
                throw new IntentProfileValidationException("Profile unpaid payment requires payment_method to be explicit null (got '".json_encode($payment['payment_method'])."')");
            }
            if ($payment['cash_account_id'] !== null) {
                throw new IntentProfileValidationException("Profile unpaid payment requires cash_account_id to be explicit null (got '".json_encode($payment['cash_account_id'])."')");
            }
            if (! BigDecimal::of($payment['amount'])->isZero()) {
                throw new IntentProfileValidationException("Profile unpaid payment requires amount to be zero (got '{$payment['amount']}')");
            }
        } else {
            if ($payment['payment_method'] !== 'cash') {
                throw new IntentProfileValidationException("Profile {$mode} payment requires payment_method to be 'cash'");
            }
            if (! is_string($payment['cash_account_id']) || preg_match(self::ULID_REGEX, $payment['cash_account_id']) !== 1) {
                throw new IntentProfileValidationException("Profile {$mode} payment requires valid cash_account_id ULID");
            }
            if (BigDecimal::of($payment['amount'])->isZero()) {
                throw new IntentProfileValidationException("Profile {$mode} payment requires strictly positive amount");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private static function validateContext(array $context): void
    {
        self::assertValidNativeStructure($context, 'context');

        if (array_is_list($context)) {
            throw new IntentProfileValidationException('Context must be an associative array');
        }

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
     * @param  array<array-key, mixed>|string  $request
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

            // Defect 2: Validate AST shape BEFORE conversion to native array
            $linesElem = $element->get('lines');
            if ($linesElem === null) {
                throw new IntentProfileValidationException('Raw request schema validation failed: missing required properties ["lines"]');
            }
            if (! $linesElem instanceof StrictJsonArray) {
                throw new IntentProfileValidationException("Property 'lines' must be a JSON array, got JSON object or primitive");
            }
            foreach ($linesElem->elements as $idx => $item) {
                if (! $item instanceof StrictJsonObject) {
                    throw new IntentProfileValidationException("Line item at index {$idx} must be a JSON object");
                }
            }

            $paymentElem = $element->get('payment');
            if ($paymentElem === null) {
                throw new IntentProfileValidationException('Raw request schema validation failed: missing required properties ["payment"]');
            }
            if (! $paymentElem instanceof StrictJsonObject) {
                throw new IntentProfileValidationException("Property 'payment' must be a JSON object");
            }

            $req = $element->toNative();
        } else {
            if (array_is_list($request)) {
                throw new IntentProfileValidationException('Raw request must be an associative array');
            }
            $req = $request;
        }

        self::assertValidNativeStructure($req);

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

        if (! is_array($req['lines']) || ! array_is_list($req['lines'])) {
            throw new IntentProfileValidationException("Property 'lines' must be a list of line items");
        }

        if ($req['lines'] === [] || count($req['lines']) > 100) {
            throw new IntentProfileValidationException('Online intent profile requires between 1 and 100 line items');
        }

        foreach ($req['lines'] as $idx => $line) {
            if (! is_array($line) || array_is_list($line)) {
                throw new IntentProfileValidationException("Line item at index {$idx} must be an associative object");
            }
            self::validateLineItem($line, $idx);
        }

        $payment = $req['payment'];
        if (! is_array($payment) || array_is_list($payment)) {
            throw new IntentProfileValidationException('Payment must be an associative object');
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

        // Defect 3: Enforce full normative request schema rules before projection
        if ($settlementMode === 'unpaid') {
            if (! BigDecimal::of($payment['amount'])->isZero()) {
                throw new IntentProfileValidationException("Unpaid settlement mode requires amount to be zero, got '{$payment['amount']}'");
            }
            if (array_key_exists('payment_method', $payment) && $payment['payment_method'] !== null) {
                throw new IntentProfileValidationException('An unpaid sale cannot carry payment method');
            }
            if (array_key_exists('cash_account_id', $payment) && $payment['cash_account_id'] !== null) {
                throw new IntentProfileValidationException('An unpaid sale cannot carry cash account instructions');
            }
        } else {
            // full or partial
            if (BigDecimal::of($payment['amount'])->isZero()) {
                throw new IntentProfileValidationException("{$settlementMode} settlement mode requires positive amount, got '{$payment['amount']}'");
            }
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
     * @param  array<string, mixed>  $line
     */
    private static function validateLineItem(array $line, int $idx): void
    {
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

    private static function assertValidNativeStructure(mixed $val, string $path = 'root', int $depth = 0): void
    {
        if ($depth > ContractConstants::MAX_NESTING_DEPTH) {
            throw new IntentProfileValidationException('Maximum structure nesting depth exceeded');
        }
        if (is_object($val)) {
            throw new IntentProfileValidationException("Forbidden object detected at {$path}");
        }

        if (is_float($val)) {
            throw new IntentProfileValidationException("Forbidden float detected at {$path}: {$val}");
        }

        if (is_string($val)) {
            if (preg_match('/(?:\xED[\xA0-\xBF][\x80-\xBF])/', $val) === 1) {
                throw new IntentProfileValidationException("Forbidden Unicode surrogate detected at {$path}");
            }
            if (! mb_check_encoding($val, 'UTF-8') || preg_match('//u', $val) !== 1) {
                throw new IntentProfileValidationException("Invalid UTF-8 string at {$path}");
            }
        }

        if (is_array($val)) {
            foreach ($val as $k => $v) {
                if (is_string($k)) {
                    if (preg_match('/(?:\xED[\xA0-\xBF][\x80-\xBF])/', $k) === 1) {
                        throw new IntentProfileValidationException("Forbidden Unicode surrogate in key at {$path}.{$k}");
                    }
                    if (! mb_check_encoding($k, 'UTF-8') || preg_match('//u', $k) !== 1) {
                        throw new IntentProfileValidationException("Invalid UTF-8 string in key at {$path}.{$k}");
                    }
                }
                self::assertValidNativeStructure($v, "{$path}.{$k}", $depth + 1);
            }
        }
    }
}
