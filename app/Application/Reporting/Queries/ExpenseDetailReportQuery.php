<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\ExpenseReportHelper;
use App\Application\Reporting\Support\MoneyReportHelper;
use App\Application\Reporting\Support\OperationalReportRead as Read;
use App\Models\Company;
use App\Models\User;
use App\Services\Phase7\Phase7FinancialRead;
use Illuminate\Support\Facades\DB;

final class ExpenseDetailReportQuery
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
        if (in_array($f->grouping, ['fuel', 'delivery', 'transport'], true)) {
            $operating->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(category_snapshot,'$.code')))=?", [$f->grouping]);
        }
        $landed = (clone $q)->where('classification', 'landed_cost');
        $operatingTotal = Read::decimal((string) (clone $operating)->sum('base_amount'));
        $canCost = $this->guard->allows($company, 'purchasing.cost.view', $actor);

        if ($f->grouping !== null) {
            throw new InvalidReportFilterException('Grouping is not supported by expense detail.');
        }

        return Read::result('expenses.detail', $company, $f, $q->orderBy('date')->orderBy('id')->orderBy('is_reversal'),
            ['total_operating_base' => $operatingTotal, 'records_count' => (clone $q)->count()],
            static fn (object $r): array => ['expense_id' => (int) $r->id, 'public_id' => (string) $r->public_id,
                'expense_number' => (string) $r->expense_number, 'date' => (string) $r->date,
                'category_name' => ExpenseReportHelper::extractCategoryName(Read::snapshot($r->category_snapshot)),
                'vendor_name' => MoneyReportHelper::extractPartyName(Read::snapshot($r->vendor_snapshot), null, (string) ($r->payee_name ?? __('money.unavailable'))),
                'description' => (string) $r->description, 'payment_method' => (string) $r->payment_method,
                'currency_code' => (string) $r->currency_code, 'amount' => Read::decimal((string) $r->amount),
                'exchange_rate' => (string) $r->exchange_rate, 'base_amount' => Read::decimal((string) $r->base_amount),
                'classification' => (string) $r->classification, 'is_reversal' => (bool) $r->is_reversal],
            ['cost_redacted' => ! $canCost]);

    }
}
