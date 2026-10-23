<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

trait AdjustsDocumentQuantity
{
    /**
     * Authorize quantity modification using fresh permissions and stale draft guards.
     */
    protected function authorizeQuantityModification(): void
    {
        $this->authorizeQuickPartyForm();
        $this->assertQuickProductDraft();
    }

    /**
     * Resolve and validate the active Unit model for a document line.
     * Must match BOTH line.product_id and company, active ProductUnit AND active Unit and active Product.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: ?Unit, 1: ?string} [Unit or null, error message or null]
     */
    protected function resolveDocumentLineUnit(array $line): array
    {
        $productId = $line['product_id'] ?? null;
        $productUnitId = $line['product_unit_id'] ?? null;

        if (! empty($productId)) {
            $product = Product::where('company_id', $this->pageCompanyId)
                ->where('active', true)
                ->find((int) $productId);

            if ($product === null) {
                return [null, __('workflow.product_not_found')];
            }

            if (! empty($productUnitId)) {
                $productUnit = ProductUnit::with('unit')
                    ->where('company_id', $this->pageCompanyId)
                    ->where('product_id', (int) $productId)
                    ->where('id', (int) $productUnitId)
                    ->where('active', true)
                    ->first();

                if ($productUnit === null || $productUnit->unit === null || ! $productUnit->unit->active || (int) $productUnit->unit->company_id !== (int) $this->pageCompanyId) {
                    return [null, __('mobile.invalid_unit')];
                }

                return [$productUnit->unit, null];
            }

            // Product selected with no unit specified: look for active base unit
            $baseProductUnit = ProductUnit::with('unit')
                ->where('company_id', $this->pageCompanyId)
                ->where('product_id', (int) $productId)
                ->where('is_base', true)
                ->where('active', true)
                ->first();

            if ($baseProductUnit === null || $baseProductUnit->unit === null || ! $baseProductUnit->unit->active || (int) $baseProductUnit->unit->company_id !== (int) $this->pageCompanyId) {
                // A selected product with no valid unit must NOT fall back to generic step
                return [null, __('mobile.invalid_unit')];
            }

            return [$baseProductUnit->unit, null];
        }

        // Ad-hoc line without product
        return [null, null];
    }

    /**
     * Adjust line quantity by a given direction (+1 / -1).
     */
    public function stepQuantity(int $index, string $direction = 'up', ?string $step = null): void
    {
        $this->authorizeQuantityModification();

        $validDirections = ['up', '+', 'increment', 'down', '-', 'decrement'];
        $direction = strtolower(trim($direction));
        if (! in_array($direction, $validDirections, true)) {
            $this->addError("lines.{$index}.quantity", __('mobile.invalid_direction'));

            return;
        }

        if ($index < 0 || $index >= count($this->lines)) {
            return;
        }

        $line = $this->lines[$index];

        [$unit, $unitError] = $this->resolveDocumentLineUnit($line);
        if ($unitError !== null) {
            $this->addError("lines.{$index}.quantity", $unitError);

            return;
        }

        $allowsFraction = $unit ? (bool) $unit->allows_fraction : false;
        $maxDecimals = $allowsFraction ? min(max(0, (int) $unit->decimal_places), 6) : 0;

        if (! is_string($line['quantity'] ?? null)) {
            $this->addError("lines.{$index}.quantity", __('commands.exact_string_required'));

            return;
        }
        $rawCurrent = trim($line['quantity']);
        if (str_contains($rawCurrent, '.')) {
            $rawCurrent = rtrim(rtrim($rawCurrent, '0'), '.');
        }

        $pattern = $maxDecimals > 0
            ? '/^\d{1,12}(?:\.\d{1,'.$maxDecimals.'})?$/D'
            : '/^\d{1,12}$/D';

        if (preg_match($pattern, $rawCurrent) !== 1) {
            $this->addError("lines.{$index}.quantity", __('commands.exact_string_required'));

            return;
        }

        // Determine exact step: finest configured quantum if fractional, else 1
        if ($allowsFraction && $maxDecimals > 0) {
            $stepValue = '0.'.str_repeat('0', $maxDecimals - 1).'1';
        } else {
            $stepValue = '1';
        }

        // If step parameter explicitly supplied, enforce precision and quantum
        if ($step !== null) {
            $step = trim($step);
            if (preg_match($pattern, $step) !== 1 || ! BigDecimal::of($step)->isPositive()) {
                $this->addError("lines.{$index}.quantity", __('commands.exact_string_required'));

                return;
            }
            $stepValue = $step;
        }

        try {
            $currentDecimal = BigDecimal::of($rawCurrent);
            $stepDecimal = BigDecimal::of($stepValue);

            $isIncrement = in_array($direction, ['up', '+', 'increment'], true);
            $newDecimal = $isIncrement ? $currentDecimal->plus($stepDecimal) : $currentDecimal->minus($stepDecimal);

            if ($newDecimal->compareTo(0) <= 0) {
                $this->addError("lines.{$index}.quantity", __('mobile.quantity_must_be_positive'));

                return;
            }

            if ($maxDecimals > 0) {
                // If original had decimals or quantum applied, preserve precision
                $formatted = (string) $newDecimal;
            } else {
                $formatted = (string) $newDecimal->toBigInteger();
            }

            $this->lines[$index]['quantity'] = $formatted;
            $this->resetErrorBag("lines.{$index}.quantity");

            if (property_exists($this, 'showPreview') && $this->showPreview) {
                if (method_exists($this, 'cancelPreview')) {
                    $this->cancelPreview();
                }
            }

            $this->recalculate();
        } catch (MathException) {
            $this->addError("lines.{$index}.quantity", __('commands.exact_string_required'));
        }
    }

