<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\VendorPaymentAllocation;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use InvalidArgumentException;

/** Read-only business-date positions. This does not authorize new allocations. */
final class PurchasePayableAsOf
{
    /**
     * @param  iterable<Purchase>  $purchases
     * @return array<int, BigDecimal> Positive outstanding at company-local end of day.
     */
    public function forPurchases(iterable $purchases, Carbon $asOf): array
    {
        $purchases = collect($purchases);
        if ($purchases->isEmpty()) {
            return [];
        }

        $companyId = (int) $purchases->first()->company_id;
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== $companyId) {
            throw new InvalidArgumentException('Historical payable positions require matching company context.');
        }
        $date = $asOf->toDateString();
        // Reversal batches persist the Company-local business date at execution.
        // Do not reinterpret historical reversal instants in today's timezone.
        $result = [];
        foreach ($purchases as $purchase) {
            if ((int) $purchase->company_id !== $companyId || ! $purchase->isPosted()
                || $purchase->purchase_date->format('Y-m-d') > $date) {
                throw new InvalidArgumentException('Purchase is outside the historical company/date boundary.');
            }
            app(PurchasePostingCommandBuilder::class)->validatePosted($purchase);
            $result[(int) $purchase->id] = BigDecimal::of($purchase->grand_total_currency);
        }
        $ids = array_keys($result);
        $returns = PurchaseReturn::where('company_id', $companyId)->whereIn('purchase_id', $ids)
            ->where('status', Purchase::STATUS_POSTED)->where('return_date', '<=', $date)->get();
        foreach ($returns as $return) {
            app(PurchaseReturnPostingCommandBuilder::class)->validatePosted($return);
            $id = (int) $return->purchase_id;
            $result[$id] = $result[$id]->minus($return->grand_total_currency);
        }

        $allocations = VendorPaymentAllocation::query()
            ->join('vendor_payments as payment', 'payment.id', '=', 'vendor_payment_allocations.vendor_payment_id')
            ->leftJoin('posting_batches as payment_reversal', function ($join) use ($companyId): void {
                $join->on('payment_reversal.id', '=', 'payment.reversal_posting_batch_id')->where('payment_reversal.company_id', $companyId);
            })
            ->leftJoin('vendor_payment_application_events as event', 'event.id', '=', 'vendor_payment_allocations.application_event_id')
            ->where('vendor_payment_allocations.company_id', $companyId)
            ->where('payment.company_id', $companyId)
            ->whereIn('vendor_payment_allocations.purchase_id', $ids)
            ->whereNotNull('payment.posted_at')->whereNotNull('payment.posting_batch_id')
            ->where('payment.payment_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('payment.reversed_at')->orWhere('payment_reversal.posting_date', '>', $date))
            ->where(function ($q) use ($companyId, $date): void {
                $q->whereNull('vendor_payment_allocations.application_event_id')
                    ->orWhere(function ($event) use ($companyId, $date): void {
                        $event->where('event.company_id', $companyId)
                            ->whereColumn('event.vendor_payment_id', 'payment.id')
                            ->whereNotNull('event.applied_at')->where('event.application_date', '<=', $date)
                            ->where(fn ($rev) => $rev->whereNull('event.reversed_at')->orWhere('payment_reversal.posting_date', '>', $date));
                    });
            })
            ->selectRaw('vendor_payment_allocations.purchase_id, SUM(vendor_payment_allocations.allocated_amount) as relief')
            ->groupBy('vendor_payment_allocations.purchase_id')->get();
        foreach ($allocations as $row) {
            $id = (int) $row->purchase_id;
            $result[$id] = $result[$id]->minus((string) $row->getAttribute('relief'));
        }
        foreach ($result as $id => $position) {
            $result[$id] = ($position->isPositive() ? $position : BigDecimal::zero())->toScale(6);
        }

        return $result;
    }
}
