<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Models\Company;
use App\Models\MoneyAccount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class MoneyReportHelper
{
    /**
     * Validate money-specific filters and enforce tenant isolation.
     *
     * @param  list<string>  $disallowedFilters
     */
    public static function validateFilters(Company $company, ReportFilters $filters, array $disallowedFilters = []): void
    {
        foreach ($disallowedFilters as $disallowed) {
            $value = match ($disallowed) {
                'product_id' => $filters->productId,
                'warehouse_id' => $filters->warehouseId,
                'category_id' => $filters->categoryId,
                'employee_id' => $filters->employeeId,
                default => null,
            };

            if ($value !== null) {
                throw InvalidReportFilterException::invalidValue($disallowed, $value, "Filter [{$disallowed}] is not supported for this money report.");
            }
        }

        if ($filters->moneyAccountId !== null) {
            $exists = MoneyAccount::withoutGlobalScopes()
                ->where('id', $filters->moneyAccountId)
                ->where('company_id', $company->id)
                ->exists();

            if (! $exists) {
                throw InvalidReportFilterException::foreignEntity('money_account_id', $filters->moneyAccountId, (int) $company->id);
            }
        }
    }

    /**
     * Extract party name from immutable party_snapshot array.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function extractPartyName(?array $snapshot, ?string $locale = null, string $fallback = 'Unavailable'): string
    {
        if ($snapshot === null || empty($snapshot)) {
            return $fallback;
        }

        $locale ??= app()->getLocale();
        $keys = $locale === 'en'
            ? ['name_en', 'name_ar', 'name', 'payee_name', 'business_name_en', 'business_name_ar', 'business_name']
            : ['name_ar', 'name_en', 'name', 'payee_name', 'business_name_ar', 'business_name_en', 'business_name'];

        foreach ($keys as $key) {
            $val = $snapshot[$key] ?? null;
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
        }

        return $fallback;
    }

    /**
     * Format money string to exact decimal scale, respecting JOD (3 decimals) or custom scale.
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
