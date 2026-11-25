<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Domain\Sales\DocumentDiscountAllocator;
use App\Domain\Sales\DocumentDiscountIntent;
use App\Domain\Sales\DocumentDiscountLineCandidate;
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
        $isDocScope = ($invoice->discount_scope ?? 'document') === 'document';
        $minorUnits = $invoice->currency_minor_units ?? $invoice->resolvedCurrencyMinorUnits();

        $allocationResult = null;
        if ($isDocScope && ($invoice->discount_type ?? 'none') !== 'none' && BigDecimal::of($invoice->discount_value ?? '0')->isPositive()) {
            $intent = new DocumentDiscountIntent(
                discountType: $invoice->discount_type,
                discountValue: (string) $invoice->discount_value,
                discountScope: 'document',
                discountBasis: $invoice->discount_basis ?? 'subtotal',
                version: (int) ($invoice->discount_calculation_version ?? DocumentDiscountIntent::VERSION_CANONICAL),
            );
            $candidates = [];
            foreach ($lines as $line) {
                $candidates[] = new DocumentDiscountLineCandidate(
                    lineNumber: (int) $line->line_number,
                    unitPrice: (string) $line->unit_price,
                    quantity: (string) $line->quantity,
                    subtotal: (string) $line->line_subtotal,
                    taxInclusive: (bool) $line->tax_inclusive,
                    taxRate: $line->tax_rate_snapshot !== null ? (string) $line->tax_rate_snapshot : null,
                    identity: (int) $line->line_number,
                );
            }
            $allocationResult = app(DocumentDiscountAllocator::class)->allocate($intent, $candidates, $invoice->currency_code, $minorUnits);
        }

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

            $discountType = $line->discount_type;
            $discountVal = $line->discount_value ?? '0';
            if ($isDocScope) {
                $expectedAllocation = $allocationResult !== null ? $allocationResult->forLine((int) $line->line_number) : BigDecimal::zero()->toScale(6);
                if (! BigDecimal::of((string) ($line->allocated_document_discount ?? '0'))->isEqualTo($expectedAllocation)) {
                    throw new InvalidArgumentException('Draft line allocated document discount mismatch.');
                }
                if ($expectedAllocation->isPositive()) {
                    $discountType = 'fixed';
                    $discountVal = (string) $expectedAllocation;
                }
            }

            $result = app(SalesLineCalculator::class)->calculate(new SalesLineCalculationInput(
                quantity: $line->quantity, unitPrice: $line->unit_price,
                discountType: $discountType, discountValue: $discountVal,
                taxRate: $taxRate, taxInclusive: $line->tax_inclusive,
                currencyMinorUnits: $minorUnits,
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
