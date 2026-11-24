<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Purchasing\DocumentDiscountAllocator;
use App\Domain\Purchasing\DocumentDiscountIntent;
use App\Domain\Purchasing\DocumentDiscountLineCandidate;
use App\Domain\Purchasing\PreparedPurchaseDraft;
use App\Domain\Purchasing\PurchaseCalculator;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\TaxRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
            if (array_key_exists('currency_code', $data) && $data['currency_code'] !== $existing->currency_code) {
                $errors = [];
                foreach (['exchange_rate', 'lines'] as $field) {
                    if (! array_key_exists($field, $data)) {
                        $errors[$field] = __('purchasing.currency_change_requires_replacement');
                    }
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                Validator::make($data, ['exchange_rate' => ['required']])->validate();
            }
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
        $customAttributes = [
            'vendor_id' => __('purchasing.vendor'),
            'vendor_invoice_number' => __('purchasing.vendor_invoice_number'),
            'warehouse_id' => __('purchasing.receiving_warehouse'),
            'purchase_date' => __('purchasing.purchase_date'),
            'due_date' => __('purchasing.due_date'),
            'currency_code' => __('purchasing.currency'),
            'exchange_rate' => __('purchasing.exchange_rate'),
            'discount_type' => __('purchasing.discount'),
            'discount_value' => __('purchasing.discount_value'),
            'lines' => __('purchasing.purchase_lines'),
        ];
        if (isset($data['lines']) && is_array($data['lines'])) {
            foreach ($data['lines'] as $idx => $line) {
                $humanIndex = $idx + 1;
                $customAttributes["lines.{$idx}.product_id"] = __('purchasing.line_attribute_product', ['index' => $humanIndex]);
                $customAttributes["lines.{$idx}.item_description"] = __('purchasing.line_attribute_description', ['index' => $humanIndex]);
                $customAttributes["lines.{$idx}.quantity"] = __('purchasing.line_attribute_quantity', ['index' => $humanIndex]);
                $customAttributes["lines.{$idx}.unit_cost"] = __('purchasing.line_attribute_cost', ['index' => $humanIndex]);
                $customAttributes["lines.{$idx}.product_unit_id"] = __('purchasing.line_attribute_unit', ['index' => $humanIndex]);
                $customAttributes["lines.{$idx}.discount_value"] = __('purchasing.line_attribute_discount', ['index' => $humanIndex]);
                $customAttributes["lines.{$idx}.tax_rate_id"] = __('purchasing.line_attribute_tax', ['index' => $humanIndex]);
            }
        }
        $validated = Validator::make($data, [
            'vendor_id' => ['required', 'integer'],
            'vendor_invoice_number' => ['nullable', 'string', 'max:128'],
            'warehouse_id' => ['nullable', 'integer'],
            'purchase_date' => ['required', 'string'],
            'due_date' => ['nullable', 'string'],
            'currency_code' => ['required', 'string', Rule::in(['ILS', 'USD', 'JOD'])],
            'document_locale' => ['sometimes', 'string'],
            'discount_type' => ['nullable', 'string', Rule::in(['none', 'fixed', 'percent'])],
            'discount_value' => ['nullable'],
            'discount_scope' => ['nullable', 'string', Rule::in(['document', 'line'])],
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
        ], [], $customAttributes)->validate();

        $seenLinePublicIds = [];
        foreach ($validated['lines'] as $l) {
            if (! empty($l['public_id'])) {
                if (isset($seenLinePublicIds[$l['public_id']])) {
                    throw ValidationException::withMessages(['lines' => __('purchasing.duplicate_line_public_id')]);
                }
                $seenLinePublicIds[$l['public_id']] = true;
            }
        }

        $vendor = $rules->vendor($company, (int) $validated['vendor_id']);
        $warehouse = $rules->warehouse($company, isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null);
        $locale = $validated['document_locale'] ?? $rules->defaultLocale($company, $vendor);
        $rate = $data['exchange_rate'] ?? '1';
        $rules->header($company, $validated['currency_code'], $rate, $date, $validated['due_date'], $locale);

        // Resolve discount scope and enforce strict mutex / legacy preservation
        $hasLineDiscounts = false;
        foreach ($validated['lines'] as $lineIdx => $l) {
            $rawL = $data['lines'][$lineIdx] ?? [];
            $dt = $l['discount_type'] ?? null;
            $dv = $rawL['discount_value'] ?? '0';
            if ($dt !== null && $dt !== 'none' && $dt !== '') {
                $hasLineDiscounts = true;
                break;
            }
            if ($dv !== '' && ! BigDecimal::of((string) $dv)->isZero()) {
                $hasLineDiscounts = true;
                break;
            }
        }

        $headerDiscountType = $validated['discount_type'] ?? null;
        $headerDiscountValue = $data['discount_value'] ?? '0';
        $hasDocumentDiscount = $headerDiscountType !== null
            && $headerDiscountType !== 'none'
            && ! BigDecimal::of((string) ($headerDiscountValue ?: '0'))->isZero();

        if ($hasDocumentDiscount && $hasLineDiscounts) {
            throw ValidationException::withMessages(['lines' => __('purchasing.manual_line_discount_forbidden')]);
        }

        $requestedScope = $data['discount_scope'] ?? ($validated['discount_scope'] ?? null);

        if ($existing === null) {
            if ($requestedScope === 'document') {
                $discountScope = 'document';
                if ($hasLineDiscounts) {
                    throw ValidationException::withMessages(['lines' => __('purchasing.manual_line_discount_forbidden')]);
                }
            } elseif ($requestedScope === 'line') {
                $discountScope = 'line';
            } elseif ($hasDocumentDiscount) {
                $discountScope = 'document';
            } elseif ($hasLineDiscounts) {
                // Historical non-UI caller adapter: legacy tests/callers submitting line discounts
                $discountScope = 'line';
            } else {
                $discountScope = 'document';
            }
        } else {
            $discountScope = $requestedScope ?? $existing->discount_scope ?? ($hasLineDiscounts ? 'line' : 'document');
            if ($discountScope === 'document' && $hasLineDiscounts) {
                throw ValidationException::withMessages(['lines' => __('purchasing.manual_line_discount_forbidden')]);
            }
        }

        $results = [];
        $prepared = [];
        // Company lock serializes draft edits. Products are acquired in stable order.
        $products = Product::where('company_id', $company->id)
            ->whereIn('id', array_column($validated['lines'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // Document-level discount allocation pass
        $allocationResult = null;
        if ($discountScope === 'document') {
            $candidates = [];
            $minor = $validated['currency_code'] === 'JOD' ? 3 : 2;
            foreach ($validated['lines'] as $index => $line) {
                $raw = $data['lines'][$index];
                $product = $products->get((int) $line['product_id']);
                abort_if($product === null, 404);
                $quantity = $raw['quantity'] ?? null;
                $unit = $rules->selectedUnit($product, isset($line['product_unit_id']) ? (int) $line['product_unit_id'] : null, $quantity);
                $qty = Quantity::of($quantity)->toBigDecimal();
                $price = MoneyAmount::from($raw['unit_cost'])->getAmount();
                $subtotal = $qty->multipliedBy($price)->toScale($minor, RoundingMode::HALF_UP);
                $tax = isset($line['tax_rate_id']) ? TaxRate::where('company_id', $company->id)->where('active', true)->findOrFail($line['tax_rate_id']) : null;
                $candidates[] = new DocumentDiscountLineCandidate(
                    lineNumber: $index + 1,
                    unitCost: $price,
                    quantity: $qty,
                    subtotal: $subtotal,
                    taxInclusive: $tax?->calculation === TaxRate::CALC_INCLUSIVE,
                    taxRate: $tax?->rate !== null ? BigDecimal::of((string) $tax->rate) : null,
                );
            }

            try {
                $intent = new DocumentDiscountIntent(
                    discountType: in_array($headerDiscountType, [null, '', 'none'], true) ? DocumentDiscountIntent::TYPE_NONE : $headerDiscountType,
                    discountValue: $headerDiscountValue,
                    discountScope: 'document',
                    discountBasis: DocumentDiscountIntent::BASIS_SUBTOTAL,
                    version: DocumentDiscountIntent::VERSION_CANONICAL,
                );
                $allocationResult = app(DocumentDiscountAllocator::class)->allocate($intent, $candidates, $validated['currency_code']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['discount_value' => $e->getMessage()]);
            }
        }

        foreach ($validated['lines'] as $index => $line) {
            $raw = $data['lines'][$index];
            foreach (['inventory_unit_cost_base', 'stock_movement_id', 'purchase_tax_account_id', 'quantity_base', 'purchase_id', 'company_id'] as $reserved) {
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

                if ((int) $original->product_id !== (int) $product->id || (int) $original->product_unit_id !== (int) $unit->id) {
                    throw ValidationException::withMessages(['lines' => __('purchasing.stale_line_intent')]);
                }
            }
            $tax = isset($line['tax_rate_id']) ? TaxRate::where('company_id', $company->id)->where('active', true)->findOrFail($line['tax_rate_id']) : null;

            if ($discountScope === 'document') {
                $allocatedDiscount = $allocationResult->forLine($index + 1);
                $calculated = app(PurchaseCalculator::class)->line(
                    $quantity,
                    $raw['unit_cost'] ?? null,
                    'fixed',
                    (string) $allocatedDiscount,
                    $tax?->rate,
                    $tax?->calculation === TaxRate::CALC_INCLUSIVE,
                    $validated['currency_code'],
                    $rate
                );
                $lineDiscountType = null;
                $lineDiscountValue = '0.000000';
                $manualDiscountAmount = '0.000000';
                $allocatedDocDiscount = (string) $allocatedDiscount;
            } else {
                $rawDiscountType = $line['discount_type'] ?? null;
                $rawDiscountVal = $raw['discount_value'] ?? '0';
                $hasIncomingDiscount = $rawDiscountType !== null && $rawDiscountType !== 'none' && $rawDiscountType !== ''
                    && ! BigDecimal::of((string) ($rawDiscountVal !== '' ? $rawDiscountVal : '0'))->isZero();

                if (! $hasIncomingDiscount && $original !== null && $original->discount_type !== null && $original->discount_type !== 'none') {
                    $effectiveDiscountType = $original->discount_type;
                    $effectiveDiscountValue = (string) $original->discount_value;
                } else {
                    $effectiveDiscountType = $line['discount_type'] ?? null;
                    $effectiveDiscountValue = $raw['discount_value'] ?? '0';
                }

                $calculated = app(PurchaseCalculator::class)->line(
                    $quantity,
                    $raw['unit_cost'] ?? null,
                    $effectiveDiscountType,
                    $effectiveDiscountValue,
                    $tax?->rate,
                    $tax?->calculation === TaxRate::CALC_INCLUSIVE,
                    $validated['currency_code'],
                    $rate
                );
                $lineDiscountType = in_array($effectiveDiscountType, [null, 'none'], true) ? null : $effectiveDiscountType;
                $lineDiscountValue = (string) MoneyAmount::from($effectiveDiscountValue);
                $manualDiscountAmount = (string) $calculated->discount;
                $allocatedDocDiscount = '0.000000';
            }

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
                'discount_type' => $lineDiscountType,
                'discount_value' => $lineDiscountValue,
                'manual_discount_amount' => $manualDiscountAmount,
                'allocated_document_discount' => $allocatedDocDiscount,
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
            'discount_type' => in_array($headerDiscountType, [null, '', 'none'], true) ? null : $headerDiscountType,
            'discount_value' => (string) MoneyAmount::from((string) ($headerDiscountValue ?: '0')),
            'discount_scope' => $discountScope,
            'discount_basis' => 'subtotal',
            'discount_calculation_version' => $discountScope === 'document' ? DocumentDiscountIntent::VERSION_CANONICAL : DocumentDiscountIntent::VERSION_LEGACY,
            'vendor_snapshot' => app(PurchaseIdentitySnapshot::class)->vendor($vendor),
            'company_snapshot' => app(PurchaseIdentitySnapshot::class)->company($company),
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
        $data['discount_type'] = $purchase->discount_type ?? 'none';
        $data['discount_value'] = (string) ($purchase->discount_value ?? '0');
        $data['discount_scope'] = $purchase->discount_scope ?? 'document';
        $data['discount_basis'] = $purchase->discount_basis ?? 'subtotal';
        $data['discount_calculation_version'] = (int) ($purchase->discount_calculation_version ?? 1);
        $data['lines'] = $purchase->lines->map(function (PurchaseLine $line): array {
            $data = $line->only(['public_id', 'product_id', 'product_unit_id', 'item_description', 'discount_type', 'tax_rate_id']);
            $data['quantity'] = SalesMoneyFormatter::formatQuantity($line->quantity);
            $data['unit_cost'] = SalesMoneyFormatter::formatMoneyInput($line->unit_cost);
            $data['discount_value'] = (string) ($line->discount_value ?? '0');
            $data['manual_discount_amount'] = (string) ($line->manual_discount_amount ?? '0');
            $data['allocated_document_discount'] = (string) ($line->allocated_document_discount ?? '0');
            $data['lots'] = $line->lots->map(fn ($lot) => [
                'public_id' => $lot->public_id, 'product_unit_id' => $line->product_unit_id,
                'lot_number' => $lot->lot_number, 'expiry_date' => $lot->expiry_date?->format('Y-m-d'),
                'quantity' => SalesMoneyFormatter::formatQuantity($lot->quantity),
            ])->all();

            return $data;
        })->all();

        return $data;
    }
}
