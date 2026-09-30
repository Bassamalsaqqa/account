<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Accounting\Catalog\SystemAccountsCatalog;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class AccountingReconciliationService
{
    /**
     * Audit and reconcile financial records for a company without performing mutations.
     */
    public function reconcile(Company $company, bool $isSystem = false): ReconciliationReport
    {
        $context = app(CompanyContext::class);

        if (! $isSystem) {
            if (! $context->hasCompany()) {
                throw new NoActiveCompanyException('Cannot reconcile financial records without an active company context.');
            }

            if ($context->companyId() !== $company->id) {
                throw new CompanyReassignmentException("Cannot reconcile financial records for company [{$company->id}] when active company is [{$context->companyId()}].");
            }
        }

        $execute = function () use ($company): ReconciliationReport {
            $violations = [];
            $cid = $company->id;

            // 1. Check System Accounts (must exist and be unique)
            $requiredKeys = array_map(fn ($def) => $def->systemKey, SystemAccountsCatalog::all());
            $accountsByKey = LedgerAccount::where('company_id', $cid)
                ->whereNotNull('system_key')
                ->select('system_key', DB::raw('count(*) as aggregate'))
                ->groupBy('system_key')
                ->pluck('aggregate', 'system_key')
                ->all();

            foreach ($requiredKeys as $key) {
                $count = $accountsByKey[$key] ?? 0;
                if ($count === 0) {
                    $violations[] = "Missing required system account with key [{$key}].";
                } elseif ($count > 1) {
                    $violations[] = "Duplicate system account key [{$key}] detected: {$count} accounts found.";
                }
            }

            // 2. Per-Batch Imbalance and Line Count Check (Empty or One-Line Batches)
            // Uses LEFT JOIN so batches with 0 lines are detected (line_count = 0)
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
                if ($row->total_debit != $row->total_credit) {
                    $violations[] = "Unbalanced batch [{$row->public_id}]: total debit ({$row->total_debit}) does not equal total credit ({$row->total_credit}).";
                }
            }

            // 3. Whole-Ledger Imbalance Check
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

            // 4. Bidirectional Cross-Company Linkages Check
            // 4a. Lines in company pointing to batches of another company
            $lineBatchMismatch = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM posting_lines l
                JOIN posting_batches b ON l.posting_batch_id = b.id
                WHERE l.company_id = ? AND b.company_id <> ?
            ', [$cid, $cid]);

            if (($lineBatchMismatch->cnt ?? 0) > 0) {
                $violations[] = "Cross-company batch leak: {$lineBatchMismatch->cnt} lines in company [{$cid}] point to batches of another company.";
            }

            // 4b. Batches in company containing lines belonging to another company
            $batchLineMismatch = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM posting_batches b
                JOIN posting_lines l ON b.id = l.posting_batch_id
                WHERE b.company_id = ? AND l.company_id <> ?
            ', [$cid, $cid]);

            if (($batchLineMismatch->cnt ?? 0) > 0) {
                $violations[] = "Cross-company batch leak: {$batchLineMismatch->cnt} lines of another company linked to batches in company [{$cid}].";
            }

            // 4c. Lines in company pointing to accounts of another company
            $lineAccountMismatch = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM posting_lines l
                JOIN ledger_accounts a ON l.ledger_account_id = a.id
                WHERE l.company_id = ? AND a.company_id <> ?
            ', [$cid, $cid]);

            if (($lineAccountMismatch->cnt ?? 0) > 0) {
                $violations[] = "Cross-company account leak: {$lineAccountMismatch->cnt} lines in company [{$cid}] point to accounts of another company.";
            }

            // 4d. Accounts in company containing lines belonging to another company
            $accountLineMismatch = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM ledger_accounts a
                JOIN posting_lines l ON a.id = l.ledger_account_id
                WHERE a.company_id = ? AND l.company_id <> ?
            ', [$cid, $cid]);

            if (($accountLineMismatch->cnt ?? 0) > 0) {
                $violations[] = "Cross-company account leak: {$accountLineMismatch->cnt} lines of another company linked to accounts in company [{$cid}].";
            }

            // 5. Invalid Line Amounts (negative, double positive, or double zero)
            $invalidLines = DB::selectOne('
                SELECT COUNT(*) as cnt
                FROM posting_lines
                WHERE company_id = ?
                  AND (
                      debit_base < 0
                      OR credit_base < 0
                      OR (debit_base > 0 AND credit_base > 0)
                      OR (debit_base = 0 AND credit_base = 0)
                  )
            ', [$cid]);

            if (($invalidLines->cnt ?? 0) > 0) {
                $violations[] = "Invalid posting lines: {$invalidLines->cnt} lines have negative, double-sided, or zero amounts.";
            }

            // 6. Comprehensive Reversal Link Incoherence Check
            // 6a. Batches marked 'reversed' must have reversed_by_batch_id pointing to a valid reciprocal reversal batch in the same company
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

            // 6b. Reversal batches (reversal_of_id IS NOT NULL) must point to an existing reversed batch in the same company that cross-links back
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

            // 6c. A reversal batch cannot itself be marked 'reversed'
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

            // 7. Duplicate Idempotency Key Check
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

            // Stats (purely read-only)
            $batchCount = DB::table('posting_batches')->where('company_id', $cid)->count();
            $lineCount = DB::table('posting_lines')->where('company_id', $cid)->count();
            $accountCount = DB::table('ledger_accounts')->where('company_id', $cid)->count();

            return new ReconciliationReport(
                company: $company,
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

        if ($isSystem && ! $context->hasCompany()) {
            return CompanyScope::executeWithoutScope($execute);
        }

        return $execute();
    }
}
