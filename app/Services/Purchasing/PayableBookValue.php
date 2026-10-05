<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\VendorPaymentAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PayableBookValue
{
    public function relief(Purchase $purchase, BigDecimal $amount, ?int $excludingAllocationId = null): BigDecimal
    {
        $usedAmount = BigDecimal::zero();
        $usedBase = BigDecimal::zero();

        $allocations = VendorPaymentAllocation::where('company_id', $purchase->company_id)
            ->where('purchase_id', $purchase->id)
            ->active()
            ->lockForUpdate();

        if ($excludingAllocationId !== null) {
            $allocations->where('id', '!=', $excludingAllocationId);
        }

        foreach ($allocations->get() as $allocation) {
            $usedAmount = $usedAmount->plus(BigDecimal::of((string) $allocation->allocated_amount));
            $usedBase = $usedBase->plus(BigDecimal::of((string) $allocation->base_amount_applied_to_payable));
        }

        $returns = PurchaseReturn::where('company_id', $purchase->company_id)
            ->where('purchase_id', $purchase->id)
            ->where('status', Purchase::STATUS_POSTED)
            ->whereNotNull('posting_batch_id');

        foreach ($returns->lockForUpdate()->get() as $return) {
            app(PurchaseReturnPostedIntegrityValidator::class)->validate($return);
            $usedAmount = $usedAmount->plus(BigDecimal::of((string) $return->grand_total_currency));
            $usedBase = $usedBase->plus(BigDecimal::of((string) $return->grand_total_base));
        }

        $cumulative = $usedAmount->plus($amount);
        $target = $cumulative->isEqualTo(BigDecimal::of((string) $purchase->grand_total_currency))
            ? BigDecimal::of((string) $purchase->grand_total_base)
            : $cumulative->multipliedBy($purchase->exchange_rate)->toScale(6, RoundingMode::HALF_UP);

        return $target->minus($usedBase)->toScale(6);
    }
}
