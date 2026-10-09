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
use Illuminate\Support\Facades\DB;

final class ReceiptRegisterReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.money.view', 'money.receipt.view']);
        $f = Read::filters($company, $filters, ['customer_id', 'money_account_id', 'currency_code', 'status'], ['status' => ['original', 'reversal']]);
        $sources = DB::table('customer_payments as s')->where('s.company_id', $company->id);
        if ($f->customerId !== null) {
            $sources->where('s.customer_id', $f->customerId);
        }
        if ($f->moneyAccountId !== null) {
            $sources->where('s.money_account_id', $f->moneyAccountId);
        }
        if ($f->currencyCode !== null) {
            $sources->where('s.currency_code', $f->currencyCode);
        }
        $q = Read::eventLegs($sources, $company, 'customer_payment', 'payment_date', [
            'payment_number' => 'payment_number', 'party_id' => 'customer_id', 'party_snapshot' => 'customer_snapshot',
            'currency_code' => 'currency_code', 'amount' => 'amount', 'base_amount' => 'amount_base',
            'payment_method' => 'payment_method', 'exchange_rate' => 'exchange_rate']);
        $q->whereBetween('date', [$f->period->startDate, $f->period->endDate]);
        if ($f->status !== null) {
            $q->where('is_reversal', $f->status === 'reversal' ? 1 : 0);
        }
        $signed = DB::query()->fromSub($q, 'activity')->select('id', 'public_id', 'date', 'is_reversal', 'party_id', 'party_snapshot',
            'payment_number', 'payment_method', 'exchange_rate', 'currency_code')
            ->selectRaw('CASE WHEN is_reversal = 1 THEN -amount ELSE amount END AS amount')
            ->selectRaw('CASE WHEN is_reversal = 1 THEN -base_amount ELSE base_amount END AS base_amount');
        $signed = DB::query()->fromSub($signed, 'signed_events');
        $total = Read::decimal((string) (clone $signed)->sum('base_amount'));
        $totals = ['total_base_amount' => $total, 'currency_totals' => Read::currencyTotals($signed),
            'records_count' => (clone $signed)->count()];

        return Read::result('money.receipts', $company, $f, $signed->orderBy('date')->orderBy('id')->orderBy('is_reversal'), $totals,
            static fn (object $r): array => ['payment_id' => (int) $r->id, 'public_id' => (string) $r->public_id,
                'payment_number' => (string) $r->payment_number, 'date' => (string) $r->date,
                'party_name' => MoneyReportHelper::extractPartyName(Read::snapshot($r->party_snapshot)),
                'customer_id' => (int) $r->party_id, 'payment_method' => (string) $r->payment_method,
                'currency_code' => (string) $r->currency_code, 'amount' => Read::decimal((string) $r->amount),
                'amount_base' => Read::decimal((string) $r->base_amount), 'exchange_rate' => (string) $r->exchange_rate,
                'is_reversal' => (bool) $r->is_reversal, 'status' => $r->is_reversal ? 'reversal' : 'original']);
    }
}
