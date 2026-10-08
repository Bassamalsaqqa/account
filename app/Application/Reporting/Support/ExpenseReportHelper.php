<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\Vendor;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ExpenseReportHelper
{
    /**
     * Validate expense-specific filters and enforce tenant isolation.
     *
     * @param  list<string>  $disallowedFilters
     */
    public static function validateFilters(Company $company, ReportFilters $filters, array $disallowedFilters = []): void
    {
        foreach ($disallowedFilters as $disallowed) {
            $value = match ($disallowed) {
                'customer_id' => $filters->customerId,
                'warehouse_id' => $filters->warehouseId,
                'product_id' => $filters->productId,
                'employee_id' => $filters->employeeId,
                default => null,
            };

            if ($value !== null) {
                throw InvalidReportFilterException::invalidValue($disallowed, $value, "Filter [{$disallowed}] is not supported for this expense report.");
            }
        }

        if ($filters->categoryId !== null) {
            $exists = ExpenseCategory::withoutGlobalScopes()
                ->where('id', $filters->categoryId)
                ->where('company_id', $company->id)
                ->exists();

            if (! $exists) {
                throw InvalidReportFilterException::foreignEntity('category_id', $filters->categoryId, (int) $company->id);
            }
        }

        if ($filters->vendorId !== null) {
            $exists = Vendor::withTrashed()
                ->withoutGlobalScopes()
                ->where('id', $filters->vendorId)
                ->where('company_id', $company->id)
                ->exists();

            if (! $exists) {
                throw InvalidReportFilterException::foreignEntity('vendor_id', $filters->vendorId, (int) $company->id);
            }
        }
    }

    /**
     * Extract category name from immutable category_snapshot.
     * Enforces English↔Arabic bidirectional fallback strictly within snapshot, never master.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function extractCategoryName(?array $snapshot, ?string $locale = null, string $fallback = 'General Expense'): string
    {
        if ($snapshot === null || empty($snapshot)) {
            return $fallback;
        }

        $locale ??= app()->getLocale();
        if ($locale === 'en') {
            if (! empty($snapshot['name_en']) && is_string($snapshot['name_en'])) {
                return trim($snapshot['name_en']);
            }
            if (! empty($snapshot['name_ar']) && is_string($snapshot['name_ar'])) {
                return trim($snapshot['name_ar']);
            }
        } else {
            if (! empty($snapshot['name_ar']) && is_string($snapshot['name_ar'])) {
                return trim($snapshot['name_ar']);
            }
            if (! empty($snapshot['name_en']) && is_string($snapshot['name_en'])) {
                return trim($snapshot['name_en']);
            }
        }

        if (! empty($snapshot['name']) && is_string($snapshot['name'])) {
            return trim($snapshot['name']);
        }

        return $fallback;
    }

    /**
     * Format expense money amount, respecting JOD (3 decimals) or custom scale.
     */
    public static function formatAmount(string|int|BigDecimal|null $amount, ?string $currency = null, int $defaultScale = 6): string
    {
        $scale = $currency === 'JOD' ? 3 : $defaultScale;
        if ($amount === null || $amount === '') {
            return '0.'.str_repeat('0', $scale);
        }

        return BigDecimal::of((string) $amount)->toScale($scale, RoundingMode::HALF_UP)->__toString();
    }
}
