<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

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
        $type = $this->discountType ?? self::TYPE_NONE;
        if (! in_array($type, [self::TYPE_NONE, self::TYPE_FIXED, self::TYPE_PERCENT], true)) {
            throw new InvalidArgumentException(__('purchasing.invalid_discount_type'));
        }
        try {
            $val = BigDecimal::of(trim((string) ($discountValue ?? '0')) !== '' ? trim((string) $discountValue) : '0');
        } catch (\Throwable) {
            throw new InvalidArgumentException(__('purchasing.invalid_discount_value'));
        }
        if ($val->isNegative()) {
            throw new InvalidArgumentException(__('purchasing.negative_discount_forbidden'));
        }
        if ($type === self::TYPE_PERCENT && $val->isGreaterThan(100)) {
            throw new InvalidArgumentException(__('purchasing.invalid_discount_percentage'));
        }
        if ($type === self::TYPE_NONE && ! $val->isZero()) {
            throw new InvalidArgumentException(__('purchasing.invalid_discount_value'));
        }
        $this->discountValue = $val;
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
