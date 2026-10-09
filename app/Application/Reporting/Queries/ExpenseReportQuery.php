<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\ExpenseReportHelper;
use App\Application\Reporting\Support\OperationalReportRead as Read;
use App\Models\Company;
use App\Models\User;
use App\Services\Phase7\Phase7FinancialRead;
use Illuminate\Support\Facades\DB;

final class ExpenseReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.expenses.view', 'money.expense.view']);
        $f = Read::filters($company, $filters, ['vendor_id', 'category_id', 'money_account_id', 'currency_code', 'status', 'grouping'], ['status' => ['operating', 'landed_cost'], 'grouping' => ['category', 'currency', 'trend', 'period', 'type', 'fuel', 'delivery', 'transport']]);

        ExpenseReportHelper::validateFilters($company, $f);
        $sources = DB::table('expenses as s')->where('s.company_id', $company->id)
            ->whereIn('s.id', app(Phase7FinancialRead::class)->visibleExpenseIds((int) $company->id));
        foreach (['vendor_id' => $f->vendorId, 'category_id' => $f->categoryId, 'money_account_id' => $f->moneyAccountId,
            'currency_code' => $f->currencyCode, 'classification' => $f->status] as $field => $value) {
            if ($value !== null) {
                $sources->where('s.'.$field, $value);
            }
        }
        $legs = Read::eventLegs($sources, $company, 'expense', 'expense_date', [
            'expense_number' => 'expense_number', 'category_id' => 'category_id', 'category_snapshot' => 'category_snapshot',
            'vendor_snapshot' => 'vendor_snapshot', 'payee_name' => 'payee_name', 'description' => 'description',
            'classification' => 'classification', 'currency_code' => 'currency_code', 'amount' => 'amount',
            'base_amount' => 'base_amount', 'payment_method' => 'payment_method', 'exchange_rate' => 'exchange_rate'])
            ->whereBetween('date', [$f->period->startDate, $f->period->endDate]);
        $events = DB::query()->fromSub($legs, 'expense_events')->select('id', 'public_id', 'date', 'is_reversal', 'expense_number',
            'category_id', 'category_snapshot', 'vendor_snapshot', 'payee_name', 'description', 'classification', 'currency_code',
            'payment_method', 'exchange_rate')->selectRaw('CASE WHEN is_reversal=1 THEN -amount ELSE amount END AS amount')
            ->selectRaw('CASE WHEN is_reversal=1 THEN -base_amount ELSE base_amount END AS base_amount');
        $q = DB::query()->fromSub($events, 'signed_expenses');
        $operating = (clone $q)->where('classification', 'operating');
        $landed = (clone $q)->where('classification', 'landed_cost');

        $isLanded = ($f->status === 'landed_cost');
        if ($isLanded) {
            $this->guard->authorize($company, $actor, 'purchasing.cost.view');
        }

        $target = $isLanded ? $landed : $operating;
        if (in_array($f->grouping, ['fuel', 'delivery', 'transport'], true)) {
            $target->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(category_snapshot,'$.code')))=?", [$f->grouping]);
        }
        $canCost = $this->guard->allows($company, 'purchasing.cost.view', $actor);

        $categoryCode = "JSON_UNQUOTE(JSON_EXTRACT(category_snapshot,'$.code'))";

        if ($isLanded) {
            $landedTotal = Read::decimal((string) (clone $landed)->sum('base_amount'));
            $totals = [
                'landed_cost_clearing_base' => $landedTotal,
                'landed_cost_currency_totals' => Read::currencyTotals($landed),
                'legs_count' => (clone $landed)->count(),
            ];
            foreach (['fuel', 'delivery', 'transport'] as $kind) {
                $totals[$kind.'_total_base'] = Read::decimal((string) (clone $landed)->whereRaw("LOWER($categoryCode)=?", [$kind])->sum('base_amount'));
            }
        } else {
            $operatingTotal = Read::decimal((string) (clone $operating)->sum('base_amount'));
            $totals = [
                'ordinary_operating_expense_base' => $operatingTotal,
                'operating_currency_totals' => Read::currencyTotals($operating),
                'legs_count' => (clone $operating)->count(),
            ];
            foreach (['fuel', 'delivery', 'transport'] as $kind) {
                $totals[$kind.'_total_base'] = Read::decimal((string) (clone $operating)->whereRaw("LOWER($categoryCode)=?", [$kind])->sum('base_amount'));
            }
            if ($canCost) {
                $totals['landed_cost_clearing_base'] = Read::decimal((string) (clone $landed)->sum('base_amount'));
            }
        }

        $group = $f->grouping ?? 'category';
        if ($group === 'currency') {
            $rows = (clone $target)->selectRaw('currency_code,SUM(amount) AS total_amount')->groupBy('currency_code')->orderBy('currency_code');
            $map = static fn (object $r): array => ['currency_code' => (string) $r->currency_code, 'total_amount' => Read::decimal((string) $r->total_amount)];
        } elseif (in_array($group, ['trend', 'period'], true)) {
            $rows = (clone $target)->selectRaw("DATE_FORMAT(date,'%Y-%m') AS period,SUM(base_amount) AS total_base,COUNT(*) AS transaction_count")
                ->groupByRaw("DATE_FORMAT(date,'%Y-%m')")->orderBy('period');
            $map = static fn (object $r): array => ['period' => (string) $r->period, 'total_base' => Read::decimal((string) $r->total_base), 'transaction_count' => (int) $r->transaction_count];
        } else {
            // Snapshot grouping retains historical category identity even after a rename.
            $rows = (clone $target)->selectRaw("category_id,category_snapshot,$categoryCode AS category_code,SUM(base_amount) AS total_base,COUNT(*) AS transaction_count")
                ->groupBy('category_id', 'category_snapshot')->orderBy('category_id')->orderBy('category_snapshot');
            if ($group === 'type') {
                $rows->whereRaw("LOWER($categoryCode) IN ('fuel','delivery','transport')");
            }
            $map = static fn (object $r): array => ['category_id' => (int) $r->category_id,
                'category_name' => ExpenseReportHelper::extractCategoryName(Read::snapshot($r->category_snapshot)),
                'category_code' => (string) $r->category_code, 'total_base' => Read::decimal((string) $r->total_base), 'transaction_count' => (int) $r->transaction_count];
        }

        return Read::result('expenses.summary', $company, $f, DB::query()->fromSub($rows, 'grouped_expenses'), $totals, $map,
            ['landed_cost_segregated' => true, 'cost_redacted' => ! $canCost]);

    }
}
