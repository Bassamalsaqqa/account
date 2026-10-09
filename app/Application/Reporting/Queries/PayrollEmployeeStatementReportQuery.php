<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\MoneyReportHelper;
use App\Application\Reporting\Support\OperationalReportRead as Read;
use App\Application\Reporting\Support\PayrollReportHelper;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PayrollEmployeeStatementReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.payroll.view', 'employees.view', 'payroll.salary.view']);
        $f = Read::filters($company, $filters, ['employee_id', 'currency_code'], []);

        if ($f->employeeId === null) {
            throw new InvalidReportFilterException('Employee identity is required for statement.');
        }
        $helper = PayrollReportHelper::class;
        $all = null;
        foreach ([['employee_advances', 'employee_advance', 'advance_date', 'advance_number', 'amount', 'base_amount'],
            ['salary_entries', 'salary_entry', 'recognition_date', 'salary_number', 'net_payable', 'base_payable'],
            ['salary_payments', 'salary_payment', 'payment_date', 'payment_number', 'amount', 'base_amount']] as [$table,$type,$date,$number,$amount,$base]) {
            $legs = Read::eventLegs($helper::sources($company, $f, $table), $company, $type, $date, [
                'reference_number' => $number, 'employee_snapshot' => 'employee_snapshot', 'currency_code' => 'currency_code',
                'amount' => $amount, 'base_amount' => $base])->whereBetween('date', [$f->period->startDate, $f->period->endDate]);
            $event = DB::query()->fromSub($legs, 'source_legs')->select('id', 'public_id', 'date', 'is_reversal', 'reference_number', 'employee_snapshot', 'currency_code')
                ->selectRaw('? AS source_type', [$type])
                ->selectRaw('CASE WHEN is_reversal=1 THEN -amount ELSE amount END AS amount')
                ->selectRaw('CASE WHEN is_reversal=1 THEN -base_amount ELSE base_amount END AS base_amount');
            $all = $all === null ? $event : $all->unionAll($event);
        }
        $q = DB::query()->fromSub($all, 'employee_events');
        $salaries = $helper::entries($company, $f);
        $advances = $helper::advances($company, $f);

        return Read::result('payroll.statement', $company, $f, $q->orderBy('date')->orderBy('source_type')->orderBy('id')->orderBy('is_reversal'),
            ['outstanding_salary_by_currency' => Read::currencyTotals($salaries, 'remaining_payable'),
                'outstanding_advance_by_currency' => Read::currencyTotals($advances, 'remaining_amount'), 'events_count' => (clone $q)->count()],
            static fn (object $r): array => ['id' => (int) $r->id, 'public_id' => (string) $r->public_id, 'date' => (string) $r->date,
                'source_type' => (string) $r->source_type, 'type' => (string) $r->source_type, 'event_type' => $r->source_type.($r->is_reversal ? '_reversal' : ''),
                'reference_number' => (string) $r->reference_number, 'number' => (string) $r->reference_number,
                'employee_name' => MoneyReportHelper::extractPartyName(Read::snapshot($r->employee_snapshot)),
                'currency_code' => (string) $r->currency_code, 'amount' => Read::decimal((string) $r->amount),
                'base_amount' => Read::decimal((string) $r->base_amount), 'is_reversal' => (bool) $r->is_reversal],
            ['employee_id' => $f->employeeId, 'as_of_date' => $f->period->endDate]);

    }
}
