<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\TaxRate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class DraftInvoiceIntegrity
{
    /** @param Collection<int, SalesInvoiceLine> $lines */
    public function validate(SalesInvoice $invoice, Collection $lines): void
    {
        $results = [];
        foreach ($lines as $line) {
            $quantity = Quantity::of($line->quantity);
            if (! $quantity->toBigDecimal()->isPositive() || (int) $line->company_id !== (int) $invoice->company_id) {
                throw new InvalidArgumentException('Invalid draft line company or quantity.');
            }
            $baseQuantity = $quantity->toBigDecimal()->multipliedBy($line->unit_conversion_ratio)->toScale(6);
            if (! $baseQuantity->isEqualTo(BigDecimal::of($line->quantity_base))) {
                throw new InvalidArgumentException('Draft base quantity does not match its conversion.');
            }
            $taxRate = null;
            if ($line->tax_rate_id !== null) {
                $tax = TaxRate::where('company_id', $invoice->company_id)->whereKey($line->tax_rate_id)->lockForUpdate()->first();
                if ($tax === null || ! $tax->active || $line->tax_rate_snapshot === null
                    || ! BigDecimal::of($tax->rate)->isEqualTo(BigDecimal::of($line->tax_rate_snapshot))
                    || ($tax->calculation === TaxRate::CALC_INCLUSIVE) !== $line->tax_inclusive) {
                    throw new InvalidArgumentException('Draft tax configuration changed; refresh the draft before posting.');
                }
                $taxRate = $line->tax_rate_snapshot;
            } elseif ($line->tax_rate_snapshot !== null || BigDecimal::of($line->line_tax)->isPositive()) {
                throw new InvalidArgumentException('Draft tax has no configured tax rate.');
            }
            $result = app(SalesLineCalculator::class)->calculate(new SalesLineCalculationInput(
                quantity: $line->quantity, unitPrice: $line->unit_price,
                discountType: $line->discount_type, discountValue: $line->discount_value,
                taxRate: $taxRate, taxInclusive: $line->tax_inclusive,
                currencyMinorUnits: $invoice->currency_code === 'JOD' ? 3 : 2,
                exchangeRate: $invoice->exchange_rate,
            ));
            foreach (['subtotal', 'discount', 'tax', 'total'] as $field) {
                foreach (['' => $field, '_base' => $field.'Base'] as $suffix => $property) {
                    if (! $result->{$property}->isEqualTo(BigDecimal::of($line->getAttribute('line_'.$field.$suffix)))) {
                        throw new InvalidArgumentException('Draft line totals do not match the exact calculator.');
                    }
                }
            }
            $results[] = $result;
        }
        $totals = app(SalesDocumentTotalsCalculator::class)->calculate($results, BigDecimal::of($invoice->exchange_rate));
        foreach ($totals->toArray() as $field => $expected) {
            if (! BigDecimal::of($expected)->isEqualTo(BigDecimal::of($invoice->getAttribute($field)))) {
                throw new InvalidArgumentException('Draft header totals do not match its lines.');
            }
        }
    }
}
