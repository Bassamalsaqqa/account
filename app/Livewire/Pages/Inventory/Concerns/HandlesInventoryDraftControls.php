<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Inventory\Concerns;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Unit;
use Brick\Math\BigDecimal;

trait HandlesInventoryDraftControls
{
    /**
     * Authorize active company context, membership, and inventory permissions.
     */
    abstract protected function authorizeInventoryContext(): Company;

    /**
     * Handle camera barcode scan for selecting product and unit on draft inventory forms.
     * ONLY selects draft product/unit; warehouse and lot remain authority; never posts.
     *
     * @return array{ok: bool, message: string}
     */
    public function scanCameraBarcode(mixed $code): array
    {
        // 1. Fresh actor, company context, locked company guard and permissions
        $company = $this->authorizeInventoryContext();

        // 2. Exact bounded string validation (1..128 chars, non-empty, no control chars or URL commands)
        if (! is_string($code)) {
            return [
                'ok' => false,
                'message' => __('refinement.invalid_barcode_format'),
            ];
        }

        $raw = trim($code);
        if ($raw === '' || mb_strlen($raw) > 128) {
            return [
                'ok' => false,
                'message' => mb_strlen($raw) > 128
                    ? __('refinement.barcode_too_long')
                    : __('refinement.invalid_barcode_format'),
            ];
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $raw) || preg_match('/^(?:javascript|data|file|vbscript):/i', $raw)) {
            return [
                'ok' => false,
                'message' => __('refinement.invalid_barcode_format'),
            ];
        }

        // 3. ProductBarcode lookup
        /** @var ProductBarcode|null $barcode */
        $barcode = ProductBarcode::where('company_id', $company->id)
            ->where('barcode', $raw)
            ->with(['product', 'unit'])
            ->first();

        $product = null;
        $targetUnitId = null;

        if ($barcode !== null) {
            /** @var Product|null $barcodeProduct */
            $barcodeProduct = $barcode->product;
            if ($barcodeProduct !== null && (int) $barcodeProduct->company_id === (int) $company->id && $barcodeProduct->active && $barcodeProduct->track_stock && $barcodeProduct->product_type === Product::TYPE_STOCK) {
                $product = $barcodeProduct;
                if ($barcode->unit_id !== null) {
                    $isBase = (int) $barcode->unit_id === (int) $product->base_unit_id;
                    $isAlternate = ProductUnit::where('company_id', $company->id)
                        ->where('product_id', $product->id)
                        ->where('unit_id', $barcode->unit_id)
                        ->where('active', true)
                        ->whereHas('unit', fn ($u) => $u->where('company_id', $company->id)->where('active', true))
                        ->exists();

                    if (! $isBase && ! $isAlternate) {
                        return [
                            'ok' => false,
                            'message' => __('refinement.invalid_unit'),
                        ];
                    }
                    $targetUnitId = (int) $barcode->unit_id;
                } else {
                    $targetUnitId = (int) $product->base_unit_id;
                }
            }
        }

        if ($product === null && $barcode === null) {
            // Fallback: SKU lookup
            $product = Product::where('company_id', $company->id)
                ->where('active', true)
                ->where('track_stock', true)
                ->where('product_type', Product::TYPE_STOCK)
                ->where('sku', $raw)
                ->first();

            if ($product !== null) {
                $targetUnitId = (int) $product->base_unit_id;
            }
        }

        if ($product === null) {
            return [
                'ok' => false,
                'message' => __('refinement.barcode_not_found'),
            ];
        }

        if (! Unit::where('company_id', $company->id)->where('active', true)->whereKey($targetUnitId)->exists()
            || ! ProductUnit::where('company_id', $company->id)->where('product_id', $product->id)
                ->where('unit_id', $targetUnitId)->where('active', true)->exists()) {
            return ['ok' => false, 'message' => __('refinement.invalid_unit')];
        }

        // 4. Set product_id and trigger updatedProductId() to reset lot/expiry and initialize defaults
        if ($this->product_id !== (int) $product->id) {
            $this->product_id = (int) $product->id;
            $this->updatedProductId();
        }

