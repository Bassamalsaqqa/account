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
use Brick\Math\BigDecimal;

final class PayrollSummaryReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.payroll.view', 'employees.view', 'payroll.salary.view']);
        $f = Read::filters($company, $filters, ['employee_id', 'currency_code', 'status'], ['status' => ['unpaid', 'paid']]);

        $helper = PayrollReportHelper::class;
        $q = $helper::entries($company, $f);
        if ($f->status === 'unpaid') {
            $q->where('remaining_payable', '>', 0);
        }
        if ($f->status === 'paid') {
            $q->where('remaining_payable', 0);
        }
        $advances = $helper::advances($company, $f);
        $sources = $helper::sources($company, $f, 'salary_entries');
        $events = Read::eventLegs($sources, $company, 'salary_entry', 'recognition_date', [
            'earned' => 'base_earned_salary', 'advance' => 'base_advance_relief', 'payable' => 'base_payable'])
            ->whereBetween('date', [$f->period->startDate, $f->period->endDate]);
        $totals = [];
        foreach (['recognized_earned_salary_base' => 'earned', 'recognized_advance_relief_base' => 'advance', 'recognized_net_payable_base' => 'payable'] as $key => $column) {
            $r = (clone $events)->selectRaw("COALESCE(SUM(CASE WHEN is_reversal=1 THEN -$column ELSE $column END),0) AS total")->first();
            $totals[$key] = Read::decimal((string) $r->total);
        }
        $payments = $helper::sources($company, $f, 'salary_payments');
        $paymentEvents = Read::eventLegs($payments, $company, 'salary_payment', 'payment_date', ['base_amount' => 'base_amount'])
            ->whereBetween('date', [$f->period->startDate, $f->period->endDate]);
        $p = (clone $paymentEvents)->selectRaw('COALESCE(SUM(CASE WHEN is_reversal=1 THEN -base_amount ELSE base_amount END),0) AS total')->first();
        $totals += ['salary_payments_base' => Read::decimal((string) $p->total),
            'outstanding_unpaid_salary_base' => Read::decimal((string) (clone $q)->sum('remaining_payable_base')),
            'unpaid_salary_by_currency' => Read::currencyTotals($q, 'remaining_payable'),
            'outstanding_advances_base' => Read::decimal((string) (clone $advances)->sum('remaining_base_amount')),
            'outstanding_advances_by_currency' => Read::currencyTotals($advances, 'remaining_amount'), 'records_count' => (clone $q)->count()];

        return Read::result('payroll.summary', $company, $f, $q->orderBy('recognition_date')->orderBy('id'), $totals,
            static fn (object $r): array => ['salary_entry_id' => (int) $r->id, 'public_id' => (string) $r->public_id,
                'salary_number' => (string) $r->salary_number, 'employee_id' => (int) $r->employee_id,
                'employee_name' => MoneyReportHelper::extractPartyName(Read::snapshot($r->employee_snapshot)),
                'recognition_date' => (string) $r->recognition_date, 'period' => $r->period_start.' to '.$r->period_end,
                'currency_code' => (string) $r->currency_code, 'earned_salary' => Read::decimal((string) $r->earned_salary),
                'advance_applied' => Read::decimal((string) $r->advance_applied), 'net_payable' => Read::decimal((string) $r->net_payable),
                'paid_as_of_cutoff' => Read::decimal((string) $r->paid_amount), 'remaining_unpaid' => Read::decimal((string) $r->remaining_payable),
                'remaining_unpaid_base' => Read::decimal((string) $r->remaining_payable_base),
                'is_unpaid' => BigDecimal::of((string) $r->remaining_payable)->isPositive()], ['as_of_date' => $f->period->endDate]);

    }
}
