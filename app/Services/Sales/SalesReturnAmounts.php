<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class SalesReturnAmounts
{
    /**
     * @param  iterable<SalesReturnLine>  $prior
     * @return array<string, BigDecimal>
     */
    public function calculateLine(SalesInvoiceLine $original, BigDecimal $quantity, iterable $prior, SalesInvoice $invoice): array
    {
        $scale = $invoice->resolvedCurrencyMinorUnits();
        $previous = [];
        $previousQty = BigDecimal::zero();
        foreach ($prior as $line) {
            $previousQty = $previousQty->plus($line->quantity);
            foreach (['subtotal', 'discount', 'tax', 'total'] as $field) {
                foreach ([$field, $field.'_base'] as $key) {
                    $raw = (string) ($line->getAttribute('line_'.$key) ?? '0');
                    $val = $raw !== '' ? $raw : '0';
                    $previous[$key] = ($previous[$key] ?? BigDecimal::zero())->plus($val);
                }
            }
        }

        $originalQty = BigDecimal::of((string) $original->quantity);
        $cumulativeQty = $previousQty->plus($quantity);
        if (! $quantity->isPositive() || $cumulativeQty->isGreaterThan($originalQty)) {
            throw new InvalidArgumentException('Return quantity exceeds the remaining original invoice quantity.');
        }

        $final = $cumulativeQty->isEqualTo($originalQty);
        $entitlements = [];
        foreach (['discount', 'tax', 'total'] as $field) {
            $raw = (string) ($original->getAttribute('line_'.$field) ?? '0');
            $originalAmount = BigDecimal::of($raw !== '' ? $raw : '0');
            $entitlements[$field] = $final ? $originalAmount
                : $originalAmount->multipliedBy($cumulativeQty)->dividedBy($originalQty, $scale, RoundingMode::HALF_UP);
        }
        $entitlements['subtotal'] = $entitlements['total']->plus($entitlements['discount'])->minus($entitlements['tax']);

        $amounts = [];
        foreach ($entitlements as $field => $amount) {
            $rawBase = (string) ($original->getAttribute('line_'.$field.'_base') ?? '');
            $base = ($final && $rawBase !== '') ? BigDecimal::of($rawBase)
                : $amount->multipliedBy($invoice->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
            $amounts[$field] = $amount->minus($previous[$field] ?? BigDecimal::zero())->toScale(6);
            $amounts[$field.'_base'] = $base->minus($previous[$field.'_base'] ?? BigDecimal::zero())->toScale(6);
        }

        return $amounts;
    }

    /**
     * @param  list<array<string, BigDecimal>>  $lines
     * @return array<string, string>
     */
    public function totals(SalesInvoice $invoice, array $lines, ?int $excludingReturnId = null): array
    {
        $prior = SalesReturn::query()->where('company_id', $invoice->company_id)
            ->where('sales_invoice_id', $invoice->id)->where('status', SalesReturn::STATUS_POSTED)
            ->when($excludingReturnId !== null, fn ($query) => $query->whereKeyNot($excludingReturnId))
            ->selectRaw('SUM(subtotal_currency) AS subtotal_currency, SUM(subtotal_base) AS subtotal_base,
                SUM(discount_total_currency) AS discount_total_currency, SUM(discount_total_base) AS discount_total_base,
                SUM(tax_total_currency) AS tax_total_currency, SUM(tax_total_base) AS tax_total_base,
                SUM(grand_total_currency) AS grand_total_currency, SUM(grand_total_base) AS grand_total_base')
            ->toBase()->first();

        $totals = [];
        foreach (['subtotal' => 'subtotal', 'discount' => 'discount_total', 'tax' => 'tax_total', 'total' => 'grand_total'] as $field => $header) {
            $currencyColumn = $header.'_currency';
            $baseColumn = $header.'_base';
            $current = BigDecimal::zero();
            foreach ($lines as $amounts) {
                $current = $current->plus($amounts[$field]);
            }
            $cumulative = $current->plus((string) ($prior->{$currencyColumn} ?? '0'));
            $totals[$currencyColumn] = (string) $current->toScale(6);
            $totals[$baseColumn] = (string) $cumulative->multipliedBy($invoice->exchange_rate)->toScale(6, RoundingMode::HALF_UP)
                ->minus((string) ($prior->{$baseColumn} ?? '0'))->toScale(6);
        }

        return $totals;
    }

    /** Cumulative rounded entitlement minus actual prior credits assigns the final residual explicitly. */
    public function refresh(SalesReturn $return, SalesInvoice $invoice): void
    {
        app(TenantWriteAccess::class)->assertWritable((int) $return->company_id);
        $calculated = [];
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
            $amounts = $this->calculateLine($original, BigDecimal::of($line->quantity), $prior, $invoice);
            foreach ($amounts as $field => $amount) {
                $line->setAttribute('line_'.$field, (string) $amount);
            }
            $line->save();
            $calculated[] = $amounts;
        }
        $return->forceFill($this->totals($invoice, $calculated, (int) $return->id));
        $return->currency_minor_units = $invoice->resolvedCurrencyMinorUnits();
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
