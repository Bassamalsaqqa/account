<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\OpeningTradeItem;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class OpeningTradeCutoverAuditService
{
    /**
     * Read-only cutover audit reporting all control source history and legacy GL overlaps.
     *
     * @return array<string, mixed>
     */
    public function audit(Company|int $company, ?User $actor = null): array
    {
        if (is_int($company)) {
            $company = Company::findOrFail($company);
        }

        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('Cutover audit requires an active company context.');
        }

        if ((int) $context->companyId() !== (int) $company->id) {
            throw new CompanyReassignmentException("Company mismatch in cutover audit: expected [{$company->id}], got [{$context->companyId()}].");
        }

        $actor ??= auth()->user();
        if ($actor === null || (auth()->check() && (int) auth()->id() !== (int) $actor->id)
            || ! $actor->belongsToCompany($company->id)
            || (! $actor->hasPermissionTo('settings.accounting.manage') && ! $actor->hasPermissionTo('accounting.reports.view'))
            || ! $actor->hasPermissionTo('purchasing.cost.view')) {
            throw new AuthorizationException('Cutover audit requires accounting and supplier financial read authority.');
        }

        $cid = (int) $company->id;
        $companyBase = strtoupper((string) $company->base_currency_code);

        // Control Accounts
        $arAccount = LedgerAccount::where('company_id', $cid)->where('system_key', 'accounts_receivable')->first();
        $apAccount = LedgerAccount::where('company_id', $cid)->where('system_key', 'accounts_payable')->first();
        $obeAccount = LedgerAccount::where('company_id', $cid)->where('system_key', 'opening_balance_equity')->first();

        // 1. Audit Accounts Receivable
        $arAudit = $this->auditControlAccount($cid, $arAccount, 'customer');

        // 2. Audit Accounts Payable
        $apAudit = $this->auditControlAccount($cid, $apAccount, 'vendor');

        // 3. Opening Balance Equity
        $obeAudit = $this->auditObeAccount($cid, $obeAccount);

        $hasAmbiguousRows = $arAudit['has_legacy_generic_opening'] || $apAudit['has_legacy_generic_opening'];

        return [
            'company_id' => $cid,
            'company_public_id' => $company->public_id,
            'base_currency' => $companyBase,
            'status' => $hasAmbiguousRows ? 'AMBIGUOUS_ROWS_HELD_FOR_APPROVAL' : 'CLEAN',
            'customer_items_count' => $arAudit['trade_items_count'],
            'customer_items_total_base' => $arAudit['trade_items_total_base'],
            'customer_items_outstanding_base' => $arAudit['trade_items_outstanding_base'],
            'vendor_items_count' => $apAudit['trade_items_count'],
            'vendor_items_total_base' => $apAudit['trade_items_total_base'],
            'vendor_items_outstanding_base' => $apAudit['trade_items_outstanding_base'],
            'has_legacy_gl_overlap' => $hasAmbiguousRows,
            'accounts_receivable' => $arAudit,
            'accounts_payable' => $apAudit,
            'opening_balance_equity' => $obeAudit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditControlAccount(int $companyId, ?LedgerAccount $account, string $side): array
    {
        if ($account === null) {
            return [
                'configured' => false,
                'ledger_account_id' => null,
                'gl_balance_base' => '0.000000',
                'sources' => [],
                'has_legacy_generic_opening' => false,
                'legacy_batches' => [],
                'opening_trade_items' => [],
                'trade_items_count' => 0,
                'trade_items_total_base' => '0.000000',
                'trade_items_outstanding_base' => '0.000000',
            ];
        }

        $lines = DB::table('posting_lines as l')
            ->join('posting_batches as b', 'b.id', '=', 'l.posting_batch_id')
            ->where('l.company_id', $companyId)
            ->where('l.ledger_account_id', $account->id)
            ->where('b.status', 'posted')
            ->select([
                'b.id as batch_id',
                'b.public_id as batch_public_id',
                'b.source_type',
                'b.source_id',
                'b.posting_date',
                'b.description as batch_description',
                'l.id as line_id',
                'l.description as line_description',
                'l.debit_base',
                'l.credit_base',
                'l.transaction_amount',
                'l.transaction_currency_code',
            ])
            ->orderBy('b.posting_date')
            ->orderBy('b.id')
            ->get();

        $sourceTotals = [];
        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();
        $legacyBatches = [];

        foreach ($lines as $line) {
            $type = (string) $line->source_type;
            $debit = BigDecimal::of((string) $line->debit_base);
            $credit = BigDecimal::of((string) $line->credit_base);

            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);

            if (! isset($sourceTotals[$type])) {
                $sourceTotals[$type] = ['debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero(), 'count' => 0];
            }
            $sourceTotals[$type]['debit'] = $sourceTotals[$type]['debit']->plus($debit);
            $sourceTotals[$type]['credit'] = $sourceTotals[$type]['credit']->plus($credit);
            $sourceTotals[$type]['count']++;

            if ($type === 'opening_balance') {
                $legacyBatches[] = [
                    'batch_id' => (int) $line->batch_id,
                    'batch_public_id' => (string) $line->batch_public_id,
                    'posting_date' => (string) $line->posting_date,
                    'description' => (string) ($line->line_description ?? $line->batch_description),
                    'debit_base' => (string) $debit->toScale(6),
                    'credit_base' => (string) $credit->toScale(6),
                ];
            }
        }

        $formattedSources = [];
        foreach ($sourceTotals as $type => $st) {
            $formattedSources[$type] = [
                'count' => $st['count'],
                'total_debit_base' => (string) $st['debit']->toScale(6),
                'total_credit_base' => (string) $st['credit']->toScale(6),
            ];
        }

        // Subledger opening items
        $tradeItems = OpeningTradeItem::where('company_id', $companyId)
            ->where('side', $side)
            ->orderBy('opening_date')
            ->orderBy('id')
            ->get();

        $tradeTotalBase = BigDecimal::zero();
        $tradeOutstandingBase = BigDecimal::zero();
        $itemsList = [];

        foreach ($tradeItems as $item) {
            $baseCarrying = BigDecimal::of((string) $item->carrying_value_base);
            if ($item->isPosted()) {
                $tradeTotalBase = $tradeTotalBase->plus($baseCarrying);
                $outstandingCurr = $item->calculateOutstanding();
                $allocations = $item->isCustomerSide() ? $item->customerPaymentAllocations()->active()->get() : $item->vendorPaymentAllocations()->active()->get();
                $outstandingBase = $baseCarrying;
                foreach ($allocations as $allocation) {
                    $outstandingBase = $outstandingBase->minus($allocation->getAttribute($item->isCustomerSide() ? 'base_amount_applied_to_receivable' : 'base_amount_applied_to_payable'));
                }
                $tradeOutstandingBase = $tradeOutstandingBase->plus($outstandingBase);
            }

            $partyName = (string) ($item->party_snapshot['name_ar'] ?? $item->party_snapshot['name_en'] ?? '');

            $itemsList[] = [
                'id' => (int) $item->id,
                'public_id' => (string) $item->public_id,
                'party_id' => (int) ($item->customer_id ?? $item->vendor_id),
                'party_name' => $partyName,
                'opening_date' => $item->opening_date->toDateString(),
                'original_reference' => $item->original_reference,
                'due_date' => $item->due_date?->toDateString(),
                'amount_currency' => (string) $item->amount_currency,
                'currency_code' => (string) $item->currency_code,
                'exchange_rate' => (string) $item->exchange_rate,
                'carrying_value_base' => (string) $item->carrying_value_base,
                'status' => (string) $item->status,
                'outstanding_currency' => (string) $item->calculateOutstanding()->toScale(6),
            ];
        }

        // Net balance: for Asset (AR) debit - credit, for Liability (AP) credit - debit
        $netGlBalance = $side === 'customer'
            ? $totalDebit->minus($totalCredit)
            : $totalCredit->minus($totalDebit);

        return [
            'configured' => true,
            'ledger_account_id' => (int) $account->id,
            'gl_balance_base' => (string) $netGlBalance->toScale(6),
            'sources' => $formattedSources,
            'has_legacy_generic_opening' => ! empty($legacyBatches),
            'legacy_batches' => $legacyBatches,
            'trade_items_count' => $tradeItems->count(),
            'trade_items_total_base' => (string) $tradeTotalBase->toScale(6),
            'trade_items_outstanding_base' => (string) $tradeOutstandingBase->toScale(6),
            'opening_trade_items' => $itemsList,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditObeAccount(int $companyId, ?LedgerAccount $account): array
    {
        if ($account === null) {
            return ['configured' => false, 'balance_base' => '0.000000'];
        }

        $totals = DB::table('posting_lines as l')
            ->join('posting_batches as b', 'b.id', '=', 'l.posting_batch_id')
            ->where('l.company_id', $companyId)
            ->where('l.ledger_account_id', $account->id)
            ->where('b.status', 'posted')
            ->selectRaw('COALESCE(SUM(l.debit_base), 0) as total_debit, COALESCE(SUM(l.credit_base), 0) as total_credit')
            ->first();

        $debit = BigDecimal::of((string) ($totals->total_debit ?? '0'));
        $credit = BigDecimal::of((string) ($totals->total_credit ?? '0'));
        $balance = $credit->minus($debit); // Equity is credit normal

        return [
            'configured' => true,
            'ledger_account_id' => (int) $account->id,
            'credit_balance_base' => (string) $balance->toScale(6),
        ];
    }
}
