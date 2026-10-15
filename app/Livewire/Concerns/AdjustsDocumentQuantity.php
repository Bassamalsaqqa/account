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
}
