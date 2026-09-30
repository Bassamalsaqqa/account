<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Accounting\Catalog\SystemAccountsCatalog;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountingReconciliationService
{
    /**
     * Audit and reconcile financial records for a company without performing mutations.
     */
    public function reconcile(Company $company, bool $isSystem = false): ReconciliationReport
    {
        $context = app(CompanyContext::class);

        if ($isSystem) {
            if ($context->hasCompany()) {
                throw new InvalidArgumentException('System mode reconciliation requires no active company context.');
            }
        } else {
            if (! $context->hasCompany()) {
                throw new NoActiveCompanyException('Cannot reconcile financial records without an active company context.');
            }

            if ($context->companyId() !== $company->id) {
                throw new CompanyReassignmentException("Cannot reconcile financial records for company [{$company->id}] when active company is [{$context->companyId()}].");
            }
        }

        $execute = function () use ($company): ReconciliationReport {
            $violations = [];

            // Reload current persisted company state
            /** @var Company $persistedCompany */
            $persistedCompany = Company::where('id', $company->id)->firstOrFail();
            $cid = $persistedCompany->id;
            $companyBase = strtoupper($persistedCompany->base_currency_code);

            // 1. Currency Configuration Reconciliation (read-only)
            $companyCurrencies = DB::table('company_currencies')
                ->where('company_id', $cid)
                ->get();

            $standardCurrencies = ['ILS', 'USD', 'JOD'];
            $configuredCodes = $companyCurrencies->pluck('currency_code')->map(fn ($c) => strtoupper((string) $c))->all();
            foreach ($standardCurrencies as $sc) {
                if (! in_array($sc, $configuredCodes, true)) {
                    $violations[] = "Currency configuration error: missing standard currency row [{$sc}] for company [{$cid}].";
                }
            }

            $baseCurrencies = $companyCurrencies->filter(fn ($c) => (bool) $c->is_base);
            if ($baseCurrencies->isEmpty()) {
                $violations[] = "Currency configuration error: company [{$cid}] has no base currency flag.";
            } elseif ($baseCurrencies->count() > 1) {
                $violations[] = "Currency configuration error: company [{$cid}] has multiple base currency flags ({$baseCurrencies->count()}).";
            }

            foreach ($baseCurrencies as $flaggedBase) {
                if (strtoupper((string) $flaggedBase->currency_code) !== $companyBase) {
                    $violations[] = "Currency configuration mismatch: currency [{$flaggedBase->currency_code}] is flagged as base, but company base is [{$companyBase}].";
                }
            }

            $baseRow = $companyCurrencies->first(fn ($c) => strtoupper((string) $c->currency_code) === $companyBase);
            if ($baseRow === null) {
                $violations[] = "Currency configuration error: missing base currency row [{$companyBase}] for company [{$cid}].";
            } else {
                if (! (bool) $baseRow->enabled) {
                    $violations[] = "Currency configuration error: base currency [{$companyBase}] is disabled for company [{$cid}].";
                }
                if (! (bool) $baseRow->is_base) {
                    $violations[] = "Currency configuration error: base currency row [{$companyBase}] is not flagged as base for company [{$cid}].";
                }
            }

            // 2. Canonical System Accounts & Semantics (existence, uniqueness, and semantic conformity)
            $accountsBySystemKey = LedgerAccount::where('company_id', $cid)
                ->whereNotNull('system_key')
                ->get()
                ->groupBy('system_key');

            $allAccounts = LedgerAccount::where('company_id', $cid)->get()->keyBy('system_key');

            foreach (SystemAccountsCatalog::all() as $def) {
                $matching = $accountsBySystemKey->get($def->systemKey);
                $count = $matching ? $matching->count() : 0;

                if ($count === 0) {
                    $violations[] = "Missing required system account with key [{$def->systemKey}].";

                    continue;
                }

                if ($count > 1) {
                    $violations[] = "Duplicate system account key [{$def->systemKey}] detected: {$count} accounts found.";
                }

                /** @var LedgerAccount $acct */
                $acct = $matching->first();

                if ((int) $acct->company_id !== (int) $cid) {
                    $violations[] = "System account [{$def->systemKey}] company_id mismatch: expected [{$cid}], found [{$acct->company_id}].";
                }

                if ($acct->code !== $def->code) {
                    $violations[] = "System account [{$def->systemKey}] code mismatch: expected [{$def->code}], found [{$acct->code}].";
                }

                if ($acct->account_type !== $def->accountType) {
                    $violations[] = "System account [{$def->systemKey}] account_type mismatch: expected [{$def->accountType}], found [{$acct->account_type}].";
                }

                if ($acct->normal_balance !== $def->normalBalance) {
                    $violations[] = "System account [{$def->systemKey}] normal_balance mismatch: expected [{$def->normalBalance}], found [{$acct->normal_balance}].";
                }

                if ((bool) $acct->is_control !== $def->isControl) {
                    $violations[] = "System account [{$def->systemKey}] is_control mismatch: expected [".($def->isControl ? 'true' : 'false').'], found ['.($acct->is_control ? 'true' : 'false').'].';
                }

                if (! (bool) $acct->is_system) {
                    $violations[] = "System account [{$def->systemKey}] is_system mismatch: expected [true], found [false].";
                }

                if (! (bool) $acct->active) {
                    $violations[] = "System account [{$def->systemKey}] is inactive, but required system accounts must be active.";
                }

                if ($def->parentSystemKey !== null) {
                    $parent = $allAccounts->get($def->parentSystemKey);
                    if ($parent === null || (int) $acct->parent_id !== (int) $parent->id) {
                        $violations[] = "System account [{$def->systemKey}] hierarchy mismatch: expected parent [{$def->parentSystemKey}], found parent_id [{$acct->parent_id}].";
                    }
                } else {
                    if ($acct->parent_id !== null) {
                        $violations[] = "Root system account [{$def->systemKey}] should not have a parent, but has parent_id [{$acct->parent_id}].";
                    }
                }
            }

            $crossCompanyParents = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM ledger_accounts child
                JOIN ledger_accounts parent ON child.parent_id = parent.id
                WHERE child.company_id = ? AND parent.company_id <> ?
            ', [$cid, $cid]);

            if (($crossCompanyParents->cnt ?? 0) > 0) {
                $violations[] = "Cross-company parent leak: {$crossCompanyParents->cnt} accounts in company [{$cid}] point to parents in another company.";
            }

            // 3. Batch Status, Base Currency, and Rate Checks
            $batches = DB::table('posting_batches')
                ->where('company_id', $cid)
                ->get();

            foreach ($batches as $batch) {
                if (! in_array($batch->status, ['posted', 'reversed'], true)) {
                    $violations[] = "Invalid batch status [{$batch->status}] on batch [{$batch->public_id}].";
                }

                if (strtoupper($batch->base_currency_code) !== $companyBase) {
                    $violations[] = "Invalid batch base currency [{$batch->base_currency_code}] on batch [{$batch->public_id}], expected company base [{$companyBase}].";
                }

                $batchRateDec = BigDecimal::of((string) $batch->exchange_rate);
                if ($batchRateDec->isNegative() || $batchRateDec->isZero()) {
                    $violations[] = "Non-positive exchange rate [{$batch->exchange_rate}] on batch [{$batch->public_id}].";
                } else {
                    if (strtoupper($batch->transaction_currency_code) === $companyBase && ! ExchangeRate::from((string) $batch->exchange_rate)->isOne()) {
                        $violations[] = "Base-currency batch [{$batch->public_id}] exchange rate [{$batch->exchange_rate}] must be exactly 1.0000000000.";
                    }
                }
            }

            // 4. Per-Batch Imbalance and Line Count Check (Empty or One-Line Batches)
            $batchChecks = DB::select('
                SELECT b.id, b.public_id,
                       COUNT(l.id) AS line_count,
                       COALESCE(SUM(l.debit_base), 0) AS total_debit,
                       COALESCE(SUM(l.credit_base), 0) AS total_credit
                FROM posting_batches b
                LEFT JOIN posting_lines l ON b.id = l.posting_batch_id
                WHERE b.company_id = ?
                GROUP BY b.id, b.public_id
                HAVING line_count < 2 OR total_debit <> total_credit
            ', [$cid]);

            foreach ($batchChecks as $row) {
                if ($row->line_count < 2) {
                    $violations[] = "Invalid batch [{$row->public_id}]: batch has only {$row->line_count} line(s) (minimum 2 lines required for double entry).";
                }
                $batchDebit = BigDecimal::of((string) $row->total_debit);
                $batchCredit = BigDecimal::of((string) $row->total_credit);
                if (! $batchDebit->isEqualTo($batchCredit)) {
                    $violations[] = "Unbalanced batch [{$row->public_id}]: total debit ({$row->total_debit}) does not equal total credit ({$row->total_credit}).";
                }
            }

            // 5. Whole-Ledger Imbalance Check
            $totals = DB::selectOne('
                SELECT COALESCE(SUM(debit_base), 0) as total_debit, COALESCE(SUM(credit_base), 0) as total_credit
                FROM posting_lines
                WHERE company_id = ?
            ', [$cid]);

            $ledgerDebit = BigDecimal::of((string) ($totals->total_debit ?? 0));
            $ledgerCredit = BigDecimal::of((string) ($totals->total_credit ?? 0));

            if (! $ledgerDebit->isEqualTo($ledgerCredit)) {
                $violations[] = "Whole-ledger imbalance: total debits ({$ledgerDebit}) do not equal total credits ({$ledgerCredit}).";
            }

            // 6. Posting Lines: Invalid Amounts, Incomplete Metadata, and Conversion Consistency
            $lines = DB::table('posting_lines')->where('company_id', $cid)->get();
            foreach ($lines as $line) {
                $debitDec = BigDecimal::of((string) $line->debit_base);
                $creditDec = BigDecimal::of((string) $line->credit_base);

                if ($debitDec->isNegative() || $creditDec->isNegative() || ($debitDec->isPositive() && $creditDec->isPositive()) || ($debitDec->isZero() && $creditDec->isZero())) {
                    $violations[] = "Invalid posting line [{$line->id}]: amounts must be single-directional and positive.";
                }

                $hasCurr = $line->transaction_currency_code !== null;
                $hasAmt = $line->transaction_amount !== null;
                $hasRate = $line->exchange_rate !== null;

                if (($hasCurr || $hasAmt || $hasRate) && ! ($hasCurr && $hasAmt && $hasRate)) {
                    $violations[] = "Line [{$line->id}] of batch [{$line->posting_batch_id}] has incomplete metadata: currency, amount, and rate must either all be null or all present.";
                } elseif ($hasCurr && $hasAmt && $hasRate) {
                    $amtDec = BigDecimal::of((string) $line->transaction_amount);
                    $rateDec = BigDecimal::of((string) $line->exchange_rate);

                    if ($amtDec->isNegative() || $amtDec->isZero()) {
                        $violations[] = "Line [{$line->id}] has non-positive transaction amount [{$line->transaction_amount}].";
                    }
                    if ($rateDec->isNegative() || $rateDec->isZero()) {
                        $violations[] = "Line [{$line->id}] has non-positive exchange rate [{$line->exchange_rate}].";
                    }

                    if ($rateDec->isPositive() && $amtDec->isPositive()) {
                        if (strtoupper((string) $line->transaction_currency_code) === $companyBase && ! $rateDec->isEqualTo(BigDecimal::one())) {
                            $violations[] = "Base-currency line [{$line->id}] exchange rate [{$line->exchange_rate}] must be exactly 1.0000000000.";
                        }

                        $actualBaseDec = $debitDec->isPositive() ? $debitDec : $creditDec;
                        $expectedBaseDec = $amtDec->multipliedBy($rateDec)->toScale(MoneyAmount::DEFAULT_SCALE, RoundingMode::HALF_UP);

                        if (! $actualBaseDec->isEqualTo($expectedBaseDec)) {
                            $violations[] = "Line [{$line->id}] conversion mismatch: positive base [{$actualBaseDec}] does not equal transaction amount [{$line->transaction_amount}] * exchange rate [{$line->exchange_rate}].";
                        }
                    }
                }
            }

            // 7. Bidirectional Cross-Company Linkages Check
            // 7a. Lines in company pointing to batches of another company
            $lineBatchMismatch = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM posting_lines l
                JOIN posting_batches b ON l.posting_batch_id = b.id
                WHERE l.company_id = ? AND b.company_id <> ?
            ', [$cid, $cid]);

            if (($lineBatchMismatch->cnt ?? 0) > 0) {
                $violations[] = "Cross-company batch leak: {$lineBatchMismatch->cnt} lines in company [{$cid}] point to batches of another company.";
            }

            // 7b. Batches in company containing lines belonging to another company
            $batchLineMismatch = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM posting_batches b
                JOIN posting_lines l ON b.id = l.posting_batch_id
                WHERE b.company_id = ? AND l.company_id <> ?
            ', [$cid, $cid]);

            if (($batchLineMismatch->cnt ?? 0) > 0) {
                $violations[] = "Cross-company batch leak: {$batchLineMismatch->cnt} lines of another company linked to batches in company [{$cid}].";
            }

            // 7c. Lines in company pointing to accounts of another company
            $lineAccountMismatch = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM posting_lines l
                JOIN ledger_accounts a ON l.ledger_account_id = a.id
                WHERE l.company_id = ? AND a.company_id <> ?
            ', [$cid, $cid]);

            if (($lineAccountMismatch->cnt ?? 0) > 0) {
                $violations[] = "Cross-company account leak: {$lineAccountMismatch->cnt} lines in company [{$cid}] point to accounts of another company.";
            }

            // 7d. Accounts in company containing lines belonging to another company
            $accountLineMismatch = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM ledger_accounts a
                JOIN posting_lines l ON a.id = l.ledger_account_id
                WHERE a.company_id = ? AND l.company_id <> ?
            ', [$cid, $cid]);

            if (($accountLineMismatch->cnt ?? 0) > 0) {
                $violations[] = "Cross-company account leak: {$accountLineMismatch->cnt} lines of another company linked to accounts in company [{$cid}].";
            }

            // 8. Comprehensive Reversal Coherence & Inversion Checks
            // 8a. Batches marked 'reversed' must have reversed_by_batch_id pointing to a valid reciprocal reversal batch in the same company
            $unlinkedReversals = DB::selectOne("
                SELECT COUNT(*) as cnt
                FROM posting_batches
                WHERE company_id = ?
                  AND status = 'reversed'
                  AND reversed_by_batch_id IS NULL
            ", [$cid]);

            if (($unlinkedReversals->cnt ?? 0) > 0) {
                $violations[] = "Incoherent reversal: {$unlinkedReversals->cnt} batches marked 'reversed' lack a reversed_by_batch_id link.";
            }

            $mismatchedReversalLinks = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM posting_batches orig
                LEFT JOIN posting_batches rev ON orig.reversed_by_batch_id = rev.id
                WHERE orig.company_id = ?
                  AND orig.status = \'reversed\'
                  AND orig.reversed_by_batch_id IS NOT NULL
                  AND (rev.id IS NULL OR rev.company_id <> ? OR rev.reversal_of_id <> orig.id)
            ', [$cid, $cid]);

            if (($mismatchedReversalLinks->cnt ?? 0) > 0) {
                $violations[] = "Mismatched reversal link: {$mismatchedReversalLinks->cnt} reversed batches fail to cross-link reciprocally to their reversal batch.";
            }

            // 8b. Reversal batches (reversal_of_id IS NOT NULL) must point to an existing reversed batch in the same company that cross-links back
            $orphanReversals = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM posting_batches rev
                LEFT JOIN posting_batches orig ON rev.reversal_of_id = orig.id
                WHERE rev.company_id = ?
                  AND rev.reversal_of_id IS NOT NULL
                  AND (orig.id IS NULL OR orig.company_id <> ? OR orig.reversed_by_batch_id <> rev.id OR orig.status <> \'reversed\')
            ', [$cid, $cid]);

            if (($orphanReversals->cnt ?? 0) > 0) {
                $violations[] = "Orphan reversal: {$orphanReversals->cnt} reversal batches fail to link to a valid reversed original batch.";
            }

            // 8c. A reversal batch cannot itself be marked 'reversed'
            $reversedReversals = DB::selectOne("
                SELECT COUNT(*) as cnt
                FROM posting_batches
                WHERE company_id = ?
                  AND reversal_of_id IS NOT NULL
                  AND status = 'reversed'
            ", [$cid]);

            if (($reversedReversals->cnt ?? 0) > 0) {
                $violations[] = "Invalid reversal status: {$reversedReversals->cnt} reversal batches are marked 'reversed'.";
            }

            // 8d. Reversal Batch Rate and Line Inversion Checks
            $reversals = DB::table('posting_batches as rev')
                ->join('posting_batches as orig', 'rev.reversal_of_id', '=', 'orig.id')
                ->where('rev.company_id', $cid)
                ->where('orig.company_id', $cid)
                ->select(
                    'rev.id as rev_id',
                    'orig.id as orig_id',
                    'rev.exchange_rate as rev_rate',
                    'orig.exchange_rate as orig_rate'
                )
                ->get();

            foreach ($reversals as $rev) {
                try {
                    $revRate = ExchangeRate::from((string) $rev->rev_rate);
                    $origRate = ExchangeRate::from((string) $rev->orig_rate);
                    if (! $revRate->equals($origRate)) {
                        $violations[] = "Reversal batch [{$rev->rev_id}] exchange rate [{$rev->rev_rate}] does not match original batch [{$rev->orig_id}] rate [{$rev->orig_rate}].";
                    }
                } catch (\Throwable $e) {
                    $violations[] = "Reversal batch [{$rev->rev_id}] exchange rate [{$rev->rev_rate}] or original batch [{$rev->orig_id}] rate [{$rev->orig_rate}] is invalid.";
                }

                $origBatchLines = DB::table('posting_lines')->where('posting_batch_id', $rev->orig_id)->orderBy('line_number')->get();
                $revBatchLines = DB::table('posting_lines')->where('posting_batch_id', $rev->rev_id)->orderBy('line_number')->get();

                if ($origBatchLines->count() !== $revBatchLines->count()) {
                    $violations[] = "Reversal batch [{$rev->rev_id}] line count [{$revBatchLines->count()}] does not match original [{$origBatchLines->count()}].";

                    continue;
                }

                for ($i = 0; $i < $origBatchLines->count(); $i++) {
                    $ol = $origBatchLines[$i];
                    $rl = $revBatchLines[$i];

                    $rlDebit = BigDecimal::of((string) $rl->debit_base);
                    $rlCredit = BigDecimal::of((string) $rl->credit_base);
                    $olDebit = BigDecimal::of((string) $ol->debit_base);
                    $olCredit = BigDecimal::of((string) $ol->credit_base);

                    if ((int) $rl->ledger_account_id !== (int) $ol->ledger_account_id
                        || (int) $rl->line_number !== (int) $ol->line_number
                        || ! $rlDebit->isEqualTo($olCredit)
                        || ! $rlCredit->isEqualTo($olDebit)
                    ) {
                        $violations[] = "Reversal batch [{$rev->rev_id}] line [{$rl->line_number}] does not financially invert original line [{$ol->line_number}].";
                    }

                    if (($ol->transaction_currency_code === null) !== ($rl->transaction_currency_code === null)
                        || ($ol->transaction_currency_code !== null && $rl->transaction_currency_code !== $ol->transaction_currency_code)
                    ) {
                        $violations[] = "Reversal batch [{$rev->rev_id}] line [{$rl->line_number}] transaction currency code does not match original.";
                    }

                    if (($ol->transaction_amount === null) !== ($rl->transaction_amount === null)
                        || ($ol->transaction_amount !== null && ! BigDecimal::of((string) $rl->transaction_amount)->isEqualTo(BigDecimal::of((string) $ol->transaction_amount)))
                    ) {
                        $violations[] = "Reversal batch [{$rev->rev_id}] line [{$rl->line_number}] transaction amount does not match original.";
                    }

                    if (($ol->exchange_rate === null) !== ($rl->exchange_rate === null)) {
                        $violations[] = "Reversal batch [{$rev->rev_id}] line [{$rl->line_number}] exchange rate presence does not match original.";
                    } elseif ($ol->exchange_rate !== null) {
                        try {
                            $rlRate = ExchangeRate::from((string) $rl->exchange_rate);
                            $olRate = ExchangeRate::from((string) $ol->exchange_rate);
                            if (! $rlRate->equals($olRate)) {
                                $violations[] = "Reversal batch [{$rev->rev_id}] line [{$rl->line_number}] exchange rate does not match original.";
                            }
                        } catch (\Throwable $e) {
                            $violations[] = "Reversal batch [{$rev->rev_id}] line [{$rl->line_number}] exchange rate is invalid.";
                        }
                    }
                }
            }

            // 9. Duplicate Idempotency Key Check
            $duplicateKeys = DB::select('
                SELECT idempotency_key, COUNT(*) as cnt
                FROM posting_batches
                WHERE company_id = ?
                GROUP BY idempotency_key
                HAVING cnt > 1
            ', [$cid]);

            foreach ($duplicateKeys as $dup) {
                $violations[] = "Duplicate idempotency key [{$dup->idempotency_key}] found with {$dup->cnt} occurrences.";
            }

            // 10. Exchange Rate Integrity Check (read-only)
            $rateRows = DB::table('exchange_rates')->where('company_id', $cid)->get();
            $creatorIds = $rateRows->pluck('created_by')->filter()->unique()->all();
            $validCreatorIds = empty($creatorIds)
                ? []
                : DB::table('users')->whereIn('id', $creatorIds)->pluck('id')->all();

            foreach ($rateRows as $rateRow) {
                $rawBase = (string) $rateRow->base_currency_code;
                $rawCurr = (string) $rateRow->currency_code;

                if (! preg_match('/\A[A-Z]{3}\z/', $rawBase) || ! preg_match('/\A[A-Z]{3}\z/', $rawCurr)) {
                    $violations[] = "Invalid exchange rate [{$rateRow->id}]: currency code [{$rawBase}] or [{$rawCurr}] is malformed.";
                }

                if (! in_array($rawBase, ['ILS', 'USD', 'JOD'], true)) {
                    $violations[] = "Invalid exchange rate [{$rateRow->id}]: base currency [{$rawBase}] is outside supported historical currencies (ILS, USD, JOD).";
                }

                if ($rawBase === $rawCurr) {
                    $violations[] = "Invalid exchange rate [{$rateRow->id}]: base currency [{$rawBase}] cannot equal quote currency [{$rawCurr}].";
                }

                try {
                    $rateDec = BigDecimal::of((string) $rateRow->rate);
                    if ($rateDec->isNegative() || $rateDec->isZero()) {
                        $violations[] = "Invalid exchange rate [{$rateRow->id}]: rate [{$rateRow->rate}] must be strictly positive.";
                    }
                } catch (\Throwable) {
                    $violations[] = "Invalid exchange rate [{$rateRow->id}]: rate [{$rateRow->rate}] is malformed.";
                }

                if ($rateRow->created_by === null || ! in_array($rateRow->created_by, $validCreatorIds, true)) {
                    $violations[] = "Invalid exchange rate [{$rateRow->id}]: creator user [{$rateRow->created_by}] does not exist.";
                }
            }

            // Stats (strictly read-only)
            $batchCount = DB::table('posting_batches')->where('company_id', $cid)->count();
            $lineCount = DB::table('posting_lines')->where('company_id', $cid)->count();
            $accountCount = DB::table('ledger_accounts')->where('company_id', $cid)->count();

            return new ReconciliationReport(
                company: $persistedCompany,
                isHealthy: empty($violations),
                violations: $violations,
                stats: [
                    'batches_count' => $batchCount,
                    'lines_count' => $lineCount,
                    'accounts_count' => $accountCount,
                    'total_debit' => (string) $ledgerDebit,
                    'total_credit' => (string) $ledgerCredit,
                ]
            );
        };

        if ($isSystem) {
            return CompanyScope::executeWithoutScope($execute);
        }

        return $execute();
    }
}
