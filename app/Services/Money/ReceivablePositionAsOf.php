<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\SalesInvoice;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Business-date view only; canonical posting still uses current book relief. */
final class ReceivablePositionAsOf
{
    public function outstanding(SalesInvoice $invoice, string $date): BigDecimal
    {
        return $this->forInvoices([$invoice], $date)[(int) $invoice->id];
    }

    /**
     * The same historical position calculation, batched for bounded read pages.
     * Caller retains lifecycle selection and read authorization responsibilities.
     *
     * @param  iterable<SalesInvoice>  $invoices
     * @return array<int, BigDecimal>
     */
    public function forInvoices(iterable $invoices, string $date): array
    {
        $invoices = collect($invoices);
        if ($invoices->isEmpty()) {
            return [];
        }
        if ($invoices->count() > 500) {
            throw new InvalidArgumentException('Historical receivables must be read in batches of at most 500 invoices.');
        }
        $companyId = (int) $invoices->first()->company_id;
        foreach ($invoices as $invoice) {
            if ((int) $invoice->company_id !== $companyId) {
                throw new InvalidArgumentException('Historical receivable batch must belong to one company.');
            }
        }
        $ids = $invoices->pluck('id')->all();
        $paid = DB::table('customer_payment_allocations as a')
            ->join('sales_invoices as i', 'i.id', '=', 'a.sales_invoice_id')
            ->join('customer_payments as p', 'p.id', '=', 'a.customer_payment_id')
            ->leftJoin('customer_payment_application_events as e', 'e.id', '=', 'a.application_event_id')
            ->leftJoin('posting_batches as pr', 'pr.id', '=', 'p.reversal_posting_batch_id')
            ->leftJoin('posting_batches as er', 'er.id', '=', 'e.reversal_posting_batch_id')
            ->where('a.company_id', $companyId)->where('p.company_id', $companyId)->where('i.company_id', $companyId)
            ->whereColumn('p.customer_id', 'i.customer_id')
            ->whereIn('a.sales_invoice_id', $ids)->where('p.payment_date', '<=', $date)->whereNotNull('p.posting_batch_id')
            ->where(fn ($q) => $q->where('p.is_reversed', false)->orWhere('pr.posting_date', '>', $date))
            ->where(fn ($q) => $q->whereNull('a.application_event_id')->orWhere(fn ($q) => $q->where('e.company_id', $companyId)
                ->whereColumn('e.customer_payment_id', 'p.id')->whereNotNull('e.applied_at')->where('e.application_date', '<=', $date)
                ->where(fn ($q) => $q->whereNull('e.reversed_at')->orWhere('er.posting_date', '>', $date)->orWhere(fn ($q) => $q->whereNull('e.posting_batch_id')->where('pr.posting_date', '>', $date)))))
            ->selectRaw('a.sales_invoice_id, SUM(a.allocated_amount) AS relief')
            ->groupBy('a.sales_invoice_id')->get()->keyBy('sales_invoice_id');
        $returned = DB::table('sales_returns as r')->leftJoin('posting_batches as inverse', 'inverse.id', '=', 'r.void_posting_batch_id')
            ->where('r.company_id', $companyId)->whereIn('r.sales_invoice_id', $ids)->whereNotNull('r.posting_batch_id')
            ->where('r.issue_date', '<=', $date)->where(fn ($q) => $q->where('r.status', 'posted')->orWhere('inverse.posting_date', '>', $date))
            ->selectRaw('r.sales_invoice_id, SUM(r.grand_total_currency) AS relief')
            ->groupBy('r.sales_invoice_id')->get()->keyBy('sales_invoice_id');
        $result = [];
        foreach ($invoices as $invoice) {
            $id = (int) $invoice->id;
            $position = BigDecimal::of($invoice->grand_total_currency)
                ->minus((string) ($paid->get($id)->relief ?? '0'))
                ->minus((string) ($returned->get($id)->relief ?? '0'));
            $result[$id] = $position->isPositive() ? $position : BigDecimal::zero();
        }

        return $result;
    }
}
