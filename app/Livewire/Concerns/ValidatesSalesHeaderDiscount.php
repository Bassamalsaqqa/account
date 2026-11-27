<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Domain\Sales\DocumentDiscountAllocationResult;
use App\Domain\Sales\DocumentDiscountAllocator;
use App\Domain\Sales\DocumentDiscountIntent;
use App\Domain\Sales\DocumentDiscountLineCandidate;
use App\Models\Company;
use App\Models\TaxRate;
use App\Services\Money\CurrencyMinorUnitsResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

trait ValidatesSalesHeaderDiscount
{
    protected function invalidatePreviewTotals(): void
    {
        $this->previewSubtotal = '0.00';
        $this->previewDiscountTotal = '0.00';
        $this->previewTaxTotal = '0.00';
        $this->previewGrandTotal = '0.00';
        $this->documentTotalsValid = false;
        $this->onPreviewTotalsInvalidated();
    }

    protected function onPreviewTotalsInvalidated(): void {}

    protected function salesDiscountDocumentCurrencyMinorUnits(): ?int
    {
        return null;
    }

    protected function resolveSalesCurrencyMinorUnits(Company $company): int
    {
        $documentMinorUnits = $this->salesDiscountDocumentCurrencyMinorUnits();
        if ($documentMinorUnits !== null) {
            return $documentMinorUnits;
        }

        return app(CurrencyMinorUnitsResolver::class)->resolveForCompany($company, $this->currency_code);
    }

    protected function hasIncompleteDocumentLine(): bool
    {
        if ($this->lines === []) {
            return true;
        }

        foreach ($this->lines as $line) {
            if (! empty($line['product_id']) || trim((string) ($line['item_description'] ?? '')) !== '') {
                continue;
            }

            $rawPrice = $line['unit_price'] ?? '0';
            if (is_string($rawPrice)) {
                $trimmedPrice = trim($rawPrice);
                if (preg_match('/^\d{1,14}(?:\.\d{1,6})?$/D', $trimmedPrice) === 1 && BigDecimal::of($trimmedPrice)->isZero()) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function resolveHeaderDiscountIntent(): ?DocumentDiscountIntent
    {
        if ($this->discount_scope !== 'document' || $this->discount_type === 'none') {
            return new DocumentDiscountIntent('none', '0', 'document', 'subtotal', $this->discount_calculation_version);
        }

        $discountType = $this->discount_type === 'percentage' ? 'percent' : $this->discount_type;

        if (is_float($this->discount_value) || (! is_string($this->discount_value) && ! is_int($this->discount_value) && $this->discount_value !== null)) {
            $this->addError('discount_value', __('commands.exact_string_required'));

            return null;
        }

        $rawDiscVal = $this->discount_value === null ? '' : trim((string) $this->discount_value);

        try {
            $intent = new DocumentDiscountIntent(
                discountType: $discountType,
                discountValue: $rawDiscVal,
                discountScope: 'document',
                discountBasis: 'subtotal',
                version: $this->discount_calculation_version,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError('discount_value', $e->getMessage());

            return null;
        }

        if (preg_match('/^\d{1,14}(?:\.\d{1,6})?$/D', $rawDiscVal) !== 1) {
            $this->addError('discount_value', __('commands.exact_string_required'));

            return null;
        }

        return $intent;
    }

    /**
     * @param  Collection<int, TaxRate>  $taxes
     * @param  array<int, int|false>  $lineTaxIds
     */
    protected function allocateSalesHeaderDiscount(
        DocumentDiscountIntent $intent,
        Collection $taxes,
        array $lineTaxIds,
        int $minorUnits,
    ): ?DocumentDiscountAllocationResult {
        if ($this->discount_scope !== 'document' || $intent->isNone()) {
            return null;
        }

        $candidates = [];
        foreach ($this->lines as $index => $line) {
            $qty = BigDecimal::of((string) ($line['quantity'] ?: '0'));
            $price = BigDecimal::of((string) ($line['unit_price'] ?: '0'));
            $subtotal = $qty->multipliedBy($price)->toScale($minorUnits, RoundingMode::HALF_UP);
            $tax = $taxes->get($lineTaxIds[$index] ?: 0);

            $candidates[] = new DocumentDiscountLineCandidate(
                lineNumber: $index + 1,
                unitPrice: $price,
                quantity: $qty,
                subtotal: $subtotal,
                taxInclusive: $tax?->calculation === TaxRate::CALC_INCLUSIVE,
                taxRate: $tax?->rate !== null ? (string) $tax->rate : null,
                identity: $index + 1,
            );
        }

        try {
            return app(DocumentDiscountAllocator::class)->allocate($intent, $candidates, $this->currency_code, $minorUnits);
        } catch (\InvalidArgumentException $e) {
            $this->addError('discount_value', $e->getMessage());

            return null;
        }
    }

    protected function resolveAndValidateHeaderDiscountForSave(Company $company): ?DocumentDiscountIntent
    {
        $this->resetErrorBag('discount_value');
        $headerDiscountIntent = $this->resolveHeaderDiscountIntent();
        if ($headerDiscountIntent === null) {
            $this->invalidatePreviewTotals();

            return null;
        }

        if ($this->discount_scope === 'document' && ! $headerDiscountIntent->isNone()) {
            try {
                $minorUnits = $this->resolveSalesCurrencyMinorUnits($company);
            } catch (\InvalidArgumentException) {
                $this->currency_minor_units = null;
                $this->addError('currency_code', __('commands.code_validation_failed'));
                $this->invalidatePreviewTotals();

                return null;
            }

            $lineTaxIds = array_map(static fn (array $line): int|false => filter_var($line['tax_rate_id'] ?? null, FILTER_VALIDATE_INT), $this->lines);
            $taxIds = array_values(array_unique(array_filter($lineTaxIds)));
            $taxes = $taxIds === [] ? collect() : TaxRate::query()
                ->where('company_id', $company->id)
                ->whereKey($taxIds)
                ->get()
                ->keyBy('id');

            if ($this->allocateSalesHeaderDiscount($headerDiscountIntent, $taxes, $lineTaxIds, $minorUnits) === null) {
                $this->invalidatePreviewTotals();

                return null;
            }
        }

        return $headerDiscountIntent;
    }
}