    /**
     * Increment line quantity by finest configured quantum or 1.
     */
    public function incrementQuantity(int $index, ?string $step = null): void
    {
        $this->stepQuantity($index, 'up', $step);
    }

    /**
     * Decrement line quantity by finest configured quantum or 1.
     */
    public function decrementQuantity(int $index, ?string $step = null): void
    {
        $this->stepQuantity($index, 'down', $step);
    }

    /**
     * One exact, bounded draft edit. The complete economic row identity prevents
     * a delayed tap from changing a different same-product row after reordering.
     * No batch is automatically replayed by the client after an ambiguous failure.
     *
     * @param  array<string, mixed>  $expectedIdentity
     * @param  array<int, string>  $directions
     * @return array{ok: bool, quantity?: string, minimumReached?: bool, message?: string}
     */
    public function batchStepQuantity(int $index, mixed $expectedQuantity, array $expectedIdentity, array $directions): array
    {
        $this->authorizeQuantityModification();
        $refuse = function (string $message) use ($index): array {
            $this->addError("lines.{$index}.quantity", $message);

            return ['ok' => false, 'message' => $message];
        };
        if ($directions === [] || count($directions) > 100 || ! array_is_list($directions)) {
            return $refuse(__('mobile.invalid_direction'));
        }
        foreach ($directions as $direction) {
            if (! in_array($direction, ['up', 'down'], true)) {
                return $refuse(__('mobile.invalid_direction'));
            }
        }
        if ($index < 0 || ! isset($this->lines[$index])) {
            return $refuse(__('mobile.line_not_found'));
        }
        $line = $this->lines[$index];
        $identity = [];
        foreach (['id', 'product_id', 'product_unit_id', 'item_description', 'unit_price', 'unit_cost', 'discount_type', 'discount_value', 'tax_rate_id', 'tax_inclusive', 'lot_id', 'lots', 'lot_allocations', 'warehouse_id'] as $field) {
            $identity[$field] = $line[$field] ?? null;
        }
        // JSON object key order is irrelevant; values, types and nested allocations are exact.
        if (count($expectedIdentity) !== count($identity)) {
            return $refuse(__('mobile.quantity_stale'));
        }
        foreach ($identity as $field => $value) {
            if (! array_key_exists($field, $expectedIdentity) || $expectedIdentity[$field] !== $value) {
                return $refuse(__('mobile.quantity_stale'));
            }
        }
        [$unit, $unitError] = $this->resolveDocumentLineUnit($line);
        if ($unitError !== null) {
            return $refuse($unitError);
        }
        $scale = $unit !== null && $unit->allows_fraction ? min(max(0, (int) $unit->decimal_places), 6) : 0;
        $normalize = static function (mixed $value) use ($scale): ?string {
            if (! is_string($value) || strlen($value) > 32) {
                return null;
            }
            $value = trim($value);
            if (str_contains($value, '.')) {
                $value = rtrim(rtrim($value, '0'), '.');
            }
            $pattern = $scale > 0 ? '/^\d{1,12}(?:\.\d{1,'.$scale.'})?$/D' : '/^\d{1,12}$/D';

            return preg_match($pattern, $value) === 1 ? $value : null;
        };
        $current = $normalize($line['quantity'] ?? null);
        $expected = $normalize($expectedQuantity);
        if ($current === null || $expected === null) {
            return $refuse(__('commands.exact_string_required'));
        }
        try {
            $quantity = BigDecimal::of($current);
            if (! $quantity->isPositive() || ! $quantity->isEqualTo(BigDecimal::of($expected))) {
                return $refuse(__('mobile.quantity_stale'));
            }
            $quantum = BigDecimal::of($scale > 0 ? '0.'.str_repeat('0', $scale - 1).'1' : '1');
            $minimumReached = false;
            foreach ($directions as $direction) {
                $next = $direction === 'up' ? $quantity->plus($quantum) : $quantity->minus($quantum);
                if (! $next->isPositive()) {
                    $minimumReached = true;

                    continue;
                }
                if ($normalize((string) $next) === null) {
                    return $refuse(__('commands.exact_string_required'));
                }
                $quantity = $next;
            }
            $formatted = $scale > 0 ? (string) $quantity : (string) $quantity->toBigInteger();
            $this->lines[$index]['quantity'] = $formatted;
            $this->resetErrorBag("lines.{$index}.quantity");
            if (property_exists($this, 'showPreview') && $this->showPreview && method_exists($this, 'cancelPreview')) {
                $this->cancelPreview();
            }
            $this->recalculate();
            if ($minimumReached) {
                $this->addError("lines.{$index}.quantity", __('mobile.quantity_must_be_positive'));
            }

            return ['ok' => true, 'quantity' => $formatted, 'minimumReached' => $minimumReached];
        } catch (MathException) {
            return $refuse(__('commands.exact_string_required'));
        }
    }
}
