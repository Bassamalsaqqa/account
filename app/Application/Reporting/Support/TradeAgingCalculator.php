<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Services\Money\ReceivablePositionAsOf;
use App\Services\Purchasing\PurchasePayableAsOf;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class TradeAgingCalculator
{
    /**
     * Compute receivable aging for an invoice collection as of a cutoff date.
     *
     * @param  Collection<int, SalesInvoice>  $invoices
     * @return array{
     *     unspecified: BigDecimal,
     *     current: BigDecimal,
     *     days_1_30: BigDecimal,
     *     days_31_60: BigDecimal,
     *     days_61_90: BigDecimal,
     *     days_90_plus: BigDecimal,
     *     total: BigDecimal
     * }
     */
    public static function calculateInvoiceAging(Collection $invoices, string $cutoffDate, string $timezone): array
    {
        $cutoff = Carbon::parse($cutoffDate, $timezone)->startOfDay();
        $receivablePosition = app(ReceivablePositionAsOf::class);

        $unspecified = BigDecimal::zero();
        $current = BigDecimal::zero();
        $days1_30 = BigDecimal::zero();
        $days31_60 = BigDecimal::zero();
        $days61_90 = BigDecimal::zero();
        $days90Plus = BigDecimal::zero();
        $total = BigDecimal::zero();

        foreach ($invoices as $inv) {
            $outstanding = $receivablePosition->outstanding($inv, $cutoffDate);
            if ($outstanding->isLessThanOrEqualTo(0)) {
                continue;
            }

            $total = $total->plus($outstanding);

            if ($inv->due_date === null) {
                $unspecified = $unspecified->plus($outstanding);

                continue;
            }

            $dueDate = Carbon::parse($inv->due_date, $timezone)->startOfDay();
            $diffDays = $dueDate->diffInDays($cutoff, false); // positive if overdue

            if ($diffDays <= 0) {
                $current = $current->plus($outstanding);
            } elseif ($diffDays <= 30) {
                $days1_30 = $days1_30->plus($outstanding);
            } elseif ($diffDays <= 60) {
                $days31_60 = $days31_60->plus($outstanding);
            } elseif ($diffDays <= 90) {
                $days61_90 = $days61_90->plus($outstanding);
            } else {
                $days90Plus = $days90Plus->plus($outstanding);
            }
        }

        return [
            'unspecified' => $unspecified,
            'current' => $current,
            'days_1_30' => $days1_30,
            'days_31_60' => $days31_60,
            'days_61_90' => $days61_90,
            'days_90_plus' => $days90Plus,
            'total' => $total,
        ];
    }

    /**
     * Compute payable aging for a purchase collection as of a cutoff date.
     *
     * @param  Collection<int, Purchase>  $purchases
     * @return array{
     *     unspecified: BigDecimal,
     *     current: BigDecimal,
     *     days_1_30: BigDecimal,
     *     days_31_60: BigDecimal,
     *     days_61_90: BigDecimal,
     *     days_90_plus: BigDecimal,
     *     gross_open: BigDecimal
     * }
     */
    public static function calculatePurchaseAging(Collection $purchases, string $cutoffDate, string $timezone): array
    {
        $cutoff = Carbon::parse($cutoffDate, $timezone)->startOfDay();
        $payablePositions = app(PurchasePayableAsOf::class)->forPurchases($purchases, $cutoff);

        $unspecified = BigDecimal::zero();
        $current = BigDecimal::zero();
        $days1_30 = BigDecimal::zero();
        $days31_60 = BigDecimal::zero();
        $days61_90 = BigDecimal::zero();
        $days90Plus = BigDecimal::zero();
        $grossOpen = BigDecimal::zero();

        foreach ($purchases as $pur) {
            $outstanding = $payablePositions[(int) $pur->id] ?? BigDecimal::zero();
            if ($outstanding->isLessThanOrEqualTo(0)) {
                continue;
            }

            $grossOpen = $grossOpen->plus($outstanding);

            if ($pur->due_date === null) {
                $unspecified = $unspecified->plus($outstanding);

                continue;
            }

            $dueDate = Carbon::parse($pur->due_date, $timezone)->startOfDay();
            $diffDays = $dueDate->diffInDays($cutoff, false); // positive if overdue

            if ($diffDays <= 0) {
                $current = $current->plus($outstanding);
            } elseif ($diffDays <= 30) {
                $days1_30 = $days1_30->plus($outstanding);
            } elseif ($diffDays <= 60) {
                $days31_60 = $days31_60->plus($outstanding);
            } elseif ($diffDays <= 90) {
                $days61_90 = $days61_90->plus($outstanding);
            } else {
                $days90Plus = $days90Plus->plus($outstanding);
            }
        }

        return [
            'unspecified' => $unspecified,
            'current' => $current,
            'days_1_30' => $days1_30,
            'days_31_60' => $days31_60,
            'days_61_90' => $days61_90,
            'days_90_plus' => $days90Plus,
            'gross_open' => $grossOpen,
        ];
    }
}
