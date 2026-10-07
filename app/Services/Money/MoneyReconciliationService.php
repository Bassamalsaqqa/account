<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentApplicationEvent;
use App\Models\MoneyAccount;
use App\Models\MoneyTransfer;
use App\Models\PostingBatch;
use App\Services\Accounting\ReconciliationReport;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MoneyReconciliationService
{
    public function reconcile(Company $company, bool $isSystem = false): ReconciliationReport
    {
        $context = app(CompanyContext::class);
        if ($isSystem ? $context->hasCompany() : (! $context->hasCompany() || $context->companyId() !== (int) $company->id)) {
            throw new InvalidArgumentException('Money reconciliation requires matching context, or explicit system mode without ambient context.');
        }
        $audit = function () use ($company): ReconciliationReport {
            $violations = [];
            $accounts = MoneyAccount::withTrashed()->where('company_id', $company->id)->get();
            foreach ($accounts as $account) {
                try {
                    app(MoneyAccountLedger::class)->validate($account);
                } catch (\Throwable $e) {
                    $violations[] = "Money account [{$account->id}]: {$e->getMessage()}";
                }
            }
            $duplicates = $accounts->groupBy('ledger_account_id')->filter(fn ($group) => $group->count() > 1);
            foreach ($duplicates as $ledgerId => $group) {
                $violations[] = "Ledger [{$ledgerId}] belongs to multiple MoneyAccounts.";
            }
            $mismatches = DB::table('posting_lines as l')->join('money_accounts as m', 'm.ledger_account_id', '=', 'l.ledger_account_id')
                ->join('posting_batches as b', 'b.id', '=', 'l.posting_batch_id')
                ->where('m.company_id', $company->id)
                ->where(function ($q) use ($company) {
                    $q->where('l.company_id', '!=', $company->id)->orWhere('b.company_id', '!=', $company->id)
                        ->orWhere(function ($q) {
                            $q->whereNotNull('l.transaction_currency_code')->whereColumn('l.transaction_currency_code', '!=', 'm.currency_code');
                        });
                })->pluck('l.id');
            foreach ($mismatches as $lineId) {
                $violations[] = "Money posting line [{$lineId}] has foreign company/currency provenance.";
            }
            // Historical base-only openings are legitimate; foreign native balances are unavailable, not invented.
            $unknown = DB::table('posting_lines as l')->join('money_accounts as m', 'm.ledger_account_id', '=', 'l.ledger_account_id')
                ->where('m.company_id', $company->id)->whereNull('l.transaction_currency_code')->count();
            foreach (MoneyTransfer::where('company_id', $company->id)->cursor() as $transfer) {
                try {
                    app(MoneyTransferHistory::class)->validate($transfer);
                } catch (\Throwable $e) {
                    $violations[] = "Transfer [{$transfer->id}]: {$e->getMessage()}";
                }
            }
            foreach (Check::where('company_id', $company->id)->cursor() as $check) {
                try {
                    app(CheckHistory::class)->validate($check);
                } catch (\Throwable $e) {
                    $violations[] = "Check [{$check->id}]: {$e->getMessage()}";
                }
            }
            foreach (CustomerPayment::where('company_id', $company->id)->cursor() as $payment) {
                try {
                    app(CustomerPaymentHistory::class)->validate($payment);
                } catch (\Throwable $e) {
                    $violations[] = "Receipt [{$payment->id}]: {$e->getMessage()}";
                }
            }
            foreach (CustomerPaymentApplicationEvent::where('company_id', $company->id)->cursor() as $event) {
                try {
                    app(CustomerApplicationHistory::class)->validate($event);
                } catch (\Throwable $e) {
                    $violations[] = "Receipt application [{$event->id}]: {$e->getMessage()}";
                }
            }
            foreach (['customer_payment' => CustomerPayment::class, 'customer_payment_application' => CustomerPaymentApplicationEvent::class] as $type => $model) {
                foreach (PostingBatch::where('company_id', $company->id)->where('source_type', $type)->cursor() as $batch) {
                    $source = $model::where('company_id', $company->id)->find($batch->source_id);
                    if ($source === null || (int) $source->posting_batch_id !== (int) $batch->id || $batch->reversal_of_id !== null) {
                        $violations[] = "Receipt batch [{$batch->id}] has orphan/duplicate canonical source ownership.";
                    }
                }
            }
            foreach (PostingBatch::where('company_id', $company->id)->where('source_type', 'check_event')->cursor() as $batch) {
                $source = CheckEvent::where('company_id', $company->id)->find($batch->source_id);
                if ($source === null || (int) $source->posting_batch_id !== (int) $batch->id || $source->event_type !== 'clear' || $batch->reversal_of_id !== null) {
                    $violations[] = "Check batch [{$batch->id}] has orphan/duplicate canonical source ownership.";
                }
            }
            // Reverse ownership check: every original canonical source batch must be owned by its exact source.
            foreach (PostingBatch::where('company_id', $company->id)->where('source_type', 'money_transfer')->cursor() as $batch) {
                $source = MoneyTransfer::where('company_id', $company->id)->find($batch->source_id);
                if ($source === null || (int) $source->posting_batch_id !== (int) $batch->id || $batch->reversal_of_id !== null) {
                    $violations[] = "Transfer batch [{$batch->id}] has orphan/duplicate canonical source ownership.";
                }
            }

            return new ReconciliationReport($company, $violations === [], $violations, [
                'accounts_count' => $accounts->count(), 'base_only_historical_lines' => $unknown,
            ]);
        };

        return $isSystem ? CompanyScope::executeWithoutScope($audit) : $audit();
    }
}
