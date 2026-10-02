<?php

declare(strict_types=1);

namespace App\Domain\Sales\Queries;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentApplicationEvent;
use App\Models\LedgerAccount;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class CustomerCreditLimitQuery
{
    /** Credit limit is in company base currency; use actual book AR, including settlement FX/residual policy. Advisory only. */
    public function exceeds(Customer $customer, BigDecimal $additionalBase): bool
    {
        $companyId = app(CompanyContext::class)->companyId();
        if ($companyId !== (int) $customer->company_id) {
            throw new AuthorizationException;
        }
        if ($customer->credit_limit === null) {
            return false;
        }
        $batchIds = SalesInvoice::where('company_id', $companyId)->where('customer_id', $customer->id)->where('status', SalesInvoice::STATUS_POSTED)->pluck('posting_batch_id')
            ->merge(SalesReturn::where('company_id', $companyId)->where('customer_id', $customer->id)->where('status', SalesReturn::STATUS_POSTED)->pluck('posting_batch_id'))
            ->merge(CustomerPayment::where('company_id', $companyId)->where('customer_id', $customer->id)->where('is_reversed', false)->pluck('posting_batch_id'))->filter();
        $applications = CustomerPaymentApplicationEvent::where('company_id', $companyId)->whereNull('reversed_at')
            ->whereHas('customerPayment', fn ($q) => $q->where('customer_id', $customer->id)->where('is_reversed', false))->pluck('posting_batch_id');
        $batchIds = $batchIds->merge($applications)->filter();
        $accountId = LedgerAccount::where('company_id', $companyId)->where('system_key', 'accounts_receivable')->value('id');
        $bookBalance = DB::table('posting_lines')->where('company_id', $companyId)->where('ledger_account_id', $accountId)
            ->whereIn('posting_batch_id', $batchIds)->selectRaw('COALESCE(SUM(debit_base - credit_base), 0) AS balance')->value('balance');

        return BigDecimal::of((string) $bookBalance)->plus($additionalBase)->isGreaterThan($customer->credit_limit);
    }
}
