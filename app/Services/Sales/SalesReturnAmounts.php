<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Services\Money\CurrencyMinorUnitsResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class SalesReturnAmounts
{
    /** @param iterable<SalesReturnLine> $prior
     * @return array<string, BigDecimal>
     */
    public function calculateLine(SalesInvoiceLine $original, BigDecimal $quantity, iterable $prior, string $currency): array
    {
        $scale = app(CurrencyMinorUnitsResolver::class)->resolve($currency);
        $amounts = [];
        $previousQty = BigDecimal::zero();
        foreach ($prior as $previous) {
            $previousQty = $previousQty->plus($previous->quantity);
        }

        $originalQty = BigDecimal::of((string) $original->quantity);
        $cumulativeQty = $previousQty->plus($quantity);
        if (! $quantity->isPositive() || $cumulativeQty->isGreaterThan($originalQty)) {
            throw new InvalidArgumentException('Return quantity exceeds the remaining original invoice quantity.');
        }
        foreach (['discount', 'tax', 'total'] as $field) {
            $column = 'line_'.$field;
            $already = BigDecimal::zero();
            foreach ($prior as $previous) {
                $already = $already->plus((string) $previous->getAttribute($column));
            }
            $originalAmount = BigDecimal::of((string) $original->getAttribute($column));
            $entitlement = $cumulativeQty->isEqualTo($originalQty) ? $originalAmount
                : $originalAmount->multipliedBy($cumulativeQty)->dividedBy($originalQty, $scale, RoundingMode::HALF_UP);
            $amount = $entitlement->minus($already)->toScale(6);
            $amounts[$field] = $amount;
        }

        // Canonical subtotal reflects gross minus tax plus discount to prevent parallel unbalancing
        $amounts['subtotal'] = $amounts['total']->plus($amounts['discount'])->minus($amounts['tax'])->toScale(6);

        return $amounts;
    }

    /** Cumulative rounded entitlement minus actual prior credits assigns the final residual explicitly. */
    public function refresh(SalesReturn $return): void
    {
        app(TenantWriteAccess::class)->assertWritable((int) $return->company_id);
        $totals = ['subtotal' => BigDecimal::zero(), 'discount' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'total' => BigDecimal::zero()];
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
            foreach ($this->calculateLine($original, BigDecimal::of((string) $line->quantity), $prior, (string) $return->currency_code) as $field => $amount) {
                $column = 'line_'.$field;
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

    /**
     * Validate that return tax and AR totals are representable in base currency at posting exchange rate.
     * Shared canonical rule for preview, web post, and native post:
     * 1. Positive return total must produce positive base relief.
     * 2. Grouped tax components by ledger account and sign must not round to zero base.
     *
     * @param  array<int, array{pos: BigDecimal, neg: BigDecimal}>  $taxComponents
     */
    public function validateRepresentability(ExchangeRate $fx, BigDecimal $grandTotalCurrency, BigDecimal $grandTotalBase, array $taxComponents): void
    {
        if ($grandTotalCurrency->isPositive() && $grandTotalBase->isZero()) {
            throw new InvalidArgumentException('Sales return total component cannot be represented at the current exchange rate.');
        }

        foreach ($taxComponents as $comp) {
            if ($comp['pos']->isPositive() && $fx->toBase(MoneyAmount::from($comp['pos']))->isZero()) {
                throw new InvalidArgumentException('Sales return tax component cannot be represented at the current exchange rate.');
            }
            if ($comp['neg']->isNegative() && $fx->toBase(MoneyAmount::from($comp['neg']->abs()))->isZero()) {
                throw new InvalidArgumentException('Sales return tax component cannot be represented at the current exchange rate.');
            }
        }
    }
}
