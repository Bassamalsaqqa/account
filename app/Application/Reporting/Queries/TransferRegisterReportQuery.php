<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\MoneyReportHelper;
use App\Application\Reporting\Support\OperationalReportRead as Read;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TransferRegisterReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.money.view', 'money.transfer.view']);
        $f = Read::filters($company, $filters, ['money_account_id', 'currency_code', 'status'], ['status' => ['original', 'reversal']]);

        $sources = DB::table('money_transfers as s')->where('s.company_id', $company->id);
        if ($f->moneyAccountId !== null) {
            $sources->where(function (Builder $q) use ($f): void {
                $q->where('s.from_money_account_id', $f->moneyAccountId)->orWhere('s.to_money_account_id', $f->moneyAccountId);
            });
        }
        if ($f->currencyCode !== null) {
            $sources->where(function (Builder $q) use ($f): void {
                $q->where('s.from_currency_code', $f->currencyCode)->orWhere('s.to_currency_code', $f->currencyCode);
            });
        }
        $legs = Read::eventLegs($sources, $company, 'money_transfer', 'transfer_date', [
            'transfer_number' => 'transfer_number', 'from_money_account_id' => 'from_money_account_id', 'to_money_account_id' => 'to_money_account_id',
            'from_account_snapshot' => 'from_account_snapshot', 'to_account_snapshot' => 'to_account_snapshot',
            'from_currency_code' => 'from_currency_code', 'to_currency_code' => 'to_currency_code',
            'from_amount' => 'from_amount', 'to_amount' => 'to_amount', 'base_value_from' => 'base_value_from', 'base_value_to' => 'base_value_to',
            'from_exchange_rate' => 'from_exchange_rate', 'to_exchange_rate' => 'to_exchange_rate', 'fx_gain_loss_base' => 'fx_gain_loss_base'])
            ->whereBetween('date', [$f->period->startDate, $f->period->endDate]);
        if ($f->status !== null) {
            $legs->where('is_reversal', $f->status === 'reversal' ? 1 : 0);
        }
        $q = DB::query()->fromSub($legs, 'transfer_legs')->select('id', 'public_id', 'date', 'is_reversal', 'transfer_number',
            'from_money_account_id', 'to_money_account_id', 'from_account_snapshot', 'to_account_snapshot',
            'from_currency_code', 'to_currency_code', 'from_exchange_rate', 'to_exchange_rate');
        foreach (['from_amount', 'to_amount', 'base_value_from', 'base_value_to', 'fx_gain_loss_base'] as $column) {
            $q->selectRaw("CASE WHEN is_reversal=1 THEN -$column ELSE $column END AS $column");
        }
        $scope = DB::query()->fromSub($q, 'signed_transfers');
        $totals = ['total_fx_gain_loss_base' => Read::decimal((string) (clone $scope)->sum('fx_gain_loss_base')), 'records_count' => (clone $scope)->count()];

        return Read::result('money.transfers', $company, $f, $scope->orderBy('date')->orderBy('id')->orderBy('is_reversal'), $totals,
            static function (object $r): array {
                $row = ['transfer_id' => (int) $r->id, 'public_id' => (string) $r->public_id, 'transfer_number' => (string) $r->transfer_number,
                    'date' => (string) $r->date, 'from_money_account_id' => (int) $r->from_money_account_id, 'to_money_account_id' => (int) $r->to_money_account_id,
                    'from_account_name' => MoneyReportHelper::extractPartyName(Read::snapshot($r->from_account_snapshot)),
                    'to_account_name' => MoneyReportHelper::extractPartyName(Read::snapshot($r->to_account_snapshot)),
                    'from_currency_code' => (string) $r->from_currency_code, 'to_currency_code' => (string) $r->to_currency_code,
                    'from_exchange_rate' => (string) $r->from_exchange_rate, 'to_exchange_rate' => (string) $r->to_exchange_rate, 'is_reversal' => (bool) $r->is_reversal];
                foreach (['from_amount', 'to_amount', 'base_value_from', 'base_value_to', 'fx_gain_loss_base'] as $column) {
                    $row[$column] = Read::decimal((string) $r->$column);
                }

                return $row;
            });

    }
}
