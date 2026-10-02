<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class SalesReturnAmounts
{
    /** Cumulative rounded entitlement minus actual prior credits assigns the final residual explicitly. */
    public function refresh(SalesReturn $return): void
    {
        $totals = ['subtotal' => BigDecimal::zero(), 'discount' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'total' => BigDecimal::zero()];
        $scale = $return->currency_code === 'JOD' ? 3 : 2;
        $seen = [];
        foreach ($return->lines()->lockForUpdate()->get() as $line) {
            if (isset($seen[$line->sales_invoice_line_id])) {
                throw new InvalidArgumentException('Combine repeated original invoice-line quantities into one return line.');
            }
            $seen[$line->sales_invoice_line_id] = true;
            $original = SalesInvoiceLine::where('company_id', $return->company_id)->where('sales_invoice_id', $return->sales_invoice_id)
                ->whereKey($line->sales_invoice_line_id)->lockForUpdate()->firstOrFail();
            $prior = SalesReturnLine::where('company_id', $return->company_id)->where('sales_invoice_line_id', $original->id)
                ->where('sales_return_id', '!=', $return->id)->whereHas('salesReturn', fn ($q) => $q->where('status', SalesReturn::STATUS_POSTED))->get();
            $previousQty = BigDecimal::zero();
            foreach ($prior as $previous) {
                $previousQty = $previousQty->plus($previous->quantity);
            }
            $quantity = BigDecimal::of((string) $line->quantity);
            $originalQty = BigDecimal::of((string) $original->quantity);
            $cumulativeQty = $previousQty->plus($quantity);
            if (! $quantity->isPositive() || $cumulativeQty->isGreaterThan($originalQty)) {
                throw new InvalidArgumentException('Return quantity exceeds the remaining original invoice quantity.');
            }
            foreach (array_keys($totals) as $field) {
                $column = 'line_'.$field;
                $already = BigDecimal::zero();
                foreach ($prior as $previous) {
                    $already = $already->plus((string) $previous->getAttribute($column));
                }
                $originalAmount = BigDecimal::of((string) $original->getAttribute($column));
                $entitlement = $cumulativeQty->isEqualTo($originalQty) ? $originalAmount
                    : $originalAmount->multipliedBy($cumulativeQty)->dividedBy($originalQty, $scale, RoundingMode::HALF_UP);
                $amount = $entitlement->minus($already)->toScale(6);
                $line->setAttribute($column, (string) $amount);
                $line->setAttribute($column.'_base', (string) $amount->multipliedBy($return->exchange_rate)->toScale(6, RoundingMode::HALF_UP));
                $totals[$field] = $totals[$field]->plus($amount);
            }
            $line->save();
        }
        foreach (['subtotal' => 'subtotal', 'discount' => 'discount_total', 'tax' => 'tax_total', 'total' => 'grand_total'] as $field => $header) {
            $return->setAttribute($header.'_currency', (string) $totals[$field]->toScale(6));
            $return->setAttribute($header.'_base', (string) $totals[$field]->multipliedBy($return->exchange_rate)->toScale(6, RoundingMode::HALF_UP));
        }
        $return->save();
        $return->unsetRelation('lines');
    }
}
