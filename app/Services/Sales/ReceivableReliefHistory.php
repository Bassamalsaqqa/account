<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Read-only replay at the allocation's persisted financial chronology boundary. */
final class ReceivableReliefHistory
{
    /** @return array{amount: BigDecimal, base: BigDecimal} */
    public function before(int $companyId, int $invoiceId, int $priorBatchId, int $allocationId): array
    {
        $amount = BigDecimal::zero();
        $base = BigDecimal::zero();
        $rows = DB::table('customer_payment_allocations as a')->join('customer_payments as p', 'p.id', '=', 'a.customer_payment_id')
            ->leftJoin('customer_payment_application_events as e', 'e.id', '=', 'a.application_event_id')
            ->where('a.company_id', $companyId)->where('a.sales_invoice_id', $invoiceId)->where('a.id', '<', $allocationId)
            ->where('p.posting_batch_id', '<=', $priorBatchId)
            ->where(fn ($q) => $q->whereNull('p.reversal_posting_batch_id')->orWhere('p.reversal_posting_batch_id', '>', $priorBatchId))
            ->where(fn ($q) => $q->whereNull('a.application_event_id')->orWhereNotNull('e.applied_at'))
            ->where(fn ($q) => $q->whereNull('e.reversal_posting_batch_id')->orWhere('e.reversal_posting_batch_id', '>', $priorBatchId))
            ->get(['a.allocated_amount', 'a.base_amount_applied_to_receivable']);
        foreach ($rows as $row) {
            $amount = $amount->plus($row->allocated_amount);
            $base = $base->plus($row->base_amount_applied_to_receivable);
        }
        foreach (DB::table('sales_returns')->where('company_id', $companyId)->where('sales_invoice_id', $invoiceId)
            ->whereNotNull('posting_batch_id')->where('posting_batch_id', '<=', $priorBatchId)
            ->where(fn ($q) => $q->whereNull('void_posting_batch_id')->orWhere('void_posting_batch_id', '>', $priorBatchId))->get() as $return) {
            $amount = $amount->plus($return->grand_total_currency);
            $base = $base->plus($return->grand_total_base);
        }

        return ['amount' => $amount, 'base' => $base];
    }
}
