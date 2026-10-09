<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Models\Company;
use App\Models\ProductCategory;

final class TradeFilterValidator
{
    /** @param ReportFilters|array<string, mixed> $input
     * @param list<string> $supportedFilters
     * @param list<string>|null $allowedGroupings
     * @param list<string>|null $allowedSorts */
    public static function validate(Company $company, ReportFilters|array $input, array $supportedFilters,
        ?array $allowedGroupings = null, ?array $allowedSorts = null, ?ReportPeriod $defaultPeriod = null): ReportFilters
    {
        $raw = $input instanceof ReportFilters ? $input->toArray() : $input;
        if (! in_array('period', $supportedFilters, true)) {
            if (! $input instanceof ReportFilters) {
                foreach (['period', 'preset', 'from', 'to', 'start_date', 'end_date'] as $key) {
                    if (isset($raw[$key]) && $raw[$key] !== '') {
                        throw new InvalidReportFilterException('This report is a current position; historical date filters are unsupported.');
                    }
                }
            } else {
                // A typed DTO always has a period. Do not pretend a custom cutoff is supported.
                if ($input->period->preset === 'custom') {
                    throw new InvalidReportFilterException('Current balances do not support a historical cutoff.');
                }
            }
        }
        if ($input instanceof ReportFilters) {
            $f = $input->validateForCompany($company, $defaultPeriod);
        } else {
            if (isset($raw['period']) && is_string($raw['period'])) {
                $raw['preset'] = $raw['period'];
                unset($raw['period']);
            }
            $f = ReportFilters::fromArray($company, $raw, $defaultPeriod);
        }
        foreach (['customer_id' => $f->customerId, 'vendor_id' => $f->vendorId, 'product_id' => $f->productId,
            'category_id' => $f->categoryId, 'warehouse_id' => $f->warehouseId, 'employee_id' => $f->employeeId,
            'money_account_id' => $f->moneyAccountId, 'currency_code' => $f->currencyCode,
            'status' => $f->status, 'grouping' => $f->grouping, 'sort' => $f->sort] as $key => $value) {
            if ($value !== null && ! in_array($key, $supportedFilters, true)) {
                throw new InvalidReportFilterException("Filter [$key] is unsupported for this report.");
            }
        }
        if ($f->categoryId !== null && ! ProductCategory::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($f->categoryId)->exists()) {
            throw InvalidReportFilterException::foreignEntity('category_id', $f->categoryId, (int) $company->id);
        }
        if ($f->grouping !== null && ($allowedGroupings === null || ! in_array($f->grouping, $allowedGroupings, true))) {
            throw new InvalidReportFilterException('Unsupported grouping.');
        }
        if ($f->sort !== null && ($allowedSorts === null || ! in_array($f->sort, $allowedSorts, true))) {
            throw new InvalidReportFilterException('Unsupported sort.');
        }

        return $f;
    }
}
