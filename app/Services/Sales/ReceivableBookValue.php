<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\CustomerPaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ReceivableBookValue
{
    public function relief(SalesInvoice $invoice, BigDecimal $amount, ?int $excludingReturnId = null): BigDecimal
    {
        $usedAmount = BigDecimal::zero();
        $usedBase = BigDecimal::zero();
        $allocations = CustomerPaymentAllocation::where('company_id', $invoice->company_id)->where('sales_invoice_id', $invoice->id)
            ->active()->lockForUpdate();
        foreach ($allocations->get() as $allocation) {
            $usedAmount = $usedAmount->plus($allocation->allocated_amount);
            $usedBase = $usedBase->plus($allocation->base_amount_applied_to_receivable);
        }
        $returns = SalesReturn::where('company_id', $invoice->company_id)->where('sales_invoice_id', $invoice->id)->where('status', SalesReturn::STATUS_POSTED);
        if ($excludingReturnId !== null) {
            $returns->where('id', '!=', $excludingReturnId);
        }
        foreach ($returns->lockForUpdate()->get() as $return) {
            $usedAmount = $usedAmount->plus($return->grand_total_currency);
            $usedBase = $usedBase->plus($return->grand_total_base);
        }
        $cumulative = $usedAmount->plus($amount);
        $target = $cumulative->isEqualTo($invoice->grand_total_currency) ? BigDecimal::of($invoice->grand_total_base)
            : $cumulative->multipliedBy($invoice->exchange_rate)->toScale(6, RoundingMode::HALF_UP);

        return $target->minus($usedBase)->toScale(6);
    }
}
