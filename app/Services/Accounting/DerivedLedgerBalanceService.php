<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DerivedLedgerBalanceService
{
    /**
     * Compute the exact cumulative balance for an account as of a given date.
     * Respects the account's normal balance direction (Debit: Debits - Credits, Credit: Credits - Debits).
     */
    public function getAccountBalance(LedgerAccount $account, ?DateTimeInterface $asOfDate = null): MoneyAmount
    {
        $context = app(CompanyContext::class);

        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('Cannot retrieve account balance without an active company context.');
        }

        if ($context->companyId() !== $account->company_id) {
            throw new CompanyReassignmentException("Cannot retrieve balance for account belonging to company [{$account->company_id}] when active company is [{$context->companyId()}].");
        }

        $query = DB::table('posting_lines')
            ->join('posting_batches', 'posting_lines.posting_batch_id', '=', 'posting_batches.id')
            ->where('posting_lines.company_id', $account->company_id)
            ->where('posting_lines.ledger_account_id', $account->id);

        if ($asOfDate !== null) {
            $query->where('posting_batches.posting_date', '<=', $asOfDate->format('Y-m-d'));
        }

        $totals = $query->selectRaw('COALESCE(SUM(posting_lines.debit_base), 0) as total_debit, COALESCE(SUM(posting_lines.credit_base), 0) as total_credit')
            ->first();

        $debit = BigDecimal::of((string) ($totals->total_debit ?? '0'));
        $credit = BigDecimal::of((string) ($totals->total_credit ?? '0'));

        $balance = $account->isDebitNormal()
            ? $debit->minus($credit)
            : $credit->minus($debit);

        return MoneyAmount::from($balance);
    }

    /**
     * Compute activity (debits, credits, net change) for an account over an optional date range.
     *
     * @return array{total_debit: MoneyAmount, total_credit: MoneyAmount, net_movement: MoneyAmount}
     */
    public function getAccountActivity(LedgerAccount $account, ?DateTimeInterface $startDate = null, ?DateTimeInterface $endDate = null): array
    {
        $context = app(CompanyContext::class);

        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('Cannot retrieve account activity without an active company context.');
        }

        if ($context->companyId() !== $account->company_id) {
            throw new CompanyReassignmentException("Cannot retrieve activity for account belonging to company [{$account->company_id}] when active company is [{$context->companyId()}].");
        }

        $query = DB::table('posting_lines')
            ->join('posting_batches', 'posting_lines.posting_batch_id', '=', 'posting_batches.id')
            ->where('posting_lines.company_id', $account->company_id)
            ->where('posting_lines.ledger_account_id', $account->id);

        if ($startDate !== null) {
            $query->where('posting_batches.posting_date', '>=', $startDate->format('Y-m-d'));
        }

        if ($endDate !== null) {
            $query->where('posting_batches.posting_date', '<=', $endDate->format('Y-m-d'));
        }

        $totals = $query->selectRaw('COALESCE(SUM(posting_lines.debit_base), 0) as total_debit, COALESCE(SUM(posting_lines.credit_base), 0) as total_credit')
            ->first();

        $debit = MoneyAmount::from((string) ($totals->total_debit ?? '0'));
        $credit = MoneyAmount::from((string) ($totals->total_credit ?? '0'));

        $movement = $account->isDebitNormal()
            ? $debit->minus($credit)
            : $credit->minus($debit);

        return [
            'total_debit' => $debit,
            'total_credit' => $credit,
            'net_movement' => $movement,
        ];
    }

    /**
     * Compute derived trial balance rows and totals for a company as of a date.
     *
     * @return array{
     *     rows: list<array{
     *         account_id: int,
     *         code: string,
     *         name_ar: string,
     *         name_en: ?string,
     *         account_type: string,
     *         normal_balance: string,
     *         total_debit: MoneyAmount,
     *         total_credit: MoneyAmount,
     *         balance: MoneyAmount
     *     }>,
     *     total_debit: MoneyAmount,
     *     total_credit: MoneyAmount,
     *     is_balanced: bool
     * }
     */
    public function getTrialBalance(Company $company, ?DateTimeInterface $asOfDate = null): array
    {
        $context = app(CompanyContext::class);

        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('Cannot retrieve trial balance without an active company context.');
        }

        if ($context->companyId() !== $company->id) {
            throw new CompanyReassignmentException("Cannot retrieve trial balance for company [{$company->id}] when active company is [{$context->companyId()}].");
        }

        /** @var Collection<int, LedgerAccount> $accounts */
        $accounts = LedgerAccount::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('code')
            ->get();

        $rows = [];
        $grandDebit = BigDecimal::zero();
        $grandCredit = BigDecimal::zero();

        foreach ($accounts as $account) {
            $query = DB::table('posting_lines')
                ->join('posting_batches', 'posting_lines.posting_batch_id', '=', 'posting_batches.id')
                ->where('posting_lines.company_id', $company->id)
                ->where('posting_lines.ledger_account_id', $account->id);

            if ($asOfDate !== null) {
                $query->where('posting_batches.posting_date', '<=', $asOfDate->format('Y-m-d'));
            }

            $totals = $query->selectRaw('COALESCE(SUM(posting_lines.debit_base), 0) as total_debit, COALESCE(SUM(posting_lines.credit_base), 0) as total_credit')
                ->first();

            $debitDec = BigDecimal::of((string) ($totals->total_debit ?? '0'));
            $creditDec = BigDecimal::of((string) ($totals->total_credit ?? '0'));

            $grandDebit = $grandDebit->plus($debitDec);
            $grandCredit = $grandCredit->plus($creditDec);

            $balanceDec = $account->isDebitNormal()
                ? $debitDec->minus($creditDec)
                : $creditDec->minus($debitDec);

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name_ar' => $account->name_ar,
                'name_en' => $account->name_en,
                'account_type' => $account->account_type,
                'normal_balance' => $account->normal_balance,
                'total_debit' => MoneyAmount::from($debitDec),
                'total_credit' => MoneyAmount::from($creditDec),
                'balance' => MoneyAmount::from($balanceDec),
            ];
        }

        return [
            'rows' => $rows,
            'total_debit' => MoneyAmount::from($grandDebit),
            'total_credit' => MoneyAmount::from($grandCredit),
            'is_balanced' => $grandDebit->isEqualTo($grandCredit),
        ];
    }
}
