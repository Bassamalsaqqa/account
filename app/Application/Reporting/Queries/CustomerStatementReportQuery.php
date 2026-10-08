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
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;

final class CustomerStatementReportQuery
{
    public const string REPORT_TYPE = 'customers.statement';

    public const array SUPPORTED_FILTERS = [
        'customer_id',
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
        // Enforce report permission + exact accepted underlying statement permission
        $this->guard->authorize($company, $actor, [
            ReportPermissionCatalog::REPORTS_SALES_VIEW,
            ReportPermissionCatalog::CUSTOMERS_STATEMENT_VIEW,
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS
        );

        if ($validatedFilters->customerId === null) {
            throw InvalidReportFilterException::invalidValue('customer_id', null, 'customer_id filter is required for statement report.');
        }

        /** @var Customer $customer */
        $customer = Customer::withoutGlobalScopes()
            ->where('id', $validatedFilters->customerId)
            ->where('company_id', $company->id)
            ->firstOrFail();

        $statement = app(CustomerStatementQuery::class)->execute(
            $customer,
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

        // Paginate rows
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
                'customer_id' => $customer->id,
                'customer_name_ar' => $customer->name_ar,
                'customer_name_en' => $customer->name_en,
            ]
        );
    }
}
