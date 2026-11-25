<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class DocumentDiscountIntent
{
    public const string TYPE_NONE = 'none';

    public const string TYPE_FIXED = 'fixed';

    public const string TYPE_PERCENT = 'percent';

    public const string SCOPE_LINE = 'line';

    public const string SCOPE_DOCUMENT = 'document';

    public const string BASIS_SUBTOTAL = 'subtotal';

    public const int VERSION_V1 = 1;

    public const int VERSION_LEGACY = 1;

    public const int VERSION_CANONICAL = 2;

    public BigDecimal $discountValue;

    public function __construct(
        public ?string $discountType = self::TYPE_NONE,
        mixed $discountValue = '0',
        public string $discountScope = self::SCOPE_DOCUMENT,
        public string $discountBasis = self::BASIS_SUBTOTAL,
        public int $version = self::VERSION_CANONICAL,
    ) {
        $this->discountValue = self::parseValue($this->discountType, $discountValue);
    }

    /** @param array<string, mixed> $data */
    public static function fromDocumentInput(array $data, ?string $existingType = null, ?string $existingValue = null, string $existingScope = self::SCOPE_LINE): self
    {
        $type = array_key_exists('discount_type', $data) ? $data['discount_type'] : $existingType;
        $noType = $type === null || $type === self::TYPE_NONE;
        $explicitClear = $noType && array_key_exists('discount_type', $data);
        $value = $explicitClear ? '0' : ($data['discount_value'] ?? ($noType ? '0' : $existingValue) ?? '0');
        $scope = $data['discount_scope'] ?? ($type !== null && $type !== self::TYPE_NONE ? self::SCOPE_DOCUMENT : $existingScope);
        if (! in_array($scope, [self::SCOPE_LINE, self::SCOPE_DOCUMENT], true)) {
            throw new InvalidArgumentException(__('sales.invalid_discount_scope'));
        }
        $intent = new self($type, $value, $scope, version: $scope === self::SCOPE_DOCUMENT ? self::VERSION_CANONICAL : self::VERSION_LEGACY);
        if ($scope === self::SCOPE_LINE && ! $intent->isNone()) {
            throw new InvalidArgumentException(__('sales.invalid_discount_scope'));
        }

        return $intent;
    }

    public static function from(
        ?string $type = self::TYPE_NONE,
        mixed $value = '0',
        string $scope = self::SCOPE_DOCUMENT,
        string $basis = self::BASIS_SUBTOTAL,
        int $version = self::VERSION_CANONICAL,
        ?int $currencyMinorUnits = null,
        ?int $calculationVersion = null,
    ): self {
        return new self(
            discountType: $type,
            discountValue: $value,
            discountScope: $scope,
            discountBasis: $basis,
            version: $calculationVersion ?? $version,
        );
    }

    public static function parseValue(?string $type, mixed $value, string $domain = 'sales'): BigDecimal
    {
        $type ??= self::TYPE_NONE;
        if (! in_array($type, [self::TYPE_NONE, self::TYPE_FIXED, self::TYPE_PERCENT], true)) {
            throw new InvalidArgumentException(__("{$domain}.invalid_discount_type"));
        }
        if ($type === self::TYPE_NONE && ($value === null || (is_string($value) && trim($value) === ''))) {
            return BigDecimal::zero();
        }
        if (! is_string($value) && ! is_int($value) && ! ($value instanceof BigDecimal)) {
            throw new InvalidArgumentException(__("{$domain}.invalid_discount_value"));
        }
        $input = trim((string) $value);
        // Match the DECIMAL(20,6) storage envelope before constructing a decimal.
        if (! preg_match('/\A-?\d{1,14}(?:\.\d{1,6})?\z/', $input)) {
            throw new InvalidArgumentException(__("{$domain}.invalid_discount_value"));
        }
        $amount = BigDecimal::of($input);
        if ($amount->isNegative()) {
            throw new InvalidArgumentException(__("{$domain}.negative_discount_forbidden"));
        }
        if ($type === self::TYPE_PERCENT && $amount->isGreaterThan(100)) {
            throw new InvalidArgumentException(__("{$domain}.invalid_discount_percentage"));
        }
        if ($type === self::TYPE_NONE && ! $amount->isZero()) {
            throw new InvalidArgumentException(__("{$domain}.invalid_discount_value"));
        }

        return $amount;
    }

    public function isNone(): bool
    {
        return $this->discountType === null
            || $this->discountType === self::TYPE_NONE
            || $this->discountValue->isZero();
    }

    public function isDocumentScope(): bool
    {
        return $this->discountScope === self::SCOPE_DOCUMENT;
    }
}
