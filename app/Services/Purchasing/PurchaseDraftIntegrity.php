<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Purchasing\PurchaseCalculator;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\TaxRate;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class PurchaseDraftIntegrity
{
    public function validate(Company $company, Purchase $purchase): void
    {
        $purchase->assertMutableDraft();
        $rules = app(PurchaseDocumentRules::class);
        $rules->validateModelHeader($company, $purchase);
        foreach (Purchase::RESERVED_FIELDS as $field) {
            if ($purchase->{$field} !== null) {
                $this->fail();
            }
        }
        if ($purchase->lines->isEmpty()) {
            $this->fail();
        }
        Product::where('company_id', $company->id)->whereIn('id', $purchase->lines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
        $results = [];
        foreach ($purchase->lines as $line) {
            if ((int) $line->company_id !== (int) $company->id || $line->stock_movement_id !== null
                || $line->inventory_unit_cost_base !== null || $line->purchase_tax_account_id !== null) {
                $this->fail();
            }
            $product = Product::where('company_id', $company->id)->lockForUpdate()->findOrFail($line->product_id);
            $unit = $rules->selectedUnit($product, (int) $line->product_unit_id, $line->quantity);
            if (! BigDecimal::of($line->unit_conversion_ratio)->isEqualTo($unit->conversion_to_base)
                || ! app(UnitConversionService::class)->toBase(Quantity::of($line->quantity), $unit)->toBigDecimal()->isEqualTo($line->quantity_base)) {
                $this->fail();
            }
            if ($line->tax_rate_id !== null) {
                TaxRate::where('company_id', $company->id)->where('active', true)->lockForUpdate()->findOrFail($line->tax_rate_id);
                if ($line->tax_rate_snapshot === null) {
                    $this->fail();
                }
            } elseif ($line->tax_rate_snapshot !== null || $line->tax_inclusive || ! BigDecimal::of($line->line_tax)->isZero()) {
                $this->fail();
            }
            $result = app(PurchaseCalculator::class)->line($line->quantity, $line->unit_cost, $line->discount_type,
                $line->discount_value, $line->tax_rate_snapshot, $line->tax_inclusive, $purchase->currency_code, $purchase->exchange_rate);
            foreach (['subtotal', 'discount', 'tax', 'total'] as $field) {
                foreach (['' => $field, '_base' => $field.'Base'] as $suffix => $property) {
                    if (! $result->{$property}->isEqualTo($line->getAttribute('line_'.$field.$suffix))) {
                        $this->fail();
                    }
                }
            }
            $results[] = $result;
            if (! $product->track_expiry && $line->lots->isNotEmpty()) {
                $this->fail();
            }
            foreach ($line->lots as $lot) {
                if ((int) $lot->company_id !== (int) $company->id || $lot->created_inventory_lot_id !== null || $lot->stock_movement_id !== null) {
                    $this->fail();
                }
                $rules->selectedUnit($product, (int) $unit->id, $lot->quantity);
                if (! app(UnitConversionService::class)->toBase(Quantity::of($lot->quantity), $unit)->toBigDecimal()->isEqualTo($lot->quantity_base)) {
                    $this->fail();
                }
                if ($lot->expiry_date !== null) {
                    $rules->date($lot->expiry_date->format('Y-m-d'));
                }
            }
            if ($product->track_expiry) {
                app(PurchaseAcquisitionValue::class)->lots($line, BigDecimal::of($line->line_total_base));
            }
        }
        foreach (app(SalesDocumentTotalsCalculator::class)->calculate($results)->toArray() as $field => $expected) {
            if (! BigDecimal::of($expected)->isEqualTo($purchase->{$field})) {
                $this->fail();
            }
        }
        if (! BigDecimal::of($purchase->grand_total_base)->isPositive()) {
            throw new InvalidArgumentException(__('purchasing.positive_post_total_required'));
        }
    }

    private function fail(): never
    {
        throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
    }
}
