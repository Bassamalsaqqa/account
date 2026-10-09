<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Models\Company;
use Carbon\Carbon;

final class TradeResultPresenter
{
    /**
     * @param  array<string, mixed>  $totals
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    public static function create(
        string $reportType,
        Company $company,
        ReportFilters $filters,
        array $totals,
        array $rows,
        int $totalRecords,
        array $meta = []
    ): ReportResult {
        $page = (int) $filters->page;
        $perPage = (int) $filters->perPage;
        $lastPage = $perPage > 0 ? (int) ceil($totalRecords / $perPage) : 1;
        $lastPage = max(1, $lastPage);

        $pagination = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalRecords,
            'last_page' => $lastPage,
        ];

        $baseCurrencyCode = (string) $company->base_currency_code;
        if ($baseCurrencyCode === '') {
            throw new ReportingException("Company [{$company->id}] is missing base currency configuration.");
        }

        $currency = [
            'base_currency_code' => $baseCurrencyCode,
            'filter_currency_code' => $filters->currencyCode,
        ];

        $companyTz = (string) $company->timezone;
        if ($companyTz === '') {
            throw new ReportingException("Company [{$company->id}] is missing timezone configuration.");
        }
        $generatedAt = Carbon::now($companyTz)->toIso8601String();

        return new ReportResult(
            reportType: $reportType,
            filters: $filters->toArray(),
            totals: $totals,
            rows: $rows,
            currency: $currency,
            pagination: $pagination,
            generatedAt: $generatedAt,
            meta: $meta
        );
    }
}
