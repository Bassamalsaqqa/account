<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Purchasing\PreparedPurchaseDraft;
use App\Domain\Purchasing\PurchaseCalculator;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\TaxRate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PurchaseDraftBuilder
{
    /** @param array<string, mixed> $data */
    public function prepare(Company $company, array $data, ?Purchase $existing = null): PreparedPurchaseDraft
    {
        foreach ([...Purchase::RESERVED_FIELDS, 'status', 'company_id', 'base_currency_code', 'created_by'] as $reserved) {
            if (array_key_exists($reserved, $data)) {
                throw ValidationException::withMessages([$reserved => __('purchasing.draft_effects_forbidden')]);
            }
        }
        $rules = app(PurchaseDocumentRules::class);
        if ($existing !== null) {
            $data = array_replace($this->editableData($existing), $data);
        }
        $data['vendor_invoice_number'] = isset($data['vendor_invoice_number']) && is_string($data['vendor_invoice_number'])
            ? (trim($data['vendor_invoice_number']) ?: null) : ($data['vendor_invoice_number'] ?? null);
        $date = $data['purchase_date'] ?? now($company->timezone)->format('Y-m-d');
        if ($existing === null && ! array_key_exists('due_date', $data)) {
            $data['due_date'] = $rules->defaultDueDate($company, $date);
        }
        $data['due_date'] = ($data['due_date'] ?? null) === '' ? null : ($data['due_date'] ?? null);
        $data['purchase_date'] = $date;
        if (isset($data['lines']) && is_array($data['lines'])) {
            foreach ($data['lines'] as &$lineInput) {
                if (is_array($lineInput)) {
                    foreach (['product_unit_id', 'tax_rate_id'] as $optionalId) {
                        if (($lineInput[$optionalId] ?? null) === '') {
                            $lineInput[$optionalId] = null;
                        }
                    }
                }
            }
            unset($lineInput);
        }
        $validated = Validator::make($data, [
            'vendor_id' => ['required', 'integer'],
            'vendor_invoice_number' => ['nullable', 'string', 'max:128'],
            'warehouse_id' => ['nullable', 'integer'],
            'purchase_date' => ['required', 'string'],
            'due_date' => ['nullable', 'string'],
            'currency_code' => ['required', 'string', Rule::in(['ILS', 'USD', 'JOD'])],
            'document_locale' => ['sometimes', 'string'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.public_id' => ['sometimes', 'string', 'size:26'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.product_unit_id' => ['nullable', 'integer'],
            'lines.*.item_description' => ['sometimes', 'required', 'string', 'max:500'],
            'lines.*.discount_type' => ['nullable', Rule::in(['none', 'fixed', 'percent'])],
            'lines.*.tax_rate_id' => ['nullable', 'integer'],
            'lines.*.lots' => ['sometimes', 'array', 'max:100'],
            'lines.*.lots.*.public_id' => ['sometimes', 'string', 'size:26'],
            'lines.*.lots.*.product_unit_id' => ['required', 'integer'],
            'lines.*.lots.*.lot_number' => ['nullable', 'string', 'max:128'],
            'lines.*.lots.*.expiry_date' => ['nullable', 'string'],
        ])->validate();
        $vendor = $rules->vendor($company, (int) $validated['vendor_id']);
        $warehouse = $rules->warehouse($company, isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null);
        $locale = $validated['document_locale'] ?? $rules->defaultLocale($company, $vendor);
        $rate = $data['exchange_rate'] ?? '1';
        $rules->header($company, $validated['currency_code'], $rate, $date, $validated['due_date'], $locale);
        $results = [];
        $prepared = [];
        // Company lock serializes draft edits. Products are acquired in stable order.
        $products = Product::where('company_id', $company->id)
            ->whereIn('id', array_column($validated['lines'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($validated['lines'] as $index => $line) {
            $raw = $data['lines'][$index];
            foreach (['inventory_unit_cost_base', 'stock_movement_id', 'quantity_base', 'purchase_id', 'company_id'] as $reserved) {
                if (array_key_exists($reserved, $raw)) {
                    throw ValidationException::withMessages(['lines' => __('purchasing.draft_effects_forbidden')]);
                }
            }
            $product = $products->get((int) $line['product_id']);
            abort_if($product === null, 404);
            $quantity = $raw['quantity'] ?? null;
            $unit = $rules->selectedUnit($product, isset($line['product_unit_id']) ? (int) $line['product_unit_id'] : null, $quantity);
            $original = null;
            if (isset($line['public_id'])) {
                if ($existing === null) {
                    throw ValidationException::withMessages(['lines' => __('purchasing.invalid_receiving_intent')]);
                }
                $original = PurchaseLine::where('company_id', $company->id)->where('purchase_id', $existing->id)
                    ->where('public_id', $line['public_id'])->firstOrFail();
            }
            $tax = isset($line['tax_rate_id']) ? TaxRate::where('company_id', $company->id)->where('active', true)->findOrFail($line['tax_rate_id']) : null;
            $calculated = app(PurchaseCalculator::class)->line($quantity, $raw['unit_cost'] ?? null,
                $line['discount_type'] ?? null, $raw['discount_value'] ?? '0', $tax?->rate,
                $tax?->calculation === TaxRate::CALC_INCLUSIVE, $validated['currency_code'], $rate);
            $results[] = $calculated;
            $lots = [];
            $allocated = Quantity::zero();
            if (! $product->track_expiry && ! empty($line['lots'])) {
                throw ValidationException::withMessages(['lines' => __('purchasing.non_expiry_lots_forbidden')]);
            }
            foreach ($line['lots'] ?? [] as $lotIndex => $lot) {
                $rawLot = $raw['lots'][$lotIndex];
                if (array_intersect(['created_inventory_lot_id', 'stock_movement_id', 'quantity_base', 'purchase_line_id', 'company_id'], array_keys($rawLot))
                    || (int) $lot['product_unit_id'] !== (int) $unit->id) {
                    throw ValidationException::withMessages(['lines' => __('purchasing.invalid_receiving_intent')]);
                }
                if (isset($lot['public_id'])) {
                    if ($original === null || (int) $original->product_id !== (int) $product->id
                        || (int) $original->product_unit_id !== (int) $unit->id) {
                        throw ValidationException::withMessages(['lines' => __('purchasing.stale_lot_intent')]);
                    }
                    $original->lots()->where('company_id', $company->id)->where('public_id', $lot['public_id'])->firstOrFail();
                }
                $lotQty = Quantity::of($rawLot['quantity'] ?? null);
                $rules->selectedUnit($product, (int) $unit->id, $lotQty->toBigDecimal());
                $allocated = $allocated->add($lotQty);
                $expiry = ($lot['expiry_date'] ?? null) ?: null;
                if ($expiry !== null) {
                    $rules->date($expiry);
                }
                $lots[] = ['lot_number' => trim($lot['lot_number'] ?? '') ?: null, 'expiry_date' => $expiry,
                    'quantity' => (string) $lotQty, 'quantity_base' => (string) app(UnitConversionService::class)->toBase($lotQty, $unit)];
            }
            if ($allocated->isGreaterThan(Quantity::of($quantity))) {
                throw ValidationException::withMessages(['lines' => __('purchasing.lot_quantity_exceeds_line')]);
            }
            $prepared[] = ['attributes' => [
                'line_number' => $index + 1, 'product_id' => $product->id, 'product_unit_id' => $unit->id,
                'item_description' => $line['item_description'] ?? $product->displayName(),
                'quantity' => (string) Quantity::of($quantity),
                'quantity_base' => (string) app(UnitConversionService::class)->toBase(Quantity::of($quantity), $unit),
                'unit_cost' => (string) MoneyAmount::from($raw['unit_cost']),
                'discount_type' => in_array($line['discount_type'] ?? null, [null, 'none'], true) ? null : $line['discount_type'],
                'discount_value' => (string) MoneyAmount::from($raw['discount_value'] ?? '0'),
                'line_discount' => (string) $calculated->discount,
                'tax_rate_id' => $tax?->id, 'tax_rate_snapshot' => $tax?->rate,
                'tax_inclusive' => $tax?->calculation === TaxRate::CALC_INCLUSIVE,
                'line_subtotal' => (string) $calculated->subtotal, 'line_tax' => (string) $calculated->tax,
                'line_total' => (string) $calculated->total, 'line_subtotal_base' => (string) $calculated->subtotalBase,
                'line_discount_base' => (string) $calculated->discountBase, 'line_tax_base' => (string) $calculated->taxBase,
                'line_total_base' => (string) $calculated->totalBase,
                'unit_conversion_ratio' => (string) $unit->conversion_to_base,
                'unit_name_ar' => $unit->unit->name_ar, 'unit_name_en' => $unit->unit->name_en,
                'product_sku' => $product->sku, 'product_name_ar' => $product->name_ar, 'product_name_en' => $product->name_en,
            ], 'lots' => $lots];
        }
        // Totals are the exact sum of stored rounded lines, including six-decimal base equivalents.
        $totals = app(SalesDocumentTotalsCalculator::class)->calculate($results);
        foreach (get_object_vars($totals) as $amount) {
            MoneyAmount::from($amount);
        }

        return new PreparedPurchaseDraft([
            'vendor_id' => $vendor->id, 'vendor_invoice_number' => $validated['vendor_invoice_number'],
            'warehouse_id' => $warehouse->id, 'purchase_date' => $date, 'due_date' => $validated['due_date'],
            'currency_code' => $validated['currency_code'], 'base_currency_code' => $company->base_currency_code,
            'exchange_rate' => (string) ExchangeRate::from($rate)->getValue(),
            'document_locale' => $locale, 'notes' => $validated['notes'] ?? null,
            'vendor_snapshot' => $vendor->only(['name_ar', 'name_en', 'business_name_ar', 'business_name_en', 'phone', 'email', 'tax_number', 'address_ar', 'address_en', 'city_ar', 'city_en', 'postal_code', 'country_code']),
            'company_snapshot' => $company->only(['name_ar', 'name_en', 'phone', 'email', 'tax_number', 'address_ar', 'address_en']),
            'subtotal_currency' => (string) $totals->subtotalCurrency, 'discount_total_currency' => (string) $totals->discountTotalCurrency,
            'tax_total_currency' => (string) $totals->taxTotalCurrency, 'grand_total_currency' => (string) $totals->grandTotalCurrency,
            'subtotal_base' => (string) $totals->subtotalBase, 'discount_total_base' => (string) $totals->discountTotalBase,
            'tax_total_base' => (string) $totals->taxTotalBase, 'grand_total_base' => (string) $totals->grandTotalBase,
        ], $prepared);
    }

    /** @return array<string, mixed> */
    public function editableData(Purchase $purchase): array
    {
        $data = $purchase->only(['vendor_id', 'vendor_invoice_number', 'warehouse_id', 'currency_code', 'exchange_rate', 'document_locale', 'notes']);
        $data['purchase_date'] = $purchase->purchase_date->format('Y-m-d');
        $data['due_date'] = $purchase->due_date?->format('Y-m-d');
        $data['lines'] = $purchase->lines->map(function (PurchaseLine $line): array {
            $data = $line->only(['public_id', 'product_id', 'product_unit_id', 'item_description', 'quantity', 'unit_cost', 'discount_type', 'discount_value', 'tax_rate_id']);
            $data['lots'] = $line->lots->map(fn ($lot) => [
                'public_id' => $lot->public_id, 'product_unit_id' => $line->product_unit_id,
                'lot_number' => $lot->lot_number, 'expiry_date' => $lot->expiry_date?->format('Y-m-d'), 'quantity' => $lot->quantity,
            ])->all();

            return $data;
        })->all();

        return $data;
    }
}
