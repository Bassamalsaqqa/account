<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\MoneyReportHelper;
use App\Application\Reporting\Support\OperationalReportRead as Read;
use App\Application\Reporting\Support\PayrollReportHelper;
use App\Models\Company;
use App\Models\User;
use App\Services\Phase7\Phase7FinancialRead;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;

final class PayrollAdvanceReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.payroll.view', 'employees.view']);
        $f = Read::filters($company, $filters, ['employee_id', 'currency_code', 'status'], ['status' => ['available', 'consumed']]);

        if (! app(Phase7FinancialRead::class)->advance((int) $company->id)) {
            throw new AuthorizationException('Employee advance financial authority required.');
        }
        $q = PayrollReportHelper::advances($company, $f);
        if ($f->status === 'available') {
            $q->where('remaining_amount', '>', 0);
        }
        if ($f->status === 'consumed') {
            $q->where('remaining_amount', 0);
        }

        return Read::result('payroll.advances', $company, $f, $q->orderBy('advance_date')->orderBy('id'),
            ['total_outstanding_base' => Read::decimal((string) (clone $q)->sum('remaining_base_amount')),
                'outstanding_by_currency' => Read::currencyTotals($q, 'remaining_amount'),
                'active_advance_count' => (clone $q)->where('remaining_amount', '>', 0)->count(), 'records_count' => (clone $q)->count()],
            static fn (object $r): array => ['advance_id' => (int) $r->id, 'public_id' => (string) $r->public_id,
                'advance_number' => (string) $r->advance_number, 'advance_date' => (string) $r->advance_date,
                'date' => (string) $r->advance_date,
                'employee_id' => (int) $r->employee_id,
                'employee_name' => MoneyReportHelper::extractPartyName(Read::snapshot($r->employee_snapshot)),
                'currency_code' => (string) $r->currency_code, 'amount' => Read::decimal((string) $r->amount),
                'base_amount' => Read::decimal((string) $r->base_amount),
                'consumed_amount' => Read::decimal((string) $r->consumed_amount), 'remaining_amount' => Read::decimal((string) $r->remaining_amount),
                'remaining_base_amount' => Read::decimal((string) $r->remaining_base_amount), 'payment_method' => (string) $r->payment_method,
                'is_available' => BigDecimal::of((string) $r->remaining_amount)->isPositive(),
                'status' => BigDecimal::of((string) $r->remaining_amount)->isPositive() ? 'available' : 'consumed'], ['as_of_date' => $f->period->endDate]);

    }
}
