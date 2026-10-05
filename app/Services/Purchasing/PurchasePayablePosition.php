<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\VendorPaymentAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

final class PurchasePayablePosition
{
    public const string STATUS_UNPAID = 'unpaid';

    public const string STATUS_PARTIALLY_PAID = 'partially_paid';

    public const string STATUS_SETTLED = 'settled';

    public const string STATUS_CREDIT = 'credit';

    public function __construct(
        public readonly Purchase $purchase,
        public readonly BigDecimal $rawPosition,
        public readonly BigDecimal $outstanding,
        public readonly BigDecimal $credit,
        public readonly BigDecimal $basePosition,
        public readonly BigDecimal $activeAllocatedAmount,
        public readonly BigDecimal $postedReturnedAmount,
        public readonly string $status,
    ) {}

    public static function forPurchase(Purchase $purchase): self
    {
        $batch = self::forPurchases([$purchase]);

        return $batch[(int) $purchase->id] ?? self::empty($purchase);
    }

    /**
     * @param  iterable<Purchase>  $purchases
     * @return array<int, self>
     */
    public static function forPurchases(iterable $purchases): array
    {
        $collection = $purchases instanceof Collection ? $purchases : collect($purchases);
        if ($collection->isEmpty()) {
            return [];
        }

        $purchaseIds = $collection->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        if (empty($purchaseIds)) {
            return [];
        }

        $companyId = (int) $collection->first()->company_id;

        // Query active payment allocations per purchase
        $allocationRows = VendorPaymentAllocation::query()
            ->join('vendor_payments', 'vendor_payments.id', '=', 'vendor_payment_allocations.vendor_payment_id')
            ->leftJoin('vendor_payment_application_events', 'vendor_payment_application_events.id', '=', 'vendor_payment_allocations.application_event_id')
            ->where('vendor_payment_allocations.company_id', $companyId)
            ->whereIn('vendor_payment_allocations.purchase_id', $purchaseIds)
            ->where('vendor_payments.is_reversed', false)
            ->where(function ($q): void {
                $q->whereNull('vendor_payment_allocations.application_event_id')
                    ->orWhere(function ($eq): void {
                        $eq->whereNotNull('vendor_payment_application_events.applied_at')
                            ->whereNull('vendor_payment_application_events.reversed_at');
                    });
            })
            ->selectRaw('vendor_payment_allocations.purchase_id, SUM(vendor_payment_allocations.allocated_amount) as total_allocated, SUM(vendor_payment_allocations.base_amount_applied_to_payable) as total_base_applied')
            ->groupBy('vendor_payment_allocations.purchase_id')
            ->get()
            ->keyBy('purchase_id');

        // Query posted returns per purchase
        $returnRows = PurchaseReturn::query()
            ->where('company_id', $companyId)
            ->whereIn('purchase_id', $purchaseIds)
            ->where('status', Purchase::STATUS_POSTED)
            ->whereNotNull('posting_batch_id')
            ->selectRaw('purchase_id, SUM(grand_total_currency) as total_return_currency, SUM(grand_total_base) as total_return_base')
            ->groupBy('purchase_id')
            ->get()
            ->keyBy('purchase_id');

        $result = [];
        foreach ($collection as $purchase) {
            $id = (int) $purchase->id;

            if ($purchase->status !== Purchase::STATUS_POSTED) {
                $result[$id] = self::empty($purchase);

                continue;
            }

            $allocRow = $allocationRows->get($id);
            $retRow = $returnRows->get($id);

            $allocAmount = $allocRow !== null ? BigDecimal::of((string) ($allocRow->getAttribute('total_allocated') ?? '0')) : BigDecimal::zero();
            $allocBase = $allocRow !== null ? BigDecimal::of((string) ($allocRow->getAttribute('total_base_applied') ?? '0')) : BigDecimal::zero();

            $retAmount = $retRow !== null ? BigDecimal::of((string) ($retRow->getAttribute('total_return_currency') ?? '0')) : BigDecimal::zero();
            $retBase = $retRow !== null ? BigDecimal::of((string) ($retRow->getAttribute('total_return_base') ?? '0')) : BigDecimal::zero();

            $rawCurrency = (string) $purchase->getAttribute('grand_total_currency');
            if ($rawCurrency === '' && $purchase->exists) {
                $fresh = $purchase->fresh();
                $rawCurrency = $fresh !== null ? (string) $fresh->grand_total_currency : '';
            }
            $grandTotalCurrency = $rawCurrency !== '' ? BigDecimal::of($rawCurrency) : BigDecimal::zero();

            $rawBase = (string) $purchase->getAttribute('grand_total_base');
            if ($rawBase === '' && $purchase->exists) {
                $fresh = $purchase->fresh();
                $rawBase = $fresh !== null ? (string) $fresh->grand_total_base : '';
            }
            $grandTotalBase = $rawBase !== '' ? BigDecimal::of($rawBase) : BigDecimal::zero();

            $rawPosition = $grandTotalCurrency->minus($retAmount)->minus($allocAmount)->toScale(6, RoundingMode::HALF_UP);
            $basePosition = $grandTotalBase->minus($retBase)->minus($allocBase)->toScale(6, RoundingMode::HALF_UP);

            $outstanding = $rawPosition->isPositive() ? $rawPosition : BigDecimal::zero()->toScale(6);
            $credit = $rawPosition->isNegative() ? $rawPosition->negated()->toScale(6) : BigDecimal::zero()->toScale(6);

            $status = self::deriveStatus($rawPosition, $outstanding, $credit, $allocAmount);

            $result[$id] = new self(
                purchase: $purchase,
                rawPosition: $rawPosition,
                outstanding: $outstanding,
                credit: $credit,
                basePosition: $basePosition,
                activeAllocatedAmount: $allocAmount->toScale(6),
                postedReturnedAmount: $retAmount->toScale(6),
                status: $status,
            );
        }

        return $result;
    }

    private static function deriveStatus(
        BigDecimal $rawPosition,
        BigDecimal $outstanding,
        BigDecimal $credit,
        BigDecimal $activeAllocatedAmount
    ): string {
        if ($rawPosition->isNegative()) {
            return self::STATUS_CREDIT;
        }

        if ($outstanding->isZero() && $credit->isZero()) {
            return self::STATUS_SETTLED;
        }

        if ($outstanding->isPositive() && $activeAllocatedAmount->isPositive()) {
            return self::STATUS_PARTIALLY_PAID;
        }

        return self::STATUS_UNPAID;
    }

    private static function empty(Purchase $purchase): self
    {
        $zero = BigDecimal::zero()->toScale(6);

        return new self(
            purchase: $purchase,
            rawPosition: $zero,
            outstanding: $zero,
            credit: $zero,
            basePosition: $zero,
            activeAllocatedAmount: $zero,
            postedReturnedAmount: $zero,
            status: $purchase->status === Purchase::STATUS_DRAFT ? Purchase::STATUS_DRAFT : self::STATUS_UNPAID,
        );
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SETTLED;
    }

    public function isCredit(): bool
    {
        return $this->status === self::STATUS_CREDIT;
    }

    public function isPartiallyPaid(): bool
    {
        return $this->status === self::STATUS_PARTIALLY_PAID;
    }

    public function hasOutstanding(): bool
    {
        return $this->outstanding->isPositive();
    }

    public function hasCredit(): bool
    {
        return $this->credit->isPositive();
    }

    public function isUnpaid(): bool
    {
        return $this->status === self::STATUS_UNPAID;
    }
}
