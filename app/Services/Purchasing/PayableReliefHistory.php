<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\VendorPaymentAllocation;
use Brick\Math\BigDecimal;

/** Read-only replay at the allocation's persisted financial chronology boundary. */
final class PayableReliefHistory
{
    /**
     * @return array{amount: BigDecimal, base: BigDecimal}
     */
    public function before(int $companyId, int $purchaseId, int $priorBatchId, int $allocationId): array
    {
        $amount = BigDecimal::zero();
        $base = BigDecimal::zero();

        $rows = VendorPaymentAllocation::query()
            ->join('vendor_payments', 'vendor_payments.id', '=', 'vendor_payment_allocations.vendor_payment_id')
            ->leftJoin('vendor_payment_application_events', 'vendor_payment_application_events.id', '=', 'vendor_payment_allocations.application_event_id')
            ->where('vendor_payment_allocations.company_id', $companyId)
            ->where('vendor_payment_allocations.purchase_id', $purchaseId)
            ->where('vendor_payment_allocations.id', '<', $allocationId)
            ->where('vendor_payments.posting_batch_id', '<=', $priorBatchId)
            ->where(function ($q) use ($priorBatchId): void {
                $q->whereNull('vendor_payments.reversal_posting_batch_id')
                    ->orWhere('vendor_payments.reversal_posting_batch_id', '>', $priorBatchId);
            })
            ->where(function ($q): void {
                $q->whereNull('vendor_payment_allocations.application_event_id')
                    ->orWhereNotNull('vendor_payment_application_events.applied_at');
            })
            ->where(function ($q) use ($priorBatchId): void {
                $q->whereNull('vendor_payment_application_events.reversal_posting_batch_id')
                    ->orWhere('vendor_payment_application_events.reversal_posting_batch_id', '>', $priorBatchId);
            })
            ->get(['vendor_payment_allocations.allocated_amount', 'vendor_payment_allocations.base_amount_applied_to_payable']);

        foreach ($rows as $row) {
            $amount = $amount->plus(BigDecimal::of((string) $row->allocated_amount));
            $base = $base->plus(BigDecimal::of((string) $row->base_amount_applied_to_payable));
        }

        $returns = PurchaseReturn::query()
            ->where('company_id', $companyId)
            ->where('purchase_id', $purchaseId)
            ->where('status', Purchase::STATUS_POSTED)
            ->whereNotNull('posting_batch_id')
            ->where('posting_batch_id', '<=', $priorBatchId)
            ->get();

        foreach ($returns as $return) {
            app(PurchaseReturnPostedIntegrityValidator::class)->validate($return);
            $amount = $amount->plus(BigDecimal::of((string) $return->grand_total_currency));
            $base = $base->plus(BigDecimal::of((string) $return->grand_total_base));
        }

        return ['amount' => $amount, 'base' => $base];
    }
}
