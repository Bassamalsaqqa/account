<?php

declare(strict_types=1);

namespace App\Application\Commands;

use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class SalesDraftIntent
{
    public const MAX_LINES = 100;

    public const MAX_NOTE_LENGTH = 2000;

    public const MAX_TERM_LENGTH = 2000;

    public const MAX_DESCRIPTION_LENGTH = 255;

    public const MAX_QUANTITY_SCALE = 6;

    public const MAX_MONEY_SCALE = 6;

    public const MAX_EXCHANGE_RATE_SCALE = 10;

    public const MAX_PAYLOAD_BYTES = 65536;

    public const MAX_ECONOMIC_STRING_LENGTH = 32;

    private const ALLOWED_HEADER_KEYS = [
        'customer_id',
        'warehouse_id',
        'currency_code',
        'exchange_rate',
        'issue_date',
        'due_date',
        'notes',
        'terms',
        'document_locale',
        'lines',
    ];

    private const ALLOWED_LINE_KEYS = [
        'product_id',
        'product_unit_id',
        'item_description',
        'quantity',
        'unit_price',
        'discount_type',
        'discount_value',
        'tax_rate_id',
    ];

    /**
     * Validate and normalize the draft sales invoice payload.
     * Normalizes economic values to fixed canonical scale (6 qty/money, 10 rate)
     * so that equivalent representations converge before request hashing.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function canonicalize(array $payload, bool $isUpdate = false): array
    {
        // 0. Bound total serialized payload size (64 KiB) before any processing
        $encoded = json_encode($payload);
        if ($encoded === false || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('Payload exceeds maximum serialized size limit.');
        }

        // 1. Reject unknown header keys
        foreach (array_keys($payload) as $key) {
            if (! in_array($key, self::ALLOWED_HEADER_KEYS, true)) {
                throw new InvalidArgumentException('Unknown header field present.');
            }
        }

        $canonical = [];

        // 2. Validate customer_id
        if (array_key_exists('customer_id', $payload)) {
            $val = $payload['customer_id'];
            if (! is_int($val) || $val <= 0) {
                throw new InvalidArgumentException('Field [customer_id] must be a positive integer.');
            }
            $canonical['customer_id'] = $val;
        } elseif (! $isUpdate) {
            throw new InvalidArgumentException('Field [customer_id] is required.');
        }

        // 3. Validate warehouse_id
        if (array_key_exists('warehouse_id', $payload)) {
            $val = $payload['warehouse_id'];
            if ($val !== null && (! is_int($val) || $val <= 0)) {
                throw new InvalidArgumentException('Field [warehouse_id] must be a positive integer or null.');
            }
            $canonical['warehouse_id'] = $val;
        }

        // 4. Validate currency_code
        if (array_key_exists('currency_code', $payload)) {
            $val = $payload['currency_code'];
            if (! is_string($val) || ! preg_match('/^[A-Za-z]{3}$/', trim($val))) {
                throw new InvalidArgumentException('Field [currency_code] must be a valid 3-letter currency code.');
            }
            $canonical['currency_code'] = strtoupper(trim($val));
        } elseif (! $isUpdate) {
            throw new InvalidArgumentException('Field [currency_code] is required.');
        }

        // 5. Validate exchange_rate
        if (array_key_exists('exchange_rate', $payload)) {
            $rateStr = $this->validateRateString($payload['exchange_rate'], 'exchange_rate');
            $canonical['exchange_rate'] = $rateStr;
        } elseif (! $isUpdate) {
            throw new InvalidArgumentException('Field [exchange_rate] is required.');
        }

        // 6. Validate issue_date
        $issueDate = null;
        if (array_key_exists('issue_date', $payload)) {
            $issueDate = $this->validateDate($payload['issue_date'], 'issue_date');
            $canonical['issue_date'] = $issueDate;
        } elseif (! $isUpdate) {
            throw new InvalidArgumentException('Field [issue_date] is required.');
        }

        // 7. Validate due_date
        if (array_key_exists('due_date', $payload)) {
            $val = $payload['due_date'];
            if ($val !== null) {
                if (! is_string($val)) {
                    throw new InvalidArgumentException('Field [due_date] must be a string or null.');
                }
                $dueDate = $this->validateDate($val, 'due_date');
                if ($issueDate !== null && $dueDate < $issueDate) {
                    throw new InvalidArgumentException('Due date must not precede the invoice date.');
                }
                $canonical['due_date'] = $dueDate;
            } else {
                $canonical['due_date'] = null;
            }
        }

        // 8. Validate notes and terms
        if (array_key_exists('notes', $payload)) {
            $val = $payload['notes'];
            if ($val !== null) {
                if (! is_string($val)) {
                    throw new InvalidArgumentException('Field [notes] must be a string or null.');
                }
                if (mb_strlen($val) > self::MAX_NOTE_LENGTH) {
                    throw new InvalidArgumentException('Field [notes] exceeds maximum length.');
                }
                $canonical['notes'] = trim($val);
            } else {
                $canonical['notes'] = null;
            }
        }

        if (array_key_exists('terms', $payload)) {
            $val = $payload['terms'];
            if ($val !== null) {
                if (! is_string($val)) {
                    throw new InvalidArgumentException('Field [terms] must be a string or null.');
                }
                if (mb_strlen($val) > self::MAX_TERM_LENGTH) {
                    throw new InvalidArgumentException('Field [terms] exceeds maximum length.');
                }
                $canonical['terms'] = trim($val);
            } else {
                $canonical['terms'] = null;
            }
        }

        // 9. Validate document_locale
        if (array_key_exists('document_locale', $payload)) {
            $val = $payload['document_locale'];
            if ($val !== null) {
                if (! is_string($val) || ! in_array(trim($val), ['ar', 'en'], true)) {
                    throw new InvalidArgumentException('Field [document_locale] must be "ar" or "en".');
                }
                $canonical['document_locale'] = trim($val);
            }
        }

        // 10. Validate lines
        if (array_key_exists('lines', $payload)) {
            $rawLines = $payload['lines'];
            if (! is_array($rawLines) || ! array_is_list($rawLines)) {
                throw new InvalidArgumentException('Field [lines] must be a list of line items.');
            }
            if (empty($rawLines)) {
                throw new InvalidArgumentException('Sales invoice must contain at least one line.');
            }
            if (count($rawLines) > self::MAX_LINES) {
                throw new InvalidArgumentException('Sales invoice exceeds maximum line limit.');
            }

            $canonicalLines = [];
            foreach ($rawLines as $index => $line) {
                if (! is_array($line)) {
                    throw new InvalidArgumentException("Line item must be an object/array at index [{$index}].");
                }
                $canonicalLines[] = $this->canonicalizeLine($line, $index);
            }
            $canonical['lines'] = $canonicalLines;
        } elseif (! $isUpdate) {
            throw new InvalidArgumentException('Sales invoice must contain at least one line.');
        }

        return $canonical;
    }

    /**
     * Compute a deterministic SHA-256 hash of the canonical economic payload.
     *
     * @param  array<string, mixed>  $canonicalPayload
     */
    public function canonicalHash(array $canonicalPayload, ?int $targetInvoiceId = null): string
    {
        $data = $canonicalPayload;
        if ($targetInvoiceId !== null) {
            $data['__target_invoice_id'] = $targetInvoiceId;
        }
        ksort($data);
        if (isset($data['lines']) && is_array($data['lines'])) {
            foreach ($data['lines'] as &$line) {
                ksort($line);
            }
            unset($line);
        }

        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function canonicalizeLine(array $line, int $index): array
    {
        foreach (array_keys($line) as $key) {
            if (! in_array($key, self::ALLOWED_LINE_KEYS, true)) {
                throw new InvalidArgumentException("Unknown line field present at line index [{$index}].");
            }
        }

        $canonicalLine = [];

        // product_id
        if (array_key_exists('product_id', $line)) {
            $val = $line['product_id'];
            if ($val !== null && (! is_int($val) || $val <= 0)) {
                throw new InvalidArgumentException("Field [product_id] must be a positive integer or null at line index [{$index}].");
            }
            $canonicalLine['product_id'] = $val;
        } else {
            $canonicalLine['product_id'] = null;
        }

        // product_unit_id
        if (array_key_exists('product_unit_id', $line)) {
            $val = $line['product_unit_id'];
            if ($val !== null && (! is_int($val) || $val <= 0)) {
                throw new InvalidArgumentException("Field [product_unit_id] must be a positive integer or null at line index [{$index}].");
            }
            $canonicalLine['product_unit_id'] = $val;
        } else {
            $canonicalLine['product_unit_id'] = null;
        }

        // item_description (max 255 chars)
        if (! array_key_exists('item_description', $line)) {
            throw new InvalidArgumentException("Field [item_description] is required at line index [{$index}].");
        }
        $desc = $line['item_description'];
        if (! is_string($desc) || trim($desc) === '') {
            throw new InvalidArgumentException("Field [item_description] must be a non-empty string at line index [{$index}].");
        }
        if (mb_strlen($desc) > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException('Field [item_description] exceeds maximum length of '.self::MAX_DESCRIPTION_LENGTH." characters at line index [{$index}].");
        }
        $canonicalLine['item_description'] = trim($desc);

        // quantity (normalized to scale 6 via Quantity magnitude guards)
        if (! array_key_exists('quantity', $line)) {
            throw new InvalidArgumentException("Field [quantity] is required at line index [{$index}].");
        }
        $canonicalLine['quantity'] = $this->validateQuantityString($line['quantity'], "lines.{$index}.quantity");

        // unit_price (normalized to scale 6 via MoneyAmount magnitude guards)
        if (! array_key_exists('unit_price', $line)) {
            throw new InvalidArgumentException("Field [unit_price] is required at line index [{$index}].");
        }
        $canonicalLine['unit_price'] = $this->validateMoneyString($line['unit_price'], "lines.{$index}.unit_price", allowZero: true);

        // discount_type ('fixed' or 'percent', NOT 'percentage')
        $discountType = null;
        if (array_key_exists('discount_type', $line) && $line['discount_type'] !== null) {
            $dt = $line['discount_type'];
            if (! is_string($dt) || ! in_array(trim($dt), ['fixed', 'percent'], true)) {
                throw new InvalidArgumentException("Field [discount_type] must be 'fixed', 'percent', or null at line index [{$index}].");
            }
            $discountType = trim($dt);
        }
        $canonicalLine['discount_type'] = $discountType;

        // discount_value (normalized to scale 6, default '0.000000')
        if (array_key_exists('discount_value', $line) && $line['discount_value'] !== null) {
            $discVal = $this->validateMoneyString($line['discount_value'], "lines.{$index}.discount_value", allowZero: true);
            if ($discountType === 'percent' && BigDecimal::of($discVal)->isGreaterThan(100)) {
                throw new InvalidArgumentException("Discount percent cannot exceed 100% at line index [{$index}].");
            }
            $canonicalLine['discount_value'] = $discVal;
        } else {
            $canonicalLine['discount_value'] = '0.000000';
        }

        // tax_rate_id
        if (array_key_exists('tax_rate_id', $line)) {
            $val = $line['tax_rate_id'];
            if ($val !== null && (! is_int($val) || $val <= 0)) {
                throw new InvalidArgumentException("Field [tax_rate_id] must be a positive integer or null at line index [{$index}].");
            }
            $canonicalLine['tax_rate_id'] = $val;
        } else {
            $canonicalLine['tax_rate_id'] = null;
        }

        return $canonicalLine;
    }

    /**
     * Enforce exact string representation for quantity, bounded length,
     * use Quantity magnitude guard, and normalize to 6 decimals.
     */
    private function validateQuantityString(mixed $value, string $fieldName): string
    {
        $clean = $this->sanitizeDecimalString($value, $fieldName, self::MAX_QUANTITY_SCALE);

        // Leverage existing Quantity magnitude guard
        $qtyVo = Quantity::of($clean);
        if (! $qtyVo->isPositive()) {
            throw new InvalidArgumentException("Economic field [{$fieldName}] must be strictly positive.");
        }

        return (string) $qtyVo->toBigDecimal()->toScale(self::MAX_QUANTITY_SCALE);
    }

    /**
     * Enforce exact string representation for money amounts, bounded length,
     * use MoneyAmount magnitude guard, and normalize to 6 decimals.
     */
    private function validateMoneyString(mixed $value, string $fieldName, bool $allowZero = true): string
    {
        $clean = $this->sanitizeDecimalString($value, $fieldName, self::MAX_MONEY_SCALE);

        // Leverage existing MoneyAmount magnitude guard
        $moneyVo = MoneyAmount::from($clean);
        $amount = $moneyVo->getAmount();

        if ($amount->isNegative()) {
            throw new InvalidArgumentException("Economic field [{$fieldName}] cannot be negative.");
        }

        if (! $allowZero && $amount->isZero()) {
            throw new InvalidArgumentException("Economic field [{$fieldName}] must be strictly positive.");
        }

        return (string) $amount->toScale(self::MAX_MONEY_SCALE);
    }

    /**
     * Enforce exact string representation for exchange rate, bounded length,
     * use ExchangeRate magnitude guard, and normalize to 10 decimals.
     */
    private function validateRateString(mixed $value, string $fieldName): string
    {
        $clean = $this->sanitizeDecimalString($value, $fieldName, self::MAX_EXCHANGE_RATE_SCALE);

        // Leverage existing ExchangeRate magnitude guard
        $rateVo = ExchangeRate::from($clean);
        $rateVal = $rateVo->getValue();

        if (! $rateVal->isPositive()) {
            throw new InvalidArgumentException("Economic field [{$fieldName}] must be strictly positive.");
        }

        return (string) $rateVal->toScale(self::MAX_EXCHANGE_RATE_SCALE);
    }

    /**
     * Common string sanitization and scale limit check without echoing raw input values.
     */
    private function sanitizeDecimalString(mixed $value, string $fieldName, int $maxScale): string
    {
        if (is_int($value) || is_float($value)) {
            throw new InvalidArgumentException(
                "Economic field [{$fieldName}] must be an exact decimal string; integer and float values are strictly forbidden."
            );
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "Economic field [{$fieldName}] must be an exact decimal string."
            );
        }

        $clean = trim($value);

        if (strlen($clean) > self::MAX_ECONOMIC_STRING_LENGTH) {
            throw new InvalidArgumentException(
                "Economic field [{$fieldName}] exceeds maximum allowed string length."
            );
        }

        // Strict positive decimal format: digits with optional decimal point followed by digits
        // Reject scientific notation (1e5, 1E-3), signs (+, -), whitespace, or any non-numeric chars
        if ($clean === '' || ! preg_match('/^\d+(\.\d+)?$/', $clean)) {
            throw new InvalidArgumentException(
                "Economic field [{$fieldName}] must be an exact unsigned decimal string without signs or scientific notation."
            );
        }

        $parts = explode('.', $clean);
        if (isset($parts[1]) && strlen($parts[1]) > $maxScale) {
            throw new InvalidArgumentException(
                "Economic field [{$fieldName}] exceeds maximum precision of {$maxScale} decimal places."
            );
        }

        return $clean;
    }

    private function validateDate(mixed $date, string $fieldName): string
    {
        if (! is_string($date)) {
            throw new InvalidArgumentException("Field [{$fieldName}] must be a date string.");
        }

        $clean = trim($date);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean)) {
            throw new InvalidArgumentException("Field [{$fieldName}] must be a valid Y-m-d date.");
        }

        [$year, $month, $day] = explode('-', $clean);
        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            throw new InvalidArgumentException("Field [{$fieldName}] is not a valid calendar date.");
        }

        return $clean;
    }
}
