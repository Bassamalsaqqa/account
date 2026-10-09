<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Security\ReportPermissionCatalog;
use App\Application\Reporting\Support\TradeFilterValidator;
use App\Application\Reporting\Support\TradeOpenPositions;
use App\Application\Reporting\Support\TradeProvenance;
use App\Application\Reporting\Support\TradeResultPresenter;
use App\Models\Company;
use App\Models\User;

final class SalesUnpaidInvoicesReportQuery
{
    public const string REPORT_TYPE = 'sales.unpaid_invoices';

    public const array SUPPORTED_FILTERS = [
        'period',
        'customer_id',
        'currency_code',
        'warehouse_id',
        'sort',
        'page',
        'per_page',
    ];

    public const array ALLOWED_SORTS = [
        'outstanding_desc',
        'due_date_asc',
        'issue_date_desc',
        'overdue_desc',
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
            ReportPermissionCatalog::REPORTS_SALES_VIEW,
            'sales.invoice.view',
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS,
            allowedSorts: self::ALLOWED_SORTS
        );

        $cutoffDate = $validatedFilters->period->endDate;
        $result = TradeOpenPositions::read($company, $validatedFilters, false, false, false);

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $result['totals'],
            rows: $result['rows'],
            totalRecords: $result['count'],
            meta: [
                'as_of_date' => $cutoffDate,
                'sort' => $validatedFilters->sort,
            ]
        );
    }
}
