<?php

declare(strict_types=1);

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\OperationalReportRead;
use App\Domain\Money\Queries\MoneyMovementVisibility;
use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\Money\MoneyAccountLedger;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyLedgerMetadata;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class MoneyMovementReportQuery
{
    public function __construct(
        protected ReportingGuard $guard
    ) {}

    /**
     * Execute Money Movement Ledger report with full-history window running balances
     * and date filtering applied strictly after the window.
     *
     * @param  ReportFilters|array<string, mixed>  $filters
     */
    public function execute(Company $company, ReportFilters|array $filters = [], ?User $actor = null): ReportResult
    {
        $company = $this->guard->company($company, $actor);
        $this->guard->authorize($company, $actor, 'reports.money.view');

        $validatedFilters = OperationalReportRead::filters($company, $filters, ['money_account_id']);

        if ($validatedFilters->moneyAccountId === null) {
            throw InvalidReportFilterException::invalidValue('money_account_id', null, 'A money_account_id filter is required for money movement ledger.');
        }

        /** @var MoneyAccount $account */
        $account = MoneyAccount::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->findOrFail($validatedFilters->moneyAccountId);

        app(MoneyActorGuard::class)->account($account);
        app(MoneyAccountLedger::class)->validate($account);

        $visible = app(MoneyMovementVisibility::class)->visibleBatchIds((int) $account->company_id);

        $history = DB::table('posting_lines as l')
            ->join('posting_batches as b', 'b.id', '=', 'l.posting_batch_id')
            ->where('l.company_id', $account->company_id)
            ->where('b.company_id', $account->company_id)
            ->where('l.ledger_account_id', $account->ledger_account_id);

        $rows = $history
            ->select(
                'l.id',
                'l.description',
                'l.debit_base',
                'l.credit_base',
                'l.transaction_amount',
                'l.transaction_currency_code',
                'b.posting_date',
                'b.source_type',
                'b.source_id',
                'b.status',
                'b.reversal_of_id',
                'b.id as visibility_batch_id'
            )
            ->selectRaw('MAX(CASE WHEN b.id IN ('.$visible->toSql().') THEN 0 ELSE 1 END) OVER () AS has_hidden_history', $visible->getBindings())
            ->selectRaw('SUM(l.debit_base-l.credit_base) OVER (ORDER BY b.posting_date, b.id, l.line_number, l.id) AS running_base')
            ->selectRaw('SUM(CASE WHEN l.transaction_currency_code = ? THEN CASE WHEN l.debit_base > 0 THEN l.transaction_amount ELSE -l.transaction_amount END ELSE 0 END) OVER (ORDER BY b.posting_date, b.id, l.line_number, l.id) AS known_running_currency', [$account->currency_code])
            ->selectRaw('SUM('.MoneyLedgerMetadata::unknownExpression().') OVER (ORDER BY b.posting_date, b.id, l.line_number, l.id) AS unknown_currency_lines', [$account->currency_code, $account->currency_code]);

        $period = $validatedFilters->period;

        $outer = DB::query()->fromSub($rows, 'movements')
            ->whereIn('visibility_batch_id', $visible)
            ->select('id', 'description', 'debit_base', 'credit_base', 'transaction_amount', 'transaction_currency_code', 'posting_date', 'source_type', 'source_id', 'status', 'reversal_of_id')
            ->selectRaw('CASE WHEN has_hidden_history = 0 THEN running_base ELSE NULL END AS running_base')
            ->selectRaw('CASE WHEN has_hidden_history = 0 THEN known_running_currency ELSE NULL END AS known_running_currency')
            ->selectRaw('CASE WHEN has_hidden_history = 0 THEN unknown_currency_lines ELSE NULL END AS unknown_currency_lines')
            ->where('posting_date', '>=', $period->startDate)
            ->where('posting_date', '<=', $period->endDate)
            ->orderBy('posting_date')
            ->orderBy('id');

        $totalCount = (clone $outer)->reorder()->count();
        $totalDebit = BigDecimal::of((string) (clone $outer)->reorder()->sum('debit_base'))->toScale(6);
        $totalCredit = BigDecimal::of((string) (clone $outer)->reorder()->sum('credit_base'))->toScale(6);
        $results = (clone $outer)->forPage($validatedFilters->page, $validatedFilters->perPage)->get();

        $formattedRows = [];

        foreach ($results as $res) {
            $deb = BigDecimal::of((string) $res->debit_base)->toScale(6);
            $crd = BigDecimal::of((string) $res->credit_base)->toScale(6);

            $runningBaseStr = $res->running_base !== null
                ? BigDecimal::of((string) $res->running_base)->toScale(6)->__toString()
                : null;

            $runningCurrStr = $res->known_running_currency !== null && (int) ($res->unknown_currency_lines ?? 0) === 0
                ? BigDecimal::of((string) $res->known_running_currency)->toScale(6)->__toString()
                : null;

            $formattedRows[] = [
                'line_id' => (int) $res->id,
                'posting_date' => (string) $res->posting_date,
                'description' => (string) ($res->description ?? ''),
                'debit_base' => $deb->__toString(),
                'credit_base' => $crd->__toString(),
                'transaction_amount' => $res->transaction_amount !== null
                    ? BigDecimal::of((string) $res->transaction_amount)->toScale(6)->__toString()
                    : null,
                'transaction_currency_code' => $res->transaction_currency_code !== null ? (string) $res->transaction_currency_code : null,
                'source_type' => (string) $res->source_type,
                'source_id' => (int) $res->source_id,
                'running_base' => $runningBaseStr,
                'running_currency' => $runningCurrStr,
                'is_reversed' => $res->status === 'reversed' || $res->reversal_of_id !== null,
            ];
        }

        $page = $validatedFilters->page;
        $perPage = $validatedFilters->perPage;
        $pagedRows = $formattedRows;

        return new ReportResult(
            reportType: 'money.movements',
            filters: $validatedFilters->toArray(),
            totals: [
                'total_debit_base' => $totalDebit->__toString(),
                'total_credit_base' => $totalCredit->__toString(),
                'net_movement_base' => $totalDebit->minus($totalCredit)->__toString(),
                'records_count' => $totalCount,
            ],
            rows: $pagedRows,
            currency: [
                'account_currency_code' => $account->currency_code,
                'base_currency_code' => $company->base_currency_code,
            ],
            pagination: [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalCount,
                'last_page' => (int) ceil(max(1, $totalCount) / $perPage),
            ],
            generatedAt: now()->toIso8601String(),
            meta: [
                'money_account_id' => $account->id,
                'money_account_name' => $account->displayName(),
            ]
        );
    }
}
