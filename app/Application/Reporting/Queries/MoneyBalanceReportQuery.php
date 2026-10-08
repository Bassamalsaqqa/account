<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\OperationalReportRead as Read;
use App\Models\Company;
use App\Models\User;
use App\Services\Money\MoneyLedgerMetadata;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class MoneyBalanceReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, 'reports.money.view');
        $defaultPeriod = ReportPeriod::fromPreset(ReportPeriod::PRESET_TODAY, $company);
        $f = Read::filters($company, $filters, ['money_account_id', 'currency_code', 'grouping'], ['grouping' => ['cash', 'bank']], $defaultPeriod);

        $types = [];
        foreach (['cash', 'bank'] as $type) {
            if ($this->guard->allows($company, 'money.'.$type.'.view', $actor)) {
                $types[] = $type;
            }
        }
        if ($types === []) {
            throw new AuthorizationException('Cash or Bank read authority required.');
        }
        if ($f->grouping !== null && ! in_array($f->grouping, $types, true)) {
            throw new AuthorizationException('Requested account type is restricted.');
        }
        $accounts = DB::table('money_accounts as m')->where('m.company_id', $company->id)->whereIn('m.account_type', $types);
        if ($f->grouping !== null) {
            $accounts->where('m.account_type', $f->grouping);
        }
        if ($f->moneyAccountId !== null) {
            $accounts->where('m.id', $f->moneyAccountId);
        }
        if ($f->currencyCode !== null) {
            $accounts->where('m.currency_code', $f->currencyCode);
        }
        $structure = (clone $accounts)->leftJoin('ledger_accounts as ledger', 'ledger.id', '=', 'm.ledger_account_id')
            ->leftJoin('ledger_accounts as parent', 'parent.id', '=', 'ledger.parent_id');
        if ((clone $structure)->whereRaw("ledger.id IS NULL OR ledger.company_id <> m.company_id OR ledger.account_type <> 'asset'
            OR ledger.normal_balance <> 'debit' OR ledger.is_control <> 0 OR parent.id IS NULL OR parent.company_id <> m.company_id
            OR parent.is_control <> 1 OR parent.account_type <> 'asset' OR parent.normal_balance <> 'debit'
            OR parent.system_key <> CASE WHEN m.account_type='cash' THEN 'cash_control' ELSE 'bank_control' END")->exists()) {
            throw new ReportingException('Invalid money account ledger provenance.');
        }
        $ledger = DB::table('posting_lines as l')->join('posting_batches as b', 'b.id', '=', 'l.posting_batch_id')
            ->join('money_accounts as m', 'm.ledger_account_id', '=', 'l.ledger_account_id')
            ->where('m.company_id', $company->id)->where('l.company_id', $company->id)->where('b.company_id', $company->id)
            ->where('b.posting_date', '<=', $f->period->endDate)->groupBy('m.id')
            ->selectRaw('m.id AS account_id,SUM(l.debit_base-l.credit_base) AS base_balance')
            ->selectRaw('SUM(CASE WHEN l.transaction_currency_code=m.currency_code THEN CASE WHEN l.debit_base>0 THEN l.transaction_amount ELSE -l.transaction_amount END ELSE 0 END) AS native_balance')
            ->selectRaw('SUM(CASE WHEN l.transaction_currency_code IS NOT NULL AND l.transaction_currency_code<>m.currency_code THEN 1 ELSE 0 END) AS foreign_metadata')
            ->selectRaw('SUM('.str_replace('?', 'm.currency_code', MoneyLedgerMetadata::unknownExpression()).') AS unknown_count');
        $q = $accounts->leftJoinSub($ledger, 'balances', 'balances.account_id', '=', 'm.id')
            ->select('m.id', 'm.public_id', 'm.name_ar', 'm.name_en', 'm.account_type', 'm.currency_code', 'm.is_active', 'm.deleted_at', 'm.bank_name')
            ->selectRaw('COALESCE(base_balance,0) AS balance_base')
            ->selectRaw('CASE WHEN m.currency_code=? AND COALESCE(foreign_metadata,0)=0 THEN COALESCE(base_balance,0)
                WHEN COALESCE(unknown_count,0)=0 THEN COALESCE(native_balance,0) ELSE NULL END AS balance_currency', [$company->base_currency_code]);
        $scope = DB::query()->fromSub($q, 'account_balances');
        $currency = [];
        foreach ((clone $scope)->selectRaw('currency_code,SUM(balance_currency) AS total,SUM(CASE WHEN balance_currency IS NULL THEN 1 ELSE 0 END) AS unavailable')
            ->groupBy('currency_code')->get() as $r) {
            $currency[(string) $r->currency_code] = (int) $r->unavailable === 0 ? Read::decimal((string) $r->total) : null;
        }

        return Read::result('money.balances', $company, $f, $scope->orderBy('account_type')->orderBy('id'),
            ['total_base_balance' => Read::decimal((string) (clone $scope)->sum('balance_base')), 'currency_balances' => $currency, 'accounts_count' => (clone $scope)->count()],
            static fn (object $r): array => ['public_id' => (string) $r->public_id, 'money_account_id' => (int) $r->id,
                'name' => Read::name($r->name_ar, $r->name_en), 'account_type' => (string) $r->account_type, 'currency_code' => (string) $r->currency_code,
                'balance_base' => Read::decimal((string) $r->balance_base), 'balance_currency' => $r->balance_currency === null ? null : Read::decimal((string) $r->balance_currency),
                'currency_balance_available' => $r->balance_currency !== null, 'is_active' => (bool) $r->is_active && $r->deleted_at === null,
                'bank_name' => $r->bank_name === null ? null : (string) $r->bank_name], ['as_of_date' => $f->period->endDate]);

    }
}
