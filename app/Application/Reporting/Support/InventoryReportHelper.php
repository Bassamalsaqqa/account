<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;

final class InventoryReportHelper
{
    /**
     * Validate inventory-specific filters and enforce tenant isolation.
     *
     * @param  list<string>  $disallowedFilters
     */
    public static function validateFilters(Company $company, ReportFilters $filters, array $disallowedFilters = []): void
    {
        foreach ($disallowedFilters as $disallowed) {
            $value = match ($disallowed) {
                'customer_id' => $filters->customerId,
                'vendor_id' => $filters->vendorId,
                'employee_id' => $filters->employeeId,
                'money_account_id' => $filters->moneyAccountId,
                default => null,
            };

            if ($value !== null) {
                throw InvalidReportFilterException::invalidValue($disallowed, $value, "Filter [{$disallowed}] is not supported for this inventory report.");
            }
        }

        if ($filters->productId !== null) {
            $exists = Product::withTrashed()
                ->withoutGlobalScopes()
                ->where('id', $filters->productId)
                ->where('company_id', $company->id)
                ->exists();

            if (! $exists) {
                throw InvalidReportFilterException::foreignEntity('product_id', $filters->productId, (int) $company->id);
            }
        }

        if ($filters->warehouseId !== null) {
            $exists = Warehouse::withoutGlobalScopes()
                ->where('id', $filters->warehouseId)
                ->where('company_id', $company->id)
                ->exists();

            if (! $exists) {
                throw InvalidReportFilterException::foreignEntity('warehouse_id', $filters->warehouseId, (int) $company->id);
            }
        }

        if ($filters->categoryId !== null) {
            $exists = ProductCategory::withoutGlobalScopes()
                ->where('id', $filters->categoryId)
                ->where('company_id', $company->id)
                ->exists();

            if (! $exists) {
                throw InvalidReportFilterException::foreignEntity('category_id', $filters->categoryId, (int) $company->id);
            }
        }
    }

    /**
     * Format quantity to exact six decimal places.
     */
    public static function formatQuantity(string|int|BigDecimal|null $quantity): string
    {
        if ($quantity === null || $quantity === '') {
            return '0.000000';
        }

        return BigDecimal::of((string) $quantity)->toScale(6, RoundingMode::HALF_UP)->__toString();
    }

    /**
     * Format money value to exact decimal places, respecting JOD (3 decimals) or custom scale.
     */
    public static function formatValue(string|int|BigDecimal|null $value, ?string $currency = null, int $defaultScale = 6): string
    {
        $scale = $currency === 'JOD' ? 3 : $defaultScale;
        if ($value === null || $value === '') {
            return '0.'.str_repeat('0', $scale);
        }

        return BigDecimal::of((string) $value)->toScale($scale, RoundingMode::HALF_UP)->__toString();
    }

    /**
     * Compute average unit cost = value / quantity with exact decimal math.
     * Returns null if quantity is zero or negative.
     */
    public static function calculateAverageCost(string|BigDecimal $value, string|BigDecimal $quantity, int $scale = 6): ?string
    {
        $qty = $quantity instanceof BigDecimal ? $quantity : BigDecimal::of((string) $quantity);
        $val = $value instanceof BigDecimal ? $value : BigDecimal::of((string) $value);

        if ($qty->isLessThanOrEqualTo(BigDecimal::zero())) {
            return null;
        }

        return $val->dividedBy($qty, $scale, RoundingMode::HALF_UP)->__toString();
    }

    /**
     * Classify lot expiry status relative to cutoff date.
     * Invariants:
     * - null expiry is 'unknown', never expired!
     * - expiry < cutoff is 'expired'
     * - expiry == cutoff is 'valid'
     * - expiry > cutoff and within threshold is 'soon'
     * - expiry > cutoff beyond threshold is 'valid'
     */
    public static function classifyExpiry(?string $expiryDate, string $cutoffDate, int $soonDaysThreshold = 30): string
    {
        if ($expiryDate === null || trim($expiryDate) === '') {
            return 'unknown';
        }

        if ($expiryDate < $cutoffDate) {
            return 'expired';
        }

        if ($expiryDate === $cutoffDate) {
            return 'valid';
        }

        $cutoff = Carbon::parse($cutoffDate)->startOfDay();
        $expiry = Carbon::parse($expiryDate)->startOfDay();
        $daysUntilExpiry = $cutoff->diffInDays($expiry, false);

        if ($daysUntilExpiry >= 0 && $daysUntilExpiry <= $soonDaysThreshold) {
            return 'soon';
        }

        return 'valid';
    }
}
