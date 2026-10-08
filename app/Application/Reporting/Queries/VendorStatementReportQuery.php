<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Security\ReportPermissionCatalog;
use App\Application\Reporting\Support\TradeFilterValidator;
use App\Application\Reporting\Support\TradeProvenance;
use App\Application\Reporting\Support\TradeResultPresenter;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Models\Company;
use App\Models\User;
use App\Models\Vendor;

final class VendorStatementReportQuery
{
    public const string REPORT_TYPE = 'vendors.statement';

    public const array SUPPORTED_FILTERS = [
        'vendor_id',
        'period',
        'currency_code',
        'page',
        'per_page',
    ];

    public function __construct(
        protected ReportingGuard $guard
    ) {}

    /**
     * @param  ReportFilters|array<string, mixed>  $filters
     */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $this->guard->authorize($company, $actor, [
            ReportPermissionCatalog::REPORTS_PURCHASES_VIEW,
            ReportPermissionCatalog::VENDORS_STATEMENT_VIEW,
            ReportPermissionCatalog::PURCHASING_COST_VIEW,
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::purchases($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS
        );

        if ($validatedFilters->vendorId === null) {
            throw InvalidReportFilterException::invalidValue('vendor_id', null, 'vendor_id filter is required for vendor statement report.');
        }

        /** @var Vendor $vendor */
        $vendor = Vendor::withoutGlobalScopes()
            ->where('id', $validatedFilters->vendorId)
            ->where('company_id', $company->id)
            ->firstOrFail();

        $statement = app(VendorStatementQuery::class)->execute(
            $vendor,
            $validatedFilters->period->startDate,
            $validatedFilters->period->endDate
        );

        $currencies = $statement['currencies'];
        $selectedCurrency = $validatedFilters->currencyCode;

        $rows = [];
        $totals = [];

        foreach ($currencies as $curr => $currencyData) {
            if ($selectedCurrency !== null && $curr !== $selectedCurrency) {
                continue;
            }

            $totals[$curr] = [
                'currency' => $curr,
                'opening_balance' => (string) $currencyData['opening_balance'],
                'closing_balance' => (string) $currencyData['closing_balance'],
                'total_debits' => (string) $currencyData['total_debits'],
                'total_credits' => (string) $currencyData['total_credits'],
                'aging' => $currencyData['aging'],
            ];

            foreach ($currencyData['entries'] as $entry) {
                $rows[] = [
                    'currency_code' => $curr,
                    'date' => (string) $entry['date'],
                    'type' => (string) $entry['type'],
                    'number' => (string) $entry['number'],
                    'reference' => $entry['reference'] !== null ? (string) $entry['reference'] : null,
                    'description' => (string) $entry['description'],
                    'debit' => (string) $entry['debit'],
                    'credit' => (string) $entry['credit'],
                    'balance' => (string) $entry['balance'],
                ];
            }
        }

        $totalRecords = count($rows);

        $offset = ($validatedFilters->page - 1) * $validatedFilters->perPage;
        $pagedRows = array_slice($rows, $offset, $validatedFilters->perPage);

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $totals,
            rows: $pagedRows,
            totalRecords: $totalRecords,
            meta: [
                'vendor_id' => $vendor->id,
                'vendor_name_ar' => $vendor->name_ar,
                'vendor_name_en' => $vendor->name_en,
            ]
        );
    }
}
