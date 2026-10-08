<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Models\Company;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Batched principal and historical carrying-value positions; never current getters. */
final class PayrollReportHelper
{
    public static function sources(Company $company, ReportFilters $f, string $table): Builder
    {
        $q = DB::table($table.' as s')->where('s.company_id', $company->id)->whereIn('s.status', ['posted', 'reversed']);
        if ($f->employeeId !== null) {
            $q->where('s.employee_id', $f->employeeId);
        }
        if ($f->currencyCode !== null) {
            $q->where('s.currency_code', $f->currencyCode);
        }

        return $q;
    }

    public static function entries(Company $company, ReportFilters $f): Builder
    {
        $cutoff = $f->period->endDate;
        $entries = self::sources($company, $f, 'salary_entries');
        OperationalReportRead::eventLegs($entries, $company, 'salary_entry', 'recognition_date', []);
        $payments = self::sources($company, $f, 'salary_payments');
        OperationalReportRead::eventLegs($payments, $company, 'salary_payment', 'payment_date', []);
        $relief = DB::table('salary_payment_allocations as a')->join('salary_payments as p', 'p.id', '=', 'a.salary_payment_id')
            ->join('salary_entries as target', 'target.id', '=', 'a.salary_entry_id')
            ->leftJoin('posting_batches as inverse', 'inverse.id', '=', 'p.reversal_posting_batch_id')
            ->where('a.company_id', $company->id)->where('p.company_id', $company->id)->where('target.company_id', $company->id)
            ->whereColumn('p.employee_id', 'target.employee_id')->whereColumn('p.currency_code', 'target.currency_code')
            ->where('p.payment_date', '<=', $cutoff)->where(function (Builder $q) use ($cutoff): void {
                $q->where('p.status', 'posted')->orWhere('inverse.posting_date', '>', $cutoff);
            })->groupBy('a.salary_entry_id')
            ->selectRaw('a.salary_entry_id,SUM(a.allocated_amount) AS paid_amount,SUM(a.salary_book_relief_base) AS relieved_base');
        $q = $entries->leftJoin('posting_batches as inverse', 'inverse.id', '=', 's.reversal_posting_batch_id')
            ->leftJoinSub($relief, 'relief', 'relief.salary_entry_id', '=', 's.id')->where('s.recognition_date', '<=', $cutoff)
            ->where(function (Builder $q) use ($cutoff): void {
                $q->where('s.status', 'posted')->orWhere('inverse.posting_date', '>', $cutoff);
            })->select('s.id', 's.public_id', 's.salary_number', 's.employee_id', 's.employee_snapshot', 's.recognition_date',
                's.period_start', 's.period_end', 's.currency_code', 's.earned_salary', 's.advance_applied', 's.net_payable',
                's.base_earned_salary', 's.base_advance_relief', 's.base_payable')
            ->selectRaw('COALESCE(paid_amount,0) AS paid_amount')
            ->selectRaw('s.net_payable-COALESCE(paid_amount,0) AS remaining_payable')
            ->selectRaw('s.base_payable-COALESCE(relieved_base,0) AS remaining_payable_base');
        $scope = DB::query()->fromSub($q, 'salary_positions');
        if ((clone $scope)->where(function (Builder $q): void {
            $q->where('remaining_payable', '<', 0)->orWhere('remaining_payable_base', '<', 0);
        })->exists()) {
            throw new ReportingException('Invalid salary allocation residual.');
        }

        return $scope;
    }

    public static function advances(Company $company, ReportFilters $f): Builder
    {
        $cutoff = $f->period->endDate;
        $advances = self::sources($company, $f, 'employee_advances');
        OperationalReportRead::eventLegs($advances, $company, 'employee_advance', 'advance_date', []);
        OperationalReportRead::eventLegs(self::sources($company, $f, 'salary_entries'), $company, 'salary_entry', 'recognition_date', []);
        $consumed = DB::table('salary_advance_allocations as a')->join('salary_entries as e', 'e.id', '=', 'a.salary_entry_id')
            ->join('employee_advances as target', 'target.id', '=', 'a.employee_advance_id')
            ->leftJoin('posting_batches as inverse', 'inverse.id', '=', 'e.reversal_posting_batch_id')
            ->where('a.company_id', $company->id)->where('e.company_id', $company->id)->where('target.company_id', $company->id)
            ->whereColumn('e.employee_id', 'target.employee_id')->whereColumn('e.currency_code', 'target.currency_code')
            ->where('e.recognition_date', '<=', $cutoff)->where(function (Builder $q) use ($cutoff): void {
                $q->where('e.status', 'posted')->orWhere('inverse.posting_date', '>', $cutoff);
            })->groupBy('a.employee_advance_id')
            ->selectRaw('a.employee_advance_id,SUM(a.allocated_amount) AS consumed_amount,SUM(a.advance_base_consumed) AS consumed_base');
        $q = $advances->leftJoin('posting_batches as inverse', 'inverse.id', '=', 's.reversal_posting_batch_id')
            ->leftJoinSub($consumed, 'consumption', 'consumption.employee_advance_id', '=', 's.id')->where('s.advance_date', '<=', $cutoff)
            ->where(function (Builder $q) use ($cutoff): void {
                $q->where('s.status', 'posted')->orWhere('inverse.posting_date', '>', $cutoff);
            })
            ->select('s.id', 's.public_id', 's.advance_number', 's.employee_id', 's.employee_snapshot', 's.advance_date', 's.currency_code', 's.amount', 's.base_amount', 's.payment_method')
            ->selectRaw('COALESCE(consumed_amount,0) AS consumed_amount,s.amount-COALESCE(consumed_amount,0) AS remaining_amount,
                s.base_amount-COALESCE(consumed_base,0) AS remaining_base_amount');
        $scope = DB::query()->fromSub($q, 'advance_positions');
        if ((clone $scope)->where(function (Builder $q): void {
            $q->where('remaining_amount', '<', 0)->orWhere('remaining_base_amount', '<', 0);
        })->exists()) {
            throw new ReportingException('Invalid employee advance allocation residual.');
        }

        return $scope;
    }
}
