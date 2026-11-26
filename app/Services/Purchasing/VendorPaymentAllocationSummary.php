<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Exceptions\NoActiveCompanyException;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Ephemeral request-local batch allocation projection for VendorPayment list rows.
 * Canonical single-payment accessors live on VendorPayment.
 */
final class VendorPaymentAllocationSummary
{
    /**
     * @param  iterable<mixed>  $payments
     * @return array<int, array{allocated: string, unallocated: string}>
     */
    public function summarize(iterable $payments, ?int $expectedCompanyId = null): array
    {
        $items = [];
        foreach ($payments as $payment) {
            if (! $payment instanceof VendorPayment) {
                throw new InvalidArgumentException('Vendor payment allocation summary expects VendorPayment instances.');
            }
            $items[] = $payment;
        }

        if ($items === []) {
            return [];
        }

        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('An active company context is required to summarize vendor payments.');
        }

        $activeCompanyId = (int) $context->companyId();
        if ($expectedCompanyId !== null && $expectedCompanyId !== $activeCompanyId) {
            throw new InvalidArgumentException('Expected company context mismatch for vendor payment summary.');
        }

        $resolvedCompanyId = null;
        $activePaymentIds = [];
        foreach ($items as $payment) {
            $itemCompanyId = (int) $payment->company_id;
            if ($itemCompanyId <= 0) {
                throw new InvalidArgumentException('Vendor payment summary requires valid positive company_id on every payment.');
            }
            if ($resolvedCompanyId === null) {
                $resolvedCompanyId = $itemCompanyId;
            } elseif ($itemCompanyId !== $resolvedCompanyId) {
                throw new InvalidArgumentException('Vendor payment summary cannot mix payments from multiple companies.');
            }
            if ($itemCompanyId !== $activeCompanyId) {
                throw new InvalidArgumentException('Vendor payment summary does not match active company context.');
            }
            if (! $payment->is_reversed) {
                $activePaymentIds[(int) $payment->id] = (int) $payment->id;
            }
        }

        $activePaymentIds = array_values($activePaymentIds);

        /** @var array<int, string> $allocatedByPayment */
        $allocatedByPayment = [];
        if ($activePaymentIds !== []) {
            $rows = VendorPaymentAllocation::query()
                ->where('company_id', $resolvedCompanyId)
                ->whereIn('vendor_payment_id', $activePaymentIds)
                ->active()
                ->selectRaw('vendor_payment_id, SUM(payment_currency_amount) AS total_allocated')
                ->groupBy('vendor_payment_id')
                ->get();

            foreach ($rows as $row) {
                $total = $row->getAttribute('total_allocated');
                if ($total !== null) {
                    $allocatedByPayment[(int) $row->getAttribute('vendor_payment_id')] = (string) $total;
                }
            }
        }

        $summaries = [];
        foreach ($items as $payment) {
            $id = (int) $payment->id;
            if ($payment->is_reversed) {
                $summaries[$id] = [
                    'allocated' => '0.000000',
                    'unallocated' => '0.000000',
                ];

                continue;
            }

            $allocated = isset($allocatedByPayment[$id]) ? BigDecimal::of($allocatedByPayment[$id]) : BigDecimal::zero();
            $unallocated = BigDecimal::of((string) $payment->amount)->minus($allocated);

            $summaries[$id] = [
                'allocated' => (string) $allocated->toScale(6),
                'unallocated' => (string) $unallocated->toScale(6),
            ];
        }

        return $summaries;
    }
}