        // 5. If specific target unit was resolved from barcode, apply it over default base unit
        if ($targetUnitId > 0) {
            $this->unit_id = $targetUnitId;
        }

        return [
            'ok' => true,
            'message' => __('refinement.product_selected'),
        ];
    }

    /**
     * Exact server quantity stepper for inventory draft forms.
     */
    public function stepInventoryQuantity(string $direction): void
    {
        // 1. Fresh authorization
        $company = $this->authorizeInventoryContext();

        // 2. Validate direction
        $validDirections = ['up', '+', 'increment', 'down', '-', 'decrement'];
        $dir = strtolower(trim($direction));
        if (! in_array($dir, $validDirections, true)) {
            $this->addError('quantity', __('refinement.invalid_direction'));

            return;
        }

        // 3. Resolve active selected same-company product
        if (empty($this->product_id)) {
            $this->addError('quantity', __('refinement.select_product_first'));

            return;
        }

        /** @var Product|null $product */
        $product = Product::where('company_id', $company->id)
            ->where('active', true)
            ->where('track_stock', true)
            ->where('product_type', Product::TYPE_STOCK)
            ->find($this->product_id);

        if ($product === null) {
            $this->addError('quantity', __('refinement.barcode_not_found'));

            return;
        }

        // 4. Resolve active selected same-company unit
        $unitId = $this->unit_id ?? $product->base_unit_id;
        if (empty($unitId)) {
            $this->addError('quantity', __('refinement.invalid_unit'));

            return;
        }

        /** @var Unit|null $unit */
        $unit = Unit::where('company_id', $company->id)
            ->where('active', true)
            ->find($unitId);

        if ($unit === null) {
            $this->addError('quantity', __('refinement.invalid_unit'));

            return;
        }

        $isPermitted = ProductUnit::where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->where('unit_id', $unit->id)
            ->where('active', true)
            ->exists();

        if (! $isPermitted) {
            $this->addError('quantity', __('refinement.invalid_unit'));

            return;
        }

        // 5. Unit precision & step quantum
        $allowsFraction = (bool) $unit->allows_fraction;
        $maxDecimals = $allowsFraction ? min(max(0, (int) $unit->decimal_places), 6) : 0;

        if ($allowsFraction && $maxDecimals > 0) {
            $stepValue = '0.'.str_repeat('0', $maxDecimals - 1).'1';
        } else {
            $stepValue = '1';
        }

        // 6. Current quantity validation (zero allowed in draft input)
        $rawCurrent = trim((string) $this->quantity);
        if ($rawCurrent === '') {
            $rawCurrent = '0';
        }

        if (str_contains($rawCurrent, '.')) {
            $rawCurrent = rtrim(rtrim($rawCurrent, '0'), '.');
        }

        $pattern = ($allowsFraction && $maxDecimals > 0)
            ? '/^\d{1,12}(?:\.\d{1,'.$maxDecimals.'})?$/D'
            : '/^\d{1,12}$/D';

        if (preg_match($pattern, $rawCurrent) !== 1) {
            $this->addError('quantity', __('refinement.exact_string_required'));

            return;
        }

        try {
            $currentBd = BigDecimal::of($rawCurrent);
            $stepBd = BigDecimal::of($stepValue);
            $isIncrement = in_array($dir, ['up', '+', 'increment'], true);

            $newBd = $isIncrement ? $currentBd->plus($stepBd) : $currentBd->minus($stepBd);

            if ($newBd->isNegative()) {
                $this->addError('quantity', __('refinement.quantity_cannot_be_negative'));

                return;
            }

            if ($maxDecimals > 0) {
                $formatted = (string) $newBd;
            } else {
                $formatted = (string) $newBd->toBigInteger();
            }

            $this->quantity = $formatted;
            $this->resetErrorBag('quantity');
        } catch (\Throwable) {
            $this->addError('quantity', __('refinement.exact_string_required'));
        }
    }
}
