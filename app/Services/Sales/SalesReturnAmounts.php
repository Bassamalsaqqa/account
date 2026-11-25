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
        $return->currency_minor_units = $return->resolvedCurrencyMinorUnits();
        $return->save();
        $return->unsetRelation('lines');
    }

    /**
     * Validate that return gross, net revenue, and tax components are representable in base currency at posting exchange rate.
     * Shared canonical rule for preview, web post, and native post:
     * 1. Positive return gross total must produce positive event base at the exchange rate, AND positive cumulative base relief.
     * 2. Non-zero net revenue/subtotal must produce non-zero event base at the exchange rate, AND non-zero base.
     * 3. Grouped tax components by ledger account and sign must produce positive base conversion at the exchange rate.
     *
     * @param  array<int, array{pos: BigDecimal, neg: BigDecimal, net?: BigDecimal}>  $taxComponents
     */
    public function validateRepresentability(
        ExchangeRate $fx,
        BigDecimal $grandTotalCurrency,
        BigDecimal $grandTotalBase,
        array $taxComponents,
        ?BigDecimal $subtotalCurrency = null,
        ?BigDecimal $subtotalBase = null
    ): void {
        if ($grandTotalCurrency->isPositive()) {
            $eventGrossBase = $fx->toBase(MoneyAmount::from($grandTotalCurrency));
            if ($eventGrossBase->isZero() || $grandTotalBase->isZero()) {
                throw new InvalidArgumentException(__('sales.return_total_unrepresentable'));
            }
        }

        if ($subtotalCurrency === null) {
            $taxTotalCurrency = BigDecimal::zero();
            foreach ($taxComponents as $comp) {
                $taxTotalCurrency = $taxTotalCurrency->plus($comp['net'] ?? $comp['pos']->plus($comp['neg']));
            }
            $subtotalCurrency = $grandTotalCurrency->minus($taxTotalCurrency);
        }
        if ($subtotalBase === null) {
            $taxTotalBase = BigDecimal::zero();
            foreach ($taxComponents as $comp) {
                $posB = $fx->toBase(MoneyAmount::from($comp['pos']))->getAmount();
                $negB = $fx->toBase(MoneyAmount::from($comp['neg']->abs()))->getAmount();
                $taxTotalBase = $taxTotalBase->plus($posB->minus($negB));
            }
            $subtotalBase = $grandTotalBase->minus($taxTotalBase);
        }

        if ($subtotalCurrency->isPositive()) {
            $eventNetBase = $fx->toBase(MoneyAmount::from($subtotalCurrency));
            if ($eventNetBase->isZero() || $subtotalBase->isLessThanOrEqualTo(0)) {
                throw new InvalidArgumentException(__('sales.return_revenue_unrepresentable'));
            }
        } elseif ($subtotalCurrency->isNegative()) {
            $eventNetBase = $fx->toBase(MoneyAmount::from($subtotalCurrency->abs()));
            if ($eventNetBase->isZero() || $subtotalBase->isGreaterThanOrEqualTo(0)) {
                throw new InvalidArgumentException(__('sales.return_revenue_unrepresentable'));
            }
        }

        foreach ($taxComponents as $comp) {
            if ($comp['pos']->isPositive() && $fx->toBase(MoneyAmount::from($comp['pos']))->isZero()) {
                throw new InvalidArgumentException(__('sales.return_tax_unrepresentable'));
            }
            if ($comp['neg']->isNegative() && $fx->toBase(MoneyAmount::from($comp['neg']->abs()))->isZero()) {
                throw new InvalidArgumentException(__('sales.return_tax_unrepresentable'));
            }
        }
    }
}
