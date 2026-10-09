<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Security\ReportPermissionCatalog;
use App\Application\Reporting\Support\TradeCurrentBalances;
use App\Application\Reporting\Support\TradeFilterValidator;
use App\Application\Reporting\Support\TradeProvenance;
use App\Application\Reporting\Support\TradeResultPresenter;
use App\Models\Company;
use App\Models\User;

final class CustomerBalancesReportQuery
{
    public const string REPORT_TYPE = 'customers.balances';

    public const array SUPPORTED_FILTERS = [
        'customer_id',
        'currency_code',
        'sort',
        'page',
        'per_page',
    ];

    public const array ALLOWED_SORTS = [
        'name_asc',
        'code_asc',
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
            'customers.view',
        ]);
        $company = $this->guard->company($company, $actor);

        TradeProvenance::sales($company);

        $validatedFilters = TradeFilterValidator::validate(
            $company,
            $filters,
            self::SUPPORTED_FILTERS,
            allowedSorts: self::ALLOWED_SORTS
        );

        $result = TradeCurrentBalances::read($company, $validatedFilters, false);

        return TradeResultPresenter::create(
            reportType: self::REPORT_TYPE,
            company: $company,
            filters: $validatedFilters,
            totals: $result['totals'],
            rows: $result['rows'],
            totalRecords: $result['count'],
            meta: [
                'sort' => $validatedFilters->sort ?? 'name_asc',
                'position_basis' => 'current',
                'historical_cutoff_supported' => false,
            ]
        );
    }
}
