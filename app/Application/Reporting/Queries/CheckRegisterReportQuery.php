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
use App\Services\Phase7\Phase7FinancialRead;
use Illuminate\Support\Facades\DB;

final class CheckRegisterReportQuery
{
    public function __construct(private ReportingGuard $guard) {}

    /** @param ReportFilters|array<string,mixed> $filters */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $user = $this->guard->authorize($company, $actor, ['reports.money.view', 'money.check.view']);
        $f = Read::filters($company, $filters, ['customer_id', 'vendor_id', 'currency_code', 'status', 'grouping'], ['status' => ['received', 'issued', 'deposited', 'cleared', 'returned', 'cancelled', 'due'], 'grouping' => ['incoming', 'outgoing']]);

        $latest = DB::table('check_events as e')->where('e.company_id', $company->id)->whereNotNull('e.completed_at')
            ->where('e.event_date', '<=', $f->period->endDate)->select('e.check_id', 'e.to_status', 'e.event_date', 'e.id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY e.check_id ORDER BY e.event_date DESC,e.id DESC) AS position');
        $q = DB::table('checks as c')->joinSub($latest, 'state', 'state.check_id', '=', 'c.id')
            ->where('state.position', 1)->where('c.company_id', $company->id)->where('c.received_issued_date', '<=', $f->period->endDate)
            ->whereIn('c.id', app(Phase7FinancialRead::class)->visibleCheckIds((int) $company->id));
        foreach (['c.customer_id' => $f->customerId, 'c.vendor_id' => $f->vendorId, 'c.currency_code' => $f->currencyCode,
            'c.direction' => $f->grouping] as $key => $value) {
            if ($value !== null) {
                $q->where($key, $value);
            }
        }
        if ($f->status === 'due') {
            $q->whereIn('state.to_status', ['received', 'issued', 'deposited'])->where('c.due_date', '<=', $f->period->endDate);
        } elseif ($f->status !== null) {
            $q->where('state.to_status', $f->status);
        }
        $q->select('c.id', 'c.public_id', 'c.check_number', 'c.direction', 'c.party_snapshot', 'c.received_issued_date', 'c.due_date',
            'c.currency_code', 'c.amount', 'c.exchange_rate', 'c.amount_base', 'state.to_status as status');
        $scope = DB::query()->fromSub($q, 'check_register');
        $counts = [];
        foreach ((clone $scope)->selectRaw('status,COUNT(*) AS count')->groupBy('status')->get() as $r) {
            $counts[(string) $r->status] = (int) $r->count;
        }

        return Read::result('money.checks', $company, $f, $scope->orderBy('received_issued_date')->orderBy('id'),
            ['currency_totals' => Read::currencyTotals($scope), 'status_counts' => $counts, 'total_checks' => (clone $scope)->count()],
            static fn (object $r): array => ['check_id' => (int) $r->id, 'public_id' => (string) $r->public_id,
                'check_number' => (string) $r->check_number, 'direction' => (string) $r->direction, 'status' => (string) $r->status,
                'party_name' => MoneyReportHelper::extractPartyName(Read::snapshot($r->party_snapshot)),
                'received_issued_date' => (string) $r->received_issued_date, 'due_date' => (string) $r->due_date,
                'currency_code' => (string) $r->currency_code, 'amount' => Read::decimal((string) $r->amount),
                'exchange_rate' => (string) $r->exchange_rate, 'amount_base' => Read::decimal((string) $r->amount_base)],
            ['as_of_date' => $f->period->endDate, 'date_semantics' => 'received_by_cutoff']);

    }
}
