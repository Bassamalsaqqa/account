<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\SalesInvoice;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Business-date view only; canonical posting still uses current book relief. */
final class ReceivablePositionAsOf
{
    public function outstanding(SalesInvoice $invoice, string $date): BigDecimal
    {
        $paid = DB::table('customer_payment_allocations as a')->join('customer_payments as p', 'p.id', '=', 'a.customer_payment_id')
            ->leftJoin('customer_payment_application_events as e', 'e.id', '=', 'a.application_event_id')
            ->leftJoin('posting_batches as pr', 'pr.id', '=', 'p.reversal_posting_batch_id')
            ->leftJoin('posting_batches as er', 'er.id', '=', 'e.reversal_posting_batch_id')
            ->where('a.company_id', $invoice->company_id)->where('p.company_id', $invoice->company_id)->where('p.customer_id', $invoice->customer_id)
            ->where('a.sales_invoice_id', $invoice->id)->where('p.payment_date', '<=', $date)->whereNotNull('p.posting_batch_id')
            ->where(fn ($q) => $q->where('p.is_reversed', false)->orWhere('pr.posting_date', '>', $date))
            ->where(fn ($q) => $q->whereNull('a.application_event_id')->orWhere(fn ($q) => $q->where('e.company_id', $invoice->company_id)
                ->whereColumn('e.customer_payment_id', 'p.id')->whereNotNull('e.applied_at')->where('e.application_date', '<=', $date)
                ->where(fn ($q) => $q->whereNull('e.reversed_at')->orWhere('er.posting_date', '>', $date)->orWhere(fn ($q) => $q->whereNull('e.posting_batch_id')->where('pr.posting_date', '>', $date)))))
            ->sum('a.allocated_amount');
        $returned = DB::table('sales_returns as r')->leftJoin('posting_batches as inverse', 'inverse.id', '=', 'r.void_posting_batch_id')
            ->where('r.company_id', $invoice->company_id)->where('r.sales_invoice_id', $invoice->id)->whereNotNull('r.posting_batch_id')
            ->where('r.issue_date', '<=', $date)->where(fn ($q) => $q->where('r.status', 'posted')->orWhere('inverse.posting_date', '>', $date))->sum('r.grand_total_currency');
        $position = BigDecimal::of($invoice->grand_total_currency)->minus((string) $paid)->minus((string) $returned);

        return $position->isPositive() ? $position : BigDecimal::zero();
    }
}
